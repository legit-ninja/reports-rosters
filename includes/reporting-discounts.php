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
                    'price' => floatval($item['data']->get_price()),
                    'item' => $item
                ];
            }
        }
    }
    
    // Calculate child totals and sort
    $child_totals = [];
    foreach ($courses_by_child as $child_id => $courses) {
        $child_totals[$child_id] = array_sum(array_column($courses, 'price'));
    }
    arsort($child_totals);
    
    $sorted_children = array_keys($child_totals);
    $assigned_player = $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
    
    // Find which position this child is in
    $child_position = array_search($assigned_player, $sorted_children);
    
    if ($child_position !== false && $child_position > 0) {
        $item_price = floatval($cart_item['data']->get_price());
        
        if ($child_position === 1) {
            // Second child - 20%
            return $item_price * 0.20;
        } else {
            // Third+ child - 30%
            return $item_price * 0.30;
        }
    }
    
    return 0;
}

/**
 * Calculate same-season course discount portion
 */
function intersoccer_calculate_same_season_portion($cart_item, $all_cart_items, $discount) {
    $assigned_player = $cart_item['assigned_attendee'] ?? $cart_item['assigned_player'] ?? null;
    $item_season = intersoccer_get_product_season($cart_item['product_id']);
    
    // Get all course items for same child and season
    $same_season_courses = [];
    foreach ($all_cart_items as $item) {
        if (intersoccer_get_product_type($item['product_id']) === 'course') {
            $player_id = $item['assigned_attendee'] ?? $item['assigned_player'] ?? null;
            $season = intersoccer_get_product_season($item['product_id']);
            
            if ($player_id === $assigned_player && $season === $item_season) {
                $same_season_courses[] = [
                    'price' => floatval($item['data']->get_price()),
                    'item' => $item
                ];
            }
        }
    }
    
    if (count($same_season_courses) < 2) {
        return 0;
    }
    
    // Sort by price (descending)
    usort($same_season_courses, function($a, $b) {
        return $b['price'] <=> $a['price'];
    });
    
    // Find position of current item
    foreach ($same_season_courses as $index => $course) {
        if ($course['item'] === $cart_item) {
            if ($index > 0) {
                // Not the most expensive - gets 50% discount
                return floatval($cart_item['data']->get_price()) * 0.50;
            }
            break;
        }
    }
    
    return 0;
}

/**
 * Enhanced booking report that uses new discount data
 */
