<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.2.90
 */

defined('ABSPATH') or die('Restricted access');

require_once dirname(__DIR__) . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

        $headers = [
            'First Name', 'Last Name', 'Gender', 'Parent Phone', 'Parent Email', 'Medical/Dietary', 'Late Pickup'
        ];
        $sheet->fromArray($headers, NULL, 'A1');

        $row = 2;
        foreach ($roster as $player) {
            $data = [
                $player['first_name'],
                $player['last_name'],
                $player['gender'],
                $player['parent_phone'],
                $player['parent_email'],
                $player['medical_conditions'] ?? 'None',
                $player['late_pickup'] === '18h' ? 'Yes' : 'No'
            ];
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
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
        }
        if (ob_get_length()) ob_end_clean();
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) wp_die(__('PhpSpreadsheet missing.', 'intersoccer-reports-rosters'));

        $spreadsheet = new Spreadsheet();
        $sheet_index = 0;

        $export_types = ['camps' => $camps, 'courses' => $courses, 'girls_only' => $girls_only];
        foreach (array_intersect_key($export_types, array_fill_keys([$export_type, 'all'], true)) as $type => $variations) {
            if (empty($variations)) continue;
            foreach ($variations as $config_key => $config) {
                $roster = intersoccer_pe_get_event_roster_by_variation($config['variation_ids']);
                if (empty($roster)) continue;
                $sheet = $spreadsheet->createSheet($sheet_index++);
                $sheet_title = substr(preg_replace('/[^A-Za-z0-9\-\s]/', '', $config['product_name'] . ' - ' . $config['venues'][array_key_first($config['venues'])]['venue'] ?? ''), 0, 31);
                $sheet->setTitle($sheet_title);

                $headers = [
                    'First Name', 'Last Name', 'Gender', 'Parent Phone', 'Parent Email', 'Medical/Dietary', 'Late Pickup'
                ];
                $sheet->fromArray($headers, NULL, 'A1');

                $row = 2;
                foreach ($roster as $player) {
                    $data = [
                        $player['first_name'],
                        $player['last_name'],
                        $player['gender'],
                        $player['parent_phone'],
                        $player['parent_email'],
                        $player['medical_conditions'] ?? 'None',
                        $player['late_pickup'] === '18h' ? 'Yes' : 'No'
                    ];
                    $sheet->fromArray($data, NULL, 'A' . $row++);
                }
                $sheet->setCellValue('A' . $row, 'Total Players: ' . count($roster));
            }
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="intersoccer_' . ($export_type === 'all' ? 'master' : $export_type) . '_rosters_' . date('Y-m-d_H-i-s') . '.xlsx"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        intersoccer_log_audit('export_all_rosters_excel', 'Exported ' . $export_type);
    } catch (Exception $e) {
        error_log('InterSoccer: Export all rosters error: ' . $e->getMessage());
        wp_die(__('Export failed.', 'intersoccer-reports-rosters'));
    }
    exit;
}
?>
