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
     * @param mixed $input Input parameters (none required).
     * @return array System explanation.
     */
    public static function explainSystem($input): array
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
     * @param mixed $input Input parameters (none required).
     * @return array Schema with hints.
     */
    public static function getSchema($input): array
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
     * @param mixed $input Input parameters (none required).
     * @return array Styling schema.
     */
    public static function getStylingSchema($input): array
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
     * @param mixed $input Input parameters (none required).
     * @return array List of funnels.
     */
    public static function listFunnels($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $funnels = get_posts([
            'post_type' => 'hp-funnel',
            'post_status' => 'any',
            'posts_per_page' => -1,
        ]);

        $result = [];
        foreach ($funnels as $funnel) {
            $slug = get_field('funnel_slug', $funnel->ID) ?: $funnel->post_name;
            $result[] = [
                'id' => $funnel->ID,
                'title' => $funnel->post_title,
                'slug' => $slug,
                'status' => $funnel->post_status,
                'modified' => $funnel->post_modified,
                'url' => home_url('/express-shop/' . $slug . '/'),
            ];
        }

        return [
            'success' => true,
            'count' => count($result),
            'funnels' => $result,
        ];
    }

    /**
     * Get a complete funnel by slug.
     *
     * @param mixed $input Input with 'slug'.
     * @return array Complete funnel data.
     */
    public static function getFunnel($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['slug'] ?? '');
        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

        $funnel = \HP_RW\Services\FunnelConfigLoader::getBySlug($slug);
        if (!$funnel) {
            return ['success' => false, 'error' => 'Funnel not found'];
        }

        return [
            'success' => true,
            'funnel' => $funnel,
        ];
    }

    /**
     * Create a new funnel from JSON.
     *
     * @param mixed $input Funnel data.
     * @return array Result.
     */
    public static function createFunnel($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $result = \HP_RW\Services\FunnelConfigLoader::createFunnel($input);
        
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'post_id' => $result['post_id'],
            'slug' => $result['slug'],
        ];
    }

    /**
     * Update an existing funnel.
     *
     * @param mixed $input Updated funnel data (including slug).
     * @return array Result.
     */
    public static function updateFunnel($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = $input['slug'] ?? '';
        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required to update'];
        }

        $result = \HP_RW\Services\FunnelConfigLoader::updateFunnel($slug, $input);
        
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'post_id' => $result['post_id'],
            'slug' => $result['slug'],
        ];
    }

    /**
     * Update specific sections of a funnel.
     *
     * @param mixed $input Array with 'slug' and 'sections' map.
     * @return array Result.
     */
    public static function updateSections($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = $input['slug'] ?? '';
        $sections = $input['sections'] ?? [];

        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

        $result = \HP_RW\Services\FunnelConfigLoader::updateSections($slug, $sections);
        
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return ['success' => true];
    }

    /**
     * List saved versions of a funnel.
     *
     * @param mixed $input Input with 'slug'.
     * @return array Versions list.
     */
    public static function listVersions($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = $input['slug'] ?? '';
        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel '$slug' not found"];
        }

        if (!class_exists('\HP_RW\Services\FunnelVersionControl')) {
            return ['success' => false, 'error' => 'Version control service not available'];
        }

        $versions = \HP_RW\Services\FunnelVersionControl::listVersions($postId);
        
        return [
            'success' => true,
            'versions' => $versions,
        ];
    }

    /**
     * Create a version backup.
     *
     * @param mixed $input Array with 'slug' and 'description'.
     * @return array Result.
     */
    public static function createVersion($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = $input['slug'] ?? '';
        $description = $input['description'] ?? 'Manual backup';

        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel '$slug' not found"];
        }

        if (!class_exists('\HP_RW\Services\FunnelVersionControl')) {
            return ['success' => false, 'error' => 'Version control service not available'];
        }

        $versionId = \HP_RW\Services\FunnelVersionControl::createVersion($postId, $description, 'ai_agent');
        
        return [
            'success' => true,
            'version_id' => $versionId,
        ];
    }

    /**
     * Restore a funnel version.
     *
     * @param mixed $input Array with 'slug' and 'version_id'.
     * @return array Result.
     */
    public static function restoreVersion($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = $input['slug'] ?? '';
        $versionId = $input['version_id'] ?? '';

        if (empty($slug) || empty($versionId)) {
            return ['success' => false, 'error' => 'Slug and version_id are required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel '$slug' not found"];
        }

        if (!class_exists('\HP_RW\Services\FunnelVersionControl')) {
            return ['success' => false, 'error' => 'Version control service not available'];
        }

        $restored = \HP_RW\Services\FunnelVersionControl::restoreVersion($postId, $versionId);
        
        if (is_wp_error($restored)) {
            return ['success' => false, 'error' => $restored->get_error_message()];
        }

        return [
            'success' => true,
            'message' => 'Version restored successfully',
        ];
    }

    /**
     * Validate a funnel JSON object.
     *
     * @param mixed $input JSON data.
     * @return array Result.
     */
    public static function validateFunnel($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $valid = \HP_RW\Services\FunnelSchema::validate($input);
        
        return [
            'valid' => empty($valid['errors']),
            'errors' => $valid['errors'] ?? [],
        ];
    }

    /**
     * Run an SEO audit on a funnel.
     * 
     * @param mixed $input Input parameters:
     *                     - slug (string) Funnel slug
     *                     - data (array) Optional: Fresh funnel data to audit before saving
     * @return array Audit results.
     */
    public static function seoAudit($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = $input['slug'] ?? '';
        $data = $input['data'] ?? [];

        if (empty($slug) && empty($data)) {
            return [
                'success' => false,
                'error' => 'Either slug or data must be provided for audit.',
            ];
        }

        if (!empty($data)) {
            $report = \HP_RW\Services\FunnelSeoAuditor::audit($data);
        } else {
            $postId = self::findFunnelBySlug($slug);
            if (!$postId) {
                return [
                    'success' => false,
                    'error' => "Funnel with slug '$slug' not found.",
                ];
            }
            $report = \HP_RW\Services\FunnelSeoAuditor::audit($postId);
        }

        return [
            'success' => true,
            'data' => $report,
        ];
    }

    /**
     * Apply a set of SEO fixes to a funnel.
     * 
     * Accepts a map of fields to update, creates a backup version,
     * updates the meta, and clears the cache.
     * 
     * @param mixed $input Input parameters:
     *                     - slug (string) Funnel slug
     *                     - fixes (array) Map of field names to values (e.g. ['focus_keyword' => 'Liver Detox'])
     * @return array Result of the operation.
     */
    public static function applySeoFixes($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['slug'] ?? '');
        $fixes = $input['fixes'] ?? [];

        if (empty($slug)) {
            return ['success' => false, 'error' => 'slug is required'];
        }
        if (empty($fixes)) {
            return ['success' => false, 'error' => 'fixes array is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
        }

        // 1. Create backup version
        if (class_exists('\HP_RW\Services\FunnelVersionControl')) {
            \HP_RW\Services\FunnelVersionControl::createVersion(
                $postId,
                'Auto-backup before bulk SEO fix',
                'ai_agent'
            );
        }

        $updated = [];

        // Map SEO fields to ACF paths
        $seoFieldMap = [
            'focus_keyword' => 'seo_focus_keyword',
            'meta_title' => 'seo_meta_title',
            'meta_description' => 'seo_meta_description',
            'hero_image_alt' => 'hero_image_alt',
            'authority_image_alt' => 'authority_image_alt',
            'authority_bio' => 'authority_bio',
        ];

        foreach ($fixes as $key => $value) {
            $acfKey = $seoFieldMap[$key] ?? $key;
            
            // Special handling for HTML fields
            if ($key === 'authority_bio') {
                update_post_meta($postId, $acfKey, wp_kses_post($value));
                $updated[] = $key;
                continue;
            }

            // Standard text fields
            update_post_meta($postId, $acfKey, sanitize_text_field($value));
            $updated[] = $key;
        }

        // 2. Clear funnel cache
        if (class_exists('\HP_RW\Services\FunnelConfigLoader')) {
            \HP_RW\Services\FunnelConfigLoader::clearCache($postId);
        }

        return [
            'success' => true,
            'updated_fields' => $updated,
        ];
    }

    /**
     * Search WooCommerce products with filters.
     *
     * @param mixed $input Search parameters.
     * @return array Search results.
     */
    public static function searchProducts($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $category = sanitize_text_field($input['category'] ?? '');
        $search = sanitize_text_field($input['search'] ?? '');
        $limit = (int)($input['limit'] ?? 20);

        if (!class_exists('\HP_RW\Services\ProductCatalogService')) {
            return ['success' => false, 'error' => 'Product catalog service not available'];
        }

        $products = \HP_RW\Services\ProductCatalogService::search($search, $category, $limit);

        return [
            'success' => true,
            'count' => count($products),
            'products' => $products,
        ];
    }

    /**
     * Get product details by SKU.
     *
     * @param mixed $input Input with 'sku'.
     * @return array Product data.
     */
    public static function getProduct($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $sku = sanitize_text_field($input['sku'] ?? '');
        if (empty($sku)) {
            return ['success' => false, 'error' => 'SKU is required'];
        }

        if (!class_exists('\HP_RW\Services\ProductCatalogService')) {
            return ['success' => false, 'error' => 'Product catalog service not available'];
        }

        $product = \HP_RW\Services\ProductCatalogService::getBySku($sku);
        if (!$product) {
            return ['success' => false, 'error' => "Product with SKU '$sku' not found"];
        }

        return [
            'success' => true,
            'product' => $product,
        ];
    }

    /**
     * Calculate supply for a product.
     *
     * @param mixed $input Array with 'sku', 'days', 'servings_per_day'.
     * @return array Supply calculation.
     */
    public static function calculateSupply($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $sku = sanitize_text_field($input['sku'] ?? '');
        $days = (int)($input['days'] ?? 30);
        $servingsPerDay = (int)($input['servings_per_day'] ?? 1);

        if (empty($sku)) {
            return ['success' => false, 'error' => 'SKU is required'];
        }

        if (!class_exists('\HP_RW\Services\ProductCatalogService')) {
            return ['success' => false, 'error' => 'Product catalog service not available'];
        }

        $supply = \HP_RW\Services\ProductCatalogService::calculateSupply($sku, $days, $servingsPerDay);

        return [
            'success' => true,
            'calculation' => $supply,
        ];
    }

    /**
     * Build a product kit from protocol.
     *
     * @param mixed $input Array with 'supplements' and 'duration_days'.
     * @return array Built kit.
     */
    public static function buildKit($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $supplements = $input['supplements'] ?? [];
        $durationDays = (int)($input['duration_days'] ?? 30);

        if (empty($supplements)) {
            return ['success' => false, 'error' => 'Supplements array is required'];
        }

        if (!class_exists('\HP_RW\Services\ProtocolKitBuilder')) {
            return ['success' => false, 'error' => 'Protocol kit builder service not available'];
        }

        $kit = \HP_RW\Services\ProtocolKitBuilder::buildFromProtocol($supplements, $durationDays);

        return [
            'success' => true,
            'kit' => $kit,
        ];
    }

    /**
     * Calculate economics for an offer.
     *
     * @param mixed $input Array with 'items', 'price', 'shipping_scenario'.
     * @return array Economics results.
     */
    public static function calculateEconomics($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $items = $input['items'] ?? [];
        $price = (float)($input['price'] ?? 0);
        $shippingScenario = sanitize_text_field($input['shipping_scenario'] ?? 'domestic');

        if (empty($items)) {
            return ['success' => false, 'error' => 'Items array is required'];
        }

        if (!class_exists('\HP_RW\Services\EconomicsService')) {
            return ['success' => false, 'error' => 'Economics service not available'];
        }

        $economics = \HP_RW\Services\EconomicsService::calculateOfferProfitability($items, $price, $shippingScenario);

        return [
            'success' => true,
            'economics' => $economics,
        ];
    }

    /**
     * Validate economics against guidelines.
     *
     * @param mixed $input Same as calculateEconomics.
     * @return array Validation result.
     */
    public static function validateEconomics($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $items = $input['items'] ?? [];
        $price = (float)($input['price'] ?? 0);
        $shippingScenario = sanitize_text_field($input['shipping_scenario'] ?? 'domestic');

        if (empty($items)) {
            return ['success' => false, 'error' => 'Items array is required'];
        }

        if (!class_exists('\HP_RW\Services\EconomicsService')) {
            return ['success' => false, 'error' => 'Economics service not available'];
        }

        $validation = \HP_RW\Services\EconomicsService::validateOffer($items, $price, $shippingScenario);

        return [
            'success' => true,
            'validation' => $validation,
        ];
    }

    /**
     * Get or set economic guidelines.
     *
     * @param mixed $input Optional 'settings' to update.
     * @return array Current guidelines.
     */
    public static function economicGuidelines($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $settings = $input['settings'] ?? null;

        if (!class_exists('\HP_RW\Services\EconomicsService')) {
            return ['success' => false, 'error' => 'Economics service not available'];
        }

        if ($settings) {
            \HP_RW\Services\EconomicsService::updateGuidelines($settings);
        }

        return [
            'success' => true,
            'guidelines' => \HP_RW\Services\EconomicsService::getGuidelines(),
        ];
    }

    /**
     * Get SEO schema for a funnel.
     *
     * @param mixed $input Array with 'funnel_slug'.
     * @return array Result.
     */
    public static function getSeoSchema($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['funnel_slug'] ?? '');
        if (empty($slug)) {
            return ['success' => false, 'error' => 'funnel_slug is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel '$slug' not found"];
        }

        if (!class_exists('\HP_RW\Services\FunnelSeoService')) {
            return ['success' => false, 'error' => 'SEO service not available'];
        }

        $schema = \HP_RW\Services\FunnelSeoService::getProductSchema($postId);
        
        return [
            'success' => true,
            'schema' => $schema,
        ];
    }

    /**
     * Get price range for a funnel.
     *
     * @param mixed $input Array with 'funnel_slug'.
     * @return array Result.
     */
    public static function getPriceRange($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['funnel_slug'] ?? '');
        if (empty($slug)) {
            return ['success' => false, 'error' => 'funnel_slug is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel '$slug' not found"];
        }

        $minPrice = (float)get_post_meta($postId, '_hp_funnel_min_price', true);
        $maxPrice = (float)get_post_meta($postId, '_hp_funnel_max_price', true);
        $currency = get_woocommerce_currency();

        return [
            'success' => true,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'currency' => $currency,
        ];
    }

    /**
     * Get canonical status for funnels.
     *
     * @param mixed $input Optional params.
     * @return array Result.
     */
    public static function getCanonicalStatus($input): array
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
     * @param string $slug Funnel slug.
     * @return int|null Post ID or null.
     */
    private static function findFunnelBySlug(string $slug): ?int
    {
        // First try by ACF field
        $posts = get_posts([
            'post_type' => 'hp-funnel',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'funnel_slug',
                    'value' => $slug,
                ],
            ],
        ]);

        if (!empty($posts)) {
            return $posts[0]->ID;
        }

        // Try by post_name
        $posts = get_posts([
            'post_type' => 'hp-funnel',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'name' => $slug,
        ]);

        return !empty($posts) ? $posts[0]->ID : null;
    }

    /**
     * Deeply convert objects to arrays.
     * 
     * @param mixed $value Value to convert
     * @return mixed Converted value
     */
    private static function ensureArrayRecursive($value)
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::ensureArrayRecursive($v);
            }
        }
        return $value;
    }
}