function intersoccer_get_booking_report_enhanced($start_date = '', $end_date = '', $year = '', $region = '') {
    global $wpdb;
    
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';

    if (!$year) {
        $year = date('Y');
    }

    error_log("InterSoccer Enhanced: Getting booking report for year {$year}");

    // Enhanced query with discount data
    $query = "SELECT 
        r.order_id,
        r.order_item_id,
        r.variation_id,
        r.product_name,
        r.venue,
        r.start_date,
        r.parent_email,
        r.parent_phone,
        r.player_name,
        r.player_first_name,
        r.player_last_name,
        r.age,
        r.gender,
        r.player_gender,
        r.canton_region,
        r.registration_timestamp,
        p.post_date AS order_date,
        p.post_status AS order_status,
        
        -- Price data
        COALESCE(CAST(subtotal.meta_value AS DECIMAL(10,2)), 0) AS base_price,
        COALESCE(CAST(total.meta_value AS DECIMAL(10,2)), 0) AS final_price,
        
        -- Enhanced discount data
        COALESCE(CAST(intersoccer_discount.meta_value AS DECIMAL(10,2)), 0) AS intersoccer_discount_amount,
        intersoccer_breakdown.meta_value AS intersoccer_discount_breakdown,
        
        -- Refund data
        COALESCE(ABS(CAST(refunded.refunded_total AS DECIMAL(10,2))), 0) AS reimbursement
        
    FROM $rosters_table r
    INNER JOIN $posts_table p ON r.order_id = p.ID 
        AND p.post_type = 'shop_order' 
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
    INNER JOIN $order_items_table oi ON r.order_item_id = oi.order_item_id
    LEFT JOIN $order_itemmeta_table subtotal ON oi.order_item_id = subtotal.order_item_id 
        AND subtotal.meta_key = '_line_subtotal'
    LEFT JOIN $order_itemmeta_table total ON oi.order_item_id = total.order_item_id 
        AND total.meta_key = '_line_total'
    
    -- Join with enhanced discount data
    LEFT JOIN $postmeta_table intersoccer_discount ON p.ID = intersoccer_discount.post_id 
        AND intersoccer_discount.meta_key = '_intersoccer_total_discounts'
    LEFT JOIN $postmeta_table intersoccer_breakdown ON p.ID = intersoccer_breakdown.post_id 
        AND intersoccer_breakdown.meta_key = '_intersoccer_all_discounts'
        
    LEFT JOIN (
        SELECT 
            parent.ID AS order_id, 
            SUM(CAST(refmeta.meta_value AS DECIMAL(10,2))) AS refunded_total
        FROM $posts_table refund
        INNER JOIN $posts_table parent ON refund.post_parent = parent.ID
        LEFT JOIN $postmeta_table refmeta ON refund.ID = refmeta.post_id 
            AND refmeta.meta_key = '_refund_amount'
        WHERE refund.post_type = 'shop_order_refund' 
            AND CAST(refmeta.meta_value AS DECIMAL(10,2)) < 0
        GROUP BY parent.ID
    ) refunded ON r.order_id = refunded.order_id
    
    WHERE YEAR(p.post_date) = %d";

    $params = array($year);

    // Add date filters
    if ($start_date) {
        $query .= " AND DATE(p.post_date) >= %s";
        $params[] = $start_date;
    }
    if ($end_date) {
        $query .= " AND DATE(p.post_date) <= %s";
        $params[] = $end_date;
    }
    if ($region) {
        $query .= " AND r.canton_region = %s";
        $params[] = $region;
    }

    $query .= " ORDER BY p.post_date DESC, r.order_id, r.order_item_id";

    error_log("InterSoccer Enhanced: Executing query with " . count($params) . " parameters");
    
    $results = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

    if ($wpdb->last_error) {
        error_log('InterSoccer Enhanced: Query error: ' . $wpdb->last_error);
        return array('data' => array(), 'totals' => array('bookings' => 0, 'base_price' => 0, 'discount_amount' => 0, 'final_price' => 0, 'reimbursement' => 0));
    }

    error_log('InterSoccer Enhanced: Query returned ' . count($results) . ' results');

    if (empty($results)) {
        return array('data' => array(), 'totals' => array('bookings' => 0, 'base_price' => 0, 'discount_amount' => 0, 'final_price' => 0, 'reimbursement' => 0));
    }

    // Get coupon data for orders
    $order_ids = array_unique(array_column($results, 'order_id'));
    $coupon_data = array();
    
    if (!empty($order_ids)) {
        $order_ids_placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $coupon_query = "SELECT order_id, GROUP_CONCAT(order_item_name SEPARATOR ', ') as coupon_codes
                        FROM $order_items_table 
                        WHERE order_id IN ($order_ids_placeholders) 
                        AND order_item_type = 'coupon'
                        GROUP BY order_id";
        
        $coupon_results = $wpdb->get_results($wpdb->prepare($coupon_query, $order_ids), ARRAY_A);
        
        foreach ($coupon_results as $coupon_row) {
            $coupon_data[$coupon_row['order_id']] = $coupon_row['coupon_codes'];
        }
    }

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

        // Use enhanced discount data if available
        $enhanced_discount = floatval($row['intersoccer_discount_amount']);
        $discount_breakdown = $row['intersoccer_discount_breakdown'];
        
        // Calculate traditional discount as fallback
        $traditional_discount = floatval($row['base_price']) - floatval($row['final_price']);
        
        // Use enhanced discount data if available, otherwise fall back
        $total_discount = $enhanced_discount > 0 ? $enhanced_discount : $traditional_discount;
        $final_price = floatval($row['base_price']) - $total_discount;

        // Parse discount breakdown for detailed reporting
        $discount_details = array();
        if (!empty($discount_breakdown)) {
            $parsed_breakdown = maybe_unserialize($discount_breakdown);
            if (is_array($parsed_breakdown)) {
                foreach ($parsed_breakdown as $discount_detail) {
                    if (is_array($discount_detail) && isset($discount_detail['name']) && isset($discount_detail['amount'])) {
                        $discount_details[] = $discount_detail['name'] . ' (' . number_format($discount_detail['amount'], 2) . ' CHF)';
                    }
                }
            }
        }

        // Build discount codes string
        $discount_codes_parts = array();
        if (!empty($coupon_data[$row['order_id']])) {
            $discount_codes_parts[] = $coupon_data[$row['order_id']] . ' (coupon)';
        }
        if (!empty($discount_details)) {
            $discount_codes_parts = array_merge($discount_codes_parts, $discount_details);
        }
        $discount_codes = !empty($discount_codes_parts) ? implode(', ', $discount_codes_parts) : 'None';

        // Reimbursement
        $reimbursement = floatval($row['reimbursement']);

        // Determine gender
        $gender = $row['player_gender'] ? $row['player_gender'] : ($row['gender'] ? $row['gender'] : 'N/A');

        // Format dates
        $booked_date = '';
        if (!empty($row['order_date'])) {
            $booked_date = date('Y-m-d H:i', strtotime($row['order_date']));
        } elseif (!empty($row['registration_timestamp'])) {
            $booked_date = date('Y-m-d H:i', strtotime($row['registration_timestamp']));
        }

        $data[] = array(
            'ref' => 'ORD-' . $row['order_id'] . '-' . $row['order_item_id'],
            'order_id' => $row['order_id'],
            'booked' => $booked_date,
            'base_price' => number_format((float)$row['base_price'], 2),
            'discount_amount' => number_format($total_discount, 2),
            'reimbursement' => number_format($reimbursement, 2),
            'final_price' => number_format($final_price, 2),
            'discount_codes' => $discount_codes,
            'class_name' => $row['product_name'] ? $row['product_name'] : 'N/A',
            'start_date' => $row['start_date'] ? $row['start_date'] : 'N/A',
            'venue' => $row['venue'] ? $row['venue'] : 'N/A',
            'booker_email' => $row['parent_email'] ? $row['parent_email'] : 'N/A',
            'attendee_name' => $attendee_name ? $attendee_name : 'N/A',
            'attendee_age' => $row['age'] ? $row['age'] : 'N/A',
            'attendee_gender' => $gender,
            'parent_phone' => $row['parent_phone'] ? $row['parent_phone'] : 'N/A'
        );
    }

    // Calculate totals
    $totals = array(
        'bookings' => count($data),
        'base_price' => array_sum(array_map(function($row) { 
            return (float)str_replace(',', '', $row['base_price']); 
        }, $data)),
        'discount_amount' => array_sum(array_map(function($row) { 
            return (float)str_replace(',', '', $row['discount_amount']); 
        }, $data)),
        'final_price' => array_sum(array_map(function($row) { 
            return (float)str_replace(',', '', $row['final_price']); 
        }, $data)),
        'reimbursement' => array_sum(array_map(function($row) { 
            return (float)str_replace(',', '', $row['reimbursement']); 
        }, $data))
    );

    error_log('InterSoccer Enhanced: Report totals - Bookings: ' . $totals['bookings'] . 
              ', Base: ' . $totals['base_price'] . 
              ', Final: ' . $totals['final_price'] . 
              ', Discounts: ' . $totals['discount_amount'] . 
              ', Refunds: ' . $totals['reimbursement']);

    return array('data' => $data, 'totals' => $totals);
}

