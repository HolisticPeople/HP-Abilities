<?php
namespace HP_Abilities\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order status update ability.
 */
class OrderStatus
{
    /**
     * Valid order statuses that can be set.
     */
    private const ALLOWED_STATUSES = [
        'pending',
        'processing',
        'on-hold',
        'completed',
        'cancelled',
        'refunded',
        'failed',
    ];

    /**
     * Execute the order status update.
     *
     * @param array $input Input parameters.
     * @return array Result data.
     */
    public static function execute(array $input): array
    {
        $order_id = isset($input['order_id']) ? (int) $input['order_id'] : 0;
        $new_status = isset($input['status']) ? sanitize_text_field($input['status']) : '';
        $note = isset($input['note']) ? sanitize_textarea_field($input['note']) : '';
        
        if ($order_id <= 0) {
            return [
                'success' => false,
                'error'   => 'Invalid order ID',
            ];
        }
        
        // Normalize status
        $new_status = str_replace('wc-', '', $new_status);
        
        if (!in_array($new_status, self::ALLOWED_STATUSES, true)) {
            return [
                'success'          => false,
                'error'            => 'Invalid status: ' . $new_status,
                'allowed_statuses' => self::ALLOWED_STATUSES,
            ];
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return [
                'success' => false,
                'error'   => 'Order not found',
            ];
        }
        
        $old_status = $order->get_status();
        
        // Check if status is already the same
        if ($old_status === $new_status) {
            return [
                'success'    => true,
                'order_id'   => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'message'    => 'Order already has this status',
            ];
        }
        
        // Update the status
        try {
            $order->set_status($new_status, $note);
            $order->save();
            
            // Add additional note if provided
            if (!empty($note)) {
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: note text, 2: old status, 3: new status */
                        __('AI Agent Note: %1$s (Status changed from %2$s to %3$s)', 'hp-abilities'),
                        $note,
                        wc_get_order_status_name($old_status),
                        wc_get_order_status_name($new_status)
                    ),
                    false, // Not for customer
                    true   // Added by admin
                );
            }
            
            return [
                'success'    => true,
                'order_id'   => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'message'    => sprintf(
                    'Order #%s status changed from %s to %s',
                    $order->get_order_number(),
                    wc_get_order_status_name($old_status),
                    wc_get_order_status_name($new_status)
                ),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => 'Failed to update order: ' . $e->getMessage(),
            ];
        }
    }
}















