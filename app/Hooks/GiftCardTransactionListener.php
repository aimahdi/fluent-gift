<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCartGiftCards\App\Services\GiftCardService;

class GiftCardTransactionListener
{
    /**
     * Handle Order Paid (Purchase Logic)
     * Hook: fluent_cart/order_paid_done
     * 
     * When a gift card product is purchased, grant the buyer access to the template coupon
     * by adding their email to the coupon's email_restrictions and storing coupon ID in customer meta
     */
    public function handleOrderPaid($data)
    {
        // Extract Order from event data
        // Hook format: ['order' => $order, 'transaction' => $transaction, 'customer' => $customer]
        $order = null;
        if ($data instanceof \FluentCart\App\Models\Order) {
            $order = $data;
        } elseif (is_array($data) && isset($data['order'])) {
            $order = $data['order'];
        }

        if (!$order || empty($order->order_items)) {
            return;
        }

        $service = new GiftCardService();
        
        // Get customer email - prefer billing email, fallback to user email
        $purchaseEmail = null;
        if (!empty($order->billing_address) && isset($order->billing_address['email'])) {
            $purchaseEmail = $order->billing_address['email'];
        } elseif ($order->user_id) {
            $userInfo = get_userdata($order->user_id);
            if ($userInfo) {
                $purchaseEmail = $userInfo->user_email;
            }
        }
        
        if (!$purchaseEmail) {
            return; // Cannot grant/revoke access without email
        }
        
        // 1. Process Used Coupons (Redemption Logic - Revoke Access)
        // If the user used a Gift Card to pay for this order, we "redeem" it (revoke access).
        
        // Try getting coupons from the Relation (usually usedCoupons or appliedCoupons)
        // Using direct model query to ensure we get the latest data from DB
        $usedCoupons = \FluentCart\App\Models\AppliedCoupon::where('order_id', $order->id)->get();
        
        if ($usedCoupons->count()) {
            foreach ($usedCoupons as $couponItem) {
                // Determine Code
                $couponCode = $couponItem->code ?? $couponItem->coupon_code ?? '';
                
                if (!$couponCode) continue;
                
                $coupon = $service->getCardByCode($couponCode);
                
                // Use helper to check if it's our gift card
                if ($coupon && $this->isGiftCardTemplate($coupon)) {
                     // Revoke Access: Remove email from allowed list so it cannot be used again
                     $service->revokeAccess($order->user_id, $coupon, $purchaseEmail, $customerId);
                }
            }
        }

        // 2. Process Items (Grant Access for Purchases)
        // Get customer ID from order, or find/create customer by user_id or email
        $customerId = $order->customer_id;
        if (!$customerId) {
            // Try to find customer by user_id
            if ($order->user_id) {
                $customer = \FluentCart\App\Models\Customer::where('user_id', $order->user_id)->first();
                if ($customer) {
                    $customerId = $customer->id;
                }
            }
            
            // If still not found, try by email
            if (!$customerId && $purchaseEmail) {
                $customer = \FluentCart\App\Models\Customer::where('email', $purchaseEmail)->first();
                if ($customer) {
                    $customerId = $customer->id;
                }
            }
        }
        
        // Process each order item to find gift card products
        foreach ($order->order_items as $item) {
            // Find Associated Master Coupon (template coupon for this product variation)
            $masterCouponId = \FluentCart\App\Models\Meta::where('object_type', 'product_variation')
                ->where('object_id', $item->object_id) // variation_id
                ->where('meta_key', '_associated_gift_coupon_id')
                ->value('meta_value');
            
            if (!$masterCouponId) {
                continue; // Not a gift card product
            }
            
            $coupon = \FluentCart\App\Models\Coupon::find($masterCouponId);
            if (!$coupon) {
                continue;
            }

            // Grant Access: 
            // 1. Add buyer's email to coupon's email_restrictions
            // 2. Store coupon ID in fct_customer_meta table as JSON array
            $service->grantAccess($order->user_id, $coupon, $purchaseEmail, $customerId);
        }
    }

    /**
     * Handle Order Fully Refunded
     * Hook: fluent_cart/order_fully_refunded
     */
    public function onOrderFullyRefunded($data)
    {
        $this->handleOrderRefunded($data);
    }

    /**
     * Handle Order Partially Refunded
     * Hook: fluent_cart/order_partially_refunded
     */
    public function onOrderPartiallyRefunded($data)
    {
        $this->handleOrderRefunded($data);
    }
    
    /**
     * Handle Payment Status Change
     * Hook: fluent_cart/order_payment_status_changed
     */
    public function onPaymentStatusChanged($order, $newStatus, $oldStatus)
    {
        if ($newStatus === 'paid') {
            $this->handleOrderPaid($order);
        }
    }

    /**
     * Common refund handler
     */
    private function handleOrderRefunded($data)
    {
        // Extract order from event data
        $order = null;
        if ($data instanceof \FluentCart\App\Models\Order) {
            $order = $data;
        } elseif (is_array($data) && isset($data['order'])) {
            $order = $data['order'];
        }

        if (!$order) {
            return;
        }

        $service = new GiftCardService();
        $userId = $order->user_id;
        $customerId = $order->customer_id;

        // CASE 1: Refund of GIFT CARD USAGE (Restore Balance/Access)
        // If this order used a gift card and is being refunded, restore the user's access
        if (!empty($order->used_coupons)) {
            foreach ($order->used_coupons as $couponItem) {
                $couponCode = is_object($couponItem) ? $couponItem->code : (is_array($couponItem) ? ($couponItem['code'] ?? '') : '');
                if (!$couponCode) continue;
                
                $coupon = $service->getCardByCode($couponCode);
                if ($coupon && $this->isGiftCardTemplate($coupon)) {
                    // Restore Access: Add email back to allowed list so they can use it again
                    $purchaseEmail = null;
                    if (!empty($order->billing_address) && isset($order->billing_address['email'])) {
                        $purchaseEmail = $order->billing_address['email'];
                    }
                    $service->grantAccess($userId, $coupon, $purchaseEmail, $customerId);
                }
            }
        }

        // CASE 2: Refund of GIFT CARD PURCHASE (Revoke Access)
        // If this order purchased gift cards and items are being refunded, revoke access
        if (!empty($data['refunded_items'])) {
            foreach ($data['refunded_items'] as $rItem) {
                $objectId = is_array($rItem) ? ($rItem['object_id'] ?? 0) : ($rItem->object_id ?? 0);
                
                if (!$objectId) continue;

                // Find the associated gift card coupon for this product variation
                $masterCouponId = \FluentCart\App\Models\Meta::where('object_type', 'product_variation')
                    ->where('object_id', $objectId)
                    ->where('meta_key', '_associated_gift_coupon_id')
                    ->value('meta_value');
                
                if ($masterCouponId) {
                    $coupon = \FluentCart\App\Models\Coupon::find($masterCouponId);
                    if ($coupon) {
                        // Revoke Access: 
                        // 1. Remove user's email from email_restrictions
                        // 2. Remove coupon ID from fct_customer_meta table
                        $refundEmail = null;
                        if (!empty($order->billing_address) && isset($order->billing_address['email'])) {
                            $refundEmail = $order->billing_address['email'];
                        }
                        $service->revokeAccess($userId, $coupon, $refundEmail, $customerId);
                    }
                }
            }
        }
    }

    /**
     * Helper to identify Gift Card Template Coupons
     */
    private function isGiftCardTemplate($coupon)
    {
        // Check Meta table for gift card template flag
        $isTemplate = $coupon->getMeta('_is_gift_card_template');
        return $isTemplate === 'yes';
    }
}
