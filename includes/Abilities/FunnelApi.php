<?php
namespace HP_Abilities\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Funnel API abilities.
 * 
 * Wraps HP-React-Widgets funnel services as WordPress Abilities
 * for MCP access by AI agents.
 */
class FunnelApi
{
    /**
     * Check if HP-React-Widgets is available.
     *
     * @return bool
     */
    private static function is_hp_rw_available(): bool
    {
        return class_exists('\HP_RW\Services\FunnelSystemExplainer');
    }

    /**
     * Return error if HP-RW is not available.
     *
     * @return array
     */
    private static function hp_rw_not_available(): array
    {
        return [
            'success' => false,
            'error' => 'HP-React-Widgets plugin is not active or missing required services.',
        ];
    }

    /**
     * Get complete funnel system documentation.
     *
     * @param mixed  Input parameters (none required).
     * @return array System explanation.
     */
    public static function explainSystem(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        return [
            'success' => true,
            'data' => \HP_RW\Services\FunnelSystemExplainer::getSystemExplanation(),
        ];
    }

    /**
     * Get funnel JSON schema with AI generation hints.
     *
     * @param mixed  Input parameters (none required).
     * @return array Schema with hints.
     */
    public static function getSchema(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        return [
            'success' => true,
            'data' => \HP_RW\Services\FunnelSchema::getSchemaResponse(),
        ];
    }

    /**
     * Get styling schema with theme presets.
     *
     * @param mixed  Input parameters (none required).
     * @return array Styling schema.
     */
    public static function getStylingSchema(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        return [
            'success' => true,
            'data' => \HP_RW\Services\FunnelSchema::getStylingSchema(),
        ];
    }

    /**
     * List all funnels.
     *
     * @param mixed  Input parameters (none required).
     * @return array List of funnels.
     */
    public static function listFunnels(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = get_posts([
            'post_type' => 'hp-funnel',
            'post_status' => 'any',
            'posts_per_page' => -1,
        ]);

         = [];
        foreach ( as ) {
             = get_field('funnel_slug', ->ID) ?: ->post_name;
            [] = [
                'id' => ->ID,
                'title' => ->post_title,
                'slug' => ,
                'status' => ->post_status,
                'modified' => ->post_modified,
                'url' => home_url('/express-shop/' .  . '/'),
            ];
        }

        return [
            'success' => true,
            'count' => count(),
            'funnels' => ,
        ];
    }

    /**
     * Get a complete funnel by slug.
     *
     * @param mixed  Input with 'slug'.
     * @return array Complete funnel data.
     */
    public static function getFunnel(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = sanitize_text_field(['slug'] ?? '');
        if (empty()) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

         = \HP_RW\Services\FunnelConfigLoader::getBySlug();
        if (!) {
            return ['success' => false, 'error' => 'Funnel not found'];
        }

        return [
            'success' => true,
            'funnel' => ,
        ];
    }

    /**
     * Create a new funnel from JSON.
     *
     * @param mixed  Funnel data.
     * @return array Result.
     */
    public static function createFunnel(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = \HP_RW\Services\FunnelConfigLoader::createFunnel();
        
        if (is_wp_error()) {
            return ['success' => false, 'error' => ->get_error_message()];
        }

        return [
            'success' => true,
            'post_id' => ['post_id'],
            'slug' => ['slug'],
        ];
    }

    /**
     * Update an existing funnel.
     *
     * @param mixed  Updated funnel data (including slug).
     * @return array Result.
     */
    public static function updateFunnel(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['slug'] ?? '';
        if (empty()) {
            return ['success' => false, 'error' => 'Slug is required to update'];
        }

         = \HP_RW\Services\FunnelConfigLoader::updateFunnel(, );
        
        if (is_wp_error()) {
            return ['success' => false, 'error' => ->get_error_message()];
        }

        return [
            'success' => true,
            'post_id' => ['post_id'],
            'slug' => ['slug'],
        ];
    }

    /**
     * Update specific sections of a funnel.
     *
     * @param mixed  Array with 'slug' and 'sections' map.
     * @return array Result.
     */
    public static function updateSections(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['slug'] ?? '';
         = ['sections'] ?? [];

        if (empty()) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

         = \HP_RW\Services\FunnelConfigLoader::updateSections(, );
        
        if (is_wp_error()) {
            return ['success' => false, 'error' => ->get_error_message()];
        }

        return ['success' => true];
    }

