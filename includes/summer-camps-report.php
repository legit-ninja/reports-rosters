<?php
/**
 * Redirect for Summer Camps Report to the tabbed Reports page.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.0
 * @author Generated Solution
 */

if (!defined('ABSPATH')) {
    die('Restricted access');
}

// Define the render function for the admin menu callback
function intersoccer_render_summer_camps_report_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $year = isset($_GET['year']) ? sanitize_text_field($_GET['year']) : date('Y');
    wp_redirect(add_query_arg(['page' => 'intersoccer-reports', 'tab' => 'summer-camps', 'year' => $year], admin_url('admin.php')));
    exit;
}

// Register the admin menu page (moved here to avoid global inclusion)
add_action('admin_menu', function() {
    add_submenu_page(
        'intersoccer-reports-rosters',
        __('Summer Camps Report', 'intersoccer-reports-rosters'),
        __('Summer Camps Report', 'intersoccer-reports-rosters'),
        'manage_options',
        'intersoccer-summer-camps-report',
        'intersoccer_render_summer_camps_report_page'
    );
});
?>
