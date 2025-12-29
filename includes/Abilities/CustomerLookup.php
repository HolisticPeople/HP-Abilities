<?php
namespace HP_Abilities\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Customer lookup ability.
 */
class CustomerLookup
{
    /**
     * Execute the customer lookup.
     *
     * @param array $input Input parameters.
     * @return array Result data.
     */
    public static function execute(array $input): array
    {
        $email = sanitize_email($input['email'] ?? '');
        
        if (empty($email) || !is_email($email)) {
            return [
                'found' => false,
                'error' => 'Invalid email address',
            ];
        }
        
        // Try to find user by email
        $user = get_user_by('email', $email);
        
        if (!$user) {
            // Check for guest orders
            $guest_orders = wc_get_orders([
                'billing_email' => $email,
                'limit'         => 1,
            ]);
            
            if (empty($guest_orders)) {
                return [
                    'found' => false,
                    'email' => $email,
                ];
            }
            
            // Build guest profile from orders
            return self::build_guest_profile($email);
        }
        
        return self::build_user_profile($user);
    }

    /**
     * Build profile for registered user.
     *
     * @param \WP_User $user WordPress user.
     * @return array Profile data.
     */
    private static function build_user_profile(\WP_User $user): array
    {
        $customer = new \WC_Customer($user->ID);
        
        // Get order stats
        $orders = wc_get_orders([
            'customer_id' => $user->ID,
            'limit'       => -1,
            'return'      => 'ids',
        ]);
        
        $total_spent = 0;
        $last_order_date = null;
        
        if (!empty($orders)) {
            foreach ($orders as $order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $total_spent += (float) $order->get_total();
                    $date = $order->get_date_created();
                    if ($date && (!$last_order_date || $date > $last_order_date)) {
                        $last_order_date = $date;
                    }
                }
            }
        }
        
        // Get points balance
        $points = 0;
        if (function_exists('eao_yith_get_customer_points')) {
            $points = (int) \eao_yith_get_customer_points($user->ID);
        } else {
            $raw = get_user_meta($user->ID, '_ywpar_user_total_points', true);
            $points = is_numeric($raw) ? (int) $raw : 0;
        }
        
        return [
            'found'           => true,
            'is_registered'   => true,
            'user_id'         => $user->ID,
            'name'            => trim($customer->get_first_name() . ' ' . $customer->get_last_name()),
            'email'           => $user->user_email,
            'username'        => $user->user_login,
            'orders_count'    => count($orders),
            'total_spent'     => round($total_spent, 2),
            'points_balance'  => $points,
            'last_order_date' => $last_order_date ? $last_order_date->date('Y-m-d H:i:s') : null,
            'billing_address' => [
                'first_name' => $customer->get_billing_first_name(),
                'last_name'  => $customer->get_billing_last_name(),
                'company'    => $customer->get_billing_company(),
                'address_1'  => $customer->get_billing_address_1(),
                'address_2'  => $customer->get_billing_address_2(),
                'city'       => $customer->get_billing_city(),
                'state'      => $customer->get_billing_state(),
                'postcode'   => $customer->get_billing_postcode(),
                'country'    => $customer->get_billing_country(),
                'phone'      => $customer->get_billing_phone(),
            ],
            'shipping_address' => [
                'first_name' => $customer->get_shipping_first_name(),
                'last_name'  => $customer->get_shipping_last_name(),
                'company'    => $customer->get_shipping_company(),
                'address_1'  => $customer->get_shipping_address_1(),
                'address_2'  => $customer->get_shipping_address_2(),
                'city'       => $customer->get_shipping_city(),
                'state'      => $customer->get_shipping_state(),
                'postcode'   => $customer->get_shipping_postcode(),
                'country'    => $customer->get_shipping_country(),
            ],
            'recent_orders' => self::get_recent_orders($user->ID, 5),
        ];
    }

    /**
     * Build profile for guest customer.
     *
     * @param string $email Customer email.
     * @return array Profile data.
     */
    private static function build_guest_profile(string $email): array
    {
        $orders = wc_get_orders([
            'billing_email' => $email,
            'limit'         => -1,
        ]);
        
        $total_spent = 0;
        $last_order_date = null;
        $last_order = null;
        
        foreach ($orders as $order) {
            $total_spent += (float) $order->get_total();
            $date = $order->get_date_created();
            if ($date && (!$last_order_date || $date > $last_order_date)) {
                $last_order_date = $date;
                $last_order = $order;
            }
        }
        
        $result = [
            'found'           => true,
            'is_registered'   => false,
            'user_id'         => 0,
            'name'            => $last_order ? trim($last_order->get_billing_first_name() . ' ' . $last_order->get_billing_last_name()) : '',
            'email'           => $email,
            'orders_count'    => count($orders),
            'total_spent'     => round($total_spent, 2),
            'points_balance'  => 0,
            'last_order_date' => $last_order_date ? $last_order_date->date('Y-m-d H:i:s') : null,
        ];
        
        if ($last_order) {
            $result['billing_address'] = [
                'first_name' => $last_order->get_billing_first_name(),
                'last_name'  => $last_order->get_billing_last_name(),
                'company'    => $last_order->get_billing_company(),
                'address_1'  => $last_order->get_billing_address_1(),
                'address_2'  => $last_order->get_billing_address_2(),
                'city'       => $last_order->get_billing_city(),
                'state'      => $last_order->get_billing_state(),
                'postcode'   => $last_order->get_billing_postcode(),
                'country'    => $last_order->get_billing_country(),
                'phone'      => $last_order->get_billing_phone(),
            ];
        }
        
        $result['recent_orders'] = self::format_orders_summary($orders, 5);
        
        return $result;
    }

    /**
     * Get recent orders for a user.
     *
     * @param int $user_id User ID.
     * @param int $limit Max orders.
     * @return array Orders summary.
     */
    private static function get_recent_orders(int $user_id, int $limit = 5): array
    {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => $limit,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        
        return self::format_orders_summary($orders, $limit);
    }

    /**
     * Format orders into summary array.
     *
     * @param array $orders WC_Order objects.
     * @param int   $limit  Max to return.
     * @return array Formatted orders.
     */
    private static function format_orders_summary(array $orders, int $limit): array
    {
        $result = [];
        $count = 0;
        
        foreach ($orders as $order) {
            if ($count >= $limit) {
                break;
            }
            
            $items_summary = [];
            foreach ($order->get_items() as $item) {
                $items_summary[] = $item->get_quantity() . 'x ' . $item->get_name();
            }
            
            $result[] = [
                'id'       => $order->get_id(),
                'number'   => $order->get_order_number(),
                'status'   => $order->get_status(),
                'date'     => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : null,
                'total'    => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'items'    => implode(', ', $items_summary),
            ];
            
            $count++;
        }
        
        return $result;
    }
}















