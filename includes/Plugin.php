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

        // Hook into WooCommerce MCP to include HP abilities
        add_filter('woocommerce_mcp_include_ability', [self::class, 'include_hp_abilities_in_wc_mcp'], 10, 2);
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

        // Staging Config
        $stg_config = [
            'mcpServers' => [
                'hp_products_stg' => [
                    'command' => 'node',
                    'args' => [
                        'C:\\DEV\\hp-mcp-bridge.js',
                        'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-json/woocommerce/mcp?scope=products',
                        $stg_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_orders_stg' => [
                    'command' => 'node',
                    'args' => [
                        'C:\\DEV\\hp-mcp-bridge.js',
                        'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-json/woocommerce/mcp?scope=orders',
                        $stg_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_funnels_stg' => [
                    'command' => 'node',
                    'args' => [
                        'C:\\DEV\\hp-mcp-bridge.js',
                        'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-json/woocommerce/mcp?scope=funnels',
                        $stg_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_economics_stg' => [
                    'command' => 'node',
                    'args' => [
                        'C:\\DEV\\hp-mcp-bridge.js',
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
                        'C:\\DEV\\hp-mcp-bridge.js',
                        'https://holisticpeople.com/wp-json/woocommerce/mcp?scope=products',
                        $prod_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_orders_prod' => [
                    'command' => 'node',
                    'args' => [
                        'C:\\DEV\\hp-mcp-bridge.js',
                        'https://holisticpeople.com/wp-json/woocommerce/mcp?scope=orders',
                        $prod_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_funnels_prod' => [
                    'command' => 'node',
                    'args' => [
                        'C:\\DEV\\hp-mcp-bridge.js',
                        'https://holisticpeople.com/wp-json/woocommerce/mcp?scope=funnels',
                        $prod_key
                    ],
                    'type' => 'stdio'
                ],
                'hp_economics_prod' => [
                    'command' => 'node',
                    'args' => [
                        'C:\\DEV\\hp-mcp-bridge.js',
                        'https://holisticpeople.com/wp-json/woocommerce/mcp?scope=economics',
                        $prod_key
                    ],
                    'type' => 'stdio'
                ]
            ]
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('HP Abilities', 'hp-abilities'); ?> <span style="font-size: 0.5em; vertical-align: middle; background: #eee; padding: 2px 8px; border-radius: 4px;">v<?php echo esc_html(HP_ABILITIES_VERSION); ?></span></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('hp_abilities_settings'); ?>
                
                <div class="card">
                    <h2><?php echo esc_html__('Status', 'hp-abilities'); ?></h2>
                    <p>
                        <strong><?php echo esc_html__('Abilities API:', 'hp-abilities'); ?></strong>
                        <?php if ($abilities_available): ?>
                            <span style="color: green;">✔ <?php echo esc_html__('Available', 'hp-abilities'); ?></span>
                        <?php else: ?>
                            <span style="color: orange;">⚠ <?php echo esc_html__('Not available (requires WordPress 6.9+)', 'hp-abilities'); ?></span>
                        <?php endif; ?>
                    </p>
                    <p>
                        <strong><?php echo esc_html__('HP-React-Widgets:', 'hp-abilities'); ?></strong>
                        <?php if ($hp_rw_active): ?>
                            <span style="color: green;">✔ <?php echo esc_html__('Active', 'hp-abilities'); ?></span>
                        <?php else: ?>
                            <span style="color: #d63638;">✘ <?php echo esc_html__('Inactive (Required for Funnel/Economics abilities)', 'hp-abilities'); ?></span>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="card" style="margin-top: 20px;">
                    <h2><?php echo esc_html__('WooCommerce API Credentials', 'hp-abilities'); ?></h2>
                    <p><?php echo esc_html__('Enter your WooCommerce Consumer Key and Secret for Staging and Production. These are used to generate the Cursor configuration snippets below.', 'hp-abilities'); ?></p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="hp_abilities_stg_ck"><?php echo esc_html__('Staging Consumer Key', 'hp-abilities'); ?></label></th>
                            <td><input name="hp_abilities_stg_ck" type="text" id="hp_abilities_stg_ck" value="<?php echo esc_attr($stg_ck); ?>" class="regular-text" placeholder="ck_..."></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hp_abilities_stg_cs"><?php echo esc_html__('Staging Consumer Secret', 'hp-abilities'); ?></label></th>
                            <td><input name="hp_abilities_stg_cs" type="password" id="hp_abilities_stg_cs" value="<?php echo esc_attr($stg_cs); ?>" class="regular-text" placeholder="cs_..."></td>
                        </tr>
                        <tr><td colspan="2"><hr></td></tr>
                        <tr>
                            <th scope="row"><label for="hp_abilities_prod_ck"><?php echo esc_html__('Production Consumer Key', 'hp-abilities'); ?></label></th>
                            <td><input name="hp_abilities_prod_ck" type="text" id="hp_abilities_prod_ck" value="<?php echo esc_attr($prod_ck); ?>" class="regular-text" placeholder="ck_..."></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hp_abilities_prod_cs"><?php echo esc_html__('Production Consumer Secret', 'hp-abilities'); ?></label></th>
                            <td><input name="hp_abilities_prod_cs" type="password" id="hp_abilities_prod_cs" value="<?php echo esc_attr($prod_cs); ?>" class="regular-text" placeholder="cs_..."></td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </div>
            </form>

            <?php if ($hp_rw_active): ?>
            <div class="card" style="margin-top: 20px; border-left: 4px solid #72aee6;">
                <h2><?php echo esc_html__('AI Configuration', 'hp-abilities'); ?></h2>
                <p><?php echo esc_html__('Configure economic guidelines and version control settings for AI funnel creation.', 'hp-abilities'); ?></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=hp-funnel&page=hp-funnel-ai-settings')); ?>" class="button button-secondary">
                        <?php echo esc_html__('Go to AI Settings', 'hp-abilities'); ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('Cursor Configuration Snippets', 'hp-abilities'); ?></h2>
                <p><?php echo esc_html__('Copy these snippets into your %USERPROFILE%\.cursor\mcp.json file. They include the API keys saved above.', 'hp-abilities'); ?></p>
                
                <div style="display: flex; gap: 20px; margin-top: 15px;">
                    <div style="flex: 1;">
                        <h3><?php echo esc_html__('Staging Environment', 'hp-abilities'); ?></h3>
                        <div style="position: relative;">
                            <textarea id="stg_mcp_snippet" readonly style="width: 100%; height: 250px; font-family: monospace; background: #f9f9f9; padding: 10px; border: 1px solid #ccc;"><?php 
                            echo esc_textarea(json_encode($stg_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                            ?></textarea>
                            <button class="button button-small" style="position: absolute; top: 10px; right: 10px;" onclick="copyToClipboard('stg_mcp_snippet', this)">Copy</button>
                        </div>
                    </div>
                    <div style="flex: 1;">
                        <h3><?php echo esc_html__('Production Environment', 'hp-abilities'); ?></h3>
                        <div style="position: relative;">
                            <textarea id="prod_mcp_snippet" readonly style="width: 100%; height: 250px; font-family: monospace; background: #f9f9f9; padding: 10px; border: 1px solid #ccc;"><?php 
                            echo esc_textarea(json_encode($prod_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                            ?></textarea>
                            <button class="button button-small" style="position: absolute; top: 10px; right: 10px;" onclick="copyToClipboard('prod_mcp_snippet', this)">Copy</button>
                        </div>
                    </div>
                </div>

                <script>
                function copyToClipboard(elementId, btn) {
                    var copyText = document.getElementById(elementId);
                    copyText.select();
                    copyText.setSelectionRange(0, 99999);
                    navigator.clipboard.writeText(copyText.value);
                    
                    var originalText = btn.innerText;
                    btn.innerText = 'Copied!';
                    setTimeout(function() {
                        btn.innerText = originalText;
                    }, 2000);
                }
                </script>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('Registered Abilities', 'hp-abilities'); ?></h2>
                <p><?php echo esc_html__('The following abilities are registered and exposed to AI agents via MCP.', 'hp-abilities'); ?></p>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Ability ID', 'hp-abilities'); ?></th>
                            <th><?php echo esc_html__('Description', 'hp-abilities'); ?></th>
                            <th><?php echo esc_html__('Category', 'hp-abilities'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('Store Administration', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp-abilities/inventory-check</code></td>
                            <td><?php echo esc_html__('Check stock levels by SKU', 'hp-abilities'); ?></td>
                            <td><code>hp-admin</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/products-search</code></td>
                            <td><?php echo esc_html__('Search WooCommerce products with HP filters', 'hp-abilities'); ?></td>
                            <td><code>hp-admin</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/products-get</code></td>
                            <td><?php echo esc_html__('Get product details by SKU', 'hp-abilities'); ?></td>
                            <td><code>hp-admin</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/products-calculate-supply</code></td>
                            <td><?php echo esc_html__('Calculate product supply days based on servings', 'hp-abilities'); ?></td>
                            <td><code>hp-admin</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/products-update-comprehensive</code></td>
                            <td><?php echo esc_html__('Comprehensive update: core fields, ACF, and SEO', 'hp-abilities'); ?></td>
                            <td><code>hp-admin</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/products-seo-audit</code></td>
                            <td><?php echo esc_html__('Perform on-demand SEO health check', 'hp-abilities'); ?></td>
                            <td><code>hp-admin</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/customers-lookup</code></td>
                            <td><?php echo esc_html__('Get customer profile and history by email', 'hp-abilities'); ?></td>
                            <td><code>hp-admin</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/orders-search</code></td>
                            <td><?php echo esc_html__('Search WooCommerce orders', 'hp-abilities'); ?></td>
                            <td><code>hp-admin</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/orders-update-status</code></td>
                            <td><?php echo esc_html__('Change WooCommerce order status', 'hp-abilities'); ?></td>
                            <td><code>hp-admin</code></td>
                        </tr>

                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('Funnels & Protocols', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp-abilities/funnels-list</code></td>
                            <td><?php echo esc_html__('List all HP funnels', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-get</code></td>
                            <td><?php echo esc_html__('Get complete funnel configuration by slug', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-create</code></td>
                            <td><?php echo esc_html__('Create a new funnel from JSON configuration', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-update</code></td>
                            <td><?php echo esc_html__('Update an existing funnel configuration', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-update-sections</code></td>
                            <td><?php echo esc_html__('Update specific sections of a funnel', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-validate</code></td>
                            <td><?php echo esc_html__('Validate a funnel JSON configuration', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-restore-version</code></td>
                            <td><?php echo esc_html__('Restore a funnel to a previously saved version', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-explain-system</code></td>
                            <td><?php echo esc_html__('Get funnel architecture documentation', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-schema</code></td>
                            <td><?php echo esc_html__('Get funnel JSON schema with AI hints', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-versions-list</code></td>
                            <td><?php echo esc_html__('List saved backup versions of a funnel', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-versions-create</code></td>
                            <td><?php echo esc_html__('Create a manual backup version of a funnel', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/protocols-build-kit</code></td>
                            <td><?php echo esc_html__('Build a product kit from a set of supplements', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>

                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('SEO & Economics', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp-abilities/funnels-apply-seo-fixes</code></td>
                            <td><?php echo esc_html__('Bulk apply SEO fixes to a funnel', 'hp-abilities'); ?></td>
                            <td><code>hp-funnels</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/seo-funnel-schema</code></td>
                            <td><?php echo esc_html__('Get JSON-LD schema for a funnel', 'hp-abilities'); ?></td>
                            <td><code>hp-seo</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/funnels-seo-audit</code></td>
                            <td><?php echo esc_html__('Perform an SEO audit on a funnel', 'hp-abilities'); ?></td>
                            <td><code>hp-seo</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/economics-calculate</code></td>
                            <td><?php echo esc_html__('Calculate profitability for an offer', 'hp-abilities'); ?></td>
                            <td><code>hp-economics</code></td>
                        </tr>
                        <tr>
                            <td><code>hp-abilities/economics-validate</code></td>
                            <td><?php echo esc_html__('Validate an offer against economic guidelines', 'hp-abilities'); ?></td>
                            <td><code>hp-economics</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
