<?php
/**
 * Roster Model Class
 * 
 * Represents a roster entry - a player assigned to a specific event.
 * Combines player data with event/order information for comprehensive roster management.
 * 
 * @package InterSoccer\ReportsRosters\Data\Models
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Data\Models;

use InterSoccer\ReportsRosters\Data\Models\AbstractModel;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Utils\DateHelper;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Roster Model Class
 * 
 * Manages roster entries with comprehensive validation and utility methods
 */
class Roster extends AbstractModel {
    
    /**
     * Fillable attributes
     * 
     * @var array
     */
    protected $fillable = [
        'order_id',
        'order_item_id',
        'variation_id',
        'product_id',
        'customer_id',
        'player_index',
        'first_name',
        'last_name',
        'dob',
        'gender',
        'medical_conditions',
        'dietary_needs',
        'emergency_contact',
        'emergency_phone',
        'parent_email',
        'parent_phone',
        'event_type',
        'activity_type',
        'venue',
        'age_group',
        'start_date',
        'end_date',
        'event_details',
        'booking_type',
        'selected_days',
        'season',
        'region',
        'city',
        'course_day',
        'course_times',
        'camp_times',
        'discount_applied',
        'order_status'
    ];
    
    /**
     * Validation rules
     * 
     * @var array
     */
    protected $validation_rules = [
        'order_id' => ['required', 'integer', 'min:1'],
        'order_item_id' => ['required', 'integer', 'min:1'],
        'product_id' => ['required', 'integer', 'min:1'],
        'customer_id' => ['required', 'integer', 'min:1'],
        'player_index' => ['required', 'integer', 'min:0'],
        'first_name' => ['required', 'string', 'min:1', 'max:100'],
        'last_name' => ['required', 'string', 'min:1', 'max:100'],
        'dob' => ['required', 'date', 'before:today'],
        'gender' => ['required', 'in:male,female,other'],
        'activity_type' => ['required', 'in:Camp,Course,Birthday Party'],
        'venue' => ['required', 'string', 'max:200'],
        'start_date' => ['required', 'date'],
        'order_status' => ['required', 'in:pending,processing,on-hold,completed,cancelled,refunded,failed']
    ];
    
    /**
     * Attribute casting
     * 
     * @var array
     */
    protected $casts = [
        'order_id' => 'integer',
        'order_item_id' => 'integer',
        'variation_id' => 'integer',
        'product_id' => 'integer',
        'customer_id' => 'integer',
        'player_index' => 'integer',
        'dob' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'event_details' => 'json',
        'selected_days' => 'array'
    ];
    
    /**
     * Hidden attributes
     * 
     * @var array
     */
    protected $hidden = [
        'variation_id',
        'product_id'
    ];
    
    /**
     * Activity type constants
     */
    const ACTIVITY_CAMP = 'Camp';
    const ACTIVITY_COURSE = 'Course';
    const ACTIVITY_BIRTHDAY = 'Birthday Party';
    
    /**
     * Booking type constants
     */
    const BOOKING_FULL_WEEK = 'Full Week';
    const BOOKING_SINGLE_DAYS = 'Single Day(s)';
    const BOOKING_FULL_TERM = 'Full Term';
    
    /**
     * Order status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_ON_HOLD = 'on-hold';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_FAILED = 'failed';
    
    /**
     * Days of the week for courses and camps
     * 
     * @var array
     */
    const DAYS_OF_WEEK = [
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'
    ];
    
    /**
     * Associated Player model instance
     * 
     * @var Player|null
     */
    private $player_instance = null;
    
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
     * Get player age at event start date
     * 
     * @return int Age in years
     */
    public function getAgeAtEvent() {
        if (empty($this->dob) || empty($this->start_date)) {
            return 0;
        }
        
        $birth_date = new \DateTime($this->dob);
        $event_date = new \DateTime($this->start_date);
        
        return $birth_date->diff($event_date)->y;
    }
    
    /**
     * Get age at event attribute accessor
     * 
     * @param mixed $value Raw value (unused)
     * @return int Age at event
     */
    public function getAgeAtEventAttribute($value) {
        return $this->getAgeAtEvent();
    }
    
