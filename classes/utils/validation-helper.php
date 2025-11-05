<?php
/**
 * InterSoccer Validation Helper Utility
 * 
 * Provides validation methods for:
 * - User data validation
 * - Player metadata validation
 * - Order data validation
 * - Product variation validation
 * - Cache key validation
 * 
 * @package InterSoccer\ReportsRosters\Utils
 * @subpackage Utils
 * @version 1.0.0
 */

namespace InterSoccer\ReportsRosters\Utils;

if (!defined('ABSPATH')) {
    exit;
}

class ValidationHelper {

    /**
     * Valid age groups for InterSoccer programs
     * 
     * @var array
     */
    private static $valid_age_groups = [
        '3-4y', '3-5y', '3-6y', '3-7y', '3-8y', '3-9y', '3-10y', '3-12y',
        '4-5y', '5-7y', '5-8y', '5-13y', '6-7y', '6-8y', '6-9y', '6-10y',
        '7-9y', '8-12y',
        // With day specifications
        '3-5y (Half-Day)', '5-13y (Full Day)'
    ];

    /**
     * Valid seasons
     * 
     * @var array
     */
    private static $valid_seasons = [
        'Spring', 'Summer', 'Autumn', 'Winter',
        'spring', 'summer', 'autumn', 'winter'
    ];

    /**
     * Valid cantons/regions
     * 
     * @var array
     */
    private static $valid_cantons = [
        'Geneva', 'Basel-Stadt', 'Basel-Landschaft', 'Zurich', 'Vaud', 'Valais',
        'Bern', 'Lucerne', 'Uri', 'Schwyz', 'Obwalden', 'Nidwalden',
        'Glarus', 'Zug', 'Fribourg', 'Solothurn', 'Schaffhausen',
        'Appenzell Ausserrhoden', 'Appenzell Innerrhoden', 'St. Gallen',
        'Graubünden', 'Aargau', 'Thurgau', 'Ticino', 'Jura', 'Neuchâtel'
    ];

    /**
     * Valid activity types
     * 
     * @var array
     */
    private static $valid_activity_types = [
        'Camp', 'Course', 'Birthday', 'Girls Only', 'Community Event'
    ];

    /**
     * Valid booking types
     * 
     * @var array
     */
    private static $valid_booking_types = [
        'Full Week', 'Single Day(s)', 'Full Term', 'Half Term'
    ];

    /**
     * Valid genders
     * 
     * @var array
     */
    private static $valid_genders = [
        'male', 'female', 'other', 'prefer-not-to-say'
    ];

    /**
     * Validate WordPress user ID
     * 
     * @param mixed $user_id User ID to validate
     * 
     * @return bool True if valid user ID
     */
    public static function is_valid_user_id($user_id) {
        if (!is_numeric($user_id)) {
            return false;
        }
        
        $user_id = intval($user_id);
        return $user_id > 0 && get_user_by('ID', $user_id) !== false;
    }

    /**
     * Validate WooCommerce order ID
     * 
     * @param mixed $order_id Order ID to validate
     * 
     * @return bool True if valid order ID
     */
    public static function is_valid_order_id($order_id) {
        if (!is_numeric($order_id)) {
            return false;
        }
        
        $order_id = intval($order_id);
        if ($order_id <= 0) {
            return false;
        }
        
        // Check if WooCommerce is active
        if (!function_exists('wc_get_order')) {
            return false;
        }
        
        $order = wc_get_order($order_id);
        return $order !== false;
    }

    /**
     * Validate WooCommerce product ID
     * 
     * @param mixed $product_id Product ID to validate
     * 
     * @return bool True if valid product ID
     */
    public static function is_valid_product_id($product_id) {
        if (!is_numeric($product_id)) {
            return false;
        }
        
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return false;
        }
        
