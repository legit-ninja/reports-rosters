<?php
/**
 * File: woocommerce-orders.php
 * Description: Handles WooCommerce order status changes to populate the intersoccer_rosters table and auto-complete orders for the InterSoccer Product Variations plugin.
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
 * Version: 1.4.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Diagnostic log to confirm file inclusion (fires on every page load if included)
error_log('InterSoccer: woocommerce-orders.php file loaded');

// Hook into order status change to processing
add_action('woocommerce_order_status_processing', 'intersoccer_populate_rosters_and_complete_order');
function intersoccer_populate_rosters_and_complete_order($order_id) {
    error_log('InterSoccer: Function intersoccer_populate_rosters_and_complete_order called for order ' . $order_id); // Entry log

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('InterSoccer: Invalid order ID ' . $order_id . ' in intersoccer_populate_rosters_and_complete_order');
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'intersoccer_rosters';

    // Log order details for diagnosis
    error_log('InterSoccer: Processing order ' . $order_id . ' for roster population. Status: ' . $order->get_status() . ', Items count: ' . count($order->get_items()));

    $inserted = false;
    foreach ($order->get_items() as $item_id => $item) {
        error_log('InterSoccer: Processing item ' . $item_id . ' in order ' . $order_id); // Per-item log

        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id() ?: $product_id;
        $product_type = intersoccer_get_product_type($product_id);
        error_log('InterSoccer: Item ' . $item_id . ' product_type: ' . $product_type . ', variation_id: ' . $variation_id);

        if (!in_array($product_type, ['camp', 'course', 'birthday'])) {
            error_log('InterSoccer: Skipping non-event item ' . $item_id . ' in order ' . $order_id . ' (type: ' . $product_type . ')');
            continue;
        }

        // Check for duplicate entry
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE order_item_id = %d", $item_id));
        if ($exists) {
            error_log('InterSoccer: Skipping duplicate roster entry for item ' . $item_id . ' in order ' . $order_id . ' (existing ID: ' . $exists . ')');
            continue;
        }

        // Get raw order item meta
        $raw_order_item_meta = wc_get_order_item_meta($item_id, '', true);
        error_log('InterSoccer: Raw order item meta for ' . $item_id . ': ' . print_r($raw_order_item_meta, true));

        // Process meta into flat array, normalizing keys
        $order_item_meta = array_combine(
            array_keys($raw_order_item_meta),
            array_map(function ($value) {
                return is_array($value) ? $value[0] ?? implode(', ', array_map('trim', $value)) : trim($value);
            }, array_values($raw_order_item_meta))
        );

        // Handle Activity Type with fallbacks (aligned with advanced.php)
        $activity_type = $order_item_meta['Activity Type'] ?? null;
        error_log('InterSoccer: Raw Activity Type from meta for item ' . $item_id . ': ' . print_r($activity_type, true));
        if ($activity_type) {
            $activity_type = trim(strtolower(html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $activity_types = array_map('trim', explode(',', $activity_type));
            error_log('InterSoccer: Processed activity_types from meta for item ' . $item_id . ': ' . print_r($activity_types, true));
            if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
                $activity_type = 'Girls Only';
                error_log('InterSoccer: Assigned Girls Only from meta for item ' . $item_id);
            } else {
                $activity_type = implode(', ', array_map('ucfirst', $activity_types));
                error_log('InterSoccer: Defaulted to joined activity_types from meta for item ' . $item_id . ': ' . $activity_type);
            }
        } else {
            $variation = wc_get_product($variation_id);
            $variation_activity_type = $variation ? $variation->get_attribute('pa_activity-type') : null;
            error_log('InterSoccer: Raw pa_activity-type from variation for item ' . $item_id . ': ' . print_r($variation_activity_type, true));
            if ($variation_activity_type) {
                if (is_array($variation_activity_type)) {
                    $variation_activity_type = implode(', ', array_map('trim', $variation_activity_type));
                }
                $activity_type = trim(strtolower(html_entity_decode($variation_activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $activity_types = array_map('trim', explode(',', $activity_type));
                error_log('InterSoccer: Processed activity_types from variation for item ' . $item_id . ': ' . print_r($activity_types, true));
                if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
                    $activity_type = 'Girls Only';
                    error_log('InterSoccer: Assigned Girls Only from variation for item ' . $item_id);
                } elseif (!empty($activity_types[0])) {
                    $activity_type = ucfirst($activity_types[0]);
                    error_log('InterSoccer: Defaulted to first activity_type from variation for item ' . $item_id . ': ' . $activity_type);
                } else {
                    if ($variation->get_attribute('pa_course-day')) {
                        $activity_type = 'Course';
                        error_log('InterSoccer: Assigned Course based on pa_course-day for item ' . $item_id);
                    } elseif ($variation->get_attribute('pa_camp-terms')) {
                        $activity_type = 'Camp';
                        error_log('InterSoccer: Assigned Camp based on pa_camp-terms for item ' . $item_id);
                    } else {
                        $activity_type = 'Unknown';
                        error_log('InterSoccer: No activity type indicators found, defaulting to Unknown for item ' . $item_id);
                    }
                }
            } else {
                if (isset($order_item_meta['pa_course-day'])) {
                    $activity_type = 'Course';
                    error_log('InterSoccer: Assigned Course based on pa_course-day in meta for item ' . $item_id);
                } elseif (isset($order_item_meta['pa_camp-terms'])) {
                    $activity_type = 'Camp';
                    error_log('InterSoccer: Assigned Camp based on pa_camp-terms in meta for item ' . $item_id);
                } else {
                    $activity_type = 'Unknown';
                    error_log('InterSoccer: No activity type indicators found, defaulting to Unknown for item ' . $item_id);
                }
            }
        }

        // Assigned Attendee(s) handling (singular or plural)
        $assigned_attendees = $order_item_meta['Assigned Attendees'] ?? $order_item_meta['Assigned Attendee'] ?? null;
        $attendees = is_array($assigned_attendees) ? $assigned_attendees : [$assigned_attendees];
        if (empty($attendees[0])) {
            error_log('InterSoccer: No Assigned Attendee found for item ' . $item_id . ' in order ' . $order_id);
            continue;
        }

        foreach ($attendees as $assigned_attendee) {
            $player_name_parts = explode(' ', trim($assigned_attendee), 2);
            $first_name = $player_name_parts[0] ?? 'Unknown';
            $last_name = $player_name_parts[1] ?? 'Unknown';
            $player_name = trim($first_name . ' ' . $last_name);

            // Lookup full player details from user meta
            $user_id = $order->get_user_id();
            $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
            error_log('InterSoccer: User ' . $user_id . ' players meta for item ' . $item_id . ': ' . print_r($players, true)); // Log full players array
            $player_index = $order_item_meta['assigned_player'] ?? false;
            $age = isset($order_item_meta['Player Age']) ? (int)$order_item_meta['Player Age'] : null;
            $gender = $order_item_meta['Player Gender'] ?? 'N/A';
            $medical_conditions = $order_item_meta['Medical Conditions'] ?? '';
            $dietary_needs = $order_item_meta['Dietary Needs'] ?? '';
            $dob = null;
            if ($player_index !== false && is_array($players) && isset($players[$player_index])) {
                $player = $players[$player_index];
                $first_name = $player['first_name'] ?? $first_name;
                $last_name = $player['last_name'] ?? $last_name;
                $dob = $player['dob'] ?? null;
                $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                $gender = $player['gender'] ?? $gender;
                $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                $dietary_needs = $player['dietary_needs'] ?? $dietary_needs;
                error_log('InterSoccer: Matched player by index ' . $player_index . ' for item ' . $item_id . ': ' . print_r($player, true));
            } else {
                $player_full_name = trim("$first_name $last_name");
                foreach ($players as $player) {
                    if (trim($player['first_name'] . ' ' . $player['last_name']) === $player_full_name) {
                        $dob = $player['dob'] ?? null;
                        $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                        $gender = $player['gender'] ?? $gender;
                        $medical_conditions = $player['medical_conditions'] ?? $medical_conditions;
                        $dietary_needs = $player['dietary_needs'] ?? $dietary_needs;
                        error_log('InterSoccer: Matched player by name ' . $player_full_name . ' for item ' . $item_id . ': ' . print_r($player, true));
                        break;
                    }
                }
            }

            // Parent info
            $parent_first_name = $order->get_billing_first_name() ?: 'Unknown';
            $parent_last_name = $order->get_billing_last_name() ?: 'Unknown';
            $parent_email = $order->get_billing_email() ?: 'N/A';
            $parent_phone = $order->get_billing_phone() ?: 'N/A';
            $emergency_contact = $order_item_meta['Emergency Contact'] ?? $parent_phone;

            // Event details
            $booking_type = $order_item_meta['pa_booking-type'] ?? $order_item_meta['Booking Type'] ?? 'Unknown';
            $selected_days = $order_item_meta['Days Selected'] ?? $order_item_meta['days_selected'] ?? 'N/A';
            $camp_terms = $order_item_meta['pa_camp-terms'] ?? $order_item_meta['Camp Terms'] ?? 'N/A';
            $venue = $order_item_meta['pa_intersoccer-venues'] ?? $order_item_meta['InterSoccer Venues'] ?? 'Unknown Venue';
            $age_group = $order_item_meta['pa_age-group'] ?? $order_item_meta['Age Group'] ?? 'N/A';
            $course_day = ($activity_type === 'Course') ? ($order_item_meta['pa_course-day'] ?? $order_item_meta['Course Day'] ?? 'N/A') : 'N/A';
            $times = $order_item_meta['Camp Times'] ?? $order_item_meta['Course Times'] ?? $order_item_meta['times'] ?? 'N/A';
            $term = $order_item_meta['term'] ?? $camp_terms ?? $course_day ?? 'N/A';
            $season = $order_item_meta['Season'] ?? $order_item_meta['season'] ?? $order_item_meta['pa_program-season'] ?? 'N/A';
            $canton_region = $order_item_meta['Canton / Region'] ?? $order_item_meta['canton_region'] ?? 'N/A';
            $city = $order_item_meta['City'] ?? $order_item_meta['city'] ?? 'N/A';

            // Date parsing (aligned with advanced.php)
            $start_date = null;
            $end_date = null;
            $event_dates = 'N/A';
            $season_year = null;
            if (preg_match('/(\d{4})/', $season, $year_matches)) {
                $season_year = $year_matches[0];
            } elseif (preg_match('/(\d{4})/', $order_item_meta['pa_program-season'] ?? '', $year_matches)) {
                $season_year = $year_matches[0];
            } else {
                $season_year = date('Y', strtotime($order->get_date_created()->format('Y-m-d'))) ?: date('Y');
            }
            error_log('InterSoccer: Season year for item ' . $item_id . ': ' . $season_year);

            if ($activity_type === 'Camp' && $camp_terms !== 'N/A') {
                if (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\w+)-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                    $start_month = $matches[2];
                    $start_day = $matches[3];
                    $end_month = $matches[4];
                    $end_day = $matches[5];
                    $start_date_obj = DateTime::createFromFormat('F j Y', "$start_month $start_day $season_year");
                    $end_date_obj = DateTime::createFromFormat('F j Y', "$end_month $end_day $season_year");
                    if ($start_date_obj && $end_date_obj) {
                        $start_date = $start_date_obj->format('Y-m-d');
                        $end_date = $end_date_obj->format('Y-m-d');
                        $event_dates = "$start_date to $end_date";
                    }
                } elseif (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                    $month = $matches[2];
                    $start_day = $matches[3];
                    $end_day = $matches[4];
                    $start_date_obj = DateTime::createFromFormat('F j Y', "$month $start_day $season_year");
                    $end_date_obj = DateTime::createFromFormat('F j Y', "$month $end_day $season_year");
                    if ($start_date_obj && $end_date_obj) {
                        $start_date = $start_date_obj->format('Y-m-d');
                        $end_date = $end_date_obj->format('Y-m-d');
                        $event_dates = "$start_date to $end_date";
                    }
                }
            } elseif ($activity_type === 'Course' && !empty($order_item_meta['Start Date']) && !empty($order_item_meta['End Date'])) {
                $start_date = DateTime::createFromFormat('m/d/Y', $order_item_meta['Start Date'])->format('Y-m-d');
                $end_date = DateTime::createFromFormat('m/d/Y', $order_item_meta['End Date'])->format('Y-m-d');
                $event_dates = "$start_date to $end_date";
            }

            // Other fields
            $late_pickup = $order_item_meta['Late Pickup'] ?? 'No';
            $product_name = $item->get_name();
            $shirt_size = 'N/A';
            $shorts_size = 'N/A';
            if ($activity_type === 'Girls Only') {
                $possible_shirt_keys = ['pa_what-size-t-shirt-does-your', 'pa_tshirt-size', 'pa_what-size-t-shirt-does-your-child-wear', 'Shirt Size', 'T-shirt Size'];
                $possible_shorts_keys = ['pa_what-size-shorts-does-your-c', 'pa_what-size-shorts-does-your-child-wear', 'Shorts Size', 'Shorts'];
                foreach ($possible_shirt_keys as $key) {
                    if (isset($order_item_meta[$key]) && $order_item_meta[$key] !== '') {
                        $shirt_size = $order_item_meta[$key];
                        break;
                    }
                }
                foreach ($possible_shorts_keys as $key) {
                    if (isset($order_item_meta[$key]) && $order_item_meta[$key] !== '') {
                        $shorts_size = $order_item_meta[$key];
                        break;
                    }
                }
            }
            $registration_timestamp = $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null;

            $day_presence = ['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No'];
            if (strtolower($booking_type) === 'single-days') {
                $days = array_map('trim', explode(',', $selected_days));
                foreach ($days as $day) {
                    if (isset($day_presence[$day])) {
                        $day_presence[$day] = 'Yes';
                    }
                }
            } elseif (strtolower($booking_type) === 'full-week') {
                $day_presence = ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
            }
            $day_presence_json = json_encode($day_presence);

            // Prepare full data array for all 46 fields
            $data = [
                'order_id' => $order_id,
                'order_item_id' => $item_id,
                'variation_id' => $variation_id,
                'player_name' => $player_name,
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
                'day_presence' => $day_presence_json,
                'age_group' => $age_group,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'event_dates' => $event_dates,
                'product_name' => $product_name,
                'activity_type' => $activity_type,
                'shirt_size' => $shirt_size,
                'shorts_size' => $shorts_size,
                'registration_timestamp' => $registration_timestamp,
                'course_day' => $course_day,
                'product_id' => $product_id,
                'player_first_name' => $first_name,
                'player_last_name' => $last_name,
                'player_dob' => $dob ? date('Y-m-d', strtotime($dob)) : null,
                'player_gender' => $gender,
                'player_medical' => $medical_conditions,
                'player_dietary' => $dietary_needs,
                'parent_first_name' => $parent_first_name,
                'parent_last_name' => $parent_last_name,
                'emergency_contact' => $emergency_contact,
                'term' => $term,
                'times' => $times,
                'days_selected' => $selected_days,
                'season' => $season,
                'canton_region' => $canton_region,
                'city' => $city,
            ];

            // Insert into table
            $result = $wpdb->insert($table_name, $data);
            if ($result) {
                $inserted = true;
                error_log('InterSoccer: Inserted roster entry for order ' . $order_id . ', item ' . $item_id . ': ' . print_r($data, true));
            } else {
                error_log('InterSoccer: Failed to insert roster entry for order ' . $order_id . ', item ' . $item_id . ': ' . $wpdb->last_error);
            }
        }
    }

    // If at least one insertion succeeded, transition to complete
    if ($inserted) {
        $order->update_status('completed', 'Automatically completed after populating rosters.');
        error_log('InterSoccer: Order ' . $order_id . ' transitioned to completed after roster population');
    } else {
        error_log('InterSoccer: No rosters inserted for order ' . $order_id . ', status not changed');
    }

    // Verification query
    $verification_query = $wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id);
    $results = $wpdb->get_results($verification_query);
    error_log('InterSoccer: Verification query results for order ' . $order_id . ': ' . print_r($results, true));
}