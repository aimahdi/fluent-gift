<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCartGiftCards\App\Services\GiftCardService;

class AccountPageHandler
{
    public function __construct()
    {
        add_filter('fluent_cart/account_menu_items', [$this, 'addMenuItem']);
        add_action('fluent_cart/account_content_my_gift_cards', [$this, 'renderContent']);
    }

    public function addMenuItem($items)
    {
        $items['my_gift_cards'] = [
            'label' => __('My Gift Cards', 'fluent-cart-gift-cards'),
            'slug'  => 'my_gift_cards',
            'icon'  => 'fc-icon-gift' // Assuming this icon class exists or falls back
        ];
        return $items;
    }

    public function renderContent()
    {
        $userId = get_current_user_id();
        $service = new GiftCardService();
        $cards = $service->getCardsByUser($userId);

        echo '<h3>' . esc_html__('My Gift Cards', 'fluent-cart-gift-cards') . '</h3>';

        if (empty($cards)) {
            echo '<p>' . esc_html__('You do not have any active gift cards.', 'fluent-cart-gift-cards') . '</p>';
            return;
        }

        echo '<div class="fc-table-responsive"><table class="fc-table">';
        echo '<thead><tr>
            <th>' . esc_html__('Code', 'fluent-cart-gift-cards') . '</th>
            <th>' . esc_html__('Balance', 'fluent-cart-gift-cards') . '</th>
            <th>' . esc_html__('Status', 'fluent-cart-gift-cards') . '</th>
        </tr></thead><tbody>';

        foreach ($cards as $card) {
            echo '<tr>';
            echo '<td><code>' . esc_html($card->code) . '</code></td>';
            // Use FluentCart helper for formatting logic if available, distinct currency logic needed?
            // FluentCart Helpers are usually available.
            echo '<td>' . esc_html($card->current_balance) . '</td>'; 
            echo '<td><span class="fc-badge fc-badge-success">' . esc_html(ucfirst($card->status)) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}