/**
 * Migration function to backfill discount data for existing orders
 */
function intersoccer_migrate_existing_order_discounts() {
    global $wpdb;
    
    // Get orders that don't have intersoccer discount data yet
    $orders_query = "SELECT DISTINCT p.ID as order_id 
                    FROM {$wpdb->prefix}posts p
                    LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id 
                        AND pm.meta_key = '_intersoccer_total_discounts'
                    WHERE p.post_type = 'shop_order' 
                    AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
                    AND pm.meta_value IS NULL
                    AND p.post_date >= '2024-01-01'
                    LIMIT 100";
    
    $orders_to_migrate = $wpdb->get_results($orders_query, ARRAY_A);
    
    error_log("InterSoccer Migration: Found " . count($orders_to_migrate) . " orders to migrate");
    
    foreach ($orders_to_migrate as $order_row) {
        $order_id = $order_row['order_id'];
        
        try {
            $order = wc_get_order($order_id);
            if (!$order) continue;
            
            $total_discounts = 0;
            $discount_breakdown = [];
            
            // Look for fees in the order
            foreach ($order->get_fees() as $fee) {
                if ($fee->get_amount() < 0) {
                    $discount_breakdown[] = [
                        'name' => $fee->get_name(),
                        'amount' => abs($fee->get_amount()),
                        'type' => intersoccer_determine_discount_type($fee->get_name())
                    ];
                    $total_discounts += abs($fee->get_amount());
                }
            }
            
            // Look for traditional cart discounts
            $cart_discount = floatval(get_post_meta($order_id, '_cart_discount', true));
            if ($cart_discount > 0) {
                $discount_breakdown[] = [
                    'name' => 'Cart Discount',
                    'amount' => $cart_discount,
                    'type' => 'coupon'
                ];
                $total_discounts += $cart_discount;
            }
            
            // Store the data
            update_post_meta($order_id, '_intersoccer_total_discounts', $total_discounts);
            update_post_meta($order_id, '_intersoccer_discount_breakdown', $discount_breakdown);
            
            // Migrate item-level data
            foreach ($order->get_items() as $item_id => $item) {
                $line_subtotal = floatval($item->get_subtotal());
                $line_total = floatval($item->get_total());
                $item_discount = $line_subtotal - $line_total;
                
                if ($item_discount > 0) {
                    wc_update_order_item_meta($item_id, '_intersoccer_discount_amount', $item_discount);
                    
                    // Create basic discount breakdown for the item
                    $item_discount_breakdown = [[
                        'name' => 'Legacy Discount',
                        'amount' => $item_discount,
                        'type' => 'legacy'
                    ]];
                    wc_update_order_item_meta($item_id, '_intersoccer_item_discounts', $item_discount_breakdown);
                }
            }
            
            error_log("InterSoccer Migration: Migrated order {$order_id} with {$total_discounts} CHF total discounts");
            
        } catch (Exception $e) {
            error_log("InterSoccer Migration: Error migrating order {$order_id}: " . $e->getMessage());
        }
    }
    
    return count($orders_to_migrate);
}

