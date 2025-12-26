<?php

namespace FluentCartGiftCards\App\Services;

use FluentCart\App\Models\Coupon;
use FluentCart\App\Services\DateTime\DateTime;

class GiftCardService
{
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
            'settings'   => [
                'is_gift_card'      => true,
                'owner_user_id'     => $userId,
                'initial_balance'   => $balance,
                'origin_order_id'   => $orderId
            ]
        ];

        // Ensure we create a new coupon
        $coupon = Coupon::create($couponData);

        return $coupon;
    }

    public function getCardByCode($code)
    {
        return Coupon::where('code', $code)->first();
    }

    public function getCardsByUser($userId)
    {
        // FluentCart Coupon doesn't natively have 'owner_user_id' column, it's in settings.
        // We have to filter. Since this might be heavy if many coupons, 
        // ideally we would add an index or a meta, but for now lets query all 'active' coupons
        // that *look* like gift cards or filter them in PHP.
        // Optimization: Query by code prefix if possible? 'GIFT-'.$userId.'-%'
        
        // Better Approach: Use 'likeness' if supported or Get all coupons and filter.
        // Given we used 'GIFT-{USERID}-...' format:
        $prefix = 'GIFT-' . $userId . '-';
        
        $coupons = Coupon::where('code', 'LIKE', $prefix . '%')
                         ->where('status', 'active')
                         ->get();
        
        // Double check settings to be sure
        return $coupons->filter(function($coupon) use ($userId) {
            $settings = $coupon->settings;
            // Check if settings is array or string (decoded by model usually)
            if (is_string($settings)) {
                $settings = json_decode($settings, true);
            }
            return isset($settings['is_gift_card']) && $settings['is_gift_card'] == true;
        });
    }

    public function debitBalance($code, $amountToDebit)
    {
        $coupon = $this->getCardByCode($code);
        if (!$coupon) {
            return false;
        }

        $currentBalance = (float)$coupon->amount;
        $newBalance = $currentBalance - (float)$amountToDebit;

        if ($newBalance < 0) {
            $newBalance = 0;
        }

        $coupon->amount = $newBalance;
        
        // If balance is 0, should we disable it?
        // Maybe keep it active but 0 balance so user sees it?
        // Or set status to 'archived'?
        // Let's keep it active with 0 amount for now, or the user can't select it.
        // Actually if amount is 0, applying it does nothing.
        
        $coupon->save();
        return $newBalance;
    }

    public function creditBalance($code, $amountToCredit)
    {
        $coupon = $this->getCardByCode($code);
        if (!$coupon) {
            return false;
        }

        $coupon->amount = (float)$coupon->amount + (float)$amountToCredit;
        // Make sure it's active if it was disabled
        if ($coupon->status != 'active') {
            $coupon->status = 'active';
        }
        $coupon->save();

        return $coupon->amount;
    }

    private function generateUniqueCode($price = 0)
    {
        // Requested Format: GIFT-{PRICE}-{RANDOM5}
        // e.g., GIFT-100-ABCDE
        $priceStr = (string)(int)$price; // Use integer part of price ?? or full? "100 is the variable" -> likely int.

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
            'stackable'  => 1, // Make sure it is stackable
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
