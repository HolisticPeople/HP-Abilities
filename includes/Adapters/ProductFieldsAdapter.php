<?php
namespace HP_Abilities\Adapters;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product Fields Adapter
 * 
 * Provides comprehensive product field access by combining:
 * - WordPress core post data
 * - WooCommerce product data
 * - ACF Pro fields (via get_fields())
 * - Yoast SEO meta
 * - All post meta
 * 
 * This adapter uses direct WordPress/ACF/Yoast APIs for reliable
 * MCP/REST access without requiring any admin UI context.
 * 
 * @requires ACF Pro for custom field operations
 * @requires Yoast SEO for SEO field operations
 */
class ProductFieldsAdapter
{
    /**
     * Check if we can access product fields
     */
    public static function isAvailable(): bool
    {
        return function_exists('wc_get_product') && class_exists('WooCommerce');
    }

    /**
     * Get all field values for a product - comprehensive extraction
     * 
     * @param int $product_id WooCommerce product ID
     * @return array Categorized fields: core, acf, seo, taxonomy, meta
     */
    public static function getProductFields(int $product_id): array
    {
        if (!self::isAvailable()) {
            return ['error' => 'WooCommerce not available'];
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return ['error' => 'Product not found'];
        }

        $post = get_post($product_id);
        
        $fields = [
            'core' => self::getCoreFields($product, $post),
            'acf' => self::getAcfFields($product_id),
            'seo' => self::getSeoFields($product_id),
            'taxonomy' => self::getTaxonomyFields($product_id),
            'meta' => self::getFilteredMeta($product_id),
        ];

        return $fields;
    }

    /**
     * Get core WooCommerce/WordPress fields
     */
    private static function getCoreFields($product, $post): array
    {
        return [
            'ID' => $product->get_id(),
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status' => $post->post_status,
            'post_date' => $post->post_date,
            'post_name' => $post->post_name,
            '_sku' => $product->get_sku(),
            '_regular_price' => $product->get_regular_price(),
            '_sale_price' => $product->get_sale_price(),
            '_price' => $product->get_price(),
            '_stock' => $product->get_stock_quantity(),
            '_stock_status' => $product->get_stock_status(),
            '_manage_stock' => $product->get_manage_stock() ? 'yes' : 'no',
            '_weight' => $product->get_weight(),
            '_length' => $product->get_length(),
            '_width' => $product->get_width(),
            '_height' => $product->get_height(),
            '_thumbnail_id' => $product->get_image_id(),
            '_virtual' => $product->is_virtual() ? 'yes' : 'no',
            '_downloadable' => $product->is_downloadable() ? 'yes' : 'no',
            '_tax_status' => $product->get_tax_status(),
            '_tax_class' => $product->get_tax_class(),
            '_purchase_note' => $product->get_purchase_note(),
            '_featured' => $product->is_featured() ? 'yes' : 'no',
        ];
    }

