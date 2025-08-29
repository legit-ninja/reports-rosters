<?php
/**
 * Utility functions for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.2
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_normalize_attribute')) {
    /**
     * Normalize attribute values for comparison, preserving "Activity Type" as-is.
     *
     * @param mixed $value Attribute value (string or array).
     * @param string $key The key of the attribute (e.g., from order item meta).
     * @return string Normalized value or empty string if invalid.
     */
    function intersoccer_normalize_attribute($value, $key = '') {
        // Preserve "Activity Type" as-is without normalization
        if ($key === 'Activity Type') {
            return is_string($value) ? trim($value) : (string)$value;
        }

        if (is_array($value)) {
            return implode(', ', array_map('trim', $value));
        } elseif (is_string($value) && strpos($value, 'a:') === 0) {
            $unserialized = maybe_unserialize($value);
            return is_array($unserialized) ? implode(', ', $unserialized) : $value;
        }
        return trim(strtolower($value ?? ''));
    }
}

error_log('InterSoccer: Loaded utils.php');

/**
 * Helper function to safely get term name
 */
function intersoccer_get_term_name($value, $taxonomy) {
    if (empty($value) || $value === 'N/A') {
        return 'N/A';
    }
    $term = get_term_by('slug', $value, $taxonomy);
    return $term ? $term->name : $value;
}

/**
 * Shared function to insert or update a roster entry from an order item.
 * Ensures consistent data extraction and insertion across all population points.
 *
 * @param int $order_id Order ID.
 * @param int $item_id Order item ID.
 * @return bool True if inserted/updated successfully, false otherwise.
 */
