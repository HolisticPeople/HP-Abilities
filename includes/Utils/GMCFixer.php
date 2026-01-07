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
            hp_agent_debug_log('V81', 'GMCFixer.php:20', 'GMCFixer::init() v0.8.1 start', [
                'ver' => defined('HP_ABILITIES_VERSION') ? HP_ABILITIES_VERSION : 'unknown'
            ]);
        }
        // #endregion

        // 1. Map WooCommerce weight to GMC shipping_weight in "Google Listings & Ads" plugin
        add_filter('woocommerce_gla_product_attribute_value_shipping_weight', [self::class, 'map_shipping_weight'], 10, 2);

        // 2. Add raw schema - RAW LOG TEST
        add_action('wp_head', [self::class, 'raw_log_test'], 1);
    }

    public static function raw_log_test()
    {
        // #region agent log
        if (function_exists('hp_agent_debug_log')) {
            hp_agent_debug_log('V81', 'GMCFixer.php:38', 'RAW LOG TEST: wp_head fired', [
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'is_product' => function_exists('is_product') ? is_product() : 'N/A'
            ]);
        }
        // #endregion
        
        // Final attempt: inject a comment no matter what
        echo "\n<!-- HP GMC DEBUG 0.8.1 -->\n";
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
}
