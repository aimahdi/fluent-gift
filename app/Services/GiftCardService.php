<?php

namespace FluentCartGiftCards\App\Services;

use FluentCart\App\Models\Coupon;
use FluentCart\App\Services\DateTime\DateTime;

class GiftCardService
{
    /**
     * Create a new Gift Card Coupon for a user
     */
    /**
     * Create a new Gift Card Coupon for a user
     */
    public function createCard($userId, $balance, $orderId, $sourceCode = null)
    {
       
        // Generate code if not provided or valid
        if (!$sourceCode) {
            // Balance is likely in cents (10000), format code to dollars (100)
            $displayBalance = $balance / 100;
            $formattedCode = $this->generateUniqueCode($displayBalance);
        } else {
            // Validate user input code if needed, for update this serves as update logic
            $formattedCode = $sourceCode;
        }
        
        $userInfo = get_userdata($userId);
        $userEmail = $userInfo ? $userInfo->user_email : '';

        // Create Coupon Data
        $couponData = [
            'title'      => 'Gift Card for User #' . $userId,
            'code'       => $formattedCode,
            'status'     => 'active',
            'type'       => 'fixed',
            'amount'     => $balance, 
            'stackable'  => 1, // Make sure it is stackable
            'start_date' => date('Y-m-d H:i:s'),
            'end_date'   => null,
            'conditions' => [
                'allowed_emails' => $userEmail ? [$userEmail] : []
            ]
        ];

        // Ensure we create a new coupon
        $coupon = Coupon::create($couponData);
        
        // Store gift card settings in Meta table
        if ($userId) {
            $coupon->updateMeta('_gift_card_owner_id', $userId);
            $coupon->updateMeta('_is_gift_card', 'yes');
            $coupon->updateMeta('_initial_balance', $balance);
            $coupon->updateMeta('_origin_order_id', $orderId);
        }

        return $coupon;
    }

    /**
     * Grant Access to a Coupon for a User
     * Adds email to Allowed Emails, tracks in Coupon History, tracks in User Inventory.
     */
    public function grantAccess($userId, $coupon, $purchaseEmail = null, $customerId = null)
    {
        $email = $purchaseEmail;
        
        if (!$email && $userId) {
            $userInfo = get_userdata($userId);
            if ($userInfo) {
                $email = $userInfo->user_email;
            }
        }
        
        if (!$email) return;

        // Normalization
        $email = strtolower(trim($email));

        // 1. Add to Allowed Emails (Access Control) - Using Native 'email_restrictions'
        $conditions = $coupon->conditions; // decodes json
        
        $emailRestrictionsStr = isset($conditions['email_restrictions']) ? $conditions['email_restrictions'] : '';
        $allowed = array_filter(array_map('trim', explode(',', $emailRestrictionsStr)));
        // Normalize stored emails too just in case
        $allowed = array_map('strtolower', $allowed);

        if (!in_array($email, $allowed)) {
            $allowed[] = $email;
            $conditions['email_restrictions'] = implode(',', $allowed); // encodes json
            $coupon->conditions = $conditions;
            $coupon->save();
        }

        // 2. Add to Coupon Settings History (Tracking Purchasers)
        // Key: purchasers_history - stored in Meta
        $historyJson = $coupon->getMeta('_purchasers_history', '[]');
        $history = json_decode($historyJson, true);
        if (!is_array($history)) {
            $history = [];
        }
        // Normalize history
        $history = array_map('strtolower', $history);
        
        if (!in_array($email, $history)) {
            $history[] = $email;
            $coupon->updateMeta('_purchasers_history', json_encode($history));
        }

        // 3. Add to User Meta Inventory (My Gift Cards List)
        // Key: _purchased_gift_cards
        $userInventory = get_user_meta($userId, '_purchased_gift_cards', true);
        if(!is_array($userInventory)) $userInventory = [];
        if (!in_array($coupon->id, $userInventory)) {
            $userInventory[] = $coupon->id;
            update_user_meta($userId, '_purchased_gift_cards', $userInventory);
        }

        // 4. Store coupon ID in fct_customer_meta table as JSON array
        if ($customerId) {
            $this->addCouponToCustomerMeta($customerId, $coupon->id);
        }
    }

