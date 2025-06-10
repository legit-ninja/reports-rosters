<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.3
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Require PHPSpreadsheet autoloader (install via Composer: composer require phpoffice/phpspreadsheet)
require_once dirname(__DIR__) . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export a single roster to Excel.
 *
 * @param array|int $variation_ids The WooCommerce variation ID(s).
 * @param string $format Export format ('excel' only).
 * @param array $context Optional context data (e.g., variation_players).
 */
function intersoccer_export_roster($variation_ids, $format = 'excel', $context = []) {
    try {
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            wp_die(__('You do not have sufficient permissions to export this roster.', 'intersoccer-reports-rosters'));
        }

        if (ob_get_length()) {
            ob_end_clean();
        }

        if (!function_exists('wc_get_product')) {
            error_log('InterSoccer: wc_get_product not available in intersoccer_export_roster');
            wp_die(__('WooCommerce functions not available.', 'intersoccer-reports-rosters'));
        }

        $variation_ids = is_array($variation_ids) ? $variation_ids : [$variation_ids];
        $first_variation_id = reset($variation_ids);
        $variation = wc_get_product($first_variation_id);
        if (!$variation || $variation->get_type() !== 'variation') {
            wp_die(__('Invalid variation ID.', 'intersoccer-reports-rosters'));
        }

        $product_id = $variation->get_parent_id();
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_die(__('Invalid parent product ID.', 'intersoccer-reports-rosters'));
        }

        $roster = intersoccer_pe_get_event_roster_by_variation($variation_ids, $context);
        $is_camp = !empty($roster) && in_array($roster[0]['booking_type'] ?? '', ['Full Week', 'single-days']);

        $booking_type = $roster[0]['booking_type'] ?? 'Unknown';
        $season = $roster[0]['season'] ?? 'Unknown';
        $venue = $roster[0]['venue'] ?? 'Unknown';

        if ($format === 'excel') {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $camp_terms = $context['camp_terms'] ?? $roster[0]['camp_terms'] ?? 'Unknown Term';
            $sanitized_title = preg_replace('/[^A-Za-z0-9\-\s]/', '', $camp_terms);
            $sheet_title = substr($sanitized_title, 0, 31); // Limit to 31 characters
            $sheet->setTitle($sheet_title);

            if ($is_camp) {
                $headers = [
                    __('Venue', 'intersoccer-reports-rosters'),
                    __('Booking Type', 'intersoccer-reports-rosters'),
                    __('Season', 'intersoccer-reports-rosters'),
                    __('Camp Terms', 'intersoccer-reports-rosters'),
                    __('First Name', 'intersoccer-reports-rosters'),
                    __('Last Name', 'intersoccer-reports-rosters'),
                    __('Gender', 'intersoccer-reports-rosters'),
                    __('Parent Phone', 'intersoccer-reports-rosters'),
                    __('Parent Email', 'intersoccer-reports-rosters'),
                    __('Medical/Dietary', 'intersoccer-reports-rosters'),
                    __('Late', 'intersoccer-reports-rosters'),
                    __('Monday', 'intersoccer-reports-rosters'),
                    __('Tuesday', 'intersoccer-reports-rosters'),
                    __('Wednesday', 'intersoccer-reports-rosters'),
                    __('Thursday', 'intersoccer-reports-rosters'),
                    __('Friday', 'intersoccer-reports-rosters'),
                ];
                $sheet->fromArray($headers, NULL, 'A1');

                $row = 2;
                foreach ($roster as $player) {
                    $selected_days = $player['selected_days'] ?? [];
                    $day_presence = array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'No');
                    foreach ($selected_days as $day) {
                        if (in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'])) {
                            $day_presence[$day] = 'Yes';
                        }
                    }

                    $data = [
                        $player['venue'] ?? $venue,
                        $booking_type,
                        $season,
                        $player['camp_terms'] ?? 'N/A',
                        $player['first_name'] ?? 'N/A',
                        $player['last_name'] ?? 'N/A',
                        $player['gender'] ?? 'N/A',
                        $player['parent_phone'] ?? 'N/A',
                        $player['parent_email'] ?? 'N/A',
                        $player['medical_conditions'] ?? 'None',
                        $player['late_pickup'] === '18h' ? __('Yes', 'intersoccer-reports-rosters') : __('No', 'intersoccer-reports-rosters'),
                        $day_presence['Monday'],
                        $day_presence['Tuesday'],
                        $day_presence['Wednesday'],
                        $day_presence['Thursday'],
                        $day_presence['Friday'],
                    ];
                    $sheet->fromArray($data, NULL, 'A' . $row);
                    $row++;
                }
            } else {
                $headers = [
                    __('Venue', 'intersoccer-reports-rosters'),
                    __('Booking Type', 'intersoccer-reports-rosters'),
                    __('Season', 'intersoccer-reports-rosters'),
                    __('Start Date', 'intersoccer-reports-rosters'),
                    __('End Date', 'intersoccer-reports-rosters'),
                    __('First Name', 'intersoccer-reports-rosters'),
                    __('Last Name', 'intersoccer-reports-rosters'),
                    __('Gender', 'intersoccer-reports-rosters'),
                    __('Parent Phone', 'intersoccer-reports-rosters'),
                    __('Parent Email', 'intersoccer-reports-rosters'),
                    __('Medical/Dietary', 'intersoccer-reports-rosters'),
                    __('Late', 'intersoccer-reports-rosters'),
                ];
                $sheet->fromArray($headers, NULL, 'A1');

                $row = 2;
                foreach ($roster as $player) {
                    $data = [
                        $venue,
                        $booking_type,
                        $season,
                        $player['start_date'] ?? 'N/A',
                        $player['end_date'] ?? 'N/A',
                        $player['first_name'] ?? 'N/A',
                        $player['last_name'] ?? 'N/A',
                        $player['gender'] ?? 'N/A',
                        $player['parent_phone'] ?? 'N/A',
                        $player['parent_email'] ?? 'N/A',
                        $player['medical_conditions'] ?? 'None',
                        $player['late_pickup'] === '18h' ? __('Yes', 'intersoccer-reports-rosters') : __('No', 'intersoccer-reports-rosters'),
                    ];
                    $sheet->fromArray($data, NULL, 'A' . $row);
                    $row++;
                }
            }

            $sheet->setCellValue('A' . ($row + 1), __('Total Players:', 'intersoccer-reports-rosters') . ' ' . count($roster));

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="intersoccer_roster_variation_' . $first_variation_id . '_' . date('Y-m-d_H-i-s') . '.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            intersoccer_log_audit('export_roster_excel', "Exported Excel roster for Variation ID(s) " . implode(',', $variation_ids));
            error_log("InterSoccer: Exported roster with title $sheet_title for variation IDs " . implode(',', $variation_ids));
        }

        exit;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_export_roster: ' . $e->getMessage());
        wp_die(__('An error occurred while exporting the roster.', 'intersoccer-reports-rosters'));
    }
}

