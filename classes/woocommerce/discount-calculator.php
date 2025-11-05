<?php
/**
 * Discount Calculator
 * 
 * Calculates sibling and other discounts for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccer\ReportsRosters\WooCommerce
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\WooCommerce;

defined('ABSPATH') or die('Restricted access');

class DiscountCalculator {
    
    /**
     * Default sibling discount percentage
     * @var float
     */
    private $default_sibling_discount = 10.0;
    
    /**
     * Calculate sibling discounts for an order
     * 
     * @param WC_Order $order
     * @return array
     */
    public function calculate_sibling_discounts($order) {
        $items = $order->get_items();
        $discount_info = [];
        
        // Group items by family (same email/parent)
        $family_groups = $this->group_items_by_family($order, $items);
        
        foreach ($family_groups as $family_email => $family_items) {
            if (count($family_items) > 1) {
                // Multiple children in same family - apply sibling discount
                $discount_info[$family_email] = $this->apply_sibling_discount($family_items, $order);
            } else {
                // Single child - no sibling discount
                $item = $family_items[0];
                $discount_info[$family_email] = [
                    'items' => [$item->get_id()],
                    'sibling_count' => 1,
                    'discount_applied' => false,
                    'discount_amount' => 0,
                    'discount_type' => 'none'
                ];
            }
        }
        
        return $discount_info;
    }
    
    /**
     * Group order items by family
     * 
     * @param WC_Order $order
     * @param array $items
     * @return array
     */
    private function group_items_by_family($order, $items) {
        $family_groups = [];
        $default_email = $order->get_billing_email();
        
        foreach ($items as $item_id => $item) {
            // Try to get parent email from item meta
            $parent_email = $item->get_meta('parent_email', true) ?: 
                          $item->get_meta('guardian_email', true) ?: 
                          $default_email;
            
            $parent_email = strtolower(trim($parent_email));
            
            if (!isset($family_groups[$parent_email])) {
                $family_groups[$parent_email] = [];
            }
            
            $family_groups[$parent_email][] = $item;
        }
        
        return $family_groups;
    }
    
    /**
     * Apply sibling discount to family items
     * 
     * @param array $family_items
     * @param WC_Order $order
     * @return array
     */
    private function apply_sibling_discount($family_items, $order) {
        $sibling_count = count($family_items);
        $discount_percentage = $this->get_sibling_discount_percentage($sibling_count);
        
        $total_original = 0;
        $total_discounted = 0;
        $item_ids = [];
        
        foreach ($family_items as $item) {
            $item_ids[] = $item->get_id();
            $item_subtotal = $item->get_subtotal();
            $item_total = $item->get_total();
            
            $total_original += $item_subtotal;
            $total_discounted += $item_total;
        }
        
        $actual_discount = $total_original - $total_discounted;
        $expected_discount = $total_original * ($discount_percentage / 100);
        
        return [
            'items' => $item_ids,
            'sibling_count' => $sibling_count,
            'discount_applied' => $actual_discount > 0,
            'discount_amount' => $actual_discount,
            'discount_percentage' => $discount_percentage,
            'expected_discount' => $expected_discount,
            'discount_type' => 'sibling',
            'original_total' => $total_original,
            'discounted_total' => $total_discounted
        ];
    }
    
    /**
     * Get sibling discount percentage based on number of siblings
     * 
     * @param int $sibling_count
     * @return float
     */
    private function get_sibling_discount_percentage($sibling_count) {
        // Get custom discount rates from options
        $discount_rates = get_option('intersoccer_sibling_discounts', [
            2 => 10.0,  // 10% for 2 siblings
            3 => 15.0,  // 15% for 3 siblings
            4 => 20.0,  // 20% for 4+ siblings
        ]);
        
        if ($sibling_count >= 4) {
            return $discount_rates[4] ?? 20.0;
        }
        
        return $discount_rates[$sibling_count] ?? $this->default_sibling_discount;
    }
    
    /**
     * Calculate early bird discount
     * 
     * @param WC_Product $product
     * @param WC_Order $order
     * @return array
     */
    public function calculate_early_bird_discount($product, $order) {
        $discount_info = [
            'applies' => false,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'cutoff_date' => null,
            'days_early' => 0
        ];
        
        // Get early bird settings from product meta
        $early_bird_enabled = $product->get_meta('early_bird_enabled', true);
        if (!$early_bird_enabled) {
            return $discount_info;
        }
        
        $early_bird_cutoff = $product->get_meta('early_bird_cutoff', true);
        $early_bird_discount = $product->get_meta('early_bird_discount', true);
        $event_start_date = $product->get_meta('start_date', true);
        
        if (!$early_bird_cutoff || !$early_bird_discount || !$event_start_date) {
            return $discount_info;
        }
        
        $order_date = $order->get_date_created();
        $cutoff_date = new \DateTime($early_bird_cutoff);
        $event_date = new \DateTime($event_start_date);
        
        // Check if order was placed before cutoff
        if ($order_date <= $cutoff_date) {
            $days_early = $cutoff_date->diff($order_date)->days;
            
            $discount_info = [
                'applies' => true,
                'discount_amount' => floatval($early_bird_discount),
                'discount_percentage' => $this->is_percentage_discount($early_bird_discount) ? floatval($early_bird_discount) : 0,
                'cutoff_date' => $early_bird_cutoff,
                'days_early' => $days_early,
                'event_date' => $event_start_date
            ];
        }
        
        return $discount_info;
    }
    
    /**
     * Calculate loyalty discount for returning customers
     * 
     * @param WC_Order $order
     * @return array
     */
    public function calculate_loyalty_discount($order) {
        $customer_id = $order->get_customer_id();
        $customer_email = $order->get_billing_email();
        
        $discount_info = [
            'applies' => false,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'previous_orders' => 0,
            'customer_type' => 'new'
        ];
        
        if (!$customer_id && !$customer_email) {
            return $discount_info;
        }
        
        // Count previous orders
        $previous_orders = $this->count_previous_orders($customer_id, $customer_email, $order->get_id());
        
        if ($previous_orders > 0) {
            $loyalty_discount = $this->get_loyalty_discount_rate($previous_orders);
            
            if ($loyalty_discount > 0) {
                $discount_info = [
                    'applies' => true,
                    'discount_percentage' => $loyalty_discount,
                    'previous_orders' => $previous_orders,
                    'customer_type' => $this->get_customer_type($previous_orders)
                ];
            }
        }
        
        return $discount_info;
    }
    
    /**
     * Count previous orders for customer
     * 
     * @param int $customer_id
     * @param string $customer_email
     * @param int $current_order_id
     * @return int
     */
    private function count_previous_orders($customer_id, $customer_email, $current_order_id) {
        $args = [
            'status' => ['completed', 'processing'],
            'limit' => -1,
            'exclude' => [$current_order_id],
            'return' => 'ids'
        ];
        
        if ($customer_id) {
            $args['customer_id'] = $customer_id;
        } else {
            $args['billing_email'] = $customer_email;
        }
        
        $orders = wc_get_orders($args);
        return count($orders);
    }
    
    /**
     * Get loyalty discount rate based on order history
     * 
     * @param int $previous_orders
     * @return float
     */
    private function get_loyalty_discount_rate($previous_orders) {
        $loyalty_rates = get_option('intersoccer_loyalty_discounts', [
            1 => 5.0,   // 5% for 1 previous order
            3 => 7.5,   // 7.5% for 3+ previous orders
            5 => 10.0,  // 10% for 5+ previous orders
            10 => 15.0  // 15% for 10+ previous orders
        ]);
        
        foreach (array_reverse($loyalty_rates, true) as $threshold => $discount) {
            if ($previous_orders >= $threshold) {
                return $discount;
            }
        }
        
        return 0;
    }
    
    /**
     * Get customer type based on order history
     * 
     * @param int $previous_orders
     * @return string
     */
    private function get_customer_type($previous_orders) {
        if ($previous_orders == 0) return 'new';
        if ($previous_orders <= 2) return 'returning';
        if ($previous_orders <= 5) return 'regular';
        return 'loyal';
    }
    
    /**
     * Calculate group/bulk discount
     * 
     * @param WC_Order $order
     * @return array
     */
    public function calculate_group_discount($order) {
        $items = $order->get_items();
        $total_quantity = 0;
        
        foreach ($items as $item) {
            $total_quantity += $item->get_quantity();
        }
        
        $discount_info = [
            'applies' => false,
            'discount_percentage' => 0,
            'total_quantity' => $total_quantity,
            'threshold_met' => false
        ];
        
        // Get group discount thresholds
        $group_thresholds = get_option('intersoccer_group_discounts', [
            5 => 5.0,   // 5% for 5+ registrations
            10 => 10.0, // 10% for 10+ registrations
            15 => 15.0  // 15% for 15+ registrations
        ]);
        
        foreach (array_reverse($group_thresholds, true) as $threshold => $discount) {
            if ($total_quantity >= $threshold) {
                $discount_info = [
                    'applies' => true,
                    'discount_percentage' => $discount,
                    'total_quantity' => $total_quantity,
                    'threshold_met' => true,
                    'threshold' => $threshold
                ];
                break;
            }
        }
        
        return $discount_info;
    }
    
    /**
     * Get comprehensive discount analysis for an order
     * 
     * @param WC_Order $order
     * @return array
     */
    public function analyze_order_discounts($order) {
        $analysis = [
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total(),
            'subtotal' => $order->get_subtotal(),
            'total_discount' => $order->get_total_discount(),
            'coupon_discount' => $order->get_discount_total(),
            'discounts' => []
        ];
        
        // Calculate different types of discounts
        $analysis['discounts']['sibling'] = $this->calculate_sibling_discounts($order);
        $analysis['discounts']['loyalty'] = $this->calculate_loyalty_discount($order);
        $analysis['discounts']['group'] = $this->calculate_group_discount($order);
        
        // Calculate early bird for each product
        $early_bird_discounts = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $early_bird = $this->calculate_early_bird_discount($product, $order);
                if ($early_bird['applies']) {
                    $early_bird_discounts[] = $early_bird;
                }
            }
        }
        $analysis['discounts']['early_bird'] = $early_bird_discounts;
        
        // Calculate total automatic discounts
        $total_automatic_discount = 0;
        foreach ($analysis['discounts'] as $type => $discount_data) {
            if ($type === 'sibling') {
                foreach ($discount_data as $family_discount) {
                    if ($family_discount['discount_applied']) {
                        $total_automatic_discount += $family_discount['discount_amount'];
                    }
                }
            } elseif (isset($discount_data['applies']) && $discount_data['applies']) {
                // For other discount types
                if (isset($discount_data['discount_amount'])) {
                    $total_automatic_discount += $discount_data['discount_amount'];
                }
            }
        }
        
        $analysis['total_automatic_discount'] = $total_automatic_discount;
        $analysis['coupon_codes'] = $order->get_coupon_codes();
        
        return $analysis;
    }
    
    /**
     * Check if discount value is a percentage
     * 
     * @param mixed $discount
     * @return bool
     */
    private function is_percentage_discount($discount) {
        return is_string($discount) && strpos($discount, '%') !== false;
    }
    
    /**
     * Format discount for display
     * 
     * @param float $amount
     * @param string $type
     * @return string
     */
    public function format_discount($amount, $type = 'fixed') {
        if ($type === 'percentage') {
            return number_format($amount, 1) . '%';
        }
        
        return wc_price($amount);
    }
    
    /**
     * Get discount summary for reporting
     * 
     * @param array $discount_analysis
     * @return array
     */
    public function get_discount_summary($discount_analysis) {
        $summary = [
            'total_discount' => $discount_analysis['total_discount'],
            'automatic_discount' => $discount_analysis['total_automatic_discount'],
            'coupon_discount' => $discount_analysis['coupon_discount'],
            'discount_types' => []
        ];
        
        // Identify which discount types were applied
        foreach ($discount_analysis['discounts'] as $type => $discount_data) {
            switch ($type) {
                case 'sibling':
                    $sibling_families = 0;
                    $sibling_amount = 0;
                    foreach ($discount_data as $family_discount) {
                        if ($family_discount['discount_applied']) {
                            $sibling_families++;
                            $sibling_amount += $family_discount['discount_amount'];
                        }
                    }
                    if ($sibling_families > 0) {
                        $summary['discount_types']['sibling'] = [
                            'families' => $sibling_families,
                            'amount' => $sibling_amount
                        ];
                    }
                    break;
                    
                case 'loyalty':
                    if ($discount_data['applies']) {
                        $summary['discount_types']['loyalty'] = [
                            'percentage' => $discount_data['discount_percentage'],
                            'customer_type' => $discount_data['customer_type']
                        ];
                    }
                    break;
                    
                case 'group':
                    if ($discount_data['applies']) {
                        $summary['discount_types']['group'] = [
                            'percentage' => $discount_data['discount_percentage'],
                            'quantity' => $discount_data['total_quantity']
                        ];
                    }
                    break;
                    
                case 'early_bird':
                    if (!empty($discount_data)) {
                        $summary['discount_types']['early_bird'] = count($discount_data);
                    }
                    break;
            }
        }
        
        return $summary;
    }
    
    /**
     * Calculate cart-level discount
     * 
     * @param \WC_Cart $cart WooCommerce cart object
     * @return float Total discount amount
     */
    public function calculateCartDiscount($cart) {
        $total_discount = 0.0;
        
        if (!$cart || !method_exists($cart, 'get_cart')) {
            return $total_discount;
        }
        
        $cart_items = $cart->get_cart();
        
        // Group items by activity type
        $camps = [];
        $courses = [];
        
        foreach ($cart_items as $cart_item_key => $cart_item) {
            $activity_type = $cart_item['variation']['attribute_pa_activity-type'] ?? '';
            
            if ($activity_type === 'Camp') {
                $camps[] = $cart_item;
            } elseif ($activity_type === 'Course') {
                $courses[] = $cart_item;
            }
        }
        
        // Apply camp discounts
        if (count($camps) > 1) {
            foreach ($camps as $index => $item) {
                if ($index > 0) { // Skip first child
                    $discount_rate = ($index === 1) ? 0.20 : 0.25; // 20% for 2nd, 25% for 3rd+
                    $total_discount += ($item['line_total'] ?? 0) * $discount_rate;
                }
            }
        }
        
        // Apply course discounts
        if (count($courses) > 1) {
            foreach ($courses as $index => $item) {
                if ($index > 0) { // Skip first child
                    $discount_rate = ($index === 1) ? 0.20 : 0.30; // 20% for 2nd, 30% for 3rd+
                    $total_discount += ($item['line_total'] ?? 0) * $discount_rate;
                }
            }
        }
        
        return $total_discount;
    }
    
    /**
     * Apply camp discount for Nth child
     * 
     * @param float $price Original price
     * @param int $child_number Child number (1, 2, 3+)
     * @return float Discounted price
     */
    public function applyCampDiscount($price, $child_number) {
        if ($child_number <= 1) {
            return $price; // No discount for first child
        }
        
        // 20% off for 2nd child, 25% off for 3rd+ children
        $discount_rate = ($child_number === 2) ? 0.20 : 0.25;
        
        return $price * (1 - $discount_rate);
    }
    
    /**
     * Apply course discount for Nth child
     * 
     * @param float $price Original price
     * @param int $child_number Child number (1, 2, 3+)
     * @return float Discounted price
     */
    public function applyCourseDiscount($price, $child_number) {
        if ($child_number <= 1) {
            return $price; // No discount for first child
        }
        
        // 20% off for 2nd child, 30% off for 3rd+ children
        $discount_rate = ($child_number === 2) ? 0.20 : 0.30;
        
        return $price * (1 - $discount_rate);
    }
}