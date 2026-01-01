<?php
/**
 * Temporary test script for FunnelApi ability
 * Run via: wp eval-file test-ability-temp.php
 */

echo "Testing FunnelApi::getFunnel...\n\n";

// Test 1: Direct call with array input
echo "Test 1: Array input\n";
try {
    $input = ['slug' => 'liver-detox-protocol'];
    $result = \HP_Abilities\Abilities\FunnelApi::getFunnel($input);
    echo "  Success: " . ($result['success'] ? 'true' : 'false') . "\n";
    if ($result['success']) {
        echo "  Funnel name: " . $result['funnel']['name'] . "\n";
        echo "  Focus keyword: " . ($result['funnel']['seo']['focus_keyword'] ?? 'N/A') . "\n";
    } else {
        echo "  Error: " . ($result['error'] ?? 'unknown') . "\n";
    }
} catch (Throwable $e) {
    echo "  EXCEPTION: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n";

// Test 2: stdClass input (simulates MCP)
echo "Test 2: stdClass input (simulates MCP)\n";
try {
    $input = new stdClass();
    $input->slug = 'liver-detox-protocol';
    $result = \HP_Abilities\Abilities\FunnelApi::getFunnel($input);
    echo "  Success: " . ($result['success'] ? 'true' : 'false') . "\n";
    if ($result['success']) {
        echo "  Funnel name: " . $result['funnel']['name'] . "\n";
        echo "  Focus keyword: " . ($result['funnel']['seo']['focus_keyword'] ?? 'N/A') . "\n";
    } else {
        echo "  Error: " . ($result['error'] ?? 'unknown') . "\n";
    }
} catch (Throwable $e) {
    echo "  EXCEPTION: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n";

// Test 3: Call via WordPress Abilities API
echo "Test 3: Via wp_execute_ability\n";
try {
    $input = ['slug' => 'liver-detox-protocol'];
    $result = wp_execute_ability('hp-funnels/get', $input);
    
    if (is_wp_error($result)) {
        echo "  WP_Error: " . $result->get_error_message() . "\n";
    } else {
        echo "  Result type: " . gettype($result) . "\n";
        if (is_array($result)) {
            echo "  Success: " . ($result['success'] ? 'true' : 'false') . "\n";
            if ($result['success']) {
                echo "  Funnel name: " . $result['funnel']['name'] . "\n";
            }
        } else {
            echo "  Raw result: " . print_r($result, true) . "\n";
        }
    }
} catch (Throwable $e) {
    echo "  EXCEPTION: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "  Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nDone.\n";

