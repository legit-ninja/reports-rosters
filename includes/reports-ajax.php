<?php
/**
 * InterSoccer Reports - AJAX Functions
 *
 * @package InterSoccerReports
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filter report callback
 */
function intersoccer_filter_report_callback() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'intersoccer_reports_nonce')) {
        wp_die(__('Security check failed', 'intersoccer-reports-rosters'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'intersoccer-reports-rosters'));
    }

    $start_date = sanitize_text_field($_POST['start_date']);
    $end_date = sanitize_text_field($_POST['end_date']);
    $activity_type = sanitize_text_field($_POST['activity_type']);
    $venue = sanitize_text_field($_POST['venue']);
    $canton = sanitize_text_field($_POST['canton']);

    // Include the data processing file
    require_once plugin_dir_path(__FILE__) . 'reports-data.php';

    // Generate the filtered report HTML
    ob_start();
    intersoccer_display_booking_report($start_date, $end_date, $activity_type, $venue, $canton);
    $html = ob_get_clean();

    wp_send_json_success(array('html' => $html));
}

/**
 * Export booking report callback
 */
function intersoccer_export_booking_report_callback() {
    // Include the export file
    require_once plugin_dir_path(__FILE__) . 'reports-export.php';
    intersoccer_export_booking_report_callback();
}