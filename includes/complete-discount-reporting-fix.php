<?php
/**
 * DEPRECATED: Superseded by OrderFinancialAttributionService (classes/services/order-financial-attribution-service.php).
 * Do not require this file — it registers duplicate checkout hooks.
 *
 * Clean Discount Reporting Fix for InterSoccer Finance Team
 * Integrates with existing advanced.php - No syntax errors
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CRITICAL: Capture discount data when orders are placed
 */
add_action('woocommerce_checkout_order_processed', 'intersoccer_capture_discount_data', 10, 3);
function intersoccer_capture_discount_data($order_id, $posted_data, $order) {
    try {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Precise: Processing order for precise discount allocation');
        }
        
        // Get cart data before it's cleared
        $cart_items = WC()->cart->get_cart();
        $cart_fees = WC()->cart->get_fees();
        
        // Store cart context for discount allocation
        $cart_context = intersoccer_build_cart_context($cart_items);
        
        // Get all discount sources
        $discount_sources = array(
            'combo_fees' => intersoccer_extract_combo_discounts($cart_fees),
            'coupons' => intersoccer_extract_coupon_discounts($order),
            'line_items' => intersoccer_extract_line_item_discounts($order)
        );
        
        // Allocate discounts to specific order items with precision
        intersoccer_allocate_discounts($order_id, $order, $cart_context, $discount_sources);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Precise: Successfully processed order with precise discount allocation');
        }
        
    } catch (Exception $e) {
        error_log('InterSoccer Precise: Error processing order for precise discount allocation: ' . $e->getMessage());
    }
}


/**
 * Extract combo discounts from cart fees
 */
function intersoccer_extract_combo_discounts($cart_fees) {
    $combo_discounts = array();
    
    foreach ($cart_fees as $fee) {
        if ($fee->amount < 0) { // Negative = discount
            $combo_discounts[] = array(
                'name' => $fee->name,
                'amount' => abs($fee->amount),
                'type' => intersoccer_determine_precise_discount_type($fee->name),
                'source' => 'combo_fee'
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer Precise: Found combo discount: ' . $fee->name . ' = ' . abs($fee->amount) . ' CHF');
            }
        }
    }
    
    return $combo_discounts;
}

/**
 * Extract coupon discounts from order
 */
function intersoccer_extract_coupon_discounts($order) {
    $coupon_discounts = array();
    
    foreach ($order->get_coupon_codes() as $coupon_code) {
        $coupon_amount = $order->get_discount_amount($coupon_code);
        if ($coupon_amount > 0) {
            $coupon_discounts[] = array(
                'name' => $coupon_code,
                'amount' => $coupon_amount,
                'type' => 'coupon',
                'source' => 'woocommerce_coupon'
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer Precise: Found coupon discount: ' . $coupon_code . ' = ' . $coupon_amount . ' CHF');
            }
        }
    }
    
    return $coupon_discounts;
}

/**
 * Extract line item discounts (difference between subtotal and total)
 */
function intersoccer_extract_line_item_discounts($order) {
    $line_item_discounts = array();
    
    foreach ($order->get_items() as $item_id => $item) {
        $line_subtotal = floatval($item->get_subtotal());
        $line_total = floatval($item->get_total());
        $line_discount = $line_subtotal - $line_total;
        
        if ($line_discount > 0.01) { // More than 1 cent
            $line_item_discounts[] = array(
                'item_id' => $item_id,
                'amount' => $line_discount,
                'type' => 'line_item',
                'source' => 'woocommerce_line_item'
            );
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer Precise: Found line item discount: ' . $line_discount . ' CHF');
            }
        }
    }
    
    return $line_item_discounts;
}

/**
 * CORE FUNCTION: Allocate discounts precisely to specific line items
 */
