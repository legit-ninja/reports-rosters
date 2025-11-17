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
    $product_type = intersoccer_get_product_type_safe($product_id, $variation_id);
    error_log('InterSoccer: Item ' . $item_id . ' product_type: ' . $product_type . ' (using ID: ' . $type_id . ', parent: ' . $product_id . ', variation: ' . $variation_id . ')');

    if (!in_array($product_type, ['camp', 'course', 'birthday', 'tournament'])) {
        error_log('InterSoccer: Skipping item ' . $item_id . ' in order ' . $order_id . ' - Reason: Non-event product type (' . $product_type . ')');
        return false;
    }

    // Get item metadata - use same robust method as prepare_roster_entry
    $raw_order_item_meta = wc_get_order_item_meta($item_id, '', true);
    error_log('InterSoccer: Raw order item meta for order ' . $order_id . ', item ' . $item_id . ': ' . print_r($raw_order_item_meta, true));

    $item_meta = array_combine(
        array_keys($raw_order_item_meta),
        array_map(function ($value, $key) {
            if ($key !== 'Activity Type' && is_array($value)) {
                return $value[0] ?? implode(', ', array_map('trim', $value));
            }
            return is_array($value) ? $value[0] ?? implode(', ', array_map('trim', $value)) : trim($value);
        }, array_values($raw_order_item_meta), array_keys($raw_order_item_meta))
    );

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
    $late_pickup = (!empty($item_meta['Late Pickup Type'])) ? 'Yes' : 'No';
    $late_pickup_days = $item_meta['Late Pickup Days'] ?? '';
    $product_name = $item->get_name();
    $shirt_size = 'N/A';
    $shorts_size = 'N/A';

    // Determine girls_only and set activity_type to Camp or Course
    $girls_only = FALSE;
    if (!empty($activity_type)) {
        // Log the raw activity type for debugging
        error_log('InterSoccer: Raw Activity Type for order ' . $order_id . ', item ' . $item_id . ': "' . $activity_type . '"');
        
        // Normalize for comparison - handle apostrophes and case variations
        $normalized_activity = trim(strtolower(html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        
        // Split by comma and check each part
        $activity_parts = array_map('trim', explode(',', $normalized_activity));
        error_log('InterSoccer: Activity Type parts: ' . print_r($activity_parts, true));
        
        foreach ($activity_parts as $part) {
            // Remove apostrophes and check for girls only patterns
            $clean_part = str_replace(["'", '"'], '', $part);
            
            if (strpos($clean_part, 'girls only') !== false ||
                strpos($clean_part, 'girls-only') !== false ||
                strpos($clean_part, 'girlsonly') !== false) {
                
                $girls_only = TRUE;
                error_log('InterSoccer: Set girls_only = TRUE for order ' . $order_id . ', item ' . $item_id . ' based on Activity Type part: "' . $part . '"');
                break;
            }
        }
    }

    // Fallback: Check product name if Activity Type didn't indicate Girls' Only
    if (!$girls_only && !empty($product_name)) {
        $normalized_product_name = trim(strtolower(html_entity_decode($product_name, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $clean_product_name = str_replace(['-', "'", '"'], ' ', $normalized_product_name);
        
        if (strpos($clean_product_name, 'girls only') !== false) {
            $girls_only = TRUE;
            error_log('InterSoccer: Set girls_only = TRUE for order ' . $order_id . ', item ' . $item_id . ' based on product name: "' . $product_name . '"');
        }
    }

    // Apply shirt/shorts size logic for Girls Only events
    if ($girls_only) {
        error_log('InterSoccer: Applying shirt/shorts size logic for Girls Only event');
        $possible_shirt_keys = ['pa_what-size-t-shirt-does-your', 'pa_tshirt-size', 'pa_what-size-t-shirt-does-your-child-wear', 'Shirt Size', 'T-shirt Size'];
        $possible_shorts_keys = ['pa_what-size-shorts-does-your-c', 'pa_what-size-shorts-does-your-child-wear', 'Shorts Size', 'Shorts'];
        
        foreach ($possible_shirt_keys as $key) {
            if (isset($item_meta[$key]) && $item_meta[$key] !== '') {
                $shirt_size = trim($item_meta[$key]);
                error_log('InterSoccer: Found shirt size from ' . $key . ': ' . $shirt_size);
                break;
            }
        }
        
        foreach ($possible_shorts_keys as $key) {
            if (isset($item_meta[$key]) && $item_meta[$key] !== '') {
                $shorts_size = trim($item_meta[$key]);
                error_log('InterSoccer: Found shorts size from ' . $key . ': ' . $shorts_size);
                break;
            }
        }
    }
    // Set activity_type based on product_type
    $activity_type = $product_type === 'camp' ? 'Camp' : ($product_type === 'course' ? 'Course' : ucfirst($product_type));
    error_log('InterSoccer: Set activity_type to ' . $activity_type . ' for order ' . $order_id . ', item ' . $item_id);

    // Parse dates
    if ($product_type === 'camp' && !empty($camp_terms) && $camp_terms !== 'N/A') {
        list($start_date, $end_date, $event_dates) = intersoccer_parse_camp_dates_fixed($camp_terms, $season);
    } elseif ($product_type === 'course' && !empty($start_date) && !empty($end_date)) {
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

    $data = [
        'order_id' => $order_id,
        'order_item_id' => $item_id,
        'variation_id' => $variation_id,
        'player_name' => substr((string)($assigned_attendee ?: 'Unknown Player'), 0, 255),
        'first_name' => substr((string)($first_name ?: 'Unknown'), 0, 100),
        'last_name' => substr((string)($last_name ?: 'Unknown'), 0, 100),
        'age' => $age,
        'gender' => substr((string)($gender ?: 'N/A'), 0, 20),
        'booking_type' => substr((string)($booking_type ?: 'Unknown'), 0, 50),
        'selected_days' => $selected_days,
        'camp_terms' => substr((string)($camp_terms ?: 'N/A'), 0, 100),
        'venue' => substr((string)($venue ?: 'Unknown Venue'), 0, 200),
        'parent_phone' => substr((string)($parent_phone ?: 'N/A'), 0, 20),
        'parent_email' => substr((string)($parent_email ?: 'N/A'), 0, 100),
        'medical_conditions' => $medical_conditions,
        'late_pickup' => $late_pickup,
        'late_pickup_days' => $late_pickup_days,
        'day_presence' => json_encode($day_presence),
        'age_group' => substr((string)($age_group ?: 'N/A'), 0, 50),
        'start_date' => $start_date ?: '1970-01-01',
        'end_date' => $end_date ?: '1970-01-01',
        'event_dates' => substr((string)($event_dates ?: 'N/A'), 0, 100),
        'product_name' => substr((string)($product_name ?: 'Unknown Product'), 0, 255),
        'activity_type' => substr((string)($activity_type ?: 'Event'), 0, 50),
        'shirt_size' => substr((string)($shirt_size ?: 'N/A'), 0, 50),
        'shorts_size' => substr((string)($shorts_size ?: 'N/A'), 0, 50),
        'registration_timestamp' => $order_date,
        'course_day' => substr((string)($course_day ?: 'N/A'), 0, 20),
        'product_id' => $product_id,
        'player_first_name' => substr((string)($first_name ?: 'Unknown'), 0, 100),
        'player_last_name' => substr((string)($last_name ?: 'Unknown'), 0, 100),
        'player_dob' => $dob ?: '1970-01-01',
        'player_gender' => substr((string)($gender ?: 'N/A'), 0, 10),
        'player_medical' => $medical_conditions,
        'player_dietary' => '',
        'parent_first_name' => substr((string)($parent_first_name ?: 'Unknown'), 0, 100),
        'parent_last_name' => substr((string)($parent_last_name ?: 'Unknown'), 0, 100),
        'emergency_contact' => substr((string)($parent_phone ?: 'N/A'), 0, 20),
        'term' => substr((string)(($camp_terms ?: $course_day) ?: 'N/A'), 0, 200),
        'times' => substr((string)($times ?: 'N/A'), 0, 50),
        'days_selected' => substr((string)($selected_days ?: 'N/A'), 0, 200),
        'season' => substr((string)($season ?: 'N/A'), 0, 50),
        'canton_region' => substr((string)($canton_region ?: ''), 0, 100),
        'city' => substr((string)($city ?: ''), 0, 100),
        'avs_number' => substr((string)($avs_number ?: 'N/A'), 0, 50),
        'created_at' => current_time('mysql'),
        'base_price' => $base_price,
        'discount_amount' => $discount_amount,
        'final_price' => $final_price,
        'reimbursement' => $reimbursement,
        'discount_codes' => $discount_codes,
        'girls_only' => $girls_only,
        'event_signature' => '',
    ];

    // Generate event signature with normalized (English) values for consistent grouping
    $original_event_data = [
        'activity_type' => $activity_type,
        'venue' => $venue,
        'age_group' => $age_group,
        'camp_terms' => $camp_terms,
        'course_day' => $course_day,
        'times' => $times,
        'season' => $season,
        'girls_only' => $girls_only,
        'product_id' => $product_id,
    ];
    
    // Log original event data before normalization
    error_log('InterSoccer Signature: Original event data (Order: ' . $order_id . ', Item: ' . $item_id . '): ' . json_encode($original_event_data));
    
    $normalized_event_data = intersoccer_normalize_event_data_for_signature($original_event_data);
    
    // Log normalized event data after normalization
    error_log('InterSoccer Signature: Normalized event data (Order: ' . $order_id . ', Item: ' . $item_id . '): ' . json_encode($normalized_event_data));

    $data['event_signature'] = intersoccer_generate_event_signature($normalized_event_data);
    
    // Log final signature with key identifying info
    error_log('InterSoccer Signature: Generated event_signature=' . $data['event_signature'] . ' for Order=' . $order_id . ', Item=' . $item_id . ', Product=' . $product_id . ', Venue=' . $venue . ', Camp/Course=' . ($camp_terms ?: $course_day));

    // Delete any placeholder roster with the same event_signature before inserting real roster
    if (function_exists('intersoccer_delete_placeholder_by_signature')) {
        intersoccer_delete_placeholder_by_signature($data['event_signature']);
    }

    // Insert or update
    $result = $wpdb->replace($table_name, $data);
    $insert_id = $wpdb->insert_id;
    error_log('InterSoccer: Upsert result for order ' . $order_id . ', item ' . $item_id . ': ' . var_export($result, true) . ' | Insert ID: ' . $insert_id . ' | Last DB error: ' . $wpdb->last_error . ' | Last query: ' . $wpdb->last_query);

    if ($result) {
        error_log('InterSoccer: Successfully upserted roster entry for order ' . $order_id . ', item ' . $item_id . ' (ID: ' . $insert_id . ') with event_signature: ' . $data['event_signature']);
        return true;
    } else {
        error_log('InterSoccer: Failed to upsert roster entry for order ' . $order_id . ', item ' . $item_id . ' - Check DB error');
        return false;
    }
}

if (!function_exists('intersoccer_rebuild_event_signature_for_order_item')) {
    /**
     * Recalculate the event signature for a specific roster entry identified by order item ID.
     *
     * @param int $order_item_id
     * @return bool
     */
    function intersoccer_rebuild_event_signature_for_order_item($order_item_id) {
        global $wpdb;

        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, activity_type, venue, age_group, camp_terms, course_day, times, season, girls_only, product_id 
                 FROM {$rosters_table} 
                 WHERE order_item_id = %d 
                 LIMIT 1",
                $order_item_id
            ),
            ARRAY_A
        );

        if (!$record) {
            error_log('InterSoccer: No roster entry found for order item ' . $order_item_id . ' when rebuilding event signature.');
            return false;
        }

        $normalized_data = intersoccer_normalize_event_data_for_signature([
            'activity_type' => $record['activity_type'],
            'venue'         => $record['venue'],
            'age_group'     => $record['age_group'],
            'camp_terms'    => $record['camp_terms'],
            'course_day'    => $record['course_day'],
            'times'         => $record['times'],
            'season'        => $record['season'],
            'girls_only'    => (bool) $record['girls_only'],
            'product_id'    => $record['product_id'],
        ]);

        $signature = intersoccer_generate_event_signature($normalized_data);

        $updated = $wpdb->update(
            $rosters_table,
            ['event_signature' => $signature],
            ['id' => $record['id']],
            ['%s'],
            ['%d']
        );

        if ($updated !== false) {
            error_log('InterSoccer: Rebuilt event signature ' . $signature . ' for order item ' . $order_item_id . '.');
            return true;
        }

        error_log('InterSoccer: Failed to rebuild event signature for order item ' . $order_item_id . ' - DB error: ' . $wpdb->last_error);
        return false;
    }
}

if (!function_exists('intersoccer_align_event_signature_for_variation')) {
    /**
     * Align a roster entry's event signature with existing entries for the same variation.
     *
     * @param int $variation_id
     * @param int $order_item_id
     * @return bool True if aligned with an existing signature, false otherwise.
     */
    function intersoccer_align_event_signature_for_variation($variation_id, $order_item_id) {
        global $wpdb;

        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $existing_signature = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT event_signature 
                 FROM {$rosters_table} 
                 WHERE variation_id = %d 
                   AND order_item_id != %d
                   AND event_signature != ''
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                $variation_id,
                $order_item_id
            )
        );

        if (empty($existing_signature)) {
            return false;
        }

        $updated = $wpdb->update(
            $rosters_table,
            ['event_signature' => $existing_signature],
            ['order_item_id' => $order_item_id],
            ['%s'],
            ['%d']
        );

        if ($updated !== false) {
            error_log(
                sprintf(
                    'InterSoccer: Aligned event signature for order item %d to existing signature %s.',
                    $order_item_id,
                    $existing_signature
                )
            );
            return true;
        }

        error_log(
            sprintf(
                'InterSoccer: Failed to align event signature for order item %d - DB error: %s',
                $order_item_id,
                $wpdb->last_error
            )
        );
        return false;
    }
}

/**
 * Check for required InterSoccer Product Variations plugin dependency
 * This plugin requires intersoccer_get_product_type() and other core functions
 */
add_action('admin_init', function() {
    // Check if the required function exists
if (!function_exists('intersoccer_get_product_type')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><strong>InterSoccer Reports & Rosters:</strong> The <strong>InterSoccer Product Variations</strong> plugin is required and must be activated first.</p>
            </div>
            <?php
        });
        
        // Log the error
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Reports & Rosters: DEPENDENCY ERROR - InterSoccer Product Variations plugin is not active or intersoccer_get_product_type() function is missing');
                }
            }
});

