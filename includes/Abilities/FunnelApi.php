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
     * @param array $input Input parameters (none required).
     * @return array System explanation.
     */
    public static function explainSystem(array $input): array
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
     * @param array $input Input parameters (none required).
     * @return array Schema with hints.
     */
    public static function getSchema(array $input): array
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
     * @param array $input Input parameters (none required).
     * @return array Styling schema.
     */
    public static function getStylingSchema(array $input): array
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
     * @param array $input Input parameters (none required).
     * @return array List of funnels.
     */
    public static function listFunnels(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

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
     * @param array $input Input with 'slug'.
     * @return array Complete funnel data.
     */
    public static function getFunnel(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $slug = sanitize_text_field($input['slug'] ?? '');
        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

        $funnel = \HP_RW\Services\FunnelConfigLoader::loadBySlug($slug);
        if (!$funnel) {
            return ['success' => false, 'error' => 'Funnel not found'];
        }

        return [
            'success' => true,
            'funnel' => $funnel,
        ];
    }

    /**
     * Create a new funnel.
     *
     * @param array $input Funnel data.
     * @return array Created funnel info.
     */
    public static function createFunnel(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        // Validate input schema
        $validation = \HP_RW\Services\FunnelSchema::validate($input);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => 'Schema validation failed',
                'errors' => $validation['errors'],
            ];
        }

        // Import the funnel using FunnelImporter
        $result = \HP_RW\Services\FunnelImporter::import($input);
        
        if (!$result['success']) {
            return $result;
        }

        // Log AI activity
        if (class_exists('\HP_RW\Admin\AiActivityLog')) {
            \HP_RW\Admin\AiActivityLog::logActivity(
                $result['post_id'],
                $input['funnel']['slug'] ?? '',
                'Created funnel via MCP ability',
                'Applied'
            );
        }

        return [
            'success' => true,
            'post_id' => $result['post_id'],
            'slug' => $input['funnel']['slug'] ?? '',
            'message' => 'Funnel created successfully',
        ];
    }

    /**
     * Update an existing funnel.
     *
     * @param array $input Funnel data with 'slug'.
     * @return array Updated funnel info.
     */
    public static function updateFunnel(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $slug = sanitize_text_field($input['slug'] ?? '');
        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

        // Find existing funnel
        $existing = self::findFunnelBySlug($slug);
        if (!$existing) {
            return ['success' => false, 'error' => 'Funnel not found'];
        }

        // Create backup before updating
        if (class_exists('\HP_RW\Services\FunnelVersionControl')) {
            \HP_RW\Services\FunnelVersionControl::createVersion(
                $existing,
                'Auto-backup before MCP update',
                'ai-agent'
            );
        }

        // Validate and import
        $validation = \HP_RW\Services\FunnelSchema::validate($input);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => 'Schema validation failed',
                'errors' => $validation['errors'],
            ];
        }

        $result = \HP_RW\Services\FunnelImporter::import($input, $existing);

        if ($result['success'] && class_exists('\HP_RW\Admin\AiActivityLog')) {
            \HP_RW\Admin\AiActivityLog::logActivity(
                $existing,
                $slug,
                'Updated funnel via MCP ability',
                'Applied'
            );
        }

        return $result;
    }

    /**
     * Update specific sections of a funnel.
     *
     * @param array $input Input with 'slug' and 'sections'.
     * @return array Update result.
     */
    public static function updateSections(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $slug = sanitize_text_field($input['slug'] ?? '');
        $sections = $input['sections'] ?? [];

        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required'];
        }
        if (empty($sections)) {
            return ['success' => false, 'error' => 'Sections data is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => 'Funnel not found'];
        }

        // Create backup
        if (class_exists('\HP_RW\Services\FunnelVersionControl')) {
            \HP_RW\Services\FunnelVersionControl::createVersion(
                $postId,
                'Auto-backup before section update',
                'ai-agent'
            );
        }

        $updated = [];
        foreach ($sections as $sectionName => $sectionData) {
            // Update ACF fields for this section
            $fieldName = $sectionName;
            if (function_exists('update_field')) {
                update_field($fieldName, $sectionData, $postId);
                $updated[] = $sectionName;
            }
        }

        if (class_exists('\HP_RW\Admin\AiActivityLog')) {
            \HP_RW\Admin\AiActivityLog::logActivity(
                $postId,
                $slug,
                'Updated sections: ' . implode(', ', $updated),
                'Applied'
            );
        }

        return [
            'success' => true,
            'post_id' => $postId,
            'updated_sections' => $updated,
        ];
    }

    /**
     * List versions of a funnel.
     *
     * @param array $input Input with 'slug'.
     * @return array Version list.
     */
    public static function listVersions(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $slug = sanitize_text_field($input['slug'] ?? '');
        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => 'Funnel not found'];
        }

        $versions = \HP_RW\Services\FunnelVersionControl::getVersions($postId);

        return [
            'success' => true,
            'slug' => $slug,
            'versions' => $versions,
        ];
    }

    /**
     * Create a version backup.
     *
     * @param array $input Input with 'slug' and optional 'description'.
     * @return array Created version info.
     */
    public static function createVersion(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $slug = sanitize_text_field($input['slug'] ?? '');
        $description = sanitize_text_field($input['description'] ?? 'Manual backup via MCP');

        if (empty($slug)) {
            return ['success' => false, 'error' => 'Slug is required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => 'Funnel not found'];
        }

        $versionId = \HP_RW\Services\FunnelVersionControl::createVersion(
            $postId,
            $description,
            'ai-agent'
        );

        return [
            'success' => true,
            'version_id' => $versionId,
            'slug' => $slug,
        ];
    }

    /**
     * Restore a version.
     *
     * @param array $input Input with 'slug' and 'version_id'.
     * @return array Restore result.
     */
    public static function restoreVersion(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $slug = sanitize_text_field($input['slug'] ?? '');
        $versionId = sanitize_text_field($input['version_id'] ?? '');

        if (empty($slug) || empty($versionId)) {
            return ['success' => false, 'error' => 'Slug and version_id are required'];
        }

        $postId = self::findFunnelBySlug($slug);
        if (!$postId) {
            return ['success' => false, 'error' => 'Funnel not found'];
        }

        $result = \HP_RW\Services\FunnelVersionControl::restoreVersion($postId, $versionId, true);

        if ($result && class_exists('\HP_RW\Admin\AiActivityLog')) {
            \HP_RW\Admin\AiActivityLog::logActivity(
                $postId,
                $slug,
                'Restored version ' . $versionId,
                'Applied'
            );
        }

        return [
            'success' => $result,
            'message' => $result ? 'Version restored successfully' : 'Failed to restore version',
        ];
    }

    /**
     * Search products.
     *
     * @param array $input Search filters.
     * @return array Product list.
     */
    public static function searchProducts(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $products = \HP_RW\Services\ProductCatalogService::searchProducts($input);

        return [
            'success' => true,
            'count' => count($products),
            'products' => $products,
        ];
    }

    /**
     * Get product details by SKU.
     *
     * @param array $input Input with 'sku'.
     * @return array Product details.
     */
    public static function getProduct(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $sku = sanitize_text_field($input['sku'] ?? '');
        if (empty($sku)) {
            return ['success' => false, 'error' => 'SKU is required'];
        }

        $product = \HP_RW\Services\ProductCatalogService::getProductDetails($sku);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found'];
        }

        return [
            'success' => true,
            'product' => $product,
        ];
    }

    /**
     * Calculate supply for a protocol.
     *
     * @param array $input Input with 'sku', 'days', and optional 'servings_per_day'.
     * @return array Supply calculation.
     */
    public static function calculateSupply(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $sku = sanitize_text_field($input['sku'] ?? '');
        $days = absint($input['days'] ?? 30);
        $servingsPerDay = absint($input['servings_per_day'] ?? 1);

        if (empty($sku)) {
            return ['success' => false, 'error' => 'SKU is required'];
        }

        $result = \HP_RW\Services\ProductCatalogService::calculateSupply($sku, $days, $servingsPerDay);

        return array_merge(['success' => true], $result);
    }

    /**
     * Build a product kit from a protocol.
     *
     * @param array $input Protocol definition.
     * @return array Kit recommendation.
     */
    public static function buildKit(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $result = \HP_RW\Services\ProtocolKitBuilder::buildKitFromProtocol($input);

        return array_merge(['success' => true], $result);
    }

    /**
     * Calculate offer profitability.
     *
     * @param array $input Offer details.
     * @return array Profitability analysis.
     */
    public static function calculateEconomics(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $items = $input['items'] ?? [];
        $price = floatval($input['price'] ?? 0);
        $shippingScenario = sanitize_text_field($input['shipping_scenario'] ?? 'domestic');

        $result = \HP_RW\Services\EconomicsService::calculateOfferProfitability(
            $items,
            $price,
            $shippingScenario
        );

        return array_merge(['success' => true], $result);
    }

    /**
     * Validate offer against economic guidelines.
     *
     * @param array $input Offer to validate.
     * @return array Validation result.
     */
    public static function validateOffer(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        $shippingScenario = sanitize_text_field($input['shipping_scenario'] ?? 'domestic');
        $result = \HP_RW\Services\EconomicsService::validateOffer($input, $shippingScenario);

        return array_merge(['success' => true], $result);
    }

    /**
     * Get or set economic guidelines.
     *
     * @param array $input Optional new guidelines to set.
     * @return array Current guidelines.
     */
    public static function economicGuidelines(array $input): array
    {
        if (!self::is_hp_rw_available()) {
            return self::hp_rw_not_available();
        }

        // If settings provided, update them
        if (!empty($input['settings'])) {
            \HP_RW\Services\EconomicsService::updateGuidelines($input['settings']);
        }

        return [
            'success' => true,
            'guidelines' => \HP_RW\Services\EconomicsService::getGuidelines(),
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
}

