<?php
namespace HP_Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class for HP Abilities.
 */
class Plugin
{
    /**
     * Initialize the plugin with Abilities API support.
     */
    public static function init(): void
    {
        // Register ability categories first (fires before abilities)
        add_action('wp_abilities_api_categories_init', [self::class, 'register_ability_categories']);
        
        // Register abilities on the correct hook
        add_action('wp_abilities_api_init', [self::class, 'register_abilities']);
        
        // Register settings page
        add_action('admin_menu', [self::class, 'register_settings_page']);

        // Hook into WooCommerce MCP to include HP abilities
        add_filter('woocommerce_mcp_include_ability', [self::class, 'include_hp_abilities_in_wc_mcp'], 10, 2);

        // ALWAYS register REST API endpoints for internal tools (like manual SEO audit)
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
    }

    /**
     * Include HP abilities in WooCommerce MCP server.
     *
     * @param bool   $include    Whether to include the ability.
     * @param string $ability_id The ability ID.
     * @return bool
     */
    public static function include_hp_abilities_in_wc_mcp(bool $include, string $ability_id): bool
    {
        // Enabling ALL HP tools now that we found the schema issue
        if (str_starts_with($ability_id, 'hp-')) {
            return true;
        }
        return $include;
    }

    /**
     * Initialize REST API fallback when Abilities API is not available.
     */
    public static function init_rest_fallback(): void
    {
        add_action('rest_api_init', [self::class, 'register_rest_routes']);
    }

    /**
     * Register all abilities.
     */
    public static function register_abilities(): void
    {
        // Customer lookup ability
        self::register_customer_lookup_ability();
        
        // Order search ability
        self::register_order_search_ability();
        
        // Inventory check ability
        self::register_inventory_check_ability();
        
        // Order status update ability
        self::register_order_status_ability();

        // Test ability
        self::register_test_ability();

        // Funnel abilities (requires HP-React-Widgets)
        self::register_funnel_abilities();

        // SEO & Analytics abilities (requires HP-React-Widgets)
        self::register_seo_abilities();
    }

    /**
     * Register custom ability categories.
     */
    public static function register_ability_categories(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        // HP Admin category for store operations
        wp_register_ability_category('hp-admin', [
            'label'       => __('HP Store Admin', 'hp-abilities'),
            'description' => __('Holistic People store administration abilities', 'hp-abilities'),
        ]);

        // HP Funnels category for funnel management
        wp_register_ability_category('hp-funnels', [
            'label'       => __('HP Funnels', 'hp-abilities'),
            'description' => __('Holistic People sales funnel abilities', 'hp-abilities'),
        ]);

        // HP SEO category for SEO and schema abilities
        wp_register_ability_category('hp-seo', [
            'label'       => __('HP SEO & Analytics', 'hp-abilities'),
            'description' => __('Holistic People SEO, schema, and analytics abilities', 'hp-abilities'),
        ]);
    }

