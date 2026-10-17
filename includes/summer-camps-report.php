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

/**
 * Redirect before admin headers are sent (load-{page_hook}).
 */
function intersoccer_maybe_redirect_summer_camps_report_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $year = isset($_GET['year']) ? sanitize_text_field(wp_unslash($_GET['year'])) : gmdate('Y');
    wp_safe_redirect(add_query_arg(
        [
            'page' => 'intersoccer-reports',
            'tab'  => 'summer-camps',
            'year' => $year,
        ],
        admin_url('admin.php')
    ));
    exit;
}

/**
 * Menu callback (redirect runs on load-{page_hook}).
 */
function intersoccer_render_summer_camps_report_page() {
}

add_action('admin_menu', function () {
    $page_hook = add_submenu_page(
        'intersoccer-reports-rosters',
        __('Summer Camps Report', 'intersoccer-reports-rosters'),
        __('Summer Camps Report', 'intersoccer-reports-rosters'),
        'manage_options',
        'intersoccer-summer-camps-report',
        'intersoccer_render_summer_camps_report_page'
    );
    if (is_string($page_hook) && $page_hook !== '') {
        add_action('load-' . $page_hook, 'intersoccer_maybe_redirect_summer_camps_report_page');
    }
});
