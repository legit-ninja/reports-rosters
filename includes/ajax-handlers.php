<?php
/**
 * InterSoccer Reports and Rosters AJAX Handlers
 * Author: Jeremy Lee
 * Description: Handles AJAX requests for rosters and reports.
 * Dependencies: WooCommerce, intersoccer-product-variations, intersoccer-player-management
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for fetching event roster
 */
function intersoccer_get_event_roster_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $filters = [
        'region' => isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '',
        'start_date' => isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '',
        'end_date' => isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '',
    ];

    if (!$product_id) {
        wp_send_json_error(['message' => __('Invalid product ID.', 'intersoccer-reports-rosters')]);
    }

    $roster = intersoccer_pe_get_event_roster($product_id, $filters);
    wp_send_json_success(['data' => $roster]);
}

/**
 * AJAX handler for fetching camp report
 */
function intersoccer_get_camp_report_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';
    $week = isset($_POST['week']) ? sanitize_text_field($_POST['week']) : '';
    $camp_type = isset($_POST['camp_type']) ? sanitize_text_field($_POST['camp_type']) : '';
    $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : date('Y');

    $report = intersoccer_pe_get_camp_report_data($region, $week, $camp_type, $year);
    wp_send_json_success(['data' => $report]);
}
?>