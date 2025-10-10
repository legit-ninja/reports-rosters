<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.24 // Incremented for grouping fix
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Start output buffering early
ob_start();

require_once dirname(__DIR__) . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

if (!function_exists('intersoccer_log_audit')) {
    /**
     * Logs an audit event (fallback implementation).
     *
     * @param string $action The action being logged.
     * @param string $message The log message.
     */
    function intersoccer_log_audit($action, $message) {
        error_log("InterSoccer Audit [$action]: $message");
    }
}

/**
 * Normalize phone number to standard format (e.g., +41xxxxxxxxx)
 *
 * @param string $phone The raw phone number.
 * @return string The normalized phone number or original if invalid or N/A.
 */
function intersoccer_normalize_phone_number($phone) {
    if (empty($phone) || $phone === 'N/A') {
        return 'N/A';
    }

    // Preserve + prefix and clean spaces, hyphens, dots, and parentheses
    $cleaned = preg_replace('/[\s\-\.\(\)]+/', '', $phone);
    // Only log cleaning for debugging if WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("InterSoccer: Cleaned phone number: {$phone} -> {$cleaned}");
    }

    // Handle invalid numbers first
    $reason = 'unknown';
    if (!preg_match('/^\+?\d+$/', $cleaned)) {
        $reason = 'non-numeric characters after cleaning';
    } elseif (strlen($cleaned) < 7) {
        $reason = 'too short';
    } elseif (strlen($cleaned) > 15) {
        $reason = 'too long';
    } elseif (preg_match('/^([0-1]{2,4})\1+$/', $cleaned)) {
        $reason = 'repetitive pattern (possible test data)';
    }
    if ($reason !== 'unknown') {
        // Only log invalid numbers if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("InterSoccer: Invalid phone number format: {$phone} (Reason: {$reason})");
        }
        return (string)$phone; // Return as string
    }

    // Handle 00 prefix (e.g., 0041795351346 -> +41795351346)
    if (strpos($cleaned, '00') === 0) {
        $cleaned = '+' . substr($cleaned, 2);
    }

    // Handle Swiss numbers starting with 07 (e.g., 0784027071 -> +41784027071)
    if (preg_match('/^0?7(\d{7,10})$/', $cleaned, $matches)) {
        $local_number = $matches[1];
        // Normalize to 9 digits
        if (strlen($local_number) > 9) {
            $local_number = substr($local_number, 0, 9); // Truncate to 9 digits
        } elseif (strlen($local_number) < 9) {
            $local_number = str_pad($local_number, 9, '0', STR_PAD_RIGHT); // Pad with zeros
        }
        $normalized = '+41' . $local_number;
        return (string)$normalized; // Ensure string type
    }

    // Handle Swiss numbers starting with +41 or 41 (e.g., +41789414742, +41 78 941 47 42)
    if (preg_match('/^\+?41(\d{7,12})$/', $cleaned, $matches)) {
        $local_number = $matches[1];
        // Normalize to 9 digits
        if (strlen($local_number) > 9) {
            $local_number = substr($local_number, 0, 9); // Truncate to 9 digits
        } elseif (strlen($local_number) < 9) {
            $local_number = str_pad($local_number, 9, '0', STR_PAD_RIGHT); // Pad with zeros
        }
        $normalized = '+41' . $local_number;
        return (string)$normalized; // Ensure string type
    }

    // Match other international numbers: +33xxxxxxxxx, 33xxxxxxxxxx, etc.
    if (preg_match('/^\+?(\d{1,3})(\d{7,12})$/', $cleaned, $matches)) {
        $country_code = '+' . $matches[1]; // Always prepend +
        $local_number = $matches[2];
        // Validate country code
        $valid_country_codes = ['33', '44', '40', '49', '39', '34', '31', '32', '971', '354', '1', '46'];
        if (!in_array($matches[1], $valid_country_codes)) {
            error_log("InterSoccer: Invalid country code for phone: {$phone}, assuming Swiss");
            $country_code = '+41';
            $local_number = $matches[1] . $matches[2]; // Treat entire number as local part
        }
        // Normalize local number to 9 digits
        if (strlen($local_number) > 9) {
            $local_number = substr($local_number, 0, 9); // Truncate to 9 digits
        } elseif (strlen($local_number) < 9) {
            $local_number = str_pad($local_number, 9, '0', STR_PAD_RIGHT); // Pad with zeros
        }
        $normalized = $country_code . $local_number;
        // Only log if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("InterSoccer: Normalized phone number: {$phone} -> {$normalized} (country code match)");
        }
        return (string)$normalized; // Ensure string type
    }

    // Handle ambiguous numbers with 7-12 digits (assume Swiss)
    if (preg_match('/^\d{7,12}$/', $cleaned, $matches)) {
        $local_number = $cleaned;
        $country_code = '+41'; // Assume Swiss
        // Normalize to 9 digits
        if (strlen($local_number) > 9) {
            $local_number = substr($local_number, 0, 9); // Truncate to 9 digits
        } elseif (strlen($local_number) < 9) {
            $local_number = str_pad($local_number, 9, '0', STR_PAD_RIGHT); // Pad with zeros
        }
        $normalized = $country_code . $local_number;
        error_log("InterSoccer: Normalized phone number: {$phone} -> {$normalized} (ambiguous match)");
        return (string)$normalized; // Ensure string type
    }

    // Fallback for any unhandled cases
    // Only log if debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("InterSoccer: Invalid phone number format: {$phone} (Reason: unhandled format)");
    }
    return (string)$phone; // Return as string
}