// 2. Enhanced debug function for the Process Orders functionality
function intersoccer_debug_process_orders() {
    error_log('=== InterSoccer: DEBUG PROCESS ORDERS START ===');
    
    // Check if required functions exist
    $required_functions = [
        'intersoccer_get_product_type',
        'intersoccer_update_roster_entry',
        'intersoccer_process_existing_orders'
    ];
    
    foreach ($required_functions as $function) {
        if (function_exists($function)) {
            error_log('InterSoccer: ✓ Function ' . $function . ' exists');
        } else {
            error_log('InterSoccer: ✗ Function ' . $function . ' MISSING');
        }
    }
    
    // Check database table
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rosters_table)) === $rosters_table;
    error_log('InterSoccer: Rosters table exists: ' . ($table_exists ? 'yes' : 'no'));
    
    if ($table_exists) {
        $row_count = $wpdb->get_var("SELECT COUNT(*) FROM $rosters_table");
        error_log('InterSoccer: Rosters table row count: ' . $row_count);
    }
    
    // Check orders to be processed
    $orders = wc_get_orders([
        'limit' => 5, // Just check first 5
        'status' => ['wc-processing', 'wc-on-hold'],
    ]);
    error_log('InterSoccer: Found ' . count($orders) . ' orders with processing/on-hold status');
    
    foreach ($orders as $order) {
        $order_id = $order->get_id();
        error_log('InterSoccer: Order ' . $order_id . ' - Status: ' . $order->get_status() . ', Items: ' . count($order->get_items()));
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $type_id = $variation_id ?: $product_id;
            $product_type = intersoccer_get_product_type_safe($product_id, $variation_id);
            
            $assigned_attendee = wc_get_order_item_meta($item_id, 'Assigned Attendee', true);
            
            error_log('InterSoccer: - Item ' . $item_id . ' - Product: ' . $product_id . ', Variation: ' . $variation_id . ', Type: ' . $product_type . ', Attendee: ' . ($assigned_attendee ?: 'NONE'));
            
            // Only check first item to avoid log spam
            break;
        }
    }
    
    error_log('=== InterSoccer: DEBUG PROCESS ORDERS END ===');
}