function intersoccer_allocate_discounts($order_id, $order, $cart_context, $discount_sources) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Precise: Starting precise discount allocation');
    }
    
    // Initialize tracking
    $item_discount_allocations = array();
    $total_allocated = 0;
    
    // Process combo discounts first (most specific)
    foreach ($discount_sources['combo_fees'] as $combo_discount) {
        $allocated_amount = intersoccer_allocate_combo_discount($combo_discount, $cart_context, $order);
        
        if (!empty($allocated_amount)) {
            foreach ($allocated_amount as $allocation) {
                $item_id = $allocation['item_id'];
                if (!isset($item_discount_allocations[$item_id])) {
                    $item_discount_allocations[$item_id] = array();
                }
                $item_discount_allocations[$item_id][] = array(
                    'name' => $combo_discount['name'],
                    'type' => $combo_discount['type'],
                    'amount' => $allocation['amount'],
                    'source' => 'combo_precise',
                    'allocation_method' => $allocation['method']
                );
                $total_allocated += $allocation['amount'];
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer Precise: Allocated combo discount using ' . $allocation['method']);
                }
            }
        }
    }
    
    // Process coupon discounts (proportional across all items)
    foreach ($discount_sources['coupons'] as $coupon_discount) {
        $allocated_amount = intersoccer_allocate_coupon_discount_precisely($coupon_discount, $order);
        
        if (!empty($allocated_amount)) {
            foreach ($allocated_amount as $allocation) {
                $item_id = $allocation['item_id'];
                if (!isset($item_discount_allocations[$item_id])) {
                    $item_discount_allocations[$item_id] = array();
                }
                $item_discount_allocations[$item_id][] = array(
                    'name' => $coupon_discount['name'],
                    'type' => 'coupon',
                    'amount' => $allocation['amount'],
                    'source' => 'coupon_precise',
                    'allocation_method' => 'proportional'
                );
                $total_allocated += $allocation['amount'];
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer Precise: Allocated coupon discount to line item');
                }
            }
        }
    }
    
    // Process line item discounts (already item-specific)
    foreach ($discount_sources['line_items'] as $line_discount) {
        $item_id = $line_discount['item_id'];
        if (!isset($item_discount_allocations[$item_id])) {
            $item_discount_allocations[$item_id] = array();
        }
        $item_discount_allocations[$item_id][] = array(
            'name' => 'Line Item Discount',
            'type' => 'line_item',
            'amount' => $line_discount['amount'],
            'source' => 'line_item_precise',
            'allocation_method' => 'direct'
        );
        $total_allocated += $line_discount['amount'];
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Precise: Allocated line item discount');
        }
    }
    
    // Store precise allocations to order items
    foreach ($item_discount_allocations as $item_id => $discounts) {
        $total_item_discount = array_sum(array_column($discounts, 'amount'));
        
        wc_update_order_item_meta($item_id, '_intersoccer_item_discounts', $discounts);
        wc_update_order_item_meta($item_id, '_intersoccer_total_item_discount', round($total_item_discount, 2));
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Precise: Stored discount allocations for line item');
        }
    }
    
    // Store order-level summary
    $order_total_discounts = $total_allocated;
    $all_discounts = array_merge($discount_sources['combo_fees'], $discount_sources['coupons']);
    
    update_post_meta($order_id, '_intersoccer_total_discounts', round($order_total_discounts, 2));
    update_post_meta($order_id, '_intersoccer_all_discounts', $all_discounts);
    update_post_meta($order_id, '_intersoccer_precise_allocation', true);
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Precise Migration: Successfully migrated order discount allocations');
    }
}

/**
 * Allocate discounts to individual order items
 */
function intersoccer_allocate_discounts_to_items($order_id, $order, $all_discounts) {
    if (empty($all_discounts)) {
        return;
    }
    
    $order_items = $order->get_items();
    $total_subtotal = 0;
    
    // Calculate total subtotal
    foreach ($order_items as $item) {
        $total_subtotal += floatval($item->get_subtotal());
    }
    
    if ($total_subtotal <= 0) {
        return;
    }
    
    // Allocate discounts to items
    foreach ($order_items as $item_id => $item) {
        $item_subtotal = floatval($item->get_subtotal());
        $item_discounts = array();
        $total_item_discount = 0;
        
        foreach ($all_discounts as $discount) {
            // Proportional allocation for simplicity
            $allocated_amount = ($item_subtotal / $total_subtotal) * $discount['amount'];
            
            if ($allocated_amount > 0.01) { // Only allocate if > 1 cent
                $item_discounts[] = array(
                    'name' => $discount['name'],
                    'type' => $discount['type'],
                    'amount' => round($allocated_amount, 2)
                );
                $total_item_discount += $allocated_amount;
            }
        }
        
        // Store item-level discount data
        if (!empty($item_discounts)) {
            wc_update_order_item_meta($item_id, '_intersoccer_item_discounts', $item_discounts);
            wc_update_order_item_meta($item_id, '_intersoccer_total_item_discount', round($total_item_discount, 2));
        }
    }
}