    /**
     * List saved versions of a funnel.
     *
     * @param mixed  Input with 'slug'.
     * @return array Versions list.
     */
    public static function listVersions(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['slug'] ?? '';
        if (empty()) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

         = self::findFunnelBySlug();
        if (!) {
            return ['success' => false, 'error' => "Funnel '' not found"];
        }

        if (!class_exists('\HP_RW\Services\FunnelVersionControl')) {
            return ['success' => false, 'error' => 'Version control service not available'];
        }

         = \HP_RW\Services\FunnelVersionControl::listVersions();
        
        return [
            'success' => true,
            'versions' => ,
        ];
    }

    /**
     * Create a version backup.
     *
     * @param mixed  Array with 'slug' and 'description'.
     * @return array Result.
     */
    public static function createVersion(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['slug'] ?? '';
         = ['description'] ?? 'Manual backup';

        if (empty()) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

         = self::findFunnelBySlug();
        if (!) {
            return ['success' => false, 'error' => "Funnel '' not found"];
        }

        if (!class_exists('\HP_RW\Services\FunnelVersionControl')) {
            return ['success' => false, 'error' => 'Version control service not available'];
        }

         = \HP_RW\Services\FunnelVersionControl::createVersion(, , 'ai_agent');
        
        return [
            'success' => true,
            'version_id' => ,
        ];
    }

    /**
     * Restore a funnel version.
     *
     * @param mixed  Array with 'slug' and 'version_id'.
     * @return array Result.
     */
    public static function restoreVersion(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['slug'] ?? '';
         = ['version_id'] ?? '';

        if (empty() || empty()) {
            return ['success' => false, 'error' => 'Slug and version_id are required'];
        }

         = self::findFunnelBySlug();
        if (!) {
            return ['success' => false, 'error' => "Funnel '' not found"];
        }

        if (!class_exists('\HP_RW\Services\FunnelVersionControl')) {
            return ['success' => false, 'error' => 'Version control service not available'];
        }

         = \HP_RW\Services\FunnelVersionControl::restoreVersion(, );
        
        if (is_wp_error()) {
            return ['success' => false, 'error' => ->get_error_message()];
        }

        return [
            'success' => true,
            'message' => 'Version restored successfully',
        ];
    }

    /**
     * Validate a funnel JSON object.
     *
     * @param mixed  JSON data.
     * @return array Result.
     */
    public static function validateFunnel(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = \HP_RW\Services\FunnelSchema::validate();
        
        return [
            'valid' => empty(['errors']),
            'errors' => ['errors'] ?? [],
        ];
    }

    /**
     * Run an SEO audit on a funnel.
     * 
     * @param mixed  Input parameters:
     *                     - slug (string) Funnel slug
     *                     - data (array) Optional: Fresh funnel data to audit before saving
     * @return array Audit results.
     */
    public static function seoAudit(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['slug'] ?? '';
         = ['data'] ?? [];

        if (empty() && empty()) {
            return [
                'success' => false,
                'error' => 'Either slug or data must be provided for audit.',
            ];
        }

        if (!empty()) {
             = \HP_RW\Services\FunnelSeoAuditor::audit();
        } else {
             = self::findFunnelBySlug();
            if (!) {
                return [
                    'success' => false,
                    'error' => "Funnel with slug '' not found.",
                ];
            }
             = \HP_RW\Services\FunnelSeoAuditor::audit();
        }

        return [
            'success' => true,
            'data' => ,
        ];
    }

