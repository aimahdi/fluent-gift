<?php

namespace FluentCartGiftCards\App\Boot;

require_once __DIR__ . '/../Database/Migrations/GiftCardTables.php';

// Load Services
require_once __DIR__ . '/../Services/GiftCardService.php';

// Load Hooks
require_once __DIR__ . '/../Hooks/OrderCompletedListener.php';
require_once __DIR__ . '/../Hooks/AccountPageHandler.php';
require_once __DIR__ . '/../Hooks/CheckoutHandler.php';
require_once __DIR__ . '/../Hooks/GiftCardTransactionListener.php';
require_once __DIR__ . '/../Hooks/AdminProductWidget.php';

class App
{
    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        // Initialize Hooks
        add_action('fluent_cart/order_completed', [new \FluentCartGiftCards\App\Hooks\OrderCompletedListener(), 'handle']);

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
