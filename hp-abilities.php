<?php
/**
 * Plugin Name:       HP Abilities
 * Description:       Exposes WooCommerce capabilities via the WordPress Abilities API for AI agent integrations.
 * Version:           0.5.54
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Holistic People
 * License:           GPL-2.0-or-later
 * Text Domain:       hp-abilities
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HP_ABILITIES_VERSION', '0.5.54');
define('HP_ABILITIES_FILE', __FILE__);
define('HP_ABILITIES_PATH', plugin_dir_path(__FILE__));
define('HP_ABILITIES_URL', plugin_dir_url(__FILE__));

// Simple autoloader
spl_autoload_register(function ($class) {
    // #region agent log
    $log = ['sessionId' => 'debug-site-crash', 'runId' => 'initial', 'hypothesisId' => 'A', 'location' => 'hp-abilities.php:24', 'message' => 'Autoloader check', 'data' => ['class' => $class], 'timestamp' => microtime(true)*1000];
    file_put_contents('c:\DEV\WC Plugins\My Plugins\HP-React-Widgets\.cursor\debug.log', json_encode($log) . PHP_EOL, FILE_APPEND);
    // #endregion
    $prefix = 'HP_Abilities\\';
    $base_dir = HP_ABILITIES_PATH . 'includes/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';
    // #region agent log
    $log = ['sessionId' => 'debug-site-crash', 'runId' => 'initial', 'hypothesisId' => 'B', 'location' => 'hp-abilities.php:36', 'message' => 'Autoloader attempting file', 'data' => ['file' => $file, 'exists' => file_exists($file)], 'timestamp' => microtime(true)*1000];
    file_put_contents('c:\DEV\WC Plugins\My Plugins\HP-React-Widgets\.cursor\debug.log', json_encode($log) . PHP_EOL, FILE_APPEND);
    // #endregion
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize plugin
add_action('plugins_loaded', function () {
    // #region agent log
    $log = ['sessionId' => 'debug-site-crash', 'runId' => 'initial', 'hypothesisId' => 'C', 'location' => 'hp-abilities.php:47', 'message' => 'plugins_loaded hook triggered', 'data' => [], 'timestamp' => microtime(true)*1000];
    file_put_contents('c:\DEV\WC Plugins\My Plugins\HP-React-Widgets\.cursor\debug.log', json_encode($log) . PHP_EOL, FILE_APPEND);
    // #endregion
    // Check if Abilities API is available (WordPress 6.9+)
    if (!function_exists('wp_register_ability')) {
        // Abilities API not available - show admin notice
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('HP Abilities requires WordPress 6.9+ with the Abilities API. Some features may be limited.', 'hp-abilities');
            echo '</p></div>';
        });
        
        // Still register REST API endpoints as fallback
        \HP_Abilities\Plugin::init_rest_fallback();
        return;
    }
    
    \HP_Abilities\Plugin::init();
});

// Add settings link
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
    $url = admin_url('options-general.php?page=hp-abilities');
    $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'hp-abilities') . '</a>';
    return $links;
});