// 3. Improved error handling for the update roster entry function
function intersoccer_safe_update_roster_entry($order_id, $item_id) {
    try {
        error_log('InterSoccer: Starting safe_update_roster_entry for order ' . $order_id . ', item ' . $item_id);
        
        // Validate inputs
        if (empty($order_id) || empty($item_id)) {
            error_log('InterSoccer: Invalid parameters - order_id: ' . $order_id . ', item_id: ' . $item_id);
            return false;
        }
        
        // Check if order exists
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('InterSoccer: Order not found: ' . $order_id);
            return false;
        }
        
        // Check if item exists
        $item = $order->get_item($item_id);
        if (!$item) {
            error_log('InterSoccer: Item not found: ' . $item_id . ' in order ' . $order_id);
            return false;
        }
        
        // Check if intersoccer_update_roster_entry function exists
        if (!function_exists('intersoccer_update_roster_entry')) {
            error_log('InterSoccer: intersoccer_update_roster_entry function does not exist');
            return false;
        }
        
        // Call the actual function
        $result = intersoccer_update_roster_entry($order_id, $item_id);
        
        error_log('InterSoccer: safe_update_roster_entry result for order ' . $order_id . ', item ' . $item_id . ': ' . ($result ? 'success' : 'failed'));
        
        return $result;
        
    } catch (Exception $e) {
        error_log('InterSoccer: Exception in safe_update_roster_entry for order ' . $order_id . ', item ' . $item_id . ': ' . $e->getMessage());
        return false;
    }
}

