<?php
/**
 * InterSoccer Reports and Rosters AJAX Handlers
 * Author: Jeremy Lee
 * Description: Handles AJAX requests for rosters and reports.
 * Dependencies: WooCommerce, intersoccer-product-variations, intersoccer-player-management
 * Version: 1.0.7
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler for fetching event roster
 */
function intersoccer_reports_get_event_roster_ajax() {
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

    $roster = intersoccer_pe_get_event_roster($product_id, $filters); // Assuming this function exists or needs adjustment
    wp_send_json_success(['data' => $roster]);
}

/**
 * AJAX handler for fetching camp report
 */
function intersoccer_reports_get_camp_report_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';
    $week = isset($_POST['week']) ? sanitize_text_field($_POST['week']) : '';
    $camp_type = isset($_POST['camp_type']) ? sanitize_text_field($_POST['camp_type']) : '';
    $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : date('Y');

    $report = intersoccer_pe_get_camp_report_data($region, $week, $camp_type, $year); // Assuming this function exists
    wp_send_json_success(['data' => $report]);
}

/**
 * AJAX handler for exporting a single roster
 */
function intersoccer_reports_export_roster_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options') && !current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $variation_ids = isset($_POST['variation_ids']) ? array_map('intval', $_POST['variation_ids']) : [];
    if (empty($variation_ids)) {
        wp_send_json_error(['message' => __('Invalid variation IDs.', 'intersoccer-reports-rosters')]);
    }

    ob_start();
    intersoccer_export_roster($variation_ids);
    ob_end_clean(); // Clean output buffer to prevent interference
    wp_die(); // Ensure proper exit after export
}

/**
 * AJAX handler for exporting a single roster
 */
function intersoccer_reports_export_all_rosters_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options') && !current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $export_type = isset($_POST['export_type']) ? sanitize_text_field($_POST['export_type']) : 'all';
    if (!in_array($export_type, ['all', 'camps', 'courses', 'girls_only', 'other'])) {
        wp_send_json_error(['message' => __('Invalid export type.', 'intersoccer-reports-rosters')]);
    }

    // Placeholder for $camps, $courses, $girls_only - these should be fetched based on export_type if needed
    $camps = ($export_type === 'camps') ? intersoccer_pe_get_camp_variations([]) : [];
    $courses = ($export_type === 'courses') ? intersoccer_pe_get_course_variations([]) : [];
    $girls_only = ($export_type === 'girls_only') ? intersoccer_pe_get_girls_only_variations([]) : [];

    ob_start();
    intersoccer_export_all_rosters($camps, $courses, $girls_only, $export_type);
    ob_end_clean(); // Clean output buffer to prevent interference
    wp_die(); // Ensure proper exit after export
}

add_action('wp_ajax_intersoccer_export_roster', 'intersoccer_reports_export_roster_ajax');
add_action('wp_ajax_intersoccer_export_all_rosters', 'intersoccer_reports_export_all_rosters_ajax');
?>
