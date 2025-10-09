<?php
/**
 * Database config and maintenance for InterSoccer Reports and Rosters plugin.
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
        late_pickup_days text,
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
        player_medical text,
        player_dietary text,
        parent_first_name varchar(100) NOT NULL,
        parent_last_name varchar(100) NOT NULL,
        emergency_contact varchar(20) DEFAULT '',
        term varchar(200) DEFAULT '',
        times varchar(50) DEFAULT '',
        days_selected varchar(200) DEFAULT '',
        season varchar(50) DEFAULT '',
        canton_region varchar(100) DEFAULT '',
        city varchar(100) DEFAULT '',
        avs_number varchar(50) DEFAULT 'N/A',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        base_price decimal(10,2) DEFAULT 0.00,
        discount_amount decimal(10,2) DEFAULT 0.00,
        final_price decimal(10,2) DEFAULT 0.00,
        reimbursement decimal(10,2) DEFAULT 0.00,
        discount_codes varchar(255) DEFAULT '',
        girls_only BOOLEAN DEFAULT FALSE,
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
    $describe = $wpdb->get_results("DESCRIBE $rosters_table", ARRAY_A);
    error_log('InterSoccer: Post-rebuild DESCRIBE: ' . print_r($describe, true));
}

/**
 * Rebuild rosters and reports table
 */
function intersoccer_rebuild_rosters_and_reports() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    error_log('InterSoccer: Starting forced rebuild for table ' . $rosters_table);

    $wpdb->query("DROP TABLE IF EXISTS $rosters_table");
    intersoccer_create_rosters_table();
    error_log('InterSoccer: Table ' . $rosters_table . ' dropped and recreated');

    $wpdb->query('START TRANSACTION');

    $orders = wc_get_orders(['limit' => -1, 'status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold']]);
    error_log('InterSoccer: Found ' . count($orders) . ' orders for rebuild');

    $inserted_items = 0;

    if (empty($orders)) {
        error_log('InterSoccer: No orders retrieved for rebuild');
        $wpdb->query('ROLLBACK');
        return ['status' => 'error', 'inserted' => 0];
    }

    foreach ($orders as $order) {
        wp_cache_flush();
        $order_id = $order->get_id();
        error_log('InterSoccer: Processing order ' . $order_id . ' for rebuild');

        foreach ($order->get_items() as $item_id => $item) {
            $result = intersoccer_update_roster_entry($order_id, $item_id);
            if ($result) {
                $inserted_items++;
            }
        }
    }

    $wpdb->query('COMMIT');
    error_log('InterSoccer: Rebuild completed. Inserted: ' . $inserted_items);
    return [
        'status' => 'success',
        'inserted' => $inserted_items,
        'message' => __('Rebuild completed. Inserted ' . $inserted_items . ' rosters.', 'intersoccer-reports-rosters')
    ];
}

function intersoccer_reconcile_rosters() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    error_log('InterSoccer: Starting roster reconciliation');

    $orders = wc_get_orders([
        'limit' => -1,
        'status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
    ]);
    error_log('InterSoccer: Found ' . count($orders) . ' orders for reconciliation');

    $synced = 0;
    $deleted = 0;

    try {
        $existing_items = $wpdb->get_col("SELECT order_item_id FROM $rosters_table");

        foreach ($orders as $order) {
            $order_id = $order->get_id();
            error_log('InterSoccer: Reconciling order ' . $order_id);

            foreach ($order->get_items() as $item_id => $item) {
                $result = intersoccer_update_roster_entry($order_id, $item_id);
                if ($result) {
                    $synced++;
                    $key = array_search($item_id, $existing_items);
                    if ($key !== false) {
                        unset($existing_items[$key]);
                    }
                }
            }
        }

        foreach ($existing_items as $obsolete_item_id) {
            $wpdb->delete($rosters_table, ['order_item_id' => $obsolete_item_id]);
            $deleted++;
            error_log('InterSoccer: Deleted obsolete roster entry for order_item_id ' . $obsolete_item_id);
        }

        error_log('InterSoccer: Reconciliation completed. Synced: ' . $synced . ', Deleted: ' . $deleted);
        return [
            'status' => 'success',
            'synced' => $synced,
            'deleted' => $deleted,
            'message' => __('Reconciled rosters: Synced ' . $synced . ' entries, deleted ' . $deleted . ' obsolete ones.', 'intersoccer-reports-rosters')
        ];
    } catch (Exception $e) {
        error_log('InterSoccer: Reconciliation failed: ' . $e->getMessage());
        return [
            'status' => 'error',
            'message' => __('Reconciliation failed: ' . $e->getMessage(), 'intersoccer-reports-rosters')
        ];
    }
}