    /**
     * Get all ACF fields for a product
     * 
     * @requires ACF Pro plugin
     */
    private static function getAcfFields(int $product_id): array
    {
        if (!function_exists('get_fields')) {
            return [];
        }

        $acf_fields = get_fields($product_id);
        if (!is_array($acf_fields)) {
            return [];
        }

        // Flatten complex values for display
        $result = [];
        foreach ($acf_fields as $key => $value) {
            if (is_array($value)) {
                // Handle arrays (multi-select, repeater, etc.)
                if (self::isAssociativeArray($value)) {
                    // Associative array - serialize as JSON
                    $result[$key] = json_encode($value);
                } else {
                    // Sequential array - join with comma
                    $result[$key] = implode(', ', array_map(function($v) {
                        return is_array($v) ? json_encode($v) : $v;
                    }, $value));
                }
            } elseif (is_object($value)) {
                // Objects (post, user, etc.)
                if (isset($value->ID)) {
                    $result[$key] = $value->ID;
                } else {
                    $result[$key] = json_encode($value);
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get Yoast SEO fields
     * 
     * @requires Yoast SEO plugin
     */
    private static function getSeoFields(int $product_id): array
    {
        return [
            '_yoast_wpseo_title' => get_post_meta($product_id, '_yoast_wpseo_title', true),
            '_yoast_wpseo_metadesc' => get_post_meta($product_id, '_yoast_wpseo_metadesc', true),
            '_yoast_wpseo_focuskw' => get_post_meta($product_id, '_yoast_wpseo_focuskw', true),
            '_yoast_wpseo_canonical' => get_post_meta($product_id, '_yoast_wpseo_canonical', true),
            '_yoast_wpseo_meta-robots-noindex' => get_post_meta($product_id, '_yoast_wpseo_meta-robots-noindex', true),
            '_yoast_wpseo_meta-robots-nofollow' => get_post_meta($product_id, '_yoast_wpseo_meta-robots-nofollow', true),
        ];
    }

    /**
     * Get taxonomy terms for a product
     */
    private static function getTaxonomyFields(int $product_id): array
    {
        $result = [];
        
        // Product categories
        $cats = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'names']);
        $result['product_cat'] = is_array($cats) ? implode(', ', $cats) : '';
        
        // Product tags
        $tags = wp_get_object_terms($product_id, 'product_tag', ['fields' => 'names']);
        $result['product_tag'] = is_array($tags) ? implode(', ', $tags) : '';
        
        // Product attributes (simplified)
        $product = wc_get_product($product_id);
        if ($product) {
            $attributes = $product->get_attributes();
            foreach ($attributes as $attr_name => $attr) {
                if (is_object($attr) && method_exists($attr, 'get_options')) {
                    $options = $attr->get_options();
                    if (is_array($options)) {
                        // Get term names if taxonomy attribute
                        if ($attr->is_taxonomy()) {
                            $terms = [];
                            foreach ($options as $term_id) {
                                $term = get_term($term_id);
                                if ($term && !is_wp_error($term)) {
                                    $terms[] = $term->name;
                                }
                            }
                            $result['attr_' . $attr_name] = implode(', ', $terms);
                        } else {
                            $result['attr_' . $attr_name] = implode(', ', $options);
                        }
                    }
                }
            }
        }
        
        return $result;
    }

    /**
     * Get filtered post meta (excluding internal/system keys)
     */
    private static function getFilteredMeta(int $product_id): array
    {
        $all_meta = get_post_meta($product_id);
        $result = [];
        
        // Keys to exclude (internal, duplicates of core fields)
        $exclude_patterns = [
            '/^_wp_/',           // WordPress internal
            '/^_edit_/',         // Edit locks
            '/^_oembed/',        // Embed cache
            '/^_transient/',     // Transients
            '/^_yoast/',         // Yoast (handled separately)
            '/^_sku$/',          // Core WC (handled separately)
            '/^_price$/',
            '/^_regular_price$/',
            '/^_sale_price$/',
            '/^_stock/',
            '/^_weight$/',
            '/^_length$/',
            '/^_width$/',
            '/^_height$/',
            '/^_thumbnail_id$/',
            '/^_virtual$/',
            '/^_downloadable$/',
            '/^_tax_/',
            '/^_purchase_note$/',
            '/^_featured$/',
            '/^_manage_stock$/',
            '/^_product_attributes$/', // Complex serialized data
        ];
        
        foreach ($all_meta as $key => $values) {
            // Skip excluded patterns
            $skip = false;
            foreach ($exclude_patterns as $pattern) {
                if (preg_match($pattern, $key)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;
            
            // Get the first value (most meta is single)
            $value = isset($values[0]) ? $values[0] : '';
            
            // Skip empty values
            if ($value === '' || $value === null) continue;
            
            // Skip serialized data that's too complex
            if (is_serialized($value)) {
                $unserialized = maybe_unserialize($value);
                if (is_array($unserialized) && count($unserialized) > 10) {
                    continue; // Skip large arrays
                }
                $value = is_array($unserialized) ? json_encode($unserialized) : $value;
            }
            
            $result[$key] = $value;
        }
        
        return $result;
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
        if (!self::isAvailable()) {
            return ['success' => false, 'error' => 'WooCommerce not available'];
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found'];
        }

        $updated = [];
        $errors = [];

        foreach ($fields as $field_name => $value) {
            $result = self::setFieldValue($product_id, $product, $field_name, $value);
            if ($result['success']) {
                $updated[] = $field_name;
            } else {
                $errors[$field_name] = $result['error'];
            }
        }

        // Save product changes
        $product->save();

        // Clear caches
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
    private static function setFieldValue(int $product_id, $product, string $field_name, $value): array
    {
        try {
            // Core post fields
            $post_fields = ['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name'];
            if (in_array($field_name, $post_fields)) {
                $result = wp_update_post([
                    'ID' => $product_id,
                    $field_name => $value
                ], true);
                return ['success' => !is_wp_error($result)];
            }

            // WooCommerce core fields
            $wc_setters = [
                '_regular_price' => 'set_regular_price',
                '_sale_price' => 'set_sale_price',
                '_sku' => 'set_sku',
                '_stock' => 'set_stock_quantity',
                '_stock_status' => 'set_stock_status',
                '_weight' => 'set_weight',
                '_length' => 'set_length',
                '_width' => 'set_width',
                '_height' => 'set_height',
                '_manage_stock' => 'set_manage_stock',
                '_virtual' => 'set_virtual',
                '_downloadable' => 'set_downloadable',
                '_tax_status' => 'set_tax_status',
                '_tax_class' => 'set_tax_class',
                '_purchase_note' => 'set_purchase_note',
            ];
            
            if (isset($wc_setters[$field_name]) && method_exists($product, $wc_setters[$field_name])) {
                $method = $wc_setters[$field_name];
                $product->$method($value);
                return ['success' => true];
            }

            // Yoast SEO fields
            if (strpos($field_name, '_yoast_wpseo') === 0) {
                update_post_meta($product_id, $field_name, sanitize_text_field($value));
                return ['success' => true];
            }

            // Taxonomy fields
            if ($field_name === 'product_cat') {
                $terms = array_map('trim', explode(',', $value));
                $result = wp_set_object_terms($product_id, $terms, 'product_cat');
                return ['success' => !is_wp_error($result)];
            }
            if ($field_name === 'product_tag') {
                $terms = array_map('trim', explode(',', $value));
                $result = wp_set_object_terms($product_id, $terms, 'product_tag');
                return ['success' => !is_wp_error($result)];
            }

            // ACF fields (try ACF first, then fall back to meta)
            if (function_exists('update_field')) {
                // Check if this is an ACF field by trying to get field object
                $field_object = acf_get_field($field_name);
                if ($field_object) {
                    update_field($field_name, $value, $product_id);
                    return ['success' => true];
                }
            }

            // Default: update as post meta
            $result = update_post_meta($product_id, $field_name, $value);
            return ['success' => $result !== false];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
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
        $always_exclude = ['ID', '_sku', 'post_date', 'post_modified', '_edit_lock', '_edit_last', 'post_name'];
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
     * Get a simplified list of available fields for a product
     * 
     * @param string $provider Product type (default 'product')
     * @return array Field names with their categories
     */
    public static function getAvailableFields(string $provider = 'product'): array
    {
        // Get a sample product to enumerate fields
        $products = wc_get_products(['limit' => 1, 'status' => 'publish']);
        
        if (empty($products)) {
            return [];
        }

        $sample_id = $products[0]->get_id();
        $all_fields = self::getProductFields($sample_id);
        
        if (isset($all_fields['error'])) {
            return [];
        }

        $fields = [];
        $categories = ['core', 'acf', 'seo', 'taxonomy', 'meta'];

        foreach ($categories as $category) {
            $cat_fields = $all_fields[$category] ?? [];
            foreach ($cat_fields as $key => $value) {
                $fields[$key] = [
                    'title' => self::humanizeFieldName($key),
                    'category' => $category,
                    'type' => gettype($value),
                    'is_acf' => $category === 'acf',
                    'is_readonly' => in_array($key, ['ID', 'post_date']),
                ];
            }
        }

        return $fields;
    }

    /**
     * Convert field key to human-readable name
     */
    private static function humanizeFieldName(string $key): string
    {
        // Remove common prefixes
        $name = preg_replace('/^(_yoast_wpseo_|_wc_|_)/', '', $key);
        // Convert underscores/dashes to spaces
        $name = str_replace(['_', '-'], ' ', $name);
        // Title case
        return ucwords($name);
    }

    /**
     * Check if array is associative
     */
    private static function isAssociativeArray(array $arr): bool
    {
        if (empty($arr)) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
