<?php
/**
 * Event Matcher Service
 * 
 * Matches WooCommerce products and variations to InterSoccer events.
 * Handles complex product attribute parsing and event type detection.
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
 * Event Matcher Service
 * 
 * Sophisticated matching system for WooCommerce products to InterSoccer events
 */
class EventMatcher {
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Product attribute cache
     * 
     * @var array
     */
    private $attribute_cache = [];
    
    /**
     * Event type patterns
     * 
     * @var array
     */
    private $event_patterns = [
        'camp' => [
            'activity_type' => ['Camp'],
            'booking_types' => ['Full Week', 'Single Day(s)'],
            'duration_pattern' => '/week|day|days/',
            'required_attributes' => ['venue', 'age_group', 'season']
        ],
        'course' => [
            'activity_type' => ['Course'],
            'booking_types' => ['Full Term'],
            'duration_pattern' => '/week|term|season/',
            'required_attributes' => ['venue', 'age_group', 'course_day']
        ],
        'birthday' => [
            'activity_type' => ['Birthday Party'],
            'booking_types' => ['Single Event'],
            'duration_pattern' => '/party|birthday/',
            'required_attributes' => ['venue', 'age_group']
        ]
    ];
    
    /**
     * Venue mappings
     * 
     * @var array
     */
    private $venue_mappings = [
        'geneva' => [
            'Geneva - Stade de Varembé (Nations)',
            'Geneva - Stade Chênois, Thonex'
        ],
        'basel' => [
            'Basel - Stadion Rankhof, Basel City'
        ],
        'zurich' => [
            'Zurich - Sportanlage Fronwald',
            'Zurich - Sportplatz Buchlern'
        ]
    ];
    