    /**
     * Add coupon ID to customer meta as JSON array
     */
    private function addCouponToCustomerMeta($customerId, $couponId)
    {
        if (!$customerId) {
            return; // Cannot store without customer ID
        }

        $metaKey = '_purchased_gift_card_coupon_ids';
        
        // Get existing meta record
        $existingMeta = \FluentCart\App\Models\CustomerMeta::where('customer_id', $customerId)
            ->where('meta_key', $metaKey)
            ->first();
        
        if ($existingMeta) {
            // Get existing array of coupon IDs
            $couponIds = $existingMeta->meta_value;
            if (!is_array($couponIds)) {
                $couponIds = [];
            }
            
            // Add coupon ID if not already present
            if (!in_array($couponId, $couponIds)) {
                $couponIds[] = $couponId;
                $existingMeta->meta_value = $couponIds; // Will auto-encode to JSON
                $existingMeta->save();
            }
        } else {
            // Create new meta record with array containing this coupon ID
            \FluentCart\App\Models\CustomerMeta::create([
                'customer_id' => $customerId,
                'meta_key' => $metaKey,
                'meta_value' => [$couponId] // Will auto-encode to JSON
            ]);
        }
    }

    /**
     * Revoke Access (Usage)
     * Removes email from Allowed Emails. Keeps history/inventory.
     * 
     * @param int $userId User ID (can be 0 for guests)
     * @param \FluentCart\App\Models\Coupon $coupon The coupon to revoke access from
     * @param string|null $email Optional email to revoke (if not provided, uses user email)
     * @param int|null $customerId Optional customer ID for removing from customer meta
     */
    public function revokeAccess($userId, $coupon, $email = null, $customerId = null)
    {
        // Get email to revoke
        if (!$email && $userId) {
            $userInfo = get_userdata($userId);
            if ($userInfo) {
                $email = $userInfo->user_email;
            }
        }
        
        if (!$email) {
            return; // Cannot revoke without email
        }

        // Normalization
        $email = strtolower(trim($email));

        // Remove from Allowed Emails
        $conditions = $coupon->conditions;
        $emailRestrictionsStr = isset($conditions['email_restrictions']) ? $conditions['email_restrictions'] : '';
        $allowed = array_filter(array_map('trim', explode(',', $emailRestrictionsStr)));
        $allowed = array_map('strtolower', $allowed);
        
        if (($key = array_search($email, $allowed)) !== false) {
            unset($allowed[$key]);
            $conditions['email_restrictions'] = implode(',', $allowed); // Re-join
            $coupon->conditions = $conditions;
            $coupon->save();
        }

        // Keep the coupon in the wallet history, just revoke access rights
        // if ($customerId) {
        //     $this->removeCouponFromCustomerMeta($customerId, $coupon->id);
        // }
    }

    /**
     * Remove coupon ID from customer meta JSON array
     */
    private function removeCouponFromCustomerMeta($customerId, $couponId)
    {
        $metaKey = '_purchased_gift_card_coupon_ids';
        
        // Get existing meta record
        $existingMeta = \FluentCart\App\Models\CustomerMeta::where('customer_id', $customerId)
            ->where('meta_key', $metaKey)
            ->first();
        
        if ($existingMeta) {
            // Get existing array of coupon IDs
            $couponIds = $existingMeta->meta_value;
            if (!is_array($couponIds)) {
                $couponIds = [];
            }
            
            // Remove coupon ID if present
            if (($key = array_search($couponId, $couponIds)) !== false) {
                unset($couponIds[$key]);
                $couponIds = array_values($couponIds); // Re-index array
                
                if (empty($couponIds)) {
                    // If array is empty, delete the meta record
                    $existingMeta->delete();
                } else {
                    // Update with remaining coupon IDs
                    $existingMeta->meta_value = $couponIds; // Will auto-encode to JSON
                    $existingMeta->save();
                }
            }
        }
    }

