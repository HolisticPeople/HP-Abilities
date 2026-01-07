<?php
namespace HP_Abilities;

use HP_Abilities\Abilities\ProductManager;
use HP_Abilities\Abilities\FunnelApi;
use HP_Abilities\Abilities\InventoryCheck;
use HP_Abilities\Abilities\CustomerLookup;
use HP_Abilities\Abilities\OrderSearch;
use HP_Abilities\Abilities\OrderStatus;
use HP_Abilities\Abilities\Test;

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
        // #region agent log
        if (!function_exists('hp_agent_debug_log')) {
            function hp_agent_debug_log($hypothesisId, $location, $message, $data = []) {
                $log_file = ABSPATH . 'wp-content/hp_debug.log';
                $entry = json_encode([
                    'id' => uniqid('log_', true),
                    'timestamp' => round(microtime(true) * 1000),
                    'location' => $location,
                    'message' => $message,
                    'data' => $data,
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => $hypothesisId
                ]) . PHP_EOL;
                file_put_contents($log_file, $entry, FILE_APPEND);
            }
        }
        hp_agent_debug_log('A', 'Plugin.php:25', 'Plugin::init() start');
        // #endregion

        // Initialize GMC fixes
        \HP_Abilities\Utils\GMCFixer::init();

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
            wp_send_json_error('Missing ability ID');
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
        if (strpos($id, 'customers') !== false || strpos($id, 'orders') !== false || strpos($id, 'order') !== false) {
            return 'orders';
        }
        if (strpos($id, 'funnels') !== false || strpos($id, 'funnel') !== false || strpos($id, 'protocols') !== false) {
            return 'funnels';
        }
        if (strpos($id, 'inventory') !== false || strpos($id, 'products') !== false || strpos($id, 'product') !== false || strpos($id, 'stock') !== false || strpos($id, 'kit') !== false || strpos($id, 'supply') !== false) {
            return 'products';
        }
        if (strpos($id, 'economics') !== false || strpos($id, 'profit') !== false || strpos($id, 'revenue') !== false || strpos($id, 'guidelines') !== false) {
            return 'economics';
        }
        if (strpos($id, 'seo') !== false) {
            return 'funnels';
        }
        return 'all';
    }

    /**
     * Include HP and WooCommerce abilities in WooCommerce MCP server with scope support.
     */
    public static function include_hp_abilities_in_wc_mcp(bool $include, string $ability_id): bool
    {
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

        return $should_include;
    }

    /**
     * Register all abilities.
     */
    public static function register_abilities(): void
    {
        self::load_classes();
        
        self::register_product_abilities();
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
                        'description' => 'Comprehensive update data (core fields, acf, seo)',
                        'properties' => (object)[],
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
                    'config' => ['type' => 'object', 'description' => 'Full funnel configuration object', 'properties' => (object)[]],
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
                    'config'      => ['type' => 'object', 'description' => 'Updated funnel configuration object', 'properties' => (object)[]],
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
                    'sections' => ['type' => 'object', 'description' => 'Map of section names to new configurations', 'properties' => (object)[]],
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
                    'config' => ['type' => 'object', 'description' => 'Funnel configuration object to validate', 'properties' => (object)[]],
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
                            <?php submit_button(); ?>
                        </div>
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
This protocol ensures all AI agents maintain the "Self-Healing" HP Abilities ecosystem correctly.

## 1. Tool Stewardship (Development)
- **Namespace**: All custom tools MUST use the `hp-abilities/` prefix.
- **Hook**: Register tools ONLY within the `wp_abilities_api_init` hook in `includes/Plugin.php`.
- **Scoping**: Assign tools to a valid scope to ensure they appear in the correct MCP server:
  - `hp-admin`: Store administration, products, orders.
  - `hp-funnels`: Funnel configuration, building kits, protocols.
  - `hp-seo`: SEO audits, JSON-LD schema.
  - `hp-economics`: Profitability calculations, pricing rules.

## 2. Implementation Standards
- **Callbacks**: Use static methods in existing Ability classes (e.g., `FunnelApi::execute`).
- **Input Validation**: Use standard JSON schema for `input_schema`. Ensure empty parameters are `(object)[]`.
- **Dependencies**: Check if `HP_RW` service classes exist before execution.

## 3. Deployment & Verification
- **Auto-Discovery**: Do NOT manually update the settings page table; the plugin discovers tools dynamically.
- **Health Check**: After adding a tool, you MUST click the "Check Health" button on the Settings page to verify connectivity.
- **Kill-Switch**: Use the "Status" toggle to instantly mute tools if security or performance issues arise.

## 4. MCP Server Sync
- Use the generated snippets in `mcp.json`. The bridge file is distributed with this plugin in the `bin/` folder.
- If Cursor is on a remote machine, copy the bridge code from the "Bridge Source" card to your local `C:\DEV\hp-mcp-bridge.js`.
- Custom bridge handles authentication headers and BOM issues.
EOD;
    }
}
