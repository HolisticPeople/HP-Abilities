<?php
namespace HP_Abilities;

use HP_Abilities\Abilities\ProductManager;
use HP_Abilities\Abilities\FunnelApi;
use HP_Abilities\Abilities\InventoryCheck;
use HP_Abilities\Abilities\CustomerLookup;
use HP_Abilities\Abilities\OrderSearch;
use HP_Abilities\Abilities\OrderStatus;
use HP_Abilities\Abilities\Test;
use HP_Abilities\Adapters\ProductFieldsAdapter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class for HP Abilities.
 */
class Plugin
{
    /**
     * Initialize the plugin.
     */
    public static function init(): void
    {
        // Initialize GMC fixes
        if (class_exists('\HP_Abilities\Utils\GMCFixer')) {
            \HP_Abilities\Utils\GMCFixer::init();
        }

        // Core WordPress 6.9+ hook names
        add_action('wp_abilities_api_categories_init', [self::class, 'register_ability_categories']);
        add_action('wp_abilities_api_init', [self::class, 'register_abilities']);

        // Check if we missed the boat
        if (did_action('wp_abilities_api_init')) {
            self::register_abilities();
        }
        if (did_action('wp_abilities_api_categories_init')) {
            self::register_ability_categories();
        }

        // Register settings page and settings
        add_action('admin_menu', [self::class, 'register_settings_page']);
        add_action('admin_init', [self::class, 'register_plugin_settings']);

        // Enqueue Yoast compliance script
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_yoast_compliance_script']);

        // AJAX handlers
        add_action('wp_ajax_hp_toggle_ability', [self::class, 'ajax_toggle_ability']);
        add_action('wp_ajax_hp_check_tool_health', [self::class, 'ajax_check_tool_health']);

        // Hook into WooCommerce MCP to include HP abilities
        add_filter('woocommerce_mcp_include_ability', [self::class, 'include_hp_abilities_in_wc_mcp'], 10, 2);
    }

    /**
     * Enqueue Yoast compliance script in the admin.
     */
    public static function enqueue_yoast_compliance_script($hook): void
    {
        // Only load on post edit pages
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        // Only for products
        if (get_post_type() !== 'product') {
            return;
        }

        wp_enqueue_script(
            'hp-yoast-gmc-compliance',
            plugins_url('assets/js/yoast-gmc-compliance.js', HP_ABILITIES_FILE),
            ['jquery'],
            HP_ABILITIES_VERSION,
            true
        );

        wp_localize_script('hp-yoast-gmc-compliance', 'hpGmcComplianceData', [
            'forbiddenKeywords' => \HP_Abilities\Utils\GMCValidator::get_forbidden_keywords()
        ]);
    }

    /**
     * Get all registered HP abilities from the global registry.
     */
    public static function get_registered_hp_abilities(): array
    {
        if (!class_exists('\WP_Abilities_Registry')) {
            return [];
        }

        $registry = \WP_Abilities_Registry::get_instance();
        $all_abilities = $registry->get_all_registered();
        $hp_abilities = [];

        foreach ($all_abilities as $id => $ability) {
            if (strpos($id, 'hp-abilities/') === 0) {
                $hp_abilities[$id] = $ability;
            }
        }

        return $hp_abilities;
    }

