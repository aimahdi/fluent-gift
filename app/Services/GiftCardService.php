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
            ],
            'settings'   => [
                'is_gift_card'      => true,
                'owner_user_id'     => $userId,
                'initial_balance'   => $balance,
                'origin_order_id'   => $orderId
            ]
        ];

        // Ensure we create a new coupon
        $coupon = Coupon::create($couponData);
        
        // Store robust ownership meta
        if ($userId) {
            $coupon->updateMeta('_gift_card_owner_id', $userId);
        }

        return $coupon;
    }

    /**
     * Grant Access to a Coupon for a User
     * Adds email to Allowed Emails, tracks in Coupon History, tracks in User Inventory.
     */
    public function grantAccess($userId, $coupon)
    {
        $userInfo = get_userdata($userId);
        if (!$userInfo) return;
        $email = $userInfo->user_email;

        // 1. Add to Allowed Emails (Access Control)
        $conditions = $coupon->conditions; // decodes json
        $allowed = $conditions['allowed_emails'] ?? [];
        if (!in_array($email, $allowed)) {
            $allowed[] = $email;
            $conditions['allowed_emails'] = $allowed;
            $coupon->conditions = $conditions; // encodes json
            $coupon->save();
        }

        // 2. Add to Coupon Meta History (Tracking Purchasers)
        // Key: _gift_card_purchasers_history
        $history = $coupon->getMeta('_gift_card_purchasers_history', []);
        if(!is_array($history)) $history = [];
        if (!in_array($email, $history)) {
            $history[] = $email;
            $coupon->updateMeta('_gift_card_purchasers_history', $history);
        }

        // 3. Add to User Meta Inventory (My Gift Cards List)
        // Key: _purchased_gift_cards
        $userInventory = get_user_meta($userId, '_purchased_gift_cards', true);
        if(!is_array($userInventory)) $userInventory = [];
        if (!in_array($coupon->id, $userInventory)) {
            $userInventory[] = $coupon->id;
            update_user_meta($userId, '_purchased_gift_cards', $userInventory);
        }
    }

    /**
     * Revoke Access (Usage)
     * Removes email from Allowed Emails. Keeps history/inventory.
     */
    public function revokeAccess($userId, $coupon)
    {
        $userInfo = get_userdata($userId);
        if (!$userInfo) return;
        $email = $userInfo->user_email;

        // Remove from Allowed Emails
        $conditions = $coupon->conditions;
        $allowed = $conditions['allowed_emails'] ?? [];
        
        if (($key = array_search($email, $allowed)) !== false) {
            unset($allowed[$key]);
            $conditions['allowed_emails'] = array_values($allowed); // Re-index
            $coupon->conditions = $conditions;
            $coupon->save();
        }
    }

    public function getCardByCode($code)
    {
        return Coupon::where('code', $code)->first();
    }

    public function getCardsByUser($userId)
    {
        // 1. Fetch Inventory from User Meta
        $userInventory = get_user_meta($userId, '_purchased_gift_cards', true);
        if (!is_array($userInventory) || empty($userInventory)) {
            return new \FluentCart\Framework\Support\Collection([]);
        }

        $coupons = Coupon::whereIn('id', $userInventory)
                         ->where('status', 'active')
                         ->get();

        $userInfo = get_userdata($userId);
        $email = $userInfo ? $userInfo->user_email : '';

        // 2. Inject Status based on Access
        return $coupons->map(function($coupon) use ($email) {
            $conditions = $coupon->conditions; // auto-decoded
            $allowed = $conditions['allowed_emails'] ?? [];
            
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
            'settings'   => [
                'is_gift_card_template' => 'yes',
                'product_id'   => $product->ID, // Post ID
                'variation_id' => $variant->id
            ]
        ];

        $coupon = Coupon::create($couponData);

        // Store relation
        \FluentCart\App\Models\Meta::create([
            'object_type' => 'product_variation',
            'object_id'   => $variant->id,
            'meta_key'    => '_associated_gift_coupon_id',
            'meta_value'  => $coupon->id
        ]);
    }
}
