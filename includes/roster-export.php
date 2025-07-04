<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.3.98
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

require_once dirname(__DIR__) . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
 * Export roster data to Excel
 */
add_action('wp_ajax_intersoccer_export_roster', 'intersoccer_export_roster');
function intersoccer_export_roster() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_send_json_error(__('You do not have permission to export rosters.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $variation_ids = isset($_POST['variation_ids']) ? array_map('intval', (array)$_POST['variation_ids']) : [];
    $age_group = isset($_POST['age_group']) ? sanitize_text_field($_POST['age_group']) : '';

    if (empty($variation_ids)) {
        wp_send_json_error(__('No variation IDs provided for export.', 'intersoccer-reports-rosters'));
    }

    // Build query
    $query = $wpdb->prepare(
        "SELECT player_name, first_name, last_name, gender, parent_phone, parent_email, age, medical_conditions, late_pickup, booking_type, day_presence, age_group, activity_type, product_name, camp_terms, venue, shirt_size, shorts_size
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
                'booking_type' => $row['booking_type'],
                'shirt_size' => $row['shirt_size'],
                'shorts_size' => $row['shorts_size'],
                'day_presence' => $day_presence,
                'activity_type' => $row['activity_type']
            ];
        }, array_slice($rosters, 0, 1))));
    }

    if (empty($rosters)) {
        wp_send_json_error(__('No roster data found for export.', 'intersoccer-reports-rosters'));
    }

    // Prepare Excel data
    $base_roster = $rosters[0];
    $filename = 'roster_' . sanitize_title($base_roster['product_name'] . '_' . $base_roster['camp_terms'] . '_' . $base_roster['venue']) . '_' . date('Y-m-d_H-i-s') . '.xlsx';
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
        __('Monday', 'intersoccer-reports-rosters'),
        __('Tuesday', 'intersoccer-reports-rosters'),
        __('Wednesday', 'intersoccer-reports-rosters'),
        __('Thursday', 'intersoccer-reports-rosters'),
        __('Friday', 'intersoccer-reports-rosters'),
        __('Age Group', 'intersoccer-reports-rosters'),
        __('Product Name', 'intersoccer-reports-rosters'),
        __('Venue', 'intersoccer-reports-rosters'),
        __('Camp Terms', 'intersoccer-reports-rosters')
    ];

    if ($base_roster['activity_type'] === 'Girls Only' || $base_roster['activity_type'] === 'Camp, Girls Only' || $base_roster['activity_type'] === 'Camp, Girls\' only') {
        $headers[] = __('Shirt Size', 'intersoccer-reports-rosters');
        $headers[] = __('Shorts Size', 'intersoccer-reports-rosters');
    }

    $sheet->fromArray($headers, NULL, 'A1');

    $row = 2;
    foreach ($rosters as $player) {
        $day_presence = !empty($player['day_presence']) ? json_decode($player['day_presence'], true) : [];
        $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
        $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
        $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
        $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
        $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
        $data = [
            $player['first_name'] ?? 'N/A',
            $player['last_name'] ?? 'N/A',
            $player['gender'] ?? 'N/A',
            $player['parent_phone'] ?? 'N/A',
            $player['parent_email'] ?? 'N/A',
            $player['age'] ?? 'N/A',
            $player['medical_conditions'] ?? 'N/A',
            ($player['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
            $player['booking_type'] ?? 'N/A',
            $monday,
            $tuesday,
            $wednesday,
            $thursday,
            $friday,
            intersoccer_get_term_name($player['age_group'], 'pa_age-group') ?? 'N/A',
            $player['product_name'] ?? 'N/A',
            intersoccer_get_term_name($player['venue'], 'pa_intersoccer-venues') ?? 'N/A',
            $player['camp_terms'] ?? 'N/A'
        ];
        if ($player['activity_type'] === 'Girls Only' || $player['activity_type'] === 'Camp, Girls Only' || $player['activity_type'] === 'Camp, Girls\' only') {
            $data[] = $player['shirt_size'] ?? 'N/A';
            $data[] = $player['shorts_size'] ?? 'N/A';
        }
        $sheet->fromArray($data, NULL, 'A' . $row++);
    }

    // Add event details
    $sheet->setCellValue('A' . $row, 'Event Details:');
    $sheet->setCellValue('A' . ($row + 1), 'Product Name: ' . ($base_roster['product_name'] ?? 'N/A'));
    $sheet->setCellValue('A' . ($row + 2), 'Venue: ' . ($base_roster['venue'] ?? 'N/A'));
    $sheet->setCellValue('A' . ($row + 3), 'Age Group: ' . ($base_roster['age_group'] ?? 'N/A'));
    $sheet->setCellValue('A' . ($row + 4), 'Camp Terms: ' . ($base_roster['camp_terms'] ?? 'N/A'));
    $sheet->setCellValue('A' . ($row + 5), 'Total Players: ' . count($rosters));

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    intersoccer_log_audit('export_roster_excel', 'Exported for variation_ids: ' . implode(',', $variation_ids));
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
            wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
        }
        while (ob_get_level()) ob_end_clean();
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            error_log('InterSoccer: PhpSpreadsheet class not found in ' . __FILE__ . ' for export type ' . $export_type);
            wp_die(__('PhpSpreadsheet missing.', 'intersoccer-reports-rosters'));
        }

        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        error_log('InterSoccer: Starting export for type ' . $export_type . ' by user ' . $user_id);

        $spreadsheet = new Spreadsheet();
        if ($export_type === 'all') {
            $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC", ARRAY_A);
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' rows for all rosters export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('All_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 
                'Age Group', 'Product Name', 'Venue', 'Camp Terms', 'Activity Type'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = !empty($roster['day_presence']) ? json_decode($roster['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $roster['parent_phone'] ?? 'N/A',
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
                    $roster['activity_type'] ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'camps_full_day') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE activity_type IN ('Camp', 'Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only') 
                 AND (age_group LIKE '%Full Day%' OR age_group LIKE '%full-day%') 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' full-day camp rosters for export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No full-day camp roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Full_Day_Camp_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 
                'Age Group', 'Camp Terms', 'Venue'
            ];
            if (isset($rosters[0]['activity_type']) && ($rosters[0]['activity_type'] === 'Girls Only' || $rosters[0]['activity_type'] === 'Camp, Girls Only' || $rosters[0]['activity_type'] === 'Camp, Girls\' only')) {
                $headers = array_merge($headers, ['Shirt Size', 'Shorts Size']);
            }
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = !empty($roster['day_presence']) ? json_decode($roster['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $roster['parent_phone'] ?? 'N/A',
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
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A'
                ];
                if ($roster['activity_type'] === 'Girls Only' || $roster['activity_type'] === 'Camp, Girls Only' || $roster['activity_type'] === 'Camp, Girls\' only') {
                    $data = array_merge($data, [
                        $roster['shirt_size'] ?? 'N/A',
                        $roster['shorts_size'] ?? 'N/A'
                    ]);
                }
                $sheet->fromArray($data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'camps_half_day') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE activity_type IN ('Camp', 'Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only') 
                 AND (age_group LIKE '%Half-Day%' OR age_group LIKE '%half-day%') 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' half-day camp rosters for export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No half-day camp roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Half_Day_Camp_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 
                'Age Group', 'Camp Terms', 'Venue'
            ];
            if (isset($rosters[0]['activity_type']) && ($rosters[0]['activity_type'] === 'Girls Only' || $rosters[0]['activity_type'] === 'Camp, Girls Only' || $rosters[0]['activity_type'] === 'Camp, Girls\' only')) {
                $headers = array_merge($headers, ['Shirt Size', 'Shorts Size']);
            }
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = !empty($roster['day_presence']) ? json_decode($roster['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $roster['parent_phone'] ?? 'N/A',
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
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A'
                ];
                if ($roster['activity_type'] === 'Girls Only' || $roster['activity_type'] === 'Camp, Girls Only' || $roster['activity_type'] === 'Camp, Girls\' only') {
                    $data = array_merge($data, [
                        $roster['shirt_size'] ?? 'N/A',
                        $roster['shorts_size'] ?? 'N/A'
                    ]);
                }
                $sheet->fromArray($data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'courses_full_day') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE activity_type = 'Course' 
                 AND (age_group LIKE '%Full Day%' OR age_group LIKE '%full-day%') 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' full-day course rosters for export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No full-day course roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Full_Day_Course_Rosters');
            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Course Day', 'Season', 'Age Group', 'Venue'];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $season = date('Y', strtotime($roster['start_date'] ?? '1970-01-01'));
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $roster['parent_phone'] ?? 'N/A',
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $roster['medical_conditions'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['course_day'] ?? 'N/A',
                    $season,
                    intersoccer_get_term_name($roster['age_group'], 'pa_age-group') ?? 'N/A',
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'courses_half_day') {
            $rosters = $wpdb->get_results(
                "SELECT * FROM $rosters_table 
                 WHERE activity_type = 'Course' 
                 AND (age_group LIKE '%Half-Day%' OR age_group LIKE '%half-day%') 
                 ORDER BY updated_at DESC",
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' half-day course rosters for export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No half-day course roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Half_Day_Course_Rosters');
            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Course Day', 'Season', 'Age Group', 'Venue'];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $season = date('Y', strtotime($roster['start_date'] ?? '1970-01-01'));
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $roster['parent_phone'] ?? 'N/A',
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $roster['medical_conditions'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['course_day'] ?? 'N/A',
                    $season,
                    intersoccer_get_term_name($roster['age_group'], 'pa_age-group') ?? 'N/A',
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
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
            if (empty($rosters)) wp_die(__('No full-day girls only roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Full_Day_Girls_Only_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 
                'Age Group', 'Event Name', 'Venue', 'Camp Terms', 'Shirt Size', 'Shorts Size'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = !empty($roster['day_presence']) ? json_decode($roster['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $roster['parent_phone'] ?? 'N/A',
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
                    $roster['shirt_size'] ?? 'N/A',
                    $roster['shorts_size'] ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
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
            if (empty($rosters)) wp_die(__('No half-day girls only roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Half_Day_Girls_Only_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'Late Pickup', 'Booking Type', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 
                'Age Group', 'Event Name', 'Venue', 'Camp Terms', 'Shirt Size', 'Shorts Size'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = !empty($roster['day_presence']) ? json_decode($roster['day_presence'], true) : [];
                $monday = isset($day_presence['Monday']) ? $day_presence['Monday'] : 'No';
                $tuesday = isset($day_presence['Tuesday']) ? $day_presence['Tuesday'] : 'No';
                $wednesday = isset($day_presence['Wednesday']) ? $day_presence['Wednesday'] : 'No';
                $thursday = isset($day_presence['Thursday']) ? $day_presence['Thursday'] : 'No';
                $friday = isset($day_presence['Friday']) ? $day_presence['Friday'] : 'No';
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $roster['parent_phone'] ?? 'N/A',
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
                    $roster['shirt_size'] ?? 'N/A',
                    $roster['shorts_size'] ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
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
            if (empty($rosters)) wp_die(__('No other event roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Other_Event_Rosters');
            $headers = [
                'First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 
                'Late Pickup', 'Booking Type', 'Age Group', 'Event Name', 'Venue'
            ];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    $roster['parent_phone'] ?? 'N/A',
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $roster['medical_conditions'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['booking_type'] ?? 'N/A',
                    intersoccer_get_term_name($roster['age_group'], 'pa_age-group') ?? 'N/A',
                    $roster['product_name'] ?? 'N/A',
                    intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues') ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        }

        error_log('InterSoccer: Exporting all rosters for type ' . $export_type . ' with ' . $sheet->getHighestRow() . ' rows');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="intersoccer_' . ($export_type === 'all' ? 'master' : $export_type) . '_rosters_' . date('Y-m-d_H-i-s') . '.xlsx"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        intersoccer_log_audit('export_all_rosters_excel', 'Exported ' . $export_type . ' by user ' . $user_id);
    } catch (Exception $e) {
        error_log('InterSoccer: Export all rosters error in production for user ' . $user_id . ': ' . $e->getMessage() . ' on line ' . $e->getLine());
        wp_die(__('Export failed. Check server logs for details.', 'intersoccer-reports-rosters'));
    }
    exit;
}
?>