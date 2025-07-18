<?php
/**
 * File: woocommerce-orders.php
 * Description: Handles WooCommerce order status changes to populate the intersoccer_rosters table and auto-complete orders for the InterSoccer Reports and Rosters plugin.
 * Dependencies: WooCommerce
 * Author: Jeremy Lee
 * Version: 1.4.18  // Incremented for fix
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Diagnostic log to confirm file inclusion
error_log('InterSoccer: woocommerce-orders.php file loaded');

// Define known Girls Only variation IDs
$girls_only_variation_ids = ['32648', '32649', '33957', '32645', '32641'];

// Define intersoccer_get_product_type if not already defined (to avoid redeclaration)
if (!function_exists('intersoccer_get_product_type')) {
    function intersoccer_get_product_type($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            error_log('InterSoccer: Invalid product for type detection: ' . $product_id);
            return '';
        }

        // Check existing meta
        $product_type = get_post_meta($product_id, '_intersoccer_product_type', true);
        if ($product_type) {
            error_log('InterSoccer: Product type from meta for product ' . $product_id . ': ' . $product_type);
            return $product_type;
        }

        // Check categories (on parent)
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'slugs'));
        if (is_wp_error($categories)) {
            error_log('InterSoccer: Error fetching categories for product ' . $product_id . ': ' . $categories->get_error_message());
            $categories = [];
        } else {
            $categories = array_map('strtolower', $categories); // Normalize case
            error_log('InterSoccer: Categories for product ' . $product_id . ': ' . print_r($categories, true));
        }

        if (in_array('camps', $categories, true) || in_array('camp', $categories, true)) {
            $product_type = 'camp';
        } elseif (in_array('courses', $categories, true) || in_array('course', $categories, true)) {
            $product_type = 'course';
        } elseif (in_array('birthdays', $categories, true) || in_array('birthday', $categories, true)) {
            $product_type = 'birthday';
        }

        // Fallback: Check attributes (on variation if available, else parent)
        if (!$product_type) {
            $attributes = $product->get_attributes();
            error_log('InterSoccer: Attributes for product ' . $product_id . ': ' . print_r($attributes, true));
            if (isset($attributes['pa_activity-type']) && $attributes['pa_activity-type'] instanceof WC_Product_Attribute) {
                $attribute = $attributes['pa_activity-type'];
                if ($attribute->is_taxonomy()) {
                    $terms = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'slugs']);
                    $terms = array_map('strtolower', $terms); // Normalize case
                    error_log('InterSoccer: pa_activity-type terms for product ' . $product_id . ': ' . print_r($terms, true));
                    if (in_array('course', $terms)) {
                        $product_type = 'course';
                    } elseif (in_array('camp', $terms)) {
                        $product_type = 'camp';
                    } elseif (in_array('birthday', $terms)) {
                        $product_type = 'birthday';
                    }
                } else {
                    error_log('InterSoccer: pa_activity-type attribute is not a taxonomy for product ' . $product_id);
                }
            } else {
                error_log('InterSoccer: pa_activity-type attribute not found for product ' . $product_id);
            }
        }

        // Fallback: Check product title as a last resort
        if (!$product_type) {
            $title = strtolower($product->get_title());
            if (strpos($title, 'course') !== false) {
                $product_type = 'course';
            } elseif (strpos($title, 'camp') !== false) {
                $product_type = 'camp';
            } elseif (strpos($title, 'birthday') !== false) {
                $product_type = 'birthday';
            }
            error_log('InterSoccer: Fallback to title for product type detection for product ' . $product_id . ', title: ' . $product->get_title() . ', type: ' . ($product_type ?: 'none'));
        }

        // Save the detected type to meta for future consistency
        if ($product_type) {
            update_post_meta($product_id, '_intersoccer_product_type', $product_type);
            error_log('InterSoccer: Determined and saved product type for product ' . $product_id . ': ' . $product_type);
        } else {
            error_log('InterSoccer: Could not determine product type for product ' . $product_id . ', categories: ' . print_r($categories, true));
        }

        return $product_type;
    }
}

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
    $order_date = $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null;
    $parent_phone = $order->get_billing_phone() ?: 'N/A';
    $parent_email = $order->get_billing_email() ?: 'N/A';
    $parent_first_name = $order->get_billing_first_name() ?: 'Unknown';
    $parent_last_name = $order->get_billing_last_name() ?: 'Unknown';

    foreach ($order->get_items() as $item_id => $item) {
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

        // Split name (assuming first last)
        $name_parts = explode(' ', $assigned_attendee, 2);
        $first_name = trim($name_parts[0] ?? 'Unknown');
        $last_name = trim($name_parts[1] ?? 'Unknown');

        // Lookup full player details from user meta
        $user_id = $order->get_user_id();
        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
        error_log('InterSoccer: User ' . $user_id . ' players meta: ' . print_r($players, true)); // Log full players array
        $player_index = $item_meta['assigned_player'] ?? false;
        $age = isset($item_meta['Player Age']) ? (int)$item_meta['Player Age'] : null;
        $gender = $item_meta['Player Gender'] ?? 'N/A';
        $medical_conditions = $item_meta['Medical Conditions'] ?? '';
        $dob = null;
        if ($player_index !== false && is_array($players) && isset($players[$player_index])) {
            $player = $players[$player_index];
            $first_name = trim($player['first_name'] ?? $first_name);
            $last_name = trim($player['last_name'] ?? $last_name);
            $dob = $player['dob'] ?? null;
            $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
            $gender = $player['gender'] ?? $gender;
            $medical_conditions = trim($player['medical_conditions'] ?? $medical_conditions);
        } else {
            $player_full_name = trim("$first_name $last_name");
            foreach ($players as $player) {
                if (trim($player['first_name'] . ' ' . $player['last_name']) === $player_full_name) {
                    $dob = $player['dob'] ?? null;
                    $age = $dob ? (new DateTime($dob))->diff(new DateTime())->y : $age;
                    $gender = $player['gender'] ?? $gender;
                    $medical_conditions = trim($player['medical_conditions'] ?? $medical_conditions);
                    break;
                }
            }
        }

        error_log('InterSoccer: Player lookup for ' . $assigned_attendee . ' (user_id: ' . $user_id . ') in order ' . $order_id . ': ' . (isset($player) ? 'Found - ' . print_r($player, true) : 'Not found, using defaults'));

        // Load product and variation for attribute fallbacks
        $product = $item->get_product();
        $variation = wc_get_product($variation_id);
        $parent_product = wc_get_product($product_id);

        // Handle Activity Type with case-insensitive fallback, aligned with rebuild
        $activity_type = $raw_order_item_meta['Activity Type'][0] ?? null;
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
            $variation_activity_type = $variation ? $variation->get_attribute('pa_activity-type') : ($parent_product ? $parent_product->get_attribute('pa_activity-type') : null);
            error_log("InterSoccer: Raw pa_activity-type from variation/parent for order $order_id, item $item_id: " . print_r($variation_activity_type, true));
            if ($variation_activity_type) {
                if (is_array($variation_activity_type)) {
                    $variation_activity_type = implode(', ', array_map('trim', $variation_activity_type));
                }
                $activity_type = trim(strtolower(html_entity_decode($variation_activity_type, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $activity_types = array_map('trim', explode(',', $activity_type));
                error_log("InterSoccer: Processed activity_types from variation for order $order_id, item $item_id: " . print_r($activity_types, true));
                if (in_array('girls only', $activity_types) || in_array('camp, girls\' only', $activity_types) || in_array('camp, girls only', $activity_types)) {
                    $activity_type = 'Girls Only';
                    error_log("InterSoccer: Assigned Girls Only from variation for order $order_id, item $item_id");
                } elseif (!empty($activity_types[0])) {
                    $activity_type = ucfirst($activity_types[0]);
                    error_log("InterSoccer: Defaulted to first activity_type from variation for order $order_id, item $item_id: $activity_type");
                } else {
                    if ($variation->get_attribute('pa_course-day') || $parent_product->get_attribute('pa_course-day')) {
                        $activity_type = 'Course';
                        error_log("InterSoccer: Assigned Course based on pa_course-day for order $order_id, item $item_id");
                    } elseif ($variation->get_attribute('pa_camp-terms') || $parent_product->get_attribute('pa_camp-terms')) {
                        $activity_type = 'Camp';
                        error_log("InterSoccer: Assigned Camp based on pa_camp-terms for order $order_id, item $item_id");
                    } elseif (in_array($variation_id, $girls_only_variation_ids)) {
                        $activity_type = 'Girls Only';
                        error_log("InterSoccer: Assigned Girls Only based on variation_id $variation_id for order $order_id, item $item_id");
                    } else {
                        $activity_type = 'unknown';
                        error_log("InterSoccer: No activity type indicators found, defaulting to unknown for order $order_id, item $item_id");
                    }
                }
            } else {
                if (isset($raw_order_item_meta['pa_course-day'])) {
                    $activity_type = 'Course';
                    error_log("InterSoccer: Assigned Course based on pa_course-day in meta for order $order_id, item $item_id");
                } elseif (isset($raw_order_item_meta['pa_camp-terms'])) {
                    $activity_type = 'Camp';
                    error_log("InterSoccer: Assigned Camp based on pa_camp-terms in meta for order $order_id, item $item_id");
                } elseif (in_array($variation_id, $girls_only_variation_ids)) {
                    $activity_type = 'Girls Only';
                    error_log("InterSoccer: Assigned Girls Only based on variation_id $variation_id for order $order_id, item $item_id");
                } else {
                    $activity_type = 'unknown';
                    error_log("InterSoccer: No activity type indicators found, defaulting to unknown for order $order_id, item $item_id");
                }
            }
        }

        // Event details from item meta, with fallbacks to variation/parent attributes
        $venue = substr(trim($item_meta['InterSoccer Venues'] ?? $item_meta['pa_intersoccer-venues'] ?? ($variation ? $variation->get_attribute('pa_intersoccer-venues') : ($parent_product ? $parent_product->get_attribute('pa_intersoccer-venues') : 'Unknown Venue'))), 0, 200);
        $age_group = substr(trim($item_meta['Age Group'] ?? $item_meta['pa_age-group'] ?? ($variation ? $variation->get_attribute('pa_age-group') : ($parent_product ? $parent_product->get_attribute('pa_age-group') : 'N/A'))), 0, 50);
        $camp_terms = ($activity_type === 'Camp' ? substr(trim($item_meta['Camp Terms'] ?? $item_meta['pa_camp-terms'] ?? ($variation ? $variation->get_attribute('pa_camp-terms') : ($parent_product ? $parent_product->get_attribute('pa_camp-terms') : 'N/A'))), 0, 100) : null);
        $course_day = ($activity_type === 'Course' ? substr(trim($item_meta['Course Day'] ?? $item_meta['pa_course-day'] ?? ($variation ? $variation->get_attribute('pa_course-day') : ($parent_product ? $parent_product->get_attribute('pa_course-day') : 'N/A'))), 0, 20) : null);
        $times = substr(trim($item_meta['Camp Times'] ?? $item_meta['pa_camp-times'] ?? $item_meta['Course Times'] ?? $item_meta['pa_course-times'] ?? ($variation ? $variation->get_attribute('pa_camp-times') ?: $variation->get_attribute('pa_course-times') : ($parent_product ? $parent_product->get_attribute('pa_camp-times') ?: $parent_product->get_attribute('pa_course-times') : 'N/A'))), 0, 50);
        $booking_type = substr(trim($item_meta['Booking Type'] ?? $item_meta['pa_booking-type'] ?? ($variation ? $variation->get_attribute('pa_booking-type') : ($parent_product ? $parent_product->get_attribute('pa_booking-type') : 'Unknown'))), 0, 50);
        $selected_days = trim($item_meta['Days Selected'] ?? 'N/A');
        $season = substr(trim($item_meta['Season'] ?? $item_meta['pa_program-season'] ?? ($variation ? $variation->get_attribute('pa_program-season') : ($parent_product ? $parent_product->get_attribute('pa_program-season') : ''))), 0, 50);
        $canton = substr(trim($item_meta['Canton / Region'] ?? ''), 0, 100);
        $city = substr(trim($item_meta['City'] ?? ''), 0, 100);
        $start_date_str = $item_meta['Start Date'] ?? null;
        $end_date_str = $item_meta['End Date'] ?? null;

        // Log sources for debugging
        error_log('InterSoccer: Venue source for item ' . $item_id . ': Item meta - ' . ($item_meta['InterSoccer Venues'] ?? 'N/A') . ', Variation attr - ' . ($variation ? $variation->get_attribute('pa_intersoccer-venues') : 'N/A') . ', Parent attr - ' . ($parent_product ? $parent_product->get_attribute('pa_intersoccer-venues') : 'N/A') . ', Final: ' . $venue);

        // Prepare dates, use valid default if null or invalid, align with rebuild parsing
        $start_date = null;
        $end_date = null;
        $event_dates = 'N/A';
        $season_year = $season ? preg_replace('/[^0-9]/', '', $season) : (date('Y', strtotime($order_date)) ?: date('Y'));
        if ($activity_type === 'Camp' && $camp_terms !== 'N/A') {
            // Parse camp_terms like rebuild
            if (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\w+)-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                $start_month = $matches[2];
                $start_day = $matches[3];
                $end_month = $matches[4];
                $end_day = $matches[5];
                $year = $season_year;
                $start_date_obj = DateTime::createFromFormat('F j Y', "$start_month $start_day $year");
                $end_date_obj = DateTime::createFromFormat('F j Y', "$end_month $end_day $year");
                if ($start_date_obj && $end_date_obj) {
                    $start_date = $start_date_obj->format('Y-m-d');
                    $end_date = $end_date_obj->format('Y-m-d');
                    $event_dates = "$start_date to $end_date";
                } else {
                    error_log("InterSoccer: Date parsing failed for camp_terms $camp_terms (start_month: $start_month, start_day: $start_day, end_month: $end_month, end_day: $end_day, year: $year) for order $order_id, item $item_id");
                }
            } elseif (preg_match('/(\w+)-week-\d+-(\w+)-(\d{1,2})-(\d{1,2})-\d+-days/', $camp_terms, $matches)) {
                $month = $matches[2];
                $start_day = $matches[3];
                $end_day = $matches[4];
                $year = $season_year;
                $start_date_obj = DateTime::createFromFormat('F j Y', "$month $start_day $year");
                $end_date_obj = DateTime::createFromFormat('F j Y', "$month $end_day $year");
                if ($start_date_obj && $end_date_obj) {
                    $start_date = $start_date_obj->format('Y-m-d');
                    $end_date = $end_date_obj->format('Y-m-d');
                    $event_dates = "$start_date to $end_date";
                } else {
                    error_log("InterSoccer: Date parsing failed for camp_terms $camp_terms (month: $month, start_day: $start_day, end_day: $end_day, year: $year) for order $order_id, item $item_id");
                }
            } else {
                error_log("InterSoccer: Regex failed to match camp_terms $camp_terms for order $order_id, item $item_id");
            }
        } elseif ($activity_type === 'Course' && $start_date_str && $end_date_str) {
            // Parse like rebuild for courses
            $start_date_obj = DateTime::createFromFormat('m/d/Y', $start_date_str);
            $end_date_obj = DateTime::createFromFormat('m/d/Y', $end_date_str);
            if ($start_date_obj && $end_date_obj) {
                $start_date = $start_date_obj->format('Y-m-d');
                $end_date = $end_date_obj->format('Y-m-d');
                $event_dates = "$start_date to $end_date";
            } else {
                error_log("InterSoccer: Course date parsing failed for Start Date: $start_date_str, End Date: $end_date_str for order $order_id, item $item_id");
            }
        }

        $start_date = $start_date ?: '1970-01-01';
        $end_date = $end_date ?: '1970-01-01';

        $late_pickup = $item_meta['Late Pickup'] ?? 'No';

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
            // Aligned logic for shirt/short sizes
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
            if ($shirt_size === 'N/A' || $shorts_size === 'N/A') {
                foreach ($possible_shirt_keys as $key) {
                    if (isset($raw_order_item_meta[$key][0]) && $raw_order_item_meta[$key][0] !== '') {
                        $shirt_size = substr(trim($raw_order_item_meta[$key][0]), 0, 50);
                        break;
                    }
                }
                foreach ($possible_shorts_keys as $key) {
                    if (isset($raw_order_item_meta[$key][0]) && $raw_order_item_meta[$key][0] !== '') {
                        $shorts_size = substr(trim($raw_order_item_meta[$key][0]), 0, 50);
                        break;
                    }
                }
                error_log("InterSoccer: Fallback for order $order_id, item $item_id - shirt_size: $shirt_size, shorts_size: $shorts_size");
            }
        }

        // Prepare data for insertion, now aligned with full rebuild fields
        $data = [
            'order_id' => $order_id,
            'order_item_id' => $item_id,
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
            'parent_phone' => substr($parent_phone, 0, 20),
            'parent_email' => substr($parent_email, 0, 100),
            'medical_conditions' => $medical_conditions,
            'late_pickup' => $late_pickup,
            'day_presence' => json_encode($day_presence),
            'age_group' => $age_group,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'event_dates' => substr($event_dates, 0, 100),
            'product_name' => substr($product_name, 0, 255),
            'activity_type' => substr(ucfirst($activity_type), 0, 50),
            'shirt_size' => $shirt_size,
            'shorts_size' => $shorts_size,
            'registration_timestamp' => $order_date,
            'course_day' => $course_day,
            'product_id' => $product_id,
            'player_first_name' => substr($first_name, 0, 100),
            'player_last_name' => substr($last_name, 0, 100),
            'player_dob' => $dob ?? '1970-01-01',
            'player_gender' => substr($gender, 0, 10),
            'player_medical' => $medical_conditions,
            'player_dietary' => '',
            'parent_first_name' => substr($parent_first_name, 0, 100),
            'parent_last_name' => substr($parent_last_name, 0, 100),
            'emergency_contact' => substr($parent_phone, 0, 20),  // Fallback
            'term' => $term,
            'times' => $times,
            'days_selected' => $days_selected,
            'season' => $season,
            'canton_region' => $canton,
            'city' => $city,
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
        // Temporarily postpone auto-complete to prevent errors during checkout
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
?>