    public function getCardByCode($code)
    {
        return Coupon::where('code', $code)->first();
    }

    /**
     * Get user's balance for a specific gift card coupon
     * Returns the coupon amount if user has access, 0 otherwise
     */
    public function getUserBalance($userId, $couponId)
    {
        $coupon = Coupon::find($couponId);
        if (!$coupon || $coupon->status !== 'active') {
            return 0;
        }

        $userInfo = get_userdata($userId);
        if (!$userInfo) {
            return 0;
        }

        $email = strtolower(trim($userInfo->user_email));
        $conditions = $coupon->conditions;
        
        // Check if user's email is in allowed list
        $emailRestrictionsStr = isset($conditions['email_restrictions']) ? $conditions['email_restrictions'] : '';
        $allowed = array_filter(array_map('trim', explode(',', $emailRestrictionsStr)));
        $allowed = array_map('strtolower', $allowed);
        
        if (in_array($email, $allowed)) {
            return (float)$coupon->amount;
        }
        
        return 0;
    }

    public function getCardsByUser($userId)
    {
        // 1. Fetch Inventory from User Meta
        $userInventory = get_user_meta($userId, '_purchased_gift_cards', true);

        
        if (!is_array($userInventory)) {
            $userInventory = [];
        }

        // 2. Fetch Coupons Owned by User (Data Robustness)
        // Query Meta table for owner_user_id
        $ownedCouponIds = \FluentCart\App\Models\Meta::where('object_type', 'coupon')
            ->where('meta_key', '_gift_card_owner_id')
            ->where('meta_value', $userId)
            ->pluck('object_id')
            ->toArray();

        // 3. Fetch Coupons from Customer Meta (fct_customer_meta table)
        $customerMetaCouponIds = [];
        $customer = \FluentCart\App\Models\Customer::where('user_id', $userId)->first();
        if ($customer) {
            $customerMeta = \FluentCart\App\Models\CustomerMeta::where('customer_id', $customer->id)
                ->where('meta_key', '_purchased_gift_card_coupon_ids')
                ->first();
            
            if ($customerMeta && !empty($customerMeta->meta_value)) {
                $metaValue = $customerMeta->meta_value;
                // meta_value is auto-decoded, but ensure it's an array
                if (is_array($metaValue)) {
                    $customerMetaCouponIds = $metaValue;
                } elseif (is_string($metaValue)) {
                    // Fallback: manually decode if needed
                    $decoded = json_decode($metaValue, true);
                    if (is_array($decoded)) {
                        $customerMetaCouponIds = $decoded;
                    }
                }
            }
        }
            
        // Merge and Unique - combine all sources
        $allCouponIds = array_unique(array_merge($userInventory, $ownedCouponIds, $customerMetaCouponIds));

        if (empty($allCouponIds)) {
            return new \FluentCart\Framework\Support\Collection([]);
        }

        $coupons = Coupon::whereIn('id', $allCouponIds)
                         ->where('status', 'active')
                         ->get();

        $userInfo = get_userdata($userId);
        $email = $userInfo ? strtolower(trim($userInfo->user_email)) : '';

        // 4. Inject Status based on Access
        return $coupons->map(function($coupon) use ($email) {
            $conditions = $coupon->conditions; // auto-decoded
            
            // Check Native 'email_restrictions'
            $emailRestrictionsStr = isset($conditions['email_restrictions']) ? $conditions['email_restrictions'] : '';
            $allowed = array_filter(array_map('trim', explode(',', $emailRestrictionsStr)));
            $allowed = array_map('strtolower', $allowed);
            
            // If email is in allowed list, it's Available. Else it's Used/Redeemed.
            if (in_array($email, $allowed)) {
                 $coupon->display_status = 'Active';
                 $coupon->can_use = true;
            } else {
                 $coupon->display_status = 'Redeemed';
                 $coupon->can_use = false;
                 $coupon->amount = 0; // Visual logic: 0 balance left
            }
            
            return $coupon;
        });
    }