    /**
     * AJAX handler to toggle ability state.
     */
    public static function ajax_toggle_ability(): void
    {
        check_ajax_referer('hp_abilities_toggle', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $ability_id = sanitize_text_field($_POST['ability_id'] ?? '');
        $enabled = (bool)($_POST['enabled'] ?? false);

        if (empty($ability_id)) {
            wp_send_json_error('Missing ability ID');
        }

        $disabled_list = get_option('hp_abilities_disabled_list', []);
        
        if ($enabled) {
            $disabled_list = array_diff($disabled_list, [$ability_id]);
        } else {
            if (!in_array($ability_id, $disabled_list)) {
                $disabled_list[] = $ability_id;
            }
        }

        update_option('hp_abilities_disabled_list', array_values($disabled_list));
        wp_send_json_success(['ability_id' => $ability_id, 'enabled' => $enabled]);
    }

    /**
     * AJAX handler to check tool health.
     */
    public static function ajax_check_tool_health(): void
    {
        check_ajax_referer('hp_abilities_health', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $ability_id = sanitize_text_field($_POST['ability_id'] ?? '');
        if (empty($ability_id)) {
            wp_send_json_error('Missing_ability_ID');
        }

        $registry = \WP_Abilities_Registry::get_instance();
        $ability = $registry->get_registered($ability_id);

        if (!$ability) {
            wp_send_json_error('Ability not found');
        }

        $health = [
            'status' => 'ok',
            'message' => 'Active',
            'details' => []
        ];

        // 1. Check callback using reflection for protected property
        $reflection = new \ReflectionClass($ability);
        $callback_prop = $reflection->getProperty('execute_callback');
        $callback_prop->setAccessible(true);
        $callback = $callback_prop->getValue($ability);

        if (!is_callable($callback)) {
            $health['status'] = 'error';
            $health['message'] = 'Broken';
            $health['details'][] = 'Callback not callable';
        }

        // 2. Check dependency classes (HP_RW)
        if (is_array($callback) && is_string($callback[0])) {
            $class = $callback[0];
            if (!class_exists($class)) {
                $health['status'] = 'error';
                $health['message'] = 'Missing Deps';
                $health['details'][] = "Class $class not found";
            }
        }

        wp_send_json_success($health);
    }

    /**
     * Register plugin settings for API keys.
     */
    public static function register_plugin_settings(): void
    {
        register_setting('hp_abilities_settings', 'hp_abilities_stg_ck', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('hp_abilities_settings', 'hp_abilities_stg_cs', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('hp_abilities_settings', 'hp_abilities_prod_ck', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('hp_abilities_settings', 'hp_abilities_prod_cs', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        // Image preparation settings
        register_setting('hp_abilities_settings', 'hp_abilities_image_target_size', [
            'type'              => 'integer',
            'default'           => 1100,
            'sanitize_callback' => 'absint',
        ]);
        register_setting('hp_abilities_settings', 'hp_abilities_image_padding', [
            'type'              => 'number',
            'default'           => 0.05,
            'sanitize_callback' => function($val) { return max(0, min(0.5, floatval($val))); },
        ]);
        register_setting('hp_abilities_settings', 'hp_abilities_image_aggressiveness', [
            'type'              => 'integer',
            'default'           => 50,
            'sanitize_callback' => function($val) { return max(1, min(100, absint($val))); },
        ]);
        register_setting('hp_abilities_settings', 'hp_abilities_image_naming', [
            'type'              => 'string',
            'default'           => '{sku}-{angle}',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('hp_abilities_settings', 'hp_abilities_image_correction_prompt', [
            'type'              => 'string',
            'default'           => self::get_default_correction_prompt(),
            'sanitize_callback' => 'wp_kses_post',
        ]);
    }

    /**
     * Get the default mask correction prompt for agents.
     */
    public static function get_default_correction_prompt(): string
    {
        return <<<'PROMPT'
MASK CORRECTION WORKFLOW: THE POWER OF SYMMETRY

ALWAYS VIEW ON BLACK BACKGROUND - this reveals edge issues that white/transparent hides.
MAXIMUM 3-4 ITERATIONS - if not fixed after 4 passes, accept or flag for manual review.

== STEP 0: SYMMETRY SANITY CHECK ==

Bottles are GEOMETRICALLY PREDICTABLE. They consist of smooth, continuous curves 
and are almost always SYMMETRICAL. 

1. Look at the RIGHT edge vs the LEFT edge. 
2. If one side is clean (high contrast) but the other is jagged, use SYMMETRY.
3. The "good" side is your template for the "bad" side.

== STEP 1: FIND THE CENTER ==

Identify the horizontal center of the bottle in the original image.
- Center X = (Leftmost product point + Rightmost product point) / 2
- Usually around X=390-400 for standard shots.

== STEP 2: FULL-PATH MIRRORING (Pass 1) ==

Use: node bin/mirror-edge.js --mask <mask-path> --original <original-path> --center <X> --source-side <good-side>

This is the MOST EFFECTIVE way to fix broken edges. It mirrors the ENTIRE path 
of the good side to the bad side, ensuring a perfectly natural, symmetrical shape.

== STEP 3: REFINEMENT (Pass 2) ==

View on BLACK background: node bin/view-on-black.js <mask-path>

If the mirrored edge cuts off important product details (like label text very 
close to the edge):
1. Use edit-mask.js for targeted minor adjustments.
2. CRITICAL: Always provide the --original path to sample TRUE product colors.

== ANALYSIS TOOLS ==

- node bin/mirror-edge.js      → Mirrors entire edge path based on symmetry
- node bin/apply-bottle-shape.js → Enforces region-based geometric frame
- node bin/view-on-black.js    → Reveals edge issues hidden by white/transparency
- node bin/edit-mask.js        → Targeted manual pixel corrections

== WORKFLOW SUMMARY ==

Iteration 1: Find Center + node bin/mirror-edge.js (The Symmetry Pass)
Iteration 2: node bin/view-on-black.js + node bin/edit-mask.js (The Detail Pass)
Iteration 3: Final verification on Black.

If mirroring doesn't work (e.g. non-symmetrical product), use apply-bottle-shape.js 
to enforce clean geometric lines.
PROMPT;
    }

    /**
     * Load ability classes manually to ensure they are callable.
     */
    private static function load_classes(): void
    {
        $dir = HP_ABILITIES_PATH . 'includes/Abilities/';
        require_once $dir . 'CustomerLookup.php';
        require_once $dir . 'OrderSearch.php';
        require_once $dir . 'InventoryCheck.php';
        require_once $dir . 'OrderStatus.php';
        require_once $dir . 'Test.php';
        require_once $dir . 'FunnelApi.php';
        require_once $dir . 'ProductManager.php';

        // Load Adapters
        $adapters_dir = HP_ABILITIES_PATH . 'includes/Adapters/';
        require_once $adapters_dir . 'ProductFieldsAdapter.php';

        // Load Utils
        $utils_dir = HP_ABILITIES_PATH . 'includes/Utils/';
        require_once $utils_dir . 'GMCValidator.php';
        require_once $utils_dir . 'GMCFixer.php';
    }

    /**
     * Get the ability group for scoping.
     */
    private static function get_ability_group(string $ability_id): string
    {
        $id = strtolower($ability_id);
        $group = 'all';
        if (strpos($id, 'customers') !== false || strpos($id, 'orders') !== false || strpos($id, 'order') !== false) {
            $group = 'orders';
        } elseif (strpos($id, 'funnels') !== false || strpos($id, 'funnel') !== false || strpos($id, 'protocols') !== false) {
            $group = 'funnels';
        } elseif (strpos($id, 'inventory') !== false || strpos($id, 'products') !== false || strpos($id, 'product') !== false || strpos($id, 'stock') !== false || strpos($id, 'kit') !== false || strpos($id, 'supply') !== false || strpos($id, 'media') !== false || strpos($id, 'image') !== false) {
            $group = 'products';
        } elseif (strpos($id, 'economics') !== false || strpos($id, 'profit') !== false || strpos($id, 'revenue') !== false || strpos($id, 'guidelines') !== false) {
            $group = 'economics';
        } elseif (strpos($id, 'seo') !== false) {
            $group = 'funnels';
        }

        // #region agent log
        $log_file = (DIRECTORY_SEPARATOR === '/') ? ABSPATH . 'wp-content/debug-mcp.log' : 'c:\\DEV\\.cursor\\debug.log';
        $log_payload = [
            'location' => 'Plugin.php:get_ability_group',
            'message' => 'Determined group',
            'data' => [
                'ability_id' => $ability_id,
                'group' => $group
            ],
            'timestamp' => microtime(true) * 1000,
            'sessionId' => 'debug-session',
            'hypothesisId' => 'H1'
        ];
        file_put_contents($log_file, json_encode($log_payload) . "\n", FILE_APPEND);
        // #endregion

        return $group;
    }

    /**
     * Include HP and WooCommerce abilities in WooCommerce MCP server with scope support.
     */
    public static function include_hp_abilities_in_wc_mcp(bool $include, string $ability_id): bool
    {
        // #region agent log
        $log_file = (DIRECTORY_SEPARATOR === '/') ? ABSPATH . 'wp-content/debug-mcp.log' : 'c:\\DEV\\.cursor\\debug.log';
        $log_payload = [
            'location' => 'Plugin.php:include_hp_abilities_in_wc_mcp',
            'message' => 'Filtering ability',
            'data' => [
                'ability_id' => $ability_id,
                'scope' => $_GET['scope'] ?? 'all',
                'include_init' => $include
            ],
            'timestamp' => microtime(true) * 1000,
            'sessionId' => 'debug-session',
            'hypothesisId' => 'H1,H2'
        ];
        file_put_contents($log_file, json_encode($log_payload) . "\n", FILE_APPEND);
        // #endregion

        // Get scope from request
        $scope = isset($_GET['scope']) ? sanitize_text_field($_GET['scope']) : 'all';
        
        // Only handle HP and WooCommerce abilities
        $is_hp = strpos($ability_id, 'hp-abilities/') === 0;
        $is_wc = strpos($ability_id, 'woocommerce/') === 0;

        if (!$is_hp && !$is_wc) {
            return $include;
        }

        // Check if explicitly disabled
        $disabled_list = get_option('hp_abilities_disabled_list', []);
        if (in_array($ability_id, $disabled_list)) {
            // #region agent log
            file_put_contents($log_file, json_encode(array_merge($log_payload, ['message' => 'Ability disabled', 'data' => ['ability_id' => $ability_id]])) . "\n", FILE_APPEND);
            // #endregion
            return false;
        }

        $group = self::get_ability_group($ability_id);
        $can_manage = current_user_can('manage_woocommerce');
        
        // Default to true for 'all' scope if user can manage WC
        if ($scope === 'all') {
            $should_include = ($include || $is_hp) && $can_manage;
        } else {
            $should_include = ($group === $scope) && $can_manage;
        }
        
        // Special case: Always include test-hello in all scopes for debugging
        if (strpos($ability_id, 'test-hello') !== false) {
            $should_include = true;
        }

        // #region agent log
        file_put_contents($log_file, json_encode(array_merge($log_payload, ['message' => 'Filtering decision', 'data' => [
            'ability_id' => $ability_id,
            'group' => $group,
            'scope' => $scope,
            'can_manage' => $can_manage,
            'should_include' => $should_include
        ]])) . "\n", FILE_APPEND);
        // #endregion

        return $should_include;
    }

    /**
     * Register all abilities.
     */
    public static function register_abilities(): void
    {
        // #region agent log
        $log_file = (DIRECTORY_SEPARATOR === '/') ? ABSPATH . 'wp-content/debug-mcp.log' : 'c:\\DEV\\.cursor\\debug.log';
        $log_payload = [
            'location' => 'Plugin.php:register_abilities',
            'message' => 'Registering HP abilities',
            'data' => [],
            'timestamp' => microtime(true) * 1000,
            'sessionId' => 'debug-session',
            'hypothesisId' => 'H3'
        ];
        file_put_contents($log_file, json_encode($log_payload) . "\n", FILE_APPEND);
        // #endregion

        self::load_classes();
        
        self::register_product_abilities();
        self::register_media_abilities();
        self::register_order_abilities();
        self::register_funnel_abilities();
        self::register_economics_abilities();
        self::register_test_abilities();
    }

    /**
     * Register custom ability categories.
     */
    public static function register_ability_categories(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category('hp-admin', [
            'label'       => __('HP Store Admin', 'hp-abilities'),
            'description' => __('Holistic People store administration abilities', 'hp-abilities'),
        ]);

        wp_register_ability_category('hp-funnels', [
            'label'       => __('HP Funnels', 'hp-abilities'),
            'description' => __('Holistic People sales funnel abilities', 'hp-abilities'),
        ]);

        wp_register_ability_category('hp-seo', [
            'label'       => __('HP SEO & Analytics', 'hp-abilities'),
            'description' => __('Holistic People SEO, schema, and analytics abilities', 'hp-abilities'),
        ]);

        wp_register_ability_category('hp-economics', [
            'label'       => __('HP Economics', 'hp-abilities'),
            'description' => __('Holistic People economic and profitability abilities', 'hp-abilities'),
        ]);
    }

    /**
     * =========================================================================
     * DEPENDENCY SYNC CHECKLIST
     * =========================================================================
     * When modifying HP Abilities tools, keep these files in sync:
     * 
     * | Change              | Files to Update                                    |
     * |---------------------|---------------------------------------------------|
     * | Add/remove ability  | Plugin.php (register), Ability class (callback)   |
     * | Change behavior     | .cursor/rules/mcp-protocol.mdc,                   |
     * |                     | Plugin.php get_protocol_rule_text()               |
     * | Bridge changes      | bin/hp-mcp-bridge.js, C:\DEV\hp-mcp-bridge.js     |
     * | Version bump        | hp-abilities.php header + constant                |
     * =========================================================================
     */

    private static function register_product_abilities(): void
    {
        wp_register_ability('hp-abilities/products-update-comprehensive', [
            'label'               => 'Comprehensive Product Update',
            'description'         => 'Update product core, ACF, and SEO',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'updateComprehensive'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'sku'  => ['type' => 'string', 'description' => 'Product SKU'],
                    'data' => [
                        'type' => 'object', 
                        'description' => 'Update data: {status, acf: {field: value}, seo: {title, description, focus_keyword}}',
                        'additionalProperties' => true,
                    ],
                ],
                'required'   => ['sku', 'data'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-gmc-audit', [
            'label'               => 'Product GMC Audit',
            'description'         => 'Audit product for Google Merchant Center compliance',
            'category'            => 'hp-seo',
            'execute_callback'    => [ProductManager::class, 'gmcAudit'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'sku' => ['type' => 'string', 'description' => 'Product SKU'],
                ],
                'required'   => ['sku'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-seo-audit', [
            'label'               => 'Product SEO Audit',
            'description'         => 'Perform on-demand SEO audit',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'seoAudit'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'sku' => ['type' => 'string', 'description' => 'Product SKU'],
                ],
                'required'   => ['sku'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/inventory-check', [
            'label'               => 'Inventory Check',
            'description'         => 'Check stock levels by SKU',
            'category'            => 'hp-admin',
            'execute_callback'    => [InventoryCheck::class, 'execute'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'sku' => ['type' => 'string', 'description' => 'Product SKU to check'],
                ],
                'required'   => ['sku'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-search', [
            'label'               => 'Search Products',
            'description'         => 'Search WooCommerce products with HP filters',
            'category'            => 'hp-admin',
            'execute_callback'    => [FunnelApi::class, 'searchProducts'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'search'   => ['type' => 'string', 'description' => 'Search term'],
                    'category' => ['type' => 'string', 'description' => 'Category slug filter'],
                    'limit'    => ['type' => 'integer', 'description' => 'Max results', 'default' => 20],
                ],
                'required'   => ['search'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-get', [
            'label'               => 'Get Product',
            'description'         => 'Get product details by SKU',
            'category'            => 'hp-admin',
            'execute_callback'    => [FunnelApi::class, 'getProduct'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'sku' => ['type' => 'string', 'description' => 'Product SKU'],
                ],
                'required'   => ['sku'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-create', [
            'label'               => 'Create Product',
            'description'         => 'Create a new simple WooCommerce product with full field support',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'createProduct'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'name'              => ['type' => 'string', 'description' => 'Product name'],
                    'sku'               => ['type' => 'string', 'description' => 'Unique SKU'],
                    'price'             => ['type' => 'string', 'description' => 'Regular price'],
                    'description'       => ['type' => 'string', 'description' => 'Full description'],
                    'short_description' => ['type' => 'string', 'description' => 'Short description'],
                    'stock_quantity'    => ['type' => 'integer', 'description' => 'Stock quantity'],
                    'categories'        => ['type' => 'array', 'description' => 'Category slugs array'],
                    'tags'              => ['type' => 'array', 'description' => 'Tag names array'],
                    'weight'            => ['type' => 'string', 'description' => 'Weight (kg)'],
                    'dimensions'        => ['type' => 'object', 'description' => '{length, width, height}'],
                    'images'            => ['type' => 'array', 'description' => 'Image URLs to sideload'],
                    // Supplement ACF fields (common HP product fields)
                    'serving_size'           => ['type' => 'integer', 'description' => 'Units per serving (e.g., 2 capsules)'],
                    'servings_per_container' => ['type' => 'integer', 'description' => 'Total servings in container'],
                    'serving_form_unit'      => ['type' => 'string', 'description' => 'Form unit: Capsule, Dropper, Soft Gel, Tablet(s), etc.'],
                    'ingredients'            => ['type' => 'string', 'description' => 'Primary ingredients list'],
                    'ingredients_other'      => ['type' => 'string', 'description' => 'Other ingredients'],
                    'potency'                => ['type' => 'string', 'description' => 'Potency concentration value'],
                    'potency_units'          => ['type' => 'string', 'description' => 'Potency units: IU, mcg, mg, PPM'],
                    'manufacturer_acf'       => ['type' => 'string', 'description' => 'Manufacturer name (e.g., Dragon Herbs Ron Teeguarden)'],
                    'country_of_manufacturer'=> ['type' => 'string', 'description' => 'Country: United States, Taiwan'],
                    // Generic ACF and SEO
                    'acf'               => ['type' => 'object', 'description' => 'Additional ACF fields by field name'],
                    'seo'               => ['type' => 'object', 'description' => '{title, description, focus_keyword} for Yoast'],
                ],
                'required'   => ['name', 'sku', 'price'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-calculate-supply', [
            'label'               => 'Calculate Product Supply',
            'description'         => 'Calculate how many days a product will last based on servings per day',
            'category'            => 'hp-admin',
            'execute_callback'    => [FunnelApi::class, 'calculateSupply'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'sku'              => ['type' => 'string', 'description' => 'Product SKU'],
                    'days'             => ['type' => 'integer', 'description' => 'Number of days to calculate for', 'default' => 30],
                    'servings_per_day' => ['type' => 'integer', 'description' => 'Number of servings per day', 'default' => 1],
                ],
                'required'   => ['sku'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-retire-redirect', [
            'label'               => 'Retire Product with Redirect',
            'description'         => 'Retire a product and create 301 redirect to replacement using Yoast Premium',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'retireWithRedirect'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'old_sku'       => ['type' => 'string', 'description' => 'SKU of the product being retired'],
                    'new_sku'       => ['type' => 'string', 'description' => 'SKU of the replacement product'],
                    'redirect_type' => ['type' => 'integer', 'description' => 'Redirect type: 301 (default), 302, 307, or 410', 'default' => 301],
                    'set_private'   => ['type' => 'boolean', 'description' => 'Set old product to private status', 'default' => true],
                ],
                'required'   => ['old_sku', 'new_sku'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        // =========================================================================
        // ACF-POWERED TOOLS (Full Field Access)
        // These tools use ACF Pro + Yoast to access ALL product fields
        // =========================================================================

        wp_register_ability('hp-abilities/products-get-full', [
            'label'               => 'Get Full Product Data',
            'description'         => 'Get ALL product fields (core, ACF, SEO, taxonomy)',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'getFullProduct'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'sku' => ['type' => 'string', 'description' => 'Product SKU'],
                ],
                'required'   => ['sku'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-compare', [
            'label'               => 'Compare Products',
            'description'         => 'Compare two products field-by-field, returns ALL differences including ACF',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'compareProducts'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'source_sku' => ['type' => 'string', 'description' => 'Source product SKU (the reference)'],
                    'target_sku' => ['type' => 'string', 'description' => 'Target product SKU (being compared)'],
                ],
                'required'   => ['source_sku', 'target_sku'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-clone', [
            'label'               => 'Clone Product Fields',
            'description'         => 'Copy ALL fields from source to target (core, ACF, SEO, taxonomy) with optional overrides',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'cloneProduct'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'source_sku' => ['type' => 'string', 'description' => 'Source product SKU to copy from'],
                    'target_sku' => ['type' => 'string', 'description' => 'Target product SKU to copy to'],
                    'overrides'  => ['type' => 'object', 'description' => 'Fields to override (field_name: value)', 'additionalProperties' => true],
                    'exclude'    => ['type' => 'array', 'description' => 'Field names to exclude from copying'],
                ],
                'required'   => ['source_sku', 'target_sku'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-update-fields', [
            'label'               => 'Update Product Fields',
            'description'         => 'Update ANY fields by human-readable name (core, ACF, SEO, taxonomy)',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'updateFields'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'sku'    => ['type' => 'string', 'description' => 'Product SKU'],
                    'fields' => ['type' => 'object', 'description' => 'Fields to update (field_name: value)', 'additionalProperties' => true],
                ],
                'required'   => ['sku', 'fields'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/products-available-fields', [
            'label'               => 'List Available Fields',
            'description'         => 'Get list of all available product fields (core, ACF, SEO, taxonomy)',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'getAvailableFields'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => (object)[],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }

    private static function register_media_abilities(): void
    {
        wp_register_ability('hp-abilities/media-upload', [
            'label'               => 'Upload Media',
            'description'         => 'Upload a file to Media Library. Supports: url (sideload from URL), server_path (import from server), or file_content (base64).',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'uploadMedia'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'url'          => ['type' => 'string', 'description' => 'Remote URL to sideload image from (preferred for public images)'],
                    'server_path'  => ['type' => 'string', 'description' => 'Absolute path on server (e.g. /tmp/image.png) - requires SCP first'],
                    'file_content' => ['type' => 'string', 'description' => 'Base64 encoded file content (legacy, for small files only)'],
                    'file_name'    => ['type' => 'string', 'description' => 'Desired filename (auto-detected from url/path if not provided)'],
                    'title'        => ['type' => 'string', 'description' => 'Title for the media attachment'],
                    'alt_text'     => ['type' => 'string', 'description' => 'Alt text for the image'],
                    'product_id'   => ['type' => 'integer', 'description' => 'Product ID to attach this media to'],
                    'is_thumbnail' => ['type' => 'boolean', 'description' => 'Set as product featured image (requires product_id)', 'default' => false],
                ],
                'required'   => [],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/image-settings', [
            'label'               => 'Image Settings',
            'description'         => 'Get or set image preparation settings (target size, padding, aggressiveness, naming, correction_prompt)',
            'category'            => 'hp-admin',
            'execute_callback'    => [ProductManager::class, 'imageSettings'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'action'            => ['type' => 'string', 'description' => 'Action: get or set', 'enum' => ['get', 'set']],
                    'target_size'       => ['type' => 'integer', 'description' => 'Canvas size in pixels (for set action)'],
                    'padding'           => ['type' => 'number', 'description' => 'Padding percent 0-0.5 (for set action)'],
                    'aggressiveness'    => ['type' => 'integer', 'description' => 'BG removal aggressiveness 1-100 (for set action)'],
                    'naming'            => ['type' => 'string', 'description' => 'Naming pattern with {sku}, {angle}, {timestamp} (for set action)'],
                    'correction_prompt' => ['type' => 'string', 'description' => 'Agent instructions for mask correction (for set action)'],
                ],
                'required'   => ['action'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }

    private static function register_order_abilities(): void
    {
        wp_register_ability('hp-abilities/customers-lookup', [
            'label'               => 'Customer Lookup',
            'description'         => 'Get customer profile and history by email',
            'category'            => 'hp-admin',
            'execute_callback'    => [CustomerLookup::class, 'execute'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'email' => ['type' => 'string', 'description' => 'Customer email address'],
                ],
                'required'   => ['email'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/orders-search', [
            'label'               => 'Order Search',
            'description'         => 'Search WooCommerce orders',
            'category'            => 'hp-admin',
            'execute_callback'    => [OrderSearch::class, 'execute'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'search'   => ['type' => 'string', 'description' => 'Search term'],
                    'status'   => ['type' => 'string', 'description' => 'Order status'],
                    'per_page' => ['type' => 'integer', 'description' => 'Number of orders per page', 'default' => 10],
                ],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/orders-update-status', [
            'label'               => 'Update Order Status',
            'description'         => 'Change WooCommerce order status',
            'category'            => 'hp-admin',
            'execute_callback'    => [OrderStatus::class, 'execute'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'order_id' => ['type' => 'integer', 'description' => 'Order ID'],
                    'status'   => ['type' => 'string', 'description' => 'New status'],
                ],
                'required'   => ['order_id', 'status'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }

    private static function register_funnel_abilities(): void
    {
        wp_register_ability('hp-abilities/funnels-list', [
            'label'               => 'List Funnels',
            'description'         => 'List all HP funnels',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'listFunnels'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'per_page' => ['type' => 'integer', 'description' => 'Number of funnels per page', 'default' => 10],
                    'page'     => ['type' => 'integer', 'description' => 'Page number', 'default' => 1],
                ],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-get', [
            'label'               => 'Get Funnel',
            'description'         => 'Get a complete funnel configuration by slug',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'getFunnel'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'funnel_slug' => ['type' => 'string', 'description' => 'Slug of the funnel to retrieve'],
                ],
                'required'   => ['funnel_slug'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-create', [
            'label'               => 'Create Funnel',
            'description'         => 'Create a new funnel from JSON configuration',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'createFunnel'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'title'  => ['type' => 'string', 'description' => 'Funnel title'],
                    'slug'   => ['type' => 'string', 'description' => 'Funnel slug'],
                    'config' => ['type' => 'object', 'description' => 'Full funnel configuration object', 'additionalProperties' => true],
                ],
                'required'   => ['title', 'config'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-update', [
            'label'               => 'Update Funnel',
            'description'         => 'Update an existing funnel configuration',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'updateFunnel'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'funnel_slug' => ['type' => 'string', 'description' => 'Slug of the funnel to update'],
                    'config'      => ['type' => 'object', 'description' => 'Updated funnel configuration object', 'additionalProperties' => true],
                ],
                'required'   => ['funnel_slug', 'config'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-update-sections', [
            'label'               => 'Update Funnel Sections',
            'description'         => 'Update specific sections of a funnel configuration',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'updateSections'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'slug'     => ['type' => 'string', 'description' => 'Slug of the funnel'],
                    'sections' => ['type' => 'object', 'description' => 'Map of section names to new configurations', 'additionalProperties' => true],
                ],
                'required'   => ['slug', 'sections'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-validate', [
            'label'               => 'Validate Funnel JSON',
            'description'         => 'Validate a funnel JSON configuration object',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'validateFunnel'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'config' => ['type' => 'object', 'description' => 'Funnel configuration object to validate', 'additionalProperties' => true],
                ],
                'required'   => ['config'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-restore-version', [
            'label'               => 'Restore Funnel Version',
            'description'         => 'Restore a funnel to a previously saved version',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'restoreVersion'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'slug'       => ['type' => 'string', 'description' => 'Slug of the funnel'],
                    'version_id' => ['type' => 'integer', 'description' => 'ID of the version to restore'],
                ],
                'required'   => ['slug', 'version_id'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-explain-system', [
            'label'               => 'Explain Funnel System',
            'description'         => 'Get complete documentation of the funnel architecture',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'explainSystem'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => (object)[],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-schema', [
            'label'               => 'Funnel Schema',
            'description'         => 'Get funnel JSON schema with AI hints',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'getSchema'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => (object)[],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-versions-list', [
            'label'               => 'List Funnel Versions',
            'description'         => 'List saved backup versions of a funnel',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'listVersions'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'funnel_slug' => ['type' => 'string', 'description' => 'Slug of the funnel'],
                ],
                'required'   => ['funnel_slug'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-versions-create', [
            'label'               => 'Create Funnel Version',
            'description'         => 'Create a manual backup version of a funnel',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'createVersion'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'funnel_slug' => ['type' => 'string', 'description' => 'Slug of the funnel'],
                    'note'        => ['type' => 'string', 'description' => 'Optional backup note'],
                ],
                'required'   => ['funnel_slug'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-apply-seo-fixes', [
            'label'               => 'Apply SEO Fixes',
            'description'         => 'Bulk apply SEO fixes to a funnel',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'applySeoFixes'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'funnel_slug' => ['type' => 'string', 'description' => 'Slug of the funnel'],
                ],
                'required'   => ['funnel_slug'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/seo-funnel-schema', [
            'label'               => 'SEO Funnel Schema',
            'description'         => 'Get JSON-LD schema for a funnel',
            'category'            => 'hp-seo',
            'execute_callback'    => [FunnelApi::class, 'getSeoSchema'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'funnel_slug' => ['type' => 'string', 'description' => 'Slug of the funnel'],
                ],
                'required'   => ['funnel_slug'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/funnels-seo-audit', [
            'label'               => 'Funnel SEO Audit',
            'description'         => 'Perform an SEO audit on a funnel',
            'category'            => 'hp-seo',
            'execute_callback'    => [FunnelApi::class, 'seoAudit'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'funnel_slug' => ['type' => 'string', 'description' => 'Slug of the funnel'],
                ],
                'required'   => ['funnel_slug'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/protocols-build-kit', [
            'label'               => 'Build Protocol Kit',
            'description'         => 'Build a product kit from a set of supplements',
            'category'            => 'hp-funnels',
            'execute_callback'    => [FunnelApi::class, 'buildKit'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'supplements'   => [
                        'type' => 'array', 
                        'description' => 'List of supplements with sku and daily servings',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sku' => ['type' => 'string'],
                                'servings_per_day' => ['type' => 'number'],
                            ],
                        ],
                    ],
                    'duration_days' => ['type' => 'integer', 'description' => 'Protocol duration in days', 'default' => 30],
                ],
                'required'   => ['supplements'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }

    private static function register_economics_abilities(): void
    {
        wp_register_ability('hp-abilities/economics-calculate', [
            'label'               => 'Calculate Economics',
            'description'         => 'Calculate profitability for an offer',
            'category'            => 'hp-economics',
            'execute_callback'    => [FunnelApi::class, 'calculateEconomics'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'items'             => [
                        'type' => 'array',
                        'description' => 'List of items in the offer (sku and qty)',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sku' => ['type' => 'string'],
                                'qty' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'price'             => ['type' => 'number', 'description' => 'Proposed offer price'],
                    'shipping_scenario' => ['type' => 'string', 'description' => 'Shipping scenario (domestic/international)', 'default' => 'domestic'],
                ],
                'required'   => ['items', 'price'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/economics-validate', [
            'label'               => 'Validate Economics',
            'description'         => 'Validate an offer against economic guidelines',
            'category'            => 'hp-economics',
            'execute_callback'    => [FunnelApi::class, 'validateEconomics'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'items'             => [
                        'type' => 'array',
                        'description' => 'List of items in the offer (sku and qty)',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sku' => ['type' => 'string'],
                                'qty' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'price'             => ['type' => 'number', 'description' => 'Proposed offer price'],
                    'shipping_scenario' => ['type' => 'string', 'description' => 'Shipping scenario (domestic/international)', 'default' => 'domestic'],
                ],
                'required'   => ['items', 'price'],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-abilities/economics-test', [
            'label'               => 'Economics Test',
            'description'         => 'Test tool for economics scope',
            'category'            => 'hp-economics',
            'execute_callback'    => [Test::class, 'economicsTest'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => (object)[],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }

    private static function register_test_abilities(): void
    {
        wp_register_ability('hp-abilities/test-hello', [
            'label'               => 'Test Hello',
            'description'         => 'Simple test for MCP',
            'category' => 'hp-admin',
            'execute_callback'    => [Test::class, 'hello'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Name to say hello to', 'default' => 'World'],
                ],
            ],
            'meta'                => ['mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }

    public static function register_settings_page(): void {
        add_options_page(
            __('HP Abilities Settings', 'hp-abilities'),
            __('HP Abilities', 'hp-abilities'),
            'manage_woocommerce',
            'hp-abilities',
            [self::class, 'render_settings_page']
        );
    }

    public static function render_settings_page(): void
    {
        $abilities_available = function_exists('wp_register_ability');
        $hp_rw_active = class_exists('\HP_RW\Plugin');

        // Fetch saved keys
        $stg_ck = get_option('hp_abilities_stg_ck', '');
        $stg_cs = get_option('hp_abilities_stg_cs', '');
        $prod_ck = get_option('hp_abilities_prod_ck', '');
        $prod_cs = get_option('hp_abilities_prod_cs', '');

        // Fetch image settings
        $image_target_size = get_option('hp_abilities_image_target_size', 1100);
        $image_padding = get_option('hp_abilities_image_padding', 0.05);
        $image_aggressiveness = get_option('hp_abilities_image_aggressiveness', 50);
        $image_naming = get_option('hp_abilities_image_naming', '{sku}-{angle}');
        $image_correction_prompt = get_option('hp_abilities_image_correction_prompt', self::get_default_correction_prompt());

        $stg_key = ($stg_ck && $stg_cs) ? "{$stg_ck}:{$stg_cs}" : 'YOUR_STAGING_API_KEY_HERE';
        $prod_key = ($prod_ck && $prod_cs) ? "{$prod_ck}:{$prod_cs}" : 'YOUR_PRODUCTION_API_KEY_HERE';

        // Bridge Path Management
        $bridge_file_path = HP_ABILITIES_PATH . 'bin/hp-mcp-bridge.js';
        $bridge_exists = file_exists($bridge_file_path);
        
        // For the snippet, we try to be smart. If it looks like a local Windows path, we use it.
        // Otherwise, we use a placeholder or the C:\DEV fallback.
        $snippet_bridge_path = $bridge_file_path;
        if (DIRECTORY_SEPARATOR === '/') {
            // We are on Linux (Server), so we can't give a local path. Fallback to C:\DEV
            $snippet_bridge_path = 'C:\\DEV\\hp-mcp-bridge.js';
        }

        // Staging Config
        $stg_config = [
            'mcpServers' => [
                'hp_products_stg' => [
                    'command' => 'node',
                    'args' => [
                        $snippet_bridge_path,
                        'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-json/woocommerce/mcp?scope=products',
                        $stg_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_orders_stg' => [
                    'command' => 'node',
                    'args' => [
                        $snippet_bridge_path,
                        'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-json/woocommerce/mcp?scope=orders',
                        $stg_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_funnels_stg' => [
                    'command' => 'node',
                    'args' => [
                        $snippet_bridge_path,
                        'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-json/woocommerce/mcp?scope=funnels',
                        $stg_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_economics_stg' => [
                    'command' => 'node',
                    'args' => [
                        $snippet_bridge_path,
                        'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-json/woocommerce/mcp?scope=economics',
                        $stg_key
                    ],
                    'type' => 'stdio'
                ]
            ]
        ];

        // Production Config
        $prod_config = [
            'mcpServers' => [
                'hp_products_prod' => [
                    'command' => 'node',
                    'args' => [
                        $snippet_bridge_path,
                        'https://holisticpeople.com/wp-json/woocommerce/mcp?scope=products',
                        $prod_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_orders_prod' => [
                    'command' => 'node',
                    'args' => [
                        $snippet_bridge_path,
                        'https://holisticpeople.com/wp-json/woocommerce/mcp?scope=orders',
                        $prod_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_funnels_prod' => [
                    'command' => 'node',
                    'args' => [
                        $snippet_bridge_path,
                        'https://holisticpeople.com/wp-json/woocommerce/mcp?scope=funnels',
                        $prod_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_economics_prod' => [
                    'command' => 'node',
                    'args' => [
                        $snippet_bridge_path,
                        'https://holisticpeople.com/wp-json/woocommerce/mcp?scope=economics',
                        $prod_key
                    ],
                    'type' => 'stdio'
                ]
            ]
        ];

        // Dynamic Discovery
        $hp_abilities = self::get_registered_hp_abilities();
        $disabled_list = get_option('hp_abilities_disabled_list', []);
        
        // Group abilities
        $groups = [
            'products'  => ['label' => 'Products & Inventory', 'tools' => []],
            'orders'    => ['label' => 'Orders & Customers', 'tools' => []],
            'funnels'   => ['label' => 'Funnels & SEO', 'tools' => []],
            'economics' => ['label' => 'Economics & Profitability', 'tools' => []],
            'all'       => ['label' => 'Uncategorized', 'tools' => []],
        ];

        foreach ($hp_abilities as $id => $ability) {
            $group_key = self::get_ability_group($id);
            if (!isset($groups[$group_key])) $group_key = 'all';
            $groups[$group_key]['tools'][$id] = $ability;
        }

        $toggle_nonce = wp_create_nonce('hp_abilities_toggle');
        $health_nonce = wp_create_nonce('hp_abilities_health');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('HP Abilities Management Hub', 'hp-abilities'); ?> <span style="font-size: 0.5em; vertical-align: middle; background: #eee; padding: 2px 8px; border-radius: 4px;">v<?php echo esc_html(HP_ABILITIES_VERSION); ?></span></h1>
            
            <div style="display: flex; gap: 20px; align-items: flex-start;">
                <!-- Left Column: Management & Credentials -->
                <div style="flex: 2; min-width: 500px;">
                    <form method="post" action="options.php">
                        <?php settings_fields('hp_abilities_settings'); ?>
                        
                        <div class="card" style="margin-top: 0; max-width: none;">
                            <h2><?php echo esc_html__('Credentials & Settings', 'hp-abilities'); ?></h2>
                            <table class="form-table">
                                <tr>
                                    <th scope="row" style="width: 150px;"><label for="hp_abilities_stg_ck"><?php echo esc_html__('Staging CK', 'hp-abilities'); ?></label></th>
                                    <td><input name="hp_abilities_stg_ck" type="text" id="hp_abilities_stg_ck" value="<?php echo esc_attr($stg_ck); ?>" class="regular-text" style="width: 100%;" placeholder="ck_..."></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="hp_abilities_stg_cs"><?php echo esc_html__('Staging CS', 'hp-abilities'); ?></label></th>
                                    <td><input name="hp_abilities_stg_cs" type="password" id="hp_abilities_stg_cs" value="<?php echo esc_attr($stg_cs); ?>" class="regular-text" style="width: 100%;" placeholder="cs_..."></td>
                                </tr>
                                <tr><td colspan="2"><hr></td></tr>
                                <tr>
                                    <th scope="row"><label for="hp_abilities_prod_ck"><?php echo esc_html__('Prod CK', 'hp-abilities'); ?></label></th>
                                    <td><input name="hp_abilities_prod_ck" type="text" id="hp_abilities_prod_ck" value="<?php echo esc_attr($prod_ck); ?>" class="regular-text" style="width: 100%;" placeholder="ck_..."></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="hp_abilities_prod_cs"><?php echo esc_html__('Prod CS', 'hp-abilities'); ?></label></th>
                                    <td><input name="hp_abilities_prod_cs" type="password" id="hp_abilities_prod_cs" value="<?php echo esc_attr($prod_cs); ?>" class="regular-text" style="width: 100%;" placeholder="cs_..."></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Image Preparation Settings -->
                        <div class="card" style="margin-top: 20px; max-width: none; border-left: 4px solid #7e5bef;">
                            <h2><?php echo esc_html__('Image Preparation Settings', 'hp-abilities'); ?></h2>
                            <p style="color: #666; font-size: 12px;"><?php echo esc_html__('Configure AI-powered product image processing (bg removal, sizing, centering).', 'hp-abilities'); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row" style="width: 180px;"><label for="hp_abilities_image_target_size"><?php echo esc_html__('Canvas Size (px)', 'hp-abilities'); ?></label></th>
                                    <td>
                                        <input name="hp_abilities_image_target_size" type="number" id="hp_abilities_image_target_size" value="<?php echo esc_attr($image_target_size); ?>" class="small-text" min="400" max="2400" step="100">
                                        <span class="description"><?php echo esc_html__('Output image dimensions (square). Default: 1100', 'hp-abilities'); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="hp_abilities_image_padding"><?php echo esc_html__('Padding %', 'hp-abilities'); ?></label></th>
                                    <td>
                                        <input name="hp_abilities_image_padding" type="number" id="hp_abilities_image_padding" value="<?php echo esc_attr($image_padding); ?>" class="small-text" min="0" max="0.5" step="0.01">
                                        <span class="description"><?php echo esc_html__('Space around product. 0.05 = 5% margin each side. Default: 0.05', 'hp-abilities'); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="hp_abilities_image_aggressiveness"><?php echo esc_html__('BG Removal Aggressiveness', 'hp-abilities'); ?></label></th>
                                    <td>
                                        <input name="hp_abilities_image_aggressiveness" type="range" id="hp_abilities_image_aggressiveness" value="<?php echo esc_attr($image_aggressiveness); ?>" min="1" max="100" style="width: 200px; vertical-align: middle;">
                                        <span id="aggressiveness_value" style="display: inline-block; width: 35px; text-align: center; font-weight: bold;"><?php echo esc_html($image_aggressiveness); ?></span>
                                        <span class="description"><?php echo esc_html__('1=gentle (preserve edges), 100=aggressive (hard cutoff)', 'hp-abilities'); ?></span>
                                        <script>
                                            document.getElementById('hp_abilities_image_aggressiveness').addEventListener('input', function(e) {
                                                document.getElementById('aggressiveness_value').textContent = e.target.value;
                                            });
                                        </script>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="hp_abilities_image_naming"><?php echo esc_html__('Naming Pattern', 'hp-abilities'); ?></label></th>
                                    <td>
                                        <input name="hp_abilities_image_naming" type="text" id="hp_abilities_image_naming" value="<?php echo esc_attr($image_naming); ?>" class="regular-text" placeholder="{sku}-{angle}">
                                        <span class="description"><?php echo esc_html__('Variables: {sku}, {angle}, {timestamp}', 'hp-abilities'); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" style="vertical-align: top;"><label for="hp_abilities_image_correction_prompt"><?php echo esc_html__('Agent Correction Prompt', 'hp-abilities'); ?></label></th>
                                    <td>
                                        <textarea name="hp_abilities_image_correction_prompt" id="hp_abilities_image_correction_prompt" rows="15" style="width: 100%; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($image_correction_prompt); ?></textarea>
                                        <p class="description"><?php echo esc_html__('Instructions delivered to agents when performing mask correction. Guides how to identify and fix edge issues.', 'hp-abilities'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                            
                        <?php submit_button(); ?>
                    </form>

                    <!-- Dynamic Tool List -->
                    <div class="card" style="margin-top: 20px; max-width: none;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h2><?php echo esc_html__('Registered Abilities', 'hp-abilities'); ?></h2>
                        </div>
                        <p><?php echo esc_html__('Manage and monitor all HP Abilities registered in the system.', 'hp-abilities'); ?></p>

                        <?php foreach ($groups as $group_id => $group): 
                            if (empty($group['tools'])) continue;
                            $count = count($group['tools']);
                        ?>
                            <div style="margin-top: 20px;">
                                <div style="background: #f0f0f1; padding: 8px 12px; border-radius: 4px; border-left: 4px solid #2271b1; display: flex; justify-content: space-between; align-items: center;">
                                    <h3 style="margin: 0;"><?php echo esc_html($group['label']); ?> (<?php echo (int)$count; ?>)</h3>
                                    <div style="display: flex; gap: 10px;">
                                        <button type="button" class="button button-small" onclick="checkGroupHealth('<?php echo esc_js($group_id); ?>')"><?php echo esc_html__('Check Group Health', 'hp-abilities'); ?></button>
                                        <button type="button" class="button button-small" onclick="toggleGroup('<?php echo esc_js($group_id); ?>', true)"><?php echo esc_html__('Enable All', 'hp-abilities'); ?></button>
                                        <button type="button" class="button button-small" onclick="toggleGroup('<?php echo esc_js($group_id); ?>', false)"><?php echo esc_html__('Disable All', 'hp-abilities'); ?></button>
                                    </div>
                                </div>
                                <table class="widefat fixed striped" id="table-<?php echo esc_attr($group_id); ?>">
                                    <thead>
                                        <tr>
                                            <th style="width: 30px;"><input type="checkbox" readonly checked disabled></th>
                                            <th><?php echo esc_html__('Tool ID', 'hp-abilities'); ?></th>
                                            <th style="width: 80px; text-align: center;"><?php echo esc_html__('Status', 'hp-abilities'); ?></th>
                                            <th style="width: 80px; text-align: center;"><?php echo esc_html__('Health', 'hp-abilities'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group['tools'] as $id => $ability): 
                                            $is_enabled = !in_array($id, $disabled_list);
                                        ?>
                                            <tr id="row-<?php echo esc_attr(sanitize_title($id)); ?>">
                                                <td>
                                                    <input type="checkbox" 
                                                           onchange="toggleAbility('<?php echo esc_js($id); ?>', this.checked)" 
                                                           <?php checked($is_enabled); ?>>
                                                </td>
                                                <td>
                                                    <strong><code><?php echo esc_html($id); ?></code></strong>
                                                    <div style="font-size: 11px; color: #666;"><?php echo esc_html($ability->get_description()); ?></div>
                                                </td>
                                                <td style="text-align: center;">
                                                    <span class="status-badge <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>" id="status-<?php echo esc_attr(sanitize_title($id)); ?>">
                                                        <?php echo $is_enabled ? 'Active' : 'Muted'; ?>
                                                    </span>
                                                </td>
                                                <td style="text-align: center;">
                                                    <span class="health-indicator" id="health-<?php echo esc_attr(sanitize_title($id)); ?>" title="Click to verify">
                                                        <span class="dashicons dashicons-marker" style="color: #ccc; cursor: pointer;" onclick="checkHealth('<?php echo esc_js($id); ?>')"></span>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Right Column: Snippets & Documentation -->
                <div style="flex: 1.2; min-width: 450px;">
                    <div class="card" style="margin-top: 0; max-width: none;">
                        <h2><?php echo esc_html__('Cursor Configuration', 'hp-abilities'); ?></h2>
                        <div style="margin-top: 15px;">
                            <h3 style="margin-bottom: 5px;"><?php echo esc_html__('Staging mcp.json', 'hp-abilities'); ?></h3>
                            <div style="position: relative;">
                                <textarea id="stg_mcp_snippet" readonly style="width: 100%; height: 160px; font-family: monospace; background: #f9f9f9; padding: 10px; border: 1px solid #ccc; font-size: 11px;"><?php 
                                echo esc_textarea(json_encode($stg_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                ?></textarea>
                                <button class="button button-small" style="position: absolute; top: 10px; right: 10px;" onclick="copyToClipboard('stg_mcp_snippet', this)">Copy</button>
                            </div>
                        </div>

                        <div style="margin-top: 20px;">
                            <h3 style="margin-bottom: 5px;"><?php echo esc_html__('Production mcp.json', 'hp-abilities'); ?></h3>
                            <div style="position: relative;">
                                <textarea id="prod_mcp_snippet" readonly style="width: 100%; height: 160px; font-family: monospace; background: #f9f9f9; padding: 10px; border: 1px solid #ccc; font-size: 11px;"><?php 
                                echo esc_textarea(json_encode($prod_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                ?></textarea>
                                <button class="button button-small" style="position: absolute; top: 10px; right: 10px;" onclick="copyToClipboard('prod_mcp_snippet', this)">Copy</button>
                            </div>
                        </div>
                    </div>

                    <div class="card" style="margin-top: 20px; max-width: none; border-left: 4px solid <?php echo $bridge_exists ? '#207b4d' : '#d63638'; ?>;">
                        <h2><?php echo esc_html__('Bridge Source', 'hp-abilities'); ?></h2>
                        <p>
                            <?php if ($bridge_exists): ?>
                                <span style="color: #207b4d; font-weight: bold;">✔ <?php echo esc_html__('Bridge found in plugin', 'hp-abilities'); ?></span>
                            <?php else: ?>
                                <span style="color: #d63638; font-weight: bold;">✘ <?php echo esc_html__('Bridge not found in bin/ folder', 'hp-abilities'); ?></span>
                            <?php endif; ?>
                        </p>
                        <p><?php echo esc_html__('The bridge handles Kinsta/Cloudflare headers and JSON-RPC parsing.', 'hp-abilities'); ?></p>
                        <div style="margin-bottom: 10px;">
                            <strong><?php echo esc_html__('Filename:', 'hp-abilities'); ?></strong> <code>hp-mcp-bridge.js</code>
                        </div>
                        <div style="position: relative;">
                            <textarea id="bridge_code_snippet" readonly style="width: 100%; height: 200px; font-family: monospace; background: #f9f9f9; padding: 10px; border: 1px solid #ccc; font-size: 11px;"><?php 
                            echo esc_textarea(file_get_contents(HP_ABILITIES_PATH . 'bin/hp-mcp-bridge.js'));
                            ?></textarea>
                            <div style="position: absolute; top: 10px; right: 10px; display: flex; gap: 5px;">
                                <button type="button" class="button button-small" onclick="copyToClipboard('bridge_code_snippet', this)">Copy Code</button>
                                <button type="button" class="button button-small button-primary" onclick="saveBridgeFile()">Save File</button>
                            </div>
                        </div>
                        <p class="description"><?php echo esc_html__('Save this file to C:\DEV\hp-mcp-bridge.js (or your local equivalent).', 'hp-abilities'); ?></p>
                    </div>

                    <div class="card" style="margin-top: 20px; max-width: none; border-top: 4px solid #2271b1;">
                        <h2><?php echo esc_html__('Master Protocol', 'hp-abilities'); ?></h2>
                        <p><?php echo esc_html__('Copy this rule into your workspace to guide AI agents in developing new tools.', 'hp-abilities'); ?></p>
                        <div style="position: relative;">
                            <textarea id="protocol_snippet" readonly style="width: 100%; height: 350px; font-family: monospace; background: #f9f9f9; padding: 10px; border: 1px solid #ccc; font-size: 11px;"><?php 
                            echo esc_textarea(self::get_protocol_rule_text());
                            ?></textarea>
                            <button class="button button-small" style="position: absolute; top: 10px; right: 10px;" onclick="copyToClipboard('protocol_snippet', this)">Copy</button>
                        </div>
                    </div>

                    <?php if ($hp_rw_active): ?>
                    <div class="card" style="margin-top: 20px; border-left: 4px solid #72aee6; max-width: none;">
                        <h2><?php echo esc_html__('AI Configuration', 'hp-abilities'); ?></h2>
                        <p><?php echo esc_html__('Configure economic guidelines and version control.', 'hp-abilities'); ?></p>
                        <p>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=hp-funnel&page=hp-funnel-ai-settings')); ?>" class="button button-secondary">
                                <?php echo esc_html__('Go to AI Settings', 'hp-abilities'); ?>
                            </a>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <style>
                .status-badge { padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
                .status-badge.enabled { background: #e7f6ed; color: #207b4d; }
                .status-badge.disabled { background: #fcf0f1; color: #d63638; }
                .health-indicator .dashicons { font-size: 20px; }
                .health-indicator.ok { color: #207b4d; }
                .health-indicator.error { color: #d63638; }
            </style>

            <script>
            function copyToClipboard(elementId, btn) {
                var copyText = document.getElementById(elementId);
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                navigator.clipboard.writeText(copyText.value);
                
                var originalText = btn.innerText;
                btn.innerText = 'Copied!';
                setTimeout(function() { btn.innerText = originalText; }, 2000);
            }

            function toggleAbility(abilityId, enabled) {
                const badge = document.getElementById('status-' + abilityId.replace(/[\/\s]/g, '-').toLowerCase());
                
                jQuery.post(ajaxurl, {
                    action: 'hp_toggle_ability',
                    ability_id: abilityId,
                    enabled: enabled ? 1 : 0,
                    nonce: '<?php echo $toggle_nonce; ?>'
                }, function(response) {
                    if (response.success) {
                        if (enabled) {
                            badge.innerText = 'Active';
                            badge.classList.remove('disabled');
                            badge.classList.add('enabled');
                        } else {
                            badge.innerText = 'Muted';
                            badge.classList.remove('enabled');
                            badge.classList.add('disabled');
                        }
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            }

            function checkHealth(abilityId) {
                const indicator = document.getElementById('health-' + abilityId.replace(/[\/\s]/g, '-').toLowerCase());
                indicator.innerHTML = '<span class="dashicons dashicons-update spin" style="color: #2271b1;"></span>';
                
                jQuery.post(ajaxurl, {
                    action: 'hp_check_tool_health',
                    ability_id: abilityId,
                    nonce: '<?php echo $health_nonce; ?>'
                }, function(response) {
                    if (response.success && response.data.status === 'ok') {
                        indicator.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color: #207b4d;" title="Active"></span>';
                    } else {
                        const msg = response.data ? response.data.message : 'Error';
                        indicator.innerHTML = '<span class="dashicons dashicons-warning" style="color: #d63638;" title="' + msg + '"></span>';
                    }
                });
            }

            function toggleGroup(groupId, enabled) {
                const table = document.getElementById('table-' + groupId);
                const checkboxes = table.querySelectorAll('tbody input[type="checkbox"]');
                checkboxes.forEach(cb => {
                    if (cb.checked !== enabled) {
                        cb.checked = enabled;
                        cb.dispatchEvent(new Event('change'));
                    }
                });
            }

            function checkGroupHealth(groupId) {
                const table = document.getElementById('table-' + groupId);
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    const toolId = row.querySelector('strong code').innerText;
                    checkHealth(toolId);
                });
            }

            function saveBridgeFile() {
                const code = document.getElementById('bridge_code_snippet').value;
                const blob = new Blob([code], { type: 'application/javascript' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'hp-mcp-bridge.js';
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
            }

            // Inline CSS for spin animation
            const style = document.createElement('style');
            style.innerHTML = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } } .spin { animation: spin 2s linear infinite; }';
            document.head.appendChild(style);
            </script>
        </div>
        <?php
    }

    /**
     * Get the master protocol rule text.
     */
    private static function get_protocol_rule_text(): string
    {
        return <<<EOD
# MCP & Abilities Master Protocol

## Overview
This protocol ensures all AI agents maintain the HP Abilities ecosystem correctly. The bridge filters responses to only include `hp-abilities/*` tools due to Cursor's payload size limitations with WooCommerce native tools.

## 1. Tool Stewardship (Development)
- **Namespace**: All custom tools MUST use the `hp-abilities/` prefix.
- **Hook**: Register tools ONLY within the `wp_abilities_api_init` hook in `includes/Plugin.php`.
- **Scoping**: Assign tools to a valid scope to ensure they appear in the correct MCP server:
  - `hp-admin`: Store administration, products, orders.
  - `hp-funnels`: Funnel configuration, building kits, protocols.
  - `hp-seo`: SEO audits, JSON-LD schema.
  - `hp-economics`: Profitability calculations, pricing rules.

## 2. Bridge Architecture
- **HP-Only Filtering**: The `hp-mcp-bridge.js` only exposes `hp-abilities/*` tools. WooCommerce native tools (with 10KB+ schemas) are excluded to prevent Cursor parsing failures.
- **Schema Size Limit**: Keep `inputSchema` under 1KB per tool. Use flexible object types instead of exhaustive property definitions.
- **Bridge Location**: Distributed in `bin/hp-mcp-bridge.js`. Copy to `C:\DEV\hp-mcp-bridge.js` for local Cursor.

## 3. Available Product Tools

### Native Tools (Specialized Logic)
| Tool | Purpose |
|------|---------|
| `products-search` | Search products by term |
| `products-get` | Get product details by SKU (basic) |
| `products-create` | Create new simple product |
| `products-retire-redirect` | Retire product with 301 redirect |
| `products-seo-audit` | SEO audit |
| `products-gmc-audit` | GMC compliance audit |
| `inventory-check` | Check stock levels |
| `products-calculate-supply` | Calculate supply duration |
| `media-upload` | Upload local file (base64) to Media Library |

### ACF-Powered Tools (ALL Fields via ACF Pro + Yoast)
| Tool | Purpose |
|------|---------|
| `products-get-full` | Get ALL product fields (core, ACF, SEO, taxonomy) |
| `products-compare` | Compare two products, see ALL differences |
| `products-clone` | Copy ALL fields from source to target |
| `products-update-fields` | Update ANY field by name (no field keys needed) |
| `products-available-fields` | List all available fields from product registry |

**Use ACF-powered tools for product replacement workflows** - they ensure no fields are missed.

## 4. Implementation Standards
- **Callbacks**: Use static methods in Ability classes (e.g., `ProductManager::createProduct`).
- **Input Validation**: Use standard JSON schema for `input_schema`.
- **Dependencies**: Check if `HP_RW` service classes exist before execution.

## 4a. Schema Lessons Learned
- **Avoid `(object)[]` for nested objects**: When `additionalProperties:false` is set by JSON serialization, Cursor hides tools with empty property definitions.
- **Fix**: Use `'additionalProperties' => true` for flexible object types instead of `'properties' => (object)[]`.
- **Explicit properties**: Define at least primary properties for tools where the agent needs clear guidance.

## 5. Deployment & Verification
- **Auto-Discovery**: Tools are discovered dynamically on the Settings page.
- **Health Check**: After adding a tool, use "Check Health" to verify callback validity.
- **Kill-Switch**: Use "Status" toggle to mute tools instantly.

## 6. Dependency Sync Checklist
When modifying HP Abilities tools, keep these files in sync:
| Change | Files to Update |
|--------|-----------------|
| Add/remove ability | `Plugin.php` (register), Ability class (callback) |
| Change behavior | Cursor rule `.mdc`, Settings page `get_protocol_rule_text()` |
| Bridge changes | `bin/hp-mcp-bridge.js`, `C:\DEV\hp-mcp-bridge.js` |
| Version bump | `hp-abilities.php` header + constant |

## 7. Product Media Sourcing
Agents must ensure professional product imagery:
1. **Source multiple angles**: Look for Front/Primary, Side, Back, and Label images.
2. **Automated Preparation**: ALWAYS run sourced image URLs through `bin/image-prep.js` locally.
   - Command: `node bin/image-prep.js --url "SOURCE_URL" --sku "SKU" --angle "front|side|label"`
3. **Upload Process**:
   - The tool saves a prepared 1100x1100 transparent PNG locally.
   - Use `media-upload` (base64) to push the prepared file to WordPress.
4. **Naming Convention**: Prepared files should follow `[SKU]-[angle].png`.
5. **Association**:
   - `front` angle must be set as `is_thumbnail: true`.
   - Other angles must be added to the product gallery (`is_thumbnail: false`).
EOD;
    }
}
