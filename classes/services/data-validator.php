<?php
/**
 * Data Validator Service
 * 
 * Comprehensive data validation service for InterSoccer Reports & Rosters.
 * Provides multi-layer validation for players, rosters, and order data.
 * 
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data Validator Service
 * 
 * Handles comprehensive validation of all data types in the system
 */
class DataValidator {
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Validation rules cache
     * 
     * @var array
     */
    private $rules_cache = [];
    
    /**
     * Custom validation rules
     * 
     * @var array
     */
    private $custom_rules = [];
    
    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
        $this->init_custom_rules();
    }
    
    /**
     * Initialize custom validation rules
     * 
     * @return void
     */
    private function init_custom_rules() {
        $this->custom_rules = [
            'avs_number' => [$this, 'validate_avs_number'],
            'swiss_phone' => [$this, 'validate_swiss_phone'],
            'age_group_format' => [$this, 'validate_age_group_format'],
            'intersoccer_venue' => [$this, 'validate_intersoccer_venue'],
            'event_date_range' => [$this, 'validate_event_date_range'],
            'player_age_limit' => [$this, 'validate_player_age_limit'],
            'gender_value' => [$this, 'validate_gender_value'],
            'activity_type' => [$this, 'validate_activity_type'],
            'booking_type' => [$this, 'validate_booking_type'],
            'selected_days' => [$this, 'validate_selected_days'],
            'season_format' => [$this, 'validate_season_format']
        ];
    }
    
    /**
     * Validate a field with given rules
     * 
     * @param string $field_name Field name for error reporting
     * @param mixed $value Value to validate
     * @param array|string $rules Validation rules
     * @return array Array of error messages (empty if valid)
     */
    public function validate_field($field_name, $value, $rules) {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }
        
        $errors = [];
        
        foreach ($rules as $rule) {
            $rule_parts = explode(':', $rule, 2);
            $rule_name = $rule_parts[0];
            $rule_params = isset($rule_parts[1]) ? explode(',', $rule_parts[1]) : [];
            
            try {
                $result = $this->apply_validation_rule($field_name, $value, $rule_name, $rule_params);
                if ($result !== true) {
                    $errors[] = $result;
                }
            } catch (\Exception $e) {
                $errors[] = "Validation error for {$field_name}: " . $e->getMessage();
                $this->logger->error('Validation rule failed', [
                    'field' => $field_name,
                    'rule' => $rule_name,
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $errors;
    }
    
    /**
     * Apply a single validation rule
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param string $rule_name Rule name
     * @param array $params Rule parameters
     * @return true|string True if valid, error message if invalid
     */
    private function apply_validation_rule($field_name, $value, $rule_name, array $params = []) {
        // Handle nullable fields
        if ($rule_name === 'nullable' && ($value === null || $value === '')) {
            return true;
        }
        
        // Skip validation for null/empty values unless required
        if (($value === null || $value === '') && $rule_name !== 'required') {
            return true;
        }
        
        switch ($rule_name) {
            case 'required':
                return $this->validate_required($field_name, $value);
                
            case 'string':
                return $this->validate_string($field_name, $value);
                
            case 'integer':
            case 'int':
                return $this->validate_integer($field_name, $value);
                
            case 'numeric':
                return $this->validate_numeric($field_name, $value);
                
            case 'email':
                return $this->validate_email($field_name, $value);
                
            case 'date':
                return $this->validate_date($field_name, $value);
                
            case 'min':
                return $this->validate_min($field_name, $value, $params[0] ?? 0);
                
            case 'max':
                return $this->validate_max($field_name, $value, $params[0] ?? PHP_INT_MAX);
                
            case 'between':
                return $this->validate_between($field_name, $value, $params[0] ?? 0, $params[1] ?? PHP_INT_MAX);
                
            case 'in':
                return $this->validate_in($field_name, $value, $params);
                
            case 'not_in':
                return $this->validate_not_in($field_name, $value, $params);
                
            case 'regex':
                return $this->validate_regex($field_name, $value, $params[0] ?? '');
                
            case 'before':
                return $this->validate_before($field_name, $value, $params[0] ?? 'today');
                
            case 'after':
                return $this->validate_after($field_name, $value, $params[0] ?? 'today');
                
            case 'url':
                return $this->validate_url($field_name, $value);
                
            case 'json':
                return $this->validate_json($field_name, $value);
                
            default:
                // Check custom rules
                if (isset($this->custom_rules[$rule_name])) {
                    return call_user_func($this->custom_rules[$rule_name], $field_name, $value, $params);
                }
                
                throw new \InvalidArgumentException("Unknown validation rule: {$rule_name}");
        }
    }
    
    /**
     * Validate required field
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @return true|string
     */
    private function validate_required($field_name, $value) {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return "{$field_name} is required";
        }
        return true;
    }
    
    /**
     * Validate string field
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @return true|string
     */
    private function validate_string($field_name, $value) {
        if (!is_string($value)) {
            return "{$field_name} must be a string";
        }
        return true;
    }
    
    /**
     * Validate integer field
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @return true|string
     */
    private function validate_integer($field_name, $value) {
        if (!is_int($value) && !ctype_digit((string)$value)) {
            return "{$field_name} must be an integer";
        }
        return true;
    }
    
    /**
     * Validate numeric field
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @return true|string
     */
    private function validate_numeric($field_name, $value) {
        if (!is_numeric($value)) {
            return "{$field_name} must be numeric";
        }
        return true;
    }
    
    /**
     * Validate email field
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @return true|string
     */
    private function validate_email($field_name, $value) {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "{$field_name} must be a valid email address";
        }
        return true;
    }
    
    /**
     * Validate date field
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @return true|string
     */
    private function validate_date($field_name, $value) {
        if (strtotime($value) === false) {
            return "{$field_name} must be a valid date";
        }
        return true;
    }
    
    /**
     * Validate minimum value/length
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param int $min Minimum value
     * @return true|string
     */
    private function validate_min($field_name, $value, $min) {
        if (is_string($value)) {
            if (strlen($value) < $min) {
                return "{$field_name} must be at least {$min} characters";
            }
        } elseif (is_numeric($value)) {
            if ($value < $min) {
                return "{$field_name} must be at least {$min}";
            }
        } elseif (is_array($value)) {
            if (count($value) < $min) {
                return "{$field_name} must have at least {$min} items";
            }
        }
        return true;
    }
    
    /**
     * Validate maximum value/length
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param int $max Maximum value
     * @return true|string
     */
    private function validate_max($field_name, $value, $max) {
        if (is_string($value)) {
            if (strlen($value) > $max) {
                return "{$field_name} must not exceed {$max} characters";
            }
        } elseif (is_numeric($value)) {
            if ($value > $max) {
                return "{$field_name} must not exceed {$max}";
            }
        } elseif (is_array($value)) {
            if (count($value) > $max) {
                return "{$field_name} must not have more than {$max} items";
            }
        }
        return true;
    }
    
    /**
     * Validate value between range
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param int $min Minimum value
     * @param int $max Maximum value
     * @return true|string
     */
    private function validate_between($field_name, $value, $min, $max) {
        $min_result = $this->validate_min($field_name, $value, $min);
        if ($min_result !== true) {
            return $min_result;
        }
        
        return $this->validate_max($field_name, $value, $max);
    }
    
    /**
     * Validate value is in array
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $allowed_values Allowed values
     * @return true|string
     */
    private function validate_in($field_name, $value, array $allowed_values) {
        if (!in_array($value, $allowed_values)) {
            return "{$field_name} must be one of: " . implode(', ', $allowed_values);
        }
        return true;
    }
    
    /**
     * Validate value is not in array
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $forbidden_values Forbidden values
     * @return true|string
     */
    private function validate_not_in($field_name, $value, array $forbidden_values) {
        if (in_array($value, $forbidden_values)) {
            return "{$field_name} must not be one of: " . implode(', ', $forbidden_values);
        }
        return true;
    }
    
    /**
     * Validate regex pattern
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param string $pattern Regex pattern
     * @return true|string
     */
    private function validate_regex($field_name, $value, $pattern) {
        if (!preg_match($pattern, $value)) {
            return "{$field_name} format is invalid";
        }
        return true;
    }
    
    /**
     * Validate date is before another date
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param string $before_date Date to compare against
     * @return true|string
     */
    private function validate_before($field_name, $value, $before_date) {
        $value_timestamp = strtotime($value);
        $before_timestamp = strtotime($before_date);
        
        if ($value_timestamp === false || $before_timestamp === false) {
            return "{$field_name} or comparison date is invalid";
        }
        
        if ($value_timestamp >= $before_timestamp) {
            return "{$field_name} must be before {$before_date}";
        }
        
        return true;
    }
    
    /**
     * Validate date is after another date
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param string $after_date Date to compare against
     * @return true|string
     */
    private function validate_after($field_name, $value, $after_date) {
        $value_timestamp = strtotime($value);
        $after_timestamp = strtotime($after_date);
        
        if ($value_timestamp === false || $after_timestamp === false) {
            return "{$field_name} or comparison date is invalid";
        }
        
        if ($value_timestamp <= $after_timestamp) {
            return "{$field_name} must be after {$after_date}";
        }
        
        return true;
    }
    
    /**
     * Validate URL
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @return true|string
     */
    private function validate_url($field_name, $value) {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return "{$field_name} must be a valid URL";
        }
        return true;
    }
    
    /**
     * Validate JSON
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @return true|string
     */
    private function validate_json($field_name, $value) {
        if (is_string($value)) {
            json_decode($value);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return "{$field_name} must be valid JSON";
            }
        }
        return true;
    }
    
    // Custom validation rules for InterSoccer
    
    /**
     * Validate Swiss AVS number
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_avs_number($field_name, $value, array $params = []) {
        if (empty($value)) {
            return true; // AVS number is optional
        }
        
        // Remove any separators
        $clean_avs = preg_replace('/[^0-9]/', '', $value);
        
        // Check length (13 digits)
        if (strlen($clean_avs) !== 13) {
            return "{$field_name} must be 13 digits long";
        }
        
        // Check that it starts with 756 (Switzerland country code)
        if (substr($clean_avs, 0, 3) !== '756') {
            return "{$field_name} must start with 756 (Switzerland)";
        }
        
        // Calculate and verify checksum (EAN-13 algorithm)
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $multiplier = ($i % 2 === 0) ? 1 : 3;
            $sum += (int)$clean_avs[$i] * $multiplier;
        }
        
        $checksum = (10 - ($sum % 10)) % 10;
        
        if ($checksum !== (int)$clean_avs[12]) {
            return "{$field_name} has an invalid checksum";
        }
        
        return true;
    }
    
    /**
     * Validate Swiss phone number
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_swiss_phone($field_name, $value, array $params = []) {
        if (empty($value)) {
            return true;
        }
        
        // Remove all non-digit characters
        $clean_phone = preg_replace('/[^0-9+]/', '', $value);
        
        // Swiss phone number patterns
        $patterns = [
            '/^(\+41|0041|0)[0-9]{9}$/',  // Swiss format
            '/^(\+|00)[0-9]{10,15}$/',   // International format
            '/^[0-9]{10}$/'              // Simple 10 digit format
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $clean_phone)) {
                return true;
            }
        }
        
        return "{$field_name} must be a valid phone number";
    }
    
    /**
     * Validate age group format
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_age_group_format($field_name, $value, array $params = []) {
        if (empty($value)) {
            return true;
        }
        
        // Valid age group patterns
        $patterns = [
            '/^[0-9]+-[0-9]+y(\s*\(.+\))?$/',  // "5-13y (Full Day)"
            '/^[0-9]+-[0-9]+$/',               // "5-13"
            '/^[0-9]+\+$/',                    // "13+"
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        
        return "{$field_name} must be a valid age group format (e.g., '5-13y (Full Day)')";
    }
    
    /**
     * Validate InterSoccer venue
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_intersoccer_venue($field_name, $value, array $params = []) {
        if (empty($value)) {
            return "{$field_name} is required";
        }
        
        // Check for required venue format: "City - Venue Name"
        if (!preg_match('/^.+\s*-\s*.+/', $value)) {
            return "{$field_name} must include both city and venue (e.g., 'Geneva - Stade de Varembé')";
        }
        
        // Check for minimum length
        if (strlen($value) < 10) {
            return "{$field_name} seems too short for a complete venue description";
        }
        
        return true;
    }
    
    /**
     * Validate event date range
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate (expects array with start_date and end_date)
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_event_date_range($field_name, $value, array $params = []) {
        if (!is_array($value) || !isset($value['start_date']) || !isset($value['end_date'])) {
            return "{$field_name} must include both start_date and end_date";
        }
        
        $start_timestamp = strtotime($value['start_date']);
        $end_timestamp = strtotime($value['end_date']);
        
        if ($start_timestamp === false || $end_timestamp === false) {
            return "{$field_name} contains invalid dates";
        }
        
        if ($end_timestamp < $start_timestamp) {
            return "End date must be after start date";
        }
        
        // Check for reasonable duration limits
        $duration_days = ($end_timestamp - $start_timestamp) / DAY_IN_SECONDS;
        
        if ($duration_days > 365) {
            return "Event duration cannot exceed one year";
        }
        
        return true;
    }
    
    /**
     * Validate player age limits
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate (date of birth)
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_player_age_limit($field_name, $value, array $params = []) {
        if (empty($value)) {
            return true;
        }
        
        $birth_timestamp = strtotime($value);
        if ($birth_timestamp === false) {
            return "{$field_name} must be a valid date";
        }
        
        $today = time();
        $age = floor(($today - $birth_timestamp) / (365.25 * DAY_IN_SECONDS));
        
        // InterSoccer age limits
        if ($age < 0) {
            return "Date of birth cannot be in the future";
        }
        
        if ($age > 18) {
            return "Players cannot be older than 18 years";
        }
        
        if ($age < 3) {
            return "Players must be at least 3 years old";
        }
        
        return true;
    }
    
    /**
     * Validate gender value
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_gender_value($field_name, $value, array $params = []) {
        $valid_genders = ['male', 'female', 'other'];
        
        if (!in_array(strtolower($value), $valid_genders)) {
            return "{$field_name} must be one of: " . implode(', ', $valid_genders);
        }
        
        return true;
    }
    
    /**
     * Validate activity type
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_activity_type($field_name, $value, array $params = []) {
        $valid_activities = ['Camp', 'Course', 'Birthday Party'];
        
        if (!in_array($value, $valid_activities)) {
            return "{$field_name} must be one of: " . implode(', ', $valid_activities);
        }
        
        return true;
    }
    
    /**
     * Validate booking type
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_booking_type($field_name, $value, array $params = []) {
        $valid_booking_types = ['Full Week', 'Single Day(s)', 'Full Term'];
        
        if (!in_array($value, $valid_booking_types)) {
            return "{$field_name} must be one of: " . implode(', ', $valid_booking_types);
        }
        
        return true;
    }
    
    /**
     * Validate selected days
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_selected_days($field_name, $value, array $params = []) {
        if (empty($value)) {
            return true;
        }
        
        $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        
        // Handle both string and array input
        if (is_string($value)) {
            $days = explode(',', $value);
        } elseif (is_array($value)) {
            $days = $value;
        } else {
            return "{$field_name} must be a string or array of days";
        }
        
        foreach ($days as $day) {
            $day = trim($day);
            if (!in_array($day, $valid_days)) {
                return "{$field_name} contains invalid day: {$day}. Valid days: " . implode(', ', $valid_days);
            }
        }
        
        return true;
    }
    
    /**
     * Validate season format
     * 
     * @param string $field_name Field name
     * @param mixed $value Value to validate
     * @param array $params Rule parameters
     * @return true|string
     */
    private function validate_season_format($field_name, $value, array $params = []) {
        if (empty($value)) {
            return true;
        }
        
        // Valid season patterns: "Summer 2025", "Autumn 2024", etc.
        $valid_seasons = ['Spring', 'Summer', 'Autumn', 'Winter'];
        
        if (!preg_match('/^(' . implode('|', $valid_seasons) . ')\s+\d{4}$/', $value)) {
            return "{$field_name} must be in format 'Season YYYY' (e.g., 'Summer 2025')";
        }
        
        return true;
    }
    
    // High-level validation methods
    
    /**
     * Validate player data
     * 
     * @param array $player_data Player data to validate
     * @return array Validation results
     */
    public function validatePlayerData(array $player_data) {
        $rules = [
            'customer_id' => ['required', 'integer', 'min:1'],
            'player_index' => ['required', 'integer', 'min:0'],
            'first_name' => ['required', 'string', 'min:2', 'max:100'],
            'last_name' => ['required', 'string', 'min:2', 'max:100'],
            'dob' => ['required', 'date', 'player_age_limit'],
            'gender' => ['required', 'gender_value'],
            'avs_number' => ['nullable', 'avs_number'],
            'medical_conditions' => ['nullable', 'string', 'max:1000'],
            'dietary_needs' => ['nullable', 'string', 'max:1000'],
            'emergency_phone' => ['nullable', 'swiss_phone']
        ];
        
        return $this->validate_data($player_data, $rules);
    }
    
    /**
     * Validate roster data
     * 
     * @param array $roster_data Roster data to validate
     * @return array Validation results
     */
    public function validateRosterData(array $roster_data) {
        $rules = [
            'order_id' => ['required', 'integer', 'min:1'],
            'order_item_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'player_index' => ['required', 'integer', 'min:0'],
            'first_name' => ['required', 'string', 'min:2', 'max:100'],
            'last_name' => ['required', 'string', 'min:2', 'max:100'],
            'dob' => ['required', 'date', 'player_age_limit'],
            'gender' => ['required', 'gender_value'],
            'activity_type' => ['required', 'activity_type'],
            'venue' => ['required', 'intersoccer_venue'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'booking_type' => ['nullable', 'booking_type'],
            'selected_days' => ['nullable', 'selected_days'],
            'season' => ['nullable', 'season_format'],
            'parent_email' => ['nullable', 'email'],
            'parent_phone' => ['nullable', 'swiss_phone'],
            'order_status' => ['required', 'in:pending,processing,on-hold,completed,cancelled,refunded,failed']
        ];
        
        return $this->validate_data($roster_data, $rules);
    }
    
    /**
     * Validate order data
     * 
     * @param array $order_data Order data to validate
     * @return array Validation results
     */
    public function validateOrderData(array $order_data) {
        $rules = [
            'order_id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'activity_type' => ['required', 'activity_type'],
            'venue' => ['required', 'intersoccer_venue'],
            'age_group' => ['nullable', 'age_group_format'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date']
        ];
        
        return $this->validate_data($order_data, $rules);
    }
    
    /**
     * Validate data against rules
     * 
     * @param array $data Data to validate
     * @param array $rules Validation rules
     * @return array Validation results
     */
    public function validate_data(array $data, array $rules) {
        $validation_results = [
            'valid' => true,
            'errors' => [],
            'field_errors' => []
        ];
        
        foreach ($rules as $field_name => $field_rules) {
            $field_value = $data[$field_name] ?? null;
            $field_errors = $this->validate_field($field_name, $field_value, $field_rules);
            
            if (!empty($field_errors)) {
                $validation_results['valid'] = false;
                $validation_results['field_errors'][$field_name] = $field_errors;
                $validation_results['errors'] = array_merge($validation_results['errors'], $field_errors);
            }
        }
        
        // Perform cross-field validation
        $cross_field_errors = $this->validateCrossFieldRules($data, $rules);
        if (!empty($cross_field_errors)) {
            $validation_results['valid'] = false;
            $validation_results['errors'] = array_merge($validation_results['errors'], $cross_field_errors);
        }
        
        return $validation_results;
    }
    
    /**
     * Validate cross-field rules
     * 
     * @param array $data Data being validated
     * @param array $rules Validation rules
     * @return array Cross-field validation errors
     */
    private function validateCrossFieldRules(array $data, array $rules) {
        $errors = [];
        
        // Validate date ranges
        if (isset($data['start_date']) && isset($data['end_date']) && 
            !empty($data['start_date']) && !empty($data['end_date'])) {
            
            $start_timestamp = strtotime($data['start_date']);
            $end_timestamp = strtotime($data['end_date']);
            
            if ($start_timestamp !== false && $end_timestamp !== false) {
                if ($end_timestamp < $start_timestamp) {
                    $errors[] = 'End date must be after start date';
                }
                
                // Check for reasonable duration based on activity type
                $duration_days = ($end_timestamp - $start_timestamp) / DAY_IN_SECONDS;
                
                if (isset($data['activity_type'])) {
                    switch ($data['activity_type']) {
                        case 'Camp':
                            if ($duration_days > 7) {
                                $errors[] = 'Camp duration cannot exceed 7 days';
                            }
                            break;
                            
                        case 'Course':
                            if ($duration_days > 365) {
                                $errors[] = 'Course duration cannot exceed 1 year';
                            }
                            break;
                            
                        case 'Birthday Party':
                            if ($duration_days > 1) {
                                $errors[] = 'Birthday party should be a single day event';
                            }
                            break;
                    }
                }
            }
        }
        
        // Validate booking type consistency
        if (isset($data['booking_type']) && isset($data['activity_type'])) {
            if ($data['activity_type'] === 'Course' && $data['booking_type'] === 'Full Week') {
                $errors[] = 'Courses cannot use "Full Week" booking type';
            }
            
            if ($data['activity_type'] === 'Camp' && $data['booking_type'] === 'Full Term') {
                $errors[] = 'Camps cannot use "Full Term" booking type';
            }
        }
        
        // Validate selected days for single day bookings
        if (isset($data['booking_type']) && $data['booking_type'] === 'Single Day(s)') {
            if (empty($data['selected_days'])) {
                $errors[] = 'Selected days are required for single day bookings';
            }
        }
        
        // Validate age group eligibility
        if (isset($data['dob']) && isset($data['age_group']) && 
            !empty($data['dob']) && !empty($data['age_group'])) {
            
            $event_date = $data['start_date'] ?? null;
            $birth_date = new \DateTime($data['dob']);
            $check_date = $event_date ? new \DateTime($event_date) : new \DateTime();
            
            $age = $birth_date->diff($check_date)->y;
            
            // Parse age group for min/max ages
            if (preg_match('/(\d+)-(\d+)/', $data['age_group'], $matches)) {
                $min_age = (int)$matches[1];
                $max_age = (int)$matches[2];
                
                if ($age < $min_age || $age > $max_age) {
                    $errors[] = "Player age ({$age}) is not eligible for age group: {$data['age_group']}";
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Add custom validation rule
     * 
     * @param string $rule_name Rule name
     * @param callable $callback Validation callback
     * @return void
     */
    public function addCustomRule($rule_name, callable $callback) {
        $this->custom_rules[$rule_name] = $callback;
        $this->logger->debug('Added custom validation rule', ['rule' => $rule_name]);
    }
    
    /**
     * Get validation summary for a dataset
     * 
     * @param array $dataset Array of data items to validate
     * @param array $rules Validation rules
     * @return array Validation summary
     */
    public function getValidationSummary(array $dataset, array $rules) {
        $summary = [
            'total_items' => count($dataset),
            'valid_items' => 0,
            'invalid_items' => 0,
            'error_summary' => [],
            'field_error_counts' => [],
            'most_common_errors' => []
        ];
        
        foreach ($dataset as $index => $data_item) {
            $validation_result = $this->validate_data($data_item, $rules);
            
            if ($validation_result['valid']) {
                $summary['valid_items']++;
            } else {
                $summary['invalid_items']++;
                
                // Count field errors
                foreach ($validation_result['field_errors'] as $field => $field_errors) {
                    $summary['field_error_counts'][$field] = ($summary['field_error_counts'][$field] ?? 0) + count($field_errors);
                }
                
                // Count individual errors
                foreach ($validation_result['errors'] as $error) {
                    $summary['error_summary'][$error] = ($summary['error_summary'][$error] ?? 0) + 1;
                }
            }
        }
        
        // Sort errors by frequency
        arsort($summary['error_summary']);
        $summary['most_common_errors'] = array_slice(array_keys($summary['error_summary']), 0, 10);
        
        return $summary;
    }
}