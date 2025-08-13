<?php
/**
 * File: woocommerce-orders.php
 * Description: Handles WooCommerce order status changes to populate the intersoccer_rosters table and auto-complete orders for the InterSoccer Reports and Rosters plugin.
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
 * Version: 1.4.42 // Incremented for date fix
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Diagnostic log to confirm file inclusion
error_log('InterSoccer: woocommerce-orders.php file loaded');

// Define known Girls Only variation IDs
$girls_only_variation_ids = ['32648', '32649', '33957', '32645', '32641'];


// Hook into order status change to processing
add_action('woocommerce_order_status_processing', 'intersoccer_populate_rosters_and_complete_order');
function intersoccer_populate_rosters_and_complete_order($order_id) {
    error_log('InterSoccer: Function intersoccer_populate_rosters_and_complete_order called for order ' . $order_id); // Entry log

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('InterSoccer: Invalid order ID ' . $order_id . ' in intersoccer_populate_rosters_and_complete_order');
        return;
    }

    global $wpdb, $girls_only_variation_ids;  // Globalize to access in function
    $table_name = $wpdb->prefix . 'intersoccer_rosters';

    // Log order details for diagnosis
    error_log('InterSoccer: Processing order ' . $order_id . ' for roster population. Status: ' . $order->get_status() . ', Items count: ' . count($order->get_items()));

    $inserted = false;
    $date_created = $order->get_date_created();
    error_log('InterSoccer: date_created type for order ' . $order_id . ': ' . gettype($date_created) . ', value: ' . var_export($date_created, true)); // Added log
    if ($date_created instanceof WC_DateTime) {
        $order_date = $date_created->format('Y-m-d H:i:s');
    } else {
        error_log('InterSoccer: Order date_created is not WC_DateTime for order ' . $order_id . ', using current time as fallback');
        $order_date = current_time('mysql');
    }
    $parent_phone = $order->get_billing_phone() ?: 'N/A';
    $parent_email = $order->get_billing_email() ?: 'N/A';
    $parent_first_name = $order->get_billing_first_name() ?: 'Unknown';
    $parent_last_name = $order->get_billing_last_name() ?: 'Unknown';

    foreach ($order->get_items('line_item') as $item_id => $item) {
        error_log('InterSoccer: Processing item ' . $item_id . ' in order ' . $order_id); // Per-item log

        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $type_id = $variation_id ?: $product_id; // Use variation for detection if present
        $product_type = intersoccer_get_product_type($type_id);
        error_log('InterSoccer: Item ' . $item_id . ' product_type: ' . $product_type . ' (using ID: ' . $type_id . ', parent: ' . $product_id . ', variation: ' . $variation_id . ')');

        if (!in_array($product_type, ['camp', 'course', 'birthday'])) {
            error_log('InterSoccer: Skipping item ' . $item_id . ' in order ' . $order_id . ' - Reason: Non-event product type (' . $product_type . ')');
            continue;
        }

        // Check for duplicate entry
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE order_item_id = %d", $item_id));
        if ($exists) {
            error_log('InterSoccer: Skipping item ' . $item_id . ' in order ' . $order_id . ' - Reason: Duplicate roster entry (existing ID: ' . $exists . ')');
            continue;
        }

        // Get item metadata
        $item_meta = [];
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $item_meta[$data['key']] = $data['value'];
        }
        $raw_order_item_meta = wc_get_order_item_meta($item_id, '', true);  // Raw for activity_type logic
        error_log('InterSoccer: Item metadata for ' . $item_id . ': ' . print_r($item_meta, true));
        error_log('InterSoccer: Raw order item meta for order ' . $order_id . ', item ' . $item_id . ': ' . print_r($raw_order_item_meta, true));

        // Assigned Attendee
        $assigned_attendee = isset($item_meta['Assigned Attendee']) ? trim($item_meta['Assigned Attendee']) : '';
        if (empty($assigned_attendee)) {
            error_log('InterSoccer: Skipping item ' . $item_id . ' in order ' . $order_id . ' - Reason: No Assigned Attendee found');
            continue;
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

        // Lookup full player details from user meta
        $user_id = $order->get_user_id();
        $players = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true)) ?: [];
        error_log('InterSoccer: User ' . $user_id . ' players meta: ' . print_r($players, true)); // Log full players array
        $player_index = $item_meta['assigned_player'] ?? false;
        $age = isset($item_meta['Player Age']) ? (int)$item_meta['Player Age'] : null;
        $gender = $item_meta['Player Gender'] ?? 'N/A';
        $medical_conditions = $item_meta['Medical Conditions'] ?? '';
        $avs_number = 'N/A'; // Default
        $dob = null;
        $matched = false;
        $player = null; // Initialize
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
                    $dob = $player['dob'] ?? null;
                    $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                    $gender = $player['gender'] ?? $gender;
                    $medical_conditions = trim($player['medical_conditions'] ?? $medical_conditions);
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
                        $medical_conditions = trim($player['medical_conditions'] ?? $medical_conditions);
                        $avs_number = $player['avs_number'] ?? 'N/A';
                        error_log("InterSoccer: Fallback first-name match for attendee $assigned_attendee in order $order_id item $item_id");
                        break;
                    }
                }
            }
        }

        error_log('InterSoccer: Player lookup for ' . $assigned_attendee . ' (user_id: ' . $user_id . ') in order ' . $order_id . ': ' . ($matched ? 'Matched - AVS: ' . $avs_number : 'Not matched, default N/A'));

        if (!$player) {
            error_log('InterSoccer: Player not found in user meta for ' . $assigned_attendee . ' (user_id: ' . $user_id . ') in order ' . $order_id);
            continue;
        }
        error_log('InterSoccer: Found player details for ' . $assigned_attendee . ': ' . print_r($player, true));

        // Parent info from order billing
        $parent_first = $order->get_billing_first_name() ?: 'Unknown';
        $parent_last = $order->get_billing_last_name() ?: 'Unknown';
        $parent_email = $order->get_billing_email() ?: 'N/A';
        $parent_phone = $order->get_billing_phone() ?: 'N/A';

        // Emergency contact (fallback to parent phone if not set in player)
        $emergency_contact = $player['emergency_contact'] ?? $parent_phone;

        // Event details from item meta
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
        $course_day = $item_meta['pa_course-day'] ?? $item_meta['Course Day'] ?? 'N/A';
        $late_pickup = $item_meta['Late Pickup'] ?? 'No';
        $product_name = $item->get_name();
        $shirt_size = 'N/A';
        $shorts_size = 'N/A';
        if (strpos($activity_type, 'Girls Only') !== false || in_array($variation_id, $girls_only_variation_ids)) {
            $possible_shirt_keys = ['pa_what-size-t-shirt-does-your', 'pa_tshirt-size', 'pa_what-size-t-shirt-does-your-child-wear', 'Shirt Size', 'T-shirt Size'];
            $possible_shorts_keys = ['pa_what-size-shorts-does-your-c', 'pa_what-size-shorts-does-your-child-wear', 'Shorts Size', 'Shorts'];
            foreach ($possible_shirt_keys as $key) {
                if (isset($item_meta[$key]) && $item_meta[$key] !== '') {
                    $shirt_size = substr(trim($item_meta[$key]), 0, 50);
                    break;
                }
            }
            foreach ($possible_shorts_keys as $key) {
                if (isset($item_meta[$key]) && $item_meta[$key] !== '') {
                    $shorts_size = substr(trim($item_meta[$key]), 0, 50);
                    break;
                }
            }
        }

        // Parse start_date and end_date for camps
        if ($product_type === 'camp' && $camp_terms) {
            if (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\w+)-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                $start_month = $matches[2];
                $start_day = $matches[3];
                $end_month = $matches[4];
                $end_day = $matches[5];
                $year = $season ? substr($season, 0, 4) : (date('Y', strtotime($order_date)) ?: date('Y'));
                $start_date_obj = DateTime::createFromFormat('F j Y', "$start_month $start_day $year");
                $end_date_obj = DateTime::createFromFormat('F j Y', "$end_month $end_day $year");
                if ($start_date_obj && $end_date_obj) {
                    $start_date = $start_date_obj->format('Y-m-d');
                    $end_date = $end_date_obj->format('Y-m-d');
                    $event_dates = "$start_date to $end_date";
                } else {
                    error_log("InterSoccer: Date parsing failed for camp_terms $camp_terms in order $order_id item $item_id");
                }
            } elseif (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                $month = $matches[2];
                $start_day = $matches[3];
                $end_day = $matches[4];
                $year = $season ? substr($season, 0, 4) : (date('Y', strtotime($order_date)) ?: date('Y'));
                $start_date_obj = DateTime::createFromFormat('F j Y', "$month $start_day $year");
                $end_date_obj = DateTime::createFromFormat('F j Y', "$month $end_day $year");
                if ($start_date_obj && $end_date_obj) {
                    $start_date = $start_date_obj->format('Y-m-d');
                    $end_date = $end_date_obj->format('Y-m-d');
                    $event_dates = "$start_date to $end_date";
                } else {
                    error_log("InterSoccer: Date parsing failed for camp_terms $camp_terms in order $order_id item $item_id");
                }
            }
        } elseif ($product_type === 'course' && $start_date && $end_date) {
            error_log('InterSoccer: Processing course dates for item ' . $item_id . ' in order ' . $order_id . ' - start_date: ' . var_export($start_date, true) . ', end_date: ' . var_export($end_date, true));
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

        // Handle activity_type with case-insensitive fallback
        $activity_type = $item_meta['Activity Type'] ?? $item_meta['pa_activity-type'] ?? null;
        error_log("InterSoccer: Raw Activity Type from meta for order $order_id, item $item_id: " . print_r($activity_type, true));
        if ($activity_type) {
            $activity_type = trim(strtolower(html_entity_decode($activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $activity_types = array_map('trim', explode(',', $activity_type));
            error_log("InterSoccer: Processed activity_types from meta for order $order_id, item $item_id: " . print_r($activity_types, true));
            if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
                $activity_type = 'Girls Only';
                error_log("InterSoccer: Assigned Girls Only from meta for order $order_id, item $item_id");
            } else {
                $activity_type = implode(', ', array_map('ucfirst', $activity_types));
                error_log("InterSoccer: Defaulted to joined activity_types from meta for order $order_id, item $item_id: $activity_type");
            }
        } else {
            $activity_type = $product_type ? ucfirst($product_type) : 'unknown';
            error_log("InterSoccer: Defaulted activity_type to $activity_type for order $order_id, item $item_id");
        }

        // Prepare data for insertion
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
            'activity_type' => substr(ucfirst($activity_type), 0, 50),
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
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        // Log data pre-insert for validation
        error_log('InterSoccer: Pre-insert data for order ' . $order_id . ', item ' . $item_id . ': ' . print_r($data, true));

        // Insert into table
        $result = $wpdb->insert($table_name, $data);
        $insert_id = $wpdb->insert_id;
        error_log('InterSoccer: Insert result for order ' . $order_id . ', item ' . $item_id . ': ' . var_export($result, true) . ' | Insert ID: ' . $insert_id . ' | Last DB error: ' . $wpdb->last_error . ' | Last query: ' . $wpdb->last_query);

        // Fetch warnings if result but no id
        if ($result && $insert_id == 0) {
            $warnings = $wpdb->get_results("SHOW WARNINGS");
            error_log('InterSoccer: MySQL warnings for insert on order ' . $order_id . ', item ' . $item_id . ': ' . print_r($warnings, true));
        }

        if ($result && $insert_id > 0) {
            $inserted = true;
            error_log('InterSoccer: Successfully inserted roster entry for order ' . $order_id . ', item ' . $item_id . ' (ID: ' . $insert_id . ')');
        } else {
            error_log('InterSoccer: Failed to insert roster entry for order ' . $order_id . ', item ' . $item_id . ' - Check DB error/warnings above');
        }
    }

    if ($inserted) {
        // Uncomment to re-enable auto-complete if checkout issues are resolved
        // $order->update_status('completed', 'Automatically completed after populating rosters.');
        // error_log('InterSoccer: Order ' . $order_id . ' transitioned to completed after roster population');
        error_log('InterSoccer: Auto-complete postponed for order ' . $order_id . '. Use Process Orders on Advanced page to complete.');
    } else {
        error_log('InterSoccer: No rosters inserted for order ' . $order_id . ', status not changed - Check skips and insert errors above');
    }

    // Verification query
    $verification_query = $wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id);
    $results = $wpdb->get_results($verification_query);
    error_log('InterSoccer: Verification query results for order ' . $order_id . ': ' . print_r($results, true));
}

/**
 * Insert or update roster entry from order item.
 *
 * @param int $order_id Order ID.
 * @param int $order_item_id Order item ID.
 * @param array $data Roster data.
 */
