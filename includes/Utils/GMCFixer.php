<?php
namespace HP_Abilities\Utils;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles automatic mapping of WooCommerce data to Google Merchant Center (GMC) requirements.
 * Focuses on filters for the "Google Listings & Ads" plugin and Yoast SEO.
 */
class GMCFixer
{
    /**
     * Initialize filters.
     */
    public static function init(): void
    {
        // 1. Map WooCommerce weight to GMC shipping_weight in "Google Listings & Ads" plugin
        add_filter('woocommerce_gla_product_attribute_value_shipping_weight', [self::class, 'map_shipping_weight'], 10, 2);

        // 2. Add raw schema to footer as a failsafe if Yoast is failing
        add_action('wp_footer', [self::class, 'inject_raw_gmc_schema'], 99);
    }

    /**
     * Map WC product weight to the GMC shipping_weight attribute.
     */
    public static function map_shipping_weight($value, \WC_Product $product)
    {
        if (!empty($value)) return $value;
        $weight = $product->get_weight();
        if (empty($weight)) return $value;
        $unit = get_option('woocommerce_weight_unit', 'lb');
        return $weight . ' ' . $unit;
    }

    /**
     * Inject a dedicated GMC-compliant schema block if standard ones are missing.
     */
    public static function inject_raw_gmc_schema(): void
    {
        if (!is_singular('product')) return;

        $product = wc_get_product(get_the_ID());
        if (!$product) return;

        $unit = get_option('woocommerce_weight_unit', 'lb');
        $weight = $product->get_weight();
        
        $data = [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format((float)$product->get_price(), 2, '.', ''),
                'priceCurrency' => get_woocommerce_currency(),
                'availability' => 'https://schema.org/' . ($product->is_in_stock() ? 'InStock' : 'OutOfStock'),
                'url' => get_permalink($product->get_id())
            ]
        ];

        if ($weight) {
            $data['weight'] = [
                '@type' => 'QuantitativeValue',
                'value' => $weight,
                'unitText' => $unit
            ];
        }

        echo "\n<!-- HP GMC Compliance Bridge -->\n";
        echo '<script type="application/ld+json">' . wp_json_encode($data) . '</script>' . "\n";
    }
}

