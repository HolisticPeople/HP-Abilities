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
     * Initialize filters and shortcodes.
     */
    public static function init(): void
    {
        // 1. Map WooCommerce weight to GMC shipping_weight in "Google Listings & Ads" plugin
        add_filter('woocommerce_gla_product_attribute_value_shipping_weight', [self::class, 'map_shipping_weight'], 10, 2);

        // 2. Add raw schema to hooks as a failsafe
        add_action('woocommerce_after_single_product', [self::class, 'inject_raw_gmc_schema'], 1);
        add_action('wp_footer', [self::class, 'inject_raw_gmc_schema_footer'], 9999);

        // 3. Register shortcode for Elementor templates
        add_shortcode('hp_gmc_schema', [self::class, 'render_gmc_schema_shortcode']);
    }

    /**
     * Shortcode renderer for Elementor.
     */
    public static function render_gmc_schema_shortcode(): string
    {
        ob_start();
        self::inject_raw_gmc_schema('SHORTCODE');
        return ob_get_clean();
    }

    public static function inject_raw_gmc_schema_footer(): void
    {
        self::inject_raw_gmc_schema('FOOTER');
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
     * Get the brand name from YITH Brands plugin taxonomy.
     */
    public static function get_product_brand(int $product_id): string
    {
        $terms = get_the_terms($product_id, 'yith_product_brand');
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0]->name;
        }
        
        // Fallback to "product_brand" taxonomy which was also seen in list
        $terms = get_the_terms($product_id, 'product_brand');
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0]->name;
        }

        return 'HolisticPeople'; // Final fallback
    }

    /**
     * Inject a dedicated GMC-compliant schema block.
     */
    public static function inject_raw_gmc_schema(string $context = 'WC'): void
    {
        // Only on product pages
        if (!is_product() && !is_singular('product')) {
            return;
        }

        $product_id = get_the_ID();
        if (!$product_id) return;

        $product = wc_get_product($product_id);
        if (!$product) return;

        $unit = get_option('woocommerce_weight_unit', 'lb');
        $weight = $product->get_weight();
        $brand_name = self::get_product_brand($product_id);
        
        $data = [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
            'image' => get_the_post_thumbnail_url($product->get_id(), 'full'),
            'brand' => [
                '@type' => 'Brand',
                'name' => $brand_name
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

        echo "\n<!-- HP GMC Compliance Bridge ({$context}) -->\n";
        echo '<script type="application/ld+json">' . wp_json_encode($data) . '</script>' . "\n";
    }
}