/**
 * Helper to prepare roster entry data (extracted from rebuild for reuse).
 * Returns array or false if invalid.
 */
function intersoccer_prepare_roster_entry($order, $item, $order_item_id, $order_id, $order_date, $girls_only_variation_ids) {
    $product = $item->get_product();
    if (!$product) {
        return false;
    }

    $product_id = $item->get_product_id();
    $variation_id = $item->get_variation_id();
    $variation = wc_get_product($variation_id) ? wc_get_product($variation_id) : $product;
    $parent_product = wc_get_product($product_id);

    $raw_order_item_meta = wc_get_order_item_meta($order_item_id, '', true);
    error_log("InterSoccer: Raw order item meta for order $order_id, item $order_item_id: " . print_r($raw_order_item_meta, true));

    // Activity type logic (same as rebuild)
    $activity_type = $raw_order_item_meta['Activity Type'][0] ?? null;
    error_log("InterSoccer: Raw Activity Type from meta for order $order_id, item $order_item_id: " . print_r($activity_type, true));
    if ($activity_type) {
        $activity_type = trim(strtolower(html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $activity_types = array_map('trim', explode(',', $activity_type));
        error_log("InterSoccer: Processed activity_types from meta for order $order_id, item $order_item_id: " . print_r($activity_types, true));
        if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
            $activity_type = 'Girls Only';
        } else {
            $activity_type = implode(', ', array_map('ucfirst', $activity_types));
        }
    } else {
        $variation_activity_type = $variation ? $variation->get_attribute('pa_activity-type') : ($parent_product ? $parent_product->get_attribute('pa_activity-type') : null);
        if ($variation_activity_type) {
            if (is_array($variation_activity_type)) {
                $variation_activity_type = implode(', ', array_map('trim', $variation_activity_type));
            }
            $activity_type = trim(strtolower(html_entity_decode($variation_activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $activity_types = array_map('trim', explode(',', $activity_type));
            if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
                $activity_type = 'Girls Only';
            } elseif (!empty($activity_types[0])) {
                $activity_type = ucfirst($activity_types[0]);
            } else {
                if ($variation && $variation->get_attribute('pa_course-day') || ($parent_product && $parent_product->get_attribute('pa_course-day'))) {
                    $activity_type = 'Course';
                }
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
        // Strip leading numeric prefix + space
        $assigned_attendee = preg_replace('/^\d+\s*/', '', trim($assigned_attendee));
        $player_name_parts = explode(' ', $assigned_attendee, 2);
        $first_name = !empty($player_name_parts[0]) ? $player_name_parts[0] : 'Unknown';
        $last_name = !empty($player_name_parts[1]) ? $player_name_parts[1] : 'Unknown';

        // Normalize for matching (lowercase, trim, remove non-alpha, translit accents)
        $first_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $first_name) ?? $first_name)));
        $last_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $last_name) ?? $last_name)));

        $user_id = $order->get_user_id();
        $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
        $player_index = $order_item_meta['assigned_player'] ?? false;
        $age = isset($order_item_meta['Player Age']) ? (int)$order_item_meta['Player Age'] : null;
        $gender = $order_item_meta['Player Gender'] ?? 'N/A';
        $medical_conditions = $order_item_meta['Medical Conditions'] ?? '';
        $avs_number = 'N/A'; // Default
        if ($player_index !== false && is_array($players) && isset($players[$player_index])) {
            $player = $players[$player_index];
            $first_name = $player['first_name'] ?? $first_name;
            $last_name = $player['last_name'] ?? $last_name;
            $dob = $player['dob'] ?? null;
            $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
            $gender = $player['gender'] ?? $gender;
            $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
            $avs_number = $player['avs_number'] ?? 'N/A';
        } else {
            $matched = false;
            foreach ($players as $player) {
                $meta_first_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['first_name'] ?? '') ?? '')));
                $meta_last_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['last_name'] ?? '') ?? '')));
                if ($meta_first_norm === $first_name_norm && $meta_last_norm === $last_name_norm) {
                    $dob = $player['dob'] ?? null;
                    $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                    $gender = $player['gender'] ?? $gender;
                    $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                    $avs_number = $player['avs_number'] ?? 'N/A';
                    $matched = true;
                    break;
                }
            }
            // Fallback to first-name only if no exact match
            if (!$matched) {
                foreach ($players as $player) {
                    $meta_first_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['first_name'] ?? '') ?? '')));
                    if ($meta_first_norm === $first_name_norm) {
                        $dob = $player['dob'] ?? null;
                        $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                        $gender = $player['gender'] ?? $gender;
                        $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                        $avs_number = $player['avs_number'] ?? 'N/A';
                        error_log("InterSoccer: Fallback first-name match for attendee $assigned_attendee in rebuild order $order_id item $order_item_id");
                        break;
                    }
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
        $season = $raw_order_item_meta['Season'][0] ?? 'N/A';
        
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
            // Log raw date values for debugging
            error_log("InterSoccer: Raw Start Date for order $order_id, item $order_item_id: " . print_r($order_item_meta['Start Date'], true));
            error_log("InterSoccer: Raw End Date for order $order_id, item $order_item_id: " . print_r($order_item_meta['End Date'], true));

            // Try multiple date formats
            $possible_formats = ['m/d/Y', 'd/m/Y', 'Y-m-d', 'j F Y'];
            $start_date = null;
            $end_date = null;
            foreach ($possible_formats as $format) {
                $start_date_obj = DateTime::createFromFormat($format, $order_item_meta['Start Date']);
                if ($start_date_obj !== false) {
                    $start_date = $start_date_obj->format('Y-m-d');
                    error_log("InterSoccer: Parsed Start Date for order $order_id, item $order_item_id with format $format: $start_date");
                    break;
                }
            }
            foreach ($possible_formats as $format) {
                $end_date_obj = DateTime::createFromFormat($format, $order_item_meta['End Date']);
                if ($end_date_obj !== false) {
                    $end_date = $end_date_obj->format('Y-m-d');
                    error_log("InterSoccer: Parsed End Date for order $order_id, item $order_item_id with format $format: $end_date");
                    break;
                }
            }

            // Fallback if parsing fails
            if (!$start_date || !$end_date) {
                error_log("InterSoccer: Date parsing failed for order $order_id, item $order_item_id. Using default dates.");
                $start_date = '1970-01-01';
                $end_date = '1970-01-01';
                $event_dates = 'N/A';
            } else {
                $event_dates = "$start_date to $end_date";
            }
        } else {
            error_log("InterSoccer: Missing or invalid Start Date/End Date for Course in order $order_id, item $order_item_id. Using defaults.");
            $start_date = '1970-01-01';
            $end_date = '1970-01-01';
            $event_dates = 'N/A';
        }

        $late_pickup = (!empty($order_item_meta['Late Pickup Type'])) ? 'Yes' : 'No';
        $late_pickup_days = $order_item_meta['Late Pickup Days'] ?? '';
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
            'late_pickup_days' => $late_pickup_days,
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
            'season' => $season ?: '',
            'canton_region' => '',
            'city' => '',
            'avs_number' => substr($avs_number, 0, 50),
            'created_at' => current_time('mysql'),
        ];

        // Log to validate $order before insert
        error_log('InterSoccer: Order object type for ' . $order_id . ': ' . (is_object($order) ? get_class($order) : 'Invalid') . ' | Billing last name: ' . $order->get_billing_last_name());
    }

    return $roster_entry;
}