function intersoccer_insert_roster($order_id, $order_item_id, $data) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $order = wc_get_order($order_id);
    $item = $order->get_item($order_item_id);

    // Financial data
    $base_price = (float) $item->get_subtotal();
    $final_price = (float) $item->get_total();
    $discount_amount = $base_price - $final_price;
    $reimbursement = 0; // Calculate if needed (e.g., from meta)
    $discount_codes = implode(',', $order->get_coupon_codes());

    // Timestamp
    $registration_timestamp = $order->get_date_created()->date('Y-m-d H:i:s');

    // Add to data array
    $data['base_price'] = $base_price;
    $data['discount_amount'] = $discount_amount;
    $data['final_price'] = $final_price;
    $data['reimbursement'] = $reimbursement;
    $data['discount_codes'] = $discount_codes;
    $data['registration_timestamp'] = $registration_timestamp;

    // Insert or update
    $wpdb->replace($rosters_table, $data);
    error_log("InterSoccer: Inserted/updated roster for order_item_id={$order_item_id} with timestamp={$registration_timestamp}, base_price={$base_price}");
}

// Hook to order completion or wherever insertion happens
add_action('woocommerce_order_status_completed', function($order_id) {
    $order = wc_get_order($order_id);
    foreach ($order->get_items() as $item_id => $item) {
        // Gather $data from item meta, then call intersoccer_insert_roster($order_id, $item_id, $data);
    }
}, 10, 1);

function intersoccer_debug_populate_rosters($order_id) {
    ob_start();
    error_log('InterSoccer: Debug wrapper called for order ' . $order_id);
    try {
        intersoccer_populate_rosters_and_complete_order($order_id);
        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('InterSoccer: Debug wrapper output for order ' . $order_id . ': ' . substr($output, 0, 1000));
        }
        return true;
    } catch (Exception $e) {
        error_log('InterSoccer: Debug wrapper error for order ' . $order_id . ': ' . $e->getMessage());
        ob_end_clean();
        return false;
    }
}
?>