// 4. Add this test function to verify everything is working
function intersoccer_test_process_orders() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    error_log('InterSoccer: Running test_process_orders');
    
    // Run debug check
    intersoccer_debug_process_orders();
    
    // Test with one order
    $orders = wc_get_orders([
        'limit' => 1,
        'status' => ['wc-processing', 'wc-on-hold'],
    ]);
    
    if (empty($orders)) {
        error_log('InterSoccer: No test orders found');
        return;
    }
    
    $order = $orders[0];
    $order_id = $order->get_id();
    
    error_log('InterSoccer: Testing with order ' . $order_id);
    
    foreach ($order->get_items() as $item_id => $item) {
        error_log('InterSoccer: Testing item ' . $item_id);
        $result = intersoccer_safe_update_roster_entry($order_id, $item_id);
        error_log('InterSoccer: Test result: ' . ($result ? 'SUCCESS' : 'FAILED'));
        break; // Only test first item
    }
    
    error_log('InterSoccer: Test completed');
}

// Add this action to run the test (remove after testing)
add_action('admin_init', 'intersoccer_test_process_orders');

function intersoccer_get_product_type_safe($product_id, $variation_id = null) {
    error_log('InterSoccer: get_product_type_safe called with product_id: ' . $product_id . ', variation_id: ' . $variation_id);
    
    // Check if the Product Variations plugin function exists
    if (!function_exists('intersoccer_get_product_type')) {
        error_log('InterSoccer: CRITICAL - intersoccer_get_product_type function not found from Product Variations plugin');
        return 'unknown';
    }
    
    // Try variation ID first if provided
    if ($variation_id && $variation_id > 0) {
        $type = intersoccer_get_product_type($variation_id);
        error_log('InterSoccer: Product type for variation ' . $variation_id . ': ' . var_export($type, true));
        if (!empty($type)) {
            return $type;
        }
    }
    
    // Try parent product ID
    $type = intersoccer_get_product_type($product_id);
    error_log('InterSoccer: Product type for parent ' . $product_id . ': ' . var_export($type, true));
    if (!empty($type)) {
        return $type;
    }
    
    // Manual fallback if the function fails
    error_log('InterSoccer: Manual fallback for product type detection');
    return intersoccer_manual_product_type_detection($product_id, $variation_id);
}

