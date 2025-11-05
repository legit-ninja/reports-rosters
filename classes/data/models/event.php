<?php
/**
 * Event Model
 * 
 * Data model for event/product records in InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Data\Models
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Data\Models;

defined('ABSPATH') or die('Restricted access');

class Event {
    
    public $id;
    public $product_id;
    public $name;
    public $description;
    public $type;
    public $category;
    public $start_date;
    public $end_date;
    public $start_time;
    public $end_time;
    public $venue;
    public $venue_address;
    public $capacity;
    public $price;
    public $age_min;
    public $age_max;
    public $gender_restriction;
    public $skill_level;
    public $instructor;
    public $equipment_included;
    public $requirements;
    public $status;
    public $created_at;
    public $updated_at;
    
    /**
     * Constructor
     * 
     * @param array|WC_Product $data Event data or WooCommerce product object
     */
    public function __construct($data = []) {
        if ($data instanceof \WC_Product) {
            $this->load_from_wc_product($data);
        } else {
            foreach ($data as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }
    
    /**
     * Load data from WooCommerce product object
     * 
     * @param WC_Product $product
     */
    private function load_from_wc_product($product) {
        $this->id = $product->get_id();
        $this->product_id = $product->get_id();
        $this->name = $product->get_name();
        $this->description = $product->get_description();
        $this->price = $product->get_price();
        $this->status = $product->get_status();
        
        // Get meta data
        $this->type = $product->get_meta('event_type', true) ?: 'other';
        $this->category = $product->get_meta('event_category', true);
        $this->start_date = $product->get_meta('start_date', true);
        $this->end_date = $product->get_meta('end_date', true);
        $this->start_time = $product->get_meta('start_time', true);
        $this->end_time = $product->get_meta('end_time', true);
        $this->venue = $product->get_meta('venue', true);
        $this->venue_address = $product->get_meta('venue_address', true);
        $this->capacity = $product->get_meta('capacity', true);
        $this->age_min = $product->get_meta('age_min', true);
        $this->age_max = $product->get_meta('age_max', true);
        $this->gender_restriction = $product->get_meta('gender_restriction', true);
        $this->skill_level = $product->get_meta('skill_level', true);
        $this->instructor = $product->get_meta('instructor', true);
        $this->equipment_included = $product->get_meta('equipment_included', true);
        $this->requirements = $product->get_meta('requirements', true);
        
        // Get categories
        $terms = wp_get_post_terms($product->get_id(), 'product_cat');
        if (!empty($terms) && !is_wp_error($terms)) {
            $this->category = $terms[0]->name;
        }
    }
    
    /**
     * Get formatted date range
     * 
     * @return string
     */
    public function get_date_range() {
        if (!$this->start_date) {
            return 'N/A';
        }
        
        $start = date('M j, Y', strtotime($this->start_date));
        
        if (!$this->end_date || $this->start_date === $this->end_date) {
            return $start;
        }
        
        $end = date('M j, Y', strtotime($this->end_date));
        return $start . ' - ' . $end;
    }
    
    /**
     * Get formatted time range
     * 
     * @return string
     */
    public function get_time_range() {
        if (!$this->start_time) {
            return 'N/A';
        }
        
        $start = date('g:i A', strtotime($this->start_time));
        
        if (!$this->end_time) {
            return $start;
        }
        
        $end = date('g:i A', strtotime($this->end_time));
        return $start . ' - ' . $end;
    }
    
    /**
     * Get formatted price
     * 
     * @return string
     */
    public function get_formatted_price() {
        if (!$this->price) {
            return 'Free';
        }
        
        return number_format($this->price, 2) . ' CHF';
    }
    
    /**
     * Get age range
     * 
     * @return string
     */
    public function get_age_range() {
        if (!$this->age_min && !$this->age_max) {
            return 'All ages';
        }
        
        if ($this->age_min && $this->age_max) {
            return $this->age_min . '-' . $this->age_max . ' years';
        }
        
        if ($this->age_min) {
            return $this->age_min . '+ years';
        }
        
        return 'Up to ' . $this->age_max . ' years';
    }
    
    /**
     * Check if event is a camp
     * 
     * @return bool
     */
    public function is_camp() {
        return strtolower($this->type) === 'camp' || 
               strpos(strtolower($this->name), 'camp') !== false ||
               strpos(strtolower($this->category), 'camp') !== false;
    }
    
    /**
     * Check if event is a course
     * 
     * @return bool
     */
    public function is_course() {
        return strtolower($this->type) === 'course' || 
               strpos(strtolower($this->name), 'course') !== false ||
               strpos(strtolower($this->name), 'training') !== false;
    }
    
    /**
     * Check if event is girls only
     * 
     * @return bool
     */
    public function is_girls_only() {
        return strtolower($this->gender_restriction) === 'female' ||
               strpos(strtolower($this->name), 'girls') !== false;
    }
    
    /**
     * Check if event is active
     * 
     * @return bool
     */
    public function is_active() {
        return $this->status === 'publish';
    }
    
    /**
     * Check if event is full
     * 
     * @param int $current_registrations
     * @return bool
     */
    public function is_full($current_registrations = 0) {
        return $this->capacity > 0 && $current_registrations >= $this->capacity;
    }
    
    /**
     * Get available spots
     * 
     * @param int $current_registrations
     * @return int|string
     */
    public function get_available_spots($current_registrations = 0) {
        if (!$this->capacity) {
            return 'Unlimited';
        }
        
        $available = $this->capacity - $current_registrations;
        return max(0, $available);
    }
    
    /**
     * Convert to array
     * 
     * @return array
     */
    public function to_array() {
        return get_object_vars($this);
    }
}