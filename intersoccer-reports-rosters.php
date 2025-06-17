<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: Generates event rosters and reports for InterSoccer Switzerland admins using WooCommerce data.
 * Version: 1.3.43
 * Author: Jeremy Lee
 * Text Domain: intersoccer-reports-rosters
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

defined('ABSPATH') or die('Restricted access');

error_log('InterSoccer: Loading intersoccer-reports-rosters.php at ' . current_time('mysql'));

add_filter('load_textdomain_mofile', function ($mofile, $domain) {
    if (($domain === 'woocommerce' || $domain === 'woocommerce-products-filter') && !did_action('init')) {
        return '';
    }
    return $mofile;
}, 10, 2);

add_filter('deprecated_function_trigger_error', '__return_false', 10, 2);

register_activation_hook(__FILE__, 'intersoccer_activate_plugin');

function intersoccer_activate_plugin() {
    try {
        global $wpdb;
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $charset_collate = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $sql = "CREATE TABLE $rosters_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_item_id BIGINT(20) NOT NULL,
            variation_id BIGINT(20) DEFAULT NULL,
            player_name VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            age INT DEFAULT NULL,
            gender VARCHAR(20) DEFAULT 'N/A',
            booking_type VARCHAR(50) NOT NULL,
            selected_days TEXT,
            camp_terms VARCHAR(100) DEFAULT NULL,
            venue VARCHAR(100) NOT NULL,
            parent_phone VARCHAR(20) DEFAULT 'N/A',
            parent_email VARCHAR(100) DEFAULT 'N/A',
            medical_conditions TEXT,
            late_pickup VARCHAR(10) DEFAULT 'No',
            day_presence TEXT,
            age_group VARCHAR(50) DEFAULT 'N/A',
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            event_dates VARCHAR(100) DEFAULT 'N/A',
            product_name VARCHAR(255) NOT NULL,
            activity_type VARCHAR(100) NOT NULL DEFAULT 'Unknown',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_order_item_id (order_item_id),
            INDEX idx_player_name (player_name),
            INDEX idx_venue (venue),
            INDEX idx_activity_type (activity_type(50)),
            INDEX idx_start_date (start_date),
            INDEX idx_variation_id (variation_id)
        ) $charset_collate;";
        $result = dbDelta($sql);
        if (is_wp_error($result)) {
            error_log('InterSoccer: dbDelta failed during activation: ' . $result->get_error_message());
            wp_die(__('Table creation failed. Check debug.log for details.', 'intersoccer-reports-rosters'), __('Plugin Activation Error', 'intersoccer-reports-rosters'), ['back_link' => true]);
        }
        error_log('InterSoccer: Table ' . $rosters_table . ' created or verified during activation with utf8mb4 encoding');
        intersoccer_rebuild_rosters_and_reports();
    } catch (Exception $e) {
        error_log('InterSoccer: Activation error: ' . $e->getMessage());
        wp_die(__('Activation failed. Check logs.', 'intersoccer-reports-rosters'), __('Plugin Activation Error', 'intersoccer-reports-rosters'), ['back_link' => true]);
    }
}

$included_files = [];
$files_to_include = ['utils.php', 'rosters.php', 'roster-data.php', 'roster-details.php', 'roster-export.php', 'advanced.php', 'ajax-handlers.php'];
foreach ($files_to_include as $file) {
    $file_path = plugin_dir_path(__FILE__) . 'includes/' . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
        $included_files[$file] = true;
        error_log("InterSoccer: Successfully included includes/$file from $file_path");
    } else {
        error_log("InterSoccer: Failed to include includes/$file - File not found at $file_path");
    }
}

// Temporary placeholder function to prevent fatal error
if (!function_exists('intersoccer_render_plugin_overview_page')) {
    function intersoccer_render_plugin_overview_page() {
        echo '<div class="wrap"><h1>InterSoccer Reports and Rosters - Overview (Placeholder)</h1><p>This page is temporarily unavailable. Please check the plugin configuration or contact support.</p></div>';
        error_log('InterSoccer: Using temporary placeholder for intersoccer_render_plugin_overview_page');
    }
}

