<?php
/**
 * Plugin Name:       HP Abilities
 * Description:       Exposes WooCommerce capabilities via the WordPress Abilities API for AI agent integrations.
 * Version:           0.5.100
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Holistic People
 * License:           GPL-2.0-or-later
 * Text Domain:       hp-abilities
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HP_ABILITIES_VERSION', '0.5.100');
define('HP_ABILITIES_FILE', __FILE__);
define('HP_ABILITIES_PATH', plugin_dir_path(__FILE__));
define('HP_ABILITIES_URL', plugin_dir_url(__FILE__));

// Simple autoloader
spl_autoload_register(function ($class) {
    $prefix = 'HP_Abilities\\';
    $base_dir = HP_ABILITIES_PATH . 'includes/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize plugin as early as possible to catch abilities_api_init
if (function_exists('wp_register_ability') || did_action('plugins_loaded')) {
    \HP_Abilities\Plugin::init();
} else {
    add_action('plugins_loaded', function () {
        \HP_Abilities\Plugin::init();
    });
}

// Add settings link
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
    $url = admin_url('options-general.php?page=hp-abilities');
    $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'hp-abilities') . '</a>';
    return $links;
});
