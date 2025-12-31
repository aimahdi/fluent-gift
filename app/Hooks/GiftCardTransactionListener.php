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

        if (!$order) {
            return;
        }

        $service = new GiftCardService();
        
        // 0. PREPARE DATA: Get Email and Customer ID First
        
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
        
        // Get customer ID logic... (existing)
        $customerId = $order->customer_id;
        // ... (existing identification logic) ...
        if (!$customerId) {
            // Try to find customer by user_id
            if ($order->user_id) {
                $customer = \FluentCart\App\Models\Customer::where('user_id', $order->user_id)->first();
                if ($customer) {
                    $customerId = $customer->id;
                }
            }
            
            // If still not found, try by email
            if (!$customerId) {
                $customer = \FluentCart\App\Models\Customer::where('email', $purchaseEmail)->first();
                if ($customer) {
                    $customerId = $customer->id;
                }
            }
        }

        // 1. Process Used Coupons (Redemption Logic - Revoke Access)
        
        // 1. Process Used Coupons (Redemption Logic - Revoke Access)
        
        // Try getting coupons from the Relation (usually usedCoupons or appliedCoupons)
        // Using direct model query to ensure we get the latest data from DB
        try {
            $usedCoupons = \FluentCart\App\Models\AppliedCoupon::where('order_id', $order->id)->get();
        } catch (\Exception $e) {
            $usedCoupons = collect([]);
        }

        // Fallback: Check if order object has it hydrated
        if ($usedCoupons->isEmpty() && !empty($order->used_coupons)) {
             $usedCoupons = $order->used_coupons;
        } else if ($usedCoupons->isEmpty()) {
             // Fallback 2: Check fct_order_items type=coupon?
             // Sometimes usually logic is enough.
        }
        
        if (!empty($usedCoupons) && (is_array($usedCoupons) || is_object($usedCoupons))) {
            foreach ($usedCoupons as $couponItem) {
                $coupon = null;
                $lookupMethod = 'none';

                // METHOD 1: Lookup by ID (Most Reliable)
                // applied_coupons table usually has 'coupon_id'
                $couponId = is_object($couponItem) ? ($couponItem->coupon_id ?? 0) : ($couponItem['coupon_id'] ?? 0);
                if ($couponId) {
                    $coupon = \FluentCart\App\Models\Coupon::find($couponId);
                    $lookupMethod = 'id';
                }

                // METHOD 2: Lookup by Code (Fallback)
                if (!$coupon) {
                    $couponCode = is_object($couponItem) ? ($couponItem->code ?? $couponItem->coupon_code ?? '') : ($couponItem['code'] ?? '');
                    if ($couponCode) {
                        $service = new GiftCardService(); // Ensure service is available
                        $coupon = $service->getCardByCode($couponCode);
                        $lookupMethod = 'code (' . $couponCode . ')';
                    }
                }
                
                if (!$coupon) continue;
                
                // Use helper to check if it's our gift card
                if ($this->isGiftCard($coupon)) {
                     $service->revokeAccess($order->user_id, $coupon, $purchaseEmail, $customerId);
                }
            }
        }

        // 2. Process Items (Grant Access for Purchases)
        // 2. Process Items (Grant Access for Purchases)
        if (!empty($order->order_items)) {
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
                if ($coupon && $this->isGiftCard($coupon)) {
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
     * Helper to identify Gift Card Template Coupons or Unique Cards
     */
    private function isGiftCard($coupon)
    {
        // Check Meta table for gift card flags
        $isTemplate = $coupon->getMeta('_is_gift_card_template');
        $isGiftCard = $coupon->getMeta('_is_gift_card');
        
        return $isTemplate === 'yes' || $isGiftCard === 'yes';
    }
}
