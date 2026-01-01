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

try {
    echo "===== Testing getFunnel step by step =====\n\n";
    
    // Simulate authenticated user
    wp_set_current_user(1); // Admin user
    
    echo "1. Direct call to FunnelApi::getFunnel()...\n";
    $result = \HP_Abilities\Abilities\FunnelApi::getFunnel(['slug' => $slug]);
    echo "   Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
    echo "   Has funnel: " . (isset($result['funnel']) ? 'YES' : 'NO') . "\n";
    
    // Check for stdClass in result
    echo "\n2. Checking for stdClass objects in result...\n";
    function findStdClass($data, $path = '') {
        if (is_object($data)) {
            if ($data instanceof stdClass) {
                echo "   FOUND stdClass at: $path\n";
                echo "   Keys: " . implode(', ', array_keys((array)$data)) . "\n";
                return true;
            }
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (findStdClass($value, $path . "[$key]")) {
                    return true;
                }
            }
        }
        return false;
    }
    $hasStdClass = findStdClass($result);
    if (!$hasStdClass) {
        echo "   No stdClass objects found - all good!\n";
    }
    
    // Test output validation
    echo "\n3. Testing output validation (what WP_Ability does after execute)...\n";
    $output_schema = [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'funnel' => [
                'type' => 'object',
                'properties' => [],  // Fixed: use [] instead of (object) []
            ],
        ],
    ];
    $valid_output = rest_validate_value_from_schema($result, $output_schema, 'output');
    if (is_wp_error($valid_output)) {
        echo "   Validation failed: " . $valid_output->get_error_message() . "\n";
    } else {
        echo "   Output validation passed.\n";
    }
    
    // Test via ability->execute with auth
    echo "\n4. Calling via ability->execute() with auth...\n";
    $ability = wp_get_ability('hp-funnels/get');
    $result2 = $ability->execute(['slug' => $slug]);
    if (is_wp_error($result2)) {
        echo "   WP_Error: " . $result2->get_error_message() . "\n";
    } else {
        echo "   Success: " . ($result2['success'] ? 'YES' : 'NO') . "\n";
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

