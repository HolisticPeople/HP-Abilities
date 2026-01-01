<?php
/**
 * Test SEO Audit
 */
require_once dirname(__FILE__) . '/../../../wp-load.php';

echo "=== Testing SEO Audit ===\n";

try {
    $result = HP_Abilities\Abilities\FunnelApi::seoAudit(['funnel_slug' => 'liver-detox-protocol']);
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
} catch (Error $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}

