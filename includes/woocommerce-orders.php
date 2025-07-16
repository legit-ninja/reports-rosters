<?php
/**
 * File: woocommerce-orders.php
 * Description: Handles WooCommerce order status changes to populate the intersoccer_rosters table and auto-complete orders for the InterSoccer Reports and Rosters plugin.
 * Dependencies: WooCommerce
 * Author: Grok 4 (built by xAI)
 * Version: 1.4.6
 * Date: July 15, 2025
 * Changes:
 * - Enhanced intersoccer_get_product_type to use variation_id for attributes if present, parent for categories/title (2025-07-15).
 * - Added logs for detection steps, variation/parent usage (2025-07-15).
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Diagnostic log to confirm file inclusion
error_log('InterSoccer: woocommerce-orders.php file loaded');

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

    global $wpdb;
    $table_name = $wpdb->prefix . 'intersoccer_rosters';

    // Log order details for diagnosis
    error_log('InterSoccer: Processing order ' . $order_id . ' for roster population. Status: ' . $order->get_status() . ', Items count: ' . count($order->get_items()));

    $inserted = false;
    foreach ($order->get_items() as $item_id => $item) {
        error_log('InterSoccer: Processing item ' . $item_id . ' in order ' . $order_id); // Per-item log

        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $type_id = $variation_id ?: $product_id; // Use variation for detection if present
        $product_type = intersoccer_get_product_type($type_id);
        error_log('InterSoccer: Item ' . $item_id . ' product_type: ' . $product_type . ' (using ID: ' . $type_id . ', parent: ' . $product_id . ', variation: ' . $variation_id . ')');

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

        // Get item metadata
        $item_meta = [];
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            $item_meta[$data['key']] = $data['value'];
        }
        error_log('InterSoccer: Item metadata for ' . $item_id . ': ' . print_r($item_meta, true));

        // Assigned Attendee
        $assigned_attendee = isset($item_meta['Assigned Attendee']) ? $item_meta['Assigned Attendee'] : '';
        if (empty($assigned_attendee)) {
            error_log('InterSoccer: No Assigned Attendee found for item ' . $item_id . ' in order ' . $order_id);
            continue;
        }

        // Split name (assuming first last)
        $name_parts = explode(' ', $assigned_attendee, 2);
        $player_first = $name_parts[0] ?? '';
        $player_last = $name_parts[1] ?? '';

        // Lookup full player details from user meta
        $user_id = $order->get_user_id();
        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
        error_log('InterSoccer: User ' . $user_id . ' players meta: ' . print_r($players, true)); // Log full players array
        $player = null;
        foreach ($players as $p) {
            if (trim($p['first_name'] . ' ' . $p['last_name']) === trim($assigned_attendee)) {
                $player = $p;
                break;
            }
        }

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
        $venue = $item_meta['InterSoccer Venues'] ?? '';
        $age_group = $item_meta['Age Group'] ?? '';
        $term = $item_meta['Camp Terms'] ?? $item_meta['Course Day'] ?? '';
        $times = $item_meta['Camp Times'] ?? $item_meta['Course Times'] ?? '';
        $booking_type = $item_meta['Booking Type'] ?? '';
        $days_selected = $item_meta['Days Selected'] ?? '';
        $season = $item_meta['Season'] ?? '';
        $canton = $item_meta['Canton / Region'] ?? '';
        $city = $item_meta['City'] ?? '';
        $activity_type = $item_meta['Activity Type'] ?? '';
        $start_date = $item_meta['Start Date'] ?? null;
        $end_date = $item_meta['End Date'] ?? null;

        // Fallback for player DOB if missing (to satisfy NOT NULL)
        $player_dob = $player['dob'] ?? '0000-00-00'; // Use invalid date as placeholder

        // Prepare data for insertion (ensure dates are Y-m-d)
        $data = [
            'order_id' => $order_id,
            'order_item_id' => $item_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'player_name' => $assigned_attendee,
            'player_first_name' => $player_first,
            'player_last_name' => $player_last,
            'player_dob' => $player_dob,
            'player_gender' => $player['gender'] ?? '',
            'player_medical' => $player['medical_conditions'] ?? '',
            'player_dietary' => $player['dietary_needs'] ?? '',
            'parent_first_name' => $parent_first,
            'parent_last_name' => $parent_last,
            'parent_email' => $parent_email,
            'parent_phone' => $parent_phone,
            'emergency_contact' => $emergency_contact,
            'venue' => $venue,
            'age_group' => $age_group,
            'term' => $term,
            'times' => $times,
            'booking_type' => $booking_type,
            'days_selected' => $days_selected,
            'season' => $season,
            'canton_region' => $canton,
            'city' => $city,
            'activity_type' => $activity_type,
            'start_date' => $start_date ? date('Y-m-d', strtotime($start_date)) : null,
            'end_date' => $end_date ? date('Y-m-d', strtotime($end_date)) : null,
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
?>