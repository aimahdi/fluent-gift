<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCartGiftCards\App\Services\GiftCardService;

class CheckoutHandler
{
    public function __construct()
    {
        // Hooking before the Total line in the Order Summary for HTML
        add_action('fluent_cart/checkout/before_summary_total', [$this, 'renderGiftCardSection']);
        
        // Hooking to footer for JS to ensure it always loads
        add_action('wp_footer', [$this, 'printGiftCardScripts'], 99);

        // Filter store settings to hide coupon field if gift cards are available
        add_filter('option_fluent_cart_store_settings', [$this, 'filterStoreSettings']);
    }

    public function filterStoreSettings($settings)
    {
        if (!is_array($settings)) {
            return $settings;
        }

        // Only check if we haven't already hidden it (optimization)
        if (isset($settings['hide_coupon_field']) && $settings['hide_coupon_field'] === 'yes') {
            return $settings;
        }

        $userId = get_current_user_id();
        if (!$userId) {
            return $settings;
        }

        static $hasGiftCards = null;

        if ($hasGiftCards === null) {
            $service = new GiftCardService();
            $cards = $service->getCardsByUser($userId);
            
            // Check for at least one usable card
            $hasGiftCards = $cards->contains(function($card) {
                return $card->can_use === true && 
                       $card->amount > 0 && 
                       $card->status === 'active';
            });
        }

        if ($hasGiftCards) {
            $settings['hide_coupon_field'] = 'yes';
        }

        return $settings;
    }

    public function renderGiftCardSection()
    {
        // Only show for logged-in users
        $userId = get_current_user_id();
        if (!$userId) {
            return; // Guest users can still enter code manually below
        }

        $service = new GiftCardService();
        $cards = $service->getCardsByUser($userId);

        // Filter to only show cards where user has access (email in restrictions)
        $availableCards = $cards->filter(function($card) {
            // Only show active cards with balance and where user can use them
            return $card->can_use === true && 
                   $card->amount > 0 && 
                   $card->status === 'active';
        });

        if ($availableCards->isEmpty()) {
            return;
        }

        ?>
        <li class="fct_gift_card_section" style="flex-direction: column; align-items: flex-start;">
            
            <div class="fct_coupon_toggle" style="width: 100%; margin-bottom: 5px;">
                <a href="#" class="fct_gift_card_toggle" style="font-weight: 500;">
                    <?php _e('Have a Gift Card?', 'fluent-gift'); ?>
                </a>
            </div>

            <div class="fct_gift_card_container" style="display: none; width: 100%; margin-top: 10px;">
                
                <?php if ($availableCards->isNotEmpty()): ?>
                    <div class="fct_gift_card_list" style="margin-bottom: 15px;">
                        <span style="display:block; font-size: 12px; color: #666; margin-bottom: 5px;"><?php _e('Your Available Cards:', 'fluent-gift'); ?></span>
                        <?php foreach ($availableCards as $card): ?>
                            <div class="fct_gift_card_item" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; background: #f9fafb; padding: 8px; border-radius: 4px; border: 1px solid #e5e7eb;">
                                <span style="font-weight: 500; font-size: 13px;">
                                    <?php echo esc_html($card->code); ?> 
                                    <span style="color: #10b981;">(<?php echo \FluentCart\App\Helpers\Helper::toDecimal($card->amount); ?>)</span>
                                </span>
                                <button type="button" class="fct_gift_card_apply_btn_direct" data-code="<?php echo esc_attr($card->code); ?>" data-amount="<?php echo esc_attr($card->amount); ?>" style="background: none; border: none; color: var(--fct-checkout-primary-text-color, var(--fct-primary-bg-color, #2563eb)); cursor: pointer; font-size: 13px; font-weight: 600;">
                                    <?php _e('Apply', 'fluent-gift'); ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Input removed as per request -->
                <div id="fct_gift_card_msg" style="margin-top: 5px; font-size: 12px;"></div>
            </div>
        </li>
        <?php
    }

    public function printGiftCardScripts()
    {
        ?>
        <script type="text/javascript">
            jQuery(function($) {
                // Determine ajaxurl and nonce logic. 
                // fluentcart_checkout_vars is usually available on checkout page.
                // If not, we might need fallback or check localized script handles.
                var fcAjaxUrl = window.fluentcart_checkout_vars ? window.fluentcart_checkout_vars.ajaxurl : '';
                var fcNonce = window.fluentcart_checkout_vars ? window.fluentcart_checkout_vars.nonce : '';

                if(!fcAjaxUrl) {
                    // Fallback or early exit if not found
                    console.warn('FluentCart GiftCards: fluentcart_checkout_vars not found');
                    // We might try to find it from standard WP localized vars if available
                    // But usually it should be there on checkout.
                }

                // Toggle Visibility
                $(document.body).on('click', '.fct_gift_card_toggle', function(e) {
                        e.preventDefault();
                        $(this).closest('.fct_gift_card_section').find('.fct_gift_card_container').slideToggle();
                });

                // Direct Apply Handler
                $(document.body).on('click', '.fct_gift_card_apply_btn_direct', function(e) {
                    e.preventDefault();
                    var code = $(this).data('code');
                    fctApplyGiftCard($(this), code);
                });

                // Manual Apply Handler
                // Manual handler removed

                function fctApplyGiftCard($btn, code) {
                    var $container = $btn.closest('.fct_gift_card_container');
                    var $msg = $container.find('#fct_gift_card_msg');
                    
                    $msg.text('<?php _e('Applying...', 'fluent-gift'); ?>').css('color', '#666');

                    if(!fcAjaxUrl) {
                        $msg.text('Error: Ajax URL missing').css('color', 'red');
                        return;
                    }

                    // Apply the coupon - validation happens server-side
                    var urlParams = new URLSearchParams(window.location.search);
                    var fctCartHash = urlParams.get('fct_cart_hash');

                    var data = {
                        action: 'fluent_cart_checkout_routes',
                        fc_checkout_action: 'apply_coupon',
                        coupon_code: code,
                        _wpnonce: fcNonce
                    };
                    
                    if (fctCartHash) {
                        data.fct_cart_hash = fctCartHash;
                    }

                    $.post(fcAjaxUrl, data, function(response) {
                        if (response.fragments) {
                            $msg.text('<?php _e('Applied Successfully!', 'fluent-gift'); ?>').css('color', 'green');
                            // Replace Fragments
                            $.each(response.fragments, function(index, fragment) {
                                if(fragment.type === 'replace') {
                                    $(fragment.selector).replaceWith(fragment.content);
                                }
                            });
                        } else if (response.message) {
                            // Show the actual error message from the server
                            $msg.text(response.message).css('color', 'red');
                        } else {
                            var error = '<?php _e('Failed to apply.', 'fluent-gift'); ?>';
                            $msg.text(error).css('color', 'red');
                        }
                    }).fail(function(xhr) {
                        var res = xhr.responseJSON;
                        if (res && res.message) {
                            // Only show "add items" if it's specifically a no cart error
                            if (res.message.toLowerCase().indexOf('no active cart') !== -1 || 
                                res.message.toLowerCase().indexOf('no cart found') !== -1) {
                                $msg.text('<?php _e('Please add items to cart first.', 'fluent-gift'); ?>').css('color', 'red');
                            } else {
                                $msg.text(res.message).css('color', 'red');
                            }
                        } else {
                            var error = '<?php _e('Error applying gift card.', 'fluent-gift'); ?>';
                            $msg.text(error).css('color', 'red');
                        }
                    });
                }
            });
        </script>
        <?php
    }
}