    /**
     * Check if this is a camp event
     * 
     * @return bool Is camp
     */
    public function isCamp() {
        return $this->activity_type === self::ACTIVITY_CAMP;
    }
    
    /**
     * Check if this is a course event
     * 
     * @return bool Is course
     */
    public function isCourse() {
        return $this->activity_type === self::ACTIVITY_COURSE;
    }
    
    /**
     * Check if this is a birthday party event
     * 
     * @return bool Is birthday party
     */
    public function isBirthday() {
        return $this->activity_type === self::ACTIVITY_BIRTHDAY;
    }
    
    /**
     * Check if booking is for full week/term
     * 
     * @return bool Is full booking
     */
    public function isFullBooking() {
        return in_array($this->booking_type, [self::BOOKING_FULL_WEEK, self::BOOKING_FULL_TERM]);
    }
    
    /**
     * Check if booking is for selected days only
     * 
     * @return bool Is partial booking
     */
    public function isPartialBooking() {
        return $this->booking_type === self::BOOKING_SINGLE_DAYS;
    }
    
    /**
     * Get selected days as array
     * 
     * @return array Selected days
     */
    public function getSelectedDaysArray() {
        if (empty($this->selected_days)) {
            return [];
        }
        
        if (is_array($this->selected_days)) {
            return $this->selected_days;
        }
        
        // Parse comma-separated string
        $days = explode(',', $this->selected_days);
        return array_map('trim', $days);
    }
    
    /**
     * Get formatted selected days string
     * 
     * @return string Formatted days
     */
    public function getFormattedSelectedDays() {
        $days = $this->getSelectedDaysArray();
        
        if (empty($days)) {
            return 'All days';
        }
        
        return implode(', ', $days);
    }
    
    /**
     * Calculate event duration in days
     * 
     * @return int Duration in days
     */
    public function getEventDurationDays() {
        if (empty($this->start_date) || empty($this->end_date)) {
            return 1;
        }
        
        $start = new \DateTime($this->start_date);
        $end = new \DateTime($this->end_date);
        
        return $start->diff($end)->days + 1; // +1 to include both start and end dates
    }
    
    /**
     * Check if event is currently active
     * 
     * @return bool Is active
     */
    public function isActive() {
        if (empty($this->start_date) || empty($this->end_date)) {
            return false;
        }
        
        $today = new \DateTime();
        $start = new \DateTime($this->start_date);
        $end = new \DateTime($this->end_date);
        
        return $today >= $start && $today <= $end;
    }
    
    /**
     * Check if event is in the future
     * 
     * @return bool Is future event
     */
    public function isFutureEvent() {
        if (empty($this->start_date)) {
            return false;
        }
        
        $today = new \DateTime();
        $start = new \DateTime($this->start_date);
        
        return $start > $today;
    }
    
    /**
     * Check if event is in the past
     * 
     * @return bool Is past event
     */
    public function isPastEvent() {
        if (empty($this->end_date)) {
            return false;
        }
        
        $today = new \DateTime();
        $end = new \DateTime($this->end_date);
        
        return $end < $today;
    }
    
    /**
     * Get event status based on dates
     * 
     * @return string Event status
     */
    public function getEventStatus() {
        if ($this->isFutureEvent()) {
            return 'upcoming';
        } elseif ($this->isActive()) {
            return 'active';
        } elseif ($this->isPastEvent()) {
            return 'completed';
        }
        
        return 'unknown';
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
     * Check if player has special needs
     * 
     * @return bool Has special needs
     */
    public function hasSpecialNeeds() {
        return $this->hasMedicalConditions() || $this->hasDietaryNeeds();
    }
    
    /**
     * Get emergency contact information
     * 
     * @return array Emergency contact info
     */
    public function getEmergencyContact() {
        return [
            'name' => $this->emergency_contact ?: $this->getFullName() . ' Parent',
            'phone' => $this->emergency_phone ?: $this->parent_phone,
            'email' => $this->parent_email
        ];
    }
    
    /**
     * Get event times based on activity type
     * 
     * @return string Event times
     */
    public function getEventTimes() {
        if ($this->isCamp()) {
            return $this->camp_times ?: 'TBD';
        } elseif ($this->isCourse()) {
            return $this->course_times ?: 'TBD';
        }
        
        return 'TBD';
    }
    
    /**
     * Get formatted event duration string
     * 
     * @return string Duration description
     */
    public function getFormattedDuration() {
        if ($this->isCourse()) {
            $start = new \DateTime($this->start_date);
            $end = new \DateTime($this->end_date);
            $weeks = ceil($start->diff($end)->days / 7);
            
            return $weeks . ' week' . ($weeks > 1 ? 's' : '');
        }
        
        $days = $this->getEventDurationDays();
        return $days . ' day' . ($days > 1 ? 's' : '');
    }
    
    /**
     * Get order URL in WordPress admin
     * 
     * @return string Order admin URL
     */
    public function getOrderAdminUrl() {
        if (class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore')) {
            // HPOS enabled
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $this->order_id);
        } else {
            // Legacy post-based orders
            return admin_url('post.php?post=' . $this->order_id . '&action=edit');
        }
    }
    