function intersoccer_update_roster_entry($order_id, $item_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'intersoccer_rosters';

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('InterSoccer: Invalid order ID ' . $order_id . ' in upsert_roster_entry');
        return false;
    }

    $item = $order->get_item($item_id);
    if (!$item) {
        error_log('InterSoccer: Invalid item ID ' . $item_id . ' in order ' . $order_id . ' for upsert_roster_entry');
        return false;
    }

    $product_id = $item->get_product_id();
    $variation_id = $item->get_variation_id();
    $type_id = $variation_id ?: $product_id;
    $product_type = intersoccer_get_product_type($type_id);
    error_log('InterSoccer: Item ' . $item_id . ' product_type: ' . $product_type . ' (using ID: ' . $type_id . ', parent: ' . $product_id . ', variation: ' . $variation_id . ')');

    if (!in_array($product_type, ['camp', 'course', 'birthday'])) {
        error_log('InterSoccer: Skipping item ' . $item_id . ' in order ' . $order_id . ' - Reason: Non-event product type (' . $product_type . ')');
        return false;
    }

    // Get item metadata
    $item_meta = [];
    foreach ($item->get_meta_data() as $meta) {
        $data = $meta->get_data();
        $item_meta[$data['key']] = $data['value'];
    }
    $raw_order_item_meta = wc_get_order_item_meta($item_id, '', true);
    error_log('InterSoccer: Item metadata for ' . $item_id . ': ' . print_r($item_meta, true));
    error_log('InterSoccer: Raw order item meta for order ' . $order_id . ', item ' . $item_id . ': ' . print_r($raw_order_item_meta, true));

    // Day-related log for debugging
    $day_related_keys = array_filter($item_meta, function($k) { return stripos($k, 'course') !== false || stripos($k, 'day') !== false; }, ARRAY_FILTER_USE_KEY);
    error_log('InterSoccer: Day-related meta keys for item ' . $item_id . ': ' . print_r($day_related_keys, true));

    // Assigned Attendee
    $assigned_attendee = isset($item_meta['Assigned Attendee']) ? trim($item_meta['Assigned Attendee']) : '';
    if (empty($assigned_attendee)) {
        error_log('InterSoccer: Skipping item ' . $item_id . ' in order ' . $order_id . ' - Reason: No Assigned Attendee found');
        return false;
    }

    // Strip leading numeric prefix + space
    $assigned_attendee = preg_replace('/^\d+\s*/', '', $assigned_attendee);

    // Split name (assuming first last)
    $name_parts = explode(' ', $assigned_attendee, 2);
    $first_name = trim($name_parts[0] ?? 'Unknown');
    $last_name = trim($name_parts[1] ?? 'Unknown');

    // Normalize for matching
    $first_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $first_name) ?? $first_name)));
    $last_name_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $last_name) ?? $last_name)));

    // Lookup player details from user meta
    $user_id = $order->get_user_id();
    $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
    error_log('InterSoccer: User ' . $user_id . ' players meta: ' . print_r($players, true));
    $player_index = $item_meta['assigned_player'] ?? false;
    $age = isset($item_meta['Player Age']) ? (int)$item_meta['Player Age'] : null;
    $gender = $item_meta['Player Gender'] ?? 'N/A';
    $medical_conditions = $item_meta['Medical Conditions'] ?? '';
    $avs_number = 'N/A';
    $dob = null;
    $matched = false;
    if ($player_index !== false && is_array($players) && isset($players[$player_index])) {
        $player = $players[$player_index];
        $first_name = trim($player['first_name'] ?? $first_name);
        $last_name = trim($player['last_name'] ?? $last_name);
        $dob = $player['dob'] ?? null;
        $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
        $gender = $player['gender'] ?? $gender;
        $medical_conditions = trim($player['medical_conditions'] ?? $medical_conditions);
        $avs_number = $player['avs_number'] ?? 'N/A';
        $matched = true;
    } else {
        foreach ($players as $player) {
            $meta_first_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['first_name'] ?? '') ?? '')));
            $meta_last_norm = strtolower(trim(preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $player['last_name'] ?? '') ?? '')));
            if ($meta_first_norm === $first_name_norm && $meta_last_norm === $last_name_norm) {
                $first_name = trim($player['first_name'] ?? $first_name);
                $last_name = trim($player['last_name'] ?? $last_name);
                $dob = $player['dob'] ?? null;
                $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                $gender = $player['gender'] ?? $gender;
                $medical_conditions = trim($player['medical_conditions'] ?? $medical_conditions);
                $avs_number = $player['avs_number'] ?? 'N/A';
                $matched = true;
                break;
            }
        }
    }
    if (!$matched) {
        error_log('InterSoccer: No matching player meta for attendee ' . $assigned_attendee . ' in order ' . $order_id . ', item ' . $item_id . ' - Using order meta defaults');
        $dob = $item_meta['Attendee DOB'] ?? null;
        $dob_obj = $dob ? DateTime::createFromFormat('Y-m-d', $dob) : null;
        $age = $dob_obj ? $dob_obj->diff(new DateTime())->y : $age;
        $gender = $item_meta['Attendee Gender'] ?? $gender;
        $medical_conditions = $item_meta['Medical Conditions'] ?? $medical_conditions;
    }

    // Event details with fallbacks
    $venue = $item_meta['pa_intersoccer-venues'] ?? $item_meta['InterSoccer Venues'] ?? '';
    $age_group = $item_meta['pa_age-group'] ?? $item_meta['Age Group'] ?? '';
    $camp_terms = $item_meta['pa_camp-terms'] ?? $item_meta['Camp Terms'] ?? '';
    $times = $item_meta['pa_camp-times'] ?? $item_meta['pa_course-times'] ?? $item_meta['Camp Times'] ?? $item_meta['Course Times'] ?? '';
    $booking_type = $item_meta['pa_booking-type'] ?? $item_meta['Booking Type'] ?? '';
    $selected_days = $item_meta['Days Selected'] ?? '';
    $season = $item_meta['pa_program-season'] ?? $item_meta['Season'] ?? '';
    $canton_region = $item_meta['pa_canton-region'] ?? $item_meta['Canton / Region'] ?? '';
    $city = $item_meta['City'] ?? '';
    $activity_type = $item_meta['pa_activity-type'] ?? $item_meta['Activity Type'] ?? '';
    $start_date = $item_meta['Start Date'] ?? null;
    $end_date = $item_meta['End Date'] ?? null;
    $event_dates = 'N/A';
    $course_day_slug = $item_meta['pa_course-day'] ?? $item_meta['Course Day'] ?? $raw_order_item_meta['Course Day'] ?? null;
    $course_day = 'N/A';
    if ($course_day_slug) {
        $term = get_term_by('slug', $course_day_slug, 'pa_course-day');
        $course_day = $term ? $term->name : ucfirst($course_day_slug);
    }
    $late_pickup = $item_meta['Late Pickup'] ?? 'No';
    $product_name = $item->get_name();
    $shirt_size = 'N/A';
    $shorts_size = 'N/A';

    // Determine girls_only and set activity_type to Camp or Course
    $girls_only = FALSE;
    if ($activity_type) {
        $normalized_activity = trim(strtolower(html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $normalized_activity = str_replace(["'", '"'], '', $normalized_activity);
        $activity_types = array_map('trim', explode(',', $normalized_activity));
        error_log('InterSoccer: Processed activity_types for order ' . $order_id . ', item ' . $item_id . ': ' . print_r($activity_types, true));
        if (in_array('girls only', $activity_types) || in_array('camp girls only', $activity_types) || in_array('course girls only', $activity_types)) {
            $girls_only = TRUE;
            error_log('InterSoccer: Set girls_only = TRUE for order ' . $order_id . ', item ' . $item_id);
            // Apply shirt/shorts size logic for Girls Only
            $possible_shirt_keys = ['pa_what-size-t-shirt-does-your', 'pa_tshirt-size', 'pa_what-size-t-shirt-does-your-child-wear', 'Shirt Size', 'T-shirt Size'];
            $possible_shorts_keys = ['pa_what-size-shorts-does-your-c', 'pa_what-size-shorts-does-your-child-wear', 'Shorts Size', 'Shorts'];
            foreach ($possible_shirt_keys as $key) {
                if (isset($item_meta[$key]) && $item_meta[$key] !== '') {
                    $shirt_size = trim($item_meta[$key]);
                    break;
                }
            }
            foreach ($possible_shorts_keys as $key) {
                if (isset($item_meta[$key]) && $item_meta[$key] !== '') {
                    $shorts_size = trim($item_meta[$key]);
                    break;
                }
            }
        }
    }
    // Set activity_type based on product_type
    $activity_type = $product_type === 'camp' ? 'Camp' : ($product_type === 'course' ? 'Course' : ucfirst($product_type));
    error_log('InterSoccer: Set activity_type to ' . $activity_type . ' for order ' . $order_id . ', item ' . $item_id);

    // Parse dates
    if ($product_type === 'camp' && $camp_terms) {
        if (preg_match('/(\d{4}-\d{2}-\d{2}) to (\d{4}-\d{2}-\d{2})/', $camp_terms, $matches)) {
            $start_date = $matches[1];
            $end_date = $matches[2];
            $event_dates = $start_date . ' to ' . $end_date;
        } else {
            error_log('InterSoccer: Invalid camp_terms format for item ' . $item_id . ' in order ' . $order_id . ' - Using defaults');
            $start_date = '1970-01-01';
            $end_date = '1970-01-01';
            $event_dates = 'N/A';
        }
    } elseif ($product_type === 'course' && $start_date && $end_date) {
        error_log('InterSoccer: Processing course dates for item ' . $item_id . ' in order ' . $order_id . ' - start_date: ' . var_export($start_date, true) . ', end_date: ' . var_export($end_date, true));
        $start_date_obj = DateTime::createFromFormat('F j, Y', $start_date);
        $end_date_obj = DateTime::createFromFormat('F j, Y', $end_date);
        if ($start_date_obj && $end_date_obj) {
            $start_date = $start_date_obj->format('Y-m-d');
            $end_date = $end_date_obj->format('Y-m-d');
            $event_dates = "$start_date to $end_date";
        } else {
            $start_date_obj = DateTime::createFromFormat('m/d/Y', $start_date);
            $end_date_obj = DateTime::createFromFormat('m/d/Y', $end_date);
            if ($start_date_obj && $end_date_obj) {
                $start_date = $start_date_obj->format('Y-m-d');
                $end_date = $end_date_obj->format('Y-m-d');
                $event_dates = "$start_date to $end_date";
            } else {
                error_log('InterSoccer: Invalid course date format for item ' . $item_id . ' in order ' . $order_id . ' - Using defaults');
                $start_date = '1970-01-01';
                $end_date = '1970-01-01';
                $event_dates = 'N/A';
            }
        }
    }

    // Day presence
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

    // Order and parent info
    $date_created = $order->get_date_created();
    $order_date = $date_created instanceof WC_DateTime ? $date_created->format('Y-m-d H:i:s') : current_time('mysql');
    $parent_phone = $order->get_billing_phone() ?: 'N/A';
    $parent_email = $order->get_billing_email() ?: 'N/A';
    $parent_first_name = $order->get_billing_first_name() ?: 'Unknown';
    $parent_last_name = $order->get_billing_last_name() ?: 'Unknown';

    // Financial data
    $base_price = (float) $item->get_subtotal();
    $final_price = (float) $item->get_total();
    $discount_amount = $base_price - $final_price;
    $reimbursement = 0; // TODO: Calculate from meta if needed
    $discount_codes = implode(',', $order->get_coupon_codes());

    // Prepare data array
    $data = [
        'order_id' => $order_id,
        'order_item_id' => $item_id,
        'variation_id' => $variation_id,
        'player_name' => substr($assigned_attendee, 0, 255),
        'first_name' => substr($first_name, 0, 100),
        'last_name' => substr($last_name, 0, 100),
        'age' => $age,
        'gender' => substr($gender, 0, 20),
        'booking_type' => substr($booking_type, 0, 50),
        'selected_days' => $selected_days,
        'camp_terms' => substr($camp_terms, 0, 100),
        'venue' => substr($venue, 0, 200),
        'parent_phone' => substr($parent_phone, 0, 20),
        'parent_email' => substr($parent_email, 0, 100),
        'medical_conditions' => $medical_conditions,
        'late_pickup' => substr($late_pickup, 0, 10),
        'day_presence' => json_encode($day_presence),
        'age_group' => substr($age_group, 0, 50),
        'start_date' => $start_date ?: '1970-01-01',
        'end_date' => $end_date ?: '1970-01-01',
        'event_dates' => substr($event_dates, 0, 100),
        'product_name' => substr($product_name, 0, 255),
        'activity_type' => substr($activity_type, 0, 50),
        'shirt_size' => substr($shirt_size, 0, 50),
        'shorts_size' => substr($shorts_size, 0, 50),
        'registration_timestamp' => $order_date,
        'course_day' => substr($course_day, 0, 20),
        'product_id' => $product_id,
        'player_first_name' => substr($first_name, 0, 100),
        'player_last_name' => substr($last_name, 0, 100),
        'player_dob' => $dob ?? '1970-01-01',
        'player_gender' => substr($gender, 0, 10),
        'player_medical' => $medical_conditions,
        'player_dietary' => '',
        'parent_first_name' => substr($parent_first_name, 0, 100),
        'parent_last_name' => substr($parent_last_name, 0, 100),
        'emergency_contact' => substr($parent_phone, 0, 20),
        'term' => substr($camp_terms ?: $course_day, 0, 200),
        'times' => substr($times, 0, 50),
        'days_selected' => substr($selected_days, 0, 200),
        'season' => substr($season, 0, 50),
        'canton_region' => substr($canton_region, 0, 100),
        'city' => substr($city, 0, 100),
        'avs_number' => substr($avs_number, 0, 50),
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
        'base_price' => $base_price,
        'discount_amount' => $discount_amount,
        'final_price' => $final_price,
        'reimbursement' => $reimbursement,
        'discount_codes' => $discount_codes,
        'girls_only' => $girls_only,
    ];

    // Insert or update
    $result = $wpdb->replace($table_name, $data);
    $insert_id = $wpdb->insert_id;
    error_log('InterSoccer: Upsert result for order ' . $order_id . ', item ' . $item_id . ': ' . var_export($result, true) . ' | Insert ID: ' . $insert_id . ' | Last DB error: ' . $wpdb->last_error . ' | Last query: ' . $wpdb->last_query);

    if ($result) {
        error_log('InterSoccer: Successfully upserted roster entry for order ' . $order_id . ', item ' . $item_id . ' (ID: ' . $insert_id . ')');
        return true;
    } else {
        error_log('InterSoccer: Failed to upsert roster entry for order ' . $order_id . ', item ' . $item_id . ' - Check DB error');
        return false;
    }
}