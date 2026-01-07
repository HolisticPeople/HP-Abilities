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
        // #region agent log
        if (function_exists('hp_agent_debug_log')) {
            hp_agent_debug_log('AB', 'GMCFixer.php:20', 'GMCFixer::init() start');
        }
        // #endregion

        // 1. Map WooCommerce weight to GMC shipping_weight in "Google Listings & Ads" plugin
        add_filter('woocommerce_gla_product_attribute_value_shipping_weight', [self::class, 'map_shipping_weight'], 10, 2);

        // 2. Add raw schema to various hooks to see which one sticks on production
        add_action('wp_head', [self::class, 'inject_raw_gmc_schema_head'], 9999);
        add_action('woocommerce_after_single_product', [self::class, 'inject_raw_gmc_schema_wc'], 9999);
        add_action('wp_footer', [self::class, 'inject_raw_gmc_schema_footer'], 9999);
    }

    public static function inject_raw_gmc_schema_head() { self::inject_raw_gmc_schema('HEAD'); }
    public static function inject_raw_gmc_schema_wc() { self::inject_raw_gmc_schema('WC_HOOK'); }
    public static function inject_raw_gmc_schema_footer() { self::inject_raw_gmc_schema('FOOTER'); }

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
     * Inject a dedicated GMC-compliant schema block.
     */
    public static function inject_raw_gmc_schema(string $hook_name): void
    {
        $is_prod = is_product();
        $is_sing = is_singular('product');

        // #region agent log
        if (function_exists('hp_agent_debug_log')) {
            hp_agent_debug_log('AB', 'GMCFixer.php:55', 'inject_raw_gmc_schema triggered', [
                'hook' => $hook_name,
                'is_product' => $is_prod,
                'is_singular' => $is_sing,
                'post_id' => get_the_ID()
            ]);
        }
        // #endregion

        // If we are on a product page, inject the schema
        if ($is_prod || $is_sing) {
            $id = get_the_ID();
            $product = wc_get_product($id);
            if (!$product) return;

            $unit = get_option('woocommerce_weight_unit', 'lb');
            $weight = $product->get_weight();
            
            $data = [
                '@context' => 'https://schema.org/',
                '@type' => 'Product',
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
                'image' => get_the_post_thumbnail_url($product->get_id(), 'full'),
                'brand' => [
                    '@type' => 'Brand',
                    'name' => 'HolisticPeople'
                ],
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

            echo "\n<!-- HP GMC Compliance Bridge ({$hook_name}) -->\n";
            echo '<script type="application/ld+json">' . wp_json_encode($data) . '</script>' . "\n";
        }
    }
}
