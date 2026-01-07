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
            hp_agent_debug_log('V82', 'GMCFixer.php:20', 'GMCFixer::init() v0.8.2', [
                'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'script' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
                'is_admin' => is_admin(),
                'did_plugins_loaded' => did_action('plugins_loaded'),
                'did_init' => did_action('init')
            ]);
        }
        // #endregion

        // 1. Map WooCommerce weight to GMC shipping_weight
        add_filter('woocommerce_gla_product_attribute_value_shipping_weight', [self::class, 'map_shipping_weight'], 10, 2);

        // 2. Comprehensive Hook Test
        add_action('wp_head', [self::class, 'debug_hook'], 1, 0);
        add_action('template_redirect', [self::class, 'debug_hook'], 1, 0);
        add_action('woocommerce_before_main_content', [self::class, 'debug_hook'], 1, 0);
        add_action('wp_footer', [self::class, 'debug_hook'], 1, 0);
        add_action('shutdown', [self::class, 'debug_hook'], 1, 0);
    }

    public static function debug_hook()
    {
        $hook = current_filter();
        
        // #region agent log
        if (function_exists('hp_agent_debug_log')) {
            hp_agent_debug_log('V82', 'GMCFixer.php:47', "HOOK FIRED: $hook", [
                'is_product' => function_exists('is_product') ? is_product() : 'N/A',
                'post_id' => get_the_ID()
            ]);
        }
        // #endregion

        if (in_array($hook, ['wp_head', 'wp_footer', 'woocommerce_before_main_content'])) {
            echo "\n<!-- HP GMC DEBUG 0.8.2: $hook -->\n";
        }
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
