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
add_action('woocommerce_order_status_processing', 'intersoccer_populate_rosters_and_complete_order');
function intersoccer_populate_rosters_and_complete_order($order_id) {
    error_log('InterSoccer: Function intersoccer_populate_rosters_and_complete_order called for order ' . $order_id);

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('InterSoccer: Invalid order ID ' . $order_id . ' in intersoccer_populate_rosters_and_complete_order');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'intersoccer_rosters';

    error_log('InterSoccer: Processing order ' . $order_id . ' for roster population. Status: ' . $order->get_status() . ', Items count: ' . count($order->get_items()));

    $inserted = false;

    foreach ($order->get_items('line_item') as $item_id => $item) {
        error_log('InterSoccer: Processing item ' . $item_id . ' in order ' . $order_id);
        $result = intersoccer_update_roster_entry($order_id, $item_id);
        if ($result) {
            $inserted = true;
        }
    }

    if ($inserted) {
        error_log('InterSoccer: Auto-complete postponed for order ' . $order_id . '. Use Process Orders on Advanced page to complete.');
    } else {
        error_log('InterSoccer: No rosters inserted for order ' . $order_id . ', status not changed - Check skips and insert errors above');
    }

    $verification_query = $wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id);
    $results = $wpdb->get_results($verification_query);
    error_log('InterSoccer: Verification query results for order ' . $order_id . ': ' . print_r($results, true));
}

add_action('woocommerce_order_status_completed', 'intersoccer_populate_completed_orders', 10, 1);
function intersoccer_populate_completed_orders($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('InterSoccer: Invalid order ID ' . $order_id . ' in intersoccer_populate_completed_orders');
        return;
    }

    foreach ($order->get_items() as $item_id => $item) {
        intersoccer_update_roster_entry($order_id, $item_id);
    }
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