/**
 * Export all rosters to Excel, grouped by Camp Term and Venue.
 *
 * @param array $camps Camp variations (grouped by configuration).
 * @param array $courses Course variations (grouped by configuration).
 * @param string $export_type Export type ('all', 'camps', 'courses', 'girls_only').
 * @param string $format Export format ('excel' only).
 */
function intersoccer_export_all_rosters($camps, $courses, $export_type = 'all', $format = 'excel') {
    try {
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            wp_die(__('You do not have sufficient permissions to export rosters.', 'intersoccer-reports-rosters'));
        }

        if (ob_get_length()) {
            ob_end_clean();
        }

        $filename_suffix = 'master_rosters';
        if ($export_type === 'camps') {
            $filename_suffix = 'camp_rosters';
        } elseif ($export_type === 'courses') {
            $filename_suffix = 'course_rosters';
        } elseif ($export_type === 'girls_only') {
            $filename_suffix = 'girls_only_rosters';
        }

        if ($format === 'excel') {
            $spreadsheet = new Spreadsheet();
            $sheet_index = 0;

            if ($export_type === 'camps') {
                // Fetch all variable products with Activity Type "Camp"
                $camp_products = wc_get_products([
                    'type' => 'variable',
                    'limit' => -1,
                    'status' => 'publish',
                    'tax_query' => [
                        [
                            'taxonomy' => 'pa_activity-type',
                            'field' => 'name',
                            'terms' => 'camp',
                        ],
                    ],
                ]);

                // Collect all unique camp terms
                $all_camp_terms = [];
                foreach ($camp_products as $product) {
                    $product_id = $product->get_id();
                    $terms = wc_get_product_terms($product_id, 'pa_camp-terms', ['fields' => 'names']);
                    if (!is_wp_error($terms) && !empty($terms)) {
                        $all_camp_terms = array_merge($all_camp_terms, $terms);
                    }
                }
                $all_camp_terms = array_unique($all_camp_terms);
                error_log("InterSoccer: Total unique camp terms found: " . count($all_camp_terms));

                // Generate a tab for each camp term
                foreach ($all_camp_terms as $camp_term) {
                    // Get all variations for this camp term
                    $term_variation_ids = [];
                    foreach ($camp_products as $product) {
                        $product_id = $product->get_id();
                        $variations = $product->get_children();
                        foreach ($variations as $variation_id) {
                            $variation = wc_get_product($variation_id);
                            if ($variation) {
                                $variation_terms = wc_get_product_terms($product_id, 'pa_camp-terms', ['fields' => 'names']);
                                if (in_array($camp_term, $variation_terms)) {
                                    $term_variation_ids[] = $variation_id;
                                }
                            }
                        }
                    }
                    $term_variation_ids = array_unique($term_variation_ids);
                    error_log("InterSoccer: Exporting camp term $camp_term with " . count($term_variation_ids) . " variation IDs");

                    $context = ['camp_terms' => $camp_term];
                    ob_start();
                    intersoccer_export_roster($term_variation_ids, $format, $context);
                    $temp_content = ob_get_clean();

                    // Load the temporary spreadsheet content
                    $temp_spreadsheet = new Spreadsheet();
                    $temp_reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                    $temp_reader->loadFromString($temp_content);
                    $temp_sheet = $temp_spreadsheet->getActiveSheet();

                    // Create a new sheet in the main spreadsheet
                    $new_sheet = $spreadsheet->createSheet($sheet_index);
                    $sanitized_title = preg_replace('/[^A-Za-z0-9\-\s]/', '', $camp_term);
                    $sheet_title = substr($sanitized_title, 0, 31); // Limit to 31 characters
                    $new_sheet->setTitle($sheet_title);
                    $row = 1;
                    foreach ($temp_sheet->getRowIterator() as $row_iter) {
                        $cell_iterator = $row_iter->getCellIterator();
                        $cell_iterator->setIterateOnlyExistingCells(false);
                        $row_data = [];
                        foreach ($cell_iterator as $cell) {
                            $row_data[] = $cell->getValue();
                        }
                        $new_sheet->fromArray($row_data, NULL, 'A' . $row);
                        $row++;
                    }
                    $sheet_index++;
                }
            } elseif ($export_type === 'all' || $export_type === 'courses') {
                $write_rosters = function($variations, $spreadsheet, &$sheet_index, $is_camp = false) {
                    $processed_configs = 0;
                    foreach ($variations as $config_key => $config) {
                        error_log("InterSoccer: Processing config key $config_key with " . count($config['variation_ids']) . " variation IDs");
                        $variation_players = [];
                        foreach ($config['variation_ids'] as $vid) {
                            $query_args = [
                                'post_type' => 'shop_order',
                                'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
                                'posts_per_page' => -1,
                            ];
                            $order_query = new WP_Query($query_args);
                            $orders = $order_query->posts;
                            foreach ($orders as $order_post) {
                                $order = wc_get_order($order_post->ID);
                                if ($order) {
                                    foreach ($order->get_items() as $item) {
                                        if ($item->get_variation_id() == $vid) {
                                            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true) ?: wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                                            if ($player_name && !in_array($player_name, $variation_players)) {
                                                $variation_players[] = $player_name;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $context = [
                            'variation_players' => array_fill_keys($config['variation_ids'], $variation_players),
                            'camp_terms' => $config['camp_terms'] ?? 'N/A',
                            'start_date' => wc_get_product_terms(wc_get_product(reset($config['variation_ids']))->get_parent_id(), 'pa_start-date', ['fields' => 'names'])[0] ?? 'N/A',
                            'end_date' => wc_get_product_terms(wc_get_product(reset($config['variation_ids']))->get_parent_id(), 'pa_end-date', ['fields' => 'names'])[0] ?? 'N/A',
                        ];
                        $roster = intersoccer_pe_get_event_roster_by_variation($config['variation_ids'], $context);
                        if (empty($roster)) {
                            error_log("InterSoccer: No roster data for config key $config_key");
                            continue;
                        }

                        $is_camp = in_array($config['booking_type'], ['Full Week', 'single-days']);
                        $venue = $config['venue'] ?? 'Unknown Venue';
                        $term = $is_camp ? ($config['camp_terms'] ?? 'Unknown Term') : ($config['product_name'] ?? 'Unknown Course');
                        $sheet_title = $is_camp ? "$term - $venue" : "$venue - $term";
                        $sanitized_title = preg_replace('/[^A-Za-z0-9\-\s]/', '', $sheet_title);
                        $sheet_title = substr($sanitized_title, 0, 31); // Limit to 31 characters
                        $sheet = $spreadsheet->createSheet($sheet_index);
                        $sheet->setTitle($sheet_title);

                        if ($is_camp) {
                            $headers = [
                                __('Venue', 'intersoccer-reports-rosters'),
                                __('Camp Term', 'intersoccer-reports-rosters'),
                                __('First Name', 'intersoccer-reports-rosters'),
                                __('Last Name', 'intersoccer-reports-rosters'),
                                __('Gender', 'intersoccer-reports-rosters'),
                                __('Parent Phone', 'intersoccer-reports-rosters'),
                                __('Parent Email', 'intersoccer-reports-rosters'),
                                __('Medical/Dietary', 'intersoccer-reports-rosters'),
                                __('Late', 'intersoccer-reports-rosters'),
                                __('Monday', 'intersoccer-reports-rosters'),
                                __('Tuesday', 'intersoccer-reports-rosters'),
                                __('Wednesday', 'intersoccer-reports-rosters'),
                                __('Thursday', 'intersoccer-reports-rosters'),
                                __('Friday', 'intersoccer-reports-rosters'),
                            ];
                            $sheet->fromArray($headers, NULL, 'A1');

                            $row = 2;
                            foreach ($roster as $player) {
                                $selected_days = $player['selected_days'] ?? [];
                                $day_presence = array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'No');
                                foreach ($selected_days as $day) {
                                    if (in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'])) {
                                        $day_presence[$day] = 'Yes';
                                    }
                                }

                                $data = [
                                    $player['venue'] ?? $venue,
                                    $player['camp_terms'] ?? 'N/A',
                                    $player['first_name'] ?? 'N/A',
                                    $player['last_name'] ?? 'N/A',
                                    $player['gender'] ?? 'N/A',
                                    $player['parent_phone'] ?? 'N/A',
                                    $player['parent_email'] ?? 'N/A',
                                    $player['medical_conditions'] ?? 'None',
                                    $player['late_pickup'] === '18h' ? __('Yes', 'intersoccer-reports-rosters') : __('No', 'intersoccer-reports-rosters'),
                                    $day_presence['Monday'],
                                    $day_presence['Tuesday'],
                                    $day_presence['Wednesday'],
            $day_presence['Thursday'],
            $day_presence['Friday'],
        ];
        $sheet->fromArray($data, NULL, 'A' . $row);
        $row++;
    }
} else {
    $headers = [
        __('Venue', 'intersoccer-reports-rosters'),
        __('Course Name', 'intersoccer-reports-rosters'),
        __('Start Date', 'intersoccer-reports-rosters'),
        __('End Date', 'intersoccer-reports-rosters'),
        __('First Name', 'intersoccer-reports-rosters'),
        __('Last Name', 'intersoccer-reports-rosters'),
        __('Gender', 'intersoccer-reports-rosters'),
        __('Parent Phone', 'intersoccer-reports-rosters'),
        __('Parent Email', 'intersoccer-reports-rosters'),
        __('Medical/Dietary', 'intersoccer-reports-rosters'),
        __('Late', 'intersoccer-reports-rosters'),
    ];
    $sheet->fromArray($headers, NULL, 'A1');

    $row = 2;
    foreach ($roster as $player) {
        $data = [
            $venue,
            $player['product_name'] ?? 'N/A',
            $player['start_date'] ?? 'N/A',
            $player['end_date'] ?? 'N/A',
            $player['first_name'] ?? 'N/A',
            $player['last_name'] ?? 'N/A',
            $player['gender'] ?? 'N/A',
            $player['parent_phone'] ?? 'N/A',
            $player['parent_email'] ?? 'N/A',
            $player['medical_conditions'] ?? 'None',
            $player['late_pickup'] === '18h' ? __('Yes', 'intersoccer-reports-rosters') : __('No', 'intersoccer-reports-rosters'),
        ];
        $sheet->fromArray($data, NULL, 'A' . $row);
        $row++;
    }
}

$sheet->setCellValue('A' . ($row + 1), __('Total Players:', 'intersoccer-reports-rosters') . ' ' . count($roster));
error_log("InterSoccer: Processed sheet for $sheet_title with " . count($roster) . " attendees");
$sheet_index++;
$processed_configs++;
}
error_log("InterSoccer: Total configurations processed for this type: $processed_configs");
};

$has_data = false;
if ($export_type === 'all' || $export_type === 'courses') {
    if (!empty($courses)) {
        $write_rosters($courses, $spreadsheet, $sheet_index, false);
        $has_data = true;
    }
}
}

if (!$has_data) {
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', __('No rosters found matching the selected filters for this export type.', 'intersoccer-reports-rosters'));
}

$spreadsheet->setActiveSheetIndex(0);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="intersoccer_' . $filename_suffix . '_' . date('Y-m-d_H-i-s') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
intersoccer_log_audit('export_all_rosters_excel', "Exported $export_type rosters to Excel");
}

exit;
} catch (Exception $e) {
    error_log('InterSoccer: Error in intersoccer_export_all_rosters: ' . $e->getMessage());
    wp_die(__('An error occurred while exporting rosters.', 'intersoccer-reports-rosters'));
}
}
?>
