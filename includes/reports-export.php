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
 * Export booking report CSV
 */
/**
 * Export final reports Excel
 */
function intersoccer_export_final_reports_csv($year, $activity_type) {
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
            'BO',
            'Pitch Side',
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
                    $data['bo'],
                    $data['pitch_side'],
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
                $region_total['bo'],
                $region_total['pitch_side'],
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
            $totals['all']['bo'],
            $totals['all']['pitch_side'],
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

    // Generate and send Excel file
    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer->save('php://output');
    exit;
}