// 2. Manual fallback function based on the Product Variations logic
function intersoccer_manual_product_type_detection($product_id, $variation_id = null) {
    $check_id = $variation_id && $variation_id > 0 ? $variation_id : $product_id;
    
    error_log('InterSoccer: Manual product type detection for ID: ' . $check_id);
    
    // Check existing meta first
    $product_type = get_post_meta($check_id, '_intersoccer_product_type', true);
    if ($product_type) {
        error_log('InterSoccer: Found product type in meta: ' . $product_type);
        return $product_type;
    }
    
    // Check parent meta if this is a variation
    if ($variation_id && $variation_id > 0) {
        $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        if ($product_type) {
            error_log('InterSoccer: Found product type in parent meta: ' . $product_type);
            return $product_type;
        }
    }
    
    // Check categories (use parent product for variations)
    $cat_check_id = $variation_id && $variation_id > 0 ? $product_id : $check_id;
    $categories = wp_get_post_terms($cat_check_id, 'product_cat', array('fields' => 'slugs'));
    
    if (!is_wp_error($categories) && !empty($categories)) {
        error_log('InterSoccer: Categories for product ' . $cat_check_id . ': ' . print_r($categories, true));
        
        if (in_array('camps', $categories, true)) {
            $product_type = 'camp';
        } elseif (in_array('courses', $categories, true)) {
            $product_type = 'course';
        } elseif (in_array('birthdays', $categories, true)) {
            $product_type = 'birthday';
        }
        
        if ($product_type) {
            error_log('InterSoccer: Product type from categories: ' . $product_type);
            // Save to meta for future use
            update_post_meta($check_id, '_intersoccer_product_type', $product_type);
            return $product_type;
        }
    }
    
    // Check product attributes
    $product = wc_get_product($check_id);
    if ($product) {
        $activity_type_attr = $product->get_attribute('pa_activity-type');
        error_log('InterSoccer: Activity type attribute: ' . var_export($activity_type_attr, true));
        
        if (!empty($activity_type_attr)) {
            $normalized = strtolower(trim($activity_type_attr));
            if (strpos($normalized, 'camp') !== false) {
                $product_type = 'camp';
            } elseif (strpos($normalized, 'course') !== false) {
                $product_type = 'course';
            } elseif (strpos($normalized, 'birthday') !== false) {
                $product_type = 'birthday';
            }
            
            if ($product_type) {
                error_log('InterSoccer: Product type from attributes: ' . $product_type);
                update_post_meta($check_id, '_intersoccer_product_type', $product_type);
                return $product_type;
            }
        }
        
        // Try parent product attributes if this is a variation
        if ($variation_id && $variation_id > 0) {
            $parent_product = wc_get_product($product_id);
            if ($parent_product) {
                $parent_activity_type = $parent_product->get_attribute('pa_activity-type');
                error_log('InterSoccer: Parent activity type attribute: ' . var_export($parent_activity_type, true));
                
                if (!empty($parent_activity_type)) {
                    $normalized = strtolower(trim($parent_activity_type));
                    if (strpos($normalized, 'camp') !== false) {
                        $product_type = 'camp';
                    } elseif (strpos($normalized, 'course') !== false) {
                        $product_type = 'course';
                    } elseif (strpos($normalized, 'birthday') !== false) {
                        $product_type = 'birthday';
                    }
                    
                    if ($product_type) {
                        error_log('InterSoccer: Product type from parent attributes: ' . $product_type);
                        update_post_meta($check_id, '_intersoccer_product_type', $product_type);
                        return $product_type;
                    }
                }
            }
        }
    }
    
    error_log('InterSoccer: Could not determine product type manually for ID: ' . $check_id);
    return 'unknown';
}