/**
 * Admin action to run migration
 */
add_action('wp_ajax_intersoccer_migrate_discounts', 'intersoccer_migrate_discounts_ajax');
function intersoccer_migrate_discounts_ajax() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    check_ajax_referer('intersoccer_migrate_discounts', 'nonce');
    
    $migrated_count = intersoccer_migrate_existing_order_discounts();
    
    wp_send_json_success([
        'message' => "Successfully migrated {$migrated_count} orders",
        'migrated_count' => $migrated_count
    ]);
}

/**
 * Add migration notice and button to admin
 */
add_action('admin_notices', 'intersoccer_discount_migration_notice');
function intersoccer_discount_migration_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $screen = get_current_screen();
    if ($screen->id !== 'intersoccer_page_intersoccer-reports') {
        return;
    }
    
    // Check if migration is needed
    global $wpdb;
    $unmigrated_count = $wpdb->get_var("
        SELECT COUNT(DISTINCT p.ID) 
        FROM {$wpdb->prefix}posts p
        LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id 
            AND pm.meta_key = '_intersoccer_total_discounts'
        WHERE p.post_type = 'shop_order' 
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND pm.meta_value IS NULL
        AND p.post_date >= '2024-01-01'
    ");
    
    if ($unmigrated_count > 0) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong>InterSoccer Discount Migration Required</strong></p>
            <p>Found <?php echo $unmigrated_count; ?> orders that need discount data migration for accurate reporting.</p>
            <p>
                <button id="intersoccer-migrate-discounts" class="button button-primary">
                    Migrate Discount Data (<?php echo $unmigrated_count; ?> orders)
                </button>
                <span id="migration-status" style="margin-left: 10px;"></span>
            </p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#intersoccer-migrate-discounts').on('click', function() {
                var $btn = $(this);
                var $status = $('#migration-status');
                
                $btn.prop('disabled', true).text('Migrating...');
                $status.text('Processing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_migrate_discounts',
                        nonce: '<?php echo wp_create_nonce('intersoccer_migrate_discounts'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                            $btn.text('Migration Complete').css('background', '#46b450');
                            
                            // Refresh page after 2 seconds
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $status.html('<span style="color: red;">✗ Migration failed</span>');
                            $btn.prop('disabled', false).text('Retry Migration');
                        }
                    },
                    error: function() {
                        $status.html('<span style="color: red;">✗ Connection error</span>');
                        $btn.prop('disabled', false).text('Retry Migration');
                    }
                });
            });
        });
        </script>
        <?php
    }
}

