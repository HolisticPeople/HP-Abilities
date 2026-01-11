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

            // Supplement ACF fields (common HP product fields)
            if (function_exists('update_field')) {
                // Serving info
                if (isset($input['serving_size'])) {
                    update_field('serving_size', (int) $input['serving_size'], $product_id);
                }
                if (isset($input['servings_per_container'])) {
                    update_field('servings_per_container', (int) $input['servings_per_container'], $product_id);
                }
                if (!empty($input['serving_form_unit'])) {
                    update_field('serving_form_unit', sanitize_text_field($input['serving_form_unit']), $product_id);
                }
                // Ingredients
                if (!empty($input['ingredients'])) {
                    update_field('ingredients', sanitize_textarea_field($input['ingredients']), $product_id);
                }
                if (!empty($input['ingredients_other'])) {
                    update_field('ingredients_other', sanitize_text_field($input['ingredients_other']), $product_id);
                }
                // Potency
                if (!empty($input['potency'])) {
                    update_field('potency', sanitize_text_field($input['potency']), $product_id);
                }
                if (!empty($input['potency_units'])) {
                    update_field('potency_units', sanitize_text_field($input['potency_units']), $product_id);
                }
                // Manufacturer
                if (!empty($input['manufacturer_acf'])) {
                    update_field('manufacturer_acf', sanitize_text_field($input['manufacturer_acf']), $product_id);
                }
                if (!empty($input['country_of_manufacturer'])) {
                    update_field('country_of_manufacturer', sanitize_text_field($input['country_of_manufacturer']), $product_id);
                }
                
                // Additional ACF fields (generic)
                if (!empty($input['acf']) && is_array($input['acf'])) {
                    foreach ($input['acf'] as $field_key => $field_value) {
                        update_field($field_key, $field_value, $product_id);
                    }
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
                if (!empty($seo['focus_keyword'])) {
                    update_post_meta($product_id, '_yoast_wpseo_focuskw', sanitize_text_field($seo['focus_keyword']));
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

    /**
     * Retire a product and create a redirect to the replacement.
     * Uses Yoast Premium's redirect manager.
     */
    public static function retireWithRedirect(array $input): array
    {
        $old_sku = isset($input['old_sku']) ? sanitize_text_field($input['old_sku']) : '';
        $new_sku = isset($input['new_sku']) ? sanitize_text_field($input['new_sku']) : '';
        $redirect_type = isset($input['redirect_type']) ? (int) $input['redirect_type'] : 301;
        $set_private = isset($input['set_private']) ? (bool) $input['set_private'] : true;

        if (empty($old_sku) || empty($new_sku)) {
            return [
                'success' => false,
                'error' => __('Both old_sku and new_sku are required', 'hp-abilities')
            ];
        }

        // Get old product
        $old_product_id = wc_get_product_id_by_sku($old_sku);
        if (!$old_product_id) {
            return [
                'success' => false,
                'error' => sprintf(__('Old product with SKU "%s" not found', 'hp-abilities'), $old_sku)
            ];
        }

        // Get new product
        $new_product_id = wc_get_product_id_by_sku($new_sku);
        if (!$new_product_id) {
            return [
                'success' => false,
                'error' => sprintf(__('New product with SKU "%s" not found', 'hp-abilities'), $new_sku)
            ];
        }

        $old_product = wc_get_product($old_product_id);
        $new_product = wc_get_product($new_product_id);

        // Get permalinks (relative paths for Yoast - without leading slashes)
        $old_url = ltrim(wp_make_link_relative(get_permalink($old_product_id)), '/');
        $new_url = ltrim(wp_make_link_relative(get_permalink($new_product_id)), '/');
        
        // Remove trailing slashes for consistency
        $old_url = rtrim($old_url, '/');
        $new_url = rtrim($new_url, '/');

        // Validate redirect type
        $valid_types = [301, 302, 307, 410];
        if (!in_array($redirect_type, $valid_types)) {
            $redirect_type = 301;
        }

        $redirect_created = false;
        $redirect_method = 'none';

        // Yoast Premium stores plain redirects in wpseo-premium-redirects-export-plain
        $redirects = get_option('wpseo-premium-redirects-export-plain', []);
        
        // Check if redirect already exists
        if (isset($redirects[$old_url])) {
            return [
                'success' => false,
                'error' => sprintf(__('Redirect already exists for "%s"', 'hp-abilities'), $old_url),
                'existing_redirect' => $redirects[$old_url]
            ];
        }

        // Add the redirect (Yoast format: just url and type)
        $redirects[$old_url] = [
            'url'  => $new_url,
            'type' => $redirect_type,
        ];

        $updated = update_option('wpseo-premium-redirects-export-plain', $redirects);
        
        if ($updated) {
            $redirect_created = true;
            $redirect_method = 'yoast_premium';
        }

        // Optionally set old product to private
        $old_status_changed = false;
        if ($set_private && $old_product) {
            $old_product->set_status('private');
            $old_product->set_stock_status('outofstock');
            $old_product->save();
            $old_status_changed = true;
        }

        return [
            'success' => true,
            'data' => [
                'old_product' => [
                    'id'        => $old_product_id,
                    'sku'       => $old_sku,
                    'name'      => $old_product->get_name(),
                    'old_url'   => $old_url,
                    'new_status'=> $old_status_changed ? 'private' : $old_product->get_status(),
                ],
                'new_product' => [
                    'id'        => $new_product_id,
                    'sku'       => $new_sku,
                    'name'      => $new_product->get_name(),
                    'new_url'   => $new_url,
                ],
                'redirect' => [
                    'created'       => $redirect_created,
                    'method'        => $redirect_method,
                    'type'          => $redirect_type,
                    'from'          => $old_url,
                    'to'            => $new_url,
                ],
            ],
        ];
    }

    // =========================================================================
    // ACF-POWERED METHODS (Full Field Access)
    // =========================================================================

    /**
     * Get ALL product fields (core, ACF, SEO, taxonomy).
     */
    public static function getFullProduct(array $input): array
    {
        $sku = isset($input['sku']) ? sanitize_text_field($input['sku']) : '';
        
        if (empty($sku)) {
            return ['success' => false, 'error' => __('SKU is required', 'hp-abilities')];
        }

        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return ['success' => false, 'error' => sprintf(__('Product with SKU "%s" not found', 'hp-abilities'), $sku)];
        }

        if (!\HP_Abilities\Adapters\ProductFieldsAdapter::isAvailable()) {
            return ['success' => false, 'error' => __('WooCommerce is required for this operation', 'hp-abilities')];
        }

        $fields = \HP_Abilities\Adapters\ProductFieldsAdapter::getProductFields($product_id);
        
        if (isset($fields['error'])) {
            return ['success' => false, 'error' => $fields['error']];
        }

        $product = wc_get_product($product_id);
        
        return [
            'success' => true,
            'data' => [
                'id' => $product_id,
                'sku' => $sku,
                'name' => $product->get_name(),
                'permalink' => $product->get_permalink(),
                'fields' => $fields,
            ],
        ];
    }

    /**
     * Compare two products field-by-field (core, ACF, SEO, taxonomy).
     */
    public static function compareProducts(array $input): array
    {
        $source_sku = isset($input['source_sku']) ? sanitize_text_field($input['source_sku']) : '';
        $target_sku = isset($input['target_sku']) ? sanitize_text_field($input['target_sku']) : '';
        
        if (empty($source_sku) || empty($target_sku)) {
            return ['success' => false, 'error' => __('Both source_sku and target_sku are required', 'hp-abilities')];
        }

        $source_id = wc_get_product_id_by_sku($source_sku);
        $target_id = wc_get_product_id_by_sku($target_sku);
        
        if (!$source_id) {
            return ['success' => false, 'error' => sprintf(__('Source product with SKU "%s" not found', 'hp-abilities'), $source_sku)];
        }
        if (!$target_id) {
            return ['success' => false, 'error' => sprintf(__('Target product with SKU "%s" not found', 'hp-abilities'), $target_sku)];
        }

        if (!\HP_Abilities\Adapters\ProductFieldsAdapter::isAvailable()) {
            return ['success' => false, 'error' => __('WooCommerce is required for this operation', 'hp-abilities')];
        }

        $result = \HP_Abilities\Adapters\ProductFieldsAdapter::compareProducts($source_id, $target_id);
        
        if (!$result['success']) {
            return $result;
        }

        // Add SKU info to the result
        $result['source_sku'] = $source_sku;
        $result['target_sku'] = $target_sku;
        
        return $result;
    }

    /**
     * Clone product fields from source to target (core, ACF, SEO, taxonomy).
     */
    public static function cloneProduct(array $input): array
    {
        $source_sku = isset($input['source_sku']) ? sanitize_text_field($input['source_sku']) : '';
        $target_sku = isset($input['target_sku']) ? sanitize_text_field($input['target_sku']) : '';
        $overrides = isset($input['overrides']) && is_array($input['overrides']) ? $input['overrides'] : [];
        $exclude = isset($input['exclude']) && is_array($input['exclude']) ? $input['exclude'] : [];
        
        if (empty($source_sku) || empty($target_sku)) {
            return ['success' => false, 'error' => __('Both source_sku and target_sku are required', 'hp-abilities')];
        }

        $source_id = wc_get_product_id_by_sku($source_sku);
        $target_id = wc_get_product_id_by_sku($target_sku);
        
        if (!$source_id) {
            return ['success' => false, 'error' => sprintf(__('Source product with SKU "%s" not found', 'hp-abilities'), $source_sku)];
        }
        if (!$target_id) {
            return ['success' => false, 'error' => sprintf(__('Target product with SKU "%s" not found', 'hp-abilities'), $target_sku)];
        }

        if (!\HP_Abilities\Adapters\ProductFieldsAdapter::isAvailable()) {
            return ['success' => false, 'error' => __('WooCommerce is required for this operation', 'hp-abilities')];
        }

        $result = \HP_Abilities\Adapters\ProductFieldsAdapter::cloneProductFields($source_id, $target_id, $overrides, $exclude);
        
        // Add SKU info to the result
        $result['source_sku'] = $source_sku;
        $result['target_sku'] = $target_sku;
        
        return $result;
    }

    /**
     * Update product fields by human-readable name (core, ACF, SEO, taxonomy).
     */
    public static function updateFields(array $input): array
    {
        $sku = isset($input['sku']) ? sanitize_text_field($input['sku']) : '';
        $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
        
        if (empty($sku)) {
            return ['success' => false, 'error' => __('SKU is required', 'hp-abilities')];
        }
        if (empty($fields)) {
            return ['success' => false, 'error' => __('Fields object is required', 'hp-abilities')];
        }

        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return ['success' => false, 'error' => sprintf(__('Product with SKU "%s" not found', 'hp-abilities'), $sku)];
        }

        if (!\HP_Abilities\Adapters\ProductFieldsAdapter::isAvailable()) {
            return ['success' => false, 'error' => __('WooCommerce is required for this operation', 'hp-abilities')];
        }

        $result = \HP_Abilities\Adapters\ProductFieldsAdapter::setProductFields($product_id, $fields);
        
        // Add context to the result
        $result['sku'] = $sku;
        $result['product_id'] = $product_id;
        
        return $result;
    }

    /**
     * Get list of available product fields (core, ACF, SEO, taxonomy).
     */
    public static function getAvailableFields(array $input): array
    {
        if (!\HP_Abilities\Adapters\ProductFieldsAdapter::isAvailable()) {
            return ['success' => false, 'error' => __('WooCommerce is required for this operation', 'hp-abilities')];
        }

        $fields = \HP_Abilities\Adapters\ProductFieldsAdapter::getAvailableFields('product');
        
        // Group fields by type
        $grouped = [
            'core' => [],
            'acf' => [],
            'other' => [],
        ];
        
        foreach ($fields as $key => $info) {
            if ($info['is_acf']) {
                $grouped['acf'][$key] = $info;
            } elseif (in_array($key, ['ID', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_date', 'post_author', 'post_parent'])) {
                $grouped['core'][$key] = $info;
            } else {
                $grouped['other'][$key] = $info;
            }
        }
        
        return [
            'success' => true,
            'data' => [
                'total_fields' => count($fields),
                'core_count' => count($grouped['core']),
                'acf_count' => count($grouped['acf']),
                'other_count' => count($grouped['other']),
                'fields' => $grouped,
            ],
        ];
    }

    /**
     * Upload a file from base64 to the Media Library.
     */
    public static function uploadMedia(array $input): array
    {
        $file_content = $input['file_content'] ?? '';
        $file_name = sanitize_file_name($input['file_name'] ?? 'upload.png');
        $alt_text = sanitize_text_field($input['alt_text'] ?? '');
        $product_id = isset($input['product_id']) ? (int) $input['product_id'] : 0;
        $is_thumbnail = isset($input['is_thumbnail']) ? (bool) $input['is_thumbnail'] : false;

        if (empty($file_content)) {
            return ['success' => false, 'error' => __('File content is missing', 'hp-abilities')];
        }

        // Decode base64
        $decoded_data = base64_decode($file_content);
        if (!$decoded_data) {
            return ['success' => false, 'error' => __('Invalid base64 content', 'hp-abilities')];
        }

        // Upload bits
        $upload = wp_upload_bits($file_name, null, $decoded_data);
        if ($upload['error']) {
            return ['success' => false, 'error' => $upload['error']];
        }

        // Create attachment
        $file_path = $upload['file'];
        $file_url = $upload['url'];
        $file_type = wp_check_filetype($file_path, null);

        $attachment = [
            'guid'           => $file_url,
            'post_mime_type' => $file_type['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', $file_name),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attachment_id = wp_insert_attachment($attachment, $file_path, $product_id);

        if (is_wp_error($attachment_id)) {
            return ['success' => false, 'error' => $attachment_id->get_error_message()];
        }

        // Generate metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        // Set alt text
        if ($alt_text) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        }

        // Attach to product
        if ($product_id > 0) {
            if ($is_thumbnail) {
                set_post_thumbnail($product_id, $attachment_id);
            } else {
                // Add to gallery
                $gallery = get_post_meta($product_id, '_product_image_gallery', true);
                $gallery_ids = $gallery ? explode(',', $gallery) : [];
                $gallery_ids[] = $attachment_id;
                update_post_meta($product_id, '_product_image_gallery', implode(',', array_filter($gallery_ids)));
            }
        }

        return [
            'success'       => true,
            'attachment_id' => $attachment_id,
            'url'           => $file_url,
            'file_path'     => $file_path,
        ];
    }
}