    // Deprecated but kept for safety
    private function generateUniqueCode($price = 0)
    {
        $priceStr = (string)(int)$price; 
        $random = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 5);
        return strtoupper("GIFT-{$priceStr}-{$random}");
    }

    /**
     * Sync method for Admin Template Products
     * Creates a generic coupon for the product variation if needed.
     * Note: This is different from the User Gift Card created upon purchase.
     */
    public function syncProductCoupons(\FluentCart\App\Models\Product $product, $enable = true)
    {
        static $syncedProducts = [];
        
        // Service-level Recursion Guard
        if (isset($syncedProducts[$product->ID])) {
            return;
        }
        $syncedProducts[$product->ID] = true;

        $variant = $product->variants->first();
        if (!$variant) {
            return;
        }

        if (!$enable) {
            // Deactivate associated coupon
            $existingCouponId = \FluentCart\App\Models\Meta::where('object_type', 'product_variation')
                ->where('object_id', $variant->id)
                ->where('meta_key', '_associated_gift_coupon_id')
                ->value('meta_value');
            
            if ($existingCouponId) {
                // Use Update query directly to ensure it hits DB and bypasses any model save issues
                Coupon::where('id', $existingCouponId)->update(['status' => 'disabled']);
            }
            return;
        }

        $this->ensureCouponForVariant($product, $variant);
    }

    private function ensureCouponForVariant($product, $variant)
    {
        // Check if we already created a coupon for this variant via Meta
        // Using 'product_variation' as object_type strictly.
        $existingCouponId = \FluentCart\App\Models\Meta::where('object_type', 'product_variation')
            ->where('object_id', $variant->id)
            ->where('meta_key', '_associated_gift_coupon_id')
            ->value('meta_value');

        if ($existingCouponId) {
            // Verify if the coupon actually exists
            $coupon = Coupon::find($existingCouponId);
            if ($coupon) {
                // Re-enable if it was disabled
                if ($coupon->status !== 'active') {
                    Coupon::where('id', $existingCouponId)->update(['status' => 'active']);
                }
                return; 
            } else {
                // If ID exists in meta but coupon deleted, we recreate.
                // Cleanup old meta?
                \FluentCart\App\Models\Meta::where('object_type', 'product_variation')
                    ->where('object_id', $variant->id)
                    ->where('meta_key', '_associated_gift_coupon_id')
                    ->delete();
            }
        }

        // Create New Template Coupon
        // Use 'item_price' as per ProductVariation model
        $price = (float)$variant->item_price ?: 0;
        
        // Ensure price is not zero or negative
        if ($price <= 0) {
            return; // Cannot create gift card with zero or negative price
        }
        
        // Format: GIFT-{PRICE}-{RANDOM5}
        // "100 is the variable"
        // Raw price is cents (10000), but code should show dollars (100).
        $displayPrice = $price / 100;
        $code = $this->generateUniqueCode($displayPrice);
        $code = str_replace(' ', '', $code);

        $couponData = [
            'title'      => $product->post_title, // Use variation_title
            'code'       => $code,
            'status'     => 'active',
            'type'       => 'fixed',
            'amount'     => $price, 
            'start_date' => date('Y-m-d H:i:s'),
            'conditions' => [] 
        ];

        $coupon = Coupon::create($couponData);
        
        // Store gift card template settings in Meta table
        $coupon->updateMeta('_is_gift_card_template', 'yes');
        $coupon->updateMeta('_gift_card_product_id', $product->ID);
        $coupon->updateMeta('_gift_card_variation_id', $variant->id);

        // Store relation
        \FluentCart\App\Models\Meta::create([
            'object_type' => 'product_variation',
            'object_id'   => $variant->id,
            'meta_key'    => '_associated_gift_coupon_id',
            'meta_value'  => $coupon->id
        ]);
    }
}