// 3. Update the intersoccer_update_roster_entry function to use the safe version
// Replace this line in your intersoccer_update_roster_entry function:
// $product_type = intersoccer_get_product_type($type_id);
// With this:
// $product_type = intersoccer_get_product_type_safe($product_id, $variation_id);

// 4. Debug function to test specific problematic products
function intersoccer_debug_specific_products() {
    if (!current_user_can('manage_options')) return;
    
    error_log('=== DEBUG SPECIFIC PRODUCTS ===');
    
    // Test the problematic products from your logs
    $test_products = [
        ['product_id' => 25232, 'variation_id' => 35888], // Ray Cazin - Type: empty
        ['product_id' => 25222, 'variation_id' => 28079], // Murtaja Al-Hamad - Type: empty
        ['product_id' => 25222, 'variation_id' => 28081], // Frederick Mcintire - Type: camp (working)
    ];
    
    foreach ($test_products as $test) {
        error_log('Testing product ' . $test['product_id'] . ', variation ' . $test['variation_id']);
        
        // Test original function if available
        if (function_exists('intersoccer_get_product_type')) {
            $original_result = intersoccer_get_product_type($test['variation_id']);
            error_log('Original function result for variation: ' . var_export($original_result, true));
            
            $original_parent = intersoccer_get_product_type($test['product_id']);
            error_log('Original function result for parent: ' . var_export($original_parent, true));
        }
        
        // Test safe function
        $safe_result = intersoccer_get_product_type_safe($test['product_id'], $test['variation_id']);
        error_log('Safe function result: ' . var_export($safe_result, true));
        
        // Check meta and categories directly
        $variation_meta = get_post_meta($test['variation_id'], '_intersoccer_product_type', true);
        $parent_meta = get_post_meta($test['product_id'], '_intersoccer_product_type', true);
        $categories = wp_get_post_terms($test['product_id'], 'product_cat', array('fields' => 'slugs'));
        
        error_log('Variation meta: ' . var_export($variation_meta, true));
        error_log('Parent meta: ' . var_export($parent_meta, true));
        error_log('Parent categories: ' . print_r($categories, true));
        
        error_log('---');
    }
    
    error_log('=== END DEBUG SPECIFIC PRODUCTS ===');
}

// Uncomment to run the debug test
add_action('admin_init', 'intersoccer_debug_specific_products');

/**
 * Normalizes event data to English for consistent event signature generation.
 * This ensures that orders placed in different languages are grouped with the correct rosters.
 *
 * @param array $event_data Array containing event characteristics
 * @return array Normalized event data in English
 */
