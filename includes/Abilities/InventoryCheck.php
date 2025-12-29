<?php
namespace HP_Abilities\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inventory check ability.
 */
class InventoryCheck
{
    /**
     * Execute the inventory check.
     *
     * @param array $input Input parameters.
     * @return array Result data.
     */
    public static function execute(array $input): array
    {
        $skus = $input['skus'] ?? [];
        
        if (!is_array($skus)) {
            $skus = [$skus];
        }
        
        if (empty($skus)) {
            return [
                'products' => [],
                'error'    => 'No SKUs provided',
            ];
        }
        
        $products = [];
        
        foreach ($skus as $sku) {
            $sku = sanitize_text_field(trim($sku));
            if (empty($sku)) {
                continue;
            }
            
            $product_id = wc_get_product_id_by_sku($sku);
            
            if (!$product_id) {
                $products[] = [
                    'sku'       => $sku,
                    'found'     => false,
                    'error'     => 'Product not found',
                ];
                continue;
            }
            
            $product = wc_get_product($product_id);
            
            if (!$product) {
                $products[] = [
                    'sku'       => $sku,
                    'found'     => false,
                    'error'     => 'Could not load product',
                ];
                continue;
            }
            
            $products[] = self::format_product_stock($product, $sku);
        }
        
        return [
            'products' => $products,
        ];
    }

    /**
     * Format product stock information.
     *
     * @param \WC_Product $product Product object.
     * @param string      $sku     SKU being queried.
     * @return array Stock data.
     */
    private static function format_product_stock(\WC_Product $product, string $sku): array
    {
        $stock_qty = $product->get_stock_quantity();
        $manages_stock = $product->managing_stock();
        
        // Handle parent product for variations
        $parent_stock = null;
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent && $parent->managing_stock()) {
                $parent_stock = $parent->get_stock_quantity();
            }
        }
        
        return [
            'found'           => true,
            'sku'             => $sku,
            'product_id'      => $product->get_id(),
            'name'            => $product->get_name(),
            'type'            => $product->get_type(),
            'in_stock'        => $product->is_in_stock(),
            'stock_status'    => $product->get_stock_status(),
            'manages_stock'   => $manages_stock,
            'stock_quantity'  => $manages_stock ? $stock_qty : null,
            'parent_stock'    => $parent_stock,
            'backorders'      => $product->get_backorders(),
            'backorders_allowed' => $product->backorders_allowed(),
            'low_stock_threshold' => $product->get_low_stock_amount(),
            'price'           => (float) $product->get_price(),
            'regular_price'   => (float) $product->get_regular_price(),
            'sale_price'      => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
            'on_sale'         => $product->is_on_sale(),
        ];
    }
}