/**
 * Enhanced debug function for troubleshooting discount reporting
 */
function intersoccer_debug_discount_reporting($order_id) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    error_log("=== InterSoccer Discount Reporting Debug for Order {$order_id} ===");
    
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log("Order {$order_id} not found");
        return;
    }
    
    // Check for stored intersoccer discount data
    $total_discounts = get_post_meta($order_id, '_intersoccer_total_discounts', true);
    $discount_breakdown = get_post_meta($order_id, '_intersoccer_discount_breakdown', true);
    
    error_log("Stored Total Discounts: " . ($total_discounts ?: 'None'));
    error_log("Stored Discount Breakdown: " . print_r($discount_breakdown, true));
    
    // Check order fees
    $fees = $order->get_fees();
    error_log("Order Fees: " . count($fees));
    foreach ($fees as $fee) {
        error_log("  Fee: " . $fee->get_name() . " = " . $fee->get_amount() . " CHF");
    }
    
    // Check order items
    foreach ($order->get_items() as $item_id => $item) {
        $item_discount = wc_get_order_item_meta($item_id, '_intersoccer_discount_amount', true);
        $item_breakdown = wc_get_order_item_meta($item_id, '_intersoccer_item_discounts', true);
        $assigned_player = wc_get_order_item_meta($item_id, '_intersoccer_assigned_player', true);
        
        error_log("Item {$item_id}: " . $item->get_name());
        error_log("  Subtotal: " . $item->get_subtotal() . ", Total: " . $item->get_total());
        error_log("  InterSoccer Discount: " . ($item_discount ?: 'None'));
        error_log("  Assigned Player: " . ($assigned_player ?: 'None'));
        error_log("  Discount Breakdown: " . print_r($item_breakdown, true));
    }
    
    error_log("=== End Discount Reporting Debug ===");
}

/**
 * Validation function to check discount reporting accuracy
 */
function intersoccer_validate_discount_reporting($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return false;
    }
    
    $stored_total = floatval(get_post_meta($order_id, '_intersoccer_total_discounts', true));
    
    // Calculate actual discount from order items
    $calculated_total = 0;
    foreach ($order->get_items() as $item_id => $item) {
        $item_discount = floatval(wc_get_order_item_meta($item_id, '_intersoccer_discount_amount', true));
        $calculated_total += $item_discount;
    }
    
    // Also check fees
    $fee_total = 0;
    foreach ($order->get_fees() as $fee) {
        if ($fee->get_amount() < 0) {
            $fee_total += abs($fee->get_amount());
        }
    }
    
    $is_valid = abs($stored_total - $calculated_total) < 0.01; // Allow for rounding
    
    if (!$is_valid) {
        error_log("InterSoccer Validation: Order {$order_id} discount mismatch - Stored: {$stored_total}, Calculated: {$calculated_total}, Fees: {$fee_total}");
    }
    
    return $is_valid;
}

/**
 * Hook to store discount data when orders are completed via admin
 * This catches orders that might be processed outside the normal checkout flow
 */
