<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCartGiftCards\App\Services\GiftCardService;

class GiftCardTransactionListener
{
    public function onOrderCompleted($order)
    {
        if (empty($order->used_coupons)) {
            return;
        }

        $service = new GiftCardService();
        $userId = $order->user_id;

        foreach ($order->used_coupons as $couponItem) {
            $coupon = $service->getCardByCode($couponItem->code);
            
            if ($coupon && $userId) {
                // Determine if this is a gift card we track.
                // 1. Is it a template?
                // 2. Or do we have it in our inventory?
                // For safety, checking if it's a "Gift Card Template" is best.
                
                if ($this->isGiftCardTemplate($coupon)) {
                    // Revoke Access (One-Time Use)
                    $service->revokeAccess($userId, $coupon);
                }
            }
        }
    }

    public function onOrderRefunded($refundData)
    {
        $orderId = $refundData['order_id'] ?? null;
        if (!$orderId) return;

        $order = \FluentCart\App\Models\Order::find($orderId);
        if (!$order || empty($order->used_coupons)) return;

        $service = new GiftCardService();
        $userId = $order->user_id;

        foreach ($order->used_coupons as $couponItem) {
            $coupon = $service->getCardByCode($couponItem->code);
            if ($coupon && $userId) {
                 if($this->isGiftCardTemplate($coupon)) {
                     // Grant Access back
                     $service->grantAccess($userId, $coupon);
                 }
            }
        }
    }

    private function isGiftCardTemplate($coupon)
    {
        $settings = $coupon->settings;
        if(is_string($settings)) $settings = json_decode($settings, true);
        return !empty($settings['is_gift_card_template']);
    }
}
