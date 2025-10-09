<?php
/**
 * Player Model Class
 * 
 * Represents a player/child in the InterSoccer system.
 * Handles player data validation, age calculations, and metadata management.
 * 
 * @package InterSoccer\ReportsRosters\Data\Models
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Data\Models;

use InterSoccer\ReportsRosters\Data\Models\AbstractModel;
use InterSoccer\ReportsRosters\Utils\DateHelper;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Player Model Class
 * 
 * Manages player data with comprehensive validation and utility methods
 */
class Player extends AbstractModel {
    
    /**
     * Fillable attributes
     * 
     * @var array
     */
    protected $fillable = [
        'customer_id',
        'player_index',
        'avs_number',
        'first_name',
        'last_name',
        'dob',
        'gender',
        'medical_conditions',
        'dietary_needs',
        'emergency_contact',
        'emergency_phone',
        'event_count',
        'creation_timestamp',
        'last_updated'
    ];
    
    /**
     * Validation rules
     * 
     * @var array
     */
    protected $validation_rules = [
        'customer_id' => ['required', 'integer', 'min:1'],
        'player_index' => ['required', 'integer', 'min:0'],
        'first_name' => ['required', 'string', 'min:1', 'max:100'],
        'last_name' => ['required', 'string', 'min:1', 'max:100'],
        'dob' => ['required', 'date', 'before:today'],
        'gender' => ['required', 'in:male,female,other'],
        'avs_number' => ['nullable', 'string', 'regex:/^\d{3}\.\d{4}\.\d{4}\.\d{2}$/'],
        'emergency_phone' => ['nullable', 'string', 'max:50'],
        'medical_conditions' => ['nullable', 'string', 'max:1000'],
        'dietary_needs' => ['nullable', 'string', 'max:1000']
    ];
    
    /**
     * Attribute casting
     * 
     * @var array
     */
    protected $casts = [
        'customer_id' => 'integer',
        'player_index' => 'integer',
        'dob' => 'date',
        'event_count' => 'integer',
        'creation_timestamp' => 'integer',
        'last_updated' => 'datetime'
    ];
    
    /**
     * Hidden attributes
     * 
     * @var array
     */
    protected $hidden = [
        'creation_timestamp'
    ];
    
    /**
     * Age group definitions for camps and courses
     * 
     * @var array
     */
    const AGE_GROUPS = [
        'camp' => [
            '3-5y (Half-Day)' => ['min' => 3, 'max' => 5],
            '5-13y (Full Day)' => ['min' => 5, 'max' => 13],
        ],
        'course' => [
            '3-4y' => ['min' => 3, 'max' => 4],
            '3-5y' => ['min' => 3, 'max' => 5],
            '3-6y' => ['min' => 3, 'max' => 6],
            '3-7y' => ['min' => 3, 'max' => 7],
            '3-8y' => ['min' => 3, 'max' => 8],
            '3-9y' => ['min' => 3, 'max' => 9],
            '3-10y' => ['min' => 3, 'max' => 10],
            '3-12y' => ['min' => 3, 'max' => 12],
            '4-5y' => ['min' => 4, 'max' => 5],
            '5-7y' => ['min' => 5, 'max' => 7],
            '5-8y' => ['min' => 5, 'max' => 8],
            '6-7y' => ['min' => 6, 'max' => 7],
            '6-8y' => ['min' => 6, 'max' => 8],
            '6-9y' => ['min' => 6, 'max' => 9],
            '6-10y' => ['min' => 6, 'max' => 10],
            '7-9y' => ['min' => 7, 'max' => 9],
            '8-12y' => ['min' => 8, 'max' => 12],
        ]
    ];
    
    /**
     * Constructor
     * 
     * @param array $attributes Initial attributes
     */
    public function __construct(array $attributes = []) {
        // Set default values
        $attributes = array_merge([
            'event_count' => 0,
            'creation_timestamp' => time(),
            'last_updated' => current_time('mysql')
        ], $attributes);
        
        parent::__construct($attributes);
    }
    
    /**
     * Get full name
     * 
     * @return string Full name
     */
    public function getFullName() {
        return trim($this->first_name . ' ' . $this->last_name);
    }
    
