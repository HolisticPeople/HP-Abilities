<?php
namespace HP_Abilities\Adapters;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP Sheet Editor Adapter
 * 
 * Provides a clean interface to WP Sheet Editor's internal API
 * for reading/writing ALL product fields including ACF.
 */
class WPSEAdapter
{
    /**
     * Check if WP Sheet Editor is available
     */
    public static function isAvailable(): bool
    {
        return function_exists('VGSE') && class_exists('WP_Sheet_Editor_Dist');
    }

    /**
     * Get WPSE instance
     */
    private static function getVGSE()
    {
        if (!self::isAvailable()) {
            return null;
        }
        return VGSE();
    }

    /**
     * Get all registered columns for a provider (e.g., 'product')
     * 
     * @param string $provider Post type or 'user', 'term'
     * @return array Column definitions keyed by column name
     */
    public static function getColumnRegistry(string $provider = 'product'): array
    {
        $vgse = self::getVGSE();
        if (!$vgse || !isset($vgse->columns)) {
            return [];
        }

        $columns = $vgse->columns->get_provider_items($provider, true, true);
        return is_array($columns) ? $columns : [];
    }

    /**
     * Get all field values for a product
     * 
     * @param int $product_id WooCommerce product ID
     * @return array Associative array of field_name => value
     */
    public static function getProductFields(int $product_id): array
    {
        $vgse = self::getVGSE();
        if (!$vgse) {
            return ['error' => 'WP Sheet Editor not available'];
        }

        $columns = self::getColumnRegistry('product');
        if (empty($columns)) {
            return ['error' => 'No columns registered for products'];
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return ['error' => 'Product not found'];
        }

        // Create a mock post object for WPSE callbacks
        $post = get_post($product_id);
        
        $fields = [
            'core' => [],
            'acf' => [],
            'seo' => [],
            'taxonomy' => [],
            'meta' => [],
        ];

        foreach ($columns as $column_key => $column_settings) {
            $value = self::getFieldValue($product_id, $post, $column_key, $column_settings);
            
            // Categorize the field
            $data_type = $column_settings['data_type'] ?? 'meta_data';
            $is_acf = isset($column_settings['acf_field']);
            $is_seo = strpos($column_key, '_yoast_wpseo') === 0 || strpos($column_key, 'wpseo_') === 0;
            $is_taxonomy = $data_type === 'taxonomy';
            $is_core = in_array($column_key, ['ID', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_date', 'post_author', 'post_parent']);
            
            if ($is_core || in_array($column_key, ['_sku', '_regular_price', '_sale_price', '_stock', '_stock_status', '_weight', '_length', '_width', '_height'])) {
                $fields['core'][$column_key] = $value;
            } elseif ($is_acf) {
                $fields['acf'][$column_key] = $value;
            } elseif ($is_seo) {
                $fields['seo'][$column_key] = $value;
            } elseif ($is_taxonomy) {
                $fields['taxonomy'][$column_key] = $value;
            } else {
                $fields['meta'][$column_key] = $value;
            }
        }

        return $fields;
    }

    /**
     * Get a single field value using WPSE's callback system
     */
    private static function getFieldValue(int $product_id, $post, string $column_key, array $column_settings)
    {
        $vgse = self::getVGSE();
        
        // Use custom callback if defined
        if (!empty($column_settings['get_value_callback']) && is_callable($column_settings['get_value_callback'])) {
            return call_user_func($column_settings['get_value_callback'], $post, $column_key, $column_settings);
        }

        // Handle different data types
        $data_type = $column_settings['data_type'] ?? 'meta_data';
        
        switch ($data_type) {
            case 'post_data':
                return $vgse->data_helpers->get_post_data($column_key, $product_id);
                
            case 'meta_data':
                return get_post_meta($product_id, $column_key, true);
                
            case 'taxonomy':
                $taxonomy = $column_settings['taxonomy'] ?? '';
                if ($taxonomy) {
                    $terms = wp_get_object_terms($product_id, $taxonomy, ['fields' => 'names']);
                    return is_array($terms) ? implode(', ', $terms) : '';
                }
                return '';
                
            default:
                return get_post_meta($product_id, $column_key, true);
        }
    }

    /**
     * Set multiple field values for a product
     * 
     * @param int $product_id WooCommerce product ID
     * @param array $fields Associative array of field_name => value
     * @return array Result with success status and updated fields
     */
    public static function setProductFields(int $product_id, array $fields): array
    {
        $vgse = self::getVGSE();
        if (!$vgse) {
            return ['success' => false, 'error' => 'WP Sheet Editor not available'];
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found'];
        }

        $columns = self::getColumnRegistry('product');
        $updated = [];
        $errors = [];

        foreach ($fields as $field_name => $value) {
            $result = self::setFieldValue($product_id, $field_name, $value, $columns);
            if ($result['success']) {
                $updated[] = $field_name;
            } else {
                $errors[$field_name] = $result['error'];
            }
        }

        // Clear WC product cache
        wc_delete_product_transients($product_id);
        clean_post_cache($product_id);

        return [
            'success' => empty($errors),
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * Set a single field value
     */
    private static function setFieldValue(int $product_id, string $field_name, $value, array $columns): array
    {
        $vgse = self::getVGSE();
        
        // Find column settings
        $column_settings = $columns[$field_name] ?? null;
        
        // Use custom callback if defined
        if ($column_settings && !empty($column_settings['save_value_callback']) && is_callable($column_settings['save_value_callback'])) {
            try {
                call_user_func($column_settings['save_value_callback'], $product_id, $field_name, $value, 'product', $column_settings, $columns);
                return ['success' => true];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Handle different field types
        $data_type = $column_settings['data_type'] ?? 'meta_data';
        
        // Check if this is an ACF field
        $is_acf = $column_settings && isset($column_settings['acf_field']);
        
        if ($is_acf && function_exists('update_field')) {
            // Use ACF's update_field for proper handling
            $result = update_field($field_name, $value, $product_id);
            return ['success' => $result !== false];
        }

        switch ($data_type) {
            case 'post_data':
                // Core post fields
                $post_data = ['ID' => $product_id];
                $allowed_post_fields = ['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_date', 'post_author', 'post_parent', 'post_name'];
                if (in_array($field_name, $allowed_post_fields)) {
                    $post_data[$field_name] = $value;
                    $result = wp_update_post($post_data, true);
                    return ['success' => !is_wp_error($result)];
                }
                return ['success' => false, 'error' => 'Invalid post field'];
                
            case 'meta_data':
                $result = update_post_meta($product_id, $field_name, $value);
                return ['success' => $result !== false];
                
            case 'taxonomy':
                $taxonomy = $column_settings['taxonomy'] ?? '';
                if ($taxonomy) {
                    $terms = is_array($value) ? $value : array_map('trim', explode(',', $value));
                    $result = wp_set_object_terms($product_id, $terms, $taxonomy);
                    return ['success' => !is_wp_error($result)];
                }
                return ['success' => false, 'error' => 'Invalid taxonomy'];
                
            default:
                // Default to meta_data
                $result = update_post_meta($product_id, $field_name, $value);
                return ['success' => $result !== false];
        }
    }

    /**
     * Compare two products and return differences
     * 
     * @param int $source_id Source product ID
     * @param int $target_id Target product ID
     * @return array Differences between products
     */
    public static function compareProducts(int $source_id, int $target_id): array
    {
        $source_fields = self::getProductFields($source_id);
        $target_fields = self::getProductFields($target_id);

        if (isset($source_fields['error'])) {
            return ['success' => false, 'error' => 'Source: ' . $source_fields['error']];
        }
        if (isset($target_fields['error'])) {
            return ['success' => false, 'error' => 'Target: ' . $target_fields['error']];
        }

        $differences = [];
        $categories = ['core', 'acf', 'seo', 'taxonomy', 'meta'];

        foreach ($categories as $category) {
            $source_cat = $source_fields[$category] ?? [];
            $target_cat = $target_fields[$category] ?? [];
            
            // Get all unique keys
            $all_keys = array_unique(array_merge(array_keys($source_cat), array_keys($target_cat)));
            
            foreach ($all_keys as $key) {
                $source_val = $source_cat[$key] ?? null;
                $target_val = $target_cat[$key] ?? null;
                
                // Compare values (handle arrays)
                $source_comparable = is_array($source_val) ? json_encode($source_val) : (string)$source_val;
                $target_comparable = is_array($target_val) ? json_encode($target_val) : (string)$target_val;
                
                if ($source_comparable !== $target_comparable) {
                    $differences[] = [
                        'field' => $key,
                        'category' => $category,
                        'source_value' => $source_val,
                        'target_value' => $target_val,
                    ];
                }
            }
        }

        return [
            'success' => true,
            'source_id' => $source_id,
            'target_id' => $target_id,
            'differences_count' => count($differences),
            'differences' => $differences,
        ];
    }

    /**
     * Clone product fields from source to target
     * 
     * @param int $source_id Source product ID
     * @param int $target_id Target product ID  
     * @param array $overrides Fields to override instead of copying
     * @param array $exclude Fields to exclude from copying
     * @return array Result with copied fields
     */
    public static function cloneProductFields(int $source_id, int $target_id, array $overrides = [], array $exclude = []): array
    {
        $source_fields = self::getProductFields($source_id);
        
        if (isset($source_fields['error'])) {
            return ['success' => false, 'error' => 'Source: ' . $source_fields['error']];
        }

        // Fields to always exclude (identifiers, timestamps)
        $always_exclude = ['ID', '_sku', 'post_date', 'post_modified', '_edit_lock', '_edit_last'];
        $exclude = array_merge($exclude, $always_exclude);

        $fields_to_copy = [];
        $categories = ['core', 'acf', 'seo', 'taxonomy', 'meta'];

        foreach ($categories as $category) {
            $source_cat = $source_fields[$category] ?? [];
            foreach ($source_cat as $key => $value) {
                if (in_array($key, $exclude)) {
                    continue;
                }
                // Skip empty values
                if ($value === '' || $value === null || $value === []) {
                    continue;
                }
                $fields_to_copy[$key] = $value;
            }
        }

        // Apply overrides
        foreach ($overrides as $key => $value) {
            $fields_to_copy[$key] = $value;
        }

        // Set the fields on target
        $result = self::setProductFields($target_id, $fields_to_copy);
        
        return [
            'success' => $result['success'],
            'fields_copied' => count($result['updated']),
            'fields' => $result['updated'],
            'skipped' => array_keys($result['errors']),
            'errors' => $result['errors'],
        ];
    }

    /**
     * Get a simplified list of available fields for a provider
     * 
     * @param string $provider Post type
     * @return array Field names with their types
     */
    public static function getAvailableFields(string $provider = 'product'): array
    {
        $columns = self::getColumnRegistry($provider);
        $fields = [];

        foreach ($columns as $key => $settings) {
            $fields[$key] = [
                'title' => $settings['title'] ?? $key,
                'type' => $settings['type'] ?? 'text',
                'data_type' => $settings['data_type'] ?? 'meta_data',
                'is_acf' => isset($settings['acf_field']),
                'is_readonly' => !empty($settings['is_locked']) || !empty($settings['formatted']['readOnly']),
            ];
        }

        return $fields;
    }
}
