<?php
/**
 * Product Variation Handler
 * 
 * Handles WooCommerce product variations for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\WooCommerce
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\WooCommerce;

defined('ABSPATH') or die('Restricted access');

class ProductVariationHandler {
    
    /**
     * Get variation data for roster processing
     * 
     * @param WC_Product_Variation $variation
     * @return array
     */
    public function get_variation_data($variation) {
        if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
            return [];
        }
        
        $data = [];
        
        // Get variation attributes
        $attributes = $variation->get_variation_attributes();
        
        // Map common variation attributes
        foreach ($attributes as $attribute_name => $attribute_value) {
            $clean_name = str_replace('attribute_', '', $attribute_name);
            $clean_name = str_replace('pa_', '', $clean_name);
            
            switch ($clean_name) {
                case 'age-group':
                case 'age_group':
                    $data['age_group'] = $attribute_value;
                    break;
                    
                case 'gender':
                    $data['gender'] = $attribute_value;
                    break;
                    
                case 'skill-level':
                case 'skill_level':
                    $data['skill_level'] = $attribute_value;
                    break;
                    
                case 'venue':
                    $data['venue'] = $attribute_value;
                    break;
                    
                case 'time-slot':
                case 'time_slot':
                    $data['time_slot'] = $attribute_value;
                    $this->parse_time_slot($attribute_value, $data);
                    break;
                    
                case 'date':
                    $data['date'] = $attribute_value;
                    break;
                    
                default:
                    $data[$clean_name] = $attribute_value;
                    break;
            }
        }
        
        // Get variation meta data
        $meta_data = [
            'start_date' => $variation->get_meta('start_date', true),
            'end_date' => $variation->get_meta('end_date', true),
            'start_time' => $variation->get_meta('start_time', true),
            'end_time' => $variation->get_meta('end_time', true),
            'venue' => $variation->get_meta('venue', true),
            'venue_address' => $variation->get_meta('venue_address', true),
            'capacity' => $variation->get_meta('capacity', true),
            'instructor' => $variation->get_meta('instructor', true),
            'equipment_included' => $variation->get_meta('equipment_included', true),
            'requirements' => $variation->get_meta('requirements', true)
        ];
        
        // Merge meta data, giving priority to variation meta over attributes
        $data = array_merge($data, array_filter($meta_data));
        
        return $data;
    }
    
    /**
     * Parse time slot string into start/end times
     * 
     * @param string $time_slot
     * @param array &$data
     */
    private function parse_time_slot($time_slot, &$data) {
        if (empty($time_slot)) {
            return;
        }
        
        // Common time slot patterns
        $patterns = [
            '/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/',  // 9:00 - 12:00
            '/(\d{1,2})h(\d{2})\s*-\s*(\d{1,2})h(\d{2})/',  // 9h00 - 12h00
            '/(\d{1,2})\.(\d{2})\s*-\s*(\d{1,2})\.(\d{2})/', // 9.00 - 12.00
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $time_slot, $matches)) {
                $start_hour = intval($matches[1]);
                $start_min = intval($matches[2]);
                $end_hour = intval($matches[3]);
                $end_min = intval($matches[4]);
                
                $data['start_time'] = sprintf('%02d:%02d:00', $start_hour, $start_min);
                $data['end_time'] = sprintf('%02d:%02d:00', $end_hour, $end_min);
                break;
            }
        }
    }
    
    /**
     * Get parent product data
     * 
     * @param WC_Product_Variation $variation
     * @return array
     */
    public function get_parent_product_data($variation) {
        if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
            return [];
        }
        
        $parent_id = $variation->get_parent_id();
        $parent_product = wc_get_product($parent_id);
        
        if (!$parent_product) {
            return [];
        }
        
        return [
            'parent_id' => $parent_id,
            'parent_name' => $parent_product->get_name(),
            'parent_description' => $parent_product->get_description(),
            'parent_short_description' => $parent_product->get_short_description(),
            'parent_categories' => $this->get_product_categories($parent_product),
            'parent_tags' => $this->get_product_tags($parent_product)
        ];
    }
    
    /**
     * Get product categories
     * 
     * @param WC_Product $product
     * @return array
     */
    private function get_product_categories($product) {
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        
        if (is_wp_error($categories) || empty($categories)) {
            return [];
        }
        
        return array_map(function($category) {
            return [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug
            ];
        }, $categories);
    }
    
    /**
     * Get product tags
     * 
     * @param WC_Product $product
     * @return array
     */
    private function get_product_tags($product) {
        $tags = wp_get_post_terms($product->get_id(), 'product_tag');
        
        if (is_wp_error($tags) || empty($tags)) {
            return [];
        }
        
        return array_map(function($tag) {
            return [
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug
            ];
        }, $tags);
    }
    
    /**
     * Determine event type from variation
     * 
     * @param WC_Product_Variation $variation
     * @param array $variation_data
     * @return string
     */
    public function determine_event_type($variation, $variation_data = []) {
        // Check meta first
        $meta_type = $variation->get_meta('event_type', true);
        if ($meta_type) {
            return $meta_type;
        }
        
        // Check parent product meta
        $parent_id = $variation->get_parent_id();
        if ($parent_id) {
            $parent_product = wc_get_product($parent_id);
            if ($parent_product) {
                $parent_meta_type = $parent_product->get_meta('event_type', true);
                if ($parent_meta_type) {
                    return $parent_meta_type;
                }
            }
        }
        
        // Check variation name and attributes
        $variation_name = strtolower($variation->get_name());
        
        if (strpos($variation_name, 'camp') !== false || strpos($variation_name, 'summer') !== false) {
            return 'camp';
        }
        
        if (strpos($variation_name, 'course') !== false || strpos($variation_name, 'training') !== false) {
            return 'course';
        }
        
        if (strpos($variation_name, 'girls') !== false || 
            (isset($variation_data['gender']) && strtolower($variation_data['gender']) === 'female')) {
            return 'girls_only';
        }
        
        // Check categories
        $categories = $this->get_product_categories($variation);
        foreach ($categories as $category) {
            $cat_name = strtolower($category['name']);
            if (strpos($cat_name, 'camp') !== false) return 'camp';
            if (strpos($cat_name, 'course') !== false) return 'course';
            if (strpos($cat_name, 'girls') !== false) return 'girls_only';
        }
        
        return 'other';
    }
    
    /**
     * Get variation pricing information
     * 
     * @param WC_Product_Variation $variation
     * @return array
     */
    public function get_variation_pricing($variation) {
        if (!$variation || !is_a($variation, 'WC_Product_Variation')) {
            return [];
        }
        
        return [
            'price' => $variation->get_price(),
            'regular_price' => $variation->get_regular_price(),
            'sale_price' => $variation->get_sale_price(),
            'price_html' => $variation->get_price_html(),
            'is_on_sale' => $variation->is_on_sale(),
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => get_woocommerce_currency_symbol()
        ];
    }
    
    /**
     * Check if variation has sibling discount
     * 
     * @param WC_Product_Variation $variation
     * @param WC_Order $order
     * @return array
     */
    public function check_sibling_discount($variation, $order) {
        $discount_info = [
            'has_discount' => false,
            'discount_amount' => 0,
            'discount_type' => 'none',
            'reason' => ''
        ];
        
        // Check for sibling discount meta
        $sibling_discount = $variation->get_meta('sibling_discount', true);
        if ($sibling_discount) {
            $discount_info['has_discount'] = true;
            $discount_info['discount_type'] = 'sibling';
            $discount_info['reason'] = 'Sibling discount applied';
            
            if (is_numeric($sibling_discount)) {
                $discount_info['discount_amount'] = floatval($sibling_discount);
            }
        }
        
        // Check order for multiple items (potential siblings)
        $items = $order->get_items();
        if (count($items) > 1) {
            // Logic to detect sibling registrations could be added here
            // For now, just flag if multiple items exist
            $discount_info['multiple_items'] = true;
        }
        
        return $discount_info;
    }
    
    /**
     * Get all variation data combined
     * 
     * @param WC_Product_Variation $variation
     * @param WC_Order $order
     * @return array
     */
    public function get_complete_variation_data($variation, $order = null) {
        $data = [];
        
        // Basic variation data
        $data['variation'] = $this->get_variation_data($variation);
        
        // Parent product data
        $data['parent'] = $this->get_parent_product_data($variation);
        
        // Event type
        $data['event_type'] = $this->determine_event_type($variation, $data['variation']);
        
        // Pricing
        $data['pricing'] = $this->get_variation_pricing($variation);
        
        // Sibling discount (if order provided)
        if ($order) {
            $data['discount'] = $this->check_sibling_discount($variation, $order);
        }
        
        return $data;
    }
    
    /**
     * Extract activity type from variation
     * 
     * @param \WC_Product_Variation $variation Product variation
     * @return string Activity type (Camp, Course, Birthday Party, etc.)
     */
    public function extractActivityType($variation) {
        if (!$variation) {
            return '';
        }
        
        // Try to get from attribute
        $activity_type = $variation->get_attribute('pa_activity-type');
        
        if ($activity_type) {
            return $activity_type;
        }
        
        // Try from variation meta
        $activity_type = $variation->get_meta('activity_type', true);
        
        if ($activity_type) {
            return $activity_type;
        }
        
        // Try from variation data
        $variation_data = $this->get_variation_data($variation);
        
        return $variation_data['activity_type'] ?? '';
    }
    
    /**
     * Extract venue from variation
     * 
     * @param \WC_Product_Variation $variation Product variation
     * @return string Venue name
     */
    public function extractVenue($variation) {
        if (!$variation) {
            return '';
        }
        
        // Try to get from attribute
        $venue = $variation->get_attribute('pa_venue');
        
        if ($venue) {
            return $venue;
        }
        
        // Try from variation meta
        $venue = $variation->get_meta('venue', true);
        
        if ($venue) {
            return $venue;
        }
        
        // Try from variation data
        $variation_data = $this->get_variation_data($variation);
        
        return $variation_data['venue'] ?? '';
    }
}