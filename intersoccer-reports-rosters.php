<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: Generates event rosters and reports for InterSoccer Switzerland admins using WooCommerce data.
 * Version: 2.1.27
 * Author: Jeremy Lee
 * Text Domain: intersoccer-reports-rosters
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 5.0
 * Tested up to: 6.6
 * Requires PHP: 7.4
 */

defined('ABSPATH') or die('Restricted access');

if (defined('INTERSOCCER_REPORTS_ROSTERS_LOADED')) {
    return; // Prevent duplicate loading
}
define('INTERSOCCER_REPORTS_ROSTERS_LOADED', true);

add_filter('deprecated_function_trigger_error', '__return_false', 10, 2);

// ============================================================================
// OOP BOOTSTRAP (OOP-only)
// ============================================================================

$autoloader = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    add_action('admin_notices', function () use ($autoloader) {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                __('InterSoccer Reports & Rosters is missing its Composer autoloader at %s. Please deploy the plugin with vendor/ included.', 'intersoccer-reports-rosters'),
                $autoloader
            ))
        );
    });
    add_action('admin_menu', function () use ($autoloader) {
        add_menu_page(
            __('InterSoccer Reports and Rosters', 'intersoccer-reports-rosters'),
            __('Reports and Rosters', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-reports-rosters',
            function () use ($autoloader) {
                echo '<div class="wrap"><h1>' . esc_html__('Reports and Rosters', 'intersoccer-reports-rosters') . '</h1>';
                echo '<div class="notice notice-error"><p>' . esc_html(sprintf(
                    __('InterSoccer Reports & Rosters is missing its Composer autoloader at %s. Please deploy the plugin with vendor/ included.', 'intersoccer-reports-rosters'),
                    $autoloader
                )) . '</p></div></div>';
            },
            'dashicons-chart-bar',
            30
        );
    }, 9);
    return;
}

require_once $autoloader;

if (!defined('INTERSOCCER_ORDER_AUTO_COMPLETE_CRON_HOOK')) {
    define('INTERSOCCER_ORDER_AUTO_COMPLETE_CRON_HOOK', 'intersoccer_auto_complete_orders');
}

if (!defined('INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK')) {
    define('INTERSOCCER_ORDER_AUTO_COMPLETE_SINGLE_HOOK', 'intersoccer_auto_complete_order_single');
}

if (!defined('INTERSOCCER_ORDER_AUTO_COMPLETE_RECURRENCE')) {
    define('INTERSOCCER_ORDER_AUTO_COMPLETE_RECURRENCE', 'intersoccer_auto_complete_orders_interval');
}

// OOP-only: always active when bootstrap succeeds
if (!defined('INTERSOCCER_OOP_ENABLED')) {
    define('INTERSOCCER_OOP_ENABLED', true);
}

try {
    InterSoccer\ReportsRosters\Core\Plugin::get_instance(__FILE__);
    if (!defined('INTERSOCCER_OOP_ACTIVE')) {
        define('INTERSOCCER_OOP_ACTIVE', true);
    }
} catch (\Throwable $e) {
    if (!defined('INTERSOCCER_OOP_ACTIVE')) {
        define('INTERSOCCER_OOP_ACTIVE', false);
    }
    add_action('admin_notices', function () use ($e) {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                __('InterSoccer Reports & Rosters failed to bootstrap: %s', 'intersoccer-reports-rosters'),
                $e->getMessage()
            ))
        );
    });
    return;
}

// Load OOP adapter/compat layer (kept small and will be further reduced).
$intersoccer_oop_adapter = plugin_dir_path(__FILE__) . 'includes/oop-adapter.php';
if (file_exists($intersoccer_oop_adapter)) {
    require_once $intersoccer_oop_adapter;
}

// Load reports.php on admin_init so AJAX handler is registered for admin-ajax.php requests.
// admin_menu does NOT run for admin-ajax, so we must use admin_init which does.
add_action('admin_init', function () {
    $reports_file = plugin_dir_path(__FILE__) . 'includes/reports.php';
    if (file_exists($reports_file)) {
        require_once $reports_file;
    }
}, 0);

