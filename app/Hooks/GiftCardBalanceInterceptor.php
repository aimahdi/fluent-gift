<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCart\App\Models\Coupon;
use FluentCartGiftCards\App\Services\GiftCardService;

class GiftCardBalanceInterceptor
{
    public function register()
    {
        // Hook into the 'retrieved' event of the Coupon model
        // This fires after a model is fetched from the database
        try {
            Coupon::retrieved(function ($coupon) {
                $this->injectUserBalance($coupon);
            });
        } catch (\Throwable $e) {
            // Silently fail if event system not set up to avoid crashing site
        }
    }

    private function injectUserBalance($coupon)
    {
        // Only target Gift Card Template or Tracked Gift Card
        if (!$this->isGiftCard($coupon)) {
            return;
        }

        // We only care about logged-in users for now (or maybe guest via session? user said 'customer email associated', implying user account)
        // If guest checkout usage is supported, we'd need email from session, which is harder here.
        // Assuming logged in for "My Gift Cards" context strictly.
        // But for Checkout usage, user might be guest but typing email?
        // FluentCart's 'retrieved' happens early. 
        
        $userId = get_current_user_id();
        if (!$userId) {
            // If checking out as guest, maybe we can't easily validate balance yet?
            // User requested "only those 10 users can use that coupon". 
            // Implies we need to know WHO is using it.
            // If user is guest, we might skip this injection and fail validation later?
            // Or rely on 'allowed_emails'.
            return;
        }

        $service = new GiftCardService();
        $balance = $service->getUserBalance($userId, $coupon->id);

        if ($balance > 0) {
            // Override the static amount with the User's Balance
            $coupon->amount = $balance;
            
            // Also override max discount to ensure they can't spend more
            $conditions = $coupon->conditions;
            $conditions['max_discount_amount'] = $balance;
            
            // We interpret this as "Store Credit", so max_discount = balance.
            // FluentCart will calculate min(OrderTotal, Amount, MaxDiscount).
            // So if Order is $200, Amount=$50, Max=$50 -> $50 off.
            // If Order is $10, Amount=$50, Max=$50 -> $10 off.
            
            $coupon->conditions = $conditions;
        } else {
            // If balance is 0, we should probably make the coupon invalid or 0 amount
            $coupon->amount = 0;
            $conditions = $coupon->conditions;
            $conditions['max_discount_amount'] = 0;
            $coupon->conditions = $conditions;
        }
    }

    private function isGiftCard($coupon)
    {
        // Check Meta table for gift card markers
        $isTemplate = $coupon->getMeta('_is_gift_card_template');
        $isGiftCard = $coupon->getMeta('_is_gift_card');
        
        // Supports both template cards and unique cards
        return $isTemplate === 'yes' || $isGiftCard === 'yes';
    }
}
