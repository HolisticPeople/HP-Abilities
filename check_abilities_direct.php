<?php
/**
 * Diagnostic script to check HP Abilities registration.
 */

// Load WordPress
require_once 'wp-load.php';

echo "--- HP ABILITIES DIAGNOSTIC ---\n";
echo "WP Version: " . get_bloginfo('version') . "\n";
echo "HP Abilities Version: " . (defined('HP_ABILITIES_VERSION') ? HP_ABILITIES_VERSION : 'NOT DEFINED') . "\n";
echo "wp_register_ability exists: " . (function_exists('wp_register_ability') ? 'YES' : 'NO') . "\n";

if (function_exists('wp_register_ability')) {
    echo "Current Abilities Count: " . count(wp_get_abilities()) . "\n";
    
    // Manually trigger HP registration if not present
    if (!isset(wp_get_abilities()['hp-abilities/test-hello'])) {
        echo "Attempting manual registration of test-hello...\n";
        
        // Ensure classes are loaded
        if (class_exists('\HP_Abilities\Plugin')) {
            echo "HP_Abilities\Plugin exists. Calling register_abilities()...\n";
            \HP_Abilities\Plugin::register_abilities();
        } else {
            echo "ERROR: HP_Abilities\Plugin NOT FOUND.\n";
        }
    }
    
    echo "Final Abilities List:\n";
    print_r(array_keys(wp_get_abilities()));
}

echo "--- END DIAGNOSTIC ---\n";





