<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.2.96
 */

defined('ABSPATH') or die('Restricted access');

require_once dirname(__DIR__) . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Retrieve the parent's name from the WordPress user table based on email.
 * @param string $email The parent's email address.
 * @return string The parent's display name, or 'Unknown Parent' if not found.
 */
function intersoccer_get_parent_name($email) {
    global $wpdb;
    if (empty($email) || $email === 'N/A') {
        return 'Unknown Parent';
    }
    $user = $wpdb->get_row($wpdb->prepare("SELECT display_name FROM $wpdb->users WHERE user_email = %s LIMIT 1", $email));
    return $user ? $user->display_name : 'Unknown Parent (Email: ' . $email . ')';
}

function intersoccer_export_roster($variation_ids, $format = 'excel', $context = []) {
    try {
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
        }
        if (ob_get_length()) ob_end_clean();
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) wp_die(__('PhpSpreadsheet missing.', 'intersoccer-reports-rosters'));
        if (!function_exists('wc_get_product')) wp_die(__('WooCommerce unavailable.', 'intersoccer-reports-rosters'));

        $variation_ids = (array)$variation_ids;
        $roster = intersoccer_pe_get_event_roster_by_variation($variation_ids, $context);
        if (empty($roster)) wp_die(__('No roster data.', 'intersoccer-reports-rosters'));
        $is_camp = in_array(intersoccer_normalize_attribute($roster[0]['booking_type'] ?? ''), ['full week', 'single day(s)']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet_title = substr(preg_replace('/[^A-Za-z0-9\-\s]/', '', $is_camp ? $roster[0]['camp_terms'] : $roster[0]['event_dates']), 0, 31);
        $sheet->setTitle($sheet_title);

        $headers = array_keys($roster[0]);
        $sheet->fromArray($headers, NULL, 'A1');

        $row = 2;
        foreach ($roster as $player) {
            $data = array_values($player);
            $sheet->fromArray($data, NULL, 'A' . $row++);
        }
        $sheet->setCellValue('A' . $row, 'Total Players: ' . count($roster));

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="intersoccer_roster_variation_' . reset($variation_ids) . '_' . date('Y-m-d_H-i-s') . '.xlsx"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        intersoccer_log_audit('export_roster_excel', 'Exported for ' . implode(',', $variation_ids));
    } catch (Exception $e) {
        error_log('InterSoccer: Export roster error: ' . $e->getMessage());
        wp_die(__('Export failed.', 'intersoccer-reports-rosters'));
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
        if (ob_get_length()) ob_end_clean();
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) wp_die(__('PhpSpreadsheet missing.', 'intersoccer-reports-rosters'));

        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        error_log('InterSoccer: Starting export for type ' . $export_type . ' by user ' . $user_id);

        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="intersoccer_' . ($export_type === 'all' ? 'master' : $export_type) . '_rosters_' . date('Y-m-d_H-i-s') . '.csv"');
            $output = fopen('php://output', 'w');
            if ($export_type === 'all') {
                $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC", ARRAY_A);
                if (empty($rosters)) wp_die(__('No roster data.', 'intersoccer-reports-rosters'));
                fputcsv($output, array_keys($rosters[0]));
                foreach ($rosters as $roster) {
                    fputcsv($output, array_map('strval', array_values($roster)));
                }
            } else {
                $export_types = ['camps' => $camps, 'courses' => $courses, 'girls_only' => $girls_only];
                foreach (array_intersect_key($export_types, array_fill_keys([$export_type], true)) as $type => $variations) {
                    if (empty($variations)) continue;
                    foreach ($variations as $config_key => $config) {
                        $roster = intersoccer_pe_get_event_roster_by_variation($config['variation_ids']);
                        if (empty($roster)) continue;
                        fputcsv($output, array_keys($roster[0]));
                        foreach ($roster as $player) {
                            fputcsv($output, array_map('strval', array_values($player)));
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
            $rosters = $wpdb->get_results("SELECT venue, product_name, camp_terms, booking_type, first_name, last_name, age, gender, medical_conditions, late_pickup, day_presence, parent_phone, parent_email FROM $rosters_table WHERE activity_type = 'Camp' ORDER BY updated_at DESC", ARRAY_A);
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' camp rosters for export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No camp roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Camp_Rosters');
            $headers = ['InterSoccer Venue', 'Camp Name', 'Camp Terms', 'Booking Type', 'First Name', 'Last Name', 'Age', 'Gender', 'Medical/Dietary', 'Late Pickup', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Parent Phone', 'Parent Email', 'Parent Name'];
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = json_decode($roster['day_presence'], true) ?: ['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No'];
                // Default to 'yes' for all days if booking_type is 'full week'
                if (strtolower($roster['booking_type']) === 'full week') {
                    $day_presence = ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
                }
                $parent_name = intersoccer_get_parent_name($roster['parent_email']);
                $data = [
                    $roster['venue'],
                    $roster['product_name'],
                    $roster['camp_terms'],
                    $roster['booking_type'],
                    $roster['first_name'],
                    $roster['last_name'],
                    $roster['age'] ?? 'N/A',
                    $roster['gender'],
                    $roster['medical_conditions'] ?? 'None',
                    $roster['late_pickup'] === '18h' ? 'Yes' : 'No',
                    $day_presence['Monday'],
                    $day_presence['Tuesday'],
                    $day_presence['Wednesday'],
                    $day_presence['Thursday'],
                    $day_presence['Friday'],
                    $roster['parent_phone'] ?? 'N/A',
                    $roster['parent_email'] ?? 'N/A',
                    $parent_name
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
            $headers = array_keys($rosters[0]);
            $sheet->fromArray($headers, NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $sheet->fromArray(array_values($roster), NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } else {
            // Handle specific activity types (to be refined after validation)
            $export_types = ['courses' => $courses, 'girls_only' => $girls_only];
            foreach (array_intersect_key($export_types, array_fill_keys([$export_type], true)) as $type => $variations) {
                if (empty($variations)) continue;
                foreach ($variations as $config_key => $config) {
                    $roster = intersoccer_pe_get_event_roster_by_variation($config['variation_ids']);
                    if (empty($roster)) continue;
                    $sheet = $spreadsheet->createSheet();
                    $sheet_title = substr(preg_replace('/[^A-Za-z0-9\-\s]/', '', $config['product_name'] . ' - ' . $config['venues'][array_key_first($config['venues'])]['venue'] ?? ''), 0, 31);
                    $sheet->setTitle($sheet_title);

                    $headers = array_keys($roster[0]);
                    $sheet->fromArray($headers, NULL, 'A1');

                    $row = 2;
                    foreach ($roster as $player) {
                        $sheet->fromArray(array_values($player), NULL, 'A' . $row++);
                    }
                    $sheet->setCellValue('A' . $row, 'Total Players: ' . count($roster));
                }
            }
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="intersoccer_' . ($export_type === 'all' ? 'master' : $export_type) . '_rosters_' . date('Y-m-d_H-i-s') . '.xlsx"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        intersoccer_log_audit('export_all_rosters_excel', 'Exported ' . $export_type . ' by user ' . $user_id);
    } catch (Exception $e) {
        error_log('InterSoccer: Export all rosters error for user ' . $user_id . ': ' . $e->getMessage());
        wp_die(__('Export failed.', 'intersoccer-reports-rosters'));
    }
    exit;
}