    /**
     * Register all funnel-related abilities.
     */
    private static function register_funnel_abilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        // Apply SEO fixes - MOVED TO TOP FOR DEBUGGING
        wp_register_ability('hp-funnels/apply-seo-fixes', [
            'label'       => __('Apply Funnel SEO Fixes', 'hp-abilities'),
            'description' => __('Apply SEO fixes to a funnel. Pass field names directly (focus_keyword, meta_title, etc.).', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Funnel slug'],
                    'focus_keyword' => ['type' => 'string'],
                    'meta_title' => ['type' => 'string'],
                    'meta_description' => ['type' => 'string'],
                    'hero_image_alt' => ['type' => 'string'],
                    'authority_image_alt' => ['type' => 'string'],
                    'authority_bio' => ['type' => 'string', 'description' => 'HTML content for bio'],
                ],
                'required' => ['slug'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'applySeoFixes'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        // System & Schema abilities
        wp_register_ability('hp-funnels/explain-system', [
            'label'       => __('Explain Funnel System', 'hp-abilities'),
            'description' => __('Get complete funnel system documentation including sections, offer types, styling, and checkout flow.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data' => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'explainSystem'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/schema', [
            'label'       => __('Get Funnel Schema', 'hp-abilities'),
            'description' => __('Get JSON schema with AI generation hints for funnel creation.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data' => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'getSchema'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/styling-schema', [
            'label'       => __('Get Styling Schema', 'hp-abilities'),
            'description' => __('Get styling schema with theme presets and color palettes.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data' => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'getStylingSchema'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        // Funnel CRUD abilities
        wp_register_ability('hp-funnels/list', [
            'label'       => __('List Funnels', 'hp-abilities'),
            'description' => __('List all HP funnels with metadata.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'count' => ['type' => 'integer'],
                    'funnels' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'listFunnels'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/get', [
            'label'       => __('Get Funnel', 'hp-abilities'),
            'description' => __('Get complete funnel data by slug.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Funnel slug',
                    ],
                ],
                'required' => ['slug'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'funnel' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'getFunnel'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/create', [
            'label'       => __('Create Funnel', 'hp-abilities'),
            'description' => __('Create a new funnel from JSON data.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'post_id' => ['type' => 'integer'],
                    'slug' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'createFunnel'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['destructive' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/update', [
            'label'       => __('Update Funnel', 'hp-abilities'),
            'description' => __('Update an existing funnel.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Funnel slug to update',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Funnel data object with sections to update',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['slug', 'data'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'post_id' => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'updateFunnel'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['destructive' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/update-sections', [
            'label'       => __('Update Funnel Sections', 'hp-abilities'),
            'description' => __('Update specific sections of a funnel.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Funnel slug to update',
                    ],
                    'sections' => [
                        'type' => 'object',
                        'description' => 'Object with section names as keys and section data as values',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['slug', 'sections'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'updated_sections' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'updateSections'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['destructive' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        // Version control abilities
        wp_register_ability('hp-funnels/versions-list', [
            'label'       => __('List Funnel Versions', 'hp-abilities'),
            'description' => __('List all saved versions of a funnel.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Funnel slug',
                    ],
                ],
                'required' => ['slug'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'versions' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'properties' => []],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'listVersions'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/versions-create', [
            'label'       => __('Create Funnel Backup', 'hp-abilities'),
            'description' => __('Create a version backup of a funnel.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Funnel slug',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Description of this backup',
                    ],
                ],
                'required' => ['slug'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'version_id' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'createVersion'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['destructive' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/versions-restore', [
            'label'       => __('Restore Funnel Version', 'hp-abilities'),
            'description' => __('Restore a funnel to a previous version.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Funnel slug',
                    ],
                    'version_id' => [
                        'type' => 'string',
                        'description' => 'Version ID to restore',
                    ],
                ],
                'required' => ['slug', 'version_id'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'message' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'restoreVersion'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['destructive' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/validate', [
            'label'       => __('Validate Funnel JSON', 'hp-abilities'),
            'description' => __('Validate a funnel JSON object against the system schema without saving.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'valid' => ['type' => 'boolean'],
                    'errors' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'validateFunnel'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-funnels/seo-audit', [
            'label'       => __('SEO Audit', 'hp-abilities'),
            'description' => __('Run a deep SEO and readability audit on a funnel.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Funnel slug to audit'],
                    'data' => ['type' => 'object', 'description' => 'Optional: Fresh funnel data to audit before saving'],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'seoAudit'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-products/search', [
            'label'       => __('Search Products', 'hp-abilities'),
            'description' => __('Search WooCommerce products with filters.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'Filter by category slug',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search term',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max results (default 20)',
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'count' => ['type' => 'integer'],
                    'products' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'properties' => []],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'searchProducts'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-products/get', [
            'label'       => __('Get Product Details', 'hp-abilities'),
            'description' => __('Get detailed product information by SKU including serving sizes.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sku' => [
                        'type' => 'string',
                        'description' => 'Product SKU',
                    ],
                ],
                'required' => ['sku'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'product' => [
                        'type' => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'getProduct'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-products/calculate-supply', [
            'label'       => __('Calculate Supply', 'hp-abilities'),
            'description' => __('Calculate how many bottles needed for X days of a protocol.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sku' => [
                        'type' => 'string',
                        'description' => 'Product SKU',
                    ],
                    'days' => [
                        'type' => 'integer',
                        'description' => 'Number of days to supply',
                    ],
                    'servings_per_day' => [
                        'type' => 'integer',
                        'description' => 'Servings per day (default 1)',
                    ],
                ],
                'required' => ['sku', 'days'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'sku' => ['type' => 'string'],
                    'bottles_needed' => ['type' => 'integer'],
                    'total_servings' => ['type' => 'integer'],
                    'days_covered' => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'calculateSupply'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        // Protocol builder ability
        wp_register_ability('hp-protocols/build-kit', [
            'label'       => __('Build Kit from Protocol', 'hp-abilities'),
            'description' => __('Build a product kit from a health protocol with quantities for X days.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'supplements' => [
                        'type' => 'array',
                        'description' => 'Array of {sku, servings_per_day}',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sku' => ['type' => 'string'],
                                'servings_per_day' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'duration_days' => [
                        'type' => 'integer',
                        'description' => 'Protocol duration in days',
                    ],
                ],
                'required' => ['supplements', 'duration_days'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'kit_name' => ['type' => 'string'],
                    'products' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'properties' => []],
                    ],
                    'economics' => [
                        'type' => 'object',
                        'properties' => (object) [],
                    ],
                    'offer_options' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'properties' => []],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'buildKit'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        // Economics abilities
        wp_register_ability('hp-economics/calculate', [
            'label'       => __('Calculate Profitability', 'hp-abilities'),
            'description' => __('Calculate offer profitability including COGS and shipping.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'description' => 'Array of {sku, quantity}',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sku' => ['type' => 'string'],
                                'quantity' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'price' => [
                        'type' => 'number',
                        'description' => 'Offer price',
                    ],
                    'shipping_scenario' => [
                        'type' => 'string',
                        'description' => 'domestic, international, or mixed',
                    ],
                ],
                'required' => ['items', 'price'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'total_cost' => ['type' => 'number'],
                    'profit' => ['type' => 'number'],
                    'margin_percent' => ['type' => 'number'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'calculateEconomics'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-economics/validate', [
            'label'       => __('Validate Offer Economics', 'hp-abilities'),
            'description' => __('Validate if an offer meets economic guidelines.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'description' => 'Array of {sku, quantity}',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sku' => ['type' => 'string'],
                                'quantity' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'price' => [
                        'type' => 'number',
                        'description' => 'Offer price',
                    ],
                    'shipping_scenario' => [
                        'type' => 'string',
                        'description' => 'domestic, international, or mixed',
                    ],
                ],
                'required' => ['items', 'price'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'valid' => ['type' => 'boolean'],
                    'issues' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'suggestions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'validateEconomics'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        wp_register_ability('hp-economics/guidelines', [
            'label'       => __('Economic Guidelines', 'hp-abilities'),
            'description' => __('Get or set economic guidelines (min profit %, min profit $).', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'settings' => [
                        'type' => 'object',
                        'description' => 'Optional: new settings to save',
                        'properties' => [
                            'min_profit_percent' => ['type' => 'number'],
                            'min_profit_dollars' => ['type' => 'number'],
                            'free_shipping_threshold' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'guidelines' => [
                        'type' => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'economicGuidelines'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => false], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }

    /**
     * Register SEO & Analytics related abilities.
     */
    private static function register_seo_abilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        // Get funnel JSON-LD schema
        wp_register_ability('hp-seo/funnel-schema', [
            'label'       => __('Get Funnel JSON-LD Schema', 'hp-abilities'),
            'description' => __('Get the complete JSON-LD Product schema for a funnel, including AggregateOffer with price range and reviews.', 'hp-abilities'),
            'category'    => 'hp-seo',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'funnel_slug' => [
                        'type' => 'string',
                        'description' => 'The slug of the funnel to get schema for',
                    ],
                ],
                'required' => ['funnel_slug'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'funnel_slug' => ['type' => 'string'],
                    'schema' => [
                        'type' => 'object',
                        'description' => 'The JSON-LD schema object',
                        'properties' => (object) [],
                    ],
                    'schema_json' => [
                        'type' => 'string',
                        'description' => 'The schema as a formatted JSON string',
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'getSeoSchema'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        // Get funnel price range
        wp_register_ability('hp-economics/price-range', [
            'label'       => __('Get Funnel Price Range', 'hp-abilities'),
            'description' => __('Get the calculated min/max price range for a funnel. Prices are calculated on funnel save and never $0.', 'hp-abilities'),
            'category'    => 'hp-seo',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'funnel_slug' => [
                        'type' => 'string',
                        'description' => 'The slug of the funnel to get price range for',
                    ],
                ],
                'required' => ['funnel_slug'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'funnel_slug' => ['type' => 'string'],
                    'price_range' => [
                        'type' => 'object',
                        'properties' => [
                            'min' => ['type' => 'number', 'description' => 'Minimum price (never $0)'],
                            'max' => ['type' => 'number', 'description' => 'Maximum price'],
                            'currency' => ['type' => 'string', 'description' => 'Currency code (e.g., USD)'],
                            'display' => ['type' => 'string', 'description' => 'Formatted display string'],
                        ],
                    ],
                    'brand' => ['type' => 'string', 'description' => 'Brand name for Google Shopping'],
                    'availability' => ['type' => 'string', 'description' => 'Stock availability status'],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'getPriceRange'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);

        // Get canonical status for products and categories
        wp_register_ability('hp-seo/canonical-status', [
            'label'       => __('Get Canonical Override Status', 'hp-abilities'),
            'description' => __('List products and categories that have funnel canonical overrides configured.', 'hp-abilities'),
            'category'    => 'hp-seo',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'product_overrides' => ['type' => 'integer', 'description' => 'Number of products with funnel canonical overrides'],
                    'category_overrides' => ['type' => 'integer', 'description' => 'Number of categories with funnel canonical overrides'],
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'products' => [
                                'type' => 'array',
                                'description' => 'Products with canonical overrides',
                                'items' => ['type' => 'object', 'properties' => []],
                            ],
                            'categories' => [
                                'type' => 'array',
                                'description' => 'Categories with canonical overrides',
                                'items' => ['type' => 'object', 'properties' => []],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'getCanonicalStatus'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['readonly' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }

    /**
     * Register the bulk SEO fix ability.
     */
    private static function register_apply_seo_fixes_ability(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability('hp-funnels/apply-seo-fixes', [
            'label'       => __('Apply Funnel SEO Fixes', 'hp-abilities'),
            'description' => __('Bulk apply SEO fixes to a funnel including metadata and content.', 'hp-abilities'),
            'category'    => 'hp-funnels',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Funnel slug'],
                    'fixes' => [
                        'type' => 'object',
                        'description' => 'Map of fields to update (focus_keyword, meta_title, meta_description, etc.)',
                        'properties' => [
                            'focus_keyword' => ['type' => 'string'],
                            'meta_title' => ['type' => 'string'],
                            'meta_description' => ['type' => 'string'],
                            'hero_image_alt' => ['type' => 'string'],
                            'authority_image_alt' => ['type' => 'string'],
                            'authority_bio' => ['type' => 'string', 'description' => 'HTML content for bio'],
                        ],
                    ],
                ],
                'required' => ['slug', 'fixes'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'message' => ['type' => 'string'],
                    'updated_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'execute_callback'    => [Abilities\FunnelApi::class, 'applySeoFixes'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => ['show_in_rest' => true, 'annotations' => ['destructive' => true], 'mcp' => ['public' => true, 'type' => 'tool']],
        ]);
    }

    /**
     * Register customer lookup ability.
     */
    private static function register_customer_lookup_ability(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability('hp-customers/lookup', [
            'label'       => __('Lookup Customer', 'hp-abilities'),
            'description' => __('Get customer profile, order history, points balance, and lifetime value by email.', 'hp-abilities'),
            'category'    => 'hp-admin',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'email' => [
                        'type'        => 'string',
                        'description' => 'Customer email address',
                    ],
                ],
                'required' => ['email'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'found'           => ['type' => 'boolean'],
                    'user_id'         => ['type' => 'integer'],
                    'name'            => ['type' => 'string'],
                    'email'           => ['type' => 'string'],
                    'orders_count'    => ['type' => 'integer'],
                    'total_spent'     => ['type' => 'number'],
                    'points_balance'  => ['type' => 'integer'],
                    'last_order_date' => ['type' => 'string'],
                    'billing_address' => [
                        'type' => 'object',
                        'properties' => (object) [],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\CustomerLookup::class, 'execute'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'meta' => [
                'show_in_rest' => true,
                'annotations'  => [
                    'readonly' => true,
                ],
                'mcp' => ['public' => true, 'type' => 'tool'],
            ],
        ]);
    }

    /**
     * Register order search ability.
     */
    private static function register_order_search_ability(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability('hp-orders/search', [
            'label'       => __('Search Orders', 'hp-abilities'),
            'description' => __('Find WooCommerce orders by customer email, status, date range, or product.', 'hp-abilities'),
            'category'    => 'hp-admin',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'customer_email' => [
                        'type'        => 'string',
                        'description' => 'Filter by customer email',
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Order status (processing, completed, on-hold, etc.)',
                    ],
                    'product_sku' => [
                        'type'        => 'string',
                        'description' => 'Filter orders containing this product SKU',
                    ],
                    'date_from' => [
                        'type'        => 'string',
                        'description' => 'Start date (YYYY-MM-DD)',
                    ],
                    'date_to' => [
                        'type'        => 'string',
                        'description' => 'End date (YYYY-MM-DD)',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum number of orders to return (default: 10)',
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'orders' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id'           => ['type' => 'integer'],
                                'number'       => ['type' => 'string'],
                                'status'       => ['type' => 'string'],
                                'date'         => ['type' => 'string'],
                                'total'        => ['type' => 'number'],
                                'customer'     => ['type' => 'string'],
                                'items_count'  => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'total_found' => ['type' => 'integer'],
                ],
            ],
            'execute_callback'    => [Abilities\OrderSearch::class, 'execute'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'meta' => [
                'show_in_rest' => true,
                'annotations'  => [
                    'readonly' => true,
                ],
                'mcp' => ['public' => true, 'type' => 'tool'],
            ],
        ]);
    }

    /**
     * Register inventory check ability.
     */
    private static function register_inventory_check_ability(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability('hp-inventory/check', [
            'label'       => __('Check Inventory', 'hp-abilities'),
            'description' => __('Check stock levels for one or more products by SKU.', 'hp-abilities'),
            'category'    => 'hp-admin',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'skus' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Array of product SKUs to check',
                    ],
                ],
                'required' => ['skus'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'products' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sku'           => ['type' => 'string'],
                                'name'          => ['type' => 'string'],
                                'in_stock'      => ['type' => 'boolean'],
                                'stock_qty'     => ['type' => 'integer'],
                                'stock_status'  => ['type' => 'string'],
                                'backorders'    => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => [Abilities\InventoryCheck::class, 'execute'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'meta' => [
                'show_in_rest' => true,
                'annotations'  => [
                    'readonly' => true,
                ],
                'mcp' => ['public' => true, 'type' => 'tool'],
            ],
        ]);
    }

    /**
     * Register order status update ability.
     */
    private static function register_order_status_ability(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability('hp-orders/update-status', [
            'label'       => __('Update Order Status', 'hp-abilities'),
            'description' => __('Change the status of a WooCommerce order.', 'hp-abilities'),
            'category'    => 'hp-admin',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'order_id' => [
                        'type'        => 'integer',
                        'description' => 'WooCommerce order ID',
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'New order status (processing, completed, on-hold, cancelled, refunded)',
                    ],
                    'note' => [
                        'type'        => 'string',
                        'description' => 'Optional note to add to the order',
                    ],
                ],
                'required' => ['order_id', 'status'],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success'       => ['type' => 'boolean'],
                    'order_id'      => ['type' => 'integer'],
                    'old_status'    => ['type' => 'string'],
                    'new_status'    => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [Abilities\OrderStatus::class, 'execute'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'meta' => [
                'show_in_rest' => true,
                'annotations'  => [
                    'destructive' => true,
                ],
                'mcp' => ['public' => true, 'type' => 'tool'],
            ],
        ]);
    }

    /**
     * Register test ability for MCP debugging.
     */
    private static function register_test_ability(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability('hp-test/hello', [
            'label'       => __('MCP Test Hello', 'hp-abilities'),
            'description' => __('A simple test ability to verify MCP tool registration.', 'hp-abilities'),
            'category'    => 'hp-admin',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type'        => 'string',
                        'description' => 'Your name',
                    ],
                ],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'message'   => ['type' => 'string'],
                    'timestamp' => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [Abilities\Test::class, 'hello'],
            'permission_callback' => fn() => current_user_can('manage_woocommerce'),
            'meta' => [
                'show_in_rest' => true,
                'annotations'  => [
                    'readonly' => true,
                ],
                'mcp' => ['public' => true, 'type' => 'tool'],
            ],
        ]);
    }

    /**
     * Register REST API routes as fallback.
     */
    public static function register_rest_routes(): void
    {
        $namespace = 'hp-abilities/v1';

        register_rest_route($namespace, '/customers/lookup', [
            'methods'             => 'POST',
            'callback'            => function ($request) {
                return Abilities\CustomerLookup::execute([
                    'email' => $request->get_param('email'),
                ]);
            },
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);

        register_rest_route($namespace, '/orders/search', [
            'methods'             => 'POST',
            'callback'            => function ($request) {
                return Abilities\OrderSearch::execute($request->get_params());
            },
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);

        register_rest_route($namespace, '/inventory/check', [
            'methods'             => 'POST',
            'callback'            => function ($request) {
                return Abilities\InventoryCheck::execute([
                    'skus' => $request->get_param('skus'),
                ]);
            },
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);

        register_rest_route($namespace, '/orders/update-status', [
            'methods'             => 'POST',
            'callback'            => function ($request) {
                return Abilities\OrderStatus::execute($request->get_params());
            },
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);

        register_rest_route($namespace, '/funnels/(?P<slug>[a-zA-Z0-9-]+)/seo-audit', [
            'methods'             => 'GET',
            'callback'            => function ($request) {
                return Abilities\FunnelApi::seoAudit([
                    'slug' => $request->get_param('slug'),
                ]);
            },
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);
    }

    /**
     * Register admin settings page.
     */
    public static function register_settings_page(): void
    {
        add_options_page(
            __('HP Abilities', 'hp-abilities'),
            __('HP Abilities', 'hp-abilities'),
            'manage_options',
            'hp-abilities',
            [self::class, 'render_settings_page']
        );
    }

    /**
     * Render the settings page.
     */
    public static function render_settings_page(): void
    {
        $abilities_available = function_exists('wp_register_ability');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('HP Abilities', 'hp-abilities'); ?></h1>
            
            <div class="card">
                <h2><?php echo esc_html__('Status', 'hp-abilities'); ?></h2>
                <p>
                    <strong><?php echo esc_html__('Abilities API:', 'hp-abilities'); ?></strong>
                    <?php if ($abilities_available): ?>
                        <span style="color: green;">✓ <?php echo esc_html__('Available', 'hp-abilities'); ?></span>
                    <?php else: ?>
                        <span style="color: orange;">⚠ <?php echo esc_html__('Not available (requires WordPress 6.9+)', 'hp-abilities'); ?></span>
                    <?php endif; ?>
                </p>
                <p>
                    <strong><?php echo esc_html__('REST API Fallback:', 'hp-abilities'); ?></strong>
                    <span style="color: green;">✓ <?php echo esc_html__('Active', 'hp-abilities'); ?></span>
                </p>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('Core Abilities', 'hp-abilities'); ?></h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Ability', 'hp-abilities'); ?></th>
                            <th><?php echo esc_html__('Description', 'hp-abilities'); ?></th>
                            <th><?php echo esc_html__('REST Endpoint', 'hp-abilities'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('Core Abilities', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp-test/hello</code></td>
                            <td><?php echo esc_html__('Simple test ability for MCP debugging', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/customers/lookup</code></td>
                            <td><?php echo esc_html__('Lookup customer by email', 'hp-abilities'); ?></td>
                            <td><code>POST /wp-json/hp-abilities/v1/customers/lookup</code></td>
                        </tr>
                        <tr>
                            <td><code>hp/orders/search</code></td>
                            <td><?php echo esc_html__('Search orders', 'hp-abilities'); ?></td>
                            <td><code>POST /wp-json/hp-abilities/v1/orders/search</code></td>
                        </tr>
                        <tr>
                            <td><code>hp/inventory/check</code></td>
                            <td><?php echo esc_html__('Check product inventory', 'hp-abilities'); ?></td>
                            <td><code>POST /wp-json/hp-abilities/v1/inventory/check</code></td>
                        </tr>
                        <tr>
                            <td><code>hp/orders/update-status</code></td>
                            <td><?php echo esc_html__('Update order status', 'hp-abilities'); ?></td>
                            <td><code>POST /wp-json/hp-abilities/v1/orders/update-status</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('Funnel Abilities', 'hp-abilities'); ?></h2>
                <p><?php echo esc_html__('These abilities require the HP-React-Widgets plugin to be active.', 'hp-abilities'); ?></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Ability', 'hp-abilities'); ?></th>
                            <th><?php echo esc_html__('Description', 'hp-abilities'); ?></th>
                            <th><?php echo esc_html__('Type', 'hp-abilities'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('System & Schema', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp/funnels/explain-system</code></td>
                            <td><?php echo esc_html__('Get complete funnel system documentation', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/funnels/schema</code></td>
                            <td><?php echo esc_html__('Get JSON schema with AI hints', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/funnels/styling-schema</code></td>
                            <td><?php echo esc_html__('Get styling schema with theme presets', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>

                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('Funnel CRUD', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp/funnels/list</code></td>
                            <td><?php echo esc_html__('List all funnels', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/funnels/get</code></td>
                            <td><?php echo esc_html__('Get complete funnel by slug', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/funnels/create</code></td>
                            <td><?php echo esc_html__('Create new funnel', 'hp-abilities'); ?></td>
                            <td><span style="color: #d63638;"><?php echo esc_html__('Write', 'hp-abilities'); ?></span></td>
                        </tr>
                        <tr>
                            <td><code>hp/funnels/update</code></td>
                            <td><?php echo esc_html__('Update existing funnel', 'hp-abilities'); ?></td>
                            <td><span style="color: #d63638;"><?php echo esc_html__('Write', 'hp-abilities'); ?></span></td>
                        </tr>
                        <tr>
                            <td><code>hp/funnels/update-sections</code></td>
                            <td><?php echo esc_html__('Update specific sections', 'hp-abilities'); ?></td>
                            <td><span style="color: #d63638;"><?php echo esc_html__('Write', 'hp-abilities'); ?></span></td>
                        </tr>

                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('Version Control', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp/funnels/versions/list</code></td>
                            <td><?php echo esc_html__('List funnel versions', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/funnels/versions/create</code></td>
                            <td><?php echo esc_html__('Create backup', 'hp-abilities'); ?></td>
                            <td><span style="color: #d63638;"><?php echo esc_html__('Write', 'hp-abilities'); ?></span></td>
                        </tr>
                        <tr>
                            <td><code>hp/funnels/versions/restore</code></td>
                            <td><?php echo esc_html__('Restore previous version', 'hp-abilities'); ?></td>
                            <td><span style="color: #d63638;"><?php echo esc_html__('Write', 'hp-abilities'); ?></span></td>
                        </tr>

                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('Products', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp/products/search</code></td>
                            <td><?php echo esc_html__('Search products', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/products/get</code></td>
                            <td><?php echo esc_html__('Get product details', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/products/calculate-supply</code></td>
                            <td><?php echo esc_html__('Calculate supply for protocol', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>

                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('Protocol Builder', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp/protocols/build-kit</code></td>
                            <td><?php echo esc_html__('Build product kit from protocol', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>

                        <tr style="background: #f9f9f9;"><td colspan="3"><strong><?php echo esc_html__('Economics', 'hp-abilities'); ?></strong></td></tr>
                        <tr>
                            <td><code>hp/economics/calculate</code></td>
                            <td><?php echo esc_html__('Calculate offer profitability', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/economics/validate</code></td>
                            <td><?php echo esc_html__('Validate offer against guidelines', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp/economics/guidelines</code></td>
                            <td><?php echo esc_html__('Get/set economic guidelines', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read/Write', 'hp-abilities'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('SEO & Analytics Abilities', 'hp-abilities'); ?></h2>
                <p><?php echo esc_html__('These abilities provide SEO schema, pricing, and canonical override information.', 'hp-abilities'); ?></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Ability', 'hp-abilities'); ?></th>
                            <th><?php echo esc_html__('Description', 'hp-abilities'); ?></th>
                            <th><?php echo esc_html__('Type', 'hp-abilities'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>hp-seo/funnel-schema</code></td>
                            <td><?php echo esc_html__('Get JSON-LD Product schema with AggregateOffer and reviews', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp-economics/price-range</code></td>
                            <td><?php echo esc_html__('Get calculated min/max price range for a funnel', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                        <tr>
                            <td><code>hp-seo/canonical-status</code></td>
                            <td><?php echo esc_html__('List products/categories with funnel canonical overrides', 'hp-abilities'); ?></td>
                            <td><?php echo esc_html__('Read', 'hp-abilities'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php echo esc_html__('Usage with AI Agents', 'hp-abilities'); ?></h2>
                <p><?php echo esc_html__('These abilities can be accessed by AI agents via:', 'hp-abilities'); ?></p>
                <ul>
                    <li><strong><?php echo esc_html__('Abilities API:', 'hp-abilities'); ?></strong> <code>executeAbility('hp-customers/lookup', { email: '...' })</code></li>
                    <li><strong><?php echo esc_html__('REST API:', 'hp-abilities'); ?></strong> <code>POST /wp-json/hp-abilities/v1/customers/lookup</code></li>
                    <li><strong><?php echo esc_html__('MCP:', 'hp-abilities'); ?></strong> <?php echo esc_html__('Via WordPress MCP adapter', 'hp-abilities'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }
}















