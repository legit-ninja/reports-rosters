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
 * Determine discount type from discount name (for legacy format)
 * 
 * @param string $discount_name The discount name/description
 * @return string The discount type (sibling, same_season, coupon, other)
 */
function intersoccer_determine_discount_type_from_name($discount_name) {
    if (empty($discount_name)) {
        return 'other';
    }
    
    $name_lower = strtolower($discount_name);
    
    // Check for sibling discounts
    if (strpos($name_lower, 'sibling') !== false || 
        strpos($name_lower, 'multi-child') !== false ||
        strpos($name_lower, '2nd child') !== false ||
        strpos($name_lower, '3rd child') !== false ||
        strpos($name_lower, '2nd+ child') !== false) {
        return 'sibling';
    }
    
    // Check for same season discounts
    if (strpos($name_lower, 'same season') !== false || 
        strpos($name_lower, 'même saison') !== false ||
        strpos($name_lower, 'second course') !== false ||
        strpos($name_lower, '50%') !== false && strpos($name_lower, 'season') !== false) {
        return 'same_season';
    }
    
    // Check for coupon/promotional discounts
    if (strpos($name_lower, 'coupon') !== false || 
        strpos($name_lower, 'promo') !== false ||
        strpos($name_lower, 'promotional') !== false) {
        return 'coupon';
    }
    
    // Default to other
    return 'other';
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
    
    error_log("InterSoccer Enhanced Report: Called with start_date=$start_date, end_date=$end_date, year=$year, region=$region");
    
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
        
        -- Booking details
        booking_type_meta.meta_value as booking_type,
        COALESCE(days_selected_meta.meta_value, days_of_week_meta.meta_value) as selected_days,
        age_group_meta.meta_value as age_group,
        activity_type_meta.meta_value as activity_type,
        
        -- Venue and location
        venue_meta.meta_value as venue,
        canton_region.meta_value as canton_region,
        
        -- Price data
        COALESCE(CAST(subtotal.meta_value AS DECIMAL(10,2)), 0) AS base_price,
        COALESCE(CAST(total.meta_value AS DECIMAL(10,2)), 0) AS final_price,
        
        -- Discount data (ITEM-LEVEL - most accurate)
        COALESCE(CAST(item_discount_total.meta_value AS DECIMAL(10,2)), 0) AS intersoccer_item_discount_amount,
        item_discount_breakdown.meta_value AS intersoccer_item_discount_breakdown,
        
        -- Order-level discount data (fallback)
        COALESCE(CAST(intersoccer_discount.meta_value AS DECIMAL(10,2)), 0) AS intersoccer_order_discount_amount,
        intersoccer_breakdown.meta_value AS intersoccer_order_discount_breakdown,
        
        -- Refund data
        COALESCE(ABS(CAST(refunded.refunded_total AS DECIMAL(10,2))), 0) AS reimbursement
        
    FROM $posts_table p
    LEFT JOIN $order_items_table oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
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
    
    -- Booking details
    LEFT JOIN $order_itemmeta_table booking_type_meta ON oi.order_item_id = booking_type_meta.order_item_id
        AND booking_type_meta.meta_key = 'pa_booking-type'
    LEFT JOIN $order_itemmeta_table days_selected_meta ON oi.order_item_id = days_selected_meta.order_item_id
        AND days_selected_meta.meta_key = 'Days Selected'
    LEFT JOIN $order_itemmeta_table days_of_week_meta ON oi.order_item_id = days_of_week_meta.order_item_id
        AND days_of_week_meta.meta_key = 'Days of Week'
    LEFT JOIN $order_itemmeta_table age_group_meta ON oi.order_item_id = age_group_meta.order_item_id
        AND age_group_meta.meta_key = 'pa_age-group'
    LEFT JOIN $order_itemmeta_table activity_type_meta ON oi.order_item_id = activity_type_meta.order_item_id
        AND (activity_type_meta.meta_key = 'Activity Type' OR activity_type_meta.meta_key = 'pa_activity-type')
    
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
    
    -- Discount data (ITEM-LEVEL - most accurate)
    LEFT JOIN $order_itemmeta_table item_discount_total ON oi.order_item_id = item_discount_total.order_item_id
        AND item_discount_total.meta_key = '_intersoccer_total_item_discount'
    LEFT JOIN $order_itemmeta_table item_discount_breakdown ON oi.order_item_id = item_discount_breakdown.order_item_id
        AND item_discount_breakdown.meta_key = '_intersoccer_item_discounts'
    
    -- Order-level discount data (fallback)
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
        -- Include all orders (assigned attendee is now optional)
        -- AND assigned_attendee.meta_value IS NOT NULL
        -- AND assigned_attendee.meta_value != ''";

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
        
        // Use item-level discount if available, otherwise order-level, otherwise calculate
        $discount_amt = 0;
        if (!empty($row['intersoccer_item_discount_amount']) && floatval($row['intersoccer_item_discount_amount']) > 0) {
            $discount_amt = floatval($row['intersoccer_item_discount_amount']);
        } elseif (!empty($row['intersoccer_order_discount_amount']) && floatval($row['intersoccer_order_discount_amount']) > 0) {
            // Order-level discount - would need proportional allocation, use price difference for now
            $discount_amt = floatval($row['base_price']) - floatval($row['final_price']);
        } else {
            $discount_amt = floatval($row['base_price']) - floatval($row['final_price']);
        }
        
        $totals['discount_amount'] += $discount_amt;
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

        // PRIORITY 1: Use item-level discount metadata if available (most accurate)
        $total_discount = 0;
        $item_discount_breakdown = [];
        
        if (!empty($row['intersoccer_item_discount_amount']) && floatval($row['intersoccer_item_discount_amount']) > 0) {
            $total_discount = floatval($row['intersoccer_item_discount_amount']);
            
            // Get detailed breakdown if available
            if (!empty($row['intersoccer_item_discount_breakdown'])) {
                $item_discount_breakdown = maybe_unserialize($row['intersoccer_item_discount_breakdown']);
                if (!is_array($item_discount_breakdown)) {
                    $item_discount_breakdown = [];
                }
            }
        }
        // PRIORITY 2: Fallback to order-level discount (proportional allocation)
        elseif (!empty($row['intersoccer_order_discount_amount']) && floatval($row['intersoccer_order_discount_amount']) > 0) {
            // This is order-level, so we'd need to allocate proportionally
            // For now, use price difference as fallback
            $total_discount = floatval($row['base_price']) - floatval($row['final_price']);
        }
        // PRIORITY 3: Calculate from price difference (least accurate)
        else {
            $total_discount = floatval($row['base_price']) - floatval($row['final_price']);
            
            // Log when using fallback for debugging
            if ($total_discount > 0.01) {
                error_log("InterSoccer Enhanced Report: Order {$row['order_id']}, Item {$row['order_item_id']} - Using fallback discount calculation. Calculated: {$total_discount} CHF");
            }
        }
        
        // Build discount codes string
        $discount_codes = 'None'; // Simplified for now
        if (!empty($item_discount_breakdown)) {
            $discount_names = array_column($item_discount_breakdown, 'name');
            $discount_codes = implode(', ', array_filter($discount_names));
        }
        
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

/**
 * Get simplified booking report data for financial reporting
 * Focuses on revenue per participant by querying WooCommerce orders directly
 */
function intersoccer_get_financial_booking_report($start_date = '', $end_date = '', $year = '', $region = '') {
    error_log("=== InterSoccer Financial Report: Called with start_date=$start_date, end_date=$end_date, year=$year, region=$region ===");

    if (function_exists('intersoccer_oop_get_financial_report_service')) {
        try {
            return intersoccer_oop_get_financial_report_service()->getFinancialBookingReport($start_date, $end_date, $year, $region);
        } catch (\Exception $e) {
            error_log('InterSoccer Financial Report (OOP): Fallback to legacy due to exception - ' . $e->getMessage());
        }
    }

    global $wpdb;

    // Build date filter
    $date_where = '';
    if (!empty($start_date) && !empty($end_date)) {
        $date_where = $wpdb->prepare("AND p.post_date >= %s AND p.post_date <= %s", $start_date, $end_date);
    } elseif (!empty($year)) {
        $date_where = $wpdb->prepare("AND YEAR(p.post_date) = %d", $year);
    }

    // Build region filter (if needed)
    $region_where = '';
    if (!empty($region) && $region !== 'all') {
        // This would need to be implemented based on how regions are stored
        // For now, we'll skip region filtering
    }

    // Query WooCommerce orders with order items
    $query = "
        SELECT
            p.ID as order_id,
            p.post_date as order_date,
            p.post_status as order_status,
            oi.order_item_id,
            oi.order_item_name,
            oim_product.meta_value as product_id,
            oim_variation.meta_value as variation_id,
            oim_qty.meta_value as quantity,
            oim_total.meta_value as line_total,
            oim_subtotal.meta_value as line_subtotal
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_product ON oi.order_item_id = oim_product.order_item_id AND oim_product.meta_key = '_product_id'
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_variation ON oi.order_item_id = oim_variation.order_item_id AND oim_variation.meta_key = '_variation_id'
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_subtotal ON oi.order_item_id = oim_subtotal.order_item_id AND oim_subtotal.meta_key = '_line_subtotal'
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed', 'wc-processing')
        {$date_where}
        ORDER BY p.post_date DESC, p.ID DESC
    ";

    $results = $wpdb->get_results($query);
    error_log("=== InterSoccer Financial Report: Query returned " . count($results) . " results ===");

    $data = [];
    $totals = [
        'bookings' => 0, // Will be set to count($data) at the end
        'base_price' => 0,
        'discount_amount' => 0,
        'final_price' => 0,
        'reimbursement' => 0
    ];

    foreach ($results as $row) {
        // Skip if no product_id (this would be a coupon or other non-product item)
        if (empty($row->product_id)) {
            continue;
        }

        // Skip if this is a BuyClub order (check order meta)
        $buyclub_check = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_billing_company'",
            $row->order_id
        ));
        if (stripos($buyclub_check, 'buyclub') !== false) {
            continue;
        }

        // Get additional metadata for this order item
        $item_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta
             WHERE order_item_id = %d",
            $row->order_item_id
        ));

        $meta_data = [];
        foreach ($item_meta as $meta) {
            $meta_data[$meta->meta_key] = $meta->meta_value;
        }

        // Extract participant information
        $attendee_name = '';
        $attendee_age = '';
        $attendee_gender = '';
        $parent_phone = '';
        $selected_days = '';
        $age_group = '';
        $activity_type = '';
        $venue = '';
        $discount_codes = '';

        // Check for various metadata keys that might contain participant info
        if (isset($meta_data['Attendee Name'])) $attendee_name = $meta_data['Attendee Name'];
        if (isset($meta_data['Child Name'])) $attendee_name = $meta_data['Child Name'];
        if (isset($meta_data['Player Name'])) $attendee_name = $meta_data['Player Name'];

        if (isset($meta_data['Attendee Age'])) $attendee_age = $meta_data['Attendee Age'];
        if (isset($meta_data['Child Age'])) $attendee_age = $meta_data['Child Age'];

        if (isset($meta_data['Attendee Gender'])) $attendee_gender = $meta_data['Attendee Gender'];
        if (isset($meta_data['Child Gender'])) $attendee_gender = $meta_data['Child Gender'];

        if (isset($meta_data['Emergency Phone'])) $parent_phone = $meta_data['Emergency Phone'];
        if (isset($meta_data['Parent Phone'])) $parent_phone = $meta_data['Parent Phone'];

        if (isset($meta_data['Days Selected'])) $selected_days = $meta_data['Days Selected'];
        if (isset($meta_data['Days of Week'])) $selected_days = $meta_data['Days of Week'];

        if (isset($meta_data['pa_age-group'])) $age_group = $meta_data['pa_age-group'];
        if (isset($meta_data['Age Group'])) $age_group = $meta_data['Age Group'];

        if (isset($meta_data['pa_booking-type'])) $activity_type = $meta_data['pa_booking-type'];
        if (isset($meta_data['Activity Type'])) $activity_type = $meta_data['Activity Type'];

        if (isset($meta_data['pa_intersoccer-venues'])) $venue = $meta_data['pa_intersoccer-venues'];
        if (isset($meta_data['InterSoccer Venues'])) $venue = $meta_data['InterSoccer Venues'];

        // Calculate pricing
        $base_price = floatval($row->line_subtotal);
        $final_price = floatval($row->line_total);
        
        // PRIORITY 1: Use stored discount metadata if available (most accurate)
        // The Product Variations plugin stores discounts in multiple formats:
        // - New format: _intersoccer_item_discounts (array) and _intersoccer_total_item_discount (amount)
        // - Legacy format: "Discount" (name) and "Discount Amount" (HTML formatted amount)
        $discount_amount = 0;
        $item_discount_breakdown = [];
        
        // Check for new format first (_intersoccer_item_discounts)
        if (isset($meta_data['_intersoccer_total_item_discount']) && !empty($meta_data['_intersoccer_total_item_discount'])) {
            // Use the stored total discount amount (most accurate)
            $discount_amount = floatval($meta_data['_intersoccer_total_item_discount']);
            
            // Get the detailed breakdown if available
            if (isset($meta_data['_intersoccer_item_discounts']) && !empty($meta_data['_intersoccer_item_discounts'])) {
                // WooCommerce automatically serializes arrays, so we need to unserialize
                $raw_breakdown = $meta_data['_intersoccer_item_discounts'];
                
                // Try to unserialize (WooCommerce stores arrays as serialized strings)
                if (is_string($raw_breakdown)) {
                    $item_discount_breakdown = maybe_unserialize($raw_breakdown);
                } else {
                    $item_discount_breakdown = $raw_breakdown;
                }
                
                // Ensure it's an array
                if (!is_array($item_discount_breakdown)) {
                    error_log("InterSoccer Financial Report: Order {$row->order_id}, Item {$row->order_item_id} - Discount breakdown is not an array. Type: " . gettype($item_discount_breakdown) . ", Value: " . print_r($raw_breakdown, true));
                    $item_discount_breakdown = [];
                }
            }
        }
        // PRIORITY 2: Check for legacy format ("Discount" and "Discount Amount" keys)
        elseif (isset($meta_data['Discount Amount']) && !empty($meta_data['Discount Amount'])) {
            // Extract numeric value from HTML formatted amount
            // Format: <span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#67;&#72;&#70;</span>54.00</bdi></span>
            $discount_amount_html = $meta_data['Discount Amount'];
            
            // Extract numeric value using regex
            if (preg_match('/(\d+\.?\d*)/', $discount_amount_html, $matches)) {
                $discount_amount = floatval($matches[1]);
            } else {
                // Fallback: try to strip HTML and convert
                $discount_amount = floatval(strip_tags($discount_amount_html));
            }
            
            // Get discount name from "Discount" key
            if (isset($meta_data['Discount']) && !empty($meta_data['Discount'])) {
                $discount_name = trim($meta_data['Discount']);
                
                // Create breakdown array from legacy format
                $item_discount_breakdown = [
                    [
                        'name' => $discount_name,
                        'type' => intersoccer_determine_discount_type_from_name($discount_name),
                        'amount' => $discount_amount
                    ]
                ];
            }
        }
        // PRIORITY 3: FALLBACK - Calculate from price difference
        else {
            $discount_amount = $base_price - $final_price;
            
            // Log when we're using fallback calculation for debugging
            if ($discount_amount > 0.01) {
                error_log("InterSoccer Financial Report: Order {$row->order_id}, Item {$row->order_item_id} - Using fallback discount calculation (no discount metadata found). Calculated: {$discount_amount} CHF");
            }
        }

        // Get discount codes used (from order coupons)
        $coupons = $wpdb->get_col($wpdb->prepare(
            "SELECT order_item_name FROM {$wpdb->prefix}woocommerce_order_items
             WHERE order_id = %d AND order_item_type = 'coupon'",
            $row->order_id
        ));
        $discount_codes = implode(', ', $coupons);
        
        // Format discounts applied for display (combines InterSoccer discounts + coupon codes)
        $discounts_applied = [];
        
        // Add InterSoccer discounts from breakdown (new format)
        if (!empty($item_discount_breakdown) && is_array($item_discount_breakdown)) {
            foreach ($item_discount_breakdown as $disc) {
                // Handle both array format and object format
                $disc_array = is_array($disc) ? $disc : (array) $disc;
                
                if (isset($disc_array['name']) && !empty($disc_array['name'])) {
                    $discount_name = trim($disc_array['name']);
                    
                    // Add amount if available
                    if (isset($disc_array['amount']) && floatval($disc_array['amount']) > 0) {
                        $discount_amount_formatted = number_format(floatval($disc_array['amount']), 2);
                        $discount_name .= ' (' . $discount_amount_formatted . ' CHF)';
                    }
                    
                    // Only add if we have a valid discount name
                    if (!empty($discount_name)) {
                        $discounts_applied[] = $discount_name;
                    }
                }
            }
        }
        // If no breakdown but we have discount amount, check for legacy "Discount" key
        elseif ($discount_amount > 0.01 && isset($meta_data['Discount']) && !empty($meta_data['Discount'])) {
            $discount_name = trim($meta_data['Discount']);
            $discount_name .= ' (' . number_format($discount_amount, 2) . ' CHF)';
            $discounts_applied[] = $discount_name;
        }
        
        // Add WooCommerce coupon codes (these are order-level, not item-level)
        if (!empty($coupons)) {
            foreach ($coupons as $coupon_code) {
                if (!empty($coupon_code) && trim($coupon_code) !== '') {
                    $discounts_applied[] = 'Coupon: ' . trim($coupon_code);
                }
            }
        }
        
        // Format as string for display
        $discounts_applied_str = !empty($discounts_applied) ? implode('; ', $discounts_applied) : 'None';
        
        // Debug logging for discount detection
        if (defined('WP_DEBUG') && WP_DEBUG && $discount_amount > 0.01) {
            error_log("InterSoccer Financial Report: Order {$row->order_id}, Item {$row->order_item_id} - Discounts detected: " . $discounts_applied_str . " (Total: {$discount_amount} CHF)");
        }
        
        // Store discount breakdown for enhanced reporting
        $discount_type = 'other';
        if (!empty($item_discount_breakdown)) {
            // Determine discount type from breakdown
            foreach ($item_discount_breakdown as $disc) {
                if (isset($disc['type'])) {
                    $discount_type = $disc['type'];
                    break; // Use first discount type found
                }
            }
        } elseif (!empty($discount_codes)) {
            // Fallback: try to determine from coupon codes
            $discount_codes_lower = strtolower($discount_codes);
            if (strpos($discount_codes_lower, 'sibling') !== false || strpos($discount_codes_lower, 'multi-child') !== false) {
                $discount_type = 'sibling';
            } elseif (strpos($discount_codes_lower, 'same-season') !== false || strpos($discount_codes_lower, 'second-course') !== false) {
                $discount_type = 'same_season';
            } elseif (!empty($discount_codes)) {
                $discount_type = 'coupon';
            }
        }

        // Calculate Stripe fee (approximate 2.9% + 0.30 CHF)
        $stripe_fee = $final_price > 0 ? ($final_price * 0.029) + 0.30 : 0;

        $data[] = [
            'ref' => $row->order_id . '-' . $row->order_item_id,
            'booked' => date('Y-m-d', strtotime($row->order_date)),
            'base_price' => number_format($base_price, 2),
            'discount_amount' => number_format($discount_amount, 2),
            'stripe_fee' => number_format($stripe_fee, 2),
            'final_price' => number_format($final_price, 2),
            'discount_codes' => $discount_codes,
            'discounts_applied' => $discounts_applied_str, // New column: formatted discount information
            'discount_type' => $discount_type, // Store discount type for breakdown
            'discount_breakdown' => $item_discount_breakdown, // Store detailed breakdown
            'class_name' => $row->order_item_name,
            'venue' => $venue,
            'booker_email' => get_post_meta($row->order_id, '_billing_email', true),
            'booker_phone' => get_post_meta($row->order_id, '_billing_phone', true) ?: 'N/A',
            'attendee_name' => $attendee_name,
            'attendee_age' => $attendee_age,
            'attendee_gender' => $attendee_gender,
            'parent_phone' => $parent_phone,
            'selected_days' => $selected_days,
            'age_group' => $age_group,
            'activity_type' => $activity_type
        ];

        // Update totals
        // Note: Each order item represents one booking/participant
        // We don't sum quantities here - bookings = count of order items
        $totals['base_price'] += $base_price;
        $totals['discount_amount'] += $discount_amount;
        $totals['final_price'] += $final_price;
    }

    // Set bookings to the count of records (each order item = 1 booking)
    // This ensures "Total Bookings" matches "records found"
    $totals['bookings'] = count($data);

    error_log("=== InterSoccer Financial Report: Returning " . count($data) . " records with totals: " . json_encode($totals) . " ===");
    return ['data' => $data, 'totals' => $totals];
}