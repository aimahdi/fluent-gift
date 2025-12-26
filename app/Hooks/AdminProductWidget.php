<?php

namespace FluentCartGiftCards\App\Hooks;

use FluentCart\App\Models\Product;

class AdminProductWidget
{
    public function register()
    {
        // dd('Register method called');
        add_filter('fluent_cart/widgets/single_product_page', [$this, 'addProductWidget'], 10, 2);
        add_action('fluent_cart/product_updated', [$this, 'saveProductWidget'], 10, 1);
        add_action('admin_footer', [$this, 'injectAdminScripts']);
    }

    public function injectAdminScripts()
    {
        // specific check for fluent cart product page
        if (!isset($_GET['page']) || strpos($_GET['page'], 'fluent-cart') === false) {
             return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                console.log('FluentCart Gift Card Script: Loaded');

                function lockGiftCardOptions() {
                    // Check if the Gift Card checkbox exists and is checked
                    var $checkbox = $('input[name="product_meta[_fct_is_gift_card]"]');
                    if (!$checkbox.length) return; // Not on the right component yet

                    var isGiftCard = $checkbox.is(':checked');
                    
                    if (isGiftCard) {
                        // Selectors for elements to lock
                        // We use partially matching selectors to be robust
                        var $typeDropdown = $('.fct-product-pricing-wrap .el-card__header .el-select');
                        var $bodySelects = $('.fct-product-pricing-wrap .el-card__body .el-select');

                        if ($typeDropdown.length) {
                             $typeDropdown.css({'opacity': '0.5', 'pointer-events': 'none'}).attr('title', 'Locked: Gift Cards must be Simple Products');
                             // Find the input inside to force value? Hard with Vue.
                             // Just Visual Lock is enough combined with Backend Force.
                        }
                        
                        // Assumption: Payment Type is likely the first select in the body or near "Payment Terms" label
                        if ($bodySelects.length > 0) {
                             // Lock the first one (usually Payment Type)
                             var $paymentSelect = $bodySelects.first();
                             $paymentSelect.css({'opacity': '0.5', 'pointer-events': 'none'}).attr('title', 'Locked: Gift Cards must be One-Time Payment');
                        }
                    } else {
                         // Unlock (reset styles)
                         $('.fct-product-pricing-wrap .el-select').css({'opacity': '', 'pointer-events': ''}).removeAttr('title');
                    }
                }

                // Vue renders asynchronously and re-renders often.
                // We use a recurring check to ensure the lock persists.
                setInterval(lockGiftCardOptions, 500);
            });
        </script>
        <?php
    }

    public function addProductWidget($widgets, $product)
    {
        $productId = $product['product_id'];

        $product = Product::query()->where('id', $productId)->first();


        $isGiftCard = $product->getProductMeta('_fct_is_gift_card');


        $widgets[] = [
            'title' => __('Gift Card Settings', 'fluent-cart-gift-cards'),
            'sub_title' => __('Configure Gift Card options for this product.', 'fluent-cart-gift-cards'),
            'type' => 'form',
            'form_name' => 'fct_gift_card_settings',
            'name' => 'gift_card_settings',
            'schema' => [
                'is_gift_card' => [
                    'wrapperClass' => 'col-span-2 flex items-start flex-col',
                    'label' => __('This is a Gift Card', 'fluent-cart-gift-cards'),
                    'type' => 'checkbox', // Assuming checkbox is supported
                    'checkbox_label' => __('Yes, this product is a Gift Card', 'fluent-cart-gift-cards'),
                    'true_value' => 'yes',
                    'false_value' => 'no'
                ],
            ],
            'values' => [
                'is_gift_card' => $isGiftCard
            ]
        ];

        return $widgets;
    }

    public function saveProductWidget($data)
    {
        $product = $data['product'];

        if (isset($data['data']['metaValue']['fct_gift_card_settings']['is_gift_card'])) {
            $isGiftCard = $data['data']['metaValue']['fct_gift_card_settings']['is_gift_card'];

            // Normalize checkbox value if needed (often comes as 'yes' or boolean or just present)
            // Based on user sample, we access via form_name structure
            $product->updateProductMeta('_fct_is_gift_card', $isGiftCard);

            
            // Sync Coupons if enabled
            if ($isGiftCard === 'yes') {
                $service = new \FluentCartGiftCards\App\Services\GiftCardService();
                $service->syncProductCoupons($product, true);

                // FORCE 'simple' variation type to simplify UX as requested
                // This updates the ProductDetail model directly
                $detail = $product->detail; 
                if ($detail && $detail->variation_type !== 'simple') {
                    $detail->variation_type = 'simple';
                    $detail->save();
                }

                // FORCE 'onetime' payment type for all variations (One-Time Payment)
                foreach ($product->variants as $variant) {
                    if ($variant->payment_type !== 'onetime') {
                        $variant->payment_type = 'onetime';
                        $variant->save();
                    }
                }
            } else {
                // If explicitly set to 'no' (or anything other than 'yes'), deactivate coupons
                $service = new \FluentCartGiftCards\App\Services\GiftCardService();
                $service->syncProductCoupons($product, false);
            }
        } else {
            // If the widget was present but unchecked/empty, handle accordingly.
            // CAUTION: Ensure this doesn't wipe data if widget wasn't loaded. 
            // In FluentCart context, assume this runs on product save where widget is present.
            // Safest to check if form_name key exists in metaValue
            if (isset($data['data']['metaValue']['fct_gift_card_settings'])) {
                $product->updateProductMeta('_fct_is_gift_card', 'no');
                
                // Also sync coupons to inactive since it's now disabled
                $service = new \FluentCartGiftCards\App\Services\GiftCardService();
                $service->syncProductCoupons($product, false);
            }
        }

    }
}