// Register strings for WPML translation on init to ensure WPML is loaded
add_action('init', function () {
    if (function_exists('icl_register_string')) {
        // Day names
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        foreach ($days as $day) {
            icl_register_string('intersoccer-reports-rosters', $day, $day);
        }

        // Common translatable strings
        $strings = [
            'InterSoccer Reports & Rosters',
            'InterSoccer Reports and Rosters - Overview',
            'InterSoccer Overview',
            'Booking Reports',
            'Final Camp Reports',
            'Final Course Reports',
            'All Rosters',
            'Camps',
            'Courses',
            'Girls Only',
            'Tournaments',
            'Other Events',
            'InterSoccer Settings',
            'Settings',
            'Reports and Rosters',
            'Booking Report',
            'Final Numbers',
            'Current Attendance by Venue',
            'Attendees by Region',
            'Age Distribution',
            'Gender Distribution',
            'Weekly Attendance Trends',
            'Roster Details',
            'Roster Details for ',
            'Order Date',
            'Name',
            'Surname',
            'Gender',
            'Phone',
            'Email',
            'Age',
            'Medical/Dietary',
            'Late Pickup',
            'Late Pickup Days',
            'Booking Type',
            'Age Group',
            'Shirt Size',
            'Shorts Size',
            'Event Details',
            'Product Name: ',
            'Venue: ',
            'Age Group: ',
            'Tournament Day: ',
            'Course Day: ',
            'Camp Terms: ',
            'Tournament Time: ',
            'Camp Times: ',
            'Course Times: ',
            'Girls Only: ',
            'Status: ',
            'Closed',
            'Total Players',
            'Permission denied.',
            'You do not have sufficient permissions to access this page.',
            'Invalid parameters provided.',
            'No rosters found for the provided parameters.',
            '%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.',
            '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.',
            'Unknown Event',
            'Tournament Date (pa_date)',
            'General',
            'Advanced',
            'Tools',
            'General Settings',
            'Schema Version',
            'Migration Engine',
            'Database operations and advanced tools are available in the Advanced and Tools tabs.',
            'Database Management',
            'Perform database upgrades or maintenance tasks.',
            'Upgrade Database',
            'This will modify the database structure and backfill data. Are you sure?',
            'Note: This action adds new columns (e.g., financial fields, girls_only) and backfills data. Use with caution.',
            'Roster Management',
            'Process Orders',
            'Note: This will populate missing rosters for existing orders (e.g., processing or on-hold) and complete them if fully populated.',
            'Reconcile Rosters',
            'Note: This syncs the rosters table with orders, adding missing entries, updating incomplete data, and removing obsolete ones. No order statuses are changed.',
            'Rebuild Event Signatures',
            'Note: This will regenerate event signatures for all existing rosters to ensure proper grouping across languages.',
            'Rebuild Rosters',
            'Note: This will recreate the rosters table and repopulate it with current order data.',
            'Export Options',
            'Export All Rosters (CSV)',
            'Are you sure you want to rebuild the rosters table? This will delete all existing data in the table, recreate it from current WooCommerce orders. This is a last resort action and may cause temporary data inconsistencies until completed.',
            'Starting: Rebuilding rosters...',
            'Running...',
            'Finished: ',
            'Rosters rebuilt successfully.',
            'Failed: ',
            'Unknown error occurred.',
            'Are you sure you want to reconcile rosters? This will sync data from orders, potentially updating existing entries.',
            'Starting: Reconciling rosters...',
            'Rosters reconciled successfully.',
            'Reconcile Rosters',
            'Are you sure you want to rebuild event signatures? This will regenerate signatures for all roster records to ensure proper grouping.',
            'Starting: Rebuilding event signatures...',
            'Event signatures rebuilt successfully.',
            'Rebuild Event Signatures',
            'Starting: Processing orders...',
            'Orders processed successfully.',
            'Process Orders',
            'OOP Migrator',
            'Legacy Migrator',
        ];

        foreach ($strings as $string) {
            icl_register_string('intersoccer-reports-rosters', $string, $string);
        }
    }
}, 20); // Priority 20 to ensure includes are loaded

/**
 * Render the plugin overview page with charts and statistics.
 */
function intersoccer_render_plugin_overview_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Fetch data for charts (excluding placeholders if column exists)
    $filter = function_exists('intersoccer_roster_placeholder_where') ? intersoccer_roster_placeholder_where() : '';
    $current_venue_data = $wpdb->get_results("SELECT venue, COUNT(*) as count FROM $rosters_table WHERE start_date <= CURDATE() AND end_date >= CURDATE(){$filter} GROUP BY venue", ARRAY_A);
    $region_data = $wpdb->get_results("SELECT venue, COUNT(*) as count FROM $rosters_table WHERE 1=1{$filter} GROUP BY venue", ARRAY_A);
    $age_data = $wpdb->get_results("SELECT age_group, COUNT(*) as count FROM $rosters_table WHERE age_group != 'N/A'{$filter} GROUP BY age_group", ARRAY_A);
    $gender_data = $wpdb->get_results("SELECT gender, COUNT(*) as count FROM $rosters_table WHERE gender != 'N/A'{$filter} GROUP BY gender", ARRAY_A);
    $weekly_trends = $wpdb->get_results("SELECT DATE(start_date) as week_start, COUNT(*) as count FROM $rosters_table WHERE start_date IS NOT NULL{$filter} GROUP BY DATE(start_date) ORDER BY start_date", ARRAY_A);

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
}
