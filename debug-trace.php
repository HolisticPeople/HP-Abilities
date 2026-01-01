<?php
/**
 * List HP abilities to debug MCP tool availability.
 */
$path = dirname(__FILE__);
while ($path != '/' && !file_exists($path . '/wp-load.php')) {
    $path = dirname($path);
}
include $path . '/wp-load.php';

header('Content-Type: text/plain');

echo "===== HP Abilities Registered =====\n\n";

$abilities = wp_get_abilities();
$hpAbilities = [];
foreach (array_keys($abilities) as $name) {
    if (str_starts_with($name, 'hp-')) {
        $hpAbilities[] = $name;
    }
}

sort($hpAbilities);
foreach ($hpAbilities as $name) {
    echo "- $name\n";
}

echo "\nTotal HP abilities: " . count($hpAbilities) . "\n";

// Check specifically for apply-seo-fixes
echo "\n===== Checking apply-seo-fixes =====\n";
$ability = wp_get_ability('hp-funnels/apply-seo-fixes');
if ($ability) {
    echo "Found: hp-funnels/apply-seo-fixes\n";
    echo "Label: " . $ability->get_label() . "\n";
} else {
    echo "NOT FOUND: hp-funnels/apply-seo-fixes\n";
}

