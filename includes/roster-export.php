<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.3.2
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

        // Reordered headers with "Surname" instead of "Last Name"
        $headers = array_keys($roster[0]);
        $reordered_headers = [];
        foreach (['player_name', 'last_name', 'gender', 'parent_phone', 'parent_email', 'age', 'medical_conditions'] as $key) {
            if (in_array($key, $headers)) {
                $reordered_headers[$key] = $key === 'last_name' ? 'Surname' : ucfirst(str_replace('_', ' ', $key));
            }
        }
        // Append remaining headers
        foreach ($headers as $header) {
            if (!in_array($header, array_keys($reordered_headers))) {
                $reordered_headers[$header] = ucfirst(str_replace('_', ' ', $header));
            }
        }
        $sheet->fromArray(array_values($reordered_headers), NULL, 'A1');

        $row = 2;
        foreach ($roster as $player) {
            $data = array_values($player);
            $reordered_data = [];
            foreach (array_keys($reordered_headers) as $key) {
                $reordered_data[] = $player[$key] ?? 'N/A';
            }
            $sheet->fromArray($reordered_data, NULL, 'A' . $row++);
        }
        $sheet->setCellValue('A' . $row, 'Total Players: ' . count($roster));

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
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
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="intersoccer_' . ($export_type === 'all' ? 'master' : $export_type) . '_rosters_' . date('Y-m-d_H-i-s') . '.csv"');
            $output = fopen('php://output', 'w');
            if ($export_type === 'all') {
                $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC", ARRAY_A);
                if (empty($rosters)) wp_die(__('No roster data.', 'intersoccer-reports-rosters'));
                $headers = array_keys($rosters[0]);
                $reordered_headers = [];
                foreach (['player_name', 'last_name', 'gender', 'parent_phone', 'parent_email', 'age', 'medical_conditions'] as $key) {
                    if (in_array($key, $headers)) {
                        $reordered_headers[$key] = $key === 'last_name' ? 'Surname' : ucfirst(str_replace('_', ' ', $key));
                    }
                }
                foreach ($headers as $header) {
                    if (!in_array($header, array_keys($reordered_headers))) {
                        $reordered_headers[$header] = ucfirst(str_replace('_', ' ', $header));
                    }
                }
                fputcsv($output, array_values($reordered_headers));
                foreach ($rosters as $roster) {
                    $reordered_data = [];
                    foreach (array_keys($reordered_headers) as $key) {
                        $reordered_data[] = $roster[$key] ?? 'N/A';
                    }
                    fputcsv($output, array_map('mb_convert_encoding', $reordered_data, 'UTF-8', 'auto'));
                }
            } else {
                $export_types = ['camps' => $camps, 'courses' => $courses, 'girls_only' => $girls_only];
                foreach (array_intersect_key($export_types, array_fill_keys([$export_type], true)) as $type => $variations) {
                    if (empty($variations)) continue;
                    foreach ($variations as $config_key => $config) {
                        $roster = intersoccer_pe_get_event_roster_by_variation($config['variation_ids']);
                        if (empty($roster)) continue;
                        $headers = array_keys($roster[0]);
                        $reordered_headers = [];
                        foreach (['player_name', 'last_name', 'gender', 'parent_phone', 'parent_email', 'age', 'medical_conditions'] as $key) {
                            if (in_array($key, $headers)) {
                                $reordered_headers[$key] = $key === 'last_name' ? 'Surname' : ucfirst(str_replace('_', ' ', $key));
                            }
                        }
                        foreach ($headers as $header) {
                            if (!in_array($header, array_keys($reordered_headers))) {
                                $reordered_headers[$header] = ucfirst(str_replace('_', ' ', $header));
                            }
                        }
                        fputcsv($output, array_values($reordered_headers));
                        foreach ($roster as $player) {
                            $reordered_data = [];
                            foreach (array_keys($reordered_headers) as $key) {
                                $reordered_data[] = $player[$key] ?? 'N/A';
                            }
                            fputcsv($output, array_map('mb_convert_encoding', $reordered_data, 'UTF-8', 'auto'));
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
            $headers = array_keys($rosters[0]);
            $reordered_headers = [];
            foreach (['player_name', 'last_name', 'gender', 'parent_phone', 'parent_email', 'age', 'medical_conditions'] as $key) {
                if (in_array($key, $headers)) {
                    $reordered_headers[$key] = $key === 'last_name' ? 'Surname' : ucfirst(str_replace('_', ' ', $key));
                }
            }
            foreach ($headers as $header) {
                if (!in_array($header, array_keys($reordered_headers))) {
                    $reordered_headers[$header] = ucfirst(str_replace('_', ' ', $header));
                }
            }
            $sheet->fromArray(array_values($reordered_headers), NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $day_presence = json_decode($roster['day_presence'], true) ?: ['Monday' => 'No', 'Tuesday' => 'No', 'Wednesday' => 'No', 'Thursday' => 'No', 'Friday' => 'No'];
                if (strtolower($roster['booking_type']) === 'full week') {
                    $day_presence = ['Monday' => 'Yes', 'Tuesday' => 'Yes', 'Wednesday' => 'Yes', 'Thursday' => 'Yes', 'Friday' => 'Yes'];
                }
                $reordered_data = [];
                foreach (array_keys($reordered_headers) as $key) {
                    $reordered_data[] = $roster[$key] ?? 'N/A';
                }
                $sheet->fromArray($reordered_data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'courses') {
            $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE activity_type = 'Course' ORDER BY updated_at DESC", ARRAY_A);
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' course rosters for export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No course roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Course_Rosters');
            $headers = array_keys($rosters[0]);
            $reordered_headers = [];
            foreach (['player_name', 'last_name', 'gender', 'parent_phone', 'parent_email', 'age', 'medical_conditions'] as $key) {
                if (in_array($key, $headers)) {
                    $reordered_headers[$key] = $key === 'last_name' ? 'Surname' : ucfirst(str_replace('_', ' ', $key));
                }
            }
            foreach ($headers as $header) {
                if (!in_array($header, array_keys($reordered_headers))) {
                    $reordered_headers[$header] = ucfirst(str_replace('_', ' ', $header));
                }
            }
            $sheet->fromArray(array_values($reordered_headers), NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $reordered_data = [];
                foreach (array_keys($reordered_headers) as $key) {
                    $reordered_data[] = $roster[$key] ?? 'N/A';
                }
                $sheet->fromArray($reordered_data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } elseif ($export_type === 'all') {
            $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC", ARRAY_A);
            error_log('InterSoccer: Retrieved ' . count($rosters) . ' rows for all rosters export by user ' . $user_id);
            if (empty($rosters)) wp_die(__('No roster data.', 'intersoccer-reports-rosters'));

            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('All_Rosters');
            $headers = array_keys($rosters[0]);
            $reordered_headers = [];
            foreach (['player_name', 'last_name', 'gender', 'parent_phone', 'parent_email', 'age', 'medical_conditions'] as $key) {
                if (in_array($key, $headers)) {
                    $reordered_headers[$key] = $key === 'last_name' ? 'Surname' : ucfirst(str_replace('_', ' ', $key));
                }
            }
            foreach ($headers as $header) {
                if (!in_array($header, array_keys($reordered_headers))) {
                    $reordered_headers[$header] = ucfirst(str_replace('_', ' ', $header));
                }
            }
            $sheet->fromArray(array_values($reordered_headers), NULL, 'A1');

            $row = 2;
            foreach ($rosters as $roster) {
                $reordered_data = [];
                foreach (array_keys($reordered_headers) as $key) {
                    $reordered_data[] = $roster[$key] ?? 'N/A';
                }
                $sheet->fromArray($reordered_data, NULL, 'A' . $row++);
            }
            $sheet->setCellValue('A' . $row, 'Total Players: ' . count($rosters));
        } else {
            $export_types = ['girls_only' => $girls_only];
            foreach (array_intersect_key($export_types, array_fill_keys([$export_type], true)) as $type => $variations) {
                if (empty($variations)) continue;
                foreach ($variations as $config_key => $config) {
                    $roster = intersoccer_pe_get_event_roster_by_variation($config['variation_ids']);
                    if (empty($roster)) continue;
                    $sheet = $spreadsheet->createSheet();
                    $sheet_title = substr(preg_replace('/[^A-Za-z0-9\-\s]/', '', $config['product_name'] . ' - ' . $config['venues'][array_key_first($config['venues'])]['venue'] ?? ''), 0, 31);
                    $sheet->setTitle($sheet_title);

                    $headers = array_keys($roster[0]);
                    $reordered_headers = [];
                    foreach (['player_name', 'last_name', 'gender', 'parent_phone', 'parent_email', 'age', 'medical_conditions'] as $key) {
                        if (in_array($key, $headers)) {
                            $reordered_headers[$key] = $key === 'last_name' ? 'Surname' : ucfirst(str_replace('_', ' ', $key));
                        }
                    }
                    foreach ($headers as $header) {
                        if (!in_array($header, array_keys($reordered_headers))) {
                            $reordered_headers[$header] = ucfirst(str_replace('_', ' ', $header));
                        }
                    }
                    $sheet->fromArray(array_values($reordered_headers), NULL, 'A1');

                    $row = 2;
                    foreach ($roster as $player) {
                        $reordered_data = [];
                        foreach (array_keys($reordered_headers) as $key) {
                            $reordered_data[] = $player[$key] ?? 'N/A';
                        }
                        $sheet->fromArray($reordered_data, NULL, 'A' . $row++);
                    }
                    $sheet->setCellValue('A' . $row, 'Total Players: ' . count($roster));
                }
            }
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
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
?>
