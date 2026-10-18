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
        $sheet->mergeCells('C2:M2');
        $sheet->setCellValue('N2', 'Mini - Half Day Camps');
        $sheet->mergeCells('N2:X2');
        $sheet->getStyle('C2:X2')->getFont()->setBold(true);

        $headers_r3 = [
            'C' => 'Full Week', 'D' => 'BuyClub', 'E' => 'Unknown type', 'F' => 'Days unknown', 'G' => 'Single-day places',
            'H' => 'Day slots (not summed)', 'M' => 'Total min-max',
            'N' => 'Full Week', 'O' => 'BuyClub', 'P' => 'Unknown type', 'Q' => 'Days unknown', 'R' => 'Single-day places',
            'S' => 'Day slots (not summed)', 'X' => 'Total min-max',
        ];
        foreach ($headers_r3 as $col => $label) {
            $sheet->setCellValue($col . '3', $label);
        }
        $sheet->mergeCells('H3:L3');
        $sheet->mergeCells('S3:W3');
        foreach ([['H', 'M'], ['I', 'T'], ['J', 'W'], ['K', 'T'], ['L', 'F'],
                  ['S', 'M'], ['T', 'T'], ['U', 'W'], ['V', 'T'], ['W', 'F']] as $pair) {
            $sheet->setCellValue($pair[0] . '4', $pair[1]);
        }
        $sheet->setCellValue('A5', 'Canton');
        $sheet->getStyle('A3:X4')->getFont()->setBold(true);

        $row_index = 5;
        $write_metrics = static function ($sheet, $row, $start_col, array $m) {
            $row_values = function_exists('intersoccer_reports_camp_export_metric_row_values')
                ? intersoccer_reports_camp_export_metric_row_values($m)
                : intersoccer_reports_camp_export_metric_place_values($m);
            $cols = [];
            $ord = ord($start_col);
            for ($i = 0; $i < 11; $i++) {
                $cols[] = chr($ord + $i);
            }
            foreach ($row_values as $i => $val) {
                if (!isset($cols[$i])) {
                    break;
                }
                $sheet->setCellValue($cols[$i] . $row, $val);
            }
            $sheet->setCellValue($cols[10] . $row, (string) (
                function_exists('intersoccer_reports_normalize_camp_metrics')
                    ? (intersoccer_reports_normalize_camp_metrics($m)['min_max'] ?? '0-0')
                    : ($m['min_max'] ?? '0-0')
            ));
            return $cols[10];
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
                $mini_mm_col = $write_metrics($sheet, $row_index, 'N', $mini);
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
            $sheet->setCellValue('E' . $row_index, 'Unknown type');
            $sheet->setCellValue('F' . $row_index, 'Days unknown');
            $sheet->setCellValue('G' . $row_index, 'Single-day places');
            $sheet->setCellValue('H' . $row_index, 'Day slots (not summed)');
            $sheet->setCellValue('N' . $row_index, 'Full Week');
            $sheet->setCellValue('O' . $row_index, 'BuyClub');
            $sheet->setCellValue('P' . $row_index, 'Unknown type');
            $sheet->setCellValue('Q' . $row_index, 'Days unknown');
            $sheet->setCellValue('R' . $row_index, 'Single-day places');
            $sheet->setCellValue('S' . $row_index, 'Day slots (not summed)');
            $sheet->getStyle('C' . $row_index . ':S' . $row_index)->getFont()->setBold(true);
            $row_index++;

            $sheet->setCellValue('B' . $row_index, 'TOTAL');
            $sheet->setCellValue('C' . $row_index, (int) $grand['full_day']['full_week']);
            $sheet->setCellValue('D' . $row_index, (int) $grand['full_day']['buyclub']);
            $sheet->setCellValue('E' . $row_index, (int) ($grand['full_day']['unknown_booking_type'] ?? 0));
            $sheet->setCellValue('F' . $row_index, (int) ($grand['full_day']['days_unknown'] ?? 0));
            $sheet->setCellValue('G' . $row_index, (int) ($grand['full_day']['single_day_places'] ?? 0));
            $sheet->setCellValue('H' . $row_index, (int) $grand['full_day']['individual_day_slots']);
            $sheet->setCellValue('N' . $row_index, (int) $grand['mini']['full_week']);
            $sheet->setCellValue('O' . $row_index, (int) $grand['mini']['buyclub']);
            $sheet->setCellValue('P' . $row_index, (int) ($grand['mini']['unknown_booking_type'] ?? 0));
            $sheet->setCellValue('Q' . $row_index, (int) ($grand['mini']['days_unknown'] ?? 0));
            $sheet->setCellValue('R' . $row_index, (int) ($grand['mini']['single_day_places'] ?? 0));
            $sheet->setCellValue('S' . $row_index, (int) $grand['mini']['individual_day_slots']);
            $sheet->getStyle('B' . $row_index . ':S' . $row_index)->getFont()->setBold(true);
            $row_index++;

            $sheet->setCellValue('B' . $row_index, 'All registrations');
            $sheet->setCellValue('C' . $row_index, (int) $grand['full_day']['all_registrations']);
            $sheet->setCellValue('G' . $row_index, (int) $grand['full_day']['individual_day_slots']);
            $sheet->setCellValue('M' . $row_index, (int) $grand['mini']['all_registrations']);
            $sheet->setCellValue('Q' . $row_index, (int) $grand['mini']['individual_day_slots']);
            $sheet->getStyle('B' . $row_index . ':Q' . $row_index)->getFont()->setBold(true);
            $row_index++;

            if ($camp_player_registration_totals !== null && !empty($camp_player_registration_totals['not_on_roster'])) {
                $sheet->setCellValue('B' . $row_index, 'Not on roster');
                $sheet->setCellValue('C' . $row_index, (int) $camp_player_registration_totals['not_on_roster']);
                $sheet->getStyle('B' . $row_index . ':C' . $row_index)->getFont()->setBold(true);
            }
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

    $can_export = current_user_can('manage_options')
        || current_user_can('manage_woocommerce')
        || current_user_can('export_intersoccer_rosters');
    if (!$can_export) {
        wp_send_json_error(['message' => __('You do not have sufficient permissions to export reports.', 'intersoccer-reports-rosters')]);
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
        wp_send_json_error(['message' => __('Failed to generate report.', 'intersoccer-reports-rosters')]);
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
