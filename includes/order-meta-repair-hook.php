<?php
/**
 * Sync rosters when product-variations repairs order line meta.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

add_action('intersoccer_order_line_meta_repaired', 'intersoccer_handle_order_line_meta_repaired', 10, 2);
add_action('intersoccer_pv_order_season_labels_repaired', 'intersoccer_handle_pv_order_season_labels_repaired', 10, 1);

if (!function_exists('intersoccer_rr_load_reconcile')) {
    /**
     * Load includes/db.php (reconcile functions) on demand.
     *
     * db.php is only required by admin screens, so WP-CLI and cron contexts
     * (PV season-label repair hook, `wp intersoccer-rr reconcile-orders`) must
     * load it explicitly.
     *
     * @return bool True when intersoccer_reconcile_rosters() is callable.
     */
    function intersoccer_rr_load_reconcile() {
        if (!function_exists('intersoccer_reconcile_rosters')) {
            $db_file = __DIR__ . '/db.php';
            if (is_readable($db_file)) {
                require_once $db_file;
            }
        }
        return function_exists('intersoccer_reconcile_rosters')
            && function_exists('intersoccer_reconcile_roster_season_signatures');
    }
}

/**
 * Scoped reconcile after Product Variations rewrites order-item Season labels.
 *
 * @param array $order_ids Order ids whose Season labels were repaired.
 */
function intersoccer_handle_pv_order_season_labels_repaired($order_ids) {
    $order_ids = function_exists('intersoccer_reconcile_normalize_id_list')
        ? intersoccer_reconcile_normalize_id_list($order_ids)
        : array_values(array_filter(array_map('intval', (array) $order_ids)));
    if ($order_ids === []) {
        return;
    }

    if (!intersoccer_rr_load_reconcile()) {
        if (function_exists('do_action')) {
            do_action('intersoccer_rr_pv_season_reconcile_unavailable', $order_ids);
        }
        return;
    }

    return intersoccer_reconcile_rosters([
        'order_ids' => $order_ids,
        'include_product_siblings' => true,
        'delete_obsolete' => false,
    ]);
}

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
