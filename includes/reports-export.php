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
            'BuyClub',
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
                            $data['buyclub'],
                            $data['min_max'],
                            $data['full_week'] + $data['buyclub'] + array_sum($data['individual_days'])
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
        $sheet->setCellValue('A' . $totals_start, 'Full Day Camps');
        $sheet->fromArray([
            $totals['full_day']['full_week'],
            $totals['full_day']['individual_days']['Monday'],
            $totals['full_day']['individual_days']['Tuesday'],
            $totals['full_day']['individual_days']['Wednesday'],
            $totals['full_day']['individual_days']['Thursday'],
            $totals['full_day']['individual_days']['Friday'],
            $totals['full_day']['buyclub'],
            '',
            $totals['full_day']['total']
        ], null, 'E' . $totals_start);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'Mini - Half Day Camps');
        $sheet->fromArray([
            $totals['mini']['full_week'],
            $totals['mini']['individual_days']['Monday'],
            $totals['mini']['individual_days']['Tuesday'],
            $totals['mini']['individual_days']['Wednesday'],
            $totals['mini']['individual_days']['Thursday'],
            $totals['mini']['individual_days']['Friday'],
            $totals['mini']['buyclub'],
            '',
            $totals['mini']['total']
        ], null, 'E' . $totals_start);

        $totals_start++;
        $sheet->setCellValue('A' . $totals_start, 'All Camps');
        $sheet->getStyle('A' . $totals_start)->getFont()->setBold(true);
        $sheet->fromArray([
            $totals['all']['full_week'],
            $totals['all']['individual_days']['Monday'],
            $totals['all']['individual_days']['Tuesday'],
            $totals['all']['individual_days']['Wednesday'],
            $totals['all']['individual_days']['Thursday'],
            $totals['all']['individual_days']['Friday'],
            $totals['all']['buyclub'],
            '',
            $totals['all']['total']
        ], null, 'E' . $totals_start);
    } else {
        // Course Excel headers
        $headers = array(
            'Region',
            'Course Name',
            'Direct Online',
            'BuyClub',
            'Total',
            'Final',
            'Girls Free'
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
            foreach ($courses as $course_name => $data) {
                $excel_row = array(
                    $region,
                    $course_name,
                    $data['online'],
                    $data['buyclub'],
                    $data['total'],
                    $data['final'],
                    $data['girls_free']
                );
                $sheet->fromArray($excel_row, null, 'A' . $row_index);
                $row_index++;
            }
        }

        // Course totals
        $totals_start = $row_index + 2;
        $sheet->setCellValue('A' . $totals_start, 'TOTALS');
        $sheet->getStyle('A' . $totals_start)->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF0073AA');
        $sheet->mergeCells('A' . $totals_start . ':B' . $totals_start);

        $totals_start++;
        foreach ($totals['regions'] as $region => $region_total) {
            $sheet->setCellValue('A' . $totals_start, $region);
            $sheet->fromArray([
                $region_total['online'],
                $region_total['buyclub'],
                $region_total['total'],
                $region_total['final'],
                $region_total['girls_free']
            ], null, 'C' . $totals_start);
            $totals_start++;
        }

        $sheet->setCellValue('A' . $totals_start, 'TOTAL:');
        $sheet->getStyle('A' . $totals_start)->getFont()->setBold(true);
        $sheet->fromArray([
            $totals['all']['online'],
            $totals['all']['buyclub'],
            $totals['all']['total'],
            $totals['all']['final'],
            $totals['all']['girls_free']
        ], null, 'C' . $totals_start);
    }

    // Add generation info
    $info_row = $totals_start + 3;
    $generation_info = 'Report Generated: ' . date('Y-m-d H:i:s') . ' | Year: ' . $year . ' | Activity: ' . $activity_type;
    $sheet->setCellValue('A' . $info_row, $generation_info);
    $sheet->getStyle('A' . $info_row)->getFont()->setItalic(true)->setSize(10);
    $sheet->mergeCells('A' . $info_row . ':D' . $info_row);

    // Auto-size columns
    foreach (range('A', chr(64 + max(count($headers), 13))) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Generate and send Excel file via AJAX (like booking report)
    $writer = new Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();

    wp_send_json_success([
        'content' => base64_encode($content),
        'filename' => $filename,
        'record_count' => count($report_data),
        'file_size' => strlen($content)
    ]);
}

/**
 * Export final reports Excel (legacy direct URL access - deprecated)
 */
function intersoccer_export_final_reports_csv($year, $activity_type) {
    // This function is deprecated - use AJAX export instead
    wp_die(__('Export functionality has been updated. Please refresh the page and try again.', 'intersoccer-reports-rosters'));
}