<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: Generates event rosters and reports for InterSoccer Switzerland admins using WooCommerce data.
 * Version: 1.3.0
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

// Ensure table creation and initial population on activation
register_activation_hook(__FILE__, 'intersoccer_activate_plugin');

function intersoccer_activate_plugin() {
    try {
        global $wpdb;
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        $charset_collate = $wpdb->get_charset_collate();
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $sql = "CREATE TABLE $rosters_table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_item_id BIGINT(20) NOT NULL,
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
            activity_type VARCHAR(100) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_order_item_id (order_item_id),
            INDEX idx_player_name (player_name),
            INDEX idx_venue (venue),
            INDEX idx_activity_type (activity_type),
            INDEX idx_start_date (start_date)
        ) $charset_collate;";
        $result = dbDelta($sql);
        if (is_wp_error($result)) {
            error_log('InterSoccer: dbDelta failed during activation: ' . $result->get_error_message());
            wp_die(__('Table creation failed. Check debug.log for details.', 'intersoccer-reports-rosters'), __('Plugin Activation Error', 'intersoccer-reports-rosters'), ['back_link' => true]);
        }
        error_log('InterSoccer: Table ' . $rosters_table . ' created or verified during activation with unique constraint');
        intersoccer_rebuild_rosters(); // Populate immediately
    } catch (Exception $e) {
        error_log('InterSoccer: Activation error: ' . $e->getMessage());
        wp_die(__('Activation failed. Check logs.', 'intersoccer-reports-rosters'), __('Plugin Activation Error', 'intersoccer-reports-rosters'), ['back_link' => true]);
    }
}

$included_files = [];
$files_to_include = ['utils.php', 'rosters.php', 'roster-data.php', 'roster-details.php', 'roster-export.php', 'advanced.php'];
foreach ($files_to_include as $file) {
    $file_path = plugin_dir_path(__FILE__) . 'includes/' . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
        $included_files[$file] = true;
        error_log('InterSoccer: Included includes/' . $file);
    } else {
        error_log('InterSoccer: Missing includes/' . $file);
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
});

function intersoccer_log_audit($action, $details) {
    global $wpdb;
    $user_id = get_current_user_id();
    $table = $wpdb->prefix . 'intersoccer_audit';
    $wpdb->insert($table, ['user_id' => $user_id, 'action' => sanitize_text_field($action), 'details' => wp_kses_post($details), 'timestamp' => current_time('mysql')], ['%d', '%s', '%s', '%s']);
    error_log("InterSoccer: Audit - $action, Details: $details, User ID: $user_id");
}

function intersoccer_orders_need_migration() {
    $args = ['post_type' => 'shop_order', 'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'], 'posts_per_page' => -1];
    $orders = get_posts($args);
    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if (!$order) continue;
        foreach ($order->get_items() as $item) {
            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
            if (!$player_name || !wc_get_order_item_meta($item->get_id(), 'Player Age', true) || !wc_get_order_item_meta($item->get_id(), 'Player Gender', true) || !wc_get_order_item_meta($item->get_id(), 'Medical Conditions', true) || !wc_get_order_item_meta($item->get_id(), 'Late Pickup', true)) {
                return true;
            }
        }
    }
    return false;
}

function intersoccer_diagnose_assigned_player_metadata() {
    $args = ['post_type' => 'shop_order', 'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'], 'posts_per_page' => -1];
    $orders = get_posts($args);
    $total = $missing = $needs_restore = 0;
    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if (!$order) continue;
        $total++;
        $has_player = false;
        foreach ($order->get_items() as $item) {
            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true) ?: wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
            if ($player_name) $has_player = true;
            if (!$player_name && wc_get_order_item_meta($item->get_id(), 'player_assignment', true)) $needs_restore++;
        }
        if (!$has_player) $missing++;
    }
    return ['total' => $total, 'missing' => $missing, 'needs_restore' => $needs_restore, 'migration_needed' => $missing > 0 || $needs_restore > 0];
}

