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
        
        // Register Admin Metabox for Gift Card Product Option
        add_action('add_meta_boxes', function() {
            add_meta_box(
                'fct_gift_card_meta',
                'Gift Card Settings',
                function($post) {
                    $value = get_post_meta($post->ID, '_fct_is_gift_card', true);
                    $checked = $value === 'yes' ? 'checked' : '';
                    echo '<label><input type="checkbox" name="_fct_is_gift_card" value="yes" ' . $checked . '> Is Gift Card Product</label>';
                    echo '<p class="description">If checked, purchasing this product will generate a Gift Card Coupon for the buyer.</p>';
                },
                'fluent-products', // Correct CPT slug
                'side',
                'default'
            );
        });

        add_action('save_post', function($post_id) {
            if (isset($_POST['_fct_is_gift_card'])) {
                update_post_meta($post_id, '_fct_is_gift_card', 'yes');
            } else {
                // Check if it's a save action for our CPT to avoid clearing on other saves
                if (get_post_type($post_id) === 'fluent-products') {
                    delete_post_meta($post_id, '_fct_is_gift_card');
                }
            }
        });
        
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
