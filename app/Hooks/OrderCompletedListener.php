<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCartGiftCards\App\Services\GiftCardService;
use FluentCart\App\Models\Order;

class OrderCompletedListener
{
    /**
     * Handle Payment Status Change (e.g. Paid)
     * Wraps the order object from event data and calls main handler
     */
    public function handlePaymentChange($data)
    {
        // Data format from OrderStatusUpdated event: ['order' => $order, ...]
        if (is_array($data) && isset($data['order'])) {
            $order = $data['order'];
            if ($order instanceof Order) {
                $this->handle($order);
            }
        }
    }

    public function handle($order)
    {
        if (!is_object($order) || empty($order->order_items)) {
            return;
        }

        $service = new GiftCardService();
        $items = $order->order_items;
        
        foreach ($items as $item) {
            // Find Associated Master Coupon Meta on Variation
            $masterCouponId = \FluentCart\App\Models\Meta::where('object_type', 'product_variation')
                ->where('object_id', $item->object_id) // variation_id
                ->where('meta_key', '_associated_gift_coupon_id')
                ->value('meta_value');
            
            if (!$masterCouponId) continue;
            
            $coupon = \FluentCart\App\Models\Coupon::find($masterCouponId);
            if (!$coupon) continue;

            // Grant Access (One-Time / Permission Model)
            // Even if Qty > 1, the permission is binary (Allowed Email).
            // Requirement says "add user's email". Duplicate addition handled in service.
            
            $service->grantAccess($order->user_id, $coupon);
        }
    }
}