function intersoccer_get_chart_data() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $data = ['age_groups' => [], 'genders' => [], 'weekly_trends' => [], 'current_attendance_by_venue' => []];
    $data['genders'] = array_column($wpdb->get_results("SELECT gender, COUNT(*) as count FROM $rosters_table WHERE gender IN ('Male', 'Female', 'Other') GROUP BY gender", ARRAY_A), 'count', 'gender');
    $data['age_groups'] = array_column($wpdb->get_results("SELECT CASE WHEN age BETWEEN 2 AND 5 THEN '2-5' WHEN age BETWEEN 6 AND 9 THEN '6-9' WHEN age BETWEEN 10 AND 13 THEN '10-13' ELSE 'Unknown' END as age_group, COUNT(*) as count FROM $rosters_table WHERE age IS NOT NULL GROUP BY age_group", ARRAY_A), 'count', 'age_group');
    $data['weekly_trends'] = array_column($wpdb->get_results("SELECT DATE(updated_at) as week_start, COUNT(*) as count FROM $rosters_table WHERE updated_at >= DATE_SUB(CURRENT_DATE, INTERVAL 12 WEEK) GROUP BY week_start ORDER BY week_start DESC LIMIT 12", ARRAY_A), 'count', 'week_start');
    $data['current_attendance_by_venue'] = array_column($wpdb->get_results("SELECT venue, COUNT(*) as count FROM $rosters_table WHERE start_date <= CURRENT_DATE AND (end_date >= CURRENT_DATE OR end_date IS NULL) GROUP BY venue", ARRAY_A), 'count', 'venue');
    return array_map('array_filter', $data);
}

function intersoccer_render_plugin_overview_page() {
    if (!current_user_can('manage_options')) wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    intersoccer_log_audit('view_overview', 'Accessed Overview');
    $chart_data = intersoccer_get_chart_data();
    ?>
    <div class="wrap intersoccer-reports-rosters-dashboard">
        <h1><?php _e('InterSoccer Rosters - Overview', 'intersoccer-reports-rosters'); ?></h1>
        <div style="display: flex; gap: 20px;">
            <div><canvas id="ageChart"></canvas></div>
            <div><canvas id="genderChart"></canvas></div>
            <div><canvas id="weeklyTrendsChart"></canvas></div>
            <div><canvas id="currentVenueChart"></canvas></div>
        </div>
    </div>
    <script>
        var ctx = document.getElementById('ageChart').getContext('2d');
        new Chart(ctx, {type: 'bar', data: {labels: Object.keys(<?php echo json_encode($chart_data['age_groups']); ?>), datasets: [{label: 'Age Groups', data: Object.values(<?php echo json_encode($chart_data['age_groups']); ?>)}]}});
        var ctx = document.getElementById('genderChart').getContext('2d');
        new Chart(ctx, {type: 'pie', data: {labels: Object.keys(<?php echo json_encode($chart_data['genders']); ?>), datasets: [{data: Object.values(<?php echo json_encode($chart_data['genders']); ?>)}]}});
        var ctx = document.getElementById('weeklyTrendsChart').getContext('2d');
        new Chart(ctx, {type: 'line', data: {labels: Object.keys(<?php echo json_encode($chart_data['weekly_trends']); ?>), datasets: [{label: 'Weekly Trends', data: Object.values(<?php echo json_encode($chart_data['weekly_trends']); ?>)}]}});
        var ctx = document.getElementById('currentVenueChart').getContext('2d');
        new Chart(ctx, {type: 'bar', data: {labels: Object.keys(<?php echo json_encode($chart_data['current_attendance_by_venue']); ?>), datasets: [{label: 'Venues', data: Object.values(<?php echo json_encode($chart_data['current_attendance_by_venue']); ?>)}]}});
    </script>
    <?php
}

