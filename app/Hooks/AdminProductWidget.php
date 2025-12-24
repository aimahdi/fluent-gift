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
                    'label' => __('This is aGift Card Product', 'fluent-cart-gift-cards'),
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
        } else {
            // If the widget was present but unchecked/empty, handle accordingly.
            // CAUTION: Ensure this doesn't wipe data if widget wasn't loaded. 
            // In FluentCart context, assume this runs on product save where widget is present.
            // Safest to check if form_name key exists in metaValue
            if (isset($data['data']['metaValue']['fct_gift_card_settings'])) {
                $product->updateProductMeta('_fct_is_gift_card', 'no');
            }
        }

    }
}