    /**
     * Get full name attribute accessor
     * 
     * @param mixed $value Raw value (unused)
     * @return string Full name
     */
    public function getFullNameAttribute($value) {
        return $this->getFullName();
    }
    
    /**
     * Calculate player's age at a specific date
     * 
     * @param string|null $date Date to calculate age at (default: today)
     * @return int Age in years
     */
    public function getAgeAt($date = null) {
        if (empty($this->dob)) {
            return 0;
        }
        
        $birth_date = new \DateTime($this->dob);
        $target_date = $date ? new \DateTime($date) : new \DateTime();
        
        return $birth_date->diff($target_date)->y;
    }
    
    /**
     * Get current age
     * 
     * @return int Current age in years
     */
    public function getAge() {
        return $this->getAgeAt();
    }
    
    /**
     * Get age attribute accessor
     * 
     * @param mixed $value Raw value (unused)
     * @return int Current age
     */
    public function getAgeAttribute($value) {
        return $this->getAge();
    }
    
    /**
     * Check if player is eligible for an age group
     * 
     * @param string $age_group Age group specification (e.g., "5-13y (Full Day)")
     * @param string|null $event_date Date of the event
     * @return bool Is eligible
     */
    public function isEligibleForAgeGroup($age_group, $event_date = null) {
        // Parse age group
        $age_range = $this->parseAgeGroup($age_group);
        if (!$age_range) {
            return false;
        }
        
        $age = $this->getAgeAt($event_date);
        return $age >= $age_range['min'] && $age <= $age_range['max'];
    }
    
    /**
     * Parse age group string to min/max ages
     * 
     * @param string $age_group Age group string
     * @return array|null Age range or null if invalid
     */
    private function parseAgeGroup($age_group) {
        // Check predefined age groups first
        foreach (self::AGE_GROUPS as $activity_groups) {
            if (isset($activity_groups[$age_group])) {
                return $activity_groups[$age_group];
            }
        }
        
        // Try to parse dynamic age groups (e.g., "5-13y", "3-5y (Half-Day)")
        if (preg_match('/(\d+)-(\d+)y/', $age_group, $matches)) {
            return [
                'min' => (int) $matches[1],
                'max' => (int) $matches[2]
            ];
        }
        
        return null;
    }
    
    /**
     * Get suggested age groups for this player
     * 
     * @param string $activity_type Activity type ('camp' or 'course')
     * @param string|null $event_date Date of the event
     * @return array Eligible age groups
     */
    public function getEligibleAgeGroups($activity_type = 'camp', $event_date = null) {
        $eligible = [];
        $age_groups = self::AGE_GROUPS[$activity_type] ?? [];
        
        foreach ($age_groups as $group_name => $range) {
            if ($this->isEligibleForAgeGroup($group_name, $event_date)) {
                $eligible[] = $group_name;
            }
        }
        
        return $eligible;
    }
    
    /**
     * Check if player has medical conditions
     * 
     * @return bool Has medical conditions
     */
    public function hasMedicalConditions() {
        return !empty(trim($this->medical_conditions));
    }
    
    /**
     * Check if player has dietary needs
     * 
     * @return bool Has dietary needs
     */
    public function hasDietaryNeeds() {
        return !empty(trim($this->dietary_needs));
    }
    
    /**
     * Check if player has special needs (medical or dietary)
     * 
     * @return bool Has special needs
     */
    public function hasSpecialNeeds() {
        return $this->hasMedicalConditions() || $this->hasDietaryNeeds();
    }
    
    /**
     * Get medical conditions as array
     * 
     * @return array Medical conditions
     */
    public function getMedicalConditionsArray() {
        if (empty($this->medical_conditions)) {
            return [];
        }
        
        $conditions = explode(',', $this->medical_conditions);
        return array_map('trim', $conditions);
    }
    
    /**
     * Get dietary needs as array
     * 
     * @return array Dietary needs
     */
    public function getDietaryNeedsArray() {
        if (empty($this->dietary_needs)) {
            return [];
        }
        
        $needs = explode(',', $this->dietary_needs);
        return array_map('trim', $needs);
    }
    
