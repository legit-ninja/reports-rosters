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
 * Apply solid fill + white bold text for an urgency heat cell.
 *
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Sheet.
 * @param string                                         $cell  e.g. K2.
 * @param string                                         $band  Urgency CSS class.
 */
function intersoccer_reports_excel_apply_urgency_fill($sheet, $cell, $band) {
    $argb = function_exists('intersoccer_reports_urgency_band_argb')
        ? intersoccer_reports_urgency_band_argb($band)
        : 'FF6B7280';
    $style = $sheet->getStyle($cell);
    $style->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB($argb);
    $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
}

/**
 * Generate final reports Excel file (for AJAX and scheduled sync).
 *
 * @param int         $year         Year.
 * @param string      $activity_type Camp or Course.
 * @param string|null $season_type   Optional season type.
 * @param string|null $region        Optional region.
 * @param bool        $exclude_buyclub Omit BuyClub / matching coupon rows when true.
 * @param bool        $live          When true, include processing orders (Live Snapshot).
 * @param bool        $urgency_only  When true, export Critical + Low rows only.
 * @return array{filename: string, content: string}|null Null on failure.
 */
function intersoccer_office365_generate_final_reports_xlsx($year, $activity_type = 'Camp', $season_type = null, $region = null, $exclude_buyclub = false, $live = false, $urgency_only = false) {
    require_once plugin_dir_path(__FILE__) . 'reports-data.php';
    if (!function_exists('intersoccer_reports_camp_excel_data_rows')) {
        require_once plugin_dir_path(__FILE__) . 'final-reports-aggregation.php';
    }
    $report_data = intersoccer_get_final_reports_data($year, $activity_type, $season_type, $region, $exclude_buyclub, $live);
    $camp_player_registration_totals = null;
    $course_player_registration_totals = null;
    if ($activity_type === 'Camp' && isset($report_data['__player_registration_totals__'])) {
        $camp_player_registration_totals = $report_data['__player_registration_totals__'];
        unset($report_data['__player_registration_totals__']);
    }
    if ($activity_type === 'Course' && isset($report_data['__player_registration_totals__'])) {
        $course_player_registration_totals = $report_data['__player_registration_totals__'];
        unset($report_data['__player_registration_totals__']);
    }
    $totals = intersoccer_calculate_final_reports_totals($report_data, $activity_type);

    $filename = ($live ? 'live-' : '') . 'final-reports-' . strtolower($activity_type) . '-' . $year;
    if (!empty($season_type)) {
        $filename .= '-' . strtolower($season_type);
    }
    if (!empty($region)) {
        $filename .= '-' . strtolower(str_replace(' ', '-', $region));
    }
    if ($urgency_only) {
        $filename .= '-urgent';
    }
    $filename .= '.xlsx';

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr('Final ' . $activity_type . ' Reports ' . $year, 0, 31));

    if ($activity_type === 'Camp') {
        // Grid layout aligned with Admin Final Camp Numbers (no Pitchside column).
        $sheet->setCellValue('B1', 'SUMMER CAMPS NUMBERS ' . $year);
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('C2', 'Full Day Camps');
        $sheet->mergeCells('C2:J2');
        $sheet->setCellValue('K2', 'Mini - Half Day Camps');
        $sheet->mergeCells('K2:R2');
        $sheet->getStyle('C2:R2')->getFont()->setBold(true);

        $headers_r3 = [
            'C' => 'Full Week', 'D' => 'BuyClub', 'E' => 'Individual days', 'J' => 'Total min-max',
            'K' => 'Full Week', 'L' => 'BuyClub', 'M' => 'Individual days', 'R' => 'Total min-max',
        ];
        foreach ($headers_r3 as $col => $label) {
            $sheet->setCellValue($col . '3', $label);
        }
        $sheet->mergeCells('E3:I3');
        $sheet->mergeCells('M3:Q3');
        foreach ([['E', 'M'], ['F', 'T'], ['G', 'W'], ['H', 'T'], ['I', 'F'],
                  ['M', 'M'], ['N', 'T'], ['O', 'W'], ['P', 'T'], ['Q', 'F']] as $pair) {
            $sheet->setCellValue($pair[0] . '4', $pair[1]);
        }
        $sheet->setCellValue('A5', 'Canton');
        $sheet->getStyle('A3:R4')->getFont()->setBold(true);

        $row_index = 5;
        $write_metrics = static function ($sheet, $row, $start_col, array $m) {
            $cols = [];
            $ord = ord($start_col);
            for ($i = 0; $i < 8; $i++) {
                $cols[] = chr($ord + $i);
            }
            $sheet->setCellValue($cols[0] . $row, (int) $m['full_week']);
            $sheet->setCellValue($cols[1] . $row, (int) $m['buyclub']);
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            foreach ($days as $i => $day) {
                $sheet->setCellValue($cols[2 + $i] . $row, (int) ($m['individual_days'][$day] ?? 0));
            }
            $sheet->setCellValue($cols[7] . $row, (string) ($m['min_max'] ?? '0-0'));
            return $cols[7];
        };

        foreach ($report_data as $week_name => $cantons) {
            if ($week_name === '__player_registration_totals__' || !is_array($cantons)) {
                continue;
            }
            $week_rows = [];
            foreach ($cantons as $canton => $venues) {
                if (!is_array($venues)) {
                    continue;
                }
                foreach ($venues as $venue => $camp_types) {
                    if (!is_array($camp_types)) {
                        continue;
                    }
                    if ($urgency_only && function_exists('intersoccer_reports_camp_venue_is_urgent')
                        && !intersoccer_reports_camp_venue_is_urgent($camp_types)) {
                        continue;
                    }
                    $week_rows[] = [$canton, $venue, $camp_types];
                }
            }
            if (empty($week_rows)) {
                continue;
            }
            $sheet->setCellValue('B' . $row_index, $week_name);
            $sheet->getStyle('B' . $row_index)->getFont()->setBold(true);
            $row_index++;
            foreach ($week_rows as $wr) {
                list($canton, $venue, $camp_types) = $wr;
                $fd = function_exists('intersoccer_reports_normalize_camp_metrics')
                    ? intersoccer_reports_normalize_camp_metrics($camp_types['Full Day'] ?? null)
                    : intersoccer_reports_empty_camp_metrics();
                $mini = function_exists('intersoccer_reports_normalize_camp_metrics')
                    ? intersoccer_reports_normalize_camp_metrics($camp_types['Mini - Half Day'] ?? null)
                    : intersoccer_reports_empty_camp_metrics();
                $sheet->setCellValue('A' . $row_index, $canton);
                $sheet->setCellValue('B' . $row_index, $venue);
                $fd_mm_col = $write_metrics($sheet, $row_index, 'C', $fd);
                $mini_mm_col = $write_metrics($sheet, $row_index, 'K', $mini);
                $fd_band = intersoccer_reports_camp_metrics_urgency_band($fd);
                $mini_band = intersoccer_reports_camp_metrics_urgency_band($mini);
                intersoccer_reports_excel_apply_urgency_fill($sheet, $fd_mm_col . $row_index, $fd_band);
                intersoccer_reports_excel_apply_urgency_fill($sheet, $mini_mm_col . $row_index, $mini_band);
                $row_index++;
            }
        }

        $grand = function_exists('intersoccer_reports_camp_grand_totals')
            ? intersoccer_reports_camp_grand_totals($report_data, $urgency_only)
            : null;
        if (is_array($grand)) {
            $sheet->setCellValue('C' . $row_index, 'Full Week');
            $sheet->setCellValue('D' . $row_index, 'BuyClub');
            $sheet->setCellValue('E' . $row_index, 'Individual days');
            $sheet->setCellValue('K' . $row_index, 'Full Week');
            $sheet->setCellValue('L' . $row_index, 'BuyClub');
            $sheet->setCellValue('M' . $row_index, 'Individual days');
            $sheet->getStyle('C' . $row_index . ':M' . $row_index)->getFont()->setBold(true);
            $row_index++;

            $sheet->setCellValue('B' . $row_index, 'TOTAL');
            $sheet->setCellValue('C' . $row_index, (int) $grand['full_day']['full_week']);
            $sheet->setCellValue('D' . $row_index, (int) $grand['full_day']['buyclub']);
            $sheet->setCellValue('E' . $row_index, (int) $grand['full_day']['individual_day_slots']);
            $sheet->setCellValue('K' . $row_index, (int) $grand['mini']['full_week']);
            $sheet->setCellValue('L' . $row_index, (int) $grand['mini']['buyclub']);
            $sheet->setCellValue('M' . $row_index, (int) $grand['mini']['individual_day_slots']);
            $sheet->getStyle('B' . $row_index . ':M' . $row_index)->getFont()->setBold(true);
            $row_index++;

            $sheet->setCellValue('B' . $row_index, 'All registrations');
            $sheet->setCellValue('C' . $row_index, (int) $grand['full_day']['all_registrations']);
            $sheet->setCellValue('E' . $row_index, (int) $grand['full_day']['individual_day_slots']);
            $sheet->setCellValue('K' . $row_index, (int) $grand['mini']['all_registrations']);
            $sheet->setCellValue('M' . $row_index, (int) $grand['mini']['individual_day_slots']);
            $sheet->getStyle('B' . $row_index . ':M' . $row_index)->getFont()->setBold(true);
        }
    } else {
        // Compact Courses Numbers layout (title, region blocks, one TOTAL column).
        $sheet_rows = function_exists('intersoccer_reports_course_excel_sheet_rows')
            ? intersoccer_reports_course_excel_sheet_rows($report_data, $year, $urgency_only)
            : [];
        $row_index = 1;
        foreach ($sheet_rows as $sheet_row) {
            if (!is_array($sheet_row)) {
                continue;
            }
            $kind = (string) ($sheet_row['kind'] ?? '');
            $col_a = (string) ($sheet_row['col_a'] ?? '');
            $col_b = $sheet_row['col_b'] ?? null;
            $sheet->setCellValue('A' . $row_index, $col_a);
            if ($col_b !== null && $col_b !== '') {
                $sheet->setCellValue('B' . $row_index, $col_b);
            }
            if ($kind === 'title') {
                $sheet->getStyle('A' . $row_index)->getFont()->setBold(true)->setSize(14);
            } elseif (in_array($kind, ['header', 'region', 'region_total', 'grand_total'], true)) {
                $sheet->getStyle('A' . $row_index . ':B' . $row_index)->getFont()->setBold(true);
            }
            $row_index++;
        }
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
    $exclude_buyclub = !empty($_POST['exclude_buyclub']);
    $live = !empty($_POST['live']);
    $urgency_only = !empty($_POST['urgency_only']);

    $result = intersoccer_office365_generate_final_reports_xlsx($year, $activity_type, $season_type, $region, $exclude_buyclub, $live, $urgency_only);
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
