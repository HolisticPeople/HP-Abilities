<?php
/**
 * FORCED UPDATE: v0.5.38
 */
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
        return class_exists('\HP_RW\Services\FunnelConfigLoader');
    }

    /**
     * Helper for HP-React-Widgets missing.
     *
     * @return array
     */
    private static function hp_rw_not_available(): array
    {
        return [
            'success' => false,
            'error'   => 'HP-React-Widgets plugin is not active.',
        ];
    }

    /**
     * Ability: hp-funnels/explain-system
     */
    public static function explainSystem($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        return [
            'success' => true,
            'data'    => \HP_RW\Services\FunnelSystemExplainer::getSystemDocs(),
        ];
    }

    /**
     * Ability: hp-funnels/schema
     */
    public static function getSchema($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        return [
            'success' => true,
            'data'    => \HP_RW\Services\FunnelSchema::getSchema(),
        ];
    }

    /**
     * Ability: hp-funnels/styling-schema
     */
    public static function getStylingSchema($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        return [
            'success' => true,
            'data'    => \HP_RW\Services\FunnelSchema::getStylingSchema(),
        ];
    }

    /**
     * Ability: hp-funnels/list
     */
    public static function listFunnels($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $args = [
            'post_type'      => 'hp-funnel',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft', 'private'],
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ];
        $posts = get_posts($args);

        $funnels = array_map(function ($post) {
            return [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'slug'     => $post->post_name,
                'status'   => $post->post_status,
                'modified' => $post->post_modified,
                'url'      => get_permalink($post->ID),
            ];
        }, $posts);

        return [
            'success' => true,
            'count'   => count($funnels),
            'funnels' => $funnels,
        ];
    }

    /**
     * Ability: hp-funnels/get
     */
    public static function getFunnel($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['slug'] ?? '');
        if (empty($slug)) {
            return ['success' => false, 'error' => 'slug is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
        }

        $funnel = \HP_RW\Services\FunnelConfigLoader::getById($postId);
        $funnel = self::ensureArrayRecursive($funnel);

        return [
            'success' => true,
            'funnel'  => $funnel,
        ];
    }

    /**
     * Ability: hp-funnels/create
     */
    public static function createFunnel($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $result = \HP_RW\Services\FunnelImporter::importFunnel($input, 'create_new');
        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'Failed to create funnel'];
        }

        return [
            'success' => true,
            'post_id' => $result['post_id'],
            'slug'    => $result['slug'],
        ];
    }

    /**
     * Ability: hp-funnels/update
     */
    public static function updateFunnel($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['slug'] ?? '');
        $data = $input['data'] ?? [];

        if (empty($slug)) {
            return ['success' => false, 'error' => 'slug is required'];
        }
        if (empty($data)) {
            return ['success' => false, 'error' => 'data is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
        }

        $result = \HP_RW\Services\FunnelConfigLoader::updateFunnel($slug, $data);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'post_id' => $postId,
        ];
    }

    /**
     * Ability: hp-funnels/update-sections
     */
    public static function updateSections($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['slug'] ?? '');
        $sections = $input['sections'] ?? [];

        if (empty($slug)) {
            return ['success' => false, 'error' => 'slug is required'];
        }
        if (empty($sections)) {
            return ['success' => false, 'error' => 'sections is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
        }

        $result = \HP_RW\Services\FunnelConfigLoader::updateSections($slug, $sections);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'updated_sections' => $result['updated_sections'] ?? array_keys($sections),
        ];
    }

    /**
     * Ability: hp-funnels/versions-list
     */
    public static function listVersions($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\FunnelVersionControl')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['slug'] ?? '');
        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
        }

        return [
            'success'  => true,
            'versions' => \HP_RW\Services\FunnelVersionControl::listVersions($postId),
        ];
    }

    /**
     * Ability: hp-funnels/versions-create
     */
    public static function createVersion($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\FunnelVersionControl')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['slug'] ?? '');
        $description = sanitize_text_field($input['description'] ?? 'Manual backup');

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
        }

        $versionId = \HP_RW\Services\FunnelVersionControl::createVersion($postId, $description, 'ai_agent');
        
        return [
            'success'    => true,
            'version_id' => $versionId,
        ];
    }

    /**
     * Ability: hp-funnels/versions-restore
     */
    public static function restoreVersion($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\FunnelVersionControl')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['slug'] ?? '');
        $versionId = sanitize_text_field($input['version_id'] ?? '');

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
        }

        $result = \HP_RW\Services\FunnelVersionControl::restoreVersion($postId, $versionId);
        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'message' => 'Version restored successfully.',
        ];
    }

    /**
     * Ability: hp-funnels/validate
     */
    public static function validateFunnel($input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $result = \HP_RW\Services\FunnelSchema::validate($input);
        return [
            'valid'  => empty($result),
            'errors' => $result,
        ];
    }

    /**
     * Ability: hp-funnels/seo-audit
     */
    public static function seoAudit($input): array
    {
        error_log('[HP-Abilities] seoAudit called');
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        try {
            $input = self::ensureArrayRecursive($input);
            $slug = sanitize_text_field($input['slug'] ?? '');
            $data = $input['data'] ?? null;

            if (empty($slug) && empty($data)) {
                return ['success' => false, 'error' => 'Either slug or data must be provided for audit.'];
            }

            if ($slug && empty($data)) {
                $postId = self::findFunnelBySlug($slug);
                if (!$postId) {
                    return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
                }
                $data = \HP_RW\Services\FunnelConfigLoader::getById($postId);
            }

            // Ensure data is a plain array
            $data = self::ensureArrayRecursive($data);

            if (class_exists('\HP_RW\Services\FunnelSeoAuditor')) {
                $audit = \HP_RW\Services\FunnelSeoAuditor::audit($data);
                return [
                    'success' => true,
                    'data'    => $audit,
                ];
            }

            if (class_exists('\HP_RW\Services\FunnelSeoService')) {
                $audit = \HP_RW\Services\FunnelSeoService::runAudit($data);
                return [
                    'success' => true,
                    'data'    => $audit,
                ];
            }

            return ['success' => false, 'error' => 'FunnelSeoAuditor/FunnelSeoService not found.'];
        } catch (\Throwable $e) {
            error_log('[HP-Abilities] seoAudit error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'seoAudit failed: ' . $e->getMessage()];
        }
    }

    /**
     * Ability: hp-funnels/seo-fix
     */
    public static function applySeoFixes($input): array
    {
        error_log('[HP-Abilities] applySeoFixes called with: ' . json_encode($input));
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['slug'] ?? '');
        
        // Everything except 'slug' is a fix
        $fixes = $input;
        unset($fixes['slug']);

        if (empty($slug)) {
            return ['success' => false, 'error' => 'slug is required'];
        }
        if (empty($fixes)) {
            return ['success' => false, 'error' => 'No SEO fixes provided'];
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
            'data' => [
                'updated_fields' => $updated,
            ],
        ];
    }

    /**
     * Ability: hp-products/search
     */
    public static function searchProducts($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\ProductCatalogService')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $category = sanitize_text_field($input['category'] ?? '');
        $search = sanitize_text_field($input['search'] ?? '');
        $limit = (int) ($input['limit'] ?? 20);

        $products = \HP_RW\Services\ProductCatalogService::searchProducts([
            'category' => $category,
            'search'   => $search,
            'limit'    => $limit,
        ]);

        return [
            'success'  => true,
            'count'    => count($products),
            'products' => $products,
        ];
    }

    /**
     * Ability: hp-products/get
     */
    public static function getProduct($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\ProductCatalogService')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $sku = sanitize_text_field($input['sku'] ?? '');
        if (empty($sku)) {
            return ['success' => false, 'error' => 'sku is required'];
        }

        $product = \HP_RW\Services\ProductCatalogService::getProductBySku($sku);
        if (!$product) {
            return ['success' => false, 'error' => "Product with SKU '$sku' not found."];
        }

        return [
            'success' => true,
            'product' => $product,
        ];
    }

    /**
     * Ability: hp-products/calculate-supply
     */
    public static function calculateSupply($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\ProductCatalogService')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $sku = sanitize_text_field($input['sku'] ?? '');
        $days = (int) ($input['days'] ?? 0);
        $servings_per_day = (int) ($input['servings_per_day'] ?? 1);

        if (empty($sku) || $days <= 0) {
            return ['success' => false, 'error' => 'sku and days are required'];
        }

        return \HP_RW\Services\ProductCatalogService::calculateSupply($sku, $days, $servings_per_day);
    }

    /**
     * Ability: hp-protocols/build-kit
     */
    public static function buildKit($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\ProtocolKitBuilder')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $supplements = $input['supplements'] ?? [];
        $duration_days = (int) ($input['duration_days'] ?? 0);

        if (empty($supplements) || $duration_days <= 0) {
            return ['success' => false, 'error' => 'supplements and duration_days are required'];
        }

        return \HP_RW\Services\ProtocolKitBuilder::buildKitFromProtocol($supplements, $duration_days);
    }

    /**
     * Ability: hp-economics/calculate
     */
    public static function calculateEconomics($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\EconomicsService')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        return \HP_RW\Services\EconomicsService::calculateProfitability($input);
    }

    /**
     * Ability: hp-economics/validate
     */
    public static function validateEconomics($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\EconomicsService')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        return \HP_RW\Services\EconomicsService::validateOffer($input);
    }

    /**
     * Ability: hp-economics/guidelines
     */
    public static function economicGuidelines($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\EconomicsService')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        if (!empty($input['settings'])) {
            \HP_RW\Services\EconomicsService::saveGuidelines($input['settings']);
        }

        return [
            'success'    => true,
            'guidelines' => \HP_RW\Services\EconomicsService::getGuidelines(),
        ];
    }

    /**
     * Ability: hp-seo/funnel-schema
     */
    public static function getSeoSchema($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\FunnelSeoService')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['funnel_slug'] ?? '');
        if (empty($slug)) {
            return ['success' => false, 'error' => 'funnel_slug is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
        }

        $schema = \HP_RW\Services\FunnelSeoService::getProductSchema($postId);
        
        return [
            'success'     => true,
            'funnel_slug' => $slug,
            'schema'      => $schema,
            'schema_json' => json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Ability: hp-economics/price-range
     */
    public static function getPriceRange($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\EconomicsService')) {
            return self::hp_rw_not_available();
        }

        $input = self::ensureArrayRecursive($input);
        $slug = sanitize_text_field($input['funnel_slug'] ?? '');
        if (empty($slug)) {
            return ['success' => false, 'error' => 'funnel_slug is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => "Funnel with slug '$slug' not found."];
        }

        $range = \HP_RW\Services\EconomicsService::getFunnelPriceRange($postId);
        
        return [
            'success'      => true,
            'funnel_slug'  => $slug,
            'price_range'  => $range,
            'brand'        => 'Holistic People',
            'availability' => 'https://schema.org/InStock',
        ];
    }

    /**
     * Ability: hp-seo/canonical-status
     */
    public static function getCanonicalStatus($input): array
    {
        if (!self::is_hp_rw_available() || !class_exists('\HP_RW\Services\FunnelSeoService')) {
            return self::hp_rw_not_available();
        }

        $overrides = \HP_RW\Services\FunnelSeoService::getCanonicalOverrides();
        
        return [
            'success'            => true,
            'product_overrides'  => count($overrides['products']),
            'category_overrides' => count($overrides['categories']),
            'data'               => $overrides,
        ];
    }

    /**
     * Find a funnel post ID by its slug.
     */
    private static function findFunnelBySlug(string $slug): ?int
    {
        $args = [
            'post_type'      => 'hp-funnel',
            'name'           => $slug,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => ['publish', 'draft', 'private'],
        ];
        $posts = get_posts($args);
        return !empty($posts) ? (int) $posts[0] : null;
    }

    /**
     * Deeply convert stdClass objects to associative arrays.
     */
    private static function ensureArrayRecursive($value)
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $val) {
                $value[$key] = self::ensureArrayRecursive($val);
            }
        }
        return $value;
    }
}
