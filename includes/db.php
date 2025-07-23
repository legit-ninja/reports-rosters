<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.21
 * @author Jeremy Lee
 */
defined('ABSPATH') or die('Restricted access');
/**
 * Create or upgrade the rosters table schema without dropping or populating data.
 */
function intersoccer_create_rosters_table() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $charset_collate = $wpdb->get_charset_collate();

    // Same SQL as in rebuild, but without DROP
    $sql = "CREATE TABLE $rosters_table (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        order_id bigint unsigned NOT NULL,
        order_item_id bigint unsigned NOT NULL,
        variation_id bigint unsigned NOT NULL,
        player_name varchar(255) NOT NULL,
        first_name varchar(100) NOT NULL,
        last_name varchar(100) NOT NULL,
        age int DEFAULT NULL,
        gender varchar(20) DEFAULT 'N/A',
        booking_type varchar(50) NOT NULL,
        selected_days text,
        camp_terms varchar(100) DEFAULT NULL,
        venue varchar(200) DEFAULT '',
        parent_phone varchar(20) DEFAULT 'N/A',
        parent_email varchar(100) DEFAULT 'N/A',
        medical_conditions text,
        late_pickup varchar(10) DEFAULT 'No',
        day_presence text,
        age_group varchar(50) DEFAULT '',
        start_date date DEFAULT '1970-01-01',
        end_date date DEFAULT '1970-01-01',
        event_dates varchar(100) DEFAULT 'N/A',
        product_name varchar(255) NOT NULL,
        activity_type varchar(50) DEFAULT '',
        shirt_size varchar(50) DEFAULT 'N/A',
        shorts_size varchar(50) DEFAULT 'N/A',
        registration_timestamp datetime DEFAULT NULL,
        course_day varchar(20) DEFAULT 'N/A',
        updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        product_id bigint unsigned NOT NULL,
        player_first_name varchar(100) NOT NULL,
        player_last_name varchar(100) NOT NULL,
        player_dob date NOT NULL DEFAULT '1970-01-01',
        player_gender varchar(10) DEFAULT '',
        player_medical text DEFAULT '',
        player_dietary text DEFAULT '',
        parent_first_name varchar(100) NOT NULL,
        parent_last_name varchar(100) NOT NULL,
        emergency_contact varchar(20) DEFAULT '',
        term varchar(200) DEFAULT '',
        times varchar(50) DEFAULT '',
        days_selected varchar(200) DEFAULT '',
        season varchar(50) DEFAULT '',
        canton_region varchar(100) DEFAULT '',
        city varchar(100) DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_order_item_id (order_item_id),
        KEY idx_player_name (player_name),
        KEY idx_venue (venue),
        KEY idx_activity_type (activity_type(50)),
        KEY idx_start_date (start_date),
        KEY idx_variation_id (variation_id),
        KEY idx_order_id (order_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    error_log('InterSoccer: Rosters table created/verified on activation (no rebuild).');
}

/**
 * Rebuild rosters and reports table
 */