/**
 * Upgrade database schema
 */
function intersoccer_upgrade_database() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    error_log('InterSoccer: Starting database upgrade');

    $columns = $wpdb->get_results("DESCRIBE $rosters_table");
    $existing_cols = wp_list_pluck($columns, 'Field');

    $new_columns = [
        'base_price' => 'decimal(10,2) DEFAULT 0.00',
        'discount_amount' => 'decimal(10,2) DEFAULT 0.00',
        'final_price' => 'decimal(10,2) DEFAULT 0.00',
        'reimbursement' => 'decimal(10,2) DEFAULT 0.00',
        'discount_codes' => 'varchar(255) DEFAULT \'\'',
        'girls_only' => 'BOOLEAN DEFAULT FALSE',
        'late_pickup_days' => 'text',
    ];

    foreach ($new_columns as $col => $type) {
        if (!in_array($col, $existing_cols)) {
            $wpdb->query("ALTER TABLE $rosters_table ADD $col $type");
            error_log('InterSoccer: Added column ' . $col . ' to rosters table');
        }
    }

    // Backfill financial data and girls_only
    $rows = $wpdb->get_results("SELECT order_id, order_item_id, activity_type, variation_id, product_id FROM $rosters_table");
    foreach ($rows as $row) {
        $order = wc_get_order($row->order_id);
        if (!$order) continue;

        $item = $order->get_item($row->order_item_id);
        if (!$item) continue;

        $base_price = (float) $item->get_subtotal();
        $final_price = (float) $item->get_total();
        $discount_amount = $base_price - $final_price;
        $reimbursement = 0;
        $discount_codes = implode(',', $order->get_coupon_codes());

        // Backfill girls_only and update activity_type
        $girls_only = FALSE;
        $activity_type = 'unknown';
        $type_id = $row->variation_id ?: $row->product_id;
        $product_type = intersoccer_get_product_type($type_id);
        if ($product_type === 'camp') {
            $activity_type = 'Camp';
        } elseif ($product_type === 'course') {
            $activity_type = 'Course';
        } else {
            $activity_type = ucfirst($product_type);
        }

        // Extract late pickup data
        $late_pickup = (!empty($item_meta['Late Pickup Type'])) ? 'Yes' : 'No';
        $late_pickup_days = $item_meta['Late Pickup Days'] ?? '';

        // Check order item metadata for girls_only
        $item_meta = [];
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $item_meta[$data['key']] = $data['value'];
        }
        $raw_order_item_meta = wc_get_order_item_meta($row->order_item_id, '', true);
        $meta_activity_type = $item_meta['pa_activity-type'] ?? $item_meta['Activity Type'] ?? $raw_order_item_meta['Activity Type'][0] ?? '';
        if ($meta_activity_type) {
            $normalized_activity = trim(strtolower(html_entity_decode($meta_activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $normalized_activity = str_replace(["'", '"'], '', $normalized_activity);
            $activity_types = array_map('trim', explode(',', $normalized_activity));
            if (in_array('girls only', $activity_types) || in_array('camp girls only', $activity_types) || in_array('course girls only', $activity_types)) {
                $girls_only = TRUE;
            }
        } elseif ($row->activity_type) {
            // Fallback to existing activity_type for backfill
            $normalized_activity = trim(strtolower(html_entity_decode($row->activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $normalized_activity = str_replace(["'", '"'], '', $normalized_activity);
            $activity_types = array_map('trim', explode(',', $normalized_activity));
            if (in_array('girls only', $activity_types) || in_array('camp girls only', $activity_types) || in_array('course girls only', $activity_types)) {
                $girls_only = TRUE;
            }
        }

        $wpdb->update(
            $rosters_table,
            [
                'base_price' => $base_price,
                'discount_amount' => $discount_amount,
                'final_price' => $final_price,
                'reimbursement' => $reimbursement,
                'discount_codes' => $discount_codes,
                'girls_only' => $girls_only,
                'activity_type' => $activity_type,
                'late_pickup' => $late_pickup,
                'late_pickup_days' => $late_pickup_days,
            ],
            ['order_item_id' => $row->order_item_id]
        );
        error_log('InterSoccer: Backfilled financial data, girls_only, activity_type, and late pickup data for order_item_id ' . $row->order_item_id . ' (girls_only: ' . $girls_only . ', activity_type: ' . $activity_type . ', late_pickup: ' . $late_pickup . ', late_pickup_days: ' . $late_pickup_days . ')');
    }

    // Backfill avs_number
    $rows_without_avs = $wpdb->get_results("SELECT * FROM $rosters_table WHERE avs_number = 'N/A' OR avs_number = ''");
    foreach ($rows_without_avs as $row) {
        $order = wc_get_order($row->order_id);
        if (!$order) continue;

        $user_id = $order->get_user_id();
        $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
        $first_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $row->first_name))));
        $last_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $row->last_name))));

        foreach ($players as $player) {
            $meta_first_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['first_name'] ?? '') ?? '')));
            $meta_last_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['last_name'] ?? '') ?? '')));
            if ($meta_first_norm === $first_name_norm && $meta_last_norm === $last_name_norm && isset($player['avs_number'])) {
                $avs_number = $player['avs_number'];
                $wpdb->update($rosters_table, ['avs_number' => $avs_number], ['id' => $row->id]);
                error_log('InterSoccer: Backfilled avs_number for order ' . $row->order_id . ': ' . $avs_number);
                break;
            }
        }
    }

    error_log('InterSoccer: Database upgrade completed.');
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
    ob_start();
    check_ajax_referer('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field');
    if (!current_user_can('manage_options')) {
        ob_clean();
        wp_send_json_error(['message' => __('You do not have permission to rebuild rosters.', 'intersoccer-reports-rosters')]);
    }
    error_log('InterSoccer: AJAX rebuild request received with data: ' . print_r($_POST, true));

    try {
        $result = intersoccer_rebuild_rosters_and_reports();
        ob_clean();
        if ($result['status'] === 'success') {
            wp_send_json_success(['inserted' => $result['inserted'], 'message' => __('Rebuild completed. Inserted ' . $result['inserted'] . ' rosters.', 'intersoccer-reports-rosters')]);
        } else {
            wp_send_json_error(['message' => __('Rebuild failed: ' . $result['message'], 'intersoccer-reports-rosters')]);
        }
    } catch (Exception $e) {
        error_log('InterSoccer: Rebuild exception: ' . $e->getMessage());
        ob_clean();
        wp_send_json_error(['message' => __('Rebuild failed with exception: ' . $e->getMessage(), 'intersoccer-reports-rosters')]);
    }
}

