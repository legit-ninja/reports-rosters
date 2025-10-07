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
    $variation_ids = isset($_POST['variation_ids']) ? array_map('intval', (array)$_POST['variation_ids']) : [];
    $camp_terms = isset($_POST['camp_terms']) ? sanitize_text_field($_POST['camp_terms']) : '';
    $course_day = isset($_POST['course_day']) ? sanitize_text_field($_POST['course_day']) : '';
    $venue = isset($_POST['venue']) ? sanitize_text_field($_POST['venue']) : '';
    $age_group = isset($_POST['age_group']) ? sanitize_text_field($_POST['age_group']) : '';
    $times = isset($_POST['times']) ? sanitize_text_field($_POST['times']) : '';
    $activity_types_str = isset($_POST['activity_types']) ? sanitize_text_field($_POST['activity_types']) : '';
    $activity_types = $activity_types_str ? array_map('trim', explode(',', $activity_types_str)) : ['Camp', 'Course', 'Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only'];

    if (!$use_fields && empty($variation_ids)) {
        ob_end_clean();
        wp_send_json_error(__('No variation IDs or fields provided for export.', 'intersoccer-reports-rosters'));
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
    $query = "SELECT player_name, first_name, last_name, gender, parent_phone, parent_email, age, player_dob, medical_conditions, late_pickup, booking_type, day_presence, age_group, activity_type, product_name, camp_terms, course_day, venue, times, shirt_size, shorts_size, avs_number
                FROM $rosters_table";
    $where_clauses = [];
    $query_params = [];
    
    if ($use_fields) {
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
        if ($camp_terms) {
            $where_clauses[] = $wpdb->prepare("(camp_terms = %s OR camp_terms LIKE %s OR (camp_terms IS NULL AND %s = 'N/A'))", $camp_terms, '%' . $wpdb->esc_like($camp_terms) . '%', $camp_terms);
            $query_params[] = array_merge($query_params, [$camp_terms, '%' . $camp_terms . '%', $camp_terms]);
        }
        if ($course_day) {
            $where_clauses[] = $wpdb->prepare("(course_day = %s OR course_day LIKE %s OR (course_day IS NULL AND %s = 'N/A'))", $course_day, '%' . $wpdb->esc_like($course_day) . '%', $course_day);
            $query_params[] = array_merge($query_params, [$course_day, ]);
            $query_params[] = '%' . $course_day . '%';
            $query_params[] = $course_day;
        }
        if ($venue) {
            $where_clauses[] = $wpdb->prepare("(venue = %s OR venue LIKE %s OR (venue IS NULL AND %s = 'N/A'))", $venue, '%' . $wpdb->esc_like($venue) . '%', $venue);
            $query_params[] = array_merge($query_params, [$venue, '%' . $venue . '%', $venue]);
        }
        if ($age_group) {
            $where_clauses[] = $wpdb->prepare("(age_group = %s OR age_group LIKE %s)", $age_group, '%' . $wpdb->esc_like($age_group) . '%');
            $query_params[] = array_merge($query_params, [$age_group, '%' . $age_group . '%']);
        }
        if ($times) {
            $where_clauses[] = $wpdb->prepare("(times = %s OR (times IS NULL AND %s = 'N/A'))", $times, $times);
            $query_params[] = array_merge($query_params, [$times, $times]);
        }
        // Only log if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer Export: Before WHERE - Clauses: ' . json_encode($where_clauses));
            error_log('InterSoccer Export: Expected placeholders - ' . implode(',', array_fill(0, count($query_params), '%s')));
            error_log('InterSoccer Export: Applied filters - activity_type: ' . ($where_clauses[0] ?? 'N/A') . ', age_group: ' . ($where_clauses[1] ?? 'N/A') . ', times: ' . ($where_clauses[2] ?? 'N/A'));
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
            "SELECT player_name, first_name, last_name, gender, parent_phone, parent_email, age, player_dob, medical_conditions, late_pickup, booking_type, day_presence, age_group, activity_type, product_name, camp_terms, course_day, venue, times, shirt_size, shorts_size, avs_number
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

    // For very small rosters (1-2 participants), use CSV to avoid Excel memory overhead
    if (count($rosters) <= 2) {
        // Only log if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Using CSV export for very small roster (' . count($rosters) . ' participants)');
        }
        
        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

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
            $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;

            // Format birth date for CSV from player_dob
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
                        error_log("InterSoccer: Could not parse birth date: {$birth_date}");
                    }
                    $formatted_birth_date = (string)$birth_date; // Fallback
                }
            }

            $data = [
                $player['first_name'] ?? 'N/A',
                $player['last_name'] ?? 'N/A',
                $player['gender'] ?? 'N/A',
                $processed_phone,
                $player['parent_email'] ?? 'N/A',
                $player['age'] ?? 'N/A',
                $formatted_birth_date, // ADDED - Birth Date from player_dob
                $player['medical_conditions'] ?? 'N/A',
                $player['avs_number'] ?? 'N/A',
                ($player['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                $player['booking_type'] ?? 'N/A',
                $player['age_group'] ?? 'N/A',
                $player['product_name'] ?? 'N/A',
                $player['venue'] ?? 'N/A',
                $player['course_day'] ?: ($player['camp_terms'] ?? 'N/A')
            ];

            if ($base_roster['activity_type'] === 'Camp' || $base_roster['activity_type'] === 'Girls Only' || $base_roster['activity_type'] === 'Camp, Girls Only' || $base_roster['activity_type'] === 'Camp, Girls\' only') {
                $data = array_merge(
                    array_slice($data, 0, 11), // Adjust slice for added Birth Date (now after 6th: Age)
                    [$monday, $tuesday, $wednesday, $thursday, $friday],
                    array_slice($data, 11)
                );
                // Add times after event
                $data = array_merge(
                    array_slice($data, 0, count($data) - 2),
                    [$player['times'] ?? 'N/A'],
                    array_slice($data, count($data) - 2)
                );
            } else if ($base_roster['activity_type'] === 'Course') {
                // Add times after event
                $data[] = $player['times'] ?? 'N/A';
            }
            
            if ($base_roster['activity_type'] === 'Girls Only' || $base_roster['activity_type'] === 'Camp, Girls Only' || $base_roster['activity_type'] === 'Camp, Girls\' only') {
                $data[] = $player['shirt_size'] ?? 'N/A';
                $data[] = $player['shorts_size'] ?? 'N/A';
            }

            fputcsv($output, $data, ';');
        }

        fclose($output);
        intersoccer_log_audit('export_roster_csv', 'Exported for variation_ids: ' . implode(',', $variation_ids) . ' (very small roster, used CSV)');
        ob_end_flush();
        exit;
    }

    // For larger rosters, use Excel
    // Prepare Excel data
    $base_roster = $rosters[0];
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

        // UPDATED headers to include Birth Date
        $headers = [
            __('First Name', 'intersoccer-reports-rosters'),
            __('Surname', 'intersoccer-reports-rosters'),
            __('Gender', 'intersoccer-reports-rosters'),
            __('Phone', 'intersoccer-reports-rosters'),
            __('Email', 'intersoccer-reports-rosters'),
            __('Age', 'intersoccer-reports-rosters'),
            __('Birth Date', 'intersoccer-reports-rosters'), // ADDED
            __('Medical/Dietary Conditions', 'intersoccer-reports-rosters'),
            __('AVS Number', 'intersoccer-reports-rosters'),
            __('Late Pickup', 'intersoccer-reports-rosters'),
            __('Booking Type', 'intersoccer-reports-rosters'),
            __('Age Group', 'intersoccer-reports-rosters'),
            __('Product Name', 'intersoccer-reports-rosters'),
            __('Venue', 'intersoccer-reports-rosters'),
            __('Event', 'intersoccer-reports-rosters')
        ];

        if ($base_roster['activity_type'] === 'Girls Only' || $base_roster['activity_type'] === 'Camp, Girls Only' || $base_roster['activity_type'] === 'Camp, Girls\' only') {
            $headers[] = __('Shirt Size', 'intersoccer-reports-rosters');
            $headers[] = __('Shorts Size', 'intersoccer-reports-rosters');
        }
        if ($base_roster['activity_type'] === 'Camp' || $base_roster['activity_type'] === 'Girls Only' || $base_roster['activity_type'] === 'Camp, Girls Only' || $base_roster['activity_type'] === 'Camp, Girls\' only') {
            $headers = array_merge(
                array_slice($headers, 0, 11), // Adjust slice for added Birth Date (now after 6th: Age)
                [__('Monday', 'intersoccer-reports-rosters'), __('Tuesday', 'intersoccer-reports-rosters'), __('Wednesday', 'intersoccer-reports-rosters'), __('Thursday', 'intersoccer-reports-rosters'), __('Friday', 'intersoccer-reports-rosters')],
                array_slice($headers, 11)
            );
            // Add Times after Event for Camps
            $headers = array_merge(
                array_slice($headers, 0, count($headers) - 2),
                [__('Times', 'intersoccer-reports-rosters')],
                array_slice($headers, count($headers) - 2)
            );
        } else if ($base_roster['activity_type'] === 'Course') {
            // Add Times after Event for Courses
            $headers[] = __('Times', 'intersoccer-reports-rosters');
        }

        $sheet->fromArray($headers, NULL, 'A1');

        // Set phone number column (D) to Text format and adjust width
        $phone_column = 'D';
        $sheet->getStyle($phone_column . '2:' . $phone_column . (count($rosters) + 1))
              ->getNumberFormat()
              ->setFormatCode('@');
        $sheet->getColumnDimension($phone_column)->setWidth(15);

        // Set birth date column (G) to Date format
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
            $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;

            // Format birth date for Excel from player_dob
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
                    error_log("InterSoccer: Could not parse birth date: $birth_date for player: " . ($player['player_name'] ?? 'Unknown'));
                    $formatted_birth_date = $birth_date; // Use original if parsing fails
                }
            }

            $data = [
                $player['first_name'] ?? 'N/A',
                $player['last_name'] ?? 'N/A',
                $player['gender'] ?? 'N/A',
                $processed_phone,
                $player['parent_email'] ?? 'N/A',
                $player['age'] ?? 'N/A',
                $formatted_birth_date, // ADDED - Birth Date from player_dob
                $player['medical_conditions'] ?? 'N/A',
                $player['avs_number'] ?? 'N/A',
                ($player['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                $player['booking_type'] ?? 'N/A',
                $player['age_group'] ?? 'N/A',
                $player['product_name'] ?? 'N/A',
                $player['venue'] ?? 'N/A',
                $player['course_day'] ?: ($player['camp_terms'] ?? 'N/A')
            ];

            if ($player['activity_type'] === 'Camp' || $player['activity_type'] === 'Girls Only' || $player['activity_type'] === 'Camp, Girls Only' || $player['activity_type'] === 'Camp, Girls\' only') {
                $data = array_merge(
                    array_slice($data, 0, 11), // Up to and including booking_type
                    [$monday, $tuesday, $wednesday, $thursday, $friday],
                    array_slice($data, 11)
                );
                // Add times after event
                $data = array_merge(
                    array_slice($data, 0, count($data) - 2),
                    [$player['times'] ?? 'N/A'],
                    array_slice($data, count($data) - 2)
                );
            } else if ($player['activity_type'] === 'Course') {
                // Add times after event
                $data[] = $player['times'] ?? 'N/A';
            }
            
            if ($player['activity_type'] === 'Girls Only' || $player['activity_type'] === 'Camp, Girls Only' || $player['activity_type'] === 'Camp, Girls\' only') {
                $data[] = $player['shirt_size'] ?? 'N/A';
                $data[] = $player['shorts_size'] ?? 'N/A';
            }

            // Write data to Excel - Optimized for memory efficiency
            $col = 0;
            foreach ($data as $value) {
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
                $sheet->setCellValue($columnLetter . $row, $value);
                $col++;
            }

            error_log("InterSoccer: Set cell G{$row} to {$data[6]} (birth date from player_dob: {$birth_date})");
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
        error_log('InterSoccer: Sending headers for roster export with birth dates');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
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
                $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;

                // Format birth date for CSV from player_dob
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
                            error_log("InterSoccer: Could not parse birth date: {$birth_date}");
                        }
                        $formatted_birth_date = (string)$birth_date; // Fallback
                    }
                }

                $data = [
                    $player['first_name'] ?? 'N/A',
                    $player['last_name'] ?? 'N/A',
                    $player['gender'] ?? 'N/A',
                    $processed_phone,
                    $player['parent_email'] ?? 'N/A',
                    $player['age'] ?? 'N/A',
                    $formatted_birth_date, // ADDED - Birth Date from player_dob
                    $player['medical_conditions'] ?? 'N/A',
                    $player['avs_number'] ?? 'N/A',
                    ($player['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $player['booking_type'] ?? 'N/A',
                    $player['age_group'] ?? 'N/A',
                    $player['product_name'] ?? 'N/A',
                    $player['venue'] ?? 'N/A',
                    $player['course_day'] ?: ($player['camp_terms'] ?? 'N/A')
                ];

                if ($base_roster['activity_type'] === 'Camp' || $base_roster['activity_type'] === 'Girls Only' || $base_roster['activity_type'] === 'Camp, Girls Only' || $base_roster['activity_type'] === 'Camp, Girls\' only') {
                    $data = array_merge(
                        array_slice($data, 0, 11), // Adjust slice for added Birth Date (now after 6th: Age)
                        [$monday, $tuesday, $wednesday, $thursday, $friday],
                        array_slice($data, 11)
                    );
                    // Add times after event
                    $data = array_merge(
                        array_slice($data, 0, count($data) - 2),
                        [$player['times'] ?? 'N/A'],
                        array_slice($data, count($data) - 2)
                    );
                } else if ($base_roster['activity_type'] === 'Course') {
                    // Add times after event
                    $data[] = $player['times'] ?? 'N/A';
                }
                
                if ($base_roster['activity_type'] === 'Girls Only' || $base_roster['activity_type'] === 'Camp, Girls Only' || $base_roster['activity_type'] === 'Camp, Girls\' only') {
                    $data[] = $player['shirt_size'] ?? 'N/A';
                    $data[] = $player['shorts_size'] ?? 'N/A';
                }

                fputcsv($output, $data, ';');
            }

            fclose($output);
            intersoccer_log_audit('export_roster_csv', 'Exported for variation_ids: ' . implode(',', $variation_ids) . ' (small roster, used CSV)');
            ob_end_flush();
            exit;
        } catch (Exception $csv_e) {
            error_log('InterSoccer: CSV fallback export also failed: ' . $csv_e->getMessage() . ' on line ' . $csv_e->getLine());
            // If both Excel and CSV fail, show error to user
            wp_die(__('Export failed due to memory or system constraints. Please contact support.', 'intersoccer-reports-rosters'));
        }
    }
}

