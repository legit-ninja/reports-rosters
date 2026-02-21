<?php
/**
 * InterSoccer Reports - Export Functions
 *
 * @package InterSoccerReports
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include PhpSpreadsheet for Excel export
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Generate final reports Excel file (for AJAX and scheduled sync).
 *
 * @param int         $year         Year.
 * @param string      $activity_type Camp or Course.
 * @param string|null $season_type   Optional season type.
 * @param string|null $region        Optional region.
 * @return array{filename: string, content: string}|null Null on failure.
 */
function intersoccer_office365_generate_final_reports_xlsx($year, $activity_type = 'Camp', $season_type = null, $region = null) {
    require_once plugin_dir_path(__FILE__) . 'reports-data.php';
    $report_data = intersoccer_get_final_reports_data($year, $activity_type, $season_type, $region);
    $totals = intersoccer_calculate_final_reports_totals($report_data, $activity_type);

    $filename = 'final-reports-' . strtolower($activity_type) . '-' . $year;
    if (!empty($season_type)) {
        $filename .= '-' . strtolower($season_type);
    }
    if (!empty($region)) {
        $filename .= '-' . strtolower(str_replace(' ', '-', $region));
    }
    $filename .= '.xlsx';

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr('Final ' . $activity_type . ' Reports ' . $year, 0, 31));

    if ($activity_type === 'Camp') {
        // Camp Excel headers
        $headers = array(
            'Date Range',
            'Canton',
            'Venue',
            'Camp Type',
            'Full Week',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Min-Max',
            'Total'
        );
        $sheet->fromArray($headers, null, 'A1');

        // Style header row
        $header_range = 'A1:' . chr(64 + count($headers)) . '1';
        $sheet->getStyle($header_range)->getFont()->setBold(true);
        $sheet->getStyle($header_range)->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle($header_range)->getFont()->getColor()->setARGB('FFFFFFFF');

        // Camp Excel data
        $row_index = 2;
        foreach ($report_data as $week_name => $cantons) {
            foreach ($cantons as $canton => $venues) {
                foreach ($venues as $venue => $camp_types) {
                    foreach ($camp_types as $camp_type => $data) {
                        $excel_row = array(
                            $week_name,
                            $canton,
                            $venue,
                            $camp_type,
                            $data['full_week'],
                            $data['individual_days']['Monday'],
                            $data['individual_days']['Tuesday'],
                            $data['individual_days']['Wednesday'],
                            $data['individual_days']['Thursday'],
                            $data['individual_days']['Friday'],
                            $data['min_max'],
                            $data['full_week'] + array_sum($data['individual_days'])
                        );
                        $sheet->fromArray($excel_row, null, 'A' . $row_index);
                        $row_index++;
                    }
                }
            }
        }

        // Camp totals
        $totals_start = $row_index + 2;
        $sheet->setCellValue('A' . $totals_start, 'TOTALS');
        $sheet->getStyle('A' . $totals_start)->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF0073AA');
        $sheet->mergeCells('A' . $totals_start . ':D' . $totals_start);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'Category');
        $sheet->setCellValue('E' . $totals_start, 'Total Registrations');
        $sheet->getStyle('A' . $totals_start . ':E' . $totals_start)->getFont()->setBold(true);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'Full Day Camps');
        $sheet->setCellValue('E' . $totals_start, isset($totals['full_day']['unique_records']) ? $totals['full_day']['unique_records'] : $totals['full_day']['total']);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'Mini - Half Day Camps');
        $sheet->setCellValue('E' . $totals_start, isset($totals['mini']['unique_records']) ? $totals['mini']['unique_records'] : $totals['mini']['total']);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'All Camps');
        $sheet->getStyle('A' . $totals_start)->getFont()->setBold(true);
        $sheet->setCellValue('E' . $totals_start, isset($totals['all']['unique_records']) ? $totals['all']['unique_records'] : $totals['all']['total']);
    } else {
        // Course Excel headers
        $headers = array(
            'Region',
            'Course Name',
            'Course Day',
            'Direct Online',
            'Total'
        );
        $sheet->fromArray($headers, null, 'A1');

        // Style header row
        $header_range = 'A1:' . chr(64 + count($headers)) . '1';
        $sheet->getStyle($header_range)->getFont()->setBold(true);
        $sheet->getStyle($header_range)->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle($header_range)->getFont()->getColor()->setARGB('FFFFFFFF');

        // Course Excel data
        $row_index = 2;
        foreach ($report_data as $region => $courses) {
            foreach ($courses as $course_name => $course_days) {
                foreach ($course_days as $course_day => $course_data) {
                    $excel_row = array(
                        $region,
                        $course_name,
                        $course_day,
                        $course_data['online'],
                        $course_data['total']
                    );
                    $sheet->fromArray($excel_row, null, 'A' . $row_index);
                    $row_index++;
                }
            }
        }

        // Course totals
        $totals_start = $row_index + 2;
        $sheet->setCellValue('A' . $totals_start, 'TOTALS');
        $sheet->getStyle('A' . $totals_start)->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF0073AA');
        $sheet->mergeCells('A' . $totals_start . ':C' . $totals_start);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'Category');
        $sheet->setCellValue('D' . $totals_start, 'Online');
        $sheet->setCellValue('E' . $totals_start, 'Total');
        $sheet->getStyle('A' . $totals_start . ':E' . $totals_start)->getFont()->setBold(true);

    }

    // Set column widths
    foreach (range('A', $sheet->getHighestDataColumn()) as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();
    return ['filename' => $filename, 'content' => $content];
}

/**
 * Export final reports Excel (AJAX handler)
 */
add_action('wp_ajax_intersoccer_export_final_reports', 'intersoccer_export_final_reports_callback');
function intersoccer_export_final_reports_callback() {
    check_ajax_referer('intersoccer_reports_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have sufficient permissions to export reports.', 'intersoccer-reports-rosters'));
    }

    $year = isset($_POST['year']) ? absint($_POST['year']) : date('Y');
    $activity_type = isset($_POST['activity_type']) ? sanitize_text_field($_POST['activity_type']) : 'Camp';
    $season_type = isset($_POST['season_type']) ? sanitize_text_field($_POST['season_type']) : null;
    $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : null;

    $result = intersoccer_office365_generate_final_reports_xlsx($year, $activity_type, $season_type, $region);
    if (!$result) {
        wp_send_json_error(__('Failed to generate report.', 'intersoccer-reports-rosters'));
    }

    $payload = [
        'content' => base64_encode($result['content']),
        'filename' => $result['filename'],
    ];
    if (!empty($_POST['sync_to_office365']) && class_exists('InterSoccer\ReportsRosters\Office365\SyncService')) {
        $service = new \InterSoccer\ReportsRosters\Office365\SyncService();
        if ($service->isEnabled()) {
            $upload = $service->uploadFile($result['filename'], $result['content']);
            $payload['synced'] = $upload['success'];
            $payload['sync_error'] = isset($upload['error']) ? $upload['error'] : null;
        }
    }

    wp_send_json_success($payload);
}
