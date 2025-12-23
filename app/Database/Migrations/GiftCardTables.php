<?php

namespace FluentCartGiftCards\App\Database\Migrations;

class GiftCardTables
{
    /**
     * Migrate the table
     */
    public function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . 'fct_user_gift_cards';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            $sql = "CREATE TABLE $table (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                order_id BIGINT(20) UNSIGNED NULL,
                coupon_id BIGINT(20) UNSIGNED NULL,
                code VARCHAR(191) NOT NULL,
                initial_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                current_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(50) NOT NULL DEFAULT 'active',
                settings TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY code (code),
                KEY status (status)
            ) $charsetCollate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
}
