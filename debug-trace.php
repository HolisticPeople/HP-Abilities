<?php
/**
 * Helper script to find the exact line causing the stdClass error.
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
    echo "Testing funnel loading for: $slug\n\n";
    
    echo "1. Calling \HP_RW\Services\FunnelConfigLoader::getBySlug($slug)...\n";
    $f = \HP_RW\Services\FunnelConfigLoader::getBySlug($slug);
    echo "   Result type: " . gettype($f) . "\n";
    
    echo "\n2. Calling \HP_Abilities\Abilities\FunnelApi::getFunnel(['slug' => $slug])...\n";
    $res = \HP_Abilities\Abilities\FunnelApi::getFunnel(['slug' => $slug]);
    echo "   Success: " . ($res['success'] ? 'YES' : 'NO') . "\n";
    
    echo "\n3. Calling \HP_Abilities\Abilities\FunnelApi::seoAudit(['slug' => $slug])...\n";
    $res2 = \HP_Abilities\Abilities\FunnelApi::seoAudit(['slug' => $slug]);
    echo "   Success: " . ($res2['success'] ? 'YES' : 'NO') . "\n";
    
    echo "\nSuccess! No stdClass error found in these calls.\n";
} catch (Throwable $e) {
    echo "\nERROR FOUND:\n";
    echo $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    echo "STACK TRACE:\n";
    echo $e->getTraceAsString() . "\n";
}

