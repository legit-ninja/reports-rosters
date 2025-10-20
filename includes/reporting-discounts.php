<?php
/**
 * Fixed Discount Reporting System for InterSoccer Finance Team
 * Ensures accurate discount tracking and reporting after product variations improvements
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRITICAL: Store discount information when orders are placed
 * This hooks into WooCommerce order processing to capture discount data for reporting
 */
// add_action('woocommerce_checkout_order_processed', 'intersoccer_store_discount_data_for_reporting', 20, 3);
// function intersoccer_store_discount_data_for_reporting($order_id, $posted_data, $order) {
//     try {
//         error_log("InterSoccer Reporting: Storing discount data for order {$order_id}");
        
//         // Get cart fees (our discounts) before cart is cleared
//         $cart_fees = WC()->cart->get_fees();
//         $combo_discounts = [];
//         $total_combo_amount = 0;
        
//         foreach ($cart_fees as $fee) {
//             if ($fee->amount < 0) { // Negative = discount
//                 $combo_discounts[] = [
//                     'name' => $fee->name,
//                     'amount' => abs($fee->amount),
//                     'type' => intersoccer_determine_discount_type($fee->name)
//                 ];
//                 $total_combo_amount += abs($fee->amount);
//                 error_log("InterSoccer Reporting: Found combo discount: {$fee->name} = " . abs($fee->amount) . " CHF");
//             }
//         }
        
//         // Get coupon discounts
//         $coupon_discounts = [];
//         $total_coupon_amount = 0;
        
//         foreach ($order->get_coupon_codes() as $coupon_code) {
//             $coupon_amount = $order->get_discount_amount($coupon_code);
//             if ($coupon_amount > 0) {
//                 $coupon_discounts[] = [
//                     'name' => $coupon_code,
//                     'amount' => $coupon_amount,
//                     'type' => 'coupon'
//                 ];
//                 $total_coupon_amount += $coupon_amount;
//                 error_log("InterSoccer Reporting: Found coupon: {$coupon_code} = {$coupon_amount} CHF");
//             }
//         }
        
//         // Store comprehensive discount data
//         $total_discount_amount = $total_combo_amount + $total_coupon_amount;
//         $all_discounts = array_merge($combo_discounts, $coupon_discounts);
        
//         update_post_meta($order_id, '_intersoccer_total_discounts', $total_discount_amount);
//         update_post_meta($order_id, '_intersoccer_combo_discounts', $combo_discounts);
//         update_post_meta($order_id, '_intersoccer_coupon_discounts', $coupon_discounts);
//         update_post_meta($order_id, '_intersoccer_all_discounts', $all_discounts);
        
//         // Store item-level discount allocation for precise reporting
//         intersoccer_allocate_discounts_to_order_items($order_id, $order, $all_discounts);
        
//         error_log("InterSoccer Reporting: Stored discount data for order {$order_id}. Total: {$total_discount_amount} CHF");
        
//     } catch (Exception $e) {
//         error_log("InterSoccer Reporting: Error storing discount data for order {$order_id}: " . $e->getMessage());
//     }
// }

/**
 * Calculate item-level discount allocation for reporting
 */
