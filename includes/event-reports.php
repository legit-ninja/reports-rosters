<?php
/**
 * Event reports functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.4
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Fetch camp report data with age and gender.
 *
 * @param string $region Region filter.
 * @param string $week Week filter (Y-m-d).
 * @param string $camp_type Camp type filter.
 * @param string $year Year filter.
 * @param bool $list_variations Whether to list all variations.
 * @param int $variation_id Specific variation ID to filter.
 * @return array Report data.
 */
function intersoccer_pe_get_camp_report_data($region = '', $week = '', $camp_type = '', $year = '', $list_variations = false, $variation_id = 0) {
    try {
        if (!function_exists('wc_get_products')) {
            error_log('InterSoccer: wc_get_products not available in intersoccer_pe_get_camp_report_data');
            return [];
        }

        $args = [
            'type' => 'variable',
            'limit' => -1,
            'status' => 'publish',
        ];
        $products = wc_get_products($args);
        $report_data = [];

        $query_args = [
            'post_type' => 'shop_order',
            'post_status' => ['wc-completed', 'wc-processing'],
            'posts_per_page' => -1,
        ];

        if ($week) {
            $query_args['date_query'] = [
                [
                    'after' => $week,
                    'before' => date('Y-m-d', strtotime($week . ' + 6 days')),
                    'inclusive' => true,
                ],
            ];
        }

        if ($year) {
            $query_args['date_query'] = [
                [
                    'year' => $year,
                ],
            ];
        }

        $orders = get_posts($query_args);

        foreach ($products as $product) {
            $product_id = $product->get_id();
            $variations = $product->get_children();

            foreach ($variations as $var_id) {
                $variation = wc_get_product($var_id);
                if (!$variation || ($variation_id && $var_id != $variation_id)) {
                    continue;
                }

                $variation_region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
                $variation_venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
                $variation_camp_type = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names'])[0] ?? 'Unknown';
                $variation_booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
                $variation_name = $variation->get_name();

                if ($region && $region !== $variation_region) {
                    continue;
                }
                if ($camp_type && $camp_type !== $variation_camp_type) {
                    continue;
                }

                if ($list_variations) {
                    $total = 0;
                    foreach ($orders as $order_post) {
                        $order = wc_get_order($order_post->ID);
                        if (!$order) continue;
                        foreach ($order->get_items() as $item) {
                            if ($item->get_variation_id() == $var_id) $total += $item->get_quantity();
                        }
                    }
                    $report_data[] = [
                        'variation_id' => $var_id,
                        'variation_name' => $variation_name,
                        'total' => $total,
                    ];
                    continue;
                }

                $days = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0];
                $total = 0;
                $ages = [];
                $genders = ['Male' => 0, 'Female' => 0, 'Other' => 0];
                $attendees = [];

                foreach ($orders as $order_post) {
                    $order = wc_get_order($order_post->ID);
                    if (!$order) continue;
                    foreach ($order->get_items() as $item) {
                        if ($item->get_variation_id() != $var_id) continue;

                        $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
                        $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                        $user_id = $order->get_user_id();
                        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];

                        if (!$player_name && $player_index && isset($players[$player_index])) {
                            $player = $players[$player_index];
                            $player_name = $player['first_name'] . ' ' . $player['last_name'];
                            wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                        }

                        if (!$player_name) continue;

                        $age = 'N/A';
                        $gender = 'N/A';
                        if ($player_index && isset($players[$player_index])) {
                            $player = $players[$player_index];
                            if (isset($player['dob']) && !empty($player['dob'])) {
                                $dob = DateTime::createFromFormat('Y-m-d', $player['dob']);
                                if ($dob) {
                                    $current_date = new DateTime(current_time('Y-m-d'));
                                    $age = $dob->diff($current_date)->y;
                                }
                            }
                            $gender = isset($player['gender']) && !empty($player['gender']) ? ucfirst($player['gender']) : 'Other';
                        }

                        $days_of_week = wc_get_order_item_meta($item->get_id(), 'Days of Week', true);
                        $days_array = $days_of_week ? explode(',', $days_of_week) : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

                        if ($variation_booking_type === 'Full Week') {
                            foreach ($days as $day => $count) $days[$day]++;
                        } else {
                            foreach ($days_array as $day) if (isset($days[trim($day)])) $days[trim($day)]++;
                        }

                        $total += $item->get_quantity();
                        if (is_numeric($age)) $ages[] = $age;
                        if (in_array($gender, ['Male', 'Female', 'Other'])) $genders[$gender]++;
                        $attendees[] = [
                            'name' => $player_name,
                            'age' => $age,
                            'gender' => $gender,
                            'days' => implode(', ', $days_array),
                        ];
                    }
                }

                $average_age = !empty($ages) ? round(array_sum($ages) / count($ages), 1) : 'N/A';
                $gender_distribution = sprintf('Male: %d, Female: %d, Other: %d', $genders['Male'], $genders['Female'], $genders['Other']);
                $report_data[] = [
                    'variation_id' => $var_id,
                    'variation_name' => $variation_name,
                    'venue' => $variation_venue,
                    'region' => $variation_region,
                    'camp_type' => $variation_camp_type,
                    'total' => $total,
                    'average_age' => $average_age,
                    'gender_distribution' => $gender_distribution,
                    'attendees' => $attendees,
                ];
            }
        }

        return $report_data;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_pe_get_camp_report_data: ' . $e->getMessage());
        return [];
    }
}

