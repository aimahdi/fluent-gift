<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCartGiftCards\App\Services\GiftCardService;
use FluentCart\App\Services\TemplateService;

class AccountPageHandler
{
    public function __construct()
    {
        // Register Menu Item (Correct Hook)
        add_filter('fluent_cart/global_customer_menu_items', [$this, 'addMenuItem'], 10, 2);
        
        // Register Custom Endpoint Content (Correct Hook)
        add_filter('fluent_cart/customer_portal/custom_endpoints', [$this, 'registerEndpoint']);
    }

    public function addMenuItem($items, $args)
    {
        $baseUrl = $args['base_url'] ?? TemplateService::getCustomerProfileUrl('/');
        
        $newItem = [
            'my_gift_cards' => [
                'label' => __('My Gift Cards', 'fluent-cart-gift-cards'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'gift-cards',
                'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1" ry="1"></rect><line x1="12" y1="8" x2="12" y2="21"></line><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"></path><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"></path></svg>'
            ]
        ];

        // Insert after 'downloads' if exists, otherwise append
        if (array_key_exists('downloads', $items)) {
            $offset = array_search('downloads', array_keys($items)) + 1;
            $items = array_slice($items, 0, $offset, true) + $newItem + array_slice($items, $offset, null, true);
        } else {
            $items = $items + $newItem;
        }

        return $items;
    }

    public function registerEndpoint($endpoints)
    {
        $endpoints['gift-cards'] = [
            'slug' => 'gift-cards',
            'title' => __('Gift Cards', 'fluent-cart-gift-cards'),
            'render_callback' => [$this, 'renderContent']
        ];
        return $endpoints;
    }

    public function renderContent()
    {
        $userId = get_current_user_id();
        $service = new GiftCardService();
        $cards = $service->getCardsByUser($userId);

        // Styling inline or rely on global styles. FluentCart classes fc-table etc used.
        ?>
        <div class="fct_customer_profile_content">
            <h3 class="fct_page_title"><?php esc_html_e('My Gift Cards', 'fluent-cart-gift-cards'); ?></h3>
            
            <?php if ($cards->isEmpty()): ?>
                <div class="fct_empty_state">
                    <p><?php esc_html_e('You do not have any gift card history.', 'fluent-cart-gift-cards'); ?></p>
                </div>
            <?php else: ?>
                <div class="fc-table-responsive">
                    <table class="fc-table fct_table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Coupon Code', 'fluent-cart-gift-cards'); ?></th>
                                <th><?php esc_html_e('Status', 'fluent-cart-gift-cards'); ?></th>
                                <th><?php esc_html_e('Value', 'fluent-cart-gift-cards'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cards as $card): ?>
                                <tr>
                                    <td>
                                        <code style="font-size: 1.1em; color: #2563eb; background: #eff6ff; padding: 2px 6px; border-radius: 4px;">
                                            <?php echo esc_html($card->code); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <?php 
                                            // 'display_status' is injected by GiftCardService (Active / Redeemed)
                                            $statusLabel = $card->display_status ?? ucfirst($card->status);
                                            $statusClass = ($statusLabel === 'Active' && $card->status === 'active') ? 'fc-badge-success' : 'fc-badge-secondary';
                                            
                                            echo '<span class="fc-badge ' . esc_attr($statusClass) . '">' . esc_html($statusLabel) . '</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            // Show the original face value if available, or current amount
                                            // Since we zero out 'amount' when redeemed, we might want to check meta for face value?
                                            // For this model, Coupon Amount IS the face value usually.
                                            // But if we zero it for logic, we lose it for display.
                                            // Service sets amount=0 if Redeemed. 
                                            // But let's assume user knows what they bought or we show nothing if 0?
                                            // Actually, showing $0.00 for Redeemed is fine.
                                            // But ideally we show "Face Value" ($50).
                                            // The coupon object from DB (before service map) had the value.
                                            // Service modified the object.
                                            // To show Face Value, we need to store it or not zero it out in Service?
                                            // Service set amount=0.
                                            // Let's just show it. Non-critical if it says $0 for Redeemed.
                                            echo wc_price($card->amount ?? 0); // Usage of wc_price or fluentcart format helper?
                                            // FluentCart Helper: \FluentCart\App\Helpers\Helper::formatMoney($amount)
                                            echo \FluentCart\App\Helpers\Helper::formatMoney($card->amount ?? 0);
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