function intersoccer_rebuild_rosters_and_reports() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    error_log('InterSoccer: Starting forced rebuild for table ' . $rosters_table);

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $rosters_table (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        order_id bigint unsigned NOT NULL,
        order_item_id bigint unsigned NOT NULL,
        variation_id bigint unsigned NOT NULL,
        player_name varchar(255) NOT NULL,
        first_name varchar(100) NOT NULL,
        last_name varchar(100) NOT NULL,
        age int DEFAULT NULL,
        gender varchar(20) DEFAULT 'N/A',
        booking_type varchar(50) NOT NULL,
        selected_days text,
        camp_terms varchar(100) DEFAULT NULL,
        venue varchar(200) DEFAULT '',
        parent_phone varchar(20) DEFAULT 'N/A',
        parent_email varchar(100) DEFAULT 'N/A',
        medical_conditions text,
        late_pickup varchar(10) DEFAULT 'No',
        day_presence text,
        age_group varchar(50) DEFAULT '',
        start_date date DEFAULT '1970-01-01',
        end_date date DEFAULT '1970-01-01',
        event_dates varchar(100) DEFAULT 'N/A',
        product_name varchar(255) NOT NULL,
        activity_type varchar(50) DEFAULT '',
        shirt_size varchar(50) DEFAULT 'N/A',
        shorts_size varchar(50) DEFAULT 'N/A',
        registration_timestamp datetime DEFAULT NULL,
        course_day varchar(20) DEFAULT 'N/A',
        updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        product_id bigint unsigned NOT NULL,
        player_first_name varchar(100) NOT NULL,
        player_last_name varchar(100) NOT NULL,
        player_dob date NOT NULL DEFAULT '1970-01-01',
        player_gender varchar(10) DEFAULT '',
        player_medical text DEFAULT '',
        player_dietary text DEFAULT '',
        parent_first_name varchar(100) NOT NULL,
        parent_last_name varchar(100) NOT NULL,
        emergency_contact varchar(20) DEFAULT '',
        term varchar(200) DEFAULT '',
        times varchar(50) DEFAULT '',
        days_selected varchar(200) DEFAULT '',
        season varchar(50) DEFAULT '',
        canton_region varchar(100) DEFAULT '',
        city varchar(100) DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_order_item_id (order_item_id),
        KEY idx_player_name (player_name),
        KEY idx_venue (venue),
        KEY idx_activity_type (activity_type(50)),
        KEY idx_start_date (start_date),
        KEY idx_variation_id (variation_id),
        KEY idx_order_id (order_id)
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

    // Define known Girls Only variation IDs
    $girls_only_variation_ids = ['32648', '32649', '33957', '32645', '32641'];

    foreach ($orders as $order) {
        wp_cache_flush();  // Flush cache per order to avoid load issues in batch
        $order_id = $order->get_id();
        $order_date = $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null;
        $items = $order->get_items();
        $total_items += count($items);
        error_log('InterSoccer: Processing order ' . $order_id . ' with ' . count($items) . ' items');

        foreach ($items as $item) {
            $order_item_id = $item->get_id();
            // Check if order_item_id already exists to prevent duplicates
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rosters_table WHERE order_item_id = %d", $order_item_id));
            if ($exists > 0) {
                error_log("InterSoccer: Skipping duplicate order_item_id $order_item_id for order $order_id");
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                error_log("InterSoccer: Skipping invalid product for order $order_id, item $order_item_id");
                continue;
            }
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $variation = $variation_id ? wc_get_product($variation_id) : $product;
            $parent_product = wc_get_product($product_id);
            error_log("InterSoccer: Variation object for order $order_id, item $order_item_id, variation_id: $variation_id - " . ($variation ? 'Loaded (ID: ' . $variation->get_id() . ')' : 'Failed'));
            error_log("InterSoccer: Parent product for order $order_id, item $order_item_id, product_id: $product_id - " . ($parent_product ? 'Loaded (ID: ' . $parent_product->get_id() . ')' : 'Failed'));

            $raw_order_item_meta = wc_get_order_item_meta($order_item_id, '', true);
            error_log("InterSoccer: Raw order item meta for order $order_id, item $order_item_id: " . print_r($raw_order_item_meta, true));

            // Handle Activity Type with case-insensitive fallback
            $activity_type = $raw_order_item_meta['Activity Type'][0] ?? null;
            error_log("InterSoccer: Raw Activity Type from meta for order $order_id, item $order_item_id: " . print_r($activity_type, true));
            if ($activity_type) {
                $activity_type = trim(strtolower(html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $activity_types = array_map('trim', explode(',', $activity_type));
                error_log("InterSoccer: Processed activity_types from meta for order $order_id, item $order_item_id: " . print_r($activity_types, true));
                if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
                    $activity_type = 'Girls Only';
                    error_log("InterSoccer: Assigned Girls Only from meta for order $order_id, item $order_item_id");
                } else {
                    $activity_type = implode(', ', array_map('ucfirst', $activity_types));
                    error_log("InterSoccer: Defaulted to joined activity_types from meta for order $order_id, item $order_item_id: $activity_type");
                }
            } else {
                $variation_activity_type = $variation ? $variation->get_attribute('pa_activity-type') : ($parent_product ? $parent_product->get_attribute('pa_activity-type') : null);
                error_log("InterSoccer: Raw pa_activity-type from variation for order $order_id, item $order_item_id: " . print_r($variation_activity_type, true));
                if ($variation_activity_type) {
                    if (is_array($variation_activity_type)) {
                        $variation_activity_type = implode(', ', array_map('trim', $variation_activity_type));
                    }
                    $activity_type = trim(strtolower(html_entity_decode($variation_activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    $activity_types = array_map('trim', explode(',', $activity_type));
                    error_log("InterSoccer: Processed activity_types from variation for order $order_id, item $order_item_id: " . print_r($activity_types, true));
                    if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
                        $activity_type = 'Girls Only';
                        error_log("InterSoccer: Assigned Girls Only from variation for order $order_id, item $order_item_id");
                    } elseif (!empty($activity_types[0])) {
                        $activity_type = ucfirst($activity_types[0]);
                        error_log("InterSoccer: Defaulted to first activity_type from variation for order $order_id, item $order_item_id: $activity_type");
                    } else {
                        if ($variation && $variation->get_attribute('pa_course-day') || ($parent_product && $parent_product->get_attribute('pa_course-day'))) {
                            $activity_type = 'Course';
                            error_log("InterSoccer: Assigned Course based on pa_course-day for order $order_id, item $order_item_id");
                        } elseif ($variation && $variation->get_attribute('pa_camp-terms') || ($parent_product && $parent_product->get_attribute('pa_camp-terms'))) {
                            $activity_type = 'Camp';
                            error_log("InterSoccer: Assigned Camp based on pa_camp-terms for order $order_id, item $order_item_id");
                        } elseif (in_array($variation_id, $girls_only_variation_ids)) {
                            $activity_type = 'Girls Only';
                            error_log("InterSoccer: Assigned Girls Only based on variation_id $variation_id for order $order_id, item $order_item_id");
                        } else {
                            $activity_type = 'unknown';
                            error_log("InterSoccer: No activity type indicators found, defaulting to unknown for order $order_id, item $order_item_id");
                        }
                    }
                } else {
                    if (isset($raw_order_item_meta['pa_course-day'])) {
                        $activity_type = 'Course';
                        error_log("InterSoccer: Assigned Course based on pa_course-day in meta for order $order_id, item $order_item_id");
                    } elseif (isset($raw_order_item_meta['pa_camp-terms'])) {
                        $activity_type = 'Camp';
                        error_log("InterSoccer: Assigned Camp based on pa_camp-terms in meta for order $order_id, item $order_item_id");
                    } elseif (in_array($variation_id, $girls_only_variation_ids)) {
                        $activity_type = 'Girls Only';
                        error_log("InterSoccer: Assigned Girls Only based on variation_id $variation_id for order $order_id, item $order_item_id");
                    } else {
                        $activity_type = 'unknown';
                        error_log("InterSoccer: No activity type indicators found, defaulting to unknown for order $order_id, item $order_item_id");
                    }
                }
            }

            $order_item_meta = array_combine(
                array_keys($raw_order_item_meta),
                array_map(function ($value, $key) {
                    if ($key !== 'Activity Type' && is_array($value)) {
                        return $value[0] ?? implode(', ', array_map('trim', $value));
                    }
                    return is_array($value) ? $value[0] ?? implode(', ', array_map('trim', $value)) : trim($value);
                }, array_values($raw_order_item_meta), array_keys($raw_order_item_meta))
            );

            $assigned_attendees = $order_item_meta['Assigned Attendees'] ?? $order_item_meta['Assigned Attendee'] ?? 'Unknown Attendee';
            $attendees = is_array($assigned_attendees) ? $assigned_attendees : [$assigned_attendees];

            foreach ($attendees as $assigned_attendee) {
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

                // Extract event details, prioritizing camp_terms for Camps
                $booking_type = $order_item_meta['pa_booking-type'] ?? ($variation ? $variation->get_attribute('pa_booking-type') : ($parent_product ? $parent_product->get_attribute('pa_booking-type') : 'Unknown'));
                $selected_days = $order_item_meta['Days Selected'] ?? 'N/A';
                $camp_terms = $order_item_meta['pa_camp-terms'] ?? ($variation ? $variation->get_attribute('pa_camp-terms') : ($parent_product ? $parent_product->get_attribute('pa_camp-terms') : 'N/A'));
                $venue = $order_item_meta['pa_intersoccer-venues'] ?? ($variation ? $variation->get_attribute('pa_intersoccer-venues') : ($parent_product ? $parent_product->get_attribute('pa_intersoccer-venues') : 'Unknown Venue'));
                if ($venue === 'Unknown Venue') {
                    $meta = wc_get_order_item_meta($order_item_id, 'pa_intersoccer-venues', true);
                    if ($meta) {
                        $venue = $meta;
                        error_log("InterSoccer: Fallback venue extracted for order $order_id, item $order_item_id: $venue");
                    }
                }
                $age_group = $order_item_meta['pa_age-group'] ?? ($variation ? $variation->get_attribute('pa_age-group') : ($parent_product ? $parent_product->get_attribute('pa_age-group') : 'N/A'));
                $course_day = ($activity_type === 'Course') ? ($order_item_meta['pa_course-day'] ?? ($variation ? $variation->get_attribute('pa_course-day') : ($parent_product ? $parent_product->get_attribute('pa_course-day') : 'N/A'))) : 'N/A';

                // Extract times with postmeta fallback
                $times = $order_item_meta['Course Times'] ?? $order_item_meta['Camp Times'] ?? null;
                if (!$times) {
                    $times = $variation ? ($variation->get_attribute('pa_course-times') ?? $variation->get_attribute('pa_camp-times')) : null;
                    if (!$times && $parent_product) {
                        $times = $parent_product->get_attribute('pa_course-times') ?? $parent_product->get_attribute('pa_camp-times');
                    }
                    if (!$times && $variation_id) {
                        $times = get_post_meta($variation_id, 'attribute_pa_camp-times', true) ?: get_post_meta($variation_id, 'attribute_pa_course-times', true);
                    }
                    if (!$times && $product_id) {
                        $times = get_post_meta($product_id, 'attribute_pa_camp-times', true) ?: get_post_meta($product_id, 'attribute_pa_course-times', true);
                    }
                    $times = $times ?: 'N/A';
                }
                error_log("InterSoccer: Times source for order $order_id, item $order_item_id: Meta - " . ($order_item_meta['Course Times'] ?? $order_item_meta['Camp Times'] ?? 'N/A') . ', Variation attr - ' . ($variation ? ($variation->get_attribute('pa_course-times') ?? $variation->get_attribute('pa_camp-times') ?? 'N/A') : 'N/A') . ', Parent attr - ' . ($parent_product ? ($parent_product->get_attribute('pa_course-times') ?? $parent_product->get_attribute('pa_camp-times') ?? 'N/A') : 'N/A') . ', Postmeta - ' . (get_post_meta($variation_id ?: $product_id, 'attribute_pa_camp-times', true) ?: get_post_meta($variation_id ?: $product_id, 'attribute_pa_course-times', true) ?: 'N/A') . ', Final: ' . $times);

                $start_date = null;
                $end_date = null;
                $event_dates = 'N/A';
                $season_year = $order_item_meta['pa_program-season'] ?? ($variation ? $variation->get_attribute('pa_program-season') : ($parent_product ? $parent_product->get_attribute('pa_program-season') : null));
                if (!$season_year && isset($order_item_meta['Season'])) {
                    preg_match('/(\d{4})/', $order_item_meta['Season'], $year_matches);
                    $season_year = $year_matches[0] ?? null;
                }
                error_log("InterSoccer: Season year for order $order_id, item $order_item_id: $season_year");
                if ($activity_type === 'Camp' && $camp_terms !== 'N/A') {
                    if (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\w+)-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                        $start_month = $matches[2];
                        $start_day = $matches[3];
                        $end_month = $matches[4];
                        $end_day = $matches[5];
                        $year = $season_year ?: (date('Y', strtotime($order_date)) ?: date('Y'));
                        $start_date_obj = DateTime::createFromFormat('F j Y', "$start_month $start_day $year");
                        $end_date_obj = DateTime::createFromFormat('F j Y', "$end_month $end_day $year");
                        if ($start_date_obj && $end_date_obj) {
                            $start_date = $start_date_obj->format('Y-m-d');
                            $end_date = $end_date_obj->format('Y-m-d');
                            $event_dates = "$start_date to $end_date";
                        } else {
                            error_log("InterSoccer: Date parsing failed for camp_terms $camp_terms (start_month: $start_month, start_day: $start_day, end_month: $end_month, end_day: $end_day, year: $year) for order $order_id, item $order_item_id");
                        }
                    } elseif (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                        $month = $matches[2];
                        $start_day = $matches[3];
                        $end_day = $matches[4];
                        $year = $season_year ?: (date('Y', strtotime($order_date)) ?: date('Y'));
                        $start_date_obj = DateTime::createFromFormat('F j Y', "$month $start_day $year");
                        $end_date_obj = DateTime::createFromFormat('F j Y', "$month $end_day $year");
                        if ($start_date_obj && $end_date_obj) {
                            $start_date = $start_date_obj->format('Y-m-d');
                            $end_date = $end_date_obj->format('Y-m-d');
                            $event_dates = "$start_date to $end_date";
                        } else {
                            error_log("InterSoccer: Date parsing failed for camp_terms $camp_terms (month: $month, start_day: $start_day, end_day: $end_day, year: $year) for order $order_id, item $order_item_id");
                        }
                    } else {
                        error_log("InterSoccer: Regex failed to match camp_terms $camp_terms for order $order_id, item $order_item_id");
                    }
                } elseif ($activity_type === 'Course' && !empty($order_item_meta['Start Date']) && !empty($order_item_meta['End Date'])) {
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
                        if (array_key_exists($day, $day_presence)) {
                            $day_presence[$day] = 'Yes';
                        }
                    }
                } elseif (strtolower($booking_type) === 'full-week') {
                    $day_presence = ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
                }

                // Handle Girls Only specific fields with prioritized key search
                $shirt_size = 'N/A';
                $shorts_size = 'N/A';
                if ($activity_type === 'Girls Only' || in_array($variation_id, $girls_only_variation_ids)) {
                    $possible_shirt_keys = ['pa_what-size-t-shirt-does-your', 'pa_tshirt-size', 'pa_what-size-t-shirt-does-your-child-wear', 'Shirt Size', 'T-shirt Size'];
                    $possible_shorts_keys = ['pa_what-size-shorts-does-your-c', 'pa_what-size-shorts-does-your-child-wear', 'Shorts Size', 'Shorts'];
                    foreach ($possible_shirt_keys as $key) {
                        if (isset($order_item_meta[$key]) && $order_item_meta[$key] !== '') {
                            $shirt_size = substr(trim($order_item_meta[$key]), 0, 50);
                            break;
                        }
                    }
                    foreach ($possible_shorts_keys as $key) {
                        if (isset($order_item_meta[$key]) && $order_item_meta[$key] !== '') {
                            $shorts_size = substr(trim($order_item_meta[$key]), 0, 50);
                            break;
                        }
                    }
                    if ($shirt_size === 'N/A' || $shorts_size === 'N/A') {
                        $meta = wc_get_order_item_meta($order_item_id, '', true);
                        foreach ($possible_shirt_keys as $key) {
                            if (isset($meta[$key][0]) && $meta[$key][0] !== '') {
                                $shirt_size = substr(trim($meta[$key][0]), 0, 50);
                                break;
                            }
                        }
                        foreach ($possible_shorts_keys as $key) {
                            if (isset($meta[$key][0]) && $meta[$key][0] !== '') {
                                $shorts_size = substr(trim($meta[$key][0]), 0, 50);
                                break;
                            }
                        }
                        error_log("InterSoccer: Fallback for order $order_id, item $order_item_id - shirt_size: $shirt_size, shorts_size: $shorts_size");
                    }
                }

                // Prepare roster_entry for insertion
                $roster_entry = [
                    'order_id' => $order_id,
                    'order_item_id' => $order_item_id,
                    'variation_id' => $variation_id,
                    'player_name' => substr($assigned_attendee, 0, 255),
                    'first_name' => substr($first_name, 0, 100),
                    'last_name' => substr($last_name, 0, 100),
                    'age' => $age,
                    'gender' => substr($gender, 0, 20),
                    'booking_type' => $booking_type,
                    'selected_days' => $selected_days,
                    'camp_terms' => $camp_terms,
                    'venue' => $venue,
                    'parent_phone' => substr($order->get_billing_phone() ?: 'N/A', 0, 20),
                    'parent_email' => substr($order->get_billing_email() ?: 'N/A', 0, 100),
                    'medical_conditions' => $medical_conditions,
                    'late_pickup' => $late_pickup,
                    'day_presence' => json_encode($day_presence),
                    'age_group' => $age_group,
                    'start_date' => $start_date ?: '1970-01-01',
                    'end_date' => $end_date ?: '1970-01-01',
                    'event_dates' => substr($event_dates, 0, 100),
                    'product_name' => substr($product_name, 0, 255),
                    'activity_type' => substr(ucfirst($activity_type), 0, 50),
                    'shirt_size' => $shirt_size,
                    'shorts_size' => $shorts_size,
                    'registration_timestamp' => $order_date,
                    'course_day' => $course_day,
                    'updated_at' => current_time('mysql'),
                    'product_id' => $product_id,
                    'player_first_name' => substr($first_name, 0, 100),
                    'player_last_name' => substr($last_name, 0, 100),
                    'player_dob' => $dob ?? '1970-01-01',
                    'player_gender' => substr($gender, 0, 10),
                    'player_medical' => $medical_conditions,
                    'player_dietary' => '',
                    'parent_first_name' => substr($order->get_billing_first_name() ?: 'Unknown', 0, 100),
                    'parent_last_name' => substr($order->get_billing_last_name() ?: 'Unknown', 0, 100),
                    'emergency_contact' => substr($order->get_billing_phone() ?: 'N/A', 0, 20),
                    'term' => $camp_terms ?: $course_day,
                    'times' => $times,
                    'days_selected' => $selected_days,
                    'season' => $season_year ?: '',
                    'canton_region' => '',
                    'city' => '',
                    'created_at' => current_time('mysql'),
                ];

                // Log to validate $order before insert
                error_log('InterSoccer: Order object type for ' . $order_id . ': ' . (is_object($order) ? get_class($order) : 'Invalid') . ' | Billing last name: ' . $order->get_billing_last_name());

                // Insert into table
                $format = array('%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');
                try {
                    $query = $wpdb->prepare(
                        "INSERT INTO $rosters_table (order_id, order_item_id, variation_id, player_name, first_name, last_name, age, gender, booking_type, selected_days, camp_terms, venue, parent_phone, parent_email, medical_conditions, late_pickup, day_presence, age_group, start_date, end_date, event_dates, product_name, activity_type, shirt_size, shorts_size, registration_timestamp, course_day, updated_at, product_id, player_first_name, player_last_name, player_dob, player_gender, player_medical, player_dietary, parent_first_name, parent_last_name, emergency_contact, term, times, days_selected, season, canton_region, city, created_at) VALUES (%d, %d, %d, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
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
    }

    $wpdb->query('COMMIT');
    error_log('InterSoccer: Transaction committed. Processed ' . $total_items . ' items, inserted ' . $inserted_items . ' rosters');
    return ['status' => 'success', 'inserted' => $inserted_items];
}

/**
 * Upgrade database schema
 */
function intersoccer_upgrade_database() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    error_log('InterSoccer: No new schema changes required for this upgrade.');
}


// AJAX handlers unchanged
add_action('wp_ajax_intersoccer_upgrade_database', 'intersoccer_upgrade_database_ajax');
function intersoccer_upgrade_database_ajax() {
    check_ajax_referer('intersoccer_rebuild_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to upgrade the database.', 'intersoccer-reports-rosters'));
    }
    intersoccer_upgrade_database();
    wp_send_json_success(__('Database upgrade completed.', 'intersoccer-reports-rosters'));
}

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

/**
 * Validate the rosters table: Check existence and schema match.
 * Returns true if valid, else false and sets admin notice.
 */
function intersoccer_validate_rosters_table() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Check if table exists
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rosters_table)) === $rosters_table;
    error_log('InterSoccer: Rosters table exists: ' . ($table_exists ? 'yes' : 'no'));
    if (!$table_exists) {
        intersoccer_create_rosters_table(); // Auto-create if missing
        return intersoccer_validate_rosters_table(); // Re-validate after creation
    }

    // Expected columns (from DESCRIBE; adjust if schema changes)
    $expected_columns = [
        'id' => 'bigint unsigned',
        'order_id' => 'bigint unsigned',
        'order_item_id' => 'bigint unsigned',
        'variation_id' => 'bigint unsigned',
        'player_name' => 'varchar(255)',
        'first_name' => 'varchar(100)',
        'last_name' => 'varchar(100)',
        'age' => 'int',
        'gender' => 'varchar(20)',
        'booking_type' => 'varchar(50)',
        'selected_days' => 'text',
        'camp_terms' => 'varchar(100)',
        'venue' => 'varchar(200)',
        'parent_phone' => 'varchar(20)',
        'parent_email' => 'varchar(100)',
        'medical_conditions' => 'text',
        'late_pickup' => 'varchar(10)',
        'day_presence' => 'text',
        'age_group' => 'varchar(50)',
        'start_date' => 'date',
        'end_date' => 'date',
        'event_dates' => 'varchar(100)',
        'product_name' => 'varchar(255)',
        'activity_type' => 'varchar(50)',
        'shirt_size' => 'varchar(50)',
        'shorts_size' => 'varchar(50)',
        'registration_timestamp' => 'datetime',
        'course_day' => 'varchar(20)',
        'updated_at' => 'timestamp',
        'product_id' => 'bigint unsigned',
        'player_first_name' => 'varchar(100)',
        'player_last_name' => 'varchar(100)',
        'player_dob' => 'date',
        'player_gender' => 'varchar(10)',
        'player_medical' => 'text',
        'player_dietary' => 'text',
        'parent_first_name' => 'varchar(100)',
        'parent_last_name' => 'varchar(100)',
        'emergency_contact' => 'varchar(20)',
        'term' => 'varchar(200)',
        'times' => 'varchar(50)',
        'days_selected' => 'varchar(200)',
        'season' => 'varchar(50)',
        'canton_region' => 'varchar(100)',
        'city' => 'varchar(100)',
        'created_at' => 'datetime',
    ];

    // Get actual columns from DESCRIBE
    $actual_columns_raw = $wpdb->get_results("DESCRIBE $rosters_table", ARRAY_A);
    $actual_columns = [];
    foreach ($actual_columns_raw as $col) {
        $actual_columns[$col['Field']] = strtolower(preg_replace('/\s*\(.*?\)/', '', $col['Type'])); // Simplify type (e.g., 'bigint(20) unsigned' -> 'bigint unsigned')
    }
    error_log('InterSoccer: Rosters table DESCRIBE result: ' . print_r($actual_columns, true));

    // Compare
    $mismatch = array_diff_key($expected_columns, $actual_columns) || array_diff_key($actual_columns, $expected_columns);
    if ($mismatch) {
        error_log('InterSoccer: Rosters table schema mismatch detected.');
        add_action('admin_notices', 'intersoccer_db_upgrade_notice');
        return false;
    }

    return true;
}

/**
 * Admin notice for DB upgrade needed.
 */
function intersoccer_db_upgrade_notice() {
    ?>
    <div class="notice notice-warning is-dismissible">
        <p><?php _e('InterSoccer Rosters table schema is outdated. Go to Advanced Features and click "Upgrade Database".', 'intersoccer-reports-rosters'); ?></p>
    </div>
    <?php
}
?>