function intersoccer_normalize_event_data_for_signature($event_data) {
    // Store current language if using WPML
    $current_lang = '';
    if (function_exists('wpml_get_current_language')) {
        $current_lang = wpml_get_current_language();
    }

    // Switch to default language to get English values
    $default_lang = '';
    if (function_exists('wpml_get_default_language')) {
        $default_lang = wpml_get_default_language();
        if ($current_lang !== $default_lang) {
            do_action('wpml_switch_language', $default_lang);
        }
    }

    $normalized = $event_data;

    try {
        // For taxonomy-based attributes, the order metadata contains translated names
        // We need to find the term by name in current language, then get the name in default language

        // Normalize venue (taxonomy term name)
        if (!empty($event_data['venue'])) {
            $term = intersoccer_get_term_by_translated_name($event_data['venue'], 'pa_intersoccer-venues');
            if ($term) {
                $normalized['venue'] = $term->name;
            }
        }

        // Normalize age_group (taxonomy term name)
        if (!empty($event_data['age_group'])) {
            $term = intersoccer_get_term_by_translated_name($event_data['age_group'], 'pa_age-group');
            if ($term) {
                $normalized['age_group'] = $term->name;
            }
        }

        // Normalize camp_terms (taxonomy term name)
        if (!empty($event_data['camp_terms'])) {
            $term = intersoccer_get_term_by_translated_name($event_data['camp_terms'], 'pa_camp-terms');
            if ($term) {
                $normalized['camp_terms'] = $term->name;
            }
        }

        // Normalize course_day (taxonomy term name)
        if (!empty($event_data['course_day'])) {
            $term = intersoccer_get_term_by_translated_name($event_data['course_day'], 'pa_course-day');
            if ($term) {
                $normalized['course_day'] = $term->name;
            }
        }

        // Normalize times (taxonomy term name) - try different taxonomies
        if (!empty($event_data['times'])) {
            $term = null;
            $taxonomies = ['pa_camp-times', 'pa_course-times'];
            foreach ($taxonomies as $taxonomy) {
                $term = intersoccer_get_term_by_translated_name($event_data['times'], $taxonomy);
                if ($term) break;
            }
            if ($term) {
                $normalized['times'] = $term->name;
            }
        }

        // Normalize season (taxonomy term name)
        if (!empty($event_data['season'])) {
            $normalized['season'] = $event_data['season'];
            $term = intersoccer_get_term_by_translated_name($event_data['season'], 'pa_program-season');
            if ($term) {
                $normalized['season'] = $term->name;
            }
            // Manual normalization as fallback to ensure English
            $normalized['season'] = str_ireplace('Hiver', 'Winter', $normalized['season']);
            $normalized['season'] = str_ireplace('hiver', 'winter', $normalized['season']);
            $normalized['season'] = str_ireplace('Été', 'Summer', $normalized['season']);
            $normalized['season'] = str_ireplace('été', 'summer', $normalized['season']);
            $normalized['season'] = str_ireplace('Printemps', 'Spring', $normalized['season']);
            $normalized['season'] = str_ireplace('printemps', 'spring', $normalized['season']);
            $normalized['season'] = str_ireplace('Automne', 'Autumn', $normalized['season']);
            $normalized['season'] = str_ireplace('automne', 'autumn', $normalized['season']);
            // Capitalize first word
            $normalized['season'] = ucfirst(strtolower($normalized['season']));
        }

        // Normalize activity_type - this might be a direct value, not a taxonomy term
        if (!empty($event_data['activity_type'])) {
            // Check if it's a taxonomy term first
            $term = intersoccer_get_term_by_translated_name($event_data['activity_type'], 'pa_activity-type');
            if ($term) {
                $normalized['activity_type'] = $term->name;
            } else {
                // If not a taxonomy term, normalize the string directly
                $normalized['activity_type'] = intersoccer_normalize_activity_type($event_data['activity_type']);
            }
        }

        error_log('InterSoccer: Normalized event data for signature: ' . json_encode([
            'original' => $event_data,
            'normalized' => $normalized
        ]));

    } catch (Exception $e) {
        error_log('InterSoccer: Error normalizing event data: ' . $e->getMessage());
        // Return original data if normalization fails
        $normalized = $event_data;
    }

    // Switch back to original language
    if (!empty($current_lang) && $current_lang !== $default_lang && function_exists('do_action')) {
        do_action('wpml_switch_language', $current_lang);
    }

    return $normalized;
}

/**
 * Helper function to get term by translated name and return it in default language
 */
function intersoccer_get_term_by_translated_name($translated_name, $taxonomy) {
    // Get all terms in the taxonomy
    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
        'lang' => '' // Get all language versions
    ]);

    if (is_wp_error($terms)) {
        return null;
    }

    // Find the term that matches the translated name
    foreach ($terms as $term) {
        // Check if this term's name in any language matches
        if ($term->name === $translated_name) {
            return $term;
        }

        // Also check WPML translations if available
        if (function_exists('wpml_get_element_translations')) {
            $translations = wpml_get_element_translations($term->term_id, 'tax_' . $taxonomy);
            foreach ($translations as $translation) {
                if ($translation->name === $translated_name) {
                    return $term; // Return the original term
                }
            }
        }
    }

    return null;
}

/**
 * Normalize activity type string to English
 * 
 * Note: This function may already be defined in the intersoccer-product-variations plugin.
 * If so, that version will be used (it's more comprehensive).
 */
if (!function_exists('intersoccer_normalize_activity_type')) {
    function intersoccer_normalize_activity_type($activity_type) {
        // Convert to lowercase and remove extra spaces
        $normalized = strtolower(trim($activity_type));

        // Handle common translations
        $translations = [
            'camp' => 'camp',
            'cours' => 'course', // French for course
            'camp de vacances' => 'camp',
            'stage' => 'course',
            'anniversaire' => 'birthday',
        ];

        foreach ($translations as $english => $pattern) {
            if (strpos($normalized, $pattern) !== false) {
                return $english;
            }
        }

        // If no match found, return as-is but normalized
        return $normalized;
    }
}

