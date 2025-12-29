<?php
namespace HP_Abilities\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order search ability.
 */
class OrderSearch
{
    /**
     * Execute the order search.
     *
     * @param array $input Input parameters.
     * @return array Result data.
     */
    public static function execute(array $input): array
    {
        $args = [
            'limit'   => isset($input['limit']) ? min(50, max(1, (int) $input['limit'])) : 10,
            'orderby' => 'date',
            'order'   => 'DESC',
        ];
        
        // Filter by customer email
        if (!empty($input['customer_email'])) {
            $email = sanitize_email($input['customer_email']);
            if (is_email($email)) {
                // Check for registered user
                $user = get_user_by('email', $email);
                if ($user) {
                    $args['customer_id'] = $user->ID;
                } else {
                    $args['billing_email'] = $email;
                }
            }
        }
        
        // Filter by status
        if (!empty($input['status'])) {
            $status = sanitize_text_field($input['status']);
            // Normalize status (add wc- prefix if needed)
            if (strpos($status, 'wc-') !== 0) {
                $status = 'wc-' . $status;
            }
            $args['status'] = $status;
        }
        
        // Filter by date range
        if (!empty($input['date_from'])) {
            $args['date_created'] = '>=' . sanitize_text_field($input['date_from']);
        }
        
        if (!empty($input['date_to'])) {
            if (isset($args['date_created'])) {
                $args['date_created'] = $args['date_created'] . '...' . sanitize_text_field($input['date_to']);
            } else {
                $args['date_created'] = '<=' . sanitize_text_field($input['date_to']);
            }
        }
        
        // Get orders
        $orders = wc_get_orders($args);
        
        // Filter by product SKU (post-query filter)
        if (!empty($input['product_sku'])) {
            $target_sku = strtolower(trim($input['product_sku']));
            $orders = array_filter($orders, function ($order) use ($target_sku) {
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if ($product && strtolower($product->get_sku()) === $target_sku) {
                        return true;
                    }
                }
                return false;
            });
        }
        
        // Count total before limiting (approximate for performance)
        $total_found = count($orders);
        
        // Format results
        $formatted = [];
        foreach ($orders as $order) {
            $formatted[] = self::format_order($order);
        }
        
        return [
            'orders'      => $formatted,
            'total_found' => $total_found,
        ];
    }

    /**
     * Format a single order for output.
     *
     * @param \WC_Order $order Order object.
     * @return array Formatted order data.
     */
    private static function format_order(\WC_Order $order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'name'     => $item->get_name(),
                'sku'      => $product ? $product->get_sku() : '',
                'quantity' => $item->get_quantity(),
                'total'    => (float) $item->get_total(),
            ];
        }
        
        return [
            'id'              => $order->get_id(),
            'number'          => $order->get_order_number(),
            'status'          => $order->get_status(),
            'date'            => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
            'total'           => (float) $order->get_total(),
            'currency'        => $order->get_currency(),
            'customer_name'   => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'customer_email'  => $order->get_billing_email(),
            'items_count'     => $order->get_item_count(),
            'items'           => $items,
            'shipping_method' => $order->get_shipping_method(),
            'payment_method'  => $order->get_payment_method_title(),
        ];
    }
}