add_action('admin_enqueue_scripts', function ($hook) {
    $screen = get_current_screen();
    if ($screen && in_array($screen->id, ['toplevel_page_intersoccer-reports-rosters', 'intersoccer-reports-rosters_page_intersoccer-rosters', 'intersoccer-reports-rosters_page_intersoccer-roster-details', 'intersoccer-reports-rosters_page_intersoccer-advanced', 'intersoccer-reports-rosters_page_intersoccer-export-rosters'])) {
        wp_enqueue_style('intersoccer-reports-rosters-css', plugin_dir_url(__FILE__) . 'css/reports-rosters.css', [], '1.0.6');
        if ($screen->id === 'toplevel_page_intersoccer-reports-rosters') {
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
            wp_enqueue_script('intersoccer-overview-charts', plugin_dir_url(__FILE__) . 'js/overview-charts.js', ['chart-js'], '1.0.6', true);
        }
        if (in_array($screen->id, ['intersoccer-reports-rosters_page_intersoccer-rosters', 'intersoccer-reports-rosters_page_intersoccer-roster-details'])) {
            wp_enqueue_script('intersoccer-rosters-tabs', plugin_dir_url(__FILE__) . 'js/rosters-tabs.js', ['jquery'], '1.0.6', true);
        }
        if ($screen->id === 'intersoccer-reports-rosters_page_intersoccer-advanced') {
            wp_enqueue_script('intersoccer-advanced-ajax', plugin_dir_url(__FILE__) . 'js/advanced-ajax.js', ['jquery'], '1.0.6', true);
            wp_localize_script('intersoccer-advanced-ajax', 'intersoccer_ajax', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('intersoccer_rebuild_nonce')]);
        }
        if ($screen->id === 'intersoccer-reports-rosters_page_intersoccer-export-rosters') {
            wp_enqueue_script('intersoccer-export-ajax', plugin_dir_url(__FILE__) . 'js/export-ajax.js', ['jquery'], '1.0.6', true);
            wp_localize_script('intersoccer-export-ajax', 'intersoccer_export_ajax', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('intersoccer_export_nonce')]);
        }
    }
});

add_action('admin_menu', function () {
    add_menu_page(__('InterSoccer Reports and Rosters', 'intersoccer-reports-rosters'), __('Reports and Rosters', 'intersoccer-reports-rosters'), 'read', 'intersoccer-reports-rosters', 'intersoccer_render_plugin_overview_page', 'dashicons-chart-bar', 30);
    add_submenu_page('intersoccer-reports-rosters', __('InterSoccer Overview', 'intersoccer-reports-rosters'), __('Overview', 'intersoccer-reports-rosters'), 'read', 'intersoccer-reports-rosters', 'intersoccer_render_plugin_overview_page');
    add_submenu_page('intersoccer-reports-rosters', __('InterSoccer Reports', 'intersoccer-reports-rosters'), __('Reports', 'intersoccer-reports-rosters'), 'read', 'intersoccer-reports', 'intersoccer_render_reports_page');
    add_submenu_page('intersoccer-reports-rosters', __('All Rosters', 'intersoccer-reports-rosters'), __('All Rosters', 'intersoccer-reports-rosters'), 'read', 'intersoccer-all-rosters', 'intersoccer_render_all_rosters_page');
    add_submenu_page('intersoccer-reports-rosters', __('Camps', 'intersoccer-reports-rosters'), __('Camps', 'intersoccer-reports-rosters'), 'read', 'intersoccer-camps', 'intersoccer_render_camps_page');
    add_submenu_page('intersoccer-reports-rosters', __('Courses', 'intersoccer-reports-rosters'), __('Courses', 'intersoccer-reports-rosters'), 'read', 'intersoccer-courses', 'intersoccer_render_courses_page');
    add_submenu_page('intersoccer-reports-rosters', __('Girls Only', 'intersoccer-reports-rosters'), __('Girls Only', 'intersoccer-reports-rosters'), 'read', 'intersoccer-girls-only', 'intersoccer_render_girls_only_page');
    add_submenu_page('intersoccer-reports-rosters', __('Other Events', 'intersoccer-reports-rosters'), __('Other Events', 'intersoccer-reports-rosters'), 'read', 'intersoccer-other-events', 'intersoccer_render_other_events_page');
    add_submenu_page('intersoccer-reports-rosters', __('InterSoccer Advanced', 'intersoccer-reports-rosters'), __('Advanced', 'intersoccer-reports-rosters'), 'read', 'intersoccer-advanced', 'intersoccer_render_advanced_page');
    add_submenu_page('intersoccer-reports-rosters', __('Roster Details', 'intersoccer-reports-rosters'), __('Roster Details', 'intersoccer-reports-rosters'), 'read', 'intersoccer-roster-details', 'intersoccer_render_roster_details_page');
});

add_action('wp_ajax_intersoccer_upgrade_database', 'intersoccer_upgrade_database');
add_action('wp_ajax_intersoccer_rebuild_rosters_and_reports', 'intersoccer_rebuild_rosters_and_reports');
?>
