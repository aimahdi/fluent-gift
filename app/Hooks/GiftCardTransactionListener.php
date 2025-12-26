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

        foreach ($order->used_coupons as $coupon) {
            // Check if this coupon code belongs to a gift card
            // We can just verify if it exists via service, which checks logic
            $card = $service->getCardByCode($coupon->code);
            
            // Or better, check if the coupon object itself has the settings if already loaded?
            // $order->used_coupons usually contains the coupon data used.
            // Let's assume we re-fetch to be safe and check if it's a gift card.
            
            if ($card) { 
                // Check if it's a gift card 
                // getCardByCode returns a Coupon model now, need to check settings
                $settings = $card->settings;
                if(is_string($settings)) $settings = json_decode($settings, true);
                
                if (empty($settings['is_gift_card'])) {
                     continue;
                }

                // Balance Logic
                // We need to know how much was *actually* deducted by THIS coupon.
                // If $order->coupon_discount_total is used, it sums all coupons.
                // Assuming 1 coupon for now or relying on total.
                $appliedAmount = $order->coupon_discount_total; 
                 
                // Debit using Code, not ID
                $service->debitBalance($card->code, $appliedAmount);
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
        $refundAmount = $refundData['refund_amount'] ?? 0;

        foreach ($order->used_coupons as $coupon) {
             // Re-fetch to check if gift card
            $card = $service->getCardByCode($coupon->code);
            if ($card) {
                $settings = $card->settings;
                if(is_string($settings)) $settings = json_decode($settings, true);
                
                if (!empty($settings['is_gift_card'])) {
                    // Credit code
                    $service->creditBalance($card->code, $refundAmount);
                    break; 
                }
            }
        }
    }
}
