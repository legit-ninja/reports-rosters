<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: Generates event rosters and reports for InterSoccer Switzerland admins using WooCommerce data.
 * Version: 1.4.42
 * Author: Jeremy Lee
 * Text Domain: intersoccer-reports-rosters
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

defined('ABSPATH') or die('Restricted access');

error_log('InterSoccer: Loading intersoccer-reports-rosters.php at ' . current_time('mysql'));

// add_filter('load_textdomain_mofile', function ($mofile, $domain) {
//     if (($domain === 'woocommerce' || $domain === 'woocommerce-products-filter') && !did_action('init')) {
//         return '';
//     }
//     return $mofile;
// }, 10, 2);

add_filter('deprecated_function_trigger_error', '__return_false', 10, 2);

// Activation hook
function intersoccer_report_roster_plugin_activation() {
    error_log('InterSoccer: Plugin activation started.');

    // Check dependencies
    $dependencies = [
        'woocommerce/woocommerce.php' => 'WooCommerce',
        'intersoccer-product-variations/intersoccer-product-variations.php' => 'Product Variations',
        'player-management/player-management.php' => 'Player Management',
    ];
    $missing = [];
    foreach ($dependencies as $plugin => $name) {
        if (!is_plugin_active($plugin)) {
            $missing[] = $name;
            error_log("InterSoccer: Missing dependency - $name ($plugin) not active.");
        } else {
            error_log("InterSoccer: Dependency check passed - $name active.");
        }
    }

    if (!empty($missing)) {
        add_action('admin_notices', 'intersoccer_missing_dependencies_notice');
        deactivate_plugins(plugin_basename(__FILE__)); // Deactivate if critical deps missing
        error_log('InterSoccer: Plugin deactivated due to missing dependencies: ' . implode(', ', $missing));
        return; // Skip DB ops
    }

    // Proceed to DB validation
    intersoccer_create_rosters_table(); // Create if missing
    intersoccer_validate_rosters_table(); // Validate schema

    error_log('InterSoccer: Plugin activation completed.');
}
register_activation_hook(__FILE__, 'intersoccer_report_roster_plugin_activation');

/**
 * Admin notice for missing dependencies.
 */
function intersoccer_missing_dependencies_notice() {
    global $missing; // Assume set in activation or use transient
    ?>
    <div class="notice notice-error">
        <p><?php printf(__('InterSoccer Reports & Rosters requires the following plugins to be active: %s. Plugin deactivated.', 'intersoccer-reports-rosters'), implode(', ', $missing)); ?></p>
    </div>
    <?php
}

$included_files = [];
$files_to_include = ['event-reports.php', 'reports.php', 'utils.php', 'rosters.php', 'roster-data.php', 'roster-details.php', 'roster-export.php', 'advanced.php', 'woocommerce-orders.php', 'db.php']; 
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

/**
 * Render the plugin overview page with charts and statistics.
 */
