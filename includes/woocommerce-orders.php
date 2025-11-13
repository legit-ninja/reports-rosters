<?php
/**
 * File: woocommerce-orders.php
 * Description: Handles WooCommerce order status changes to populate the intersoccer_rosters table.
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
 * Version: 1.4.47
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Diagnostic log to confirm file inclusion
error_log('InterSoccer: woocommerce-orders.php file loaded');

// Include shared utils
require_once dirname(__FILE__) . '/utils.php';

// Hook into order status change to processing
add_action('woocommerce_order_status_processing', 'intersoccer_populate_rosters_and_complete_order', 10, 1);
function intersoccer_populate_rosters_and_complete_order($order_id) {
    error_log('InterSoccer: Processing order ' . $order_id . ' via woocommerce_order_status_processing hook');
    
    $oop_enabled = intersoccer_orders_use_oop();
    error_log('InterSoccer: OOP orders enabled: ' . ($oop_enabled ? 'yes' : 'no'));
    
    if ($oop_enabled && intersoccer_process_order_oop($order_id)) {
        error_log('InterSoccer: Order ' . $order_id . ' processed successfully via OOP path');
        return;
    }

    error_log('InterSoccer: Falling back to legacy path for order ' . $order_id);
    intersoccer_populate_rosters_legacy($order_id);
}

add_action('woocommerce_order_status_completed', 'intersoccer_populate_completed_orders', 10, 1);
function intersoccer_populate_completed_orders($order_id) {
    if (intersoccer_orders_use_oop() && intersoccer_process_order_oop($order_id)) {
        return;
    }

    intersoccer_populate_rosters_legacy($order_id);
}

function intersoccer_debug_populate_rosters($order_id) {
    ob_start();
    error_log('InterSoccer: Debug wrapper called for order ' . $order_id);
    try {
        intersoccer_populate_rosters_and_complete_order($order_id);
        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('InterSoccer: Debug wrapper output for order ' . $order_id . ': ' . substr($output, 0, 1000));
        }
        return true;
    } catch (Exception $e) {
        error_log('InterSoccer: Debug wrapper error for order ' . $order_id . ': ' . $e->getMessage());
        ob_end_clean();
        return false;
    }
}

/**
 * Determine if the OOP order pipeline should be used.
 *
 * @return bool
 */
function intersoccer_orders_use_oop() {
    return function_exists('intersoccer_oop_process_order') &&
        (
            (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('orders'))
            || apply_filters('intersoccer_oop_force_orders', false)
        );
}

/**
 * Attempt to process an order via the OOP pipeline.
 *
 * @param int $order_id
 * @return bool
 */
function intersoccer_process_order_oop($order_id) {
    try {
        $result = intersoccer_oop_process_order($order_id);
        if ($result) {
            error_log('InterSoccer (OOP Orders): Successfully processed order ' . $order_id);
            return true;
        }

        error_log('InterSoccer (OOP Orders): Order ' . $order_id . ' returned failure when processing.');
    } catch (\Throwable $e) {
        error_log('InterSoccer (OOP Orders): Exception while processing order ' . $order_id . ' - ' . $e->getMessage());
    }

    return false;
}

/**
 * Legacy roster population logic retained as a fallback until all orders are migrated.
 *
 * @param int $order_id
 * @return bool
 */
function intersoccer_populate_rosters_legacy($order_id) {
    // Prefer delegating to the OOP order processor even when the orders
    // feature flag is disabled. This keeps the legacy path as a thin
    // compatibility wrapper while we finish the full migration.
    if (function_exists('intersoccer_oop_get_order_processor')) {
        try {
            $processor = intersoccer_oop_get_order_processor();
            if ($processor->processOrder($order_id)) {
                error_log('InterSoccer (Legacy Orders): Delegated order ' . $order_id . ' to OrderProcessor.');
                return true;
            }
        } catch (\Throwable $e) {
            error_log('InterSoccer (Legacy Orders): OrderProcessor delegation failed for order ' . $order_id . ' - ' . $e->getMessage());
        }
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('InterSoccer (Legacy Orders): Invalid order ID ' . $order_id);
        return false;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'intersoccer_rosters';

    error_log('InterSoccer (Legacy Orders): Processing order ' . $order_id . ' for roster population. Status: ' . $order->get_status() . ', Items count: ' . count($order->get_items()));

    $inserted = false;

    foreach ($order->get_items('line_item') as $item_id => $item) {
        error_log('InterSoccer (Legacy Orders): Processing item ' . $item_id . ' in order ' . $order_id);
        $result = intersoccer_update_roster_entry($order_id, $item_id);
        if ($result) {
            $inserted = true;
        }
    }

    if ($inserted) {
        error_log('InterSoccer (Legacy Orders): Auto-complete postponed for order ' . $order_id . '. Use Process Orders on Advanced page to complete.');
    } else {
        error_log('InterSoccer (Legacy Orders): No rosters inserted for order ' . $order_id . ', status not changed - Check skips and insert errors above');
    }

    $verification_query = $wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id);
    $results = $wpdb->get_results($verification_query);
    error_log('InterSoccer (Legacy Orders): Verification query results for order ' . $order_id . ': ' . print_r($results, true));

    return $inserted;
}

