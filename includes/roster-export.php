<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.3.61
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

function intersoccer_export_roster($variation_ids, $format = 'excel', $context = []) {
    try {
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
        }
        // Ensure output buffer is clean and disabled for this request
        while (ob_get_level()) ob_end_clean();
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            error_log('InterSoccer: PhpSpreadsheet class not found in ' . __FILE__);
            wp_die(__('PhpSpreadsheet missing.', 'intersoccer-reports-rosters'));
        }
        if (!function_exists('wc_get_product')) wp_die(__('WooCommerce unavailable.', 'intersoccer-reports-rosters'));

        $variation_ids = (array)$variation_ids;
        $variation_id = $variation_ids[0]; // Use the first variation_id

        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $roster = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $rosters_table WHERE variation_id = %d ORDER BY player_name", $variation_id),
            ARRAY_A
        );
        if (empty($roster)) wp_die(__('No roster data.', 'intersoccer-reports-rosters'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet_title = substr(preg_replace('/[^A-Za-z0-9\-\s]/', '', $roster[0]['product_name'] . ' - ' . $roster[0]['venue']), 0, 31);
        $sheet->setTitle($sheet_title);

        // Toni's requested headers, adjusted for Girls Only to include shirt_size and shorts_size
        $headers = ['First Name' => 'first_name', 'Surname' => 'last_name', 'Gender' => 'gender', 'Phone' => 'parent_phone', 'Email' => 'parent_email', 'Age' => 'age', 'Medical/Dietary' => 'medical_conditions', 'Late Pickup' => 'late_pickup', 'Registration Timestamp' => 'registration_timestamp'];
        if ($roster[0]['activity_type'] === 'Camp') {
            $headers = array_merge($headers, ['Monday' => 'monday', 'Tuesday' => 'tuesday', 'Wednesday' => 'wednesday', 'Thursday' => 'thursday', 'Friday' => 'friday', 'Booking Type' => 'booking_type']);
        }
        if ($roster[0]['activity_type'] === 'Course') {
            $headers = array_merge($headers, ['Course Day' => 'course_day']);
        }
        if ($roster[0]['activity_type'] === 'Girls Only') {
            $headers = array_merge($headers, ['Shirt Size' => 'shirt_size', 'Shorts Size' => 'shorts_size']);
        }
        $sheet->fromArray(array_keys($headers), NULL, 'A1');

        $row = 2;
        foreach ($roster as $player) {
            error_log('InterSoccer: Exporting player - activity_type: ' . $player['activity_type'] . ', shirt_size: ' . $player['shirt_size'] . ', shorts_size: ' . $player['shorts_size']);
            $day_presence = json_decode($player['day_presence'] ?? '{}', true);
            if (strtolower($player['booking_type']) === 'full week' && empty($day_presence)) {
                $day_presence = ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
            }
            $data = [
                $player['first_name'] ?? 'N/A',
                $player['last_name'] ?? 'N/A',
                $player['gender'] ?? 'N/A',
                "'" . ($player['parent_phone'] ?? 'N/A'), // Prepend ' to force text format in Excel
                $player['parent_email'] ?? 'N/A',
                $player['age'] ?? 'N/A',
                $player['medical_conditions'] ?? 'N/A',
                ($player['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                $player['registration_timestamp'] ?? 'N/A'
            ];
            if ($player['activity_type'] === 'Camp') {
                $data = array_merge($data, [
                    $day_presence['Monday'] ?? 'No',
                    $day_presence['Tuesday'] ?? 'No',
                    $day_presence['Wednesday'] ?? 'No',
                    $day_presence['Thursday'] ?? 'No',
                    $day_presence['Friday'] ?? 'No',
                    $player['booking_type'] ?? 'N/A'
                ]);
            }
            if ($player['activity_type'] === 'Course') {
                $data = array_merge($data, [
                    $player['course_day'] ?? 'N/A'
                ]);
            }
            if ($player['activity_type'] === 'Girls Only') {
                $data = array_merge($data, [
                    $player['shirt_size'] ?? 'N/A',
                    $player['shorts_size'] ?? 'N/A'
                ]);
            }
            $sheet->fromArray($data, NULL, 'A' . $row++);

            // Add event details below the last row
            if ($row - 1 === count($roster)) {
                $sheet->setCellValue('A' . $row, 'Event Details:');
                $sheet->setCellValue('A' . ($row + 1), 'Product Name: ' . ($roster[0]['product_name'] ?? 'N/A'));
                $sheet->setCellValue('A' . ($row + 2), 'Venue: ' . ($roster[0]['venue'] ?? 'N/A'));
                $sheet->setCellValue('A' . ($row + 3), 'Age Group: ' . ($roster[0]['age_group'] ?? 'N/A'));
                if ($roster[0]['activity_type'] === 'Camp') {
                    $sheet->setCellValue('A' . ($row + 4), 'Camp Terms: ' . ($roster[0]['camp_terms'] ?? 'N/A'));
                }
                $sheet->setCellValue('A' . ($row + 5), 'Total Players: ' . count($roster));
            }
        }

        // Debug: Log the number of rows before saving
        error_log('InterSoccer: Exporting roster for variation_id ' . $variation_id . ' with ' . (count($roster) + 6) . ' rows');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="intersoccer_roster_variation_' . $variation_id . '_' . date('Y-m-d_H-i-s') . '.xlsx"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        intersoccer_log_audit('export_roster_excel', 'Exported for ' . implode(',', $variation_ids));
    } catch (Exception $e) {
        error_log('InterSoccer: Export roster error in production: ' . $e->getMessage() . ' on line ' . $e->getLine());
        wp_die(__('Export failed. Check server logs for details.', 'intersoccer-reports-rosters'));
    }
    exit;
}

function intersoccer_export_all_rosters($camps, $courses, $girls_only, $export_type = 'all', $format = 'excel') {
    try {
        $user_id = get_current_user_id();
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            error_log('InterSoccer: Export denied for user ID ' . $user_id . ' due to insufficient permissions.');
            wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
        }
        // Ensure output buffer is clean and disabled for this request
        while (ob_get_level()) ob_end_clean();
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            error_log('InterSoccer: PhpSpreadsheet class not found in ' . __FILE__ . ' for export type ' . $export_type);
            wp_die(__('PhpSpreadsheet missing.', 'intersoccer-reports-rosters'));
        }

        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        error_log('InterSoccer: Starting export for type ' . $export_type . ' by user ' . $user_id);

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="intersoccer_' . ($export_type === 'all' ? 'master' : $export_type) . '_rosters_' . date('Y-m-d_H-i-s') . '.csv"');
            $output = fopen('php://output', 'w');
            if ($export_type === 'all') {
                $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC", ARRAY_A);
                if (empty($rosters)) wp_die(__('No roster data.', 'intersoccer-reports-rosters'));
                $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Registration Timestamp'];
                fputcsv($output, $headers);
                foreach ($rosters as $roster) {
                    $data = [
                        $roster['first_name'] ?? 'N/A',
                        $roster['last_name'] ?? 'N/A',
                        $roster['gender'] ?? 'N/A',
                        "'" . ($roster['parent_phone'] ?? 'N/A'), // Prepend ' to force text format in Excel
                        $roster['parent_email'] ?? 'N/A',
                        $roster['age'] ?? 'N/A',
                        $roster['medical_conditions'] ?? 'N/A',
                        ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                        $roster['registration_timestamp'] ?? 'N/A'
                    ];
                    fputcsv($output, array_map('mb_convert_encoding', $data, 'UTF-8', 'auto'));
                }
            } else {
                $export_types = ['camps' => $camps, 'courses' => $courses, 'girls_only' => $girls_only];
                foreach (array_intersect_key($export_types, array_fill_keys([$export_type], true)) as $type => $variations) {
                    if (empty($variations)) continue;
                    foreach ($variations as $config_key => $config) {
                        $roster = intersoccer_pe_get_event_roster_by_variation($config['variation_ids'][0]); // Use first variation_id
                        if (empty($roster)) continue;
                        if ($type === 'camps') {
                            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Registration Timestamp', 'Camp Terms', 'Venue', 'Age Group', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Booking Type'];
                        } elseif ($type === 'courses') {
                            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Registration Timestamp', 'Course Day', 'Season', 'Age Group', 'Venue'];
                        } elseif ($type === 'girls_only') {
                            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Registration Timestamp', 'Event Name', 'Venue', 'Age Group', 'Shirt Size', 'Shorts Size'];
                        }
                        fputcsv($output, $headers);
                        foreach ($roster as $player) {
                            $season = date('Y', strtotime($player['start_date'] ?? '1970-01-01'));
                            $day_presence = json_decode($player['day_presence'] ?? '{}', true);
                            if (strtolower($player['booking_type']) === 'full week' && empty($day_presence)) {
                                $day_presence = ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
                            }
                            $data = [
                                $player['first_name'] ?? 'N/A',
                                $player['last_name'] ?? 'N/A',
                                $player['gender'] ?? 'N/A',
                                "'" . ($player['parent_phone'] ?? 'N/A'), // Prepend ' to force text format in Excel
                                $player['parent_email'] ?? 'N/A',
                                $player['age'] ?? 'N/A',
                                $player['medical_conditions'] ?? 'N/A',
                                ($player['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                                $player['registration_timestamp'] ?? 'N/A'
                            ];
                            if ($type === 'camps') {
                                $data = array_merge($data, [
                                    $player['camp_terms'] ?? 'N/A',
                                    $player['venue'] ?? 'N/A',
                                    $player['age_group'] ?? 'N/A',
                                    $day_presence['Monday'] ?? 'No',
                                    $day_presence['Tuesday'] ?? 'No',
                                    $day_presence['Wednesday'] ?? 'No',
                                    $day_presence['Thursday'] ?? 'No',
                                    $day_presence['Friday'] ?? 'No',
                                    $player['booking_type'] ?? 'N/A'
                                ]);
                            } elseif ($type === 'courses') {
                                $data = array_merge($data, [
                                    $player['course_day'] ?? 'N/A',
                                    $season,
                                    $player['age_group'] ?? 'N/A',
                                    $player['venue'] ?? 'N/A'
                                ]);
                            } elseif ($type === 'girls_only') {
                                $data = array_merge($data, [
                                    $player['product_name'] ?? 'N/A',
                                    $player['venue'] ?? 'N/A',
                                    $player['age_group'] ?? 'N/A',
                                    $player['shirt_size'] ?? 'N/A',
                                    $player['shorts_size'] ?? 'N/A'
                                ]);
                            }
                            fputcsv($output, array_map('mb_convert_encoding', $data, 'UTF-8', 'auto'));
                        }
                    }
                }
            }
            fclose($output);
            intersoccer_log_audit('export_all_rosters_csv', 'Exported ' . $export_type . ' by user ' . $user_id);
            exit;
        }

        $spreadsheet = new Spreadsheet();
        if ($export_type === 'camps') {
            $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE activity_type = 'Camp' ORDER BY updated_at DESC", ARRAY_A);
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' camp rosters for export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No camp roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Camp_Rosters');
            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Registration Timestamp', 'Camp Terms', 'Venue', 'Age Group', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Booking Type'];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = json_decode($roster['day_presence'] ?? '{}', true);
                if (strtolower($roster['booking_type']) === 'full week' && empty($day_presence)) {
                    $day_presence = ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
                }
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    "'" . ($roster['parent_phone'] ?? 'N/A'), // Prepend ' to force text format in Excel
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $roster['medical_conditions'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['registration_timestamp'] ?? 'N/A',
                    $roster['camp_terms'] ?? 'N/A',
                    $roster['venue'] ?? 'N/A',
                    $roster['age_group'] ?? 'N/A',
                    $day_presence['Monday'] ?? 'No',
                    $day_presence['Tuesday'] ?? 'No',
                    $day_presence['Wednesday'] ?? 'No',
                    $day_presence['Thursday'] ?? 'No',
                    $day_presence['Friday'] ?? 'No',
                    $roster['booking_type'] ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'courses') {
            $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE activity_type = 'Course' ORDER BY updated_at DESC", ARRAY_A);
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' course rosters for export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No course roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Course_Rosters');
            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Registration Timestamp', 'Course Day', 'Season', 'Age Group', 'Venue'];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $season = date('Y', strtotime($roster['start_date'] ?? '1970-01-01'));
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    "'" . ($roster['parent_phone'] ?? 'N/A'), // Prepend ' to force text format in Excel
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $roster['medical_conditions'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['registration_timestamp'] ?? 'N/A',
                    $roster['course_day'] ?? 'N/A',
                    $season,
                    $roster['age_group'] ?? 'N/A',
                    $roster['venue'] ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'all') {
            $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC", ARRAY_A);
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' rows for all rosters export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('All_Rosters');
            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Registration Timestamp'];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    "'" . ($roster['parent_phone'] ?? 'N/A'), // Prepend ' to force text format in Excel
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $roster['medical_conditions'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['registration_timestamp'] ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'girls_only') {
            $rosters = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $rosters_table WHERE activity_type LIKE %s OR activity_type LIKE %s ORDER BY updated_at DESC",
                    '%' . $wpdb->esc_like('Girls Only') . '%',
                    '%' . $wpdb->esc_like('Girls') . '%'
                ),
                ARRAY_A
            );
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' girls only rosters for export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No girls only roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Girls_Only_Rosters');
            $headers = ['First Name', 'Surname', 'Gender', 'Phone', 'Email', 'Age', 'Medical/Dietary', 'Late Pickup', 'Registration Timestamp', 'Event Name', 'Venue', 'Age Group', 'Shirt Size', 'Shorts Size'];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $data = [
                    $roster['first_name'] ?? 'N/A',
                    $roster['last_name'] ?? 'N/A',
                    $roster['gender'] ?? 'N/A',
                    "'" . ($roster['parent_phone'] ?? 'N/A'), // Prepend ' to force text format in Excel
                    $roster['parent_email'] ?? 'N/A',
                    $roster['age'] ?? 'N/A',
                    $roster['medical_conditions'] ?? 'N/A',
                    ($roster['late_pickup'] === 'Yes' ? 'Yes (18:00)' : 'No'),
                    $roster['registration_timestamp'] ?? 'N/A',
                    $roster['product_name'] ?? 'N/A',
                    $roster['venue'] ?? 'N/A',
                    $roster['age_group'] ?? 'N/A',
                    $roster['shirt_size'] ?? 'N/A',
                    $roster['shorts_size'] ?? 'N/A'
                ];
                $sheet->fromArray($data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        }

        // Debug: Log the number of rows before saving
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