    /**
     * Get associated Player model instance
     * 
     * @return Player Player instance
     */
    public function getPlayer() {
        if ($this->player_instance === null) {
            $this->player_instance = Player::make([
                'customer_id' => $this->customer_id,
                'player_index' => $this->player_index,
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'dob' => $this->dob,
                'gender' => $this->gender,
                'medical_conditions' => $this->medical_conditions,
                'dietary_needs' => $this->dietary_needs,
                'emergency_contact' => $this->emergency_contact,
                'emergency_phone' => $this->emergency_phone
            ]);
        }
        
        return $this->player_instance;
    }
    
    /**
     * Create from WooCommerce order data and player metadata
     * 
     * @param array $order_data WooCommerce order item data
     * @param array $player_data Player metadata
     * @param array $parent_data Parent/customer data
     * @return static Roster instance
     */
    public static function fromOrderData(array $order_data, array $player_data, array $parent_data = []) {
        $attributes = array_merge([
            // Order information
            'order_id' => $order_data['order_id'] ?? 0,
            'order_item_id' => $order_data['order_item_id'] ?? 0,
            'variation_id' => $order_data['variation_id'] ?? null,
            'product_id' => $order_data['product_id'] ?? 0,
            'customer_id' => $order_data['customer_id'] ?? 0,
            'player_index' => $order_data['player_index'] ?? 0,
            
            // Event information from order metadata
            'event_type' => $order_data['event_type'] ?? null,
            'activity_type' => $order_data['activity_type'] ?? null,
            'venue' => $order_data['venue'] ?? null,
            'age_group' => $order_data['age_group'] ?? null,
            'start_date' => $order_data['start_date'] ?? null,
            'end_date' => $order_data['end_date'] ?? null,
            'booking_type' => $order_data['booking_type'] ?? null,
            'selected_days' => $order_data['selected_days'] ?? null,
            'season' => $order_data['season'] ?? null,
            'region' => $order_data['region'] ?? null,
            'city' => $order_data['city'] ?? null,
            'course_day' => $order_data['course_day'] ?? null,
            'course_times' => $order_data['course_times'] ?? null,
            'camp_times' => $order_data['camp_times'] ?? null,
            'discount_applied' => $order_data['discount_applied'] ?? null,
            'order_status' => $order_data['order_status'] ?? 'completed',
            'event_details' => $order_data['event_details'] ?? null,
            
            // Parent information
            'parent_email' => $parent_data['email'] ?? null,
            'parent_phone' => $parent_data['phone'] ?? null,
            'emergency_contact' => $parent_data['emergency_contact'] ?? null,
            'emergency_phone' => $parent_data['emergency_phone'] ?? $parent_data['phone'] ?? null,
        ], $player_data);
        
        return new static($attributes);
    }
    