add_action('wp_ajax_intersoccer_reconcile_rosters', 'intersoccer_reconcile_rosters_ajax');
function intersoccer_reconcile_rosters_ajax() {
    ob_start();
    check_ajax_referer('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field');
    if (!current_user_can('manage_options')) {
        ob_clean();
        wp_send_json_error(['message' => __('You do not have permission to reconcile rosters.', 'intersoccer-reports-rosters')]);
    }
    error_log('InterSoccer: AJAX reconcile request received with data: ' . print_r($_POST, true));

    try {
        $result = intersoccer_reconcile_rosters();
        ob_clean();
        if ($result['status'] === 'success') {
            wp_send_json_success(['message' => $result['message'], 'synced' => $result['synced'], 'deleted' => $result['deleted']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    } catch (Exception $e) {
        error_log('InterSoccer: Reconcile exception: ' . $e->getMessage());
        ob_clean();
        wp_send_json_error(['message' => __('Reconcile failed: ' . $e->getMessage(), 'intersoccer-reports-rosters')]);
    }
}

/**
 * Validate the rosters table: Check existence and schema match.
 * Returns true if valid, else false and sets admin notice.
 */
function intersoccer_validate_rosters_table() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rosters_table)) === $rosters_table;
    error_log('InterSoccer: Rosters table exists: ' . ($table_exists ? 'yes' : 'no'));
    if (!$table_exists) {
        intersoccer_create_rosters_table();
        return intersoccer_validate_rosters_table();
    }

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
        'late_pickup_days' => 'text',
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
        'avs_number' => 'varchar(50)',
        'created_at' => 'datetime',
        'base_price' => 'decimal(10,2)',
        'discount_amount' => 'decimal(10,2)',
        'final_price' => 'decimal(10,2)',
        'reimbursement' => 'decimal(10,2)',
        'discount_codes' => 'varchar(255)',
        'girls_only' => 'boolean',
    ];

    $actual_columns_raw = $wpdb->get_results("DESCRIBE $rosters_table", ARRAY_A);
    $actual_columns = [];
    foreach ($actual_columns_raw as $col) {
        $actual_columns[$col['Field']] = strtolower(preg_replace('/\s*\(.*?\)/', '', $col['Type']));
    }
    error_log('InterSoccer: Rosters table DESCRIBE result: ' . print_r($actual_columns, true));

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