add_action('woocommerce_order_status_changed', 'intersoccer_store_discount_on_status_change', 10, 4);
function intersoccer_store_discount_on_status_change($order_id, $old_status, $new_status, $order) {
    // Only process when order moves to a completed status
    if (!in_array($new_status, ['completed', 'processing'])) {
        return;
    }
    
    // Check if we already have discount data
    $existing_discount = get_post_meta($order_id, '_intersoccer_total_discounts', true);
    if (!empty($existing_discount)) {
        return; // Already processed
    }
    
    error_log("InterSoccer Reporting: Processing discount data for order {$order_id} status change to {$new_status}");
    
    // Try to extract discount data from the order
    $total_discounts = 0;
    $discount_breakdown = [];
    
    // Look for fees (our discounts)
    foreach ($order->get_fees() as $fee) {
        if ($fee->get_amount() < 0) {
            $discount_breakdown[] = [
                'name' => $fee->get_name(),
                'amount' => abs($fee->get_amount()),
                'type' => intersoccer_determine_discount_type($fee->get_name())
            ];
            $total_discounts += abs($fee->get_amount());
        }
    }
    
    // Look for traditional cart discounts
    $cart_discount = floatval(get_post_meta($order_id, '_cart_discount', true));
    if ($cart_discount > 0) {
        $discount_breakdown[] = [
            'name' => 'Cart Discount',
            'amount' => $cart_discount,
            'type' => 'coupon'
        ];
        $total_discounts += $cart_discount;
    }
    
    // Store the data
    if ($total_discounts > 0) {
        update_post_meta($order_id, '_intersoccer_total_discounts', $total_discounts);
        update_post_meta($order_id, '_intersoccer_discount_breakdown', $discount_breakdown);
        
        error_log("InterSoccer Reporting: Stored {$total_discounts} CHF total discounts for order {$order_id}");
    }
}

/**
 * Allocate camp sibling discount to specific camp items
 */
function intersoccer_allocate_camp_sibling_discount($combo_discount, $cart_context, $order) {
    $allocations = array();
    $camps_by_child = $cart_context['camps_by_child'];
    
    if (count($camps_by_child) < 2) {
        return $allocations; // No sibling discount applicable
    }
    
    // Flatten all camps and sort by price (descending)
    $all_camps = array();
    foreach ($camps_by_child as $child_id => $camps) {
        foreach ($camps as $camp) {
            $camp['child_id'] = $child_id;
            $all_camps[] = $camp;
        }
    }
    
    // Sort by price (descending) - highest priced camps don't get discounts
    usort($all_camps, function($a, $b) {
        return $b['price'] <=> $a['price'];
    });
    
    // Find order item IDs for cart items
    $item_mapping = intersoccer_map_cart_to_order_items($order, $all_camps);
    
    // Allocate discounts starting from 2nd most expensive camp
    $discount_rates = intersoccer_get_discount_rates()['camp'];
    $second_child_rate = $discount_rates['2nd_child'] ?? 0.20;
    $third_plus_rate = $discount_rates['3rd_plus_child'] ?? 0.25;
    
    for ($i = 1; $i < count($all_camps); $i++) {
        $camp = $all_camps[$i];
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        $discount_amount = $camp['price'] * $discount_rate;
        
        if (isset($item_mapping[$camp['cart_key']])) {
            $item_id = $item_mapping[$camp['cart_key']];
            $allocations[] = array(
                'item_id' => $item_id,
                'amount' => round($discount_amount, 2),
                'method' => 'camp_sibling_rules',
                'child_position' => $i + 1,
                'discount_rate' => $discount_rate
            );
            
            error_log("InterSoccer Precise: Camp sibling discount - Child position " . ($i + 1) . " gets {$discount_rate}% discount = {$discount_amount} CHF on item {$item_id}");
        }
    }
    
    return $allocations;
}


/**
 * Allocate course multi-child discount to specific course items
 */