    /**
     * Apply a set of SEO fixes to a funnel.
     * 
     * Accepts a map of fields to update, creates a backup version,
     * updates the meta, and clears the cache.
     * 
     * @param mixed  Input parameters:
     *                     - slug (string) Funnel slug
     *                     - fixes (array) Map of field names to values (e.g. ['focus_keyword' => 'Liver Detox'])
     * @return array Result of the operation.
     */
    public static function applySeoFixes(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = sanitize_text_field(['slug'] ?? '');
         = ['fixes'] ?? [];

        if (empty()) {
            return ['success' => false, 'error' => 'slug is required'];
        }
        if (empty()) {
            return ['success' => false, 'error' => 'fixes array is required'];
        }

         = self::findFunnelBySlug();
        if (!) {
            return ['success' => false, 'error' => "Funnel with slug '' not found."];
        }

        // 1. Create backup version
        if (class_exists('\HP_RW\Services\FunnelVersionControl')) {
            \HP_RW\Services\FunnelVersionControl::createVersion(
                ,
                'Auto-backup before bulk SEO fix',
                'ai_agent'
            );
        }

         = [];

        // Map SEO fields to ACF paths
         = [
            'focus_keyword' => 'seo_focus_keyword',
            'meta_title' => 'seo_meta_title',
            'meta_description' => 'seo_meta_description',
            'hero_image_alt' => 'hero_image_alt',
            'authority_image_alt' => 'authority_image_alt',
            'authority_bio' => 'authority_bio',
        ];

        foreach ( as  => ) {
             = [] ?? ;
            
            // Special handling for HTML fields
            if ( === 'authority_bio') {
                update_post_meta(, , wp_kses_post());
                [] = ;
                continue;
            }

            // Standard text fields
            update_post_meta(, , sanitize_text_field());
            [] = ;
        }

        // 2. Clear funnel cache
        if (class_exists('\HP_RW\Services\FunnelConfigLoader')) {
            \HP_RW\Services\FunnelConfigLoader::clearCache();
        }

        return [
            'success' => true,
            'updated_fields' => ,
        ];
    }

    /**
     * Search WooCommerce products with filters.
     *
     * @param mixed  Search parameters.
     * @return array Search results.
     */
    public static function searchProducts(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = sanitize_text_field(['category'] ?? '');
         = sanitize_text_field(['search'] ?? '');
         = (int)(['limit'] ?? 20);

        if (!class_exists('\HP_RW\Services\ProductCatalogService')) {
            return ['success' => false, 'error' => 'Product catalog service not available'];
        }

         = \HP_RW\Services\ProductCatalogService::search(, , );

        return [
            'success' => true,
            'count' => count(),
            'products' => ,
        ];
    }

    /**
     * Get product details by SKU.
     *
     * @param mixed  Input with 'sku'.
     * @return array Product data.
     */
    public static function getProduct(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = sanitize_text_field(['sku'] ?? '');
        if (empty()) {
            return ['success' => false, 'error' => 'SKU is required'];
        }

        if (!class_exists('\HP_RW\Services\ProductCatalogService')) {
            return ['success' => false, 'error' => 'Product catalog service not available'];
        }

         = \HP_RW\Services\ProductCatalogService::getBySku();
        if (!) {
            return ['success' => false, 'error' => "Product with SKU '' not found"];
        }

        return [
            'success' => true,
            'product' => ,
        ];
    }

    /**
     * Calculate supply for a product.
     *
     * @param mixed  Array with 'sku', 'days', 'servings_per_day'.
     * @return array Supply calculation.
     */
    public static function calculateSupply(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = sanitize_text_field(['sku'] ?? '');
         = (int)(['days'] ?? 30);
         = (int)(['servings_per_day'] ?? 1);

        if (empty()) {
            return ['success' => false, 'error' => 'SKU is required'];
        }

        if (!class_exists('\HP_RW\Services\ProductCatalogService')) {
            return ['success' => false, 'error' => 'Product catalog service not available'];
        }

         = \HP_RW\Services\ProductCatalogService::calculateSupply(, , );

        return [
            'success' => true,
            'calculation' => ,
        ];
    }

    /**
     * Build a product kit from protocol.
     *
     * @param mixed  Array with 'supplements' and 'duration_days'.
     * @return array Built kit.
     */
    public static function buildKit(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['supplements'] ?? [];
         = (int)(['duration_days'] ?? 30);

        if (empty()) {
            return ['success' => false, 'error' => 'Supplements array is required'];
        }

        if (!class_exists('\HP_RW\Services\ProtocolKitBuilder')) {
            return ['success' => false, 'error' => 'Protocol kit builder service not available'];
        }

         = \HP_RW\Services\ProtocolKitBuilder::buildFromProtocol(, );

        return [
            'success' => true,
            'kit' => ,
        ];
    }

    /**
     * Calculate economics for an offer.
     *
     * @param mixed  Array with 'items', 'price', 'shipping_scenario'.
     * @return array Economics results.
     */
    public static function calculateEconomics(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['items'] ?? [];
         = (float)(['price'] ?? 0);
         = sanitize_text_field(['shipping_scenario'] ?? 'domestic');

        if (empty()) {
            return ['success' => false, 'error' => 'Items array is required'];
        }

        if (!class_exists('\HP_RW\Services\EconomicsService')) {
            return ['success' => false, 'error' => 'Economics service not available'];
        }

         = \HP_RW\Services\EconomicsService::calculateOfferProfitability(, , );

        return [
            'success' => true,
            'economics' => ,
        ];
    }

