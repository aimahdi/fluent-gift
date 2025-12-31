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
                'label' => __('Gift Cards', 'fluent-gift'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'gift-cards',
                'icon_svg' => '<svg style="padding: 2px;" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.25v8.25a1.5 1.5 0 0 1-1.5 1.5H4.5a1.5 1.5 0 0 1-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 1 0 9.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1 1 14.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"></path></svg>'
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
            'title' => __('Gift Cards', 'fluent-gift'),
            'render_callback' => [$this, 'renderContent']
        ];
        return $endpoints;
    }

    public function renderContent()
    {
        $userId = get_current_user_id();
        if (!$userId) {
            echo '<div class="fct_customer_profile_content"><p>' . esc_html__('Please log in to view your gift cards.', 'fluent-gift') . '</p></div>';
            return;
        }

        $service = new GiftCardService();
        $cards = $service->getCardsByUser($userId);
        ?>
        <div class="fct-customer-dashboard fct-customer-dashboard-layout-width">
            <div class="fct-customer-dashboard-header">
                <h4 class="fct-customer-dashboard-title" style="font-weight: bold;">
                    <?php esc_html_e('My Gift Cards', 'fluent-gift'); ?>
                </h4>
            </div>

            <?php if ($cards->isEmpty()): ?>
                <div class="fct-customer-dashboard-item">
                    <p class="text-center" style="padding: 2rem 0; color: var(--fluent-cart-customer-dashboard-text-color, #6b7280);">
                        <?php esc_html_e('You do not have any gift cards yet.', 'fluent-gift'); ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="fct-customer-dashboard-item">
                    <div class="fct-customer-dashboard-table">
                        <style>
                            .fct-customer-dashboard-table table {
                                width: 100% !important;
                                border-collapse: collapse; /* Ensure borders look nice with width 100% */
                            }
                            .fct-customer-dashboard-table table, 
                            .fct-customer-dashboard-table td, 
                            .fct-customer-dashboard-table th, 
                            .fct-customer-dashboard-table tr {
                              border: 1px solid var(--fct-customer-dashboard-border-color) !important;
                            }
                        </style>
                        <table>
                            <thead>
                                <tr>
                                    <th style="background-color: var(--fct-customer-dashboard-border-color, #f3f4f6); padding: 0.625rem 1.25rem; text-align: left; font-size: 0.875rem; font-weight: 500; color: var(--fluent-cart-customer-dashboard-title-color, #111827); border-bottom: 1px solid var(--fct-customer-dashboard-border-color, #e5e7eb);">
                                        <?php esc_html_e('GiftCard Code', 'fluent-gift'); ?>
                                    </th>
                                    <th style="background-color: var(--fct-customer-dashboard-border-color, #f3f4f6); padding: 0.625rem 1.25rem; text-align: left; font-size: 0.875rem; font-weight: 500; color: var(--fluent-cart-customer-dashboard-title-color, #111827); border-bottom: 1px solid var(--fct-customer-dashboard-border-color, #e5e7eb);">
                                        <?php esc_html_e('Status', 'fluent-gift'); ?>
                                    </th>
                                    <th style="background-color: var(--fct-customer-dashboard-border-color, #f3f4f6); padding: 0.625rem 1.25rem; text-align: left; font-size: 0.875rem; font-weight: 500; color: var(--fluent-cart-customer-dashboard-title-color, #111827); border-bottom: 1px solid var(--fct-customer-dashboard-border-color, #e5e7eb);">
                                        <?php esc_html_e('Balance', 'fluent-gift'); ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $cardCount = 0;
                                $totalCards = $cards->count();
                                foreach ($cards as $card): 
                                    $cardCount++;
                                    $isLastRow = ($cardCount === $totalCards);
                                    $borderBottom = $isLastRow ? 'border-bottom: 0;' : 'border-bottom: 1px solid var(--fct-customer-dashboard-border-color, #e5e7eb);';
                                ?>
                                    <tr>
                                        <td style="padding: 0.875rem 1.25rem; text-align: left; font-size: 0.875rem; color: var(--fluent-cart-customer-dashboard-text-color, #374151); border-left: 0; border-right: 0; border-top: 0; <?php echo $borderBottom; ?>">
                                            <span class="invoice-id">
                                                <?php echo esc_html($card->code); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 0.875rem 1.25rem; text-align: left; font-size: 0.875rem; color: var(--fluent-cart-customer-dashboard-text-color, #374151); border-left: 0; border-right: 0; border-top: 0; <?php echo $borderBottom; ?>">
                                            <?php 
                                                // 'display_status' is injected by GiftCardService (Active / Redeemed)
                                                $statusLabel = $card->display_status ?? ucfirst($card->status);
                                                $statusType = ($statusLabel === 'Active' && $card->can_use === true) ? 'success' : 'secondary';
                                                
                                                // Use FluentCart badge classes
                                                $badgeClass = 'fc-badge fc-badge-' . esc_attr($statusType) . ' fc-badge-small';
                                                echo '<span class="' . $badgeClass . '">' . esc_html($statusLabel) . '</span>';
                                            ?>
                                        </td>
                                        <td style="padding: 0.875rem 1.25rem; text-align: left; font-size: 0.875rem; color: var(--fluent-cart-customer-dashboard-text-color, #374151); border-left: 0; border-right: 0; border-top: 0; <?php echo $borderBottom; ?>">
                                            <span class="text">
                                                <?php 
                                                    // Show the current balance
                                                    // If redeemed (can_use = false), amount is already set to 0 by service
                                                    $balance = $card->can_use === true ? ($card->amount ?? 0) : 0;
                                                    echo \FluentCart\App\Helpers\Helper::toDecimal($balance);
                                                ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
