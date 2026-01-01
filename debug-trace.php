<?php
/**
 * Helper script to test ability execution exactly like MCP does.
 */
$path = dirname(__FILE__);
while ($path != '/' && !file_exists($path . '/wp-load.php')) {
    $path = dirname($path);
}
include $path . '/wp-load.php';

set_error_handler(function($s,$m,$f,$l){ 
    throw new ErrorException($m,0,$s,$f,$l); 
});

header('Content-Type: text/plain');

$slug = $_GET['slug'] ?? 'liver-detox-protocol';
$test = $_GET['test'] ?? 'all';

try {
    echo "===== MCP Ability Execution Test =====\n\n";
    
    // Mimic REST environment
    if (!defined('REST_REQUEST')) define('REST_REQUEST', true);
    
    // Get the ability like MCP does
    echo "1. Getting ability 'hp-funnels/get' via wp_get_ability()...\n";
    $ability = wp_get_ability('hp-funnels/get');
    if (!$ability) {
        echo "   ERROR: Ability not found!\n";
        exit(1);
    }
    echo "   Found: " . $ability->get_name() . "\n";
    
    // Simulate input exactly like MCP sends it (from JSON decode - as array)
    echo "\n2. Creating input (simulating get_json_params which returns array)...\n";
    $input = ['slug' => $slug];
    echo "   Input type: " . gettype($input) . "\n";
    echo "   Input: " . json_encode($input) . "\n";
    
    // Check permissions like MCP does
    echo "\n3. Checking permissions via ability->check_permissions()...\n";
    $has_permission = $ability->check_permissions($input);
    if (true !== $has_permission) {
        echo "   Permission check returned: " . var_export($has_permission, true) . "\n";
    } else {
        echo "   Permission check passed.\n";
    }
    
    // Execute like MCP does
    echo "\n4. Executing via ability->execute()...\n";
    $result = $ability->execute($input);
    
    if (is_wp_error($result)) {
        echo "   WP_Error: " . $result->get_error_message() . "\n";
    } else {
        echo "   Success: " . (isset($result['success']) && $result['success'] ? 'YES' : 'NO') . "\n";
        echo "   Result type: " . gettype($result) . "\n";
        echo "   Has funnel: " . (isset($result['funnel']) ? 'YES' : 'NO') . "\n";
        if (isset($result['funnel'])) {
            echo "   Funnel slug: " . ($result['funnel']['slug'] ?? 'N/A') . "\n";
        }
    }
    
    // Also test seoAudit for comparison
    if ($test === 'all') {
        echo "\n\n===== Testing seoAudit for comparison =====\n\n";
        $ability2 = wp_get_ability('hp-funnels/seo-audit');
        if ($ability2) {
            $result2 = $ability2->execute(['slug' => $slug]);
            if (is_wp_error($result2)) {
                echo "   WP_Error: " . $result2->get_error_message() . "\n";
            } else {
                echo "   Success: " . (isset($result2['success']) && $result2['success'] ? 'YES' : 'NO') . "\n";
                echo "   Status: " . ($result2['data']['status'] ?? 'N/A') . "\n";
            }
        }
    }
    
    echo "\n===== Test Complete =====\n";
    
} catch (Throwable $e) {
    echo "\nERROR FOUND:\n";
    echo $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n\n";
    echo "STACK TRACE:\n";
    echo $e->getTraceAsString() . "\n";
}