        $product = get_post($product_id);
        return $product && $product->post_type === 'product';
    }

    /**
     * Validate email address
     * 
     * @param string $email Email to validate
     * 
     * @return bool True if valid email
     */
    public static function is_valid_email($email) {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate phone number (Swiss format)
     * 
     * @param string $phone Phone number to validate
     * 
     * @return bool True if valid Swiss phone number
     */
    public static function is_valid_phone($phone) {
        if (!is_string($phone)) {
            return false;
        }
        
        // Remove all non-digit characters
        $clean_phone = preg_replace('/\D/', '', $phone);
        
        // Swiss phone numbers can be:
        // - 10 digits starting with 0 (domestic format)
        // - 11 digits starting with 41 (international format)
        // - Various mobile prefixes
        
        if (strlen($clean_phone) === 10 && substr($clean_phone, 0, 1) === '0') {
            return true;
        }
        
        if (strlen($clean_phone) === 11 && substr($clean_phone, 0, 2) === '41') {
            return true;
        }
        
        return false;
    }

    /**
     * Validate date string
     * 
     * @param string $date   Date string to validate
     * @param string $format Expected format (default: Y-m-d)
     * 
     * @return bool True if valid date
     */
    public static function is_valid_date($date, $format = 'Y-m-d') {
        if (!is_string($date)) {
            return false;
        }
        
        try {
            $datetime = \DateTime::createFromFormat($format, $date);
            return $datetime && $datetime->format($format) === $date;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate date of birth (reasonable age range)
     * 
     * @param string $dob Date of birth string
     * 
     * @return bool True if valid DOB for InterSoccer (age 2-18)
     */
    public static function is_valid_dob($dob) {
        if (!self::is_valid_date($dob)) {
            return false;
        }
        
        try {
            $birth_date = new \DateTime($dob);
            $today = new \DateTime();
            $age = $birth_date->diff($today)->y;
            
            // InterSoccer serves children aged 2-18
            return $age >= 2 && $age <= 18;
            
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate age group string
     * 
     * @param string $age_group Age group to validate
     * 
     * @return bool True if valid age group
     */
    public static function is_valid_age_group($age_group) {
        return is_string($age_group) && in_array($age_group, self::$valid_age_groups, true);
    }

    /**
     * Validate season string
     * 
     * @param string $season Season to validate
     * 
     * @return bool True if valid season
     */
    public static function is_valid_season($season) {
        return is_string($season) && in_array($season, self::$valid_seasons, true);
    }

    /**
     * Validate canton/region string
     * 
     * @param string $canton Canton to validate
     * 
     * @return bool True if valid canton
     */
    public static function is_valid_canton($canton) {
        return is_string($canton) && in_array($canton, self::$valid_cantons, true);
    }

    /**
     * Validate activity type
     * 
     * @param string $activity_type Activity type to validate
     * 
     * @return bool True if valid activity type
     */
    public static function is_valid_activity_type($activity_type) {
        return is_string($activity_type) && in_array($activity_type, self::$valid_activity_types, true);
    }

    /**
     * Validate booking type
     * 
     * @param string $booking_type Booking type to validate
     * 
     * @return bool True if valid booking type
     */
    public static function is_valid_booking_type($booking_type) {
        return is_string($booking_type) && in_array($booking_type, self::$valid_booking_types, true);
    }

    /**
     * Validate gender
     * 
     * @param string $gender Gender to validate
     * 
     * @return bool True if valid gender
     */
    public static function is_valid_gender($gender) {
        return is_string($gender) && in_array(strtolower($gender), self::$valid_genders, true);
    }

    /**
     * Validate player metadata structure
     * 
     * @param array $player_data Player data array
     * 
     * @return array Validation result with errors
     */
    public static function validate_player_data($player_data) {
        $errors = [];
        
        if (!is_array($player_data)) {
            return ['valid' => false, 'errors' => ['Player data must be an array']];
        }
        
        // Required fields
        $required_fields = ['first_name', 'last_name', 'dob', 'gender'];
        foreach ($required_fields as $field) {
            if (!isset($player_data[$field]) || empty($player_data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Validate individual fields
        if (isset($player_data['first_name'])) {
            if (!is_string($player_data['first_name']) || strlen($player_data['first_name']) < 1) {
                $errors[] = 'First name must be a non-empty string';
            }
        }
        
        if (isset($player_data['last_name'])) {
            if (!is_string($player_data['last_name']) || strlen($player_data['last_name']) < 1) {
                $errors[] = 'Last name must be a non-empty string';
            }
        }
        
        if (isset($player_data['dob'])) {
            if (!self::is_valid_dob($player_data['dob'])) {
                $errors[] = 'Invalid date of birth (must be Y-m-d format, age 2-18)';
            }
        }
        
        if (isset($player_data['gender'])) {
            if (!self::is_valid_gender($player_data['gender'])) {
                $errors[] = 'Invalid gender (must be: ' . implode(', ', self::$valid_genders) . ')';
            }
        }
        
        if (isset($player_data['avs_number'])) {
            if (!self::is_valid_avs_number($player_data['avs_number'])) {
                $errors[] = 'Invalid AVS number format';
            }
        }
        
        if (isset($player_data['medical_conditions'])) {
            if (!is_string($player_data['medical_conditions'])) {
                $errors[] = 'Medical conditions must be a string';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate Swiss AVS/AHV number (Social Security)
     * 
     * @param string $avs_number AVS number to validate
     * 
     * @return bool True if valid AVS number format
     */
    public static function is_valid_avs_number($avs_number) {
        if (!is_string($avs_number)) {
            return false;
        }
        
        // Swiss AVS numbers format: 756.YYYY.YYYY.XX
        // Remove dots and spaces
        $clean_avs = preg_replace('/[\.\s]/', '', $avs_number);
        
        // Must be 13 digits starting with 756
        if (!preg_match('/^756\d{10}$/', $clean_avs)) {
            return false;
        }
        
        // Basic checksum validation (simplified)
        return true;
    }

    /**
     * Validate cache key format
     * 
     * @param string $key Cache key to validate
     * 
     * @return bool True if valid cache key
     */
    public static function is_valid_cache_key($key) {
        if (!is_string($key) || empty($key)) {
            return false;
        }
        
        // Cache keys should be alphanumeric with underscores and hyphens
        return preg_match('/^[a-zA-Z0-9_\-]+$/', $key) && strlen($key) <= 255;
    }

    /**
     * Validate price value
     * 
     * @param mixed $price Price to validate
     * 
     * @return bool True if valid price
     */
    public static function is_valid_price($price) {
        if (!is_numeric($price)) {
            return false;
        }
        
        $price = floatval($price);
        return $price >= 0 && $price <= 10000; // Reasonable range for InterSoccer
    }

    /**
     * Validate order item data
     * 
     * @param array $order_item Order item data
     * 
     * @return array Validation result
     */
    public static function validate_order_item($order_item) {
        $errors = [];
        
        if (!is_array($order_item)) {
            return ['valid' => false, 'errors' => ['Order item must be an array']];
        }
        
        // Required fields for order items
        $required_fields = ['product_id', 'quantity', 'price'];
        foreach ($required_fields as $field) {
            if (!isset($order_item[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Validate product ID
        if (isset($order_item['product_id']) && !self::is_valid_product_id($order_item['product_id'])) {
            $errors[] = 'Invalid product ID';
        }
        
        // Validate quantity
        if (isset($order_item['quantity'])) {
            if (!is_numeric($order_item['quantity']) || intval($order_item['quantity']) < 1) {
                $errors[] = 'Quantity must be a positive integer';
            }
        }
        
        // Validate price
        if (isset($order_item['price']) && !self::is_valid_price($order_item['price'])) {
            $errors[] = 'Invalid price value';
        }
        
        // Validate optional fields
        if (isset($order_item['assigned_player']) && !is_numeric($order_item['assigned_player'])) {
            if ($order_item['assigned_player'] !== null) {
                $errors[] = 'Assigned player must be a numeric index or null';
            }
        }
        
        if (isset($order_item['activity_type']) && !self::is_valid_activity_type($order_item['activity_type'])) {
            $errors[] = 'Invalid activity type';
        }
        
        if (isset($order_item['age_group']) && !empty($order_item['age_group']) && !self::is_valid_age_group($order_item['age_group'])) {
            $errors[] = 'Invalid age group';
        }
        
        if (isset($order_item['season']) && !empty($order_item['season']) && !self::is_valid_season($order_item['season'])) {
            $errors[] = 'Invalid season';
        }
        
        if (isset($order_item['start_date']) && !empty($order_item['start_date']) && !self::is_valid_date($order_item['start_date'])) {
            $errors[] = 'Invalid start date format';
        }
        
        if (isset($order_item['end_date']) && !empty($order_item['end_date']) && !self::is_valid_date($order_item['end_date'])) {
            $errors[] = 'Invalid end date format';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate roster data structure
     * 
     * @param array $roster_data Roster data to validate
     * 
     * @return array Validation result
     */
    public static function validate_roster_data($roster_data) {
        $errors = [];
        
        if (!is_array($roster_data)) {
            return ['valid' => false, 'errors' => ['Roster data must be an array']];
        }
        
        // Required roster fields
        $required_fields = ['event_type', 'venue', 'attendees'];
        foreach ($required_fields as $field) {
            if (!isset($roster_data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Validate event type
        if (isset($roster_data['event_type']) && !self::is_valid_activity_type($roster_data['event_type'])) {
            $errors[] = 'Invalid event type';
        }
        
        // Validate venue
        if (isset($roster_data['venue'])) {
            if (!is_string($roster_data['venue']) || empty($roster_data['venue'])) {
                $errors[] = 'Venue must be a non-empty string';
            }
        }
        
        // Validate attendees array
        if (isset($roster_data['attendees'])) {
            if (!is_array($roster_data['attendees'])) {
                $errors[] = 'Attendees must be an array';
            } else {
                foreach ($roster_data['attendees'] as $index => $attendee) {
                    if (!is_array($attendee)) {
                        $errors[] = "Attendee at index {$index} must be an array";
                        continue;
                    }
                    
                    $attendee_validation = self::validate_attendee_data($attendee);
                    if (!$attendee_validation['valid']) {
                        $errors[] = "Attendee at index {$index}: " . implode(', ', $attendee_validation['errors']);
                    }
                }
            }
        }
        
        // Validate optional fields
        if (isset($roster_data['season']) && !empty($roster_data['season']) && !self::is_valid_season($roster_data['season'])) {
            $errors[] = 'Invalid season';
        }
        
        if (isset($roster_data['start_date']) && !empty($roster_data['start_date']) && !self::is_valid_date($roster_data['start_date'])) {
            $errors[] = 'Invalid start date';
        }
        
        if (isset($roster_data['end_date']) && !empty($roster_data['end_date']) && !self::is_valid_date($roster_data['end_date'])) {
            $errors[] = 'Invalid end date';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate attendee data structure
     * 
     * @param array $attendee_data Attendee data to validate
     * 
     * @return array Validation result
     */
    public static function validate_attendee_data($attendee_data) {
        $errors = [];
        
        if (!is_array($attendee_data)) {
            return ['valid' => false, 'errors' => ['Attendee data must be an array']];
        }
        
        // Required attendee fields
        $required_fields = ['first_name', 'last_name'];
        foreach ($required_fields as $field) {
            if (!isset($attendee_data[$field]) || empty($attendee_data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Validate names
        if (isset($attendee_data['first_name'])) {
            if (!is_string($attendee_data['first_name']) || strlen(trim($attendee_data['first_name'])) < 1) {
                $errors[] = 'First name must be a non-empty string';
            }
        }
        
        if (isset($attendee_data['last_name'])) {
            if (!is_string($attendee_data['last_name']) || strlen(trim($attendee_data['last_name'])) < 1) {
                $errors[] = 'Last name must be a non-empty string';
            }
        }
        
        // Validate optional fields
        if (isset($attendee_data['age_group']) && !empty($attendee_data['age_group']) && !self::is_valid_age_group($attendee_data['age_group'])) {
            $errors[] = 'Invalid age group';
        }
        
        if (isset($attendee_data['gender']) && !empty($attendee_data['gender']) && !self::is_valid_gender($attendee_data['gender'])) {
            $errors[] = 'Invalid gender';
        }
        
        if (isset($attendee_data['dob']) && !empty($attendee_data['dob']) && !self::is_valid_dob($attendee_data['dob'])) {
            $errors[] = 'Invalid date of birth';
        }
        
        if (isset($attendee_data['parent_email']) && !empty($attendee_data['parent_email']) && !self::is_valid_email($attendee_data['parent_email'])) {
            $errors[] = 'Invalid parent email';
        }
        
        if (isset($attendee_data['parent_phone']) && !empty($attendee_data['parent_phone']) && !self::is_valid_phone($attendee_data['parent_phone'])) {
            $errors[] = 'Invalid parent phone number';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate export configuration
     * 
     * @param array $export_config Export configuration array
     * 
     * @return array Validation result
     */
    public static function validate_export_config($export_config) {
        $errors = [];
        
        if (!is_array($export_config)) {
            return ['valid' => false, 'errors' => ['Export config must be an array']];
        }
        
        // Validate format
        $valid_formats = ['excel', 'csv', 'pdf'];
        if (isset($export_config['format'])) {
            if (!in_array($export_config['format'], $valid_formats, true)) {
                $errors[] = 'Invalid export format. Must be one of: ' . implode(', ', $valid_formats);
            }
        }
        
        // Validate event types filter
        if (isset($export_config['event_types'])) {
            if (!is_array($export_config['event_types'])) {
                $errors[] = 'Event types filter must be an array';
            } else {
                foreach ($export_config['event_types'] as $event_type) {
                    if (!self::is_valid_activity_type($event_type)) {
                        $errors[] = "Invalid event type in filter: {$event_type}";
                    }
                }
            }
        }
        
        // Validate date range
        if (isset($export_config['start_date']) && !empty($export_config['start_date'])) {
            if (!self::is_valid_date($export_config['start_date'])) {
                $errors[] = 'Invalid start date in export config';
            }
        }
        
        if (isset($export_config['end_date']) && !empty($export_config['end_date'])) {
            if (!self::is_valid_date($export_config['end_date'])) {
                $errors[] = 'Invalid end date in export config';
            }
        }
        
        // Validate columns if specified
        if (isset($export_config['columns'])) {
            if (!is_array($export_config['columns'])) {
                $errors[] = 'Export columns must be an array';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Sanitize string input
     * 
     * @param string $input     Input string
     * @param int    $max_length Maximum length (default: 255)
     * 
     * @return string Sanitized string
     */
    public static function sanitize_string($input, $max_length = 255) {
        if (!is_string($input)) {
            return '';
        }
        
        // Remove harmful characters and trim
        $sanitized = trim(strip_tags($input));
        
        // Limit length
        if (strlen($sanitized) > $max_length) {
            $sanitized = substr($sanitized, 0, $max_length);
        }
        
        return $sanitized;
    }

    /**
     * Sanitize array of strings
     * 
     * @param array $input Array to sanitize
     * @param int   $max_length Maximum string length
     * 
     * @return array Sanitized array
     */
    public static function sanitize_string_array($input, $max_length = 255) {
        if (!is_array($input)) {
            return [];
        }
        
        $sanitized = [];
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = self::sanitize_string($value, $max_length);
            }
        }
        
        return $sanitized;
    }

    /**
     * Validate and sanitize SQL ORDER BY clause
     * 
     * @param string $order_by ORDER BY string
     * @param array  $allowed_columns Allowed column names
     * 
     * @return string Safe ORDER BY clause
     */
    public static function sanitize_order_by($order_by, $allowed_columns) {
        if (!is_string($order_by) || empty($order_by)) {
            return '';
        }
        
        $parts = explode(',', $order_by);
        $safe_parts = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            
            // Split column and direction
            $order_parts = preg_split('/\s+/', $part);
            $column = $order_parts[0];
            $direction = isset($order_parts[1]) ? strtoupper($order_parts[1]) : 'ASC';
            
            // Validate column name
            if (in_array($column, $allowed_columns, true)) {
                // Validate direction
                if (in_array($direction, ['ASC', 'DESC'], true)) {
                    $safe_parts[] = $column . ' ' . $direction;
                }
            }
        }
        
        return implode(', ', $safe_parts);
    }

    /**
     * Validate WordPress capability for user
     * 
     * @param int    $user_id    User ID
     * @param string $capability Required capability
     * 
     * @return bool True if user has capability
     */
    public static function user_has_capability($user_id, $capability) {
        if (!self::is_valid_user_id($user_id)) {
            return false;
        }
        
        $user = get_user_by('ID', $user_id);
        return $user && $user->has_cap($capability);
    }

    /**
     * Validate and sanitize file name for exports
     * 
     * @param string $filename Proposed filename
     * 
     * @return string Safe filename
     */
    public static function sanitize_filename($filename) {
        if (!is_string($filename)) {
            return 'export_' . date('Y-m-d_H-i-s');
        }
        
        // Remove path traversal attempts
        $filename = basename($filename);
        
        // Remove or replace unsafe characters
        $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
        
        // Ensure it's not empty
        if (empty($filename)) {
            $filename = 'export_' . date('Y-m-d_H-i-s');
        }
        
        return $filename;
    }

    /**
     * Validate WordPress nonce
     * 
     * @param string $nonce  Nonce value
     * @param string $action Nonce action
     * 
     * @return bool True if valid nonce
     */
    public static function verify_nonce($nonce, $action) {
        return wp_verify_nonce($nonce, $action) !== false;
    }

    /**
     * Validate JSON structure
     * 
     * @param string $json JSON string to validate
     * 
     * @return array Validation result with decoded data
     */
    public static function validate_json($json) {
        if (!is_string($json)) {
            return ['valid' => false, 'error' => 'Input is not a string', 'data' => null];
        }
        
        $decoded = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'valid' => false,
                'error' => 'JSON decode error: ' . json_last_error_msg(),
                'data' => null
            ];
        }
        
        return [
            'valid' => true,
            'error' => null,
            'data' => $decoded
        ];
    }

    /**
     * Get validation summary for multiple validations
     * 
     * @param array $validations Array of validation results
     * 
     * @return array Summary of all validations
     */
    public static function get_validation_summary($validations) {
        $all_valid = true;
        $all_errors = [];
        
        foreach ($validations as $key => $validation) {
            if (is_array($validation) && isset($validation['valid'])) {
                if (!$validation['valid']) {
                    $all_valid = false;
                    if (isset($validation['errors']) && is_array($validation['errors'])) {
                        foreach ($validation['errors'] as $error) {
                            $all_errors[] = "{$key}: {$error}";
                        }
                    }
                }
            }
        }
        
        return [
            'all_valid' => $all_valid,
            'total_validations' => count($validations),
            'failed_validations' => count($validations) - ($all_valid ? count($validations) : 0),
            'errors' => $all_errors
        ];
    }
}