function intersoccer_render_plugin_overview_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Fetch data for charts
    $current_venue_data = $wpdb->get_results("SELECT venue, COUNT(*) as count FROM $rosters_table WHERE start_date <= CURDATE() AND end_date >= CURDATE() GROUP BY venue", ARRAY_A);
    $region_data = $wpdb->get_results("SELECT venue, COUNT(*) as count FROM $rosters_table GROUP BY venue", ARRAY_A);
    $age_data = $wpdb->get_results("SELECT age_group, COUNT(*) as count FROM $rosters_table WHERE age_group != 'N/A' GROUP BY age_group", ARRAY_A);
    $gender_data = $wpdb->get_results("SELECT gender, COUNT(*) as count FROM $rosters_table WHERE gender != 'N/A' GROUP BY gender", ARRAY_A);
    $weekly_trends = $wpdb->get_results("SELECT DATE(start_date) as week_start, COUNT(*) as count FROM $rosters_table WHERE start_date IS NOT NULL GROUP BY DATE(start_date) ORDER BY start_date", ARRAY_A);

    // Manually order: male, female, other
    $ordered_genders = ['male', 'female', 'other'];
    $ordered_gender_labels = [];
    $ordered_gender_values = [];

    foreach ($ordered_genders as $gender) {
        $found = false;
        foreach ($gender_data as $entry) {
            if (strtolower($entry['gender']) === $gender) {
                $ordered_gender_labels[] = ucfirst($gender); // Capitalize for display
                $ordered_gender_values[] = $entry['count'];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $ordered_gender_labels[] = ucfirst($gender);
            $ordered_gender_values[] = 0;
        }
    }

    $current_venue_labels = json_encode(array_column($current_venue_data, 'venue'));
    $current_venue_values = json_encode(array_column($current_venue_data, 'count'));
    $region_labels = json_encode(array_column($region_data, 'venue'));
    $region_values = json_encode(array_column($region_data, 'count'));
    $age_labels = json_encode(array_column($age_data, 'age_group'));
    $age_values = json_encode(array_column($age_data, 'count'));
    $gender_labels = json_encode($ordered_gender_labels);
    $gender_values = json_encode($ordered_gender_values);
    $weekly_labels = json_encode(array_column($weekly_trends, 'week_start'));
    $weekly_values = json_encode(array_column($weekly_trends, 'count'));

    ?>
    <div class="wrap">
        <h1><?php _e('InterSoccer Reports and Rosters - Overview', 'intersoccer-reports-rosters'); ?></h1>

        <div class="chart-container">
            <div class="chart-box">
                <h2><?php _e('Current Attendance by Venue', 'intersoccer-reports-rosters'); ?></h2>
                <canvas id="currentVenueChart" width="400" height="200"></canvas>
                <script>
                    var currentVenueChartData = {
                        labels: <?php echo $current_venue_labels; ?>,
                        values: <?php echo $current_venue_values; ?>
                    };
                </script>
            </div>

            <div class="chart-box">
                <h2><?php _e('Attendees by Region', 'intersoccer-reports-rosters'); ?></h2>
                <canvas id="regionChart" width="400" height="200"></canvas>
                <script>
                    var regionChartData = {
                        labels: <?php echo $region_labels; ?>,
                        values: <?php echo $region_values; ?>
                    };
                </script>
            </div>

            <div class="chart-box">
                <h2><?php _e('Age Distribution', 'intersoccer-reports-rosters'); ?></h2>
                <canvas id="ageChart" width="400" height="200"></canvas>
                <script>
                    var ageChartData = {
                        labels: <?php echo $age_labels; ?>,
                        values: <?php echo $age_values; ?>
                    };
                </script>
            </div>

            <div class="chart-box">
                <h2><?php _e('Gender Distribution', 'intersoccer-reports-rosters'); ?></h2>
                <canvas id="genderChart" width="400" height="200"></canvas>
                <script>
                    var genderChartData = {
                        labels: <?php echo $gender_labels; ?>,
                        values: <?php echo $gender_values; ?>
                    };
                </script>
            </div>

            <div class="chart-box">
                <h2><?php _e('Weekly Attendance Trends', 'intersoccer-reports-rosters'); ?></h2>
                <canvas id="weeklyTrendsChart" width="400" height="200"></canvas>
                <script>
                    var weeklyTrendsChartData = {
                        labels: <?php echo $weekly_labels; ?>,
                        values: <?php echo $weekly_values; ?>
                    };
                </script>
            </div>
        </div>
    </div>
    <?php
    error_log('InterSoccer: Rendered Overview page with charts');
}

add_action('admin_enqueue_scripts', function ($hook) {
    $screen = get_current_screen();
    error_log('Enqueuing scripts for screen ID: ' . ($screen ? $screen->id : 'No screen'));
    if ($screen) {
        $roster_pages = [
            'intersoccer-reports-rosters_page_intersoccer-all-rosters',
            'intersoccer-reports-rosters_page_intersoccer-camps',
            'intersoccer-reports-rosters_page_intersoccer-courses',
            'intersoccer-reports-rosters_page_intersoccer-girls-only',
            'intersoccer-reports-rosters_page_intersoccer-other-events'
        ];
        wp_enqueue_style('intersoccer-reports-rosters-css', plugin_dir_url(__FILE__) . 'css/reports-rosters.css', [], '1.0.6');
        if ($screen->id === 'toplevel_page_intersoccer-reports-rosters') {
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
            wp_enqueue_script('intersoccer-overview-charts', plugin_dir_url(__FILE__) . 'js/overview-charts.js', ['chart-js'], '1.0.6', true);
        }
        if (in_array($screen->id, $roster_pages)) {
            $script_path = plugin_dir_path(__FILE__) . 'js/rosters-tabs.js';
            error_log('Attempting to enqueue rosters-tabs.js from: ' . $script_path);
            if (file_exists($script_path)) {
                wp_enqueue_script('intersoccer-rosters-tabs', plugin_dir_url(__FILE__) . 'js/rosters-tabs.js', ['jquery'], '1.0.6', true);
                wp_localize_script('intersoccer-rosters-tabs', 'intersoccer_ajax', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('intersoccer_reports_rosters_nonce')
                ]);
                error_log('rosters-tabs.js enqueued successfully for screen ID: ' . $screen->id);
            } else {
                error_log('rosters-tabs.js not found at: ' . $script_path);
            }
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
    add_submenu_page('intersoccer-reports-rosters', __('Report Details', 'intersoccer-reports-rosters'), __('Roster Details', 'intersoccer-reports-rosters'),'manage_options','intersoccer-roster-details','intersoccer_render_roster_details_page');
});

add_action('wp_ajax_intersoccer_upgrade_database', 'intersoccer_upgrade_database');
add_action('wp_ajax_intersoccer_rebuild_rosters_and_reports', 'intersoccer_rebuild_rosters_and_reports');
?>