/**
 * Export all rosters
 */
function intersoccer_export_all_rosters($camps, $courses, $girls_only, $export_type = 'all', $format = 'excel') {
    try {
        $user_id = get_current_user_id();
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Export denied for user ID ' . $user_id . ' due to insufficient permissions.');
            }
            ob_end_clean();
            wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
        }
        // Increase memory limit for large exports
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 300); // 5 minutes timeout

        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: PhpSpreadsheet class not found in ' . __FILE__ . ' for export type ' . $export_type);
            }
            ob_end_clean();
            wp_die(__('PhpSpreadsheet missing.', 'intersoccer-reports-rosters'));
        }

        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        error_log('InterSoccer: Starting export for type ' . $export_type . ' by user ' . $user_id);

        $spreadsheet = new Spreadsheet();
        
        if ($export_type === 'all') {
            $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC", ARRAY_A);
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Retrieved ' . count($rosters) . ' rows for all rosters export by user ' . $user_id);
            }
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('All_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Birth Date', // ADDED
                'Medical/Dietary', 'AVS Number', 'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 
                'Wednesday', 'Thursday', 'Friday', 'Age Group', 'Product Name', 'Venue', 'Camp Terms', 
                'Course Day', 'Activity Type', 'Times'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            // Set phone number column (D) to Text format and adjust width
            $sheet->getStyle('D2:D' . (count($rosters) + 1))
                  ->getNumberFormat()
                  ->setFormatCode('@');
            $sheet->getColumnDimension('D')->setWidth(15);

            // Set birth date column (G) to Date format
            $sheet->getStyle('G2:G' . (count($rosters) + 1))
                  ->getNumberFormat()
                  ->setFormatCode('dd/mm/yyyy');
            $sheet->getColumnDimension('G')->setWidth(12);

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = !empty($roster['day_presence']) ? json_decode($roster['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                
                // Normalize phone number
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone);
                $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;

                // Format birth date for CSV from player_dob
                $birth_date = $roster['player_dob'] ?? '';
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
                            error_log("InterSoccer: Could not parse birth date: {$birth_date}");
                        }
                        $formatted_birth_date = (string)$birth_date; // Fallback
                    }
                }

                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $processed_phone,
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $formatted_birth_date, // ADDED - Birth Date from player_dob
                    $roster['medical_conditions'] ?? 'N/A',
                    $roster['avs_number'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['booking_type'] ?? 'N/A',
                    $roster['age_group'] ?? 'N/A',
                    $roster['product_name'] ?? 'N/A',
                    $roster['venue'] ?? 'N/A',
                    $roster['course_day'] ?? 'N/A',
                    $roster['camp_terms'] ?? 'N/A'
                ];

                // Write first three columns (A-C) explicitly
                $sheet->setCellValue('A' . $row, $data[0]);
                $sheet->setCellValue('B' . $row, $data[1]);
                $sheet->setCellValue('C' . $row, $data[2]);
                // Write phone number (D) explicitly
                $sheet->setCellValueExplicit('D' . $row, $excel_phone, DataType::TYPE_STRING);
                // Write columns E-F
                $sheet->setCellValue('E' . $row, $data[4]);
                $sheet->setCellValue('F' . $row, $data[5]);
                // Write birth date (G) explicitly
                $sheet->setCellValue('G' . $row, $data[6]);
                // Write remaining columns starting at H
                $other_data = array_slice($data, 7);
                $sheet->fromArray($other_data, NULL, 'H' . $row);
                
                $row++;
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));

        } elseif ($export_type === 'camps') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                WHERE activity_type = 'Camp' AND girls_only = 0
                ORDER BY updated_at DESC",
                ARRAY_A
            );
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Retrieved ' . count($rosters) . ' camp rosters for export by user ' . $user_id);
            }
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No camp roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Camp_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Birth Date', // ADDED
                'Medical/Dietary', 'AVS Number', 'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 
                'Wednesday', 'Thursday', 'Friday', 'Age Group', 'Camp Terms', 'Venue', 'Times'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            // Set phone number column (D) to Text format and adjust width
            $sheet->getStyle('D2:D' . (count($rosters) + 1))
                  ->getNumberFormat()
                  ->setFormatCode('@');
            $sheet->getColumnDimension('D')->setWidth(15);

            // Set birth date column (G) to Date format
            $sheet->getStyle('G2:G' . (count($rosters) + 1))
                  ->getNumberFormat()
                  ->setFormatCode('dd/mm/yyyy');
            $sheet->getColumnDimension('G')->setWidth(12);

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = !empty($roster['day_presence']) ? json_decode($roster['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                
                // Normalize phone number
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone);
                $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;

                // Format birth date for CSV from player_dob
                $birth_date = $roster['player_dob'] ?? '';
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
                            error_log("InterSoccer: Could not parse birth date: {$birth_date}");
                        }
                        $formatted_birth_date = (string)$birth_date; // Fallback
                    }
                }

                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $processed_phone,
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $formatted_birth_date, // ADDED - Birth Date from player_dob
                    $roster['medical_conditions'] ?? 'N/A',
                    $roster['avs_number'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['booking_type'] ?? 'N/A',
                    $monday,
                    $tuesday,
                    $wednesday,
                    $thursday,
                    $friday,
                    intersoccer_get_term_name($roster['age_group'], 'pa_age-group') ?? 'N/A',
                    $roster['camp_terms'] ?? 'N/A',
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A',
                    $roster['times'] ?? 'N/A'
                ];

                // Write data with explicit handling for phone and birth date
                $sheet->setCellValue('A' . $row, $data[0]);
                $sheet->setCellValue('B' . $row, $data[1]);
                $sheet->setCellValue('C' . $row, $data[2]);
                $sheet->setCellValueExplicit('D' . $row, $excel_phone, DataType::TYPE_STRING);
                $sheet->setCellValue('E' . $row, $data[4]);
                $sheet->setCellValue('F' . $row, $data[5]);
                $sheet->setCellValue('G' . $row, $data[6]); // Birth date
                $other_data = array_slice($data, 7);
                $sheet->fromArray($other_data, NULL, 'H' . $row);

                if ($roster['activity_type'] === 'Girls Only' || $roster['activity_type'] === 'Camp, Girls Only' || $roster['activity_type'] === 'Camp, Girls\' only') {
                    $extra_data = [
                        $roster['shirt_size'] ?? 'N/A',
                        $roster['shorts_size'] ?? 'N/A'
                    ];
                    $last_col = chr(ord('H') + count($other_data) - 1);
                    $sheet->fromArray($extra_data, NULL, chr(ord($last_col) + 1) . $row);
                }
                $row++;
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));

        } elseif ($export_type === 'courses') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                WHERE activity_type = 'Course' AND girls_only = 0
                ORDER BY updated_at DESC",
                ARRAY_A
            );
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Retrieved ' . count($rosters) . ' course rosters for export by user ' . $user_id);
            }
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No course roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Course_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Birth Date', // ADDED
                'Medical/Dietary', 'AVS Number', 'Late Pickup', 'Course Day', 'Course Times', 
                'Season', 'Age Group', 'Venue'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            // Set phone number column (D) to Text format and adjust width
            $sheet->getStyle('D2:D' . (count($rosters) + 1))
                  ->getNumberFormat()
                  ->setFormatCode('@');
            $sheet->getColumnDimension('D')->setWidth(15);

            // Set birth date column (G) to Date format
            $sheet->getStyle('G2:G' . (count($rosters) + 1))
                  ->getNumberFormat()
                  ->setFormatCode('dd/mm/yyyy');
            $sheet->getColumnDimension('G')->setWidth(12);

            $row = 2;
            foreach ($rosters as $roster) {
                $season = date('Y', strtotime($roster['start_date'] ?? '1970-01-01'));
                
                // Normalize phone number
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone);
                $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;

                // Format birth date from player_dob
                $birth_date = $roster['player_dob'] ?? '';
                $formatted_birth_date = 'N/A';
                if (!empty($birth_date) && $birth_date !== '0000-00-00' && $birth_date !== '1970-01-01') {
                    $date_obj = DateTime::createFromFormat('Y-m-d', $birth_date);
                    if ($date_obj) {
                        $formatted_birth_date = $date_obj->format('d/m/Y');
                    } else {
                        $formatted_birth_date = $birth_date;
                    }
                }

                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $processed_phone,
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $formatted_birth_date, // ADDED
                    $roster['medical_conditions'] ?? 'N/A',
                    $roster['avs_number'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['course_day'] ?? 'N/A',
                    $roster['times'] ?? 'N/A',
                    $season,
                    intersoccer_get_term_name($roster['age_group'], 'pa_age-group') ?? 'N/A',
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A'
                ];

                // Write data with explicit handling for phone and birth date
                $sheet->setCellValue('A' . $row, $data[0]);
                $sheet->setCellValue('B' . $row, $data[1]);
                $sheet->setCellValue('C' . $row, $data[2]);
                $sheet->setCellValueExplicit('D' . $row, $excel_phone, DataType::TYPE_STRING);
                $sheet->setCellValue('E' . $row, $data[4]);
                $sheet->setCellValue('F' . $row, $data[5]);
                $sheet->setCellValue('G' . $row, $data[6]); // Birth date
                $other_data = array_slice($data, 7);
                $sheet->fromArray($other_data, NULL, 'H' . $row);

                $row++;
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));

        } elseif ($export_type === 'girls_only_full_day') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE girls_only = 1
                 AND (age_group LIKE '%Full Day%' OR age_group LIKE '%full-day%') 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Retrieved ' . count($rosters) . ' full-day girls only rosters for export by user ' . $user_id);
            }
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No full-day girls only roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Full_Day_Girls_Only_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Birth Date', // ADDED
                'Medical/Dietary', 'AVS Number', 'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 
                'Wednesday', 'Thursday', 'Friday', 'Age Group', 'Event Name', 'Venue', 'Times', 
                'Camp Terms', 'Shirt Size', 'Shorts Size'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            // Set phone number and birth date column formatting
            $sheet->getStyle('D2:D' . (count($rosters) + 1))->getNumberFormat()->setFormatCode('@');
            $sheet->getColumnDimension('D')->setWidth(15);
            $sheet->getStyle('G2:G' . (count($rosters) + 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $sheet->getColumnDimension('G')->setWidth(12);

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = !empty($roster['day_presence']) ? json_decode($roster['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                
                // Normalize phone number and format birth date
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone);
                $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;

                $birth_date = $roster['player_dob'] ?? '';
                $formatted_birth_date = 'N/A';
                if (!empty($birth_date) && $birth_date !== '0000-00-00' && $birth_date !== '1970-01-01') {
                    $date_obj = DateTime::createFromFormat('Y-m-d', $birth_date);
                    if ($date_obj) {
                        $formatted_birth_date = $date_obj->format('d/m/Y');
                    } else {
                        $formatted_birth_date = $birth_date;
                    }
                }

                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $processed_phone,
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $formatted_birth_date, // ADDED - Birth Date from player_dob
                    $roster['medical_conditions'] ?? 'N/A',
                    $roster['avs_number'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['booking_type'] ?? 'N/A',
                    $monday,
                    $tuesday,
                    $wednesday,
                    $thursday,
                    $friday,
                    intersoccer_get_term_name($roster['age_group'], 'pa_age-group') ?? 'N/A',
                    $roster['product_name'] ?? 'N/A',
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A',
                    $roster['times'] ?? 'N/A'
                ];

                // Write data with explicit handling for phone and birth date
                $sheet->setCellValue('A' . $row, $data[0]);
                $sheet->setCellValue('B' . $row, $data[1]);
                $sheet->setCellValue('C' . $row, $data[2]);
                $sheet->setCellValueExplicit('D' . $row, $excel_phone, DataType::TYPE_STRING);
                $sheet->setCellValue('E' . $row, $data[4]);
                $sheet->setCellValue('F' . $row, $data[5]);
                $sheet->setCellValue('G' . $row, $data[6]); // Birth date
                $other_data = array_slice($data, 7);
                $sheet->fromArray($other_data, NULL, 'H' . $row);

                $row++;
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));

        } elseif ($export_type === 'girls_only_half_day') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE girls_only = 1
                 AND (age_group LIKE '%Half-Day%' OR age_group LIKE '%half-day%') 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Retrieved ' . count($rosters) . ' half-day girls only rosters for export by user ' . $user_id);
            }
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No half-day girls only roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Half_Day_Girls_Only_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'AVS Number', // Added
                'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 
                'Age Group', 'Event Name', 'Venue', 'Times', 'Camp Terms', 'Shirt Size', 'Shorts Size'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            // Set phone number column (D) to Text format and adjust width
            $sheet->getStyle('D2:D' . (count($rosters) + 1))
                  ->getNumberFormat()
                  ->setFormatCode('@'); // Use simple text format
            $sheet->getColumnDimension('D')->setWidth(15); // Set column width

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = !empty($roster['day_presence']) ? json_decode($roster['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                
                // Normalize phone number and format birth date
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone);
                $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;

                $birth_date = $roster['player_dob'] ?? '';
                $formatted_birth_date = 'N/A';
                if (!empty($birth_date) && $birth_date !== '0000-00-00' && $birth_date !== '1970-01-01') {
                    $date_obj = DateTime::createFromFormat('Y-m-d', $birth_date);
                    if ($date_obj) {
                        $formatted_birth_date = $date_obj->format('d/m/Y');
                    } else {
                        $formatted_birth_date = $birth_date;
                    }
                }

                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $processed_phone,
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $formatted_birth_date, // ADDED - Birth Date from player_dob
                    $roster['medical_conditions'] ?? 'N/A',
                    $roster['avs_number'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['booking_type'] ?? 'N/A',
                    $monday,
                    $tuesday,
                    $wednesday,
                    $thursday,
                    $friday,
                    intersoccer_get_term_name($roster['age_group'], 'pa_age-group') ?? 'N/A',
                    $roster['product_name'] ?? 'N/A',
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A',
                    $roster['times'] ?? 'N/A'
                ];

                // Write data with explicit handling for phone and birth date
                $sheet->setCellValue('A' . $row, $data[0]);
                $sheet->setCellValue('B' . $row, $data[1]);
                $sheet->setCellValue('C' . $row, $data[2]);
                $sheet->setCellValueExplicit('D' . $row, $excel_phone, DataType::TYPE_STRING);
                $sheet->setCellValue('E' . $row, $data[4]);
                $sheet->setCellValue('F' . $row, $data[5]);
                $sheet->setCellValue('G' . $row, $data[6]); // Birth date
                $other_data = array_slice($data, 7);
                $sheet->fromArray($other_data, NULL, 'H' . $row);

                $row++;
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));

        } elseif ($export_type === 'other') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE (activity_type NOT IN ('Camp', 'Course', 'Girls Only') OR activity_type IS NULL OR activity_type = 'unknown')
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            // Only log if debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('InterSoccer: Retrieved ' . count($rosters) . ' other event rosters for export by user ' . $user_id);
            }
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No other event roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Other_Event_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'AVS Number', // Added
                'Late Pickup', 'Booking Type', 'Age Group', 'Product Name', 'Venue', 'Times'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            // Set phone number column (D) to Text format and adjust width
            $sheet->getStyle('D2:D' . (count($rosters) + 1))
                  ->getNumberFormat()
                  ->setFormatCode('@'); // Use simple text format
            $sheet->getColumnDimension('D')->setWidth(15); // Set column width

            $row = 2;
            foreach ($rosters as $roster) {
                // Normalize phone number
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone); // Explicitly cast to string
                error_log("InterSoccer: Raw phone (other): {$raw_phone}, Normalized phone: {$processed_phone}");
                // Prepend space to force Excel to treat as text
                $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $processed_phone,
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $roster['medical_conditions'] ?? 'N/A',
                    $roster['avs_number'] ?? 'N/A', // Added
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['booking_type'] ?? 'N/A',
                    intersoccer_get_term_name($roster['age_group'], 'pa_age-group') ?? 'N/A',
                    $roster['product_name'] ?? 'N/A',
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A',
                    $roster['times'] ?? 'N/A'
                ];
                error_log("InterSoccer: Data array for row {$row}: " . json_encode($data));
                // Write first three columns (A-C) explicitly
                $sheet->setCellValue('A' . $row, $data[0]);
                $sheet->setCellValue('B' . $row, $data[1]);
                $sheet->setCellValue('C' . $row, $data[2]);
                // Write phone number (D) explicitly
                $sheet->setCellValueExplicit('D' . $row, $excel_phone, DataType::TYPE_STRING);
                // Write remaining columns starting at E
                $other_data = array_slice($data, 4);
                error_log("InterSoccer: Other data for row {$row}: " . json_encode($other_data));
                $sheet->fromArray($other_data, NULL, 'E' . $row);
                error_log("InterSoccer: Set cell D{$row} to {$excel_phone} (type: string)");
                $row++;
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        }

        // Only log if debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('InterSoccer: Exporting all rosters for type ' . $export_type . ' with ' . $sheet->getHighestRow() . ' rows');
        }

        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="intersoccer_' . ($export_type === 'master' ? 'all' : $export_type) . '_rosters_' . date('Y-m-d_H-i-s') . '.xlsx"');
        header('Cache-Control: max-age=0');
        header('Expires: 0');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        error_log('InterSoccer: Before Excel save. Memory usage: ' . memory_get_usage(true) / 1024 / 1024 . ' MB');
        $writer->save('php://output');
        intersoccer_log_audit('export_all_rosters_excel', 'Exported ' . $export_type . ' by user ' . $user_id);
        ob_end_flush();
    } catch (Exception $e) {
        error_log('InterSoccer: Export all rosters error in production for user ' . $user_id . ': ' . $e->getMessage() . ' on line ' . $e->getLine());
        ob_end_clean();
        wp_die(__('Export failed. Check server logs for details.', 'intersoccer-reports-rosters'));
    }
}

