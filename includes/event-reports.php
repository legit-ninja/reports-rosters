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
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    try {
        if (!function_exists('wc_get_products')) {
            error_log('InterSoccer: wc_get_products not available');
            return [];
        }

        if ($list_variations) {
            $args = [
                'type' => 'variation',
                'limit' => -1,
                'status' => 'publish',
            ];
            if ($region) {
                $args['attribute_pa_canton-region'] = sanitize_title($region);
            }
            if ($camp_type) {
                $args['attribute_pa_age-group'] = sanitize_title($camp_type);
            }
            $variations = wc_get_products($args);
            $variation_ids = array_map(function($v) { return $v->get_id(); }, $variations);

            if (empty($variation_ids)) {
                return [];
            }

            // Prepare the query with placeholders for variation_ids
            $query = "SELECT variation_id, COUNT(*) as total FROM $rosters_table WHERE variation_id IN (" . implode(',', array_fill(0, count($variation_ids), '%d')) . ")";
            $params = $variation_ids;

            if ($year) {
                $query .= " AND YEAR(start_date) = %d";
                $params[] = $year;
            }
            $query .= " GROUP BY variation_id";

            $prepared_query = $wpdb->prepare($query, $params);
            $results = $wpdb->get_results($prepared_query, ARRAY_A);

            $report_data = [];
            foreach ($results as $row) {
                $variation = wc_get_product($row['variation_id']);
                if ($variation) {
                    $report_data[] = [
                        'variation_id' => $row['variation_id'],
                        'variation_name' => $variation->get_name(),
                        'total' => $row['total'],
                    ];
                }
            }
        } else if ($variation_id) {
            $roster = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $rosters_table WHERE variation_id = %d", $variation_id),
                ARRAY_A
            );
            if (empty($roster)) {
                return [];
            }

            $variation = wc_get_product($variation_id);
            $product_id = $variation->get_parent_id();
            $total = count($roster);
            $ages = array_filter(array_column($roster, 'age'), 'is_numeric');
            $average_age = !empty($ages) ? round(array_sum($ages) / count($ages), 1) : 'N/A';
            $genders = array_count_values(array_column($roster, 'gender'));
            $gender_distribution = sprintf('Male: %d, Female: %d, Other: %d', $genders['Male'] ?? 0, $genders['Female'] ?? 0, $genders['Other'] ?? 0);

            $attendees = [];
            foreach ($roster as $player) {
                $days = json_decode($player['day_presence'], true) ?: [];
                $attendees[] = [
                    'name' => $player['player_name'],
                    'age' => $player['age'],
                    'gender' => $player['gender'],
                    'days' => implode(', ', array_keys(array_filter($days))),
                ];
            }

            $report_data = [[
                'variation_id' => $variation_id,
                'variation_name' => $variation->get_name(),
                'venue' => $roster[0]['venue'],
                'region' => wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown',
                'camp_type' => $roster[0]['age_group'],
                'total' => $total,
                'average_age' => $average_age,
                'gender_distribution' => $gender_distribution,
                'attendees' => $attendees,
                'camp_terms' => $roster[0]['camp_terms'] ?? 'N/A',
            ]];
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
        if (!current_user_can('manage_options') || !current_user_can('coach') || !current_user_can('event_organizer')) {
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
