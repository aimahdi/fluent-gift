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
        $orderListener = new \FluentCartGiftCards\App\Hooks\OrderCompletedListener();
        
        // Handle when Order is Completed (Final Status)
        add_action('fluent_cart/order_completed', [$orderListener, 'handle']);

        // Handle when Payment is Confirmed (Immediate Access)
        // Hooks: fluent_cart/payment_status_changed_to_{status}
        add_action('fluent_cart/payment_status_changed_to_paid', [$orderListener, 'handlePaymentChange']);
        add_action('fluent_cart/payment_status_changed_to_completed', [$orderListener, 'handlePaymentChange']); // Just in case


        // Initialize Product Widget
        // dd('Bootstrapping Widget');
        (new \FluentCartGiftCards\App\Hooks\AdminProductWidget())->register();

        // Initialize Account Page Integration
        new \FluentCartGiftCards\App\Hooks\AccountPageHandler();

        // Initialize Checkout UI
        new \FluentCartGiftCards\App\Hooks\CheckoutHandler();

        // Initialize Transaction Listeners (Usage & Refund)
        $txListener = new \FluentCartGiftCards\App\Hooks\GiftCardTransactionListener();
        add_action('fluent_cart/order_completed', [$txListener, 'onOrderCompleted']);
        add_action('fluent_cart/order_fully_refunded', [$txListener, 'onOrderRefunded']);
        add_action('fluent_cart/order_partially_refunded', [$txListener, 'onOrderRefunded']);
    }
}

new App();
