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
        static $intercepting = [];

        // Recursion Guard: Prevent infinite loop when Service calls Coupon::find()
        if (isset($intercepting[$coupon->id])) {
            return;
        }
        $intercepting[$coupon->id] = true;

        // Only target Gift Card Template or Tracked Gift Card
        if (!$this->isGiftCard($coupon)) {
            return;
        }

        // We only care about logged-in users for now
        $userId = get_current_user_id();
        if (!$userId) {
            return;
        }

        // Fix: Do not inject balance for Admins/Managers.
        // This prevents the Coupon List in Admin Panel from showing $0 (because Admin doesn't own the card).
        if (user_can($userId, 'manage_options')) {
            return;
        }

        try {
            $service = new GiftCardService();
            // This method might query Coupon::find($id) internally, triggering 'retrieved' again.
            // Our recursion guard handles that.
            $balance = $service->getUserBalance($userId, $coupon->id);

            if ($balance > 0) {
                // Override the static amount with the User's Balance
                $coupon->amount = $balance;
                
                // Also override max discount to ensure they can't spend more
                $conditions = $coupon->conditions;
                $conditions['max_discount_amount'] = $balance;
                
                $coupon->conditions = $conditions;
            } else {
                // If balance is 0, we should probably make the coupon invalid or 0 amount
                $coupon->amount = 0;
                $conditions = $coupon->conditions;
                $conditions['max_discount_amount'] = 0;
                $coupon->conditions = $conditions;
            }
        } catch (\Exception $e) {
            // Silence errors during interception
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