    /**
     * Format AVS number with proper separators
     * 
     * @return string|null Formatted AVS number
     */
    public function getFormattedAvsNumber() {
        if (empty($this->avs_number)) {
            return null;
        }
        
        // Remove any existing separators
        $clean = preg_replace('/[^0-9]/', '', $this->avs_number);
        
        // Format as 756.1234.5678.90
        if (strlen($clean) === 13) {
            return substr($clean, 0, 3) . '.' . 
                   substr($clean, 3, 4) . '.' . 
                   substr($clean, 7, 4) . '.' . 
                   substr($clean, 11, 2);
        }
        
        return $this->avs_number;
    }
    
    /**
     * Validate AVS number format and checksum
     * 
     * @return bool Is valid AVS number
     */
    public function isValidAvsNumber() {
        if (empty($this->avs_number)) {
            return true; // AVS number is optional
        }
        
        $formatted = $this->getFormattedAvsNumber();
        
        // Check format
        if (!preg_match('/^756\.\d{4}\.\d{4}\.\d{2}$/', $formatted)) {
            return false;
        }
        
        // Extract digits for checksum calculation
        $digits = str_replace('.', '', $formatted);
        
        // Calculate EAN-13 checksum for AVS numbers
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $multiplier = ($i % 2 === 0) ? 1 : 3;
            $sum += (int)$digits[$i] * $multiplier;
        }
        
