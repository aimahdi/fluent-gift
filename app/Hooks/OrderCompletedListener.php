<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCartGiftCards\App\Services\GiftCardService;

class OrderCompletedListener
{
    public function handle($order)
    {
        // $order is likely an Eloquent Model or Array. FluentCart usually passes Model.
        // Based on OrderService analysis: $order->order_items is a collection.

        if (!is_object($order) || empty($order->order_items)) {
            return;
        }

        $giftCardService = new GiftCardService();

        foreach ($order->order_items as $item) {
            // Check if product is gift card
            // We can check product meta or item meta
            // Assuming we save '_is_gift_card' in product meta.
            // But $item->product might need to be loaded.
            
            // In FluentCart, order item has 'post_id'.
            $productId = $item->post_id;
            
            // Or maybe check specific product type if we register one.
            $isGiftCard = get_post_meta($productId, '_fct_is_gift_card', true) === 'yes';

            if (!$isGiftCard) {
                continue;
            }

            // How many?
            $qty = $item->quantity;
            $amount = $item->unit_price; // Or line_total / qty? Use unit_price to be safe.

            // Generate card for each quantity
            for ($i = 0; $i < $qty; $i++) {
                $createdCoupon = $giftCardService->createCard(
                    $order->user_id,
                    $amount,
                    $order->id
                );
                
                // Optional: Store created coupon ID in order item meta for reference?
            }
        }
    }
}