    /**
     * Season mappings
     * 
     * @var array
     */
    private $season_mappings = [
        'spring' => ['Spring', 'Frühling'],
        'summer' => ['Summer', 'Sommer'],
        'autumn' => ['Autumn', 'Fall', 'Herbst'],
        'winter' => ['Winter']
    ];
    
    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }
    
    /**
     * Match a WooCommerce product to an InterSoccer event type
     * 
     * @param \WC_Product $product WooCommerce product
     * @param int|null $variation_id Variation ID if applicable
     * @return array Event match data
     */
    public function matchProductToEvent(\WC_Product $product, $variation_id = null) {
        try {
            $this->logger->debug('Matching product to event', [
                'product_id' => $product->get_id(),
                'variation_id' => $variation_id,
                'product_name' => $product->get_name()
            ]);
            
            // Extract product attributes
            $attributes = $this->extractProductAttributes($product, $variation_id);
            
            // Determine event type
            $event_type = $this->determineEventType($attributes);
            
            // Validate event data
            $this->validateEventData($event_type, $attributes);
            
            // Build event data
            $event_data = $this->buildEventData($event_type, $attributes);
            
            $this->logger->debug('Product matched to event', [
                'product_id' => $product->get_id(),
                'event_type' => $event_type,
                'activity_type' => $event_data['activity_type'] ?? 'Unknown'
            ]);
            
            return $event_data;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to match product to event', [
                'product_id' => $product->get_id(),
                'variation_id' => $variation_id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Extract attributes from product and variation
     * 
     * @param \WC_Product $product WooCommerce product
     * @param int|null $variation_id Variation ID
     * @return array Product attributes
     */
    private function extractProductAttributes(\WC_Product $product, $variation_id = null) {
        $cache_key = $product->get_id() . '_' . ($variation_id ?: 'parent');
        
        if (isset($this->attribute_cache[$cache_key])) {
            return $this->attribute_cache[$cache_key];
        }
        
        $attributes = [];
        
        // Get base product attributes
        $product_attributes = $product->get_attributes();
        foreach ($product_attributes as $attribute_name => $attribute) {
            $clean_name = $this->cleanAttributeName($attribute_name);
            $values = $this->getAttributeValues($attribute);
            $attributes[$clean_name] = $values;
        }
        
        // Get variation-specific attributes if applicable
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation && $variation->is_type('variation')) {
                $variation_attributes = $variation->get_attributes();
                
                foreach ($variation_attributes as $attribute_name => $attribute_value) {
                    $clean_name = $this->cleanAttributeName($attribute_name);
                    $attributes[$clean_name] = is_array($attribute_value) ? $attribute_value : [$attribute_value];
                }
            }
        }
        
        // Extract attributes from product name and description
        $name_attributes = $this->extractAttributesFromText($product->get_name());
        $desc_attributes = $this->extractAttributesFromText($product->get_description());
        
        $attributes = array_merge($attributes, $name_attributes, $desc_attributes);
        
        // Parse and normalize attributes
        $attributes = $this->normalizeAttributes($attributes);
        
        $this->attribute_cache[$cache_key] = $attributes;
        
        return $attributes;
    }
    
    /**
     * Clean attribute name
     * 
     * @param string $attribute_name Raw attribute name
     * @return string Cleaned attribute name
     */
    private function cleanAttributeName($attribute_name) {
        // Remove WordPress attribute prefixes
        $clean = str_replace(['pa_', 'attribute_'], '', $attribute_name);
        
        // Convert to snake_case
        $clean = strtolower($clean);
        $clean = str_replace(['-', ' '], '_', $clean);
        
        // Map common attribute names
        $attribute_mappings = [
            'intersoccer_venues' => 'venue',
            'venues' => 'venue',
            'age_group' => 'age_group',
            'camp_terms' => 'event_terms',
            'course_day' => 'course_day',
            'booking_type' => 'booking_type',
            'activity_type' => 'activity_type',
            'canton_region' => 'region',
            'days_of_week' => 'course_day'
        ];
        
        return $attribute_mappings[$clean] ?? $clean;
    }
    
    /**
     * Get attribute values from WooCommerce attribute object
     * 
     * @param mixed $attribute WooCommerce attribute
     * @return array Attribute values
     */
    private function getAttributeValues($attribute) {
        if (is_object($attribute) && method_exists($attribute, 'get_options')) {
            $options = $attribute->get_options();
            
            // Convert term IDs to names if needed
            if ($attribute->is_taxonomy()) {
                $term_names = [];
                foreach ($options as $term_id) {
                    $term = get_term($term_id);
                    if ($term && !is_wp_error($term)) {
                        $term_names[] = $term->name;
                    }
                }
                return $term_names;
            }
            
            return is_array($options) ? $options : [$options];
        }
        
        if (is_array($attribute)) {
            return $attribute;
        }
        
        return [$attribute];
    }
    
    /**
     * Extract attributes from text using patterns
     * 
     * @param string $text Text to analyze
     * @return array Extracted attributes
     */
    private function extractAttributesFromText($text) {
        if (empty($text)) {
            return [];
        }
        
        $attributes = [];
        
        // Extract age groups
        if (preg_match('/(\d+)-(\d+)y?\s*(\(.+\))?/i', $text, $matches)) {
            $age_group = $matches[1] . '-' . $matches[2] . 'y';
            if (!empty($matches[3])) {
                $age_group .= ' ' . $matches[3];
            }
            $attributes['age_group'] = [$age_group];
        }
        
        // Extract seasons
        foreach ($this->season_mappings as $season_key => $season_names) {
            foreach ($season_names as $season_name) {
                if (stripos($text, $season_name) !== false) {
                    // Try to extract year as well
                    if (preg_match('/' . preg_quote($season_name, '/') . '\s*(\d{4})/i', $text, $matches)) {
                        $attributes['season'] = [$season_name . ' ' . $matches[1]];
                    } else {
                        $attributes['season'] = [$season_name . ' ' . date('Y')];
                    }
                    break 2;
                }
            }
        }
        
        // Extract activity types
        $activity_patterns = [
            'camp' => '/camp|holiday\s*camp/i',
            'course' => '/course|training|lesson/i',
            'birthday' => '/birthday|party/i'
        ];
        
        foreach ($activity_patterns as $activity => $pattern) {
            if (preg_match($pattern, $text)) {
                $activity_names = [
                    'camp' => 'Camp',
                    'course' => 'Course',
                    'birthday' => 'Birthday Party'
                ];
                $attributes['activity_type'] = [$activity_names[$activity]];
                break;
            }
        }
        
        // Extract days of week
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $found_days = [];
        
        foreach ($days as $day) {
            if (stripos($text, $day) !== false) {
                $found_days[] = $day;
            }
        }
        
        if (!empty($found_days)) {
            $attributes['course_day'] = $found_days;
        }
        
        return $attributes;
    }
    
    /**
     * Normalize attribute values
     * 
     * @param array $attributes Raw attributes
     * @return array Normalized attributes
     */
    private function normalizeAttributes(array $attributes) {
        $normalized = [];
        
        foreach ($attributes as $key => $values) {
            if (!is_array($values)) {
                $values = [$values];
            }
            
            $normalized_values = [];
            
            foreach ($values as $value) {
                $normalized_value = $this->normalizeAttributeValue($key, $value);
                if ($normalized_value !== null) {
                    $normalized_values[] = $normalized_value;
                }
            }
            
            if (!empty($normalized_values)) {
                $normalized[$key] = count($normalized_values) === 1 ? $normalized_values[0] : $normalized_values;
            }
        }
        
        return $normalized;
    }
    
    /**
     * Normalize individual attribute value
     * 
     * @param string $attribute_key Attribute key
     * @param mixed $value Raw value
     * @return mixed Normalized value
     */
    private function normalizeAttributeValue($attribute_key, $value) {
        if ($value === null || $value === '') {
            return null;
        }
        
        $value = trim($value);
        
        switch ($attribute_key) {
            case 'venue':
                return $this->normalizeVenue($value);
                
            case 'age_group':
                return $this->normalizeAgeGroup($value);
                
            case 'season':
                return $this->normalizeSeason($value);
                
            case 'activity_type':
                return $this->normalizeActivityType($value);
                
            case 'booking_type':
                return $this->normalizeBookingType($value);
                
            case 'course_day':
                return $this->normalizeCourseDay($value);
                
            case 'region':
                return $this->normalizeRegion($value);
                
            default:
                return $value;
        }
    }
    
    /**
     * Normalize venue name
     * 
     * @param string $venue Raw venue name
     * @return string Normalized venue name
     */
    private function normalizeVenue($venue) {
        // Remove extra spaces and normalize separators
        $venue = preg_replace('/\s+/', ' ', trim($venue));
        $venue = str_replace([' - ', ' — ', ' – '], ' - ', $venue);
        
        // Check against known venues
        foreach ($this->venue_mappings as $region => $venues) {
            foreach ($venues as $known_venue) {
                if (stripos($venue, $known_venue) !== false || stripos($known_venue, $venue) !== false) {
                    return $known_venue;
                }
            }
        }
        
        return $venue;
    }
    
    /**
     * Normalize age group
     * 
     * @param string $age_group Raw age group
     * @return string Normalized age group
     */
    private function normalizeAgeGroup($age_group) {
        // Standardize age group format
        $age_group = preg_replace('/\s+/', ' ', trim($age_group));
        
        // Convert various formats to standard format
        if (preg_match('/(\d+)\s*-\s*(\d+)\s*y?\s*(\(.+\))?/i', $age_group, $matches)) {
            $base = $matches[1] . '-' . $matches[2] . 'y';
            
            if (!empty($matches[3])) {
                $suffix = trim($matches[3]);
                
                // Normalize common suffixes
                $suffix_mappings = [
                    '(full day)' => '(Full Day)',
                    '(half day)' => '(Half Day)',
                    '(half-day)' => '(Half Day)',
                    '(full-day)' => '(Full Day)'
                ];
                
                $suffix_lower = strtolower($suffix);
                $suffix = $suffix_mappings[$suffix_lower] ?? $suffix;
                
                return $base . ' ' . $suffix;
            }
            
            return $base;
        }
        
        return $age_group;
    }
    
    /**
     * Normalize season
     * 
     * @param string $season Raw season
     * @return string Normalized season
     */
    private function normalizeSeason($season) {
        $season = trim($season);
        
        // Extract season and year
        if (preg_match('/(\w+)\s*(\d{4})?/', $season, $matches)) {
            $season_name = ucfirst(strtolower($matches[1]));
            $year = $matches[2] ?? date('Y');
            
            // Normalize season names
            $season_mappings = [
                'Spring' => 'Spring',
                'Frühling' => 'Spring',
                'Summer' => 'Summer', 
                'Sommer' => 'Summer',
                'Autumn' => 'Autumn',
                'Fall' => 'Autumn',
                'Herbst' => 'Autumn',
                'Winter' => 'Winter'
            ];
            
            $normalized_season = $season_mappings[$season_name] ?? $season_name;
            
            return $normalized_season . ' ' . $year;
        }
        
        return $season;
    }
    
    /**
     * Normalize activity type
     * 
     * @param string $activity_type Raw activity type
     * @return string Normalized activity type
     */
    private function normalizeActivityType($activity_type) {
        $activity_type = trim($activity_type);
        
        $mappings = [
            'camp' => 'Camp',
            'camps' => 'Camp',
            'holiday camp' => 'Camp',
            'course' => 'Course',
            'courses' => 'Course',
            'training' => 'Course',
            'lesson' => 'Course',
            'lessons' => 'Course',
            'birthday' => 'Birthday Party',
            'birthday party' => 'Birthday Party',
            'party' => 'Birthday Party'
        ];
        
        $lower = strtolower($activity_type);
        
        return $mappings[$lower] ?? $activity_type;
    }
    
    /**
     * Normalize booking type
     * 
     * @param string $booking_type Raw booking type
     * @return string Normalized booking type
     */
    private function normalizeBookingType($booking_type) {
        $booking_type = trim($booking_type);
        
        $mappings = [
            'full week' => 'Full Week',
            'whole week' => 'Full Week',
            'complete week' => 'Full Week',
            'single day' => 'Single Day(s)',
            'single days' => 'Single Day(s)',
            'individual days' => 'Single Day(s)',
            'selected days' => 'Single Day(s)',
            'full term' => 'Full Term',
            'complete term' => 'Full Term',
            'whole term' => 'Full Term',
            'season' => 'Full Term'
        ];
        
        $lower = strtolower($booking_type);
        
        return $mappings[$lower] ?? $booking_type;
    }
    
    /**
     * Normalize course day
     * 
     * @param string $day Raw day name
     * @return string Normalized day name
     */
    private function normalizeCourseDay($day) {
        $day = trim($day);
        
        $mappings = [
            'mon' => 'Monday',
            'monday' => 'Monday',
            'tue' => 'Tuesday', 
            'tues' => 'Tuesday',
            'tuesday' => 'Tuesday',
            'wed' => 'Wednesday',
            'wednesday' => 'Wednesday',
            'thu' => 'Thursday',
            'thur' => 'Thursday',
            'thurs' => 'Thursday',
            'thursday' => 'Thursday',
            'fri' => 'Friday',
            'friday' => 'Friday',
            'sat' => 'Saturday',
            'saturday' => 'Saturday',
            'sun' => 'Sunday',
            'sunday' => 'Sunday'
        ];
        
        $lower = strtolower($day);
        
        return $mappings[$lower] ?? ucfirst($day);
    }
    
    /**
     * Normalize region/canton
     * 
     * @param string $region Raw region
     * @return string Normalized region
     */
    private function normalizeRegion($region) {
        $region = trim($region);
        
        // Swiss canton mappings
        $canton_mappings = [
            'ge' => 'Geneva',
            'geneva' => 'Geneva',
            'genève' => 'Geneva',
            'bs' => 'Basel-Stadt',
            'basel' => 'Basel-Stadt',
            'basel-stadt' => 'Basel-Stadt',
            'zh' => 'Zurich',
            'zürich' => 'Zurich',
            'zurich' => 'Zurich',
            'be' => 'Bern',
            'bern' => 'Bern',
            'berne' => 'Bern'
        ];
        
        $lower = strtolower($region);
        
        return $canton_mappings[$lower] ?? $region;
    }
    
    /**
     * Determine event type from attributes
     * 
     * @param array $attributes Product attributes
     * @return string Event type
     */
    private function determineEventType(array $attributes) {
        $activity_type = $attributes['activity_type'] ?? null;
        
        if (empty($activity_type)) {
            throw new ValidationException('Cannot determine event type - no activity type found');
        }
        
        // Match against known patterns
        foreach ($this->event_patterns as $event_type => $pattern) {
            if (in_array($activity_type, $pattern['activity_type'])) {
                return $event_type;
            }
        }
        
        // Fallback based on product name/attributes
        if (isset($attributes['course_day'])) {
            return 'course';
        }
        
        if (isset($attributes['booking_type'])) {
            $booking_type = $attributes['booking_type'];
            if (in_array($booking_type, ['Full Week', 'Single Day(s)'])) {
                return 'camp';
            }
            if ($booking_type === 'Full Term') {
                return 'course';
            }
        }
        
        // Default fallback
        switch (strtolower($activity_type)) {
            case 'camp':
                return 'camp';
            case 'course':
                return 'course';
            case 'birthday party':
                return 'birthday';
            default:
                throw new ValidationException("Unknown activity type: {$activity_type}");
        }
    }
    
    /**
     * Validate event data completeness
     * 
     * @param string $event_type Event type
     * @param array $attributes Event attributes
     * @return void
     * @throws ValidationException If validation fails
     */
    private function validateEventData($event_type, array $attributes) {
        if (!isset($this->event_patterns[$event_type])) {
            throw new ValidationException("Unknown event type: {$event_type}");
        }
        
        $pattern = $this->event_patterns[$event_type];
        $missing_attributes = [];
        
        foreach ($pattern['required_attributes'] as $required_attr) {
            if (empty($attributes[$required_attr])) {
                $missing_attributes[] = $required_attr;
            }
        }
        
        if (!empty($missing_attributes)) {
            throw new ValidationException(
                "Missing required attributes for {$event_type}: " . implode(', ', $missing_attributes)
            );
        }
    }
    
    /**
     * Build event data structure
     * 
     * @param string $event_type Event type
     * @param array $attributes Event attributes
     * @return array Event data
     */
    private function buildEventData($event_type, array $attributes) {
        $event_data = [
            'event_type' => $event_type,
            'activity_type' => $attributes['activity_type'],
            'venue' => $attributes['venue'],
            'age_group' => $attributes['age_group'] ?? null,
            'season' => $attributes['season'] ?? null,
            'region' => $attributes['region'] ?? $this->extractRegionFromVenue($attributes['venue']),
            'city' => $this->extractCityFromVenue($attributes['venue']),
        ];
        
        // Add event-specific data
        switch ($event_type) {
            case 'camp':
                $event_data = array_merge($event_data, [
                    'booking_type' => $attributes['booking_type'] ?? 'Full Week',
                    'camp_times' => $attributes['camp_times'] ?? $attributes['times'] ?? null,
                    'selected_days' => $attributes['selected_days'] ?? null,
                    'camp_terms' => $attributes['event_terms'] ?? $attributes['camp_terms'] ?? null
                ]);
                break;
                
            case 'course':
                $event_data = array_merge($event_data, [
                    'booking_type' => $attributes['booking_type'] ?? 'Full Term',
                    'course_day' => $attributes['course_day'],
                    'course_times' => $attributes['course_times'] ?? $attributes['times'] ?? null,
                    'start_date' => $attributes['start_date'] ?? null,
                    'end_date' => $attributes['end_date'] ?? null
                ]);
                break;
                
            case 'birthday':
                $event_data = array_merge($event_data, [
                    'booking_type' => 'Single Event',
                    'party_duration' => $attributes['duration'] ?? null,
                    'party_time' => $attributes['times'] ?? null
                ]);
                break;
        }
        
        // Add any additional attributes
        $additional_fields = ['discount', 'notes', 'special_requirements'];
        foreach ($additional_fields as $field) {
            if (isset($attributes[$field])) {
                $event_data[$field] = $attributes[$field];
            }
        }
        
        return array_filter($event_data); // Remove null values
    }
    
    /**
     * Extract region from venue name
     * 
     * @param string $venue Venue name
     * @return string Region name
     */
    private function extractRegionFromVenue($venue) {
        foreach ($this->venue_mappings as $region => $venues) {
            foreach ($venues as $known_venue) {
                if (stripos($venue, $region) !== false || stripos($known_venue, $venue) !== false) {
                    return ucfirst($region);
                }
            }
        }
        
        // Extract from venue format: "City - Venue Name"
        if (preg_match('/^([^-]+)\s*-/', $venue, $matches)) {
            return trim($matches[1]);
        }
        
        return 'Unknown';
    }
    
    /**
     * Extract city from venue name
     * 
     * @param string $venue Venue name
     * @return string City name
     */
    private function extractCityFromVenue($venue) {
        // Extract from venue format: "City - Venue Name"
        if (preg_match('/^([^-]+)\s*-/', $venue, $matches)) {
            return trim($matches[1]);
        }
        
        return 'Unknown';
    }
    
    /**
     * Get event recommendations based on product data
     * 
     * @param \WC_Product $product WooCommerce product
     * @return array Event recommendations
     */
    public function getEventRecommendations(\WC_Product $product) {
        try {
            $attributes = $this->extractProductAttributes($product);
            
            $recommendations = [
                'detected_activity_type' => $attributes['activity_type'] ?? null,
                'suggested_event_type' => null,
                'confidence' => 'low',
                'missing_attributes' => [],
                'suggestions' => []
            ];
            
            // Try to determine event type
            try {
                $event_type = $this->determineEventType($attributes);
                $recommendations['suggested_event_type'] = $event_type;
                $recommendations['confidence'] = 'high';
                
                // Check for missing required attributes
                if (isset($this->event_patterns[$event_type])) {
                    $pattern = $this->event_patterns[$event_type];
                    foreach ($pattern['required_attributes'] as $required_attr) {
                        if (empty($attributes[$required_attr])) {
                            $recommendations['missing_attributes'][] = $required_attr;
                        }
                    }
                }
                
            } catch (\Exception $e) {
                $recommendations['confidence'] = 'low';
                $recommendations['suggestions'][] = 'Could not determine event type: ' . $e->getMessage();
            }
            
            // Provide suggestions for improvement
            if (empty($attributes['venue'])) {
                $recommendations['suggestions'][] = 'Add venue information (e.g., "Geneva - Stade de Varembé")';
            }
            
            if (empty($attributes['age_group'])) {
                $recommendations['suggestions'][] = 'Specify age group (e.g., "5-13y (Full Day)")';
            }
            
            if (empty($attributes['season'])) {
                $recommendations['suggestions'][] = 'Add season information (e.g., "Summer 2025")';
            }
            
            return $recommendations;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get event recommendations', [
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => $e->getMessage(),
                'confidence' => 'none'
            ];
        }
    }
    
    /**
     * Batch match multiple products
     * 
     * @param array $product_ids Array of product IDs
     * @return array Batch match results
     */
    public function batchMatchProducts(array $product_ids) {
        $results = [
            'total_products' => count($product_ids),
            'successful_matches' => 0,
            'failed_matches' => 0,
            'matches' => [],
            'errors' => []
        ];
        
        foreach ($product_ids as $product_id) {
            try {
                $product = wc_get_product($product_id);
                if (!$product) {
                    $results['errors'][] = "Product not found: {$product_id}";
                    $results['failed_matches']++;
                    continue;
                }
                
                $match_data = $this->matchProductToEvent($product);
                $results['matches'][$product_id] = $match_data;
                $results['successful_matches']++;
                
            } catch (\Exception $e) {
                $results['errors'][] = "Product {$product_id}: " . $e->getMessage();
                $results['failed_matches']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Clear attribute cache
     * 
     * @return void
     */
    public function clearCache() {
        $this->attribute_cache = [];
        $this->logger->debug('Event matcher cache cleared');
    }
    
    /**
     * Add custom venue mapping
     * 
     * @param string $region Region key
     * @param array $venues Array of venue names
     * @return void
     */
    public function addVenueMapping($region, array $venues) {
        $this->venue_mappings[$region] = array_merge(
            $this->venue_mappings[$region] ?? [],
            $venues
        );
        
        $this->logger->debug('Added venue mapping', [
            'region' => $region,
            'venues' => $venues
        ]);
    }
    
    /**
     * Get matching statistics
     * 
     * @return array Matching statistics
     */
    public function getMatchingStatistics() {
        return [
            'cached_products' => count($this->attribute_cache),
            'supported_event_types' => array_keys($this->event_patterns),
            'supported_regions' => array_keys($this->venue_mappings),
            'total_venues' => array_sum(array_map('count', $this->venue_mappings))
        ];
    }
    
    /**
     * Generate event signature for matching
     * 
     * Creates a unique signature from event data for comparison and deduplication
     * 
     * @param array $event_data Event data array
     * @return string Event signature hash
     */
    public function generate_signature(array $event_data) {
        // Extract key identifying fields
        $signature_fields = [
            'activity_type' => $event_data['activity_type'] ?? '',
            'venue' => $event_data['venue'] ?? '',
            'start_date' => $event_data['start_date'] ?? '',
            'age_group' => $event_data['age_group'] ?? '',
            'season' => $event_data['season'] ?? ''
        ];
        
        // Normalize fields
        $signature_fields = array_map(function($value) {
            return strtolower(trim($value));
        }, $signature_fields);
        
        // Sort for consistency
        ksort($signature_fields);
        
        // Generate hash
        $signature = md5(json_encode($signature_fields));
        
        $this->logger->debug('Generated event signature', [
            'fields' => $signature_fields,
            'signature' => $signature
        ]);
        
        return $signature;
    }
    
    /**
     * Check if two events match based on their signatures
     * 
     * @param array $event1 First event data
     * @param array $event2 Second event data
     * @return bool True if events match
     */
    public function matches(array $event1, array $event2) {
        $signature1 = $this->generate_signature($event1);
        $signature2 = $this->generate_signature($event2);
        
        $matches = ($signature1 === $signature2);
        
        $this->logger->debug('Event match comparison', [
            'signature1' => $signature1,
            'signature2' => $signature2,
            'matches' => $matches
        ]);
        
        return $matches;
    }
}