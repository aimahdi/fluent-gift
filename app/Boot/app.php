<?php

namespace FluentCartGiftCards\App\Boot;



// Load Services
require_once __DIR__ . '/../Services/GiftCardService.php';

// Load Hooks
require_once __DIR__ . '/../Hooks/OrderCompletedListener.php';
require_once __DIR__ . '/../Hooks/AccountPageHandler.php';
require_once __DIR__ . '/../Hooks/CheckoutHandler.php';
require_once __DIR__ . '/../Hooks/GiftCardTransactionListener.php';
require_once __DIR__ . '/../Hooks/AdminProductWidget.php';
require_once __DIR__ . '/../Hooks/GiftCardBalanceInterceptor.php';

class App
{
    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        // Initialize Services
        $giftService = new \FluentCartGiftCards\App\Services\GiftCardService();
        
        // Initialize Handlers (they register hooks in their constructors)
        $checkoutHandler = new \FluentCartGiftCards\App\Hooks\CheckoutHandler();
        $accountHandler = new \FluentCartGiftCards\App\Hooks\AccountPageHandler();
        $transactionListener = new \FluentCartGiftCards\App\Hooks\GiftCardTransactionListener();

        // Order Hooks - Granting Access (Paid)
        add_action('fluent_cart/order_paid_done', [$transactionListener, 'handleOrderPaid']);
        
        // Hooks for Revoking/Restoring Access (Refunds)
        add_action('fluent_cart/order_fully_refunded', [$transactionListener, 'onOrderFullyRefunded']);
        add_action('fluent_cart/order_partially_refunded', [$transactionListener, 'onOrderPartiallyRefunded']);
        
        // Handle manual status changes or other payment completions
        add_action('fluent_cart/order_payment_status_changed', [$transactionListener, 'onPaymentStatusChanged'], 10, 3);

        // Initialize Product Widget
        (new \FluentCartGiftCards\App\Hooks\AdminProductWidget())->register();

        // Initialize Balance Interceptor
        (new \FluentCartGiftCards\App\Hooks\GiftCardBalanceInterceptor())->register();

        // Validate gift card amount against cart total (server-side)
        add_filter('fluent_cart/coupon/can_use_coupon', function($canUse, $data) {
            $coupon = $data['coupon'] ?? null;
            $cart = $data['cart'] ?? null;
            
            if (!$coupon || !$cart) {
                return $canUse;
            }

            // Check if this is a gift card coupon
            $isGiftCard = $coupon->getMeta('_is_gift_card_template') === 'yes' || 
                         $coupon->getMeta('_is_gift_card') === 'yes';
            
            if (!$isGiftCard) {
                return $canUse;
            }

            // Get cart item total (excluding shipping)
            $cartItemTotal = 0;
            if (!empty($cart->cart_data)) {
                foreach ($cart->cart_data as $item) {
                    $cartItemTotal += isset($item['line_total']) ? (float)$item['line_total'] : 0;
                }
            }
            
            // Get gift card amount
            $giftCardAmount = (int)$coupon->amount;
            
            // Validate: gift card amount cannot exceed cart item total
            if ($giftCardAmount > $cartItemTotal) {
                return new \WP_Error(
                    'gift_card_amount_exceeds_total',
                    __('Gift card amount cannot exceed cart item total (excluding shipping).', 'fluent-cart-gift-cards')
                );
            }
            
            return $canUse;
        }, 10, 2);
    }
}

new App();
