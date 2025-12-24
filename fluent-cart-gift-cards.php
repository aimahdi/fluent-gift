<?php
/**
 * Plugin Name: FluentCart Gift Cards
 * Description: Gift Card Addon for FluentCart
 * Version: 1.0.0
 * Author: Amimul Ihsan
 * Text Domain: fluent-cart-gift-cards
 * Domain Path: /language
 */

defined('ABSPATH') or die;

if (!defined('FLUENTCART_GIFT_CARDS_PLUGIN_PATH')) {
    define('FLUENTCART_GIFT_CARDS_VERSION', '1.0.0');
    define('FLUENTCART_GIFT_CARDS_DB_VERSION', '1.0.0');
    define('FLUENTCART_GIFT_CARDS_PLUGIN_PATH', plugin_dir_path(__FILE__));
    define('FLUENTCART_GIFT_CARDS_URL', plugin_dir_url(__FILE__));
    define('FLUENTCART_GIFT_CARDS_FILE', __FILE__);
}

add_action('plugins_loaded', function () {

    if (!defined('FLUENT_CART_DIR_FILE')) {
        return;
    }

    require_once __DIR__ . '/app/Boot/app.php';
});

register_activation_hook(__FILE__, function () {
    require_once __DIR__ . '/app/Database/Migrations/GiftCardTables.php';
    (new \FluentCartGiftCards\App\Database\Migrations\GiftCardTables())->migrate();
});