function intersoccer_calculate_item_discount_reporting($cart_item, $discount_fees, $product_type) {
    $item_discounts = [];
    $assigned_player = $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
    
    if ($assigned_player === null) {
        return $item_discounts;
    }
    
    // Get all cart items to understand context
    $all_cart_items = WC()->cart->get_cart();
    
    foreach ($discount_fees as $discount) {
        $discount_type = $discount['type'];
        $discount_amount = 0;
        
        switch ($discount_type) {
            case 'camp_sibling':
                if ($product_type === 'camp') {
                    $discount_amount = intersoccer_calculate_camp_sibling_portion($cart_item, $all_cart_items, $discount);
                }
                break;
                
            case 'course_multi_child':
                if ($product_type === 'course') {
                    $discount_amount = intersoccer_calculate_course_multi_child_portion($cart_item, $all_cart_items, $discount);
                }
                break;
                
            case 'course_same_season':
                if ($product_type === 'course') {
                    $discount_amount = intersoccer_calculate_same_season_portion($cart_item, $all_cart_items, $discount);
                }
                break;
                
            default:
                // For other discount types, distribute proportionally
                $item_subtotal = floatval($cart_item['data']->get_price());
                $total_cart_subtotal = array_sum(array_map(function($item) {
                    return floatval($item['data']->get_price());
                }, $all_cart_items));
                
                if ($total_cart_subtotal > 0) {
                    $discount_amount = ($item_subtotal / $total_cart_subtotal) * $discount['amount'];
                }
                break;
        }
        
        if ($discount_amount > 0) {
            $item_discounts[] = [
                'name' => $discount['name'],
                'type' => $discount_type,
                'amount' => round($discount_amount, 2),
                'applied_to_player' => $assigned_player
            ];
        }
    }
    
    return $item_discounts;
}

/**
 * Calculate camp sibling discount portion for specific item
 */
function intersoccer_calculate_camp_sibling_portion($cart_item, $all_cart_items, $discount) {
    $assigned_player = $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
    
    // Get all camp items
    $camp_items = [];
    foreach ($all_cart_items as $item) {
        if (intersoccer_get_product_type($item['product_id']) === 'camp') {
            $player_id = $item['assigned_attendee'] ?? $item['assigned_player'] ?? null;
            if ($player_id !== null) {
                $camp_items[] = [
                    'player_id' => $player_id,
                    'price' => floatval($item['data']->get_price()),
                    'item' => $item
                ];
            }
        }
    }
    
    // Sort by price (descending)
    usort($camp_items, function($a, $b) {
        return $b['price'] <=> $a['price'];
    });
    
    // Find which discount tier this item falls into
    $player_found_at_index = null;
    foreach ($camp_items as $index => $camp_item) {
        if ($camp_item['item'] === $cart_item) {
            $player_found_at_index = $index;
            break;
        }
    }
    
    // Only items after the first (most expensive) get discounts
    if ($player_found_at_index !== null && $player_found_at_index > 0) {
        $item_price = floatval($cart_item['data']->get_price());
        
        // Determine discount rate based on position
        if ($player_found_at_index === 1) {
            // Second child - 20%
            return $item_price * 0.20;
        } else {
            // Third+ child - 25%
            return $item_price * 0.25;
        }
    }
    
    return 0;
}

/**
 * Calculate course multi-child discount portion for specific item
 */
function intersoccer_calculate_course_multi_child_portion($cart_item, $all_cart_items, $discount) {
    // Get all course items grouped by child
    $courses_by_child = [];
    foreach ($all_cart_items as $item) {
        if (intersoccer_get_product_type($item['product_id']) === 'course') {
            $player_id = $item['assigned_attendee'] ?? $item['assigned_player'] ?? null;
            if ($player_id !== null) {
                if (!isset($courses_by_child[$player_id])) {
                    $courses_by_child[$player_id] = [];
                }
                $courses_by_child[$player_id][] = [
                    'item' => $item,
                    'price' => floatval($item['data']->get_price())
                ];
            }
        }
    }
    
    // Calculate discount for this child's courses
    $assigned_player = $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
    if ($assigned_player !== null && isset($courses_by_child[$assigned_player])) {
        $child_courses = $courses_by_child[$assigned_player];
        
        // Sort child's courses by price (descending)
        usort($child_courses, function($a, $b) {
            return $b['price'] <=> $a['price'];
        });
        
        // Find this item's position in the sorted list
        $item_position = null;
        foreach ($child_courses as $index => $course) {
            if ($course['item'] === $cart_item) {
                $item_position = $index;
                break;
            }
        }
        
        // Apply discount based on position
        if ($item_position !== null) {
            $discount_rate = 0;
            if ($item_position === 0) {
                $discount_rate = 0.10; // First item - 10%
            } elseif ($item_position === 1) {
                $discount_rate = 0.15; // Second item - 15%
            } else {
                $discount_rate = 0.20; // Third+ item - 20%
            }
            
            return floatval($cart_item['data']->get_price()) * $discount_rate;
        }
    }
    
    return 0;
}