    /**
     * Get roster summary for display
     * 
     * @return array Roster summary
     */
    public function getRosterSummary() {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'full_name' => $this->getFullName(),
            'age_at_event' => $this->getAgeAtEvent(),
            'gender' => ucfirst($this->gender ?? ''),
            'activity_type' => $this->activity_type,
            'venue' => $this->venue,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'event_status' => $this->getEventStatus(),
            'booking_type' => $this->booking_type,
            'selected_days' => $this->getFormattedSelectedDays(),
            'has_special_needs' => $this->hasSpecialNeeds(),
            'order_status' => $this->order_status
        ];
    }
    
    /**
     * Get export data for Excel/CSV
     * 
     * @return array Export data
     */
    public function getExportData() {
        return [
            'Order ID' => $this->order_id,
            'First Name' => $this->first_name,
            'Last Name' => $this->last_name,
            'Full Name' => $this->getFullName(),
            'Date of Birth' => $this->dob,
            'Age at Event' => $this->getAgeAtEvent(),
            'Gender' => ucfirst($this->gender ?? ''),
            'Activity Type' => $this->activity_type,
            'Event Type' => $this->event_type,
            'Venue' => $this->venue,
            'Age Group' => $this->age_group,
            'Start Date' => $this->start_date,
            'End Date' => $this->end_date,
            'Duration' => $this->getFormattedDuration(),
            'Booking Type' => $this->booking_type,
            'Selected Days' => $this->getFormattedSelectedDays(),
            'Event Times' => $this->getEventTimes(),
            'Season' => $this->season,
            'Region' => $this->region,
            'City' => $this->city,
            'Medical Conditions' => $this->medical_conditions ?: 'None',
            'Dietary Needs' => $this->dietary_needs ?: 'None',
            'Parent Email' => $this->parent_email,
            'Parent Phone' => $this->parent_phone,
            'Emergency Contact' => $this->emergency_contact,
            'Emergency Phone' => $this->emergency_phone,
            'Discount Applied' => $this->discount_applied ?: 'None',
            'Order Status' => ucfirst($this->order_status),
            'Event Status' => ucfirst($this->getEventStatus())
        ];
    }
    
    /**
     * Get coach-specific information
     * 
     * @return array Coach information
     */
    public function getCoachInfo() {
        return [
            'player_name' => $this->getFullName(),
            'age' => $this->getAgeAtEvent(),
            'medical_conditions' => $this->medical_conditions ?: 'None',
            'dietary_needs' => $this->dietary_needs ?: 'None',
            'emergency_contact' => $this->getEmergencyContact(),
            'special_notes' => $this->hasSpecialNeeds() ? 'Has special needs - see medical/dietary info' : 'No special requirements'
        ];
    }
    
    /**
     * Filter roster entries by criteria
     * 
     * @param array $criteria Filter criteria
     * @return bool Matches criteria
     */
    public function matches(array $criteria) {
        foreach ($criteria as $field => $value) {
            switch ($field) {
                case 'activity_type':
                    if (is_array($value)) {
                        if (!in_array($this->activity_type, $value)) {
                            return false;
                        }
                    } else {
                        if ($this->activity_type !== $value) {
                            return false;
                        }
                    }
                    break;
                    
                case 'venue':
                    if (is_array($value)) {
                        if (!in_array($this->venue, $value)) {
                            return false;
                        }
                    } else {
                        if (stripos($this->venue, $value) === false) {
                            return false;
                        }
                    }
                    break;
                    
                case 'season':
                    if ($this->season !== $value) {
                        return false;
                    }
                    break;
                    
                case 'region':
                    if (stripos($this->region, $value) === false) {
                        return false;
                    }
                    break;
                    
                case 'age_range':
                    $age = $this->getAgeAtEvent();
                    if ($age < $value['min'] || $age > $value['max']) {
                        return false;
                    }
                    break;
                    
                case 'gender':
                    if ($this->gender !== $value) {
                        return false;
                    }
                    break;
                    
                case 'has_special_needs':
                    if ($this->hasSpecialNeeds() !== (bool)$value) {
                        return false;
                    }
                    break;
                    
                case 'event_status':
                    if ($this->getEventStatus() !== $value) {
                        return false;
                    }
                    break;
                    
                case 'order_status':
                    if (is_array($value)) {
                        if (!in_array($this->order_status, $value)) {
                            return false;
                        }
                    } else {
                        if ($this->order_status !== $value) {
                            return false;
                        }
                    }
                    break;
                    
                case 'date_range':
                    if (isset($value['start'])) {
                        $start_date = new \DateTime($this->start_date);
                        $filter_start = new \DateTime($value['start']);
                        if ($start_date < $filter_start) {
                            return false;
                        }
                    }
                    
                    if (isset($value['end'])) {
                        $end_date = new \DateTime($this->end_date ?: $this->start_date);
                        $filter_end = new \DateTime($value['end']);
                        if ($end_date > $filter_end) {
                            return false;
                        }
                    }
                    break;
                    
                case 'name':
                    $full_name = strtolower($this->getFullName());
                    if (strpos($full_name, strtolower($value)) === false) {
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
     * Get unique identifier for this roster entry
     * 
     * @return string Unique identifier
     */
    public function getUniqueId() {
        return $this->order_id . '_' . $this->order_item_id . '_' . $this->player_index;
    }
    
    /**
     * Compare with another roster entry for equality
     * 
     * @param Roster $other Other roster entry
     * @return bool Are equal
     */
    public function equals(Roster $other) {
        return $this->order_id === $other->order_id && 
               $this->order_item_id === $other->order_item_id &&
               $this->player_index === $other->player_index;
    }
    
    /**
     * Check if this roster entry conflicts with another (same player, overlapping dates)
     * 
     * @param Roster $other Other roster entry
     * @return bool Has conflict
     */
    public function conflictsWith(Roster $other) {
        // Must be the same player to have a conflict
        if ($this->customer_id !== $other->customer_id || $this->player_index !== $other->player_index) {
            return false; // Different players can't conflict
        }
        
        // Check for date/time overlap
        $this_start = new \DateTime($this->start_date);
        $this_end = new \DateTime($this->end_date ?: $this->start_date);
        $other_start = new \DateTime($other->start_date);
        $other_end = new \DateTime($other->end_date ?: $other->start_date);
        
        // Same player, overlapping dates = conflict
        return ($this_start <= $other_end && $this_end >= $other_start);
    }
    
    /**
     * Get roster entry priority for sorting
     * 
     * @return int Priority value (lower = higher priority)
     */
    public function getPriority() {
        $priority = 0;
        
        // Active events get higher priority
        if ($this->isActive()) {
            $priority -= 100;
        }
        
        // Future events get medium priority
        if ($this->isFutureEvent()) {
            $priority -= 50;
        }
        
        // Special needs players get higher priority
        if ($this->hasSpecialNeeds()) {
            $priority -= 25;
        }
        
        // Camps get slightly higher priority than courses
        if ($this->isCamp()) {
            $priority -= 10;
        }
        
        return $priority;
    }
    
    /**
     * Validate roster entry with enhanced rules
     * 
     * @throws ValidationException If validation fails
     * @return bool Validation passed
     */
    public function validate() {
        // Run parent validation first
        parent::validate();
        
        // Custom validation rules
        $this->validateDates();
        $this->validateAgeGroup();
        $this->validateBookingType();
        
        return true;
    }
    
    /**
     * Validate date consistency
     * 
     * @throws ValidationException If dates are invalid
     * @return void
     */
    private function validateDates() {
        if (empty($this->start_date)) {
            throw new ValidationException('Start date is required');
        }
        
        $start_date = new \DateTime($this->start_date);
        $today = new \DateTime();
        
        // For courses, start date can be in the past (ongoing courses)
        // For camps, start date should generally be in the future unless it's a current camp
        if ($this->isCamp() && $start_date < $today->modify('-7 days')) {
            // Only warn if camp started more than a week ago
            // This allows for some flexibility in data entry
        }
        
        if (!empty($this->end_date)) {
            $end_date = new \DateTime($this->end_date);
            
            if ($end_date < $start_date) {
                throw new ValidationException('End date cannot be before start date');
            }
            
            // Check reasonable duration limits
            $duration = $start_date->diff($end_date)->days;
            if ($this->isCamp() && $duration > 7) {
                throw new ValidationException('Camp duration cannot exceed 7 days');
            }
            
            if ($this->isCourse() && $duration > 365) {
                throw new ValidationException('Course duration cannot exceed 1 year');
            }
        }
    }
    
    /**
     * Validate age group eligibility
     * 
     * @throws ValidationException If age group is invalid
     * @return void
     */
    private function validateAgeGroup() {
        if (empty($this->age_group) || empty($this->dob)) {
            return; // Skip if missing required data
        }
        
        $player = $this->getPlayer();
        
        if (!$player->isEligibleForAgeGroup($this->age_group, $this->start_date)) {
            $age_at_event = $this->getAgeAtEvent();
            throw new ValidationException(
                "Player age ({$age_at_event}) is not eligible for age group: {$this->age_group}"
            );
        }
    }
    
    /**
     * Validate booking type consistency
     * 
     * @throws ValidationException If booking type is invalid
     * @return void
     */
    private function validateBookingType() {
        if ($this->booking_type === self::BOOKING_SINGLE_DAYS) {
            $selected_days = $this->getSelectedDaysArray();
            
            if (empty($selected_days)) {
                throw new ValidationException('Selected days are required for single day bookings');
            }
            
            // Validate day names
            foreach ($selected_days as $day) {
                if (!in_array($day, self::DAYS_OF_WEEK)) {
                    throw new ValidationException("Invalid day name: {$day}");
                }
            }
        }
        
        // Validate booking type matches activity type
        if ($this->isCourse() && $this->booking_type === self::BOOKING_FULL_WEEK) {
            throw new ValidationException('Courses cannot use "Full Week" booking type');
        }
        
        if ($this->isCamp() && $this->booking_type === self::BOOKING_FULL_TERM) {
            throw new ValidationException('Camps cannot use "Full Term" booking type');
        }
    }
    
    /**
     * Generate roster entry summary for logging
     * 
     * @return array Roster summary for logs
     */
    public function getLogSummary() {
        return [
            'roster_id' => $this->getUniqueId(),
            'order_id' => $this->order_id,
            'player_name' => $this->getFullName(),
            'activity_type' => $this->activity_type,
            'venue' => $this->venue,
            'start_date' => $this->start_date,
            'customer_id' => $this->customer_id,
            'player_index' => $this->player_index
        ];
    }
    
    /**
     * Set selected days with normalization
     * 
     * @param mixed $value Selected days (string or array)
     * @return array Normalized selected days
     */
    public function setSelectedDaysAttribute($value) {
        if (empty($value)) {
            return null;
        }
        
        if (is_string($value)) {
            $days = explode(',', $value);
            $days = array_map('trim', $days);
        } else {
            $days = (array) $value;
        }
        
        // Filter out empty values and validate day names
        $valid_days = [];
        foreach ($days as $day) {
            $day = trim($day);
            if (!empty($day) && in_array($day, self::DAYS_OF_WEEK)) {
                $valid_days[] = $day;
            }
        }
        
        return !empty($valid_days) ? $valid_days : null;
    }
    
    /**
     * Set order status with normalization
     * 
     * @param string $value Order status
     * @return string Normalized order status
     */
    public function setOrderStatusAttribute($value) {
        $value = strtolower(trim($value));
        
        // Map common variations
        switch ($value) {
            case 'complete':
                return 'completed';
            case 'canceled':
                return 'cancelled';
            case 'refund':
                return 'refunded';
            case 'hold':
            case 'on_hold':
                return 'on-hold';
            default:
                return $value;
        }
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
        $array['age_at_event'] = $this->getAgeAtEvent();
        $array['event_status'] = $this->getEventStatus();
        $array['event_times'] = $this->getEventTimes();
        $array['duration_days'] = $this->getEventDurationDays();
        $array['formatted_duration'] = $this->getFormattedDuration();
        $array['formatted_selected_days'] = $this->getFormattedSelectedDays();
        $array['has_medical_conditions'] = $this->hasMedicalConditions();
        $array['has_dietary_needs'] = $this->hasDietaryNeeds();
        $array['has_special_needs'] = $this->hasSpecialNeeds();
        $array['is_active'] = $this->isActive();
        $array['is_future_event'] = $this->isFutureEvent();
        $array['is_past_event'] = $this->isPastEvent();
        $array['emergency_contact'] = $this->getEmergencyContact();
        $array['order_admin_url'] = $this->getOrderAdminUrl();
        $array['priority'] = $this->getPriority();
        
        return $array;
    }
    
    /**
     * Get event details as array
     * 
     * @return array Event details
     */
    public function getEventDetails() {
        if (empty($this->event_details)) {
            return [];
        }
        
        // If it's already an array, return it
        if (is_array($this->event_details)) {
            return $this->event_details;
        }
        
        // If it's JSON string, decode it
        if (is_string($this->event_details)) {
            $decoded = json_decode($this->event_details, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        
        return [];
    }
}