    /**
     * Validate economics against guidelines.
     *
     * @param mixed  Same as calculateEconomics.
     * @return array Validation result.
     */
    public static function validateEconomics(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['items'] ?? [];
         = (float)(['price'] ?? 0);
         = sanitize_text_field(['shipping_scenario'] ?? 'domestic');

        if (empty()) {
            return ['success' => false, 'error' => 'Items array is required'];
        }

        if (!class_exists('\HP_RW\Services\EconomicsService')) {
            return ['success' => false, 'error' => 'Economics service not available'];
        }

         = \HP_RW\Services\EconomicsService::validateOffer(, , );

        return [
            'success' => true,
            'validation' => ,
        ];
    }

    /**
     * Get or set economic guidelines.
     *
     * @param mixed  Optional 'settings' to update.
     * @return array Current guidelines.
     */
    public static function economicGuidelines(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = ['settings'] ?? null;

        if (!class_exists('\HP_RW\Services\EconomicsService')) {
            return ['success' => false, 'error' => 'Economics service not available'];
        }

        if () {
            \HP_RW\Services\EconomicsService::updateGuidelines();
        }

        return [
            'success' => true,
            'guidelines' => \HP_RW\Services\EconomicsService::getGuidelines(),
        ];
    }

    /**
     * Get SEO schema for a funnel.
     *
     * @param mixed  Array with 'funnel_slug'.
     * @return array Result.
     */
    public static function getSeoSchema(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = sanitize_text_field(['funnel_slug'] ?? '');
        if (empty()) {
            return ['success' => false, 'error' => 'funnel_slug is required'];
        }

         = self::findFunnelBySlug();
        if (!) {
            return ['success' => false, 'error' => "Funnel '' not found"];
        }

        if (!class_exists('\HP_RW\Services\FunnelSeoService')) {
            return ['success' => false, 'error' => 'SEO service not available'];
        }

         = \HP_RW\Services\FunnelSeoService::getProductSchema();
        
        return [
            'success' => true,
            'schema' => ,
        ];
    }

    /**
     * Get price range for a funnel.
     *
     * @param mixed  Array with 'funnel_slug'.
     * @return array Result.
     */
    public static function getPriceRange(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

         = self::ensureArrayRecursive();
         = sanitize_text_field(['funnel_slug'] ?? '');
        if (empty()) {
            return ['success' => false, 'error' => 'funnel_slug is required'];
        }

         = self::findFunnelBySlug();
        if (!) {
            return ['success' => false, 'error' => "Funnel '' not found"];
        }

         = (float)get_post_meta(, '_hp_funnel_min_price', true);
         = (float)get_post_meta(, '_hp_funnel_max_price', true);
         = get_woocommerce_currency();

        return [
            'success' => true,
            'min_price' => ,
            'max_price' => ,
            'currency' => ,
        ];
    }

    /**
     * Get canonical status for funnels.
     *
     * @param mixed  Optional params.
     * @return array Result.
     */
    public static function getCanonicalStatus(): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        if (!class_exists('\HP_RW\Services\FunnelSeoService')) {
            return ['success' => false, 'error' => 'SEO service not available'];
        }

        return [
            'success' => true,
            'overrides' => \HP_RW\Services\FunnelSeoService::getCanonicalOverrides(),
        ];
    }

    /**
     * Find funnel post ID by slug.
     *
     * @param string  Funnel slug.
     * @return int|null Post ID or null.
     */
    private static function findFunnelBySlug(string ): ?int
    {
        // First try by ACF field
         = get_posts([
            'post_type' => 'hp-funnel',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'funnel_slug',
                    'value' => ,
                ],
            ],
        ]);

        if (!empty()) {
            return [0]->ID;
        }

        // Try by post_name
         = get_posts([
            'post_type' => 'hp-funnel',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'name' => ,
        ]);

        return !empty() ? [0]->ID : null;
    }

    /**
     * Deeply convert objects to arrays.
     * 
     * @param mixed  Value to convert
     * @return mixed Converted value
     */
    private static function ensureArrayRecursive()
    {
        if (is_object()) {
             = (array) ;
        }
        if (is_array()) {
            foreach ( as  => ) {
                [] = self::ensureArrayRecursive();
            }
        }
        return ;
    }
}
