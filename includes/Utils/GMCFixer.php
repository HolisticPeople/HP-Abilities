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
}