// ============================================================================
// ORDER AUTO-COMPLETION VIA CRON
// ============================================================================

if (defined('INTERSOCCER_ORDER_AUTO_COMPLETE_CRON_HOOK')) {
    add_action(INTERSOCCER_ORDER_AUTO_COMPLETE_CRON_HOOK, 'intersoccer_auto_complete_processing_orders');
}

if (defined('INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK')) {
    add_action(INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK, 'intersoccer_auto_complete_single_order');
}

/**
 * Schedule a follow-up check to complete the order once rosters are confirmed.
 *
 * @param int $order_id
 * @param int|null $delay Delay in seconds. Defaults to filter-configured value.
 * @return void
 */
function intersoccer_schedule_order_completion_check($order_id, $delay = null) {
    $order_id = absint($order_id);
    if ($order_id <= 0 || !function_exists('wp_schedule_single_event') || !defined('INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK')) {
        return;
    }

    if ($delay === null) {
        $delay = (int) apply_filters('intersoccer_auto_complete_single_delay_seconds', 180, $order_id);
    }

    if ($delay < 60) {
        $delay = 60;
    }

    $timestamp = time() + $delay;

    if (!wp_next_scheduled(INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK, [$order_id])) {
        wp_schedule_single_event($timestamp, INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK, [$order_id]);
        error_log('InterSoccer: Scheduled single auto-complete check for order ' . $order_id . ' in ' . $delay . ' seconds');
    }
}

/**
 * Cron callback to auto-complete WooCommerce orders stuck in processing after roster creation.
 *
 * @return void
 */
function intersoccer_auto_complete_processing_orders() {
    if (!function_exists('wc_get_orders')) {
        return;
    }

    $limit = (int) apply_filters('intersoccer_auto_complete_orders_batch_limit', 25);
    if ($limit <= 0) {
        $limit = 25;
    }

    $orders = wc_get_orders([
        'status' => ['wc-processing'],
        'limit'  => $limit,
        'orderby' => 'date',
        'order' => 'ASC',
        'return' => 'ids',
    ]);

    if (empty($orders)) {
        return;
    }

    foreach ($orders as $order_id) {
        intersoccer_attempt_order_auto_completion($order_id, 'recurring-cron');
    }
}

/**
 * Single-order cron callback.
 *
 * @param int $order_id
 * @return void
 */
function intersoccer_auto_complete_single_order($order_id) {
    intersoccer_attempt_order_auto_completion($order_id, 'single-cron');
}

/**
 * Attempt to auto-complete an order if roster entries exist.
 *
 * @param int $order_id
 * @param string $context
 * @return bool
 */
function intersoccer_attempt_order_auto_completion($order_id, $context = 'manual') {
    $order_id = absint($order_id);
    if ($order_id <= 0) {
        return false;
    }

    if (!function_exists('wc_get_order')) {
        return false;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('InterSoccer: Auto-complete skipped, order not found (' . $order_id . ')');
        return false;
    }

    $status = $order->get_status();
    if (!in_array($status, ['processing', 'on-hold'], true)) {
        return false;
    }

    if (!intersoccer_order_has_confirmed_rosters($order_id)) {
        error_log('InterSoccer: Auto-complete skipped, no roster entries confirmed for order ' . $order_id);
        return false;
    }

    try {
        $note = sprintf(
            /* translators: %s: automation context */
            __('Auto-completed via InterSoccer automation (%s).', 'intersoccer-reports-rosters'),
            $context
        );

        $order->update_status('completed', $note);
        $order->save();

        // Double-check persisted status
        $reloaded = wc_get_order($order_id);
        if ($reloaded && $reloaded->get_status() === 'completed') {
            error_log('InterSoccer: Order ' . $order_id . ' auto-completed via context ' . $context);
            return true;
        }

        error_log('InterSoccer: Auto-complete verification failed for order ' . $order_id . ' (context ' . $context . ')');
        return false;
    } catch (\Throwable $e) {
        error_log('InterSoccer: Auto-complete failed for order ' . $order_id . ' (context ' . $context . ') - ' . $e->getMessage());
        return false;
    }
}

/**
 * Determine if an order has roster entries recorded in the database.
 *
 * @param int $order_id
 * @return bool
 */
function intersoccer_order_has_confirmed_rosters($order_id) {
    global $wpdb;

    $order_id = absint($order_id);
    if ($order_id <= 0) {
        return false;
    }

    $table = $wpdb->prefix . 'intersoccer_rosters';
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE order_id = %d",
        $order_id
    ));

    return $count > 0;
}