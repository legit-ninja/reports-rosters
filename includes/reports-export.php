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

    // Include the data processing file
    require_once plugin_dir_path(__FILE__) . 'reports-data.php';

    $report_data = intersoccer_get_final_reports_data($year, $activity_type);
    $totals = intersoccer_calculate_final_reports_totals($report_data, $activity_type);

    $filename = 'final-reports-' . strtolower($activity_type) . '-' . $year . '.xlsx';

    // Create new Spreadsheet object
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr('Final ' . $activity_type . ' Reports ' . $year, 0, 31));

    if ($activity_type === 'Camp') {
        // Camp Excel headers
        $headers = array(
            'Week',
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
        $sheet->setCellValue('E' . $totals_start, 'Direct Online');
        $sheet->setCellValue('F' . $totals_start, 'Total');
        $sheet->getStyle('A' . $totals_start . ':F' . $totals_start)->getFont()->setBold(true);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'Full Day Camps');
        $sheet->fromArray([
            $totals['full_day']['online'],
            $totals['full_day']['total']
        ], null, 'E' . $totals_start);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'Mini - Half Day Camps');
        $sheet->fromArray([
            $totals['mini']['online'],
            $totals['mini']['total']
        ], null, 'E' . $totals_start);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'All Camps');
        $sheet->getStyle('A' . $totals_start)->getFont()->setBold(true);
        $sheet->fromArray([
            $totals['all']['online'],
            $totals['all']['total']
        ], null, 'E' . $totals_start);
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

    // Generate and send file content directly
    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();

    wp_send_json_success([
        'content' => base64_encode($content),
        'filename' => $filename
    ]);
}