/**
 * Calculate same season discount portion for specific item
 */
function intersoccer_calculate_same_season_portion($cart_item, $all_cart_items, $discount) {
    $assigned_player = $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
    
    // Get all items assigned to the same player
    $player_items = [];
    foreach ($all_cart_items as $item) {
        $item_assigned_player = $item['assigned_attendee'] ?? $item['assigned_player'] ?? null;
        if ($item_assigned_player === $assigned_player) {
            $player_items[] = $item;
        }
    }
    
    // Calculate discount based on the number of items
    $item_count = count($player_items);
    if ($item_count > 1) {
        $discount_rate = 0;
        if ($item_count === 2) {
            $discount_rate = 0.05; // 2 items - 5%
        } elseif ($item_count === 3) {
            $discount_rate = 0.10; // 3 items - 10%
        } else {
            $discount_rate = 0.15; // 4+ items - 15%
        }
        
        return floatval($cart_item['data']->get_price()) * $discount_rate;
    }
    
    return 0;
}

/**
 * Allocate discounts to order items for precise reporting
 */
function intersoccer_reporting_allocate_discounts_to_order_items($order_id, $order, $all_discounts) {
    try {
        foreach ($order->get_items() as $item_id => $item) {
            $product_type = intersoccer_get_product_type($item->get_product_id());
            
            // Calculate item-level discounts
            $item_discounts = intersoccer_calculate_item_discount_reporting($item, $all_discounts, $product_type);
            
            // Store item discounts as serialized array
            update_post_meta($item_id, '_intersoccer_item_discounts', $item_discounts);
            
            // Calculate total discount for this item
            $total_item_discount = array_sum(array_column($item_discounts, 'amount'));
            update_post_meta($item_id, '_intersoccer_item_total_discount', $total_item_discount);
        }
    } catch (Exception $e) {
        error_log("InterSoccer Reporting: Error allocating discounts to order items for order {$order_id}: " . $e->getMessage());
    }
}

/**
 * Enhanced reporting function with direct database access for performance
 */
