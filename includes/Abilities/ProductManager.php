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
     * Run a GMC compliance audit on a product.
     */
    public static function gmcAudit(array $input): array
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

        $product = wc_get_product($id);
        if (!$product) {
            return [
                'success' => false,
                'error' => __('Product not found', 'hp-abilities')
            ];
        }

        $audit = \HP_Abilities\Utils\GMCValidator::audit($product);
        
        return [
            'success' => true,
            'data' => $audit
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

    /**
     * Create a new simple WooCommerce product.
     * Supports full field set including ACF and SEO meta.
     */
    public static function createProduct(array $input): array
    {
        // Validate required fields
        $name = isset($input['name']) ? sanitize_text_field($input['name']) : '';
        $sku = isset($input['sku']) ? sanitize_text_field($input['sku']) : '';
        $price = isset($input['price']) ? sanitize_text_field($input['price']) : '';

        if (empty($name) || empty($sku) || empty($price)) {
            return [
                'success' => false,
                'error' => __('Missing required fields: name, sku, and price are required', 'hp-abilities')
            ];
        }

        // Check if SKU already exists
        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            return [
                'success' => false,
                'error' => sprintf(__('SKU "%s" already exists (product ID: %d)', 'hp-abilities'), $sku, $existing_id)
            ];
        }

        try {
            // Create the product
            $product = new \WC_Product_Simple();
            
            // Core fields
            $product->set_name($name);
            $product->set_sku($sku);
            $product->set_regular_price($price);
            $product->set_status('publish');
            
            // Optional core fields
            if (!empty($input['description'])) {
                $product->set_description(wp_kses_post($input['description']));
            }
            if (!empty($input['short_description'])) {
                $product->set_short_description(wp_kses_post($input['short_description']));
            }
            if (isset($input['stock_quantity'])) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity((int) $input['stock_quantity']);
                $product->set_stock_status($input['stock_quantity'] > 0 ? 'instock' : 'outofstock');
            }
            if (!empty($input['weight'])) {
                $product->set_weight(sanitize_text_field($input['weight']));
            }
            
            // Dimensions
            if (!empty($input['dimensions']) && is_array($input['dimensions'])) {
                $dims = $input['dimensions'];
                if (!empty($dims['length'])) $product->set_length(sanitize_text_field($dims['length']));
                if (!empty($dims['width'])) $product->set_width(sanitize_text_field($dims['width']));
                if (!empty($dims['height'])) $product->set_height(sanitize_text_field($dims['height']));
            }

            // Save product to get ID
            $product_id = $product->save();

            if (!$product_id) {
                return [
                    'success' => false,
                    'error' => __('Failed to create product', 'hp-abilities')
                ];
            }

            // Categories (by slug)
            if (!empty($input['categories']) && is_array($input['categories'])) {
                $category_ids = [];
                foreach ($input['categories'] as $slug) {
                    $term = get_term_by('slug', sanitize_title($slug), 'product_cat');
                    if ($term) {
                        $category_ids[] = $term->term_id;
                    }
                }
                if (!empty($category_ids)) {
                    $product->set_category_ids($category_ids);
                    $product->save();
                }
            }

            // Tags (by name, create if not exists)
            if (!empty($input['tags']) && is_array($input['tags'])) {
                $tag_ids = [];
                foreach ($input['tags'] as $tag_name) {
                    $term = get_term_by('name', $tag_name, 'product_tag');
                    if (!$term) {
                        $result = wp_insert_term($tag_name, 'product_tag');
                        if (!is_wp_error($result)) {
                            $tag_ids[] = $result['term_id'];
                        }
                    } else {
                        $tag_ids[] = $term->term_id;
                    }
                }
                if (!empty($tag_ids)) {
                    $product->set_tag_ids($tag_ids);
                    $product->save();
                }
            }

            // Images (sideload from URLs)
            if (!empty($input['images']) && is_array($input['images'])) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                
                $image_ids = [];
                foreach ($input['images'] as $image_url) {
                    $image_url = esc_url_raw($image_url);
                    if (empty($image_url)) continue;
                    
                    $attachment_id = media_sideload_image($image_url, $product_id, $name, 'id');
                    if (!is_wp_error($attachment_id)) {
                        $image_ids[] = $attachment_id;
                    }
                }
                
                if (!empty($image_ids)) {
                    $product->set_image_id($image_ids[0]); // First image is featured
                    if (count($image_ids) > 1) {
                        $product->set_gallery_image_ids(array_slice($image_ids, 1));
                    }
                    $product->save();
                }
            }

            // ACF fields
            if (!empty($input['acf']) && is_array($input['acf']) && function_exists('update_field')) {
                foreach ($input['acf'] as $field_key => $field_value) {
                    update_field($field_key, $field_value, $product_id);
                }
            }

            // SEO meta (Yoast)
            if (!empty($input['seo']) && is_array($input['seo'])) {
                $seo = $input['seo'];
                if (!empty($seo['title'])) {
                    update_post_meta($product_id, '_yoast_wpseo_title', sanitize_text_field($seo['title']));
                }
                if (!empty($seo['description'])) {
                    update_post_meta($product_id, '_yoast_wpseo_metadesc', sanitize_text_field($seo['description']));
                }
            }

            // Refresh product data
            $product = wc_get_product($product_id);

            return [
                'success' => true,
                'data' => [
                    'id' => $product_id,
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name(),
                    'permalink' => $product->get_permalink(),
                    'status' => $product->get_status(),
                    'price' => $product->get_regular_price(),
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
