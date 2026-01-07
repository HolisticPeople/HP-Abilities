<?php
namespace HP_Abilities\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product Manager high-level AI tools.
 * Bridges between AI agents and the Products Manager plugin.
 */
class ProductManager
{
    /**
     * Comprehensive update of a product.
     * Uses the REST endpoint from the Product Manager plugin.
     */
    public static function updateComprehensive(array $input): array
    {
        $sku = isset($input['sku']) ? sanitize_text_field($input['sku']) : '';
        $id = $sku ? wc_get_product_id_by_sku($sku) : 0;
        
        if (!$id && isset($input['product_id'])) {
            $id = (int) $input['product_id'];
        }

        $changes = isset($input['changes']) ? (array) $input['changes'] : (isset($input['data']) ? (array)$input['data'] : []);

        if ($id <= 0) {
            return [
                'success' => false,
                'error' => __('Invalid product SKU or ID', 'hp-abilities')
            ];
        }

        // --- START GMC COMPLIANCE CHECK ---
        $product = wc_get_product($id);
        if ($product) {
            $audit = \HP_Abilities\Utils\GMCValidator::audit($product, $changes);
            if (!$audit['success']) {
                return [
                    'success' => false,
                    'error' => __('GMC Policy Violation: Product update blocked to prevent GMC disapproval.', 'hp-abilities'),
                    'audit' => $audit,
                    'suggestion' => __('Please fix the reported issues before updating.', 'hp-abilities')
                ];
            }
        }
        // --- END GMC COMPLIANCE CHECK ---

        // Call the Product Manager's REST logic directly
        if (!class_exists('\HP_Products_Manager')) {
            return [
                'success' => false,
                'error' => __('Products Manager plugin not found', 'hp-abilities')
            ];
        }

        // We simulate a REST request to reuse the robust validation and persistence logic
        $request = new \WP_REST_Request('POST', '/hp-products-manager/v1/product/' . $id . '/apply');
        $request->set_body(json_encode(['changes' => $changes]));
        $request->set_header('Content-Type', 'application/json');

        // Execute via internal call
        $response = rest_do_request($request);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
                'data' => $response->get_error_data()
            ];
        }

        $status = $response->get_status();
        $data = $response->get_data();

        if ($status >= 400) {
            return [
                'success' => false,
                'error' => isset($data['message']) ? $data['message'] : __('REST Error', 'hp-abilities'),
                'code' => isset($data['code']) ? $data['code'] : $status,
                'data' => $data
            ];
        }

        return [
            'success' => true,
            'data' => $data
        ];
    }

    /**
     * Perform an SEO audit on a product.
     */
    public static function seoAudit(array $input): array
    {
        $sku = isset($input['sku']) ? sanitize_text_field($input['sku']) : '';
        $id = $sku ? wc_get_product_id_by_sku($sku) : 0;

        if (!$id && isset($input['product_id'])) {
            $id = (int) $input['product_id'];
        }
        
        if ($id <= 0) {
            return [
                'success' => false,
                'error' => __('Invalid product SKU or ID', 'hp-abilities')
            ];
        }

        $request = new \WP_REST_Request('GET', '/hp-products-manager/v1/product/' . $id . '/seo-audit');
        $response = rest_do_request($request);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message()
            ];
        }

        $status = $response->get_status();
        $data = $response->get_data();

        if ($status >= 400) {
            return [
                'success' => false,
                'error' => isset($data['message']) ? $data['message'] : __('REST Error', 'hp-abilities'),
                'data' => $data
            ];
        }

        return [
            'success' => true,
            'data' => $data
        ];
    }
}
