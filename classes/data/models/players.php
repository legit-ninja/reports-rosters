<?php
/**
 * Player Model
 * 
 * Data model for player records in InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Data\Models
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\Data\Models;

defined('ABSPATH') or die('Restricted access');

class Player {
    
    public $id;
    public $first_name;
    public $last_name;
    public $email;
    public $phone;
    public $birth_date;
    public $age;
    public $gender;
    public $parent_name;
    public $parent_email;
    public $parent_phone;
    public $emergency_contact;
    public $emergency_phone;
    public $medical_info;
    public $dietary_requirements;
    public $jersey_size;
    public $skill_level;
    public $notes;
    public $created_at;
    public $updated_at;
    
    /**
     * Constructor
     * 
     * @param array $data Player data
     */
    public function __construct($data = []) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
    
    /**
     * Get full name
     * 
     * @return string
     */
    public function get_full_name() {
        return trim($this->first_name . ' ' . $this->last_name);
    }
    
    /**
     * Get age classification
     * 
     * @return string
     */
    public function get_age_group() {
        if (!$this->age) return 'N/A';
        
        $age = intval($this->age);
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
     * Convert to array
     * 
     * @return array
     */
    public function to_array() {
        return get_object_vars($this);
    }
}