        $checksum = (10 - ($sum % 10)) % 10;
        return $checksum === (int)$digits[12];
    }
    
    /**
     * Increment event count
     * 
     * @return self
     */
    public function incrementEventCount() {
        $this->event_count = ($this->event_count ?? 0) + 1;
        $this->last_updated = current_time('mysql');
        return $this;
    }
    
    /**
     * Create from WordPress user metadata
     * 
     * @param int $customer_id WordPress user ID
     * @param int $player_index Player index in metadata array
     * @param array $player_data Player data from metadata
     * @return static Player instance
     */
    public static function fromUserMetadata($customer_id, $player_index, array $player_data) {
        $attributes = [
            'customer_id' => $customer_id,
            'player_index' => $player_index,
            'first_name' => $player_data['first_name'] ?? '',
            'last_name' => $player_data['last_name'] ?? '',
            'dob' => $player_data['dob'] ?? null,
            'gender' => $player_data['gender'] ?? null,
            'medical_conditions' => $player_data['medical_conditions'] ?? null,
            'dietary_needs' => $player_data['dietary_needs'] ?? null,
            'avs_number' => $player_data['avs_number'] ?? null,
            'event_count' => $player_data['event_count'] ?? 0,
            'creation_timestamp' => $player_data['creation_timestamp'] ?? time()
        ];
        
        return new static($attributes);
    }
    
    /**
     * Export player data for WordPress user metadata
     * 
     * @return array Player data for metadata
     */
    public function toUserMetadata() {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'dob' => $this->dob,
            'gender' => $this->gender,
            'medical_conditions' => $this->medical_conditions,
            'dietary_needs' => $this->dietary_needs,
            'avs_number' => $this->avs_number,
            'event_count' => $this->event_count ?? 0,
            'creation_timestamp' => $this->creation_timestamp ?? time()
        ];
    }
    
    /**
     * Get player summary for roster display
     * 
     * @return array Player summary
     */
    public function getRosterSummary() {
        return [
            'full_name' => $this->getFullName(),
            'age' => $this->getAge(),
            'gender' => ucfirst($this->gender ?? ''),
            'has_medical' => $this->hasMedicalConditions(),
            'has_dietary' => $this->hasDietaryNeeds(),
            'medical_conditions' => $this->medical_conditions ?? '',
            'dietary_needs' => $this->dietary_needs ?? '',
            'emergency_contact' => $this->emergency_contact ?? '',
            'emergency_phone' => $this->emergency_phone ?? ''
        ];
    }
    
    /**
     * Get export data for Excel/CSV
     * 
     * @return array Export data
     */
    public function getExportData() {
        return [
            'First Name' => $this->first_name,
            'Last Name' => $this->last_name,
            'Full Name' => $this->getFullName(),
            'Date of Birth' => $this->dob,
            'Age' => $this->getAge(),
            'Gender' => ucfirst($this->gender ?? ''),
            'Medical Conditions' => $this->medical_conditions ?? 'None',
            'Dietary Needs' => $this->dietary_needs ?? 'None',
            'AVS Number' => $this->getFormattedAvsNumber() ?? '',
            'Emergency Contact' => $this->emergency_contact ?? '',
            'Emergency Phone' => $this->emergency_phone ?? '',
            'Event Count' => $this->event_count ?? 0
        ];
    }
    
    /**
     * Validate player data with enhanced rules
     * 
     * @throws ValidationException If validation fails
     * @return bool Validation passed
     */
    public function validate() {
        // Run parent validation first
        parent::validate();
        
        // Custom validation rules
        $this->validateAge();
        $this->validateAvsNumber();
        $this->validateNames();
        
        return true;
    }
    
    /**
     * Validate player age
     * 
     * @throws ValidationException If age is invalid
     * @return void
     */
    private function validateAge() {
        if (empty($this->dob)) {
            throw new ValidationException('Date of birth is required');
        }
        
        $age = $this->getAge();
        
        // Age should be between 0 and 18 for InterSoccer programs
        if ($age < 0) {
            throw new ValidationException('Date of birth cannot be in the future');
        }
        
        if ($age > 18) {
            throw new ValidationException('Player age cannot exceed 18 years');
        }
    }
    
    /**
     * Validate AVS number if provided
     * 
     * @throws ValidationException If AVS number is invalid
     * @return void
     */
    private function validateAvsNumber() {
        if (!empty($this->avs_number) && !$this->isValidAvsNumber()) {
            throw new ValidationException('Invalid AVS number format or checksum');
        }
    }
    
    /**
     * Validate player names
     * 
     * @throws ValidationException If names are invalid
     * @return void
     */
    private function validateNames() {
        // Check for potentially problematic characters
        $name_pattern = '/^[a-zA-ZÀ-ÿ\s\-\'\.]+$/u';
        
        if (!preg_match($name_pattern, $this->first_name)) {
            throw new ValidationException('First name contains invalid characters');
        }
        
        if (!preg_match($name_pattern, $this->last_name)) {
            throw new ValidationException('Last name contains invalid characters');
        }
        
        // Check for minimum length
        if (strlen(trim($this->first_name)) < 2) {
            throw new ValidationException('First name must be at least 2 characters');
        }
        
        if (strlen(trim($this->last_name)) < 2) {
            throw new ValidationException('Last name must be at least 2 characters');
        }
    }
    
    /**
     * Get unique identifier for this player
     * 
     * @return string Unique identifier
     */
    public function getUniqueId() {
        return $this->customer_id . '_' . $this->player_index;
    }
    
    /**
     * Compare with another player for equality
     * 
     * @param Player $other Other player
     * @return bool Are equal
     */
    public function equals(Player $other) {
        return $this->customer_id === $other->customer_id && 
               $this->player_index === $other->player_index;
    }
    
    /**
     * Check if this player matches search criteria
     * 
     * @param array $criteria Search criteria
     * @return bool Matches criteria
     */
    public function matches(array $criteria) {
        foreach ($criteria as $field => $value) {
            switch ($field) {
                case 'name':
                    $full_name = strtolower($this->getFullName());
                    if (strpos($full_name, strtolower($value)) === false) {
                        return false;
                    }
                    break;
                    
                case 'age':
                    if (is_array($value)) {
                        $age = $this->getAge();
                        if ($age < $value['min'] || $age > $value['max']) {
                            return false;
                        }
                    } else {
                        if ($this->getAge() !== (int)$value) {
                            return false;
                        }
                    }
                    break;
                    
                case 'gender':
                    if ($this->gender !== $value) {
                        return false;
                    }
                    break;
                    
                case 'has_medical':
                    if ($this->hasMedicalConditions() !== (bool)$value) {
                        return false;
                    }
                    break;
                    
                case 'has_dietary':
                    if ($this->hasDietaryNeeds() !== (bool)$value) {
                        return false;
                    }
                    break;
                    
                default:
                    if ($this->getAttribute($field) !== $value) {
                        return false;
                    }
            }
        }
        
        return true;
    }
    
    /**
     * Get player display name with age
     * 
     * @return string Display name with age
     */
    public function getDisplayName() {
        return sprintf('%s (%d years old)', $this->getFullName(), $this->getAge());
    }
    
    /**
     * Get player initials
     * 
     * @return string Player initials
     */
    public function getInitials() {
        $first_initial = !empty($this->first_name) ? strtoupper($this->first_name[0]) : '';
        $last_initial = !empty($this->last_name) ? strtoupper($this->last_name[0]) : '';
        
        return $first_initial . $last_initial;
    }
    
    /**
     * Check if player has complete information
     * 
     * @return bool Has complete information
     */
    public function isComplete() {
        $required_fields = ['first_name', 'last_name', 'dob', 'gender'];
        
        foreach ($required_fields as $field) {
            if (empty($this->getAttribute($field))) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get missing required information
     * 
     * @return array Missing field names
     */
    public function getMissingInformation() {
        $required_fields = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name', 
            'dob' => 'Date of Birth',
            'gender' => 'Gender'
        ];
        
        $missing = [];
        foreach ($required_fields as $field => $label) {
            if (empty($this->getAttribute($field))) {
                $missing[] = $label;
            }
        }
        
        return $missing;
    }
    
    /**
     * Create a copy of this player with updated data
     * 
     * @param array $updates Data to update
     * @return static New player instance
     */
    public function duplicate(array $updates = []) {
        $data = $this->toArray();
        unset($data['id']); // Remove ID so it creates a new record
        
        $data = array_merge($data, $updates);
        return new static($data);
    }
    
    /**
     * Generate player summary for logging
     * 
     * @return array Player summary for logs
     */
    public function getLogSummary() {
        return [
            'player_id' => $this->getUniqueId(),
            'name' => $this->getFullName(),
            'age' => $this->getAge(),
            'gender' => $this->gender,
            'customer_id' => $this->customer_id,
            'player_index' => $this->player_index
        ];
    }
    
    /**
     * Set date of birth attribute with validation
     * 
     * @param string $value Date of birth
     * @return string Formatted date
     */
    public function setDobAttribute($value) {
        if (empty($value)) {
            return null;
        }
        
        // Try to parse various date formats
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'];
        
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }
        
        // Try strtotime as fallback
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return $value; // Return original if can't parse (will fail validation)
    }
    
    /**
     * Set gender attribute with normalization
     * 
     * @param string $value Gender value
     * @return string Normalized gender
     */
    public function setGenderAttribute($value) {
        if (empty($value)) {
            return null;
        }
        
        $value = strtolower(trim($value));
        
        // Normalize common variations
        switch ($value) {
            case 'm':
            case 'boy':
            case 'man':
                return 'male';
                
            case 'f':
            case 'girl':
            case 'woman':
                return 'female';
                
            case 'male':
            case 'female':
            case 'other':
                return $value;
                
            default:
                return 'other';
        }
    }
    
    /**
     * Set medical conditions with sanitization
     * 
     * @param string $value Medical conditions
     * @return string|null Sanitized medical conditions
     */
    public function setMedicalConditionsAttribute($value) {
        if (empty(trim($value)) || strtolower(trim($value)) === 'none') {
            return null;
        }
        
        return sanitize_textarea_field($value);
    }
    
    /**
     * Set dietary needs with sanitization
     * 
     * @param string $value Dietary needs
     * @return string|null Sanitized dietary needs
     */
    public function setDietaryNeedsAttribute($value) {
        if (empty(trim($value)) || strtolower(trim($value)) === 'none') {
            return null;
        }
        
        return sanitize_textarea_field($value);
    }
    
    /**
     * Convert to array with additional computed fields
     * 
     * @return array Model as array with computed fields
     */
    public function toArray() {
        $array = parent::toArray();
        
        // Add computed fields
        $array['full_name'] = $this->getFullName();
        $array['age'] = $this->getAge();
        $array['display_name'] = $this->getDisplayName();
        $array['initials'] = $this->getInitials();
        $array['has_medical_conditions'] = $this->hasMedicalConditions();
        $array['has_dietary_needs'] = $this->hasDietaryNeeds();
        $array['has_special_needs'] = $this->hasSpecialNeeds();
        $array['is_complete'] = $this->isComplete();
        $array['formatted_avs'] = $this->getFormattedAvsNumber();
        
        return $array;
    }
}