/**
 * Migration function for historical orders
 */
function intersoccer_migrate_discount_data_batch($batch_size = 100) {
    global $wpdb;
    
    // Get unmigrated orders
    $unmigrated_orders = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID, p.post_date 
        FROM {$wpdb->prefix}posts p
        LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id 
            AND pm.meta_key = '_intersoccer_total_discounts'
        WHERE p.post_type = 'shop_order' 
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND pm.meta_value IS NULL
        AND p.post_date >= '2024-01-01'
        ORDER BY p.post_date DESC
        LIMIT %d
    ", $batch_size), ARRAY_A);
    
    $migrated_count = 0;
    $errors = array();
    
    foreach ($unmigrated_orders as $order_row) {
        $order_id = $order_row['ID'];
        
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                $errors[] = "Order {$order_id} not found";
                continue;
            }
            
            // Migrate this order
            intersoccer_migrate_single_order($order_id, $order);
            $migrated_count++;
            
        } catch (Exception $e) {
            $errors[] = "Order {$order_id}: " . $e->getMessage();
            error_log('InterSoccer Migration: Error migrating order discounts: ' . $e->getMessage());
        }
    }
    
    // Check if more orders remain
    $remaining_count = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$wpdb->prefix}posts p
        LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id 
            AND pm.meta_key = '_intersoccer_total_discounts'
        WHERE p.post_type = 'shop_order' 
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND pm.meta_value IS NULL
        AND p.post_date >= '2024-01-01'
    ");
    
    return array(
        'migrated_count' => $migrated_count,
        'remaining_count' => intval($remaining_count),
        'more_remaining' => $remaining_count > 0,
        'errors' => $errors
    );
}

/**
 * Migrate a single order
 */
function intersoccer_migrate_single_order($order_id, $order) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Migration: Processing order for discount migration');
    }
    
    $total_discounts = 0;
    $all_discounts = array();
    $combo_discounts = array();
    $coupon_discounts = array();
    
    // Extract fee-based discounts
    foreach ($order->get_fees() as $fee) {
        if ($fee->get_amount() < 0) {
            $discount_amount = abs($fee->get_amount());
            $discount_data = array(
                'name' => $fee->get_name(),
                'amount' => $discount_amount,
                'type' => intersoccer_determine_discount_type($fee->get_name())
            );
            
            $combo_discounts[] = $discount_data;
            $all_discounts[] = $discount_data;
            $total_discounts += $discount_amount;
        }
    }
    
    // Extract coupon discounts
    foreach ($order->get_coupon_codes() as $coupon_code) {
        $coupon_amount = $order->get_discount_amount($coupon_code);
        if ($coupon_amount > 0) {
            $discount_data = array(
                'name' => $coupon_code,
                'amount' => $coupon_amount,
                'type' => 'coupon'
            );
            
            $coupon_discounts[] = $discount_data;
            $all_discounts[] = $discount_data;
            $total_discounts += $coupon_amount;
        }
    }
    
    // Extract line item discounts
    foreach ($order->get_items() as $item_id => $item) {
        $line_subtotal = floatval($item->get_subtotal());
        $line_total = floatval($item->get_total());
        $line_discount = $line_subtotal - $line_total;
        
        if ($line_discount > 0) {
            $discount_data = array(
                'name' => 'Line Item Discount',
                'amount' => $line_discount,
                'type' => 'line_item'
            );
            
            $all_discounts[] = $discount_data;
            $total_discounts += $line_discount;
        }
    }
    
    // Store discount data
    if ($total_discounts > 0) {
        update_post_meta($order_id, '_intersoccer_total_discounts', $total_discounts);
        update_post_meta($order_id, '_intersoccer_combo_discounts', $combo_discounts);
        update_post_meta($order_id, '_intersoccer_coupon_discounts', $coupon_discounts);
        update_post_meta($order_id, '_intersoccer_all_discounts', $all_discounts);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Migration: Successfully migrated order discounts');
        }
    } else {
        // Store zero discount data to mark as processed
        update_post_meta($order_id, '_intersoccer_total_discounts', 0);
        update_post_meta($order_id, '_intersoccer_all_discounts', array());
    }
}

error_log('InterSoccer: Clean discount reporting system loaded');
?>