function intersoccer_get_enhanced_booking_report($start_date = '', $end_date = '', $year = '', $region = '') {
    global $wpdb;
    
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

    if (!$year) {
        $year = date('Y');
    }

    // Query for enhanced booking report - direct WooCommerce query
    $query = "SELECT 
        p.ID as order_id,
        p.post_date AS order_date,
        p.post_status AS order_status,
        oi.order_item_id,
        COALESCE(variation_meta.meta_value, product_meta.meta_value) as variation_id,
        oi.order_item_name as product_name,
        
        -- Extract metadata from order item meta
        assigned_attendee.meta_value as player_name,
        player_first_name.meta_value as player_first_name,
        player_last_name.meta_value as player_last_name,
        player_age.meta_value as age,
        player_gender.meta_value as gender,
        
        -- Venue and location
        venue_meta.meta_value as venue,
        canton_region.meta_value as canton_region,
        
        -- Price data
        COALESCE(CAST(subtotal.meta_value AS DECIMAL(10,2)), 0) AS base_price,
        COALESCE(CAST(total.meta_value AS DECIMAL(10,2)), 0) AS final_price,
        
        -- Discount data
        COALESCE(CAST(intersoccer_discount.meta_value AS DECIMAL(10,2)), 0) AS intersoccer_discount_amount,
        intersoccer_breakdown.meta_value AS intersoccer_discount_breakdown,
        
        -- Refund data
        COALESCE(ABS(CAST(refunded.refunded_total AS DECIMAL(10,2))), 0) AS reimbursement
        
    FROM $posts_table p
    LEFT JOIN $order_items_table oi ON p.ID = oi.order_id
    LEFT JOIN $order_itemmeta_table product_meta ON oi.order_item_id = product_meta.order_item_id
        AND product_meta.meta_key = '_product_id'
    
    -- Get variation ID if exists
    LEFT JOIN $order_itemmeta_table variation_meta ON oi.order_item_id = variation_meta.order_item_id
        AND variation_meta.meta_key = '_variation_id'
    
    -- Player information
    LEFT JOIN $order_itemmeta_table assigned_attendee ON oi.order_item_id = assigned_attendee.order_item_id
        AND assigned_attendee.meta_key = 'Assigned Attendee'
    LEFT JOIN $order_itemmeta_table player_first_name ON oi.order_item_id = player_first_name.order_item_id
        AND player_first_name.meta_key = 'Player First Name'
    LEFT JOIN $order_itemmeta_table player_last_name ON oi.order_item_id = player_last_name.order_item_id
        AND player_last_name.meta_key = 'Player Last Name'
    LEFT JOIN $order_itemmeta_table player_age ON oi.order_item_id = player_age.order_item_id 
        AND player_age.meta_key = 'Player Age'
    LEFT JOIN $order_itemmeta_table player_gender ON oi.order_item_id = player_gender.order_item_id
        AND player_gender.meta_key = 'Player Gender'
    
    -- Venue and location
    LEFT JOIN $order_itemmeta_table venue_meta ON oi.order_item_id = venue_meta.order_item_id 
        AND (venue_meta.meta_key = 'pa_intersoccer-venues' OR venue_meta.meta_key = 'InterSoccer Venues')
    LEFT JOIN $order_itemmeta_table canton_region ON oi.order_item_id = canton_region.order_item_id
        AND (canton_region.meta_key = 'pa_canton-region' OR canton_region.meta_key = 'Canton / Region')
    
    -- Price data
    LEFT JOIN $order_itemmeta_table subtotal ON oi.order_item_id = subtotal.order_item_id 
        AND subtotal.meta_key = '_line_subtotal'
    LEFT JOIN $order_itemmeta_table total ON oi.order_item_id = total.order_item_id 
        AND total.meta_key = '_line_total'
    
    -- Parent info from order meta
    LEFT JOIN $postmeta_table billing_email ON p.ID = billing_email.post_id 
        AND billing_email.meta_key = '_billing_email'
    LEFT JOIN $postmeta_table billing_phone ON p.ID = billing_phone.post_id 
        AND billing_phone.meta_key = '_billing_phone'
    
    -- Discount data
    LEFT JOIN $postmeta_table intersoccer_discount ON p.ID = intersoccer_discount.post_id 
        AND intersoccer_discount.meta_key = '_intersoccer_total_discounts'
    LEFT JOIN $postmeta_table intersoccer_breakdown ON p.ID = intersoccer_breakdown.post_id 
        AND intersoccer_breakdown.meta_key = '_intersoccer_all_discounts'
        
    -- Refund data
    LEFT JOIN (
        SELECT 
            parent.ID AS order_id, 
            SUM(CAST(refmeta.meta_value AS DECIMAL(10,2))) as refunded_total
        FROM $posts_table parent
        INNER JOIN $posts_table refund ON parent.ID = refund.post_parent 
            AND refund.post_type = 'shop_order_refund' 
            AND refund.post_status = 'wc-refunded'
        INNER JOIN $postmeta_table refmeta ON refund.ID = refmeta.post_id 
            AND refmeta.meta_key = '_order_total'
        GROUP BY parent.ID
    ) refunded ON p.ID = refunded.order_id
    
    WHERE p.post_type = 'shop_order' 
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
        -- Filter for orders with assigned attendees
        AND assigned_attendee.meta_value IS NOT NULL
        AND assigned_attendee.meta_value != ''";

    $params = array();

    // Add date filters - prioritize date range over year
    if ($start_date && $end_date) {
        $query .= " AND DATE(p.post_date) BETWEEN %s AND %s";
        $params[] = $start_date;
        $params[] = $end_date;
    } elseif ($start_date) {
        $query .= " AND DATE(p.post_date) >= %s";
        $params[] = $start_date;
    } elseif ($end_date) {
        $query .= " AND DATE(p.post_date) <= %s";
        $params[] = $end_date;
    } else {
        // Fallback to year filtering if no dates provided
        $query .= " AND YEAR(p.post_date) = %d";
        $params[] = $year;
    }

    $query .= " ORDER BY p.post_date DESC, p.ID, oi.order_item_id";

    error_log("InterSoccer WooCommerce Direct: Executing query with " . count($params) . " parameters");
    $results = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

    error_log("InterSoccer WooCommerce Direct: Query returned " . count($results) . " results");

    if ($wpdb->last_error) {
        error_log('InterSoccer WooCommerce Fallback: Query error: ' . $wpdb->last_error);
        return array('data' => array(), 'totals' => array('bookings' => 0, 'base_price' => 0, 'discount_amount' => 0, 'final_price' => 0, 'reimbursement' => 0));
    }

    // Add missing fields to match roster table structure
    foreach ($results as &$row) {
        // Set defaults for fields not available in direct WooCommerce query
        $row['player_gender'] = $row['gender']; // Map to same field
        $row['registration_timestamp'] = $row['order_date'];
        $row['start_date'] = null; // Not available in direct query
        $row['intersoccer_item_discounts'] = null;
        $row['intersoccer_item_total_discount'] = 0;
    }

    // Calculate totals
    $totals = array(
        'bookings' => count($results),
        'base_price' => 0,
        'discount_amount' => 0,
        'final_price' => 0,
        'reimbursement' => 0
    );

    foreach ($results as $row) {
        $totals['base_price'] += floatval($row['base_price']);
        $totals['final_price'] += floatval($row['final_price']);
        $totals['discount_amount'] += floatval($row['intersoccer_discount_amount']);
        $totals['reimbursement'] += floatval($row['reimbursement']);
    }

    error_log("InterSoccer WooCommerce Fallback: Found " . count($results) . " bookings");
    
    // Process results into report format
    $data = array();
    foreach ($results as $row) {
        // Determine best player name
        $attendee_name = '';
        if (!empty($row['player_name'])) {
            $attendee_name = $row['player_name'];
        } elseif (!empty($row['player_first_name']) || !empty($row['player_last_name'])) {
            $attendee_name = trim($row['player_first_name'] . ' ' . $row['player_last_name']);
        }

        // Calculate discount as difference between base and final price
        $total_discount = floatval($row['base_price']) - floatval($row['final_price']);
        
        // Build discount codes string
        $discount_codes = 'None'; // Simplified for now
        
        // Format dates
        $booked_date = '';
        if (!empty($row['order_date'])) {
            $booked_date = date_i18n('Y-m-d H:i', strtotime($row['order_date']));
        }

        $data[] = array(
            'ref' => 'ORD-' . $row['order_id'] . '-' . $row['order_item_id'],
            'order_id' => $row['order_id'],
            'booked' => $booked_date,
            'base_price' => number_format($row['base_price'], 2),
            'discount_amount' => number_format($total_discount, 2),
            'reimbursement' => number_format($row['reimbursement'], 2),
            'stripe_fee' => number_format(0, 2), // Not calculated
            'final_price' => number_format($row['final_price'], 2),
            'discount_codes' => $discount_codes,
            'class_name' => $row['product_name'] ?: 'N/A',
            'start_date' => 'N/A', // Not available in direct query
            'venue' => $row['venue'] ?: 'N/A',
            'booker_email' => $row['billing_email'] ?? 'N/A',
            'attendee_name' => $attendee_name ?: 'N/A',
            'attendee_age' => $row['age'] ?? 'N/A',
            'attendee_gender' => $row['gender'] ?? 'N/A',
            'parent_phone' => $row['billing_phone'] ?? 'N/A'
        );
    }
    
    return array('data' => $data, 'totals' => $totals);
}