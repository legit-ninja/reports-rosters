<?php
/**
 * Sync rosters when product-variations repairs order line meta.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

add_action('intersoccer_order_line_meta_repaired', 'intersoccer_handle_order_line_meta_repaired', 10, 2);

/**
 * Queue roster refresh for an order after meta repair.
 *
 * @param int $order_id Order ID.
 * @param int $item_id  Order item ID.
 */
function intersoccer_handle_order_line_meta_repaired($order_id, $item_id) {
    $order_id = (int) $order_id;
    $item_id = (int) $item_id;

    if ($order_id <= 0) {
        return;
    }

    static $queued_orders = [];

    if (isset($queued_orders[$order_id])) {
        return;
    }

    $queued_orders[$order_id] = true;

    add_action('shutdown', static function () use ($order_id, $item_id) {
        if (function_exists('intersoccer_oop_update_roster_entry')) {
            intersoccer_oop_update_roster_entry($order_id, $item_id);
            return;
        }

        if (function_exists('intersoccer_oop_get_roster_builder')) {
            $builder = intersoccer_oop_get_roster_builder();
            if ($builder && method_exists($builder, 'rebuildSpecificOrders')) {
                $builder->rebuildSpecificOrders([$order_id]);
            }
        }
    }, 20);
}
