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
        // Ref: https://github.com/woocommerce/google-listings-and-ads/blob/main/src/Product/WCProductAdapter.php
        add_filter('woocommerce_gla_product_attribute_value_shipping_weight', [self::class, 'map_shipping_weight'], 10, 2);

        // 2. Ensure Yoast SEO Structured Data also reflects the correct weight
        add_filter('wpseo_schema_product', [self::class, 'filter_yoast_schema_weight'], 20, 2);

        // 3. Forcefully add Product piece if missing (fix for "ItemPage" only issues)
        add_filter('wpseo_schema_graph_pieces', [self::class, 'force_product_schema_piece'], 15, 2);
    }

    /**
     * Map WC product weight to the GMC shipping_weight attribute.
     * This ensures the feed always has weight if the product has it.
     */
    public static function map_shipping_weight($value, \WC_Product $product)
    {
        // If the value is already set, respect it (unless it's empty)
        if (!empty($value)) {
            return $value;
        }

        $weight = $product->get_weight();
        if (empty($weight)) {
            return $value;
        }

        $unit = get_option('woocommerce_weight_unit', 'lb');
        
        // GMC expects a string with unit, e.g. "0.32 lb"
        return $weight . ' ' . $unit;
    }

    /**
     * Inject weight into Yoast's Product Schema if missing.
     */
    public static function filter_yoast_schema_weight($data, $context)
    {
        if (!isset($data['weight']) && isset($context->id)) {
            $product = wc_get_product($context->id);
            if ($product && $product->get_weight()) {
                $unit = get_option('woocommerce_weight_unit', 'lb');
                $data['weight'] = [
                    '@type' => 'QuantitativeValue',
                    'value' => $product->get_weight(),
                    'unitText' => $unit
                ];
            }
        }
        return $data;
    }

    /**
     * Forcefully add a Product schema piece if Yoast's standard one is missing.
     * This is a fallback for sites where Yoast identifies products as ItemPage but skips Product type.
     */
    public static function force_product_schema_piece($pieces, $context)
    {
        // For debugging: log that we are here
        // error_log('GMCFixer: checking schema pieces for ' . get_the_ID());

        if (!is_singular('product')) {
            return $pieces;
        }

        // Add our custom product piece regardless of others for a quick test
        $pieces[] = new class($context) {
                private $context;
                public function __construct($context) { $this->context = $context; }
                public function is_needed(): bool { return true; }
                public function generate(): array {
                    $product = wc_get_product($this->context->id);
                    if (!$product) return [];
                    
                    $unit = get_option('woocommerce_weight_unit', 'lb');
                    $weight = $product->get_weight();
                    
                    $data = [
                        '@type' => 'Product',
                        '@id' => get_permalink($product->get_id()) . '#product',
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
                        'url' => get_permalink($product->get_id()),
                        'mainEntityOfPage' => [
                            '@id' => get_permalink($product->get_id()) . '#webpage'
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

                    return $data;
                }
            };
        }

        return $pieces;
    }
}