/**
 * Generates a stable event signature for roster grouping that doesn't rely on variation_id.
 * This ensures rosters remain properly grouped even when product variations are deleted.
 *
 * @param array $event_data Array containing event characteristics
 * @return string MD5 hash of the event signature
 */
function intersoccer_generate_event_signature($event_data) {
    // Normalize translatable term names to slugs for language-agnostic signatures
    $normalized_components = [
        'activity_type' => $event_data['activity_type'] ?? '',
        'venue' => intersoccer_get_term_slug_by_name($event_data['venue'] ?? '', 'pa_intersoccer-venues'),
        'age_group' => intersoccer_get_term_slug_by_name($event_data['age_group'] ?? '', 'pa_age-group'),
        'camp_terms' => $event_data['camp_terms'] ?? '',
        'course_day' => intersoccer_get_term_slug_by_name($event_data['course_day'] ?? '', 'pa_course-day'),
        'times' => $event_data['times'] ?? '',
        'season' => intersoccer_get_term_slug_by_name($event_data['season'] ?? '', 'pa_program-season'),
        'girls_only' => $event_data['girls_only'] ? '1' : '0',
        'product_id' => $event_data['product_id'] ?? '',
    ];

    // Create a normalized string from components
    $signature_string = implode('|', array_map(function($key, $value) {
        return $key . ':' . trim(strtolower($value));
    }, array_keys($normalized_components), $normalized_components));

    // Generate MD5 hash for consistent length and comparison
    $signature = md5($signature_string);

    error_log('InterSoccer: Generated normalized event signature: ' . $signature . ' from components: ' . json_encode($normalized_components));

    return $signature;
}

/**
 * Get term slug by name for normalization
 */
function intersoccer_get_term_slug_by_name($name, $taxonomy) {
    if (empty($name) || empty($taxonomy)) {
        return $name; // Return as-is if empty
    }
    $term = get_term_by('name', $name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        return $term->slug;
    }
    // If not found by name, try as slug already
    $term = get_term_by('slug', $name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        return $term->slug;
    }
    // Fallback to original name if term not found
    return $name;
}

/**
 * Normalize season for display in English
 */
function intersoccer_normalize_season_for_display($season) {
    if (empty($season)) return $season;

    $normalized = str_ireplace('Hiver', 'Winter', $season);
    $normalized = str_ireplace('hiver', 'winter', $normalized);
    $normalized = str_ireplace('Été', 'Summer', $normalized);
    $normalized = str_ireplace('été', 'summer', $normalized);
    $normalized = str_ireplace('Printemps', 'Spring', $normalized);
    $normalized = str_ireplace('printemps', 'spring', $normalized);
    $normalized = str_ireplace('Automne', 'Autumn', $normalized);
    $normalized = str_ireplace('automne', 'autumn', $normalized);

    return $normalized;
}

/**
 * Parse camp dates from camp_terms string
 * @param string $camp_terms The camp terms string
 * @param string $season The season/year
 * @return array [$start_date, $end_date, $event_dates]
 */
function intersoccer_parse_camp_dates_fixed($camp_terms, $season) {
    $start_date = null;
    $end_date = null;
    $event_dates = 'N/A';

    if (empty($camp_terms) || $camp_terms === 'N/A') {
        return [$start_date, $end_date, $event_dates];
    }

    // Try first regex pattern: month-week-X-month-day-month-day-days
    if (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\w+)-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
        $start_month = $matches[2];
        $start_day = $matches[3];
        $end_month = $matches[4];
        $end_day = $matches[5];
        $year = $season && is_numeric($season) ? $season : date('Y');

        $start_date_obj = DateTime::createFromFormat('F j Y', "$start_month $start_day $year");
        $end_date_obj = DateTime::createFromFormat('F j Y', "$end_month $end_day $year");

        if ($start_date_obj && $end_date_obj) {
            $start_date = $start_date_obj->format('Y-m-d');
            $end_date = $end_date_obj->format('Y-m-d');
            $event_dates = "$start_date to $end_date";
        }
    }
    // Try second regex pattern: month-week-X-month-day-day-days
    elseif (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
        $month = $matches[2];
        $start_day = $matches[3];
        $end_day = $matches[4];
        $year = $season && is_numeric($season) ? $season : date('Y');

        $start_date_obj = DateTime::createFromFormat('F j Y', "$month $start_day $year");
        $end_date_obj = DateTime::createFromFormat('F j Y', "$month $end_day $year");

        if ($start_date_obj && $end_date_obj) {
            $start_date = $start_date_obj->format('Y-m-d');
            $end_date = $end_date_obj->format('Y-m-d');
            $event_dates = "$start_date to $end_date";
        }
    }

    return [$start_date, $end_date, $event_dates];
}