function intersoccer_render_export_page() {
    if (!current_user_can('manage_options')) wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    intersoccer_log_audit('view_export', 'Accessed Export Rosters');
    ?>
    <div class="wrap intersoccer-export-rosters">
        <h1><?php _e('Export Rosters', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php _e('Use the Rosters page for direct exports.', 'intersoccer-reports-rosters'); ?></p>
    </div>
    <?php
}

add_action('wp_ajax_intersoccer_export_all_rosters', function () {
    check_ajax_referer('intersoccer_export_nonce', 'nonce');
    $export_type = sanitize_text_field($_POST['export_type'] ?? 'all');
    $format = sanitize_text_field($_POST['format'] ?? 'excel');
    $debug_user = sanitize_text_field($_POST['debug_user'] ?? 'unknown');
    error_log('InterSoccer: Export initiated by user ' . $debug_user . ' for type ' . $export_type . ' in format ' . $format);

    $filters = ['show_no_attendees' => '1'];
    $camp_variations = intersoccer_pe_get_camp_variations($filters);
    $course_variations = intersoccer_pe_get_course_variations($filters);
    $girls_only_variations = intersoccer_pe_get_girls_only_variations($filters);
    intersoccer_export_all_rosters($camp_variations, $course_variations, $girls_only_variations, $export_type, $format);
    wp_die();
});

/**
 * Automatically update rosters when an order transitions from "Processing" to "Complete".
 *
 * @param int $order_id The ID of the order.
 * @param string $old_status The previous status of the order.
 * @param string $new_status The new status of the order.
 * @param WC_Order $order The order object.
 */
function intersoccer_auto_add_to_rosters($order_id, $old_status, $new_status, $order) {
    if ($old_status === 'processing' && $new_status === 'completed') {
        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $items = $order->get_items();
        foreach ($items as $item) {
            $order_item_id = $item->get_id();
            $product = $item->get_product();
            if (!$product) {
                error_log('InterSoccer: Skipping invalid product for order ' . $order_id . ', item ' . $order_item_id);
                continue;
            }
            $order_item_meta = $item->get_meta_data() ? array_column(array_map('get_object_vars', $item->get_meta_data()), 'value', 'key') : [];
            $product_id = $product->get_id();
            $variation_id = $item->get_variation_id() ?: $product_id;
            $parent_product = wc_get_product($product_id);

            $player_assignment = wc_get_order_item_meta($order_item_id, 'assigned_player', true);
            $assigned_attendee = wc_get_order_item_meta($order_item_id, 'Assigned Attendee', true);
            if (!$assigned_attendee && $player_assignment !== false && $player_assignment !== '' && is_numeric($player_assignment)) {
                $user_id = $order->get_user_id();
                $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
                $assigned_attendee = isset($players[$player_assignment]) ? trim($players[$player_assignment]['first_name'] . ' ' . $players[$player_assignment]['last_name']) : $assigned_attendee;
                error_log('InterSoccer: Resolved Assigned Attendee from assigned_player ' . $player_assignment . ': ' . $assigned_attendee);
            }
            if (!$assigned_attendee) {
                $assigned_attendee = 'Unknown Attendee';
                error_log('InterSoccer: No Assigned Attendee or valid assigned_player for order ' . $order_id . ', item ' . $order_item_id);
            }
            $player_name_parts = explode(' ', $assigned_attendee, 2);
            $first_name = !empty($player_name_parts[0]) ? $player_name_parts[0] : 'Unknown';
            $last_name = !empty($player_name_parts[1]) ? $player_name_parts[1] : 'Unknown';

            $booking_type = $order_item_meta['Booking Type'] ?? $order_item_meta['pa_booking-type'] ?? (wc_get_product_terms($variation_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown');
            $selected_days = $order_item_meta['Days Selected'];
            $camp_terms = $order_item_meta['Camp Terms'] ?? $order_item_meta['pa_camp-terms'] ?? (wc_get_product_terms($variation_id, 'pa_camp-terms', ['fields' => 'names'])[0] ?? 'N/A');
            $venue = $order_item_meta['InterSoccer Venues'] ?? $order_item_meta['pa_intersoccer-venues'] ?? 'Unknown Venue';
            $age_group = $order_item_meta['Age Group'] ?? $order_item_meta['pa_age-group'] ?? (wc_get_product_terms($variation_id, 'pa_age-group', ['fields' => 'names'])[0] ?? 'N/A');
            $start_date = DateTime::createFromFormat('m/d/Y', $order_item_meta['Start Date'] ?? '') ? 
                        DateTime::createFromFormat('m/d/Y', $order_item_meta['Start Date'])->format('Y-m-d') : 
                        (DateTime::createFromFormat('Y-m-d', $order_item_meta['Start Date'] ?? '') ? DateTime::createFromFormat('Y-m-d', $order_item_meta['Start Date'])->format('Y-m-d') : 'N/A');
            $end_date = DateTime::createFromFormat('m/d/Y', $order_item_meta['End Date'] ?? '') ? 
                    DateTime::createFromFormat('m/d/Y', $order_item_meta['End Date'])->format('Y-m-d') : 
                    (DateTime::createFromFormat('Y-m-d', $order_item_meta['End Date'] ?? '') ? DateTime::createFromFormat('Y-m-d', $order_item_meta['End Date'])->format('Y-m-d') : 'N/A');
            $event_dates = $order_item_meta['pa_event_dates'] ?? $order_item_meta['event-start-date'] . ' to ' . $order_item_meta['event-end-date'] ?? 'N/A';
            $parent_phone = $order->get_billing_phone() ?: 'N/A';
            $parent_email = $order->get_billing_email() ?: 'N/A';
            $medical_conditions = $order_item_meta['Medical Conditions'] ?? $order_item_meta['medical_conditions'] ?? $order_item_meta['Medical/Dietary Conditions'] ?? '';
            $late_pickup = $order_item_meta['Late Pickup'] ?? $order_item_meta['late_pickup'] ?? 'No';

            $day_presence = ['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No'];
            if (strtolower($booking_type) === 'single-days') {
                $days = is_array($selected_days) ? $selected_days : array_map('trim', explode(',', $selected_days));
                foreach ($days as $day) {
                    $day = trim(strtolower($day));
                    if (in_array($day, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'])) {
                        $day_presence[ucfirst($day)] = 'Yes';
                    }
                }
            }

            // Enrich with player meta
            $user_id = $order->get_user_id();
            $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
            $age = null;
            $gender = 'N/A';
            $found_player = false;
            foreach ($players as $index => $player) {
                $player_full_name = trim($player['first_name'] . ' ' . $player['last_name']);
                if (strtolower($player_full_name) === strtolower($assigned_attendee) || (string)$index === $player_assignment) {
                    $first_name = $player['first_name'] ?? $first_name;
                    $last_name = $player['last_name'] ?? $last_name;
                    $age = $player['dob'] ? (new DateTime($player['dob']))->diff(new DateTime())->y : $age;
                    $gender = $player['gender'] ?? $gender;
                    $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                    $found_player = true;
                    break;
                }
            }
            if (!$found_player) {
                $age = (int)$order_item_meta['Player Age'] ?? $age;
                $gender = $order_item_meta['Player Gender'] ?? $order_item_meta['gender'] ?? $gender;
            }

            $product_name = $product->get_name();
            $activity_type_terms = $parent_product ? wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']) : [];
            error_log("InterSoccer: Raw activity type terms for product $product_id: " . print_r($activity_type_terms, true));
            $activity_type = 'Unknown'; // Default to a string
            if (isset($order_item_meta['Activity Type']) && $order_item_meta['Activity Type']) {
                $raw_activity_type = $order_item_meta['Activity Type'];
                error_log("InterSoccer: Raw Activity Type from order meta: $raw_activity_type for order $order_id, item $order_item_id");
                // Use preg_split to handle commas outside quotes
                $activity_types = preg_split('/,(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $raw_activity_type, -1, PREG_SPLIT_NO_EMPTY);
                $activity_types = array_map('trim', $activity_types);
                error_log("InterSoccer: Parsed activity types before normalization: " . print_r($activity_types, true));
                // Forcefully remove apostrophes
                $activity_types = array_map(function($type) {
                    return str_replace("'", '', $type); // Explicitly remove all apostrophes
                }, $activity_types);
                error_log("InterSoccer: Parsed activity types after normalization: " . print_r($activity_types, true));
                $activity_type = implode(', ', array_filter($activity_types)); // Join with filtering out empty values
                error_log("InterSoccer: Assigned activity_type from order meta: $activity_type for order $order_id, item $order_item_id");
            } elseif (!empty($activity_type_terms)) {
                $activity_type = implode(', ', $activity_type_terms);
                error_log("InterSoccer: Assigned activity_type from terms: $activity_type for product $product_id");
            } else {
                $activity_type = (stripos($product_name, 'Camp') !== false ? 'Camp' :
                                (stripos($product_name, 'Course') !== false ? 'Course' :
                                (stripos($product_name, 'Girls') !== false ? 'Girls Only' :
                                (stripos($product_name, 'Birthday') !== false ? 'Event' : 'Other'))));
                error_log("InterSoccer: Assigned activity_type from product name: $activity_type for product $product_id");
            }
            error_log("InterSoccer: Final activity_type before insert for order $order_id, item $order_item_id: $activity_type");

            $roster_entry = [
                'order_item_id' => $order_item_id,
                'player_name' => $assigned_attendee,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'age' => $age,
                'gender' => $gender,
                'booking_type' => $booking_type,
                'selected_days' => $selected_days,
                'camp_terms' => $camp_terms,
                'venue' => $venue,
                'parent_phone' => $parent_phone,
                'parent_email' => $parent_email,
                'medical_conditions' => $medical_conditions,
                'late_pickup' => $late_pickup,
                'day_presence' => json_encode($day_presence),
                'age_group' => $age_group,
                'start_date' => ($start_date === 'N/A' ? null : $start_date),
                'end_date' => ($end_date === 'N/A' ? null : $end_date),
                'event_dates' => $event_dates,
                'product_name' => $product_name,
                'activity_type' => (string)$activity_type,
            ];

            error_log("InterSoccer: Roster entry before insert for order $order_id, item $order_item_id: " . print_r($roster_entry, true));

            // Prepare and log the exact query
            $fields = implode(', ', array_keys($roster_entry));
            $placeholders = implode(', ', array_fill(0, count($roster_entry), '%s')); // Force all as strings
            $query = "INSERT INTO $rosters_table ($fields) VALUES ($placeholders)";
            $prepared_query = $wpdb->prepare($query, array_values($roster_entry));
            error_log("InterSoccer: Prepared query for order $order_id, item $order_item_id: $prepared_query");

            // Perform the insert
            $result = $wpdb->insert($rosters_table, $roster_entry, [
                '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
            ]);
            if ($result === false) {
                error_log("InterSoccer: Insert failed for order $order_id, item $order_item_id: " . $wpdb->last_error . ' - SQL Query: ' . $wpdb->last_query . ' - Entry: ' . print_r($roster_entry, true));
            } else {
                $inserted_id = $wpdb->insert_id;
                $inserted_data = $wpdb->get_row($wpdb->prepare("SELECT activity_type FROM $rosters_table WHERE id = %d", $inserted_id));
                if ($inserted_data) {
                    error_log("InterSoccer: Inserted activity_type for ID $inserted_id: " . $inserted_data->activity_type);
                    if ($inserted_data->activity_type !== $activity_type) {
                        error_log("InterSoccer: Mismatch! Expected $activity_type, got " . $inserted_data->activity_type . ' for order $order_id, item $order_item_id');
                    }
                } else {
                    error_log("InterSoccer: Failed to retrieve inserted data for ID $inserted_id");
                }
                error_log('InterSoccer: Auto-inserted roster for order ' . $order_id . ', item ' . $order_item_id . ' (Activity: ' . $activity_type . ')');
            }
        }
    }
}
add_action('woocommerce_order_status_changed', 'intersoccer_auto_add_to_rosters', 10, 4);

/**
 * Reconcile roster database with current completed orders.
 */
function intersoccer_reconcile_rosters() {
    if (current_user_can('manage_options') || current_user_can('coach')) {
        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

        // Get all completed order item IDs
        $completed_items = $wpdb->get_col(
            "SELECT oi.order_item_id FROM {$wpdb->prefix}woocommerce_order_items oi
             JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id
             WHERE o.status = 'completed' AND oi.order_item_type = 'line_item'"
        );

        // Remove roster entries that no longer correspond to completed items
        $wpdb->query(
            "DELETE FROM $rosters_table WHERE order_item_id NOT IN (" . implode(',', $completed_items) . ")"
        );

        // Check and update roster entries for each completed item
        foreach ($completed_items as $item_id) {
            $item = new WC_Order_Item_Product($item_id);
            $product = $item->get_product();
            if ($product && $product->is_type('variation')) {
                $meta_data = $item->get_meta_data() ? array_column(array_map('get_object_vars', $item->get_meta_data()), 'value', 'key') : [];
                $venue = $meta_data['InterSoccer Venues'][0] ?? 'Unknown Venue';
                $age_group = $meta_data['Age Group'][0] ?? $meta_data['pa_age-group'] ?? (wc_get_product_terms($product->get_id(), 'pa_age-group', ['fields' => 'names'])[0] ?? 'N/A');
                $start_date = $meta_data['Start Date'][0] ?? '';
                $end_date = $meta_data['End Date'][0] ?? '';
                $activity_type = $meta_data['Activity Type'][0] ?? '';

                $existing = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM $rosters_table WHERE order_item_id = %d", $item_id)
                );

                if ($existing) {
                    // Update if data has changed
                    if ($existing->product_name !== $product->get_name() ||
                        $existing->venue !== $venue ||
                        $existing->age_group !== $age_group ||
                        $existing->start_date !== $start_date ||
                        $existing->end_date !== $end_date ||
                        $existing->activity_type !== $activity_type) {
                        $wpdb->update($rosters_table, [
                            'product_name' => $product->get_name(),
                            'venue' => $venue,
                            'age_group' => $age_group,
                            'start_date' => $start_date,
                            'end_date' => $end_date,
                            'activity_type' => $activity_type,
                            'updated_at' => current_time('mysql'),
                        ], ['order_item_id' => $item_id]);
                    }
                } else {
                    // Insert missing entry
                    $order = $item->get_order();
                    $parent_phone = $order->get_billing_phone() ?: 'N/A';
                    $parent_email = $order->get_billing_email() ?: 'N/A';
                    $wpdb->insert($rosters_table, [
                        'order_item_id' => $item_id,
                        'player_name' => $meta_data['Assigned Attendee'][0] ?? 'Unknown Attendee',
                        'first_name' => explode(' ', $meta_data['Assigned Attendee'][0] ?? 'Unknown Attendee', 2)[0] ?? 'Unknown',
                        'last_name' => explode(' ', $meta_data['Assigned Attendee'][0] ?? 'Unknown Attendee', 2)[1] ?? 'Unknown',
                        'age' => null,
                        'gender' => 'N/A',
                        'booking_type' => $meta_data['Booking Type'][0] ?? 'Unknown',
                        'selected_days' => $meta_data['Days Selected'][0] ?? 'N/A',
                        'camp_terms' => $meta_data['Camp Terms'][0] ?? 'N/A',
                        'venue' => $venue,
                        'parent_phone' => $parent_phone,
                        'parent_email' => $parent_email,
                        'medical_conditions' => $meta_data['Medical Conditions'][0] ?? '',
                        'late_pickup' => $meta_data['Late Pickup'][0] ?? 'No',
                        'day_presence' => json_encode(['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No']),
                        'age_group' => $age_group,
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'event_dates' => $start_date . ' to ' . $end_date,
                        'product_name' => $product->get_name(),
                        'activity_type' => $activity_type,
                        'updated_at' => current_time('mysql'),
                    ]);
                }
            }
        }
        error_log('InterSoccer: Rosters reconciled at ' . current_time('mysql'));
    }
}

/**
 * Rebuild the roster database from all completed orders via AJAX.
 */
function intersoccer_rebuild_rosters() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_send_json_error(__('Permission denied.', 'intersoccer-reports-rosters'));
    }
    check_ajax_referer('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field');

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Clear the existing table
    $wpdb->query("TRUNCATE TABLE $rosters_table");
    error_log('InterSoccer: Table truncated for rebuild at ' . current_time('mysql'));

    // Fetch all completed orders
    $orders = wc_get_orders(['status' => 'completed', 'limit' => -1]);
    $inserted = 0;
    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->is_type('variation')) {
                $meta_data = $item->get_meta_data() ? array_column(array_map('get_object_vars', $item->get_meta_data()), 'value', 'key') : [];
                $venue = $meta_data['InterSoccer Venues'][0] ?? 'Unknown Venue';
                $age_group = $meta_data['Age Group'][0] ?? $meta_data['pa_age-group'] ?? (wc_get_product_terms($product->get_id(), 'pa_age-group', ['fields' => 'names'])[0] ?? 'N/A');
                $start_date = $meta_data['Start Date'][0] ?? '';
                $end_date = $meta_data['End Date'][0] ?? '';
                $activity_type = $meta_data['Activity Type'][0] ?? '';

                $parent_phone = $order->get_billing_phone() ?: 'N/A';
                $parent_email = $order->get_billing_email() ?: 'N/A';
                $wpdb->insert($rosters_table, [
                    'order_item_id' => $item->get_id(),
                    'player_name' => $meta_data['Assigned Attendee'][0] ?? 'Unknown Attendee',
                    'first_name' => explode(' ', $meta_data['Assigned Attendee'][0] ?? 'Unknown Attendee', 2)[0] ?? 'Unknown',
                    'last_name' => explode(' ', $meta_data['Assigned Attendee'][0] ?? 'Unknown Attendee', 2)[1] ?? 'Unknown',
                    'age' => null,
                    'gender' => 'N/A',
                    'booking_type' => $meta_data['Booking Type'][0] ?? 'Unknown',
                    'selected_days' => $meta_data['Days Selected'][0] ?? 'N/A',
                    'camp_terms' => $meta_data['Camp Terms'][0] ?? 'N/A',
                    'venue' => $venue,
                    'parent_phone' => $parent_phone,
                    'parent_email' => $parent_email,
                    'medical_conditions' => $meta_data['Medical Conditions'][0] ?? '',
                    'late_pickup' => $meta_data['Late Pickup'][0] ?? 'No',
                    'day_presence' => json_encode(['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No']),
                    'age_group' => $age_group,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'event_dates' => $start_date . ' to ' . $end_date,
                    'product_name' => $product->get_name(),
                    'activity_type' => $activity_type,
                    'updated_at' => current_time('mysql'),
                ]);
                $inserted++;
            }
        }
    }
    error_log('InterSoccer: Rosters rebuilt, inserted ' . $inserted . ' entries at ' . current_time('mysql'));
    wp_send_json_success(['inserted' => $inserted, 'message' => __('Rebuild completed.', 'intersoccer-reports-rosters')]);
}
add_action('wp_ajax_intersoccer_rebuild_rosters', 'intersoccer_rebuild_rosters');
