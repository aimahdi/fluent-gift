<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCartGiftCards\App\Services\GiftCardService;

class CheckoutHandler
{
    public function __construct()
    {
        // Placeholder Hook - Will confirm with user
        add_action('fluent_cart/checkout/review_order_after_coupon', [$this, 'renderGiftCardSection']);
    }

    public function renderGiftCardSection()
    {
        if (!is_user_logged_in()) {
            return; // Guests use the standard coupon field
        }

        $userId = get_current_user_id();
        $service = new GiftCardService();
        $cards = $service->getCardsByUser($userId);

        if (empty($cards)) {
            return;
        }

        echo '<div class="fct-gift-card-wallet" style="margin-top: 20px; padding: 15px; border: 1px solid #e5e7eb; border-radius: 5px;">';
        echo '<h4 style="margin-bottom: 10px;">' . __('Your Gift Cards', 'fluent-cart-gift-cards') . '</h4>';
        
        foreach ($cards as $card) {
            // Check status or >0 balance just in case
            if($card->amount <= 0) continue;

            $formattedBalance = $card->amount; // Helper::formatMoney() would be better but keeping simple
            
            echo '<div class="fct-wallet-item" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">';
            echo '<span>' . esc_html($card->code) . ' (' . esc_html($formattedBalance) . ')</span>';
            
            // Inline JS to autofill existing coupon field
            // Note: Selectors strictly depend on FluentCart CSS classes. 
            // Typically: input[name="coupon_code"] or .fc_coupon_input
            $jsAction = "
                const input = document.querySelector('input[name=\"coupon_code\"], .fc_coupon_input');
                const btn = document.querySelector('.fc_coupon_apply_btn, .fc-apply-coupon');
                if(input && btn) {
                    input.value = '".esc_js($card->code)."';
                    btn.click();
                } else {
                    alert('Coupon field not found. Code: " . esc_js($card->code) . "');
                }
            ";

            echo '<button type="button" class="fc-btn fc-btn-outline fc-btn-sm" onclick="'.esc_attr($jsAction).'">' . __('Apply', 'fluent-cart-gift-cards') . '</button>';
            
            echo '</div>';
        }
        echo '</div>';
    }
}