/**
 * Render the Event Report page.
 */
function intersoccer_render_event_report_page() {
    try {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
        }

        $variation_id = isset($_GET['variation_id']) ? intval($_GET['variation_id']) : 0;
        $report_data = intersoccer_pe_get_camp_report_data('', '', '', '', false, $variation_id);

        if (isset($_GET['action']) && $_GET['action'] === 'export' && check_admin_referer('export_report_nonce')) {
            intersoccer_export_reports_csv($report_data, false, $variation_id);
        }

        if (empty($report_data)) {
            wp_die(__('No report data available for this variation.', 'intersoccer-reports-rosters'));
        }

        $row = $report_data[0];
        ?>
        <div class="wrap intersoccer-reports-rosters-reports">
            <h1><?php echo esc_html(__('Event Report for ', 'intersoccer-reports-rosters') . $row['variation_name']); ?></h1>
            <div class="export-section">
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-event-reports&variation_id=' . $variation_id . '&action=export'), 'export_report_nonce')); ?>" class="button button-primary"><?php _e('Export Report', 'intersoccer-reports-rosters'); ?></a>
            </div>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <th><?php _e('Attendee Name', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Age', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Gender', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Days', 'intersoccer-reports-rosters'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($row['attendees'] as $attendee): ?>
                        <tr>
                            <td><?php echo esc_html($attendee['name']); ?></td>
                            <td><?php echo esc_html($attendee['age']); ?></td>
                            <td><?php echo esc_html($attendee['gender']); ?></td>
                            <td><?php echo esc_html($attendee['days']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><?php echo esc_html(__('Total Attendees: ', 'intersoccer-reports-rosters') . $row['total']); ?></p>
            <p><?php echo esc_html(__('Average Age: ', 'intersoccer-reports-rosters') . $row['average_age']); ?></p>
            <p><?php echo esc_html(__('Gender Distribution: ', 'intersoccer-reports-rosters') . $row['gender_distribution']); ?></p>
        </div>
        <?php
    } catch (Exception $e) {
        error_log('InterSoccer: Error rendering event report page: ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the event report page.', 'intersoccer-reports-rosters'));
    }
}

/**
 * Export reports to CSV.
 *
 * @param array $report_data Report data to export.
 * @param bool $all_events Whether to export all events.
 * @param int $variation_id Specific variation ID for single event export.
 */
function intersoccer_export_reports_csv($report_data, $all_events = true, $variation_id = 0) {
    try {
        if (ob_get_length()) ob_end_clean();

        $filename = $all_events ? 'all_reports_' : 'event_report_' . $variation_id . '_';
        $filename .= date('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        if ($all_events) {
            $headers = ['Variation ID', 'Variation Name', 'Venue', 'Region', 'Camp Type', 'Total Players', 'Average Age', 'Gender Distribution'];
            fputcsv($output, $headers);
            foreach ($report_data as $row) {
                fputcsv($output, [
                    $row['variation_id'],
                    $row['variation_name'],
                    $row['venue'],
                    $row['region'],
                    $row['camp_type'],
                    $row['total'],
                    $row['average_age'],
                    $row['gender_distribution'],
                ]);
            }
        } else {
            $row = $report_data[0];
            $headers = ['Attendee Name', 'Age', 'Gender', 'Days'];
            fputcsv($output, $headers);
            foreach ($row['attendees'] as $attendee) {
                fputcsv($output, [
                    $attendee['name'],
                    $attendee['age'],
                    $attendee['gender'],
                    $attendee['days'],
                ]);
            }
        }

        fclose($output);
        intersoccer_log_audit('export_reports_csv', 'Exported ' . ($all_events ? 'all reports' : 'event report for variation ' . $variation_id) . ' to CSV');
        exit;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_export_reports_csv: ' . $e->getMessage());
        wp_die(__('An error occurred while exporting reports to CSV.', 'intersoccer-reports-rosters'));
    }
}
?>
