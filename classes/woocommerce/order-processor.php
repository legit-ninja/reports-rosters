<?php
/**
 * Order Processor
 * 
 * Processes WooCommerce orders for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\WooCommerce
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\WooCommerce;

use InterSoccerReportsRosters\Data\Repositories\RosterRepository;

defined('ABSPATH') or die('Restricted access');

class OrderProcessor {
    
    /**
     * Roster repository
     * @var RosterRepository
     */
    private $roster_repository;
    
    /**
     * Processed orders cache
     * @var array
     */
    private $processed_orders = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->roster_repository = new RosterRepository();
    }
    
    /**
     * Process a single WooCommerce order
     * 
     * @param WC_Order|int $order
     * @return array|false
     */
    public function process_order($order) {
        // Get WC_Order object
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        
        if (!$order || !is_a($order, 'WC_Order')) {
            error_log('InterSoccer: Invalid order object provided to process_order');
            return false;
        }
        
        $order_id = $order->get_id();
        
        // Check if already processed
        if (isset($this->processed_orders[$order_id])) {
            return $this->processed_orders[$order_id];
        }
        
        error_log("InterSoccer: Processing order {$order_id}");
        
        $roster_entries = [];
        
        // Get order items
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $quantity = $item->get_quantity();
            
            // Get product
            $product = wc_get_product($variation_id ?: $product_id);
            if (!$product) {
                error_log("InterSoccer: Product not found for item {$item_id} in order {$order_id}");
                continue;
            }
            
            // Extract player information from order meta
            $players = $this->extract_player_data($order, $item, $quantity);
            
            foreach ($players as $player_index => $player_data) {
                $roster_data = $this->build_roster_data($order, $item, $product, $player_data, $player_index);
                
                if ($roster_data) {
                    $roster_entries[] = $roster_data;
                }
            }
        }
        
        // Cache the result
        $this->processed_orders[$order_id] = $roster_entries;
        
        error_log("InterSoccer: Processed order {$order_id} - generated " . count($roster_entries) . " roster entries");
        
        return $roster_entries;
    }
    
    /**
     * Extract player data from order and item
     * 
     * @param WC_Order $order
     * @param WC_Order_Item $item
     * @param int $quantity
     * @return array
     */
    private function extract_player_data($order, $item, $quantity) {
        $players = [];
        
        // Get meta data from item
        $meta_data = $item->get_meta_data();
        $item_meta = [];
        
        foreach ($meta_data as $meta) {
            $item_meta[$meta->key] = $meta->value;
        }
        
        // Extract player information for each quantity
        for ($i = 0; $i < $quantity; $i++) {
            $player_data = [];
            
            // Try different meta key patterns
            $player_prefix = $quantity > 1 ? "player_{$i}_" : 'player_';
            
            // Player basic info
            $player_data['first_name'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'first_name',
                'first_name',
                'player_first_name'
            ]);
            
            $player_data['last_name'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'last_name',
                'last_name',
                'player_last_name'
            ]);
            
            $player_data['full_name'] = trim($player_data['first_name'] . ' ' . $player_data['last_name']) ?: 
                                       $this->get_meta_value($item_meta, ['player_name', 'name']);
            
            $player_data['email'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'email',
                'player_email'
            ]) ?: $order->get_billing_email();
            
            $player_data['phone'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'phone',
                'player_phone'
            ]) ?: $order->get_billing_phone();
            
            $player_data['age'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'age',
                'player_age',
                'age'
            ]);
            
            $player_data['birth_date'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'birth_date',
                'player_birth_date',
                'birth_date'
            ]);
            
            $player_data['gender'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'gender',
                'player_gender',
                'gender'
            ]);
            
            // Parent/Guardian info
            $player_data['parent_name'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'parent_name',
                'parent_name',
                'guardian_name'
            ]) ?: ($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            
            $player_data['parent_email'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'parent_email',
                'parent_email',
                'guardian_email'
            ]) ?: $order->get_billing_email();
            
            $player_data['parent_phone'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'parent_phone',
                'parent_phone',
                'guardian_phone'
            ]) ?: $order->get_billing_phone();
            
            // Additional info
            $player_data['emergency_contact'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'emergency_contact',
                'emergency_contact'
            ]);
            
            $player_data['emergency_phone'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'emergency_phone',
                'emergency_phone'
            ]);
            
            $player_data['medical_info'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'medical_info',
                'medical_info',
                'medical_conditions'
            ]);
            
            $player_data['dietary_requirements'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'dietary_requirements',
                'dietary_requirements',
                'dietary_needs'
            ]);
            
            $player_data['jersey_size'] = $this->get_meta_value($item_meta, [
                $player_prefix . 'jersey_size',
                'jersey_size',
                'shirt_size'
            ]);
            
            $players[] = $player_data;
        }
        
        return $players;
    }
    
    /**
     * Get meta value by trying multiple keys
     * 
     * @param array $meta_data
     * @param array $keys
     * @return string|null
     */
    private function get_meta_value($meta_data, $keys) {
        foreach ($keys as $key) {
            if (isset($meta_data[$key]) && !empty($meta_data[$key])) {
                return $meta_data[$key];
            }
        }
        return null;
    }
    
    /**
     * Build roster data array
     * 
     * @param WC_Order $order
     * @param WC_Order_Item $item
     * @param WC_Product $product
     * @param array $player_data
     * @param int $player_index
     * @return array|null
     */
    private function build_roster_data($order, $item, $product, $player_data, $player_index) {
        // Extract event information from product
        $event_data = $this->extract_event_data($product);
        
        // Calculate pricing
        $pricing = $this->calculate_item_pricing($order, $item, $player_index);
        
        $roster_data = [
            // Order information
            'order_id' => $order->get_id(),
            'order_date' => $order->get_date_created()->format('Y-m-d H:i:s'),
            
            // Product/Event information
            'product_id' => $product->get_id(),
            'product_name' => $product->get_name(),
            'product_type' => $event_data['type'],
            'event_name' => $event_data['name'],
            'event_description' => $event_data['description'],
            
            // Player information
            'player_name' => $player_data['full_name'],
            'player_first_name' => $player_data['first_name'],
            'player_last_name' => $player_data['last_name'],
            'player_email' => $player_data['email'],
            'player_phone' => $player_data['phone'],
            'player_age' => $player_data['age'],
            'player_birth_date' => $player_data['birth_date'],
            
            // Parent/Guardian information
            'parent_name' => $player_data['parent_name'],
            'parent_email' => $player_data['parent_email'],
            'parent_phone' => $player_data['parent_phone'],
            
            // Emergency and medical information
            'emergency_contact' => $player_data['emergency_contact'],
            'emergency_phone' => $player_data['emergency_phone'],
            'medical_info' => $player_data['medical_info'],
            'dietary_requirements' => $player_data['dietary_requirements'],
            
            // Event details
            'start_date' => $event_data['start_date'],
            'end_date' => $event_data['end_date'],
            'start_time' => $event_data['start_time'],
            'end_time' => $event_data['end_time'],
            'venue' => $event_data['venue'],
            'venue_address' => $event_data['venue_address'],
            
            // Classification
            'age_group' => $this->determine_age_group($player_data['age']),
            'gender' => $this->normalize_gender($player_data['gender']),
            'skill_level' => $event_data['skill_level'],
            'team_assignment' => '',
            'jersey_size' => $player_data['jersey_size'],
            
            // Financial
            'order_total' => $pricing['total'],
            'discount_amount' => $pricing['discount_amount'],
            'discount_type' => $pricing['discount_type'],
            'payment_status' => $order->get_status() === 'completed' ? 'completed' : 'pending',
            'registration_status' => $order->is_paid() ? 'complete' : 'pending',
            
            // Additional
            'notes' => '',
            'metadata' => json_encode([
                'item_id' => $item->get_id(),
                'variation_id' => $item->get_variation_id(),
                'player_index' => $player_index,
                'processed_at' => date('Y-m-d H:i:s')
            ])
        ];
        
        return $roster_data;
    }
    
    /**
     * Extract event data from product
     * 
     * @param WC_Product $product
     * @return array
     */
    private function extract_event_data($product) {
        return [
            'name' => $product->get_name(),
            'description' => $product->get_short_description() ?: $product->get_description(),
            'type' => $this->determine_product_type($product),
            'start_date' => $product->get_meta('start_date', true),
            'end_date' => $product->get_meta('end_date', true),
            'start_time' => $product->get_meta('start_time', true),
            'end_time' => $product->get_meta('end_time', true),
            'venue' => $product->get_meta('venue', true),
            'venue_address' => $product->get_meta('venue_address', true),
            'skill_level' => $product->get_meta('skill_level', true),
            'age_min' => $product->get_meta('age_min', true),
            'age_max' => $product->get_meta('age_max', true),
            'gender_restriction' => $product->get_meta('gender_restriction', true)
        ];
    }
    
    /**
     * Determine product type based on product data
     * 
     * @param WC_Product $product
     * @return string
     */
    private function determine_product_type($product) {
        $name = strtolower($product->get_name());
        $meta_type = $product->get_meta('event_type', true);
        
        if ($meta_type) {
            return $meta_type;
        }
        
        if (strpos($name, 'camp') !== false || strpos($name, 'summer') !== false) {
            return 'camp';
        }
        
        if (strpos($name, 'course') !== false || strpos($name, 'training') !== false) {
            return 'course';
        }
        
        if (strpos($name, 'girls') !== false || strpos($name, 'female') !== false) {
            return 'girls_only';
        }
        
        return 'other';
    }
    
    /**
     * Calculate pricing for individual item
     * 
     * @param WC_Order $order
     * @param WC_Order_Item $item
     * @param int $player_index
     * @return array
     */
    private function calculate_item_pricing($order, $item, $player_index) {
        $item_total = $item->get_total();
        $item_subtotal = $item->get_subtotal();
        $quantity = $item->get_quantity();
        
        // Calculate per-player cost
        $per_player_total = $quantity > 0 ? ($item_total / $quantity) : 0;
        $per_player_subtotal = $quantity > 0 ? ($item_subtotal / $quantity) : 0;
        
        $discount_amount = $per_player_subtotal - $per_player_total;
        $discount_type = 'fixed';
        
        // Check for percentage discount
        if ($per_player_subtotal > 0 && $discount_amount > 0) {
            $discount_percentage = ($discount_amount / $per_player_subtotal) * 100;
            if ($discount_percentage > 0) {
                $discount_type = 'percentage';
                $discount_amount = round($discount_percentage, 1);
            }
        }
        
        return [
            'total' => $per_player_total,
            'subtotal' => $per_player_subtotal,
            'discount_amount' => $discount_amount,
            'discount_type' => $discount_type
        ];
    }
    
    /**
     * Determine age group classification
     * 
     * @param int|string $age
     * @return string
     */
    private function determine_age_group($age) {
        if (!$age || !is_numeric($age)) {
            return 'N/A';
        }
        
        $age = intval($age);
        
        if ($age <= 6) return 'U7';
        if ($age <= 8) return 'U9';
        if ($age <= 10) return 'U11';
        if ($age <= 12) return 'U13';
        if ($age <= 14) return 'U15';
        if ($age <= 16) return 'U17';
        if ($age <= 18) return 'U19';
        
        return 'Adult';
    }
    
    /**
     * Normalize gender value
     * 
     * @param string $gender
     * @return string
     */
    private function normalize_gender($gender) {
        if (!$gender) {
            return 'N/A';
        }
        
        $gender = strtolower(trim($gender));
        
        if (in_array($gender, ['m', 'male', 'boy'])) {
            return 'male';
        }
        
        if (in_array($gender, ['f', 'female', 'girl'])) {
            return 'female';
        }
        
        return 'other';
    }
    
    /**
     * Process multiple orders
     * 
     * @param array $order_ids
     * @return array
     */
    public function process_orders($order_ids) {
        $all_roster_entries = [];
        $processed_count = 0;
        $error_count = 0;
        
        foreach ($order_ids as $order_id) {
            $entries = $this->process_order($order_id);
            
            if ($entries === false) {
                $error_count++;
                continue;
            }
            
            $all_roster_entries = array_merge($all_roster_entries, $entries);
            $processed_count++;
        }
        
        error_log("InterSoccer: Batch processing completed - {$processed_count} orders processed, {$error_count} errors, " . count($all_roster_entries) . " roster entries generated");
        
        return [
            'roster_entries' => $all_roster_entries,
            'processed_orders' => $processed_count,
            'error_count' => $error_count,
            'total_entries' => count($all_roster_entries)
        ];
    }
    
    /**
     * Save roster entries to database
     * 
     * @param array $roster_entries
     * @return int Number of entries saved
     */
    public function save_roster_entries($roster_entries) {
        $saved_count = 0;
        
        foreach ($roster_entries as $entry) {
            // Check if entry already exists
            $existing = $this->roster_repository->find_first_by([
                'order_id' => $entry['order_id'],
                'product_id' => $entry['product_id'],
                'player_name' => $entry['player_name']
            ]);
            
            if ($existing) {
                // Update existing entry
                if ($this->roster_repository->update($existing->id, $entry)) {
                    $saved_count++;
                }
            } else {
                // Create new entry
                if ($this->roster_repository->create($entry)) {
                    $saved_count++;
                }
            }
        }
        
        return $saved_count;
    }
    
    /**
     * Get order processing statistics
     * 
     * @return array
     */
    public function get_processing_stats() {
        return [
            'processed_orders_count' => count($this->processed_orders),
            'total_roster_entries' => array_sum(array_map('count', $this->processed_orders)),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
    
    /**
     * Clear processed orders cache
     */
    public function clear_cache() {
        $this->processed_orders = [];
    }
}