/**
 * Export roster data to Excel
 */
add_action('wp_ajax_intersoccer_export_roster', 'intersoccer_export_roster');
function intersoccer_export_roster() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        ob_end_clean();
        wp_send_json_error(__('You do not have permission to export rosters.', 'intersoccer-reports-rosters'));
    }
    // Only log if debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Export: Full POST data - ' . json_encode($_POST));
    }
    $use_fields = isset($_POST['use_fields']) ? (bool)$_POST['use_fields'] : false;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    $variation_ids_str = isset($_POST['variation_ids']) ? sanitize_text_field($_POST['variation_ids']) : '';
    $variation_ids = $variation_ids_str ? array_map('intval', explode(',', $variation_ids_str)) : [];
    $camp_terms = isset($_POST['camp_terms']) ? sanitize_text_field($_POST['camp_terms']) : '';
    $course_day = isset($_POST['course_day']) ? sanitize_text_field($_POST['course_day']) : '';
    $venue = isset($_POST['venue']) ? sanitize_text_field($_POST['venue']) : '';
    $age_group = isset($_POST['age_group']) ? sanitize_text_field($_POST['age_group']) : '';
    $times = isset($_POST['times']) ? sanitize_text_field($_POST['times']) : '';
    $girls_only = isset($_POST['girls_only']) ? intval($_POST['girls_only']) : 0;
    $activity_types_str = isset($_POST['activity_types']) ? sanitize_text_field($_POST['activity_types']) : '';
    $activity_types = $activity_types_str ? array_map('trim', explode(',', $activity_types_str)) : ['Camp', 'Course', 'Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only'];

    if (!$use_fields && empty($variation_ids)) {
        ob_end_clean();
        wp_send_json_error(__('No variation IDs or fields provided for export.', 'intersoccer-reports-rosters'));
    }
    
    // Additional validation for use_fields mode
    if ($use_fields && $variation_id <= 0 && empty($variation_ids) && empty($activity_types)) {
        ob_end_clean();
        wp_send_json_error(__('No variation ID, variation IDs, or activity types provided for field-based export.', 'intersoccer-reports-rosters'));
    }
    // Only log if debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer Export: Initial filters - activity_types: ' . json_encode($activity_types) . ', age_group: ' . $age_group . ', times: ' . $times);
    }
    // Increase memory limit for exports - use reasonable limits for shared hosting
    $current_limit = ini_get('memory_limit');
    $current_limit_bytes = wp_convert_hr_to_bytes($current_limit);
    
    // Only increase if current limit is less than 256MB
    if ($current_limit_bytes < 268435456) { // 256MB
        ini_set('memory_limit', '256M');
    }
    
    // Set reasonable execution time
    ini_set('max_execution_time', 180); // 3 minutes should be sufficient

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $query = "SELECT player_name, first_name, last_name, gender, parent_phone, parent_email, age, player_dob, medical_conditions, late_pickup, late_pickup_days, booking_type, day_presence, age_group, activity_type, product_name, camp_terms, course_day, venue, times, shirt_size, shorts_size, avs_number
                FROM $rosters_table";
    $where_clauses = [];
    $query_params = [];
    
    if ($use_fields) {
        // Prioritize variation_id filtering if provided (from roster details page)
        if ($variation_id > 0) {
            $where_clauses[] = $wpdb->prepare("variation_id = %d", $variation_id);
            $query_params[] = $variation_id;
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer Export: variation_id filter added: ' . $variation_id);
            }
        } elseif (!empty($variation_ids)) {
            // Handle variation_ids array (from courses/camps pages) - ONLY filter by these specific variations
            $placeholders = implode(',', array_fill(0, count($variation_ids), '%d'));
            $where_clauses[] = "variation_id IN ($placeholders)";
            $query_params = array_merge($query_params, $variation_ids);
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer Export: variation_ids filter added: ' . implode(',', $variation_ids));
            }
            // When using specific variation_ids, skip additional field-based filtering to avoid including players from other events
        } else {
            // Fallback to field-based filtering when no specific variation_ids provided
            if (!empty($activity_types)) {
                $like_conditions = [];
                foreach ($activity_types as $type) {
                    $like_conditions[] = $wpdb->prepare("activity_type LIKE %s", "%" . $wpdb->esc_like($type) . "%");
                    $query_params[] = "%" . $wpdb->esc_like($type) . "%";
                }
                // Require 'girls\' only' specifically if present
                if (in_array("girls\' only", $activity_types)) {
                    $where_clauses[] = $wpdb->prepare("activity_type LIKE %s", "%girls\' only%");
                    $query_params[] = "%girls\' only%";
                } else {
                    $where_clauses[] = "(" . implode(" OR ", $like_conditions) . ")";
                }
                // Only log if debugging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer Export: activity_types clause added - Clause: ' . $where_clauses[count($where_clauses) - 1]);
                }
            }
            if ($product_id > 0) {
                $where_clauses[] = $wpdb->prepare("product_id = %d", $product_id);
                $query_params[] = $product_id;
                // Only log if debugging
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('InterSoccer Export: Adding clause - ' . $where_clauses[count($where_clauses) - 1] . ' | Current params: ' . json_encode($query_params));
                }
            }
            // Add field-based filters only when not using specific variation_ids
            if ($camp_terms) {
                $where_clauses[] = $wpdb->prepare("(camp_terms = %s OR camp_terms LIKE %s OR (camp_terms IS NULL AND %s = 'N/A'))", $camp_terms, '%' . $wpdb->esc_like($camp_terms) . '%', $camp_terms);
                $query_params[] = $camp_terms;
                $query_params[] = '%' . $camp_terms . '%';
                $query_params[] = $camp_terms;
            }
            if ($course_day) {
                $where_clauses[] = $wpdb->prepare("(course_day = %s OR course_day LIKE %s OR (course_day IS NULL AND %s = 'N/A'))", $course_day, '%' . $wpdb->esc_like($course_day) . '%', $course_day);
                $query_params[] = $course_day;
                $query_params[] = '%' . $course_day . '%';
                $query_params[] = $course_day;
            }
            if ($venue) {
                $where_clauses[] = $wpdb->prepare("(venue = %s OR venue LIKE %s OR (venue IS NULL AND %s = 'N/A'))", $venue, '%' . $wpdb->esc_like($venue) . '%', $venue);
                $query_params[] = $venue;
                $query_params[] = '%' . $venue . '%';
                $query_params[] = $venue;
            }
            if ($age_group) {
                $where_clauses[] = $wpdb->prepare("(age_group = %s OR age_group LIKE %s)", $age_group, '%' . $wpdb->esc_like($age_group) . '%');
                $query_params[] = $age_group;
                $query_params[] = '%' . $age_group . '%';
            }
            if ($times) {
                $where_clauses[] = $wpdb->prepare("(times = %s OR (times IS NULL AND %s = 'N/A'))", $times, $times);
                $query_params[] = $times;
                $query_params[] = $times;
            }
            if ($girls_only) {
                $where_clauses[] = $wpdb->prepare("girls_only = %d", $girls_only);
                $query_params[] = $girls_only;
            }
        }
        // Only log if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Export: Before WHERE - Clauses: ' . json_encode($where_clauses));
            error_log('InterSoccer Export: Expected placeholders - ' . implode(',', array_fill(0, count($query_params), '%s')));
            error_log('InterSoccer Export: Applied filters - activity_type: ' . ($where_clauses[0] ?? 'N/A') . ', age_group: ' . ($where_clauses[1] ?? 'N/A') . ', times: ' . ($where_clauses[2] ?? 'N/A'));
        }
        
        // Add WHERE clause if there are conditions
        if (!empty($where_clauses)) {
            $query .= " WHERE " . implode(' AND ', $where_clauses);
        }
        
        $rosters = $wpdb->get_results($wpdb->prepare($query, $query_params), ARRAY_A);
        // Only log query execution details if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Export: After prepare - Executed query: ' . $wpdb->last_query . ' | Results count: ' . count($rosters) . ' | Last error: ' . $wpdb->last_error);
            error_log('InterSoccer Export: Post-execution params applied - First row activity_type: ' . ($rosters[0]['activity_type'] ?? 'N/A'));
            error_log('InterSoccer Export: Full first row - ' . json_encode($rosters[0] ?? 'No data'));
            error_log('InterSoccer Export: Expected placeholders - ' . implode(',', array_fill(0, count($query_params), '%s')));
        }
    } else {
        $query = $wpdb->prepare(
            "SELECT player_name, first_name, last_name, gender, parent_phone, parent_email, age, player_dob, medical_conditions, late_pickup, late_pickup_days, booking_type, day_presence, age_group, activity_type, product_name, camp_terms, course_day, venue, times, shirt_size, shorts_size, avs_number
             FROM $rosters_table
             WHERE variation_id IN (" . implode(',', array_fill(0, count($variation_ids), '%d')) . ")",
            $variation_ids
        );
        if ($product_id > 0) {
            $query .= $wpdb->prepare(" AND product_id = %d", $product_id);
        }
        if ($age_group) {
            $query .= $wpdb->prepare(" AND (age_group = %s OR age_group LIKE %s)", $age_group, '%' . $wpdb->esc_like($age_group) . '%');
        }
        $rosters = $wpdb->get_results($query, ARRAY_A);
    }

    // Only log if debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('InterSoccer: Export roster query: ' . $wpdb->last_query);
        error_log('InterSoccer: Export roster results count: ' . count($rosters));
        error_log('InterSoccer: Last SQL error: ' . $wpdb->last_error);
    }
    
    if ($rosters) {
        // Log sample data including player_dob
        $sample_data = array_map(function($row) {
            $day_presence = !empty($row['day_presence']) ? json_decode($row['day_presence'], true) : [];
            return [
                'player_name' => $row['player_name'],
                'player_dob' => $row['player_dob'] ?? 'NULL',
                'parent_phone' => $row['parent_phone'],
                'booking_type' => $row['booking_type'],
                'activity_type' => $row['activity_type'],
                'avs_number' => $row['avs_number'] ?? 'N/A'
            ];
        }, array_slice($rosters, 0, 1));
        // Only log if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Export roster data sample with player_dob: ' . json_encode($sample_data));
        }
    }

    if (empty($rosters)) {
        ob_end_clean();
        wp_send_json_error(__('No roster data found for export.', 'intersoccer-reports-rosters'));
    }

    // Prepare base roster and headers for both CSV and Excel exports
    $base_roster = $rosters[0];
    
    // Build headers based on activity type
    $common_headers = [
        'Player Name',
        'First Name',
        'Last Name',
        'Gender',
        'Parent Phone',
        'Parent Email',
        'Date of Birth',
        'Age',
        'Medical Conditions',
        'Age Group',
        'Activity Type',
        'Product Name',
        'Venue',
        'AVS Number'
    ];
    
    $headers = $common_headers;
    
    if ($base_roster['activity_type'] === 'Camp' || $base_roster['activity_type'] === 'Girls Only' || $base_roster['activity_type'] === 'Camp, Girls Only' || $base_roster['activity_type'] === 'Camp, Girls\' only') {
        // Add camp-specific headers
        $camp_headers = [
            'Late Pickup',
            'Late Pickup Days',
            'Booking Type',
            'Day Presence',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Camp Terms',
            'Times'
        ];
        $headers = array_merge($headers, $camp_headers);
    } elseif ($base_roster['activity_type'] === 'Course') {
        // Add course-specific headers
        $course_headers = [
            'Course Day',
            'Times'
        ];
        $headers = array_merge($headers, $course_headers);
    }
    
    // Add girls-specific headers if applicable
    if ($base_roster['activity_type'] === 'Girls Only' || $base_roster['activity_type'] === 'Camp, Girls Only' || $base_roster['activity_type'] === 'Camp, Girls\' only') {
        $headers[] = __('Shirt Size', 'intersoccer-reports-rosters');
        $headers[] = __('Shorts Size', 'intersoccer-reports-rosters');
    }

    // Prepare Excel data
    $filename = 'roster_' . sanitize_title($base_roster['product_name'] . '_' . ($base_roster['camp_terms'] ?: $base_roster['course_day']) . '_' . $base_roster['venue']) . '_' . date('Y-m-d_H-i-s') . '.xlsx';
    
    try {
        // Only log if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Starting Excel creation. Memory usage: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
        }
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet_title = substr(preg_replace('/[^A-Za-z0-9\-\s]/', '', $base_roster['product_name'] . ' - ' . $base_roster['venue']), 0, 31);
        $sheet->setTitle($sheet_title);

        $sheet->fromArray($headers, NULL, 'A1');

        // Set phone number column (D) to Text format and adjust width
        $phone_column = 'D';
        $sheet->getStyle($phone_column . '2:' . $phone_column . (count($rosters) + 1))
              ->getNumberFormat()
              ->setFormatCode('@');
        $sheet->getColumnDimension($phone_column)->setWidth(15);

        // Set date of birth column (G) to Date format
        $birth_date_column = 'G';
        $sheet->getStyle($birth_date_column . '2:' . $birth_date_column . (count($rosters) + 1))
              ->getNumberFormat()
              ->setFormatCode('dd/mm/yyyy');
        $sheet->getColumnDimension($birth_date_column)->setWidth(12);

        $row = 2;
        foreach ($rosters as $player) {
            $day_presence = !empty($player['day_presence']) ? json_decode($player['day_presence'], true) : [];
            $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
            $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
            $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
            $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
            $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
            
            // Normalize phone number
            $raw_phone = $player['parent_phone'] ?? 'N/A';
            $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone);
            $excel_phone = $processed_phone;

            // Format date of birth for Excel from player_dob
            $birth_date = $player['player_dob'] ?? '';
            $formatted_birth_date = 'N/A';
            if (!empty($birth_date) && $birth_date !== '0000-00-00' && $birth_date !== '1970-01-01') {
                // Try to parse various date formats
                $date_obj = DateTime::createFromFormat('Y-m-d', $birth_date);
                if (!$date_obj) {
                    $date_obj = DateTime::createFromFormat('d/m/Y', $birth_date);
                }
                if (!$date_obj) {
                    $date_obj = DateTime::createFromFormat('m/d/Y', $birth_date);
                }
                if ($date_obj) {
                    $formatted_birth_date = $date_obj->format('d/m/Y');
                } else {
                    error_log("InterSoccer: Could not parse date of birth: $birth_date for player: " . ($player['player_name'] ?? 'Unknown'));
                    $formatted_birth_date = $birth_date; // Use original if parsing fails
                }
            }

            // Build data array based on activity type
            $common_data = [
                ($player['player_name'] ?? '') . ' ' . ($player['first_name'] ?? ''), // Player Name
                $player['first_name'] ?? 'N/A',
                $player['last_name'] ?? 'N/A',
                $player['gender'] ?? 'N/A',
                $processed_phone,
                $player['parent_email'] ?? 'N/A',
                $formatted_birth_date, // Date of Birth
                $player['age'] ?? 'N/A', // Age
                $player['player_dob'] ?? 'N/A', // Medical Conditions
                $player['age_group'] ?? 'N/A',
                $player['activity_type'] ?? 'N/A',
                $player['product_name'] ?? 'N/A',
                $player['venue'] ?? 'N/A',
                $player['medical_conditions'] ?? 'N/A', // AVS Number
            ];
            
            $data = $common_data;
            
            if ($player['activity_type'] === 'Camp' || $player['activity_type'] === 'Girls Only' || $player['activity_type'] === 'Camp, Girls Only' || $player['activity_type'] === 'Camp, Girls\' only') {
                // Add camp-specific data
                $camp_data = [
                    ($player['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $player['late_pickup_days'] ?? 'N/A',
                    $player['booking_type'] ?? 'N/A',
                    '', // Day Presence (empty)
                    $monday,
                    $tuesday,
                    $wednesday,
                    $thursday,
                    $friday,
                    $player['camp_terms'] ?? 'N/A',
                    $player['times'] ?? 'N/A',
                ];
                $data = array_merge($data, $camp_data);
            } elseif ($player['activity_type'] === 'Course') {
                // Add course-specific data
                $course_data = [
                    $player['course_day'] ?? 'N/A',
                    $player['times'] ?? 'N/A',
                ];
                $data = array_merge($data, $course_data);
            }
            
            // Add girls-specific data if applicable
            if ($player['activity_type'] === 'Girls Only' || $player['activity_type'] === 'Camp, Girls Only' || $player['activity_type'] === 'Camp, Girls\' only') {
                $data[] = $player['shirt_size'] ?? 'N/A';
                $data[] = $player['shorts_size'] ?? 'N/A';
            }

            // Write data to Excel - Optimized for memory efficiency
            $col = 0;
            foreach ($data as $value) {
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
                if ($col == 4) { // Parent Phone column (0-based index)
                    $sheet->setCellValueExplicit($columnLetter . $row, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($columnLetter . $row, $value);
                }
                $col++;
            }

            error_log("InterSoccer: Set cell G{$row} to {$data[6]} (date of birth from player_dob: {$birth_date})");
            $row++;
        }

        // Add event details
        $sheet->setCellValue('A' . $row, 'Event Details:');
        $sheet->setCellValue('A' . ($row + 1), 'Product Name: ' . ($base_roster['product_name'] ?? 'N/A'));
        $sheet->setCellValue('A' . ($row + 2), 'Venue: ' . ($base_roster['venue'] ?? 'N/A'));
        $sheet->setCellValue('A' . ($row + 3), 'Age Group: ' . ($base_roster['age_group'] ?? 'N/A'));
        $sheet->setCellValue('A' . ($row + 4), 'Event: ' . ($base_roster['course_day'] ?: ($base_roster['camp_terms'] ?? 'N/A')));
        $sheet->setCellValue('A' . ($row + 5), 'Total Players: ' . count($rosters));

        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        // Only log if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Before Excel save. Memory usage: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
        }
        error_log('InterSoccer: Sending headers for roster export with dates of birth');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: max-age=0');
        header('Expires: 0');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        error_log('InterSoccer: Before Excel save. Memory usage: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
        $writer->save('php://output');
        intersoccer_log_audit('export_roster_excel', 'Exported for variation_ids: ' . implode(',', $variation_ids));
        ob_end_flush();
        exit;
    } catch (Exception $e) {
        error_log('InterSoccer: Excel export error: ' . $e->getMessage() . ' on line ' . $e->getLine() . '. Memory usage: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
        // Fallback to CSV export
        try {
            error_log('InterSoccer: Attempting CSV export as fallback');
            $filename_csv = 'roster_' . sanitize_title($base_roster['product_name'] . '_' . ($base_roster['camp_terms'] ?: $base_roster['course_day']) . '_' . $base_roster['venue']) . '_' . date('Y-m-d_H-i-s') . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename_csv . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');
            // Output BOM for UTF-8
            fwrite($output, "\xEF\xBB\xBF");

            // Output headers
            fputcsv($output, $headers, ';');

            foreach ($rosters as $player) {
                $day_presence = !empty($player['day_presence']) ? json_decode($player['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                
                // Normalize phone number
                $raw_phone = $player['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone);
                $excel_phone = $processed_phone;

                // Format date of birth for CSV from player_dob
                $birth_date = $player['player_dob'] ?? '';
                $formatted_birth_date = 'N/A';
                if (!empty($birth_date) && $birth_date !== '0000-00-00' && $birth_date !== '1970-01-01') {
                    // Try to parse various date formats
                    $date_obj = DateTime::createFromFormat('Y-m-d', $birth_date);
                    if (!$date_obj) {
                        $date_obj = DateTime::createFromFormat('d/m/Y', $birth_date);
                    }
                    if (!$date_obj) {
                        $date_obj = DateTime::createFromFormat('m/d/Y', $birth_date);
                    }
                    if ($date_obj) {
                        $formatted_birth_date = $date_obj->format('d/m/Y');
                    } else {
                        // Only log parsing errors if debugging
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("InterSoccer: Could not parse date of birth: {$birth_date}");
                        }
                        $formatted_birth_date = (string)$birth_date; // Fallback
                    }
                }

                $data = [
                    ($player['player_name'] ?? '') . ' ' . ($player['first_name'] ?? ''), // Player Name
                    $player['first_name'] ?? 'N/A',
                    $player['last_name'] ?? 'N/A',
                    $player['gender'] ?? 'N/A',
                    $processed_phone,
                    $player['parent_email'] ?? 'N/A',
                    $player['age'] ?? 'N/A',
                    $formatted_birth_date, // Birth Date
                    $player['player_dob'] ?? 'N/A', // Medical Conditions
                    ($player['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $player['late_pickup_days'] ?? 'N/A',
                    $player['booking_type'] ?? 'N/A',
                    $player['age_group'] ?? 'N/A',
                    $player['activity_type'] ?? 'N/A',
                    $player['product_name'] ?? 'N/A',
                    $player['camp_terms'] ?? 'N/A',
                    $player['course_day'] ?? 'N/A',
                    $player['venue'] ?? 'N/A',
                    $player['times'] ?? 'N/A',
                    $player['shirt_size'] ?? 'N/A',
                    $player['shorts_size'] ?? 'N/A',
                    $player['medical_conditions'] ?? 'N/A', // AVS Number
                ];

                // Write data to CSV
                fputcsv($output, $data, ';');
            }

            fclose($output);
            ob_end_flush();
            exit;
        } catch (Exception $e) {
            error_log('InterSoccer: CSV export error: ' . $e->getMessage());
            ob_end_clean();
            wp_send_json_error(__('Error generating export file.', 'intersoccer-reports-rosters'));
        }
    }
}
