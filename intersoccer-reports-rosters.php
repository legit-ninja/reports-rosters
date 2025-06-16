<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: Generates event rosters and reports for InterSoccer Switzerland admins using WooCommerce data.
 * Version: 1.2.95
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
            INDEX idx_activity_type (activity_type(50)),
            INDEX idx_start_date (start_date)
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
        error_log('InterSoccer: Included includes/' . $file);
    } else {
        error_log('InterSoccer: Missing includes/' . $file . ' - Check file path or plugin structure');
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

function intersoccer_trigger_rebuild() {
    if (isset($_GET['intersoccer_force_rebuild']) && current_user_can('manage_options')) {
        error_log('InterSoccer: Manual rebuild triggered at ' . current_time('mysql'));
        ob_start();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $result = intersoccer_rebuild_rosters_and_reports();
        $output = ob_get_clean();
        error_log('InterSoccer: Rebuild result: ' . print_r($result, true) . ' Output: ' . $output);
        wp_die('Manual rebuild completed. Check debug.log for details. Inserted: ' . ($result['inserted'] ?? 0) . ' rosters.');
    }
}
add_action('init', 'intersoccer_trigger_rebuild');

add_action('wp_ajax_intersoccer_export_all_rosters', function () {
    check_ajax_referer('intersoccer_export_nonce', 'nonce');
    $export_type = sanitize_text_field($_POST['export_type'] ?? 'all');
    $filters = ['show_no_attendees' => '1'];
    $camp_variations = intersoccer_pe_get_camp_variations($filters);
    $course_variations = intersoccer_pe_get_course_variations($filters);
    $girls_only_variations = intersoccer_pe_get_girls_only_variations($filters);
    intersoccer_export_all_rosters($camp_variations, $course_variations, $girls_only_variations, $export_type, 'excel');
    wp_die();
});

// Add AJAX action for rebuild with fallback diagnostics
add_action('wp_ajax_intersoccer_rebuild_rosters', function() {
    error_log('InterSoccer: Entering wp_ajax_intersoccer_rebuild_rosters at ' . current_time('mysql'));
    check_ajax_referer('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field');

    if (!current_user_can('manage_options')) {
        error_log('InterSoccer: Rebuild unauthorized for user ID ' . get_current_user_id());
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
        return;
    }

    try {
        error_log('InterSoccer: Starting rebuild process');
        $result = intersoccer_rebuild_rosters_and_reports();
        error_log('InterSoccer: Rebuild result: ' . print_r($result, true));
        if ($result['status'] === 'success') {
            wp_send_json_success(['inserted' => $result['inserted'], 'message' => __('Rosters rebuilt successfully.', 'intersoccer-reports-rosters')]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    } catch (Exception $e) {
        error_log('InterSoccer: Rebuild exception: ' . $e->getMessage());
        wp_send_json_error(['message' => __('Rebuild failed due to an error. Check server logs.', 'intersoccer-reports-rosters')]);
    }
    wp_die();
});

// Rebuild function with improved metadata and date handling
function intersoccer_rebuild_rosters_and_reports() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    error_log('InterSoccer: Starting forced rebuild for table ' . $rosters_table);

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    $charset_collate = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
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
        INDEX idx_activity_type (activity_type(50)),
        INDEX idx_start_date (start_date)
    ) $charset_collate;";
    $wpdb->query("DROP TABLE IF EXISTS $rosters_table");
    $result = dbDelta($sql);
    if (is_wp_error($result)) {
        error_log('InterSoccer: dbDelta failed: ' . $result->get_error_message());
        return ['status' => 'error', 'message' => 'Table creation failed: ' . $result->get_error_message()];
    }
    error_log('InterSoccer: Table ' . $rosters_table . ' created or verified with utf8mb4 encoding');

    $wpdb->query('START TRANSACTION');
    $wpdb->query("TRUNCATE TABLE $rosters_table");
    error_log('InterSoccer: Table truncated');

    $orders = wc_get_orders(['limit' => -1, 'status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold']]);
    error_log('InterSoccer: Found ' . count($orders) . ' orders');

    $total_items = 0;
    $inserted_items = 0;
    if (empty($orders)) {
        error_log('InterSoccer: No orders retrieved');
        $wpdb->query('ROLLBACK');
        return ['status' => 'error', 'inserted' => 0];
    }

    foreach ($orders as $order) {
        $order_id = $order->get_id();
        $items = $order->get_items();
        $total_items += count($items);
        error_log('InterSoccer: Processing order ' . $order_id . ' with ' . count($items) . ' items');

        foreach ($items as $item) {
            $order_item_id = $item->get_id();
            $product = $item->get_product();
            if (!$product) {
                error_log("InterSoccer: Skipping invalid product for order $order_id, item $order_item_id");
                continue;
            }
            $order_item_meta = wc_get_order_item_meta($order_item_id, '', true);
            $order_item_meta = array_map('intersoccer_normalize_attribute', $order_item_meta);

            $product_id = $product->get_id();
            $variation_id = $item->get_variation_id() ?: $product_id;
            $parent_product = wc_get_product($product_id);

            $assigned_attendee = $order_item_meta['Assigned Attendee'] ?? 'Unknown Attendee';
            $player_name_parts = explode(' ', $assigned_attendee, 2);
            $first_name = !empty($player_name_parts[0]) ? $player_name_parts[0] : 'Unknown';
            $last_name = !empty($player_name_parts[1]) ? $player_name_parts[1] : 'Unknown';

            $user_id = $order->get_user_id();
            $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
            $player_index = $order_item_meta['assigned_player'] ?? false;
            $age = null;
            $gender = 'N/A';
            $medical_conditions = '';
            if ($player_index !== false && is_array($players) && isset($players[$player_index])) {
                $player = $players[$player_index];
                $first_name = $player['first_name'] ?? $first_name;
                $last_name = $player['last_name'] ?? $last_name;
                $dob = $player['dob'] ?? null;
                $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : null;
                $gender = $player['gender'] ?? $gender;
                $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
            } else {
                $player_full_name = trim("$first_name $last_name");
                foreach ($players as $player) {
                    if (trim($player['first_name'] . ' ' . $player['last_name']) === $player_full_name) {
                        $dob = $player['dob'] ?? null;
                        $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : null;
                        $gender = $player['gender'] ?? $gender;
                        $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                        break;
                    }
                }
                $age = $order_item_meta['Player Age'] ? (int)$order_item_meta['Player Age'] : $age;
                $gender = $order_item_meta['Player Gender'] ?? $gender;
                $medical_conditions = $order_item_meta['Medical Conditions'] ?? $medical_conditions;
            }

            $booking_type = $order_item_meta['pa_booking-type'] ?? 'Unknown';
            $selected_days = $order_item_meta['Days Selected'] ?? 'N/A';
            $camp_terms = $order_item_meta['pa_camp-terms'] ?? 'N/A';
            $venue = $order_item_meta['pa_intersoccer-venues'] ?? 'Unknown Venue';
            $age_group = $order_item_meta['pa_age-group'] ?? 'N/A';

            $start_date = null;
            $end_date = null;
            $event_dates = 'N/A';
            if ($camp_terms !== 'N/A' && preg_match('/(\w+)-week-\d+-(\w+)-(\d{2})-(\d{2})-\d+-days/', $camp_terms, $matches)) {
                $month = $matches[2];
                $start_day = $matches[3];
                $end_day = $matches[4];
                $year = $order_item_meta['Season'] ? substr($order_item_meta['Season'], -4) : date('Y');
                $start_date_obj = DateTime::createFromFormat('F j Y', "$month $start_day $year");
                $end_date_obj = DateTime::createFromFormat('F j Y', "$month $end_day $year");
                $start_date = $start_date_obj ? $start_date_obj->format('Y-m-d') : null;
                $end_date = $end_date_obj ? $end_date_obj->format('Y-m-d') : null;
                $event_dates = $start_date && $end_date ? "$start_date to $end_date" : 'N/A';
            } elseif (!empty($order_item_meta['Start Date']) && !empty($order_item_meta['End Date'])) {
                $start_date = DateTime::createFromFormat('m/d/Y', $order_item_meta['Start Date'])->format('Y-m-d');
                $end_date = DateTime::createFromFormat('m/d/Y', $order_item_meta['End Date'])->format('Y-m-d');
                $event_dates = "$start_date to $end_date";
            }

            $late_pickup = $order_item_meta['Late Pickup'] ?? 'No';
            $product_name = $product->get_name();

            $day_presence = ['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No'];
            if (strtolower($booking_type) === 'single-days') {
                $days = array_map('trim', explode(',', $selected_days));
                foreach ($days as $day) {
                    $day_presence[$day] = 'Yes';
                }
            } elseif (strtolower($booking_type) === 'full-week') {
                $day_presence = ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
            }

            // Enhanced activity_type normalization with priority for Girls Only
            $activity_type_terms = $parent_product ? wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']) : [];
            $activity_type = 'Unknown';
            if (isset($order_item_meta['Activity Type']) && $order_item_meta['Activity Type']) {
                $raw_activity_type = html_entity_decode($order_item_meta['Activity Type'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $activity_types = array_map('trim', explode(',', $raw_activity_type));
                $activity_types = array_map(function($type) {
                    return preg_replace("/['’\x{2019}]/u", '', trim($type));
                }, $activity_types);
                $activity_type = implode(', ', array_filter($activity_types));
                // Prioritize Girls Only if present
                if (in_array('Girls Only', $activity_types)) {
                    $activity_type = 'Girls Only';
                }
            } elseif (!empty($activity_type_terms)) {
                $activity_type = implode(', ', $activity_type_terms);
                $activity_type = html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $activity_type = preg_replace("/['’\x{2019}]/u", '', $activity_type);
                // Prioritize Girls Only if present
                $normalized_terms = array_map(function($term) {
                    return preg_replace("/['’\x{2019}]/u", '', $term);
                }, $activity_type_terms);
                if (in_array('Girls Only', $normalized_terms)) {
                    $activity_type = 'Girls Only';
                }
            } else {
                $activity_type = html_entity_decode($product_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $activity_type = (stripos($activity_type, 'Girls') !== false ? 'Girls Only' :
                                 (stripos($activity_type, 'Camp') !== false ? 'Camp' :
                                 (stripos($activity_type, 'Course') !== false ? 'Course' :
                                 (stripos($activity_type, 'Birthday') !== false ? 'Event' : 'Other'))));
                $activity_type = preg_replace("/['’\x{2019}]/u", '', $activity_type);
            }
            error_log("InterSoccer: Normalized activity_type for order $order_id, item $order_item_id: $activity_type");

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
                'parent_phone' => $order->get_billing_phone() ?: 'N/A',
                'parent_email' => $order->get_billing_email() ?: 'N/A',
                'medical_conditions' => $medical_conditions,
                'late_pickup' => $late_pickup,
                'day_presence' => json_encode($day_presence),
                'age_group' => $age_group,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'event_dates' => $event_dates,
                'product_name' => $product_name,
                'activity_type' => $activity_type,
            ];

            $result = $wpdb->insert($rosters_table, $roster_entry, [
                '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
            ]);
            if ($result === false) {
                error_log("InterSoccer: Insert failed for order $order_id, item $order_item_id: " . $wpdb->last_error);
            } else {
                $inserted_items++;
            }
        }
    }

    $wpdb->query('COMMIT');
    error_log('InterSoccer: Transaction committed. Processed ' . $total_items . ' items, inserted ' . $inserted_items . ' rosters');
    return ['status' => 'success', 'inserted' => $inserted_items];
}

// Auto-add on order completion
add_action('woocommerce_order_status_changed', 'intersoccer_auto_add_to_rosters', 10, 4);
function intersoccer_auto_add_to_rosters($order_id, $old_status, $new_status, $order) {
    if ($old_status === 'processing' && $new_status === 'completed') {
        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $items = $order->get_items();
        foreach ($items as $item) {
            $order_item_id = $item->get_id();
            $product = $item->get_product();
            if (!$product) continue;
            $order_item_meta = $item->get_meta_data() ? array_column(array_map('get_object_vars', $item->get_meta_data()), 'value', 'key') : [];
            $product_id = $product->get_id();
            $parent_product = wc_get_product($product_id);

            $assigned_attendee = wc_get_order_item_meta($order_item_id, 'Assigned Attendee', true) ?: 'Unknown Attendee';
            $player_name_parts = explode(' ', $assigned_attendee, 2);
            $first_name = !empty($player_name_parts[0]) ? $player_name_parts[0] : 'Unknown';
            $last_name = !empty($player_name_parts[1]) ? $player_name_parts[1] : 'Unknown';

            $user_id = $order->get_user_id();
            $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
            $age = null;
            $gender = 'N/A';
            $medical_conditions = '';
            foreach ($players as $player) {
                $player_full_name = trim($player['first_name'] . ' ' . $player['last_name']);
                if (strtolower($player_full_name) === strtolower($assigned_attendee)) {
                    $first_name = $player['first_name'] ?? $first_name;
                    $last_name = $player['last_name'] ?? $last_name;
                    $dob = $player['dob'] ?? null;
                    $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : null;
                    $gender = $player['gender'] ?? $gender;
                    $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                    break;
                }
            }
            if (!$age) {
                $age = (int)$order_item_meta['Player Age'] ?? $age;
                $gender = $order_item_meta['Player Gender'] ?? $gender;
                $medical_conditions = $order_item_meta['Medical Conditions'] ?? $medical_conditions;
            }

            $booking_type = $order_item_meta['pa_booking-type'] ?? 'Unknown';
            $selected_days = $order_item_meta['Days Selected'] ?? 'N/A';
            $camp_terms = $order_item_meta['pa_camp-terms'] ?? 'N/A';
            $venue = $order_item_meta['pa_intersoccer-venues'] ?? 'Unknown Venue';
            $age_group = $order_item_meta['pa_age-group'] ?? 'N/A';
            $start_date = !empty($order_item_meta['Start Date']) ? DateTime::createFromFormat('m/d/Y', $order_item_meta['Start Date'])->format('Y-m-d') : null;
            $end_date = !empty($order_item_meta['End Date']) ? DateTime::createFromFormat('m/d/Y', $order_item_meta['End Date'])->format('Y-m-d') : null;
            $event_dates = $start_date && $end_date ? "$start_date to $end_date" : 'N/A';
            $late_pickup = $order_item_meta['Late Pickup'] ?? 'No';
            $product_name = $product->get_name();

            $day_presence = ['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No'];
            if (strtolower($booking_type) === 'single-days') {
                $days = array_map('trim', explode(',', $selected_days));
                foreach ($days as $day) {
                    $day_presence[ucfirst(strtolower($day))] = 'Yes';
                }
            } elseif (strtolower($booking_type) === 'full-week') {
                $day_presence = array_fill_keys(array_keys($day_presence), 'Yes');
            }

            // Enhanced activity_type normalization with priority for Girls Only
            $activity_type_terms = $parent_product ? wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']) : [];
            $activity_type = 'Unknown';
            if (isset($order_item_meta['Activity Type']) && $order_item_meta['Activity Type']) {
                $raw_activity_type = html_entity_decode($order_item_meta['Activity Type'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $activity_types = array_map('trim', explode(',', $raw_activity_type));
                $activity_types = array_map(function($type) {
                    return preg_replace("/['’\x{2019}]/u", '', trim($type));
                }, $activity_types);
                $activity_type = implode(', ', array_filter($activity_types));
                if (in_array('Girls Only', $activity_types)) {
                    $activity_type = 'Girls Only';
                }
            } elseif (!empty($activity_type_terms)) {
                $activity_type = implode(', ', $activity_type_terms);
                $activity_type = html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $activity_type = preg_replace("/['’\x{2019}]/u", '', $activity_type);
                $normalized_terms = array_map(function($term) {
                    return preg_replace("/['’\x{2019}]/u", '', $term);
                }, $activity_type_terms);
                if (in_array('Girls Only', $normalized_terms)) {
                    $activity_type = 'Girls Only';
                }
            } else {
                $activity_type = html_entity_decode($product_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $activity_type = (stripos($activity_type, 'Girls') !== false ? 'Girls Only' :
                                 (stripos($activity_type, 'Camp') !== false ? 'Camp' :
                                 (stripos($activity_type, 'Course') !== false ? 'Course' :
                                 (stripos($activity_type, 'Birthday') !== false ? 'Event' : 'Other'))));
                $activity_type = preg_replace("/['’\x{2019}]/u", '', $activity_type);
            }
            error_log("InterSoccer: Normalized activity_type for order $order_id, item $order_item_id: $activity_type");

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
                'parent_phone' => $order->get_billing_phone() ?: 'N/A',
                'parent_email' => $order->get_billing_email() ?: 'N/A',
                'medical_conditions' => $medical_conditions,
                'late_pickup' => $late_pickup,
                'day_presence' => json_encode($day_presence),
                'age_group' => $age_group,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'event_dates' => $event_dates,
                'product_name' => $product_name,
                'activity_type' => $activity_type,
            ];

            $wpdb->insert($rosters_table, $roster_entry, [
                '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
            ]);
        }
    }
}
?>