function intersoccer_allocate_course_multi_child_discount($combo_discount, $cart_context, $order) {
    $allocations = array();
    $courses_by_child = $cart_context['courses_by_child'];
    
    if (count($courses_by_child) < 2) {
        return $allocations; // No multi-child discount applicable
    }
    
    // Calculate child totals and sort
    $child_totals = array();
    foreach ($courses_by_child as $child_id => $courses) {
        $child_totals[$child_id] = array_sum(array_column($courses, 'price'));
    }
    arsort($child_totals); // Highest total first
    
    $sorted_children = array_keys($child_totals);
    $item_mapping = intersoccer_map_cart_to_order_items($order, $cart_context['all_items']);
    
    $discount_rates = intersoccer_get_discount_rates()['course'];
    $second_child_rate = $discount_rates['2nd_child'] ?? 0.20;
    $third_plus_rate = $discount_rates['3rd_plus_child'] ?? 0.30;
    
    // Apply discounts to 2nd, 3rd+ children's courses
    for ($i = 1; $i < count($sorted_children); $i++) {
        $child_id = $sorted_children[$i];
        $discount_rate = ($i === 1) ? $second_child_rate : $third_plus_rate;
        
        foreach ($courses_by_child[$child_id] as $course) {
            $discount_amount = $course['price'] * $discount_rate;
            
            if (isset($item_mapping[$course['cart_key']])) {
                $item_id = $item_mapping[$course['cart_key']];
                $allocations[] = array(
                    'item_id' => $item_id,
                    'amount' => round($discount_amount, 2),
                    'method' => 'course_multi_child_rules',
                    'child_position' => $i + 1,
                    'discount_rate' => $discount_rate
                );
                
                error_log("InterSoccer Precise: Course multi-child discount - Child position " . ($i + 1) . " gets {$discount_rate}% discount = {$discount_amount} CHF on item {$item_id}");
            }
        }
    }
    
    return $allocations;
}


/**
 * Allocate same-season course discount to specific course items
 */
function intersoccer_allocate_course_same_season_discount($combo_discount, $cart_context, $order) {
    $allocations = array();
    $courses_by_season_child = $cart_context['courses_by_season_child'];
    
    $item_mapping = intersoccer_map_cart_to_order_items($order, $cart_context['all_items']);
    $discount_rates = intersoccer_get_discount_rates()['course'];
    $same_season_rate = $discount_rates['same_season_course'] ?? 0.50;
    
    foreach ($courses_by_season_child as $season => $children_courses) {
        foreach ($children_courses as $child_id => $courses) {
            if (count($courses) < 2) {
                continue; // Need at least 2 courses for same-season discount
            }
            
            // Sort courses by price (descending) - discount applies to cheaper courses
            usort($courses, function($a, $b) {
                return $b['price'] <=> $a['price'];
            });
            
            // Apply 50% discount to 2nd, 3rd+ courses for this child in this season
            for ($i = 1; $i < count($courses); $i++) {
                $course = $courses[$i];
                $discount_amount = $course['price'] * $same_season_rate;
                
                if (isset($item_mapping[$course['cart_key']])) {
                    $item_id = $item_mapping[$course['cart_key']];
                    $allocations[] = array(
                        'item_id' => $item_id,
                        'amount' => round($discount_amount, 2),
                        'method' => 'same_season_course_rules',
                        'season' => $season,
                        'course_position' => $i + 1,
                        'discount_rate' => $same_season_rate
                    );
                    
                    error_log("InterSoccer Precise: Same-season course discount - Course position " . ($i + 1) . " in {$season} gets {$same_season_rate}% discount = {$discount_amount} CHF on item {$item_id}");
                }
            }
        }
    }
    
    return $allocations;
}

/**
 * Add discount reporting diagnostic tools
 */
add_action('wp_ajax_intersoccer_debug_order_discounts', 'intersoccer_debug_order_discounts_ajax');
function intersoccer_debug_order_discounts_ajax() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        wp_send_json_error(['message' => 'Invalid order ID']);
    }
    
    intersoccer_debug_discount_reporting($order_id);
    $is_valid = intersoccer_validate_discount_reporting($order_id);
    
    wp_send_json_success([
        'message' => "Debug completed for order {$order_id}",
        'is_valid' => $is_valid,
        'debug_logged' => true
    ]);
}

error_log('InterSoccer: Loaded enhanced discount reporting system for finance team');
?>