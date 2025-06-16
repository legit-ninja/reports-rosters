<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Description: Generates event rosters and reports for InterSoccer Switzerland admins using WooCommerce data.
 * Version: 1.3.37
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

function intersoccer_rebuild_rosters_and_reports() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    error_log('InterSoccer: Starting forced rebuild for table ' . $rosters_table);

    if (!function_exists('dbDelta')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    if (!class_exists('WC_Order')) {
        require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
    }

    $charset_collate = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
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
    $wpdb->query("DROP TABLE IF EXISTS $rosters_table");
    $result = dbDelta($sql);
    if (is_wp_error($result)) {
        error_log('InterSoccer: dbDelta failed: ' . $result->get_error_message());
        return ['status' => 'error', 'message' => 'Table creation failed: ' . $result->get_error_message()];
    }
    error_log('InterSoccer: Table ' . $rosters_table . ' created or verified with utf8mb4 encoding');

    $wpdb->query('START TRANSACTION');
    $wpdb->query("TRUNCATE TABLE $rosters_table");
    error_log('InterSoccer: Table truncated and verified empty: ' . ($wpdb->get_var("SELECT COUNT(*) FROM $rosters_table") == 0 ? 'Yes' : 'No'));

    $orders = wc_get_orders(['limit' => -1, 'status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold']]);
    error_log('InterSoccer: Found ' . count($orders) . ' orders for rebuild');

    $total_items = 0;
    $inserted_items = 0;
    if (empty($orders)) {
        error_log('InterSoccer: No orders retrieved for rebuild');
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
            $raw_order_item_meta = wc_get_order_item_meta($order_item_id, '', true);
            // Handle Activity Type separately to avoid normalization issues
            $activity_type = $raw_order_item_meta['Activity Type'] ?? null;
            if ($activity_type !== null) {
                if (is_array($activity_type)) {
                    $activity_type = implode(', ', array_map('trim', $activity_type));
                }
                $activity_type = trim(html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                // Normalize "Girls' Only" to "Girls Only"
                $activity_type = str_replace("Girls' Only", "Girls Only", $activity_type);
                error_log("InterSoccer: Activity type from order item meta for order $order_id, item $order_item_id: $activity_type");
            } else {
                // Fallback to variation attribute if available
                $variation_id = $item->get_variation_id() ?: $product->get_id();
                $variation = $variation_id ? wc_get_product($variation_id) : $product;
                $variation_activity_type = $variation ? $variation->get_attribute('pa_activity-type') : null;
                if ($variation_activity_type !== null) {
                    if (is_array($variation_activity_type)) {
                        $variation_activity_type = implode(', ', array_map('trim', $variation_activity_type));
                    }
                    $activity_type = trim(html_entity_decode($variation_activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    // Normalize "Girls' Only" to "Girls Only"
                    $activity_type = str_replace("Girls' Only", "Girls Only", $activity_type);
                    error_log("InterSoccer: Activity type from variation attribute for order $order_id, item $order_item_id: $activity_type");
                } else {
                    $activity_type = 'Unknown';
                    error_log("InterSoccer: No activity type found, defaulting to Unknown for order $order_id, item $order_item_id");
                }
            }
            // Apply normalization to other meta fields
            $order_item_meta = array_combine(
                array_keys($raw_order_item_meta),
                array_map(function ($value, $key) {
                    if ($key !== 'Activity Type' && is_array($value)) {
                        return implode(', ', array_map('trim', $value));
                    }
                    return intersoccer_normalize_attribute($value, $key);
                }, array_values($raw_order_item_meta), array_keys($raw_order_item_meta))
            );

            $product_id = $product->get_id();
            $variation_id = $item->get_variation_id() ?: $product_id;
            $variation = $variation_id ? wc_get_product($variation_id) : $product;
            $parent_product = wc_get_product($product_id);

            $assigned_attendee = $order_item_meta['Assigned Attendee'] ?? 'Unknown Attendee';
            $player_name_parts = explode(' ', $assigned_attendee, 2);
            $first_name = !empty($player_name_parts[0]) ? $player_name_parts[0] : 'Unknown';
            $last_name = !empty($player_name_parts[1]) ? $player_name_parts[1] : 'Unknown';

            $user_id = $order->get_user_id();
            $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
            $player_index = $order_item_meta['assigned_player'] ?? false;
            $age = isset($order_item_meta['Player Age']) ? (int)$order_item_meta['Player Age'] : null;
            $gender = $order_item_meta['Player Gender'] ?? 'N/A';
            $medical_conditions = $order_item_meta['Medical Conditions'] ?? '';
            if ($player_index !== false && is_array($players) && isset($players[$player_index])) {
                $player = $players[$player_index];
                $first_name = $player['first_name'] ?? $first_name;
                $last_name = $player['last_name'] ?? $last_name;
                $dob = $player['dob'] ?? null;
                $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                $gender = $player['gender'] ?? $gender;
                $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
            } else {
                $player_full_name = trim("$first_name $last_name");
                foreach ($players as $player) {
                    if (trim($player['first_name'] . ' ' . $player['last_name']) === $player_full_name) {
                        $dob = $player['dob'] ?? null;
                        $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                        $gender = $player['gender'] ?? $gender;
                        $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                        break;
                    }
                }
            }

            // Extract event details, reconciling missing data from variation or parent product
            $booking_type = $order_item_meta['pa_booking-type'] ?? ($variation ? $variation->get_attribute('pa_booking-type') : $parent_product->get_attribute('pa_booking-type')) ?? 'Unknown';
            $selected_days = $order_item_meta['Days Selected'] ?? 'N/A';
            $camp_terms = $order_item_meta['pa_camp-terms'] ?? ($variation ? $variation->get_attribute('pa_camp-terms') : $parent_product->get_attribute('pa_camp-terms')) ?? 'N/A';
            $venue = $order_item_meta['pa_intersoccer-venues'] ?? ($variation ? $variation->get_attribute('pa_intersoccer-venues') : $parent_product->get_attribute('pa_intersoccer-venues')) ?? 'Unknown Venue';
            $age_group = $order_item_meta['pa_age-group'] ?? ($variation ? $variation->get_attribute('pa_age-group') : $parent_product->get_attribute('pa_age-group')) ?? 'N/A';

            $start_date = null;
            $end_date = null;
            $event_dates = 'N/A';
            $season = $order_item_meta['Season'] ?? date('Y');
            if ($camp_terms !== 'N/A' && preg_match('/(\w+)-week-\d+-(\w+)-(\d{2})-(\d{2})-\d+-days/', $camp_terms, $matches)) {
                $month = $matches[2];
                $start_day = $matches[3];
                $end_day = $matches[4];
                $year = substr($season, -4);
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

            // Debug: Halt and inspect
            // var_dump($activity_type); exit;

            // Prepare roster_entry for insertion
            $roster_entry = array(
                'order_item_id' => $order_item_id,
                'variation_id' => $variation_id,
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
            );
            if (!is_string($roster_entry['activity_type'])) {
                $roster_entry['activity_type'] = 'Unknown';
                error_log("InterSoccer: activity_type was not a string for order $order_id, item $order_item_id, defaulting to Unknown");
            }
            error_log("InterSoccer: Roster entry for order $order_id, item $order_item_id before insert: " . print_r($roster_entry, true));

            // Prepare the insert with error handling
            $format = array('%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');
            try {
                $query = $wpdb->prepare(
                    "INSERT INTO $rosters_table (order_item_id, variation_id, player_name, first_name, last_name, age, gender, booking_type, selected_days, camp_terms, venue, parent_phone, parent_email, medical_conditions, late_pickup, day_presence, age_group, start_date, end_date, event_dates, product_name, activity_type) VALUES (%d, %d, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
                    array_values($roster_entry)
                );
                error_log("InterSoccer: Prepared insert query for order $order_id, item $order_item_id: $query");
                $result = $wpdb->query($query);
                if ($result === false) {
                    error_log("InterSoccer: Insert failed for order $order_id, item $order_item_id: " . $wpdb->last_error);
                } else {
                    $inserted_items++;
                    $inserted_id = $wpdb->insert_id;
                    $actual_activity_type = $wpdb->get_var($wpdb->prepare("SELECT activity_type FROM $rosters_table WHERE id = %d", $inserted_id));
                    error_log("InterSoccer: Verified inserted roster for order $order_id, item $order_item_id - Actual activity_type: $actual_activity_type (Type: " . gettype($actual_activity_type) . ")");
                }
            } catch (Exception $e) {
                error_log("InterSoccer: Exception during insert for order $order_id, item $order_item_id: " . $e->getMessage());
            }
        }
    }

    $wpdb->query('COMMIT');
    error_log('InterSoccer: Transaction committed. Processed ' . $total_items . ' items, inserted ' . $inserted_items . ' rosters');
    return ['status' => 'success', 'inserted' => $inserted_items];
}

function intersoccer_upgrade_database() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Check if variation_id column exists
    $column_exists = $wpdb->get_row("SHOW COLUMNS FROM $rosters_table LIKE 'variation_id'");
    if (!$column_exists) {
        $wpdb->query("ALTER TABLE $rosters_table ADD COLUMN variation_id BIGINT(20) DEFAULT NULL");
        $wpdb->query("CREATE INDEX idx_variation_id ON $rosters_table(variation_id)");
        error_log('InterSoccer: Added variation_id column and index to ' . $rosters_table);

        // Backfill existing rows
        $orders = $wpdb->get_results("SELECT order_item_id FROM $rosters_table WHERE variation_id IS NULL", ARRAY_A);
        foreach ($orders as $order) {
            $order_item_id = $order['order_item_id'];
            $order_id = $wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d", $order_item_id));
            $order = $order_id ? wc_get_order($order_id) : null;
            $item = $order ? $order->get_item($order_item_id) : null;
            if ($item) {
                $variation_id = $item->get_variation_id() ?: $item->get_product_id();
                $wpdb->update($rosters_table, ['variation_id' => $variation_id], ['order_item_id' => $order_item_id]);
                error_log("InterSoccer: Backfilled variation_id $variation_id for order_item_id $order_item_id");
            } else {
                error_log("InterSoccer: Failed to retrieve order item for order_item_id $order_item_id");
            }
        }
        error_log('InterSoccer: Completed backfill of variation_id for ' . count($orders) . ' rows');
    } else {
        error_log('InterSoccer: variation_id column already exists in ' . $rosters_table);
    }
}

// AJAX handler for upgrade
add_action('wp_ajax_intersoccer_upgrade_database', 'intersoccer_upgrade_database_ajax');
function intersoccer_upgrade_database_ajax() {
    check_ajax_referer('intersoccer_rebuild_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to upgrade the database.', 'intersoccer-reports-rosters'));
    }
    intersoccer_upgrade_database();
    wp_send_json_success(__('Database upgrade completed.', 'intersoccer-reports-rosters'));
}

// AJAX handler for rebuild
add_action('wp_ajax_intersoccer_rebuild_rosters_and_reports', 'intersoccer_rebuild_rosters_and_reports_ajax');
function intersoccer_rebuild_rosters_and_reports_ajax() {
    check_ajax_referer('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to rebuild rosters.', 'intersoccer-reports-rosters'));
    }
    error_log('InterSoccer: AJAX rebuild request received with data: ' . print_r($_POST, true));
    $result = intersoccer_rebuild_rosters_and_reports();
    if ($result['status'] === 'success') {
        wp_send_json_success(['inserted' => $result['inserted'], 'message' => __('Rebuild completed. Inserted ' . $result['inserted'] . ' rosters.', 'intersoccer-reports-rosters')]);
    } else {
        wp_send_json_error(['message' => __('Rebuild failed: ' . $result['message'], 'intersoccer-reports-rosters')]);
    }
}
?>