/**
 * AJAX handler for fetching event roster
 */
function intersoccer_reports_get_event_roster_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $filters = [
        'region' => isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '',
        'start_date' => isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '',
        'end_date' => isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '',
    ];

    if (!$product_id) {
        wp_send_json_error(['message' => __('Invalid product ID.', 'intersoccer-reports-rosters')]);
    }

    $roster = intersoccer_pe_get_event_roster($product_id, $filters); // Assuming this function exists or needs adjustment
    wp_send_json_success(['data' => $roster]);
}

/**
 * AJAX handler for fetching camp report
 */
function intersoccer_reports_get_camp_report_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';
    $week = isset($_POST['week']) ? sanitize_text_field($_POST['week']) : '';
    $camp_type = isset($_POST['camp_type']) ? sanitize_text_field($_POST['camp_type']) : '';
    $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : date('Y');

    $report = intersoccer_pe_get_camp_report_data($region, $week, $camp_type, $year); // Assuming this function exists
    wp_send_json_success(['data' => $report]);
}

/**
 * AJAX handler for exporting a single roster
 */
function intersoccer_reports_export_roster_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options') && !current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $variation_ids = isset($_POST['variation_ids']) ? array_map('intval', $_POST['variation_ids']) : [];
    if (empty($variation_ids)) {
        wp_send_json_error(['message' => __('Invalid variation IDs.', 'intersoccer-reports-rosters')]);
    }

 }

// ob_start();
// intersoccer_export_roster();
// ob_end_clean(); // Clean output buffer to prevent interference

// ob_start();
// intersoccer_export_roster();
// ob_end_clean(); // Clean output buffer to prevent interference
// wp_die(); // Ensure proper exit after export
/**
 * AJAX handler for exporting all rosters
 */
function intersoccer_reports_export_all_rosters_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options') && !current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $export_type = isset($_POST['export_type']) ? sanitize_text_field($_POST['export_type']) : 'all';

    // Log the export request
    error_log('InterSoccer: Export all rosters AJAX request - User ID: ' . get_current_user_id() . ', Export Type: ' . $export_type);

    // Call the export function directly
    ob_start();
    intersoccer_export_all_rosters(null, null, null, $export_type);
    ob_end_clean();
    wp_die();
}