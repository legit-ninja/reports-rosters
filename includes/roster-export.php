<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.3.117
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
    error_log("InterSoccer: Cleaned phone number: {$phone} -> {$cleaned}");

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
        error_log("InterSoccer: Invalid phone number format: {$phone} (Reason: {$reason})");
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
        error_log("InterSoccer: Normalized phone number: {$phone} -> {$normalized} (Swiss match)");
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
        error_log("InterSoccer: Normalized phone number: {$phone} -> {$normalized} (Swiss match)");
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
        error_log("InterSoccer: Normalized phone number: {$phone} -> {$normalized} (country code match)");
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
    error_log("InterSoccer: Invalid phone number format: {$phone} (Reason: unhandled format)");
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

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $variation_ids = isset($_POST['variation_ids']) ? array_map('intval', (array)$_POST['variation_ids']) : [];
    $age_group = isset($_POST['age_group']) ? sanitize_text_field($_POST['age_group']) : '';

    if (empty($variation_ids)) {
        ob_end_clean();
        wp_send_json_error(__('No variation IDs provided for export.', 'intersoccer-reports-rosters'));
    }

    // Increase memory limit for large exports
    ini_set('memory_limit', '256M');

    // Build query
    $query = $wpdb->prepare(
        "SELECT player_name, first_name, last_name, gender, parent_phone, parent_email, age, medical_conditions, late_pickup, booking_type, day_presence, age_group, activity_type, product_name, camp_terms, course_day, venue, times, shirt_size, shorts_size
         FROM $rosters_table
         WHERE variation_id IN (" . implode(',', array_fill(0, count($variation_ids), '%d')) . ")",
        $variation_ids
    );

    if ($age_group) {
        $query .= $wpdb->prepare(" AND (age_group = %s OR age_group LIKE %s)", $age_group, '%' . $wpdb->esc_like($age_group) . '%');
    }

    $rosters = $wpdb->get_results($query, ARRAY_A);
    error_log('InterSoccer: Export roster query: ' . $wpdb->last_query);
    error_log('InterSoccer: Export roster results count: ' . count($rosters));
    error_log('InterSoccer: Last SQL error: ' . $wpdb->last_error);
    if ($rosters) {
        error_log('InterSoccer: Export roster data sample: ' . json_encode(array_map(function($row) {
            $day_presence = !empty($row['day_presence']) ? json_decode($row['day_presence'], true) : [];
            return [
                'player_name' => $row['player_name'],
                'parent_phone' => $row['parent_phone'],
                'booking_type' => $row['booking_type'],
                'shirt_size' => $row['shirt_size'],
                'shorts_size' => $row['shorts_size'],
                'day_presence' => $day_presence,
                'activity_type' => $row['activity_type']
            ];
        }, array_slice($rosters, 0, 1))));
    }

    if (empty($rosters)) {
        ob_end_clean();
        wp_send_json_error(__('No roster data found for export.', 'intersoccer-reports-rosters'));
    }

    // Prepare Excel data
    $base_roster = $rosters[0];
    $filename = 'roster_' . sanitize_title($base_roster['product_name'] . '_' . ($base_roster['camp_terms'] ?: $base_roster['course_day']) . '_' . $base_roster['venue']) . '_' . date('Y-m-d_H-i-s') . '.xlsx';
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet_title = substr(preg_replace('/[^A-Za-z0-9\-\s]/', '', $base_roster['product_name'] . ' - ' . $base_roster['venue']), 0, 31);
    $sheet->setTitle($sheet_title);

    $headers = [
        __('First Name', 'intersoccer-reports-rosters'),
        __('Surname', 'intersoccer-reports-rosters'),
        __('Gender', 'intersoccer-reports-rosters'),
        __('Phone', 'intersoccer-reports-rosters'),
        __('Email', 'intersoccer-reports-rosters'),
        __('Age', 'intersoccer-reports-rosters'),
        __('Medical/Dietary Conditions', 'intersoccer-reports-rosters'),
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
            array_slice($headers, 0, 9),
            [__('Monday', 'intersoccer-reports-rosters'), __('Tuesday', 'intersoccer-reports-rosters'), __('Wednesday', 'intersoccer-reports-rosters'), __('Thursday', 'intersoccer-reports-rosters'), __('Friday', 'intersoccer-reports-rosters')],
            array_slice($headers, 9)
        );
        // Add Times after Event for Camps
        $headers = array_merge(
            array_slice($headers, 0, count($headers) - 2), // Before last two if girls only, but adjust
            [__('Times', 'intersoccer-reports-rosters')],
            array_slice($headers, count($headers) - 2)
        );
    } else if ($base_roster['activity_type'] === 'Course') {
        // Add Times after Event for Courses
        $headers[] = __('Times', 'intersoccer-reports-rosters');
    }

    $sheet->fromArray($headers, NULL, 'A1');

    // Set phone number column (D) to Text format and adjust width
    $phone_column = 'D'; // Phone is the 4th column (index 3)
    $sheet->getStyle($phone_column . '2:' . $phone_column . (count($rosters) + 1))
          ->getNumberFormat()
          ->setFormatCode('@'); // Use simple text format
    $sheet->getColumnDimension($phone_column)->setWidth(15); // Set column width

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
        $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone); // Explicitly cast to string
        error_log("InterSoccer: Raw phone: {$raw_phone}, Normalized phone: {$processed_phone}");
        // Prepend space to force Excel to treat as text
        $excel_phone = $processed_phone !== 'N/A' ? ' ' . $processed_phone : $processed_phone;
        $data = [
            $player['first_name'] ?? 'N/A',
            $player['last_name'] ?? 'N/A',
            $player['gender'] ?? 'N/A',
            $processed_phone,
            $player['parent_email'] ?? 'N/A',
            $player['age'] ?? 'N/A',
            $player['medical_conditions'] ?? 'N/A',
            ($player['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
            $player['booking_type'] ?? 'N/A',
            intersoccer_get_term_name($player['age_group'], 'pa_age-group') ?? 'N/A',
            $player['product_name'] ?? 'N/A',
            intersoccer_get_term_name($player['venue'], 'pa_intersoccer-venues') ?? 'N/A',
            $player['course_day'] ?: ($player['camp_terms'] ?? 'N/A')
        ];
        error_log("InterSoccer: Data array for row {$row}: " . json_encode($data));
        if ($player['activity_type'] === 'Camp' || $player['activity_type'] === 'Girls Only' || $player['activity_type'] === 'Camp, Girls Only' || $player['activity_type'] === 'Camp, Girls\' only') {
            $data = array_merge(
                array_slice($data, 0, 9),
                [$monday, $tuesday, $wednesday, $thursday, $friday],
                array_slice($data, 9)
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

    error_log('InterSoccer: Sending headers for roster export');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Expires: 0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    intersoccer_log_audit('export_roster_excel', 'Exported for variation_ids: ' . implode(',', $variation_ids));
    ob_end_flush();
    exit;
}

/**
 * Export all rosters
 */
function intersoccer_export_all_rosters($camps, $courses, $girls_only, $export_type = 'all', $format = 'excel') {
    try {
        $user_id = get_current_user_id();
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            error_log('InterSoccer: Export denied for user ID ' . $user_id . ' due to insufficient permissions.');
            ob_end_clean();
            wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
        }

        // Increase memory limit for large exports
        ini_set('memory_limit', '256M');

        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            error_log('InterSoccer: PhpSpreadsheet class not found in ' . __FILE__ . ' for export type ' . $export_type);
            ob_end_clean();
            wp_die(__('PhpSpreadsheet missing.', 'intersoccer-reports-rosters'));
        }

        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        error_log('InterSoccer: Starting export for type ' . $export_type . ' by user ' . $user_id);

        $spreadsheet = new Spreadsheet();
        if ($export_type === 'all') {
            $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC", ARRAY_A);
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' rows for all rosters export by user ' . $user_id);
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('All_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 
                'Age Group', 'Product Name', 'Venue', 'Camp Terms', 'Course Day', 'Activity Type', 'Times'
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
                // Normalize phone number
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone); // Explicitly cast to string
                error_log("InterSoccer: Raw phone (all rosters): {$raw_phone}, Normalized phone: {$processed_phone}");
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
                    $roster['camp_terms'] ?? 'N/A',
                    $roster['course_day'] ?? 'N/A',
                    $roster['activity_type'] ?? 'N/A',
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
        } elseif ($export_type === 'camps') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE activity_type = 'Camp' 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' camp rosters for export by user ' . $user_id);
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No camp roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Camp_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 
                'Age Group', 'Camp Terms', 'Venue', 'Times'
            ];
            if (isset($rosters[0]['activity_type']) && ($rosters[0]['activity_type'] === 'Girls Only' || $rosters[0]['activity_type'] === 'Camp, Girls Only' || $rosters[0]['activity_type'] === 'Camp, Girls\' only')) {
                $headers = array_merge($headers, ['Shirt Size', 'Shorts Size']);
            }
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
                // Normalize phone number
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone); // Explicitly cast to string
                error_log("InterSoccer: Raw phone (camps): {$raw_phone}, Normalized phone: {$processed_phone}");
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
                if ($roster['activity_type'] === 'Girls Only' || $roster['activity_type'] === 'Camp, Girls Only' || $roster['activity_type'] === 'Camp, Girls\' only') {
                    $data = array_merge($data, [
                        $roster['shirt_size'] ?? 'N/A',
                        $roster['shorts_size'] ?? 'N/A'
                    ]);
                }
                $row++;
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'courses') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE activity_type = 'Course' 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' course rosters for export by user ' . $user_id);
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No course roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Course_Rosters');
            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Course Day', 'Course Times', 'Season', 'Age Group', 'Venue'];
            $sheet->fromArray($headers, NULL, 'A1');

            // Set phone number column (D) to Text format and adjust width
            $sheet->getStyle('D2:D' . (count($rosters) + 1))
                  ->getNumberFormat()
                  ->setFormatCode('@'); // Use simple text format
            $sheet->getColumnDimension('D')->setWidth(15); // Set column width

            $row = 2;
            foreach ($rosters as $roster) {
                $season = date('Y', strtotime($roster['start_date'] ?? '1970-01-01'));
                // Normalize phone number
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone); // Explicitly cast to string
                error_log("InterSoccer: Raw phone (courses): {$raw_phone}, Normalized phone: {$processed_phone}");
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
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['course_day'] ?? 'N/A',
                    $roster['times'] ?? 'N/A',
                    $season,
                    intersoccer_get_term_name($roster['age_group'], 'pa_age-group') ?? 'N/A',
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A'
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
        } elseif ($export_type === 'girls_only_full_day') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE activity_type IN ('Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only') 
                 AND (age_group LIKE '%Full Day%' OR age_group LIKE '%full-day%') 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' full-day girls only rosters for export by user ' . $user_id);
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No full-day girls only roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Full_Day_Girls_Only_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
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
                // Normalize phone number
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone); // Explicitly cast to string
                error_log("InterSoccer: Raw phone (girls_only_full_day): {$raw_phone}, Normalized phone: {$processed_phone}");
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
                    $roster['times'] ?? 'N/A',
                    $roster['camp_terms'] ?? 'N/A',
                    $roster['shirt_size'] ?? 'N/A',
                    $roster['shorts_size'] ?? 'N/A'
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
        } elseif ($export_type === 'girls_only_half_day') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE activity_type IN ('Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only') 
                 AND (age_group LIKE '%Half-Day%' OR age_group LIKE '%half-day%') 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' half-day girls only rosters for export by user ' . $user_id);
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No half-day girls only roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Half_Day_Girls_Only_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
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
                // Normalize phone number
                $raw_phone = $roster['parent_phone'] ?? 'N/A';
                $processed_phone = (string)intersoccer_normalize_phone_number($raw_phone); // Explicitly cast to string
                error_log("InterSoccer: Raw phone (girls_only_half_day): {$raw_phone}, Normalized phone: {$processed_phone}");
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
                    $roster['times'] ?? 'N/A',
                    $roster['camp_terms'] ?? 'N/A',
                    $roster['shirt_size'] ?? 'N/A',
                    $roster['shorts_size'] ?? 'N/A'
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
        } elseif ($export_type === 'other') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE activity_type IN ('Event', 'Other') 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' other event rosters for export by user ' . $user_id);
            if (empty($rosters)) {
                ob_end_clean();
                wp_die(__('No other event roster data.', 'intersoccer-reports-rosters'));
            }

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Other_Event_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'Late Pickup', 'Booking Type', 'Age Group', 'Event Name', 'Venue', 'Times'
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

        error_log('InterSoccer: Exporting all rosters for type ' . $export_type . ' with ' . $sheet->getHighestRow() . ' rows');
        
        // Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="intersoccer_' . ($export_type === 'all' ? 'master' : $export_type) . '_rosters_' . date('Y-m-d_H-i-s') . '.xlsx"');
        header('Cache-Control: max-age=0');
        header('Expires: 0');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        intersoccer_log_audit('export_all_rosters_excel', 'Exported ' . $export_type . ' by user ' . $user_id);
        ob_end_flush();
    } catch (Exception $e) {
        error_log('InterSoccer: Export all rosters error in production for user ' . $user_id . ': ' . $e->getMessage() . ' on line ' . $e->getLine());
        ob_end_clean();
        wp_die(__('Export failed. Check server logs for details.', 'intersoccer-reports-rosters'));
    }
    exit;
}
?>