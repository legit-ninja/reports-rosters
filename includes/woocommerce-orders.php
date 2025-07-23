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

        // Load product and variation for attribute fallbacks
        $product = $item->get_product();
        $variation = wc_get_product($variation_id);
        $parent_product = wc_get_product($product_id);

        // ... (rest of the function remains the same as in your provided code, including activity_type logic, event details, dates, day_presence, shirt/short, etc.)

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
            'emergency_contact' => substr($parent_phone, 0, 20),
            'term' => $term,
            'times' => $times,
            'days_selected' => $days_selected,
            'season' => $season,
            'canton_region' => $canton,
            'city' => $city,
            'avs_number' => substr($avs_number, 0, 50),
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