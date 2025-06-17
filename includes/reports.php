<?php
/**
 * Reports page functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.3
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Enqueue Chart.js for Reports page
add_action('admin_enqueue_scripts', function ($hook) {
    try {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'intersoccer-reports-rosters_page_intersoccer-reports') {
            wp_enqueue_script(
                'chart-js',
                'https://cdn.jsdelivr.net/npm/chart.js',
                [],
                '3.9.1',
                true
            );
            wp_enqueue_script(
                'intersoccer-reports-charts',
                plugin_dir_url(__FILE__) . '../js/reports-charts.js',
                ['chart-js'],
                '1.0.3',
                true
            );
        }
    } catch (Exception $e) {
        error_log('InterSoccer: Error enqueuing scripts in reports.php: ' . $e->getMessage());
    }
});

/**
 * Render the Reports page.
 */
function intersoccer_render_reports_page() {
    try {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
        }

        $filters = [
            'region' => isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '',
            'week' => isset($_GET['week']) ? sanitize_text_field($_GET['week']) : '',
            'camp_type' => isset($_GET['camp_type']) ? sanitize_text_field($_GET['camp_type']) : '',
            'year' => isset($_GET['year']) ? sanitize_text_field($_GET['year']) : '',
        ];

        $report_data = intersoccer_pe_get_camp_report_data($filters['region'], $filters['week'], $filters['camp_type'], $filters['year']);

        $region_totals = [];
        foreach ($report_data as $row) {
            $region = $row['region'];
            $total = $row['total'];
            if (!isset($region_totals[$region])) {
                $region_totals[$region] = 0;
            }
            $region_totals[$region] += $total;
        }
        $region_labels = json_encode(array_keys($region_totals));
        $region_values = json_encode(array_values($region_totals));

        if (isset($_GET['action']) && $_GET['action'] === 'export' && check_admin_referer('export_reports_nonce')) {
            intersoccer_export_reports_csv($report_data);
        }

        ?>
        <div class="wrap intersoccer-reports-rosters-reports">
            <h1><?php _e('InterSoccer Reports', 'intersoccer-reports-rosters'); ?></h1>

            <form id="reports-filter-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="intersoccer-reports" />
                <div class="filter-section">
                    <h2><?php _e('Filter Reports', 'intersoccer-reports-rosters'); ?></h2>
                    <div class="filter-row">
                        <label for="region"><?php _e('Region:', 'intersoccer-reports-rosters'); ?></label>
                        <select name="region" id="region">
                            <option value=""><?php _e('All Regions', 'intersoccer-reports-rosters'); ?></option>
                            <?php
                            $regions = get_terms(['taxonomy' => 'pa_canton-region', 'fields' => 'names']);
                            if (!is_wp_error($regions)) {
                                foreach ($regions as $region) {
                                    ?>
                                    <option value="<?php echo esc_attr($region); ?>" <?php selected($filters['region'], $region); ?>><?php echo esc_html($region); ?></option>
                                    <?php
                                }
                            }
                            ?>
                        </select>

                        <label for="week"><?php _e('Week:', 'intersoccer-reports-rosters'); ?></label>
                        <input type="date" name="week" id="week" value="<?php echo esc_attr($filters['week']); ?>" />

                        <label for="camp_type"><?php _e('Camp Type:', 'intersoccer-reports-rosters'); ?></label>
                        <select name="camp_type" id="camp_type">
                            <option value=""><?php _e('All Camp Types', 'intersoccer-reports-rosters'); ?></option>
                            <?php
                            $camp_types = get_terms(['taxonomy' => 'pa_age-group', 'fields' => 'names']);
                            if (!is_wp_error($camp_types)) {
                                foreach ($camp_types as $camp_type) {
                                    ?>
                                    <option value="<?php echo esc_attr($camp_type); ?>" <?php selected($filters['camp_type'], $camp_type); ?>><?php echo esc_html($camp_type); ?></option>
                                    <?php
                                }
                            }
                            ?>
                        </select>

                        <label for="year"><?php _e('Year:', 'intersoccer-reports-rosters'); ?></label>
                        <input type="number" name="year" id="year" value="<?php echo esc_attr($filters['year']); ?>" placeholder="<?php _e('e.g., 2025', 'intersoccer-reports-rosters'); ?>" />
                    </div>

                    <div class="filter-row">
                        <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
                    </div>
                </div>
            </form>

            <?php if (!empty($report_data)): ?>
                <div class="export-section">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-reports&action=export' . ($filters['region'] ? '&region=' . urlencode($filters['region']) : '') . ($filters['week'] ? '&week=' . urlencode($filters['week']) : '') . ($filters['camp_type'] ? '&camp_type=' . urlencode($filters['camp_type']) : '') . ($filters['year'] ? '&year=' . urlencode($filters['year']) : '')), 'export_reports_nonce')); ?>" class="button button-primary"><?php _e('Export to CSV', 'intersoccer-reports-rosters'); ?></a>
                </div>

                <div class="filter-section">
                    <h2><?php _e('Total Attendees by Region', 'intersoccer-reports-rosters'); ?></h2>
                    <canvas id="regionAttendeesChart" width="400" height="200"></canvas>
                </div>

                <h2><?php _e('Camp Reports', 'intersoccer-reports-rosters'); ?></h2>
                <div class="table-responsive">
                    <table class="widefat fixed">
                        <thead>
                            <tr>
                                <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Week', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Year', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Camp Type', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Full Week', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('BuyClub', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Girls Only', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Daily Breakdown', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Total', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Total Range', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Average Age', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Gender Distribution', 'intersoccer-reports-rosters'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td><?php echo esc_html($row['venue']); ?></td>
                                    <td><?php echo esc_html($row['region']); ?></td>
                                    <td><?php echo esc_html($row['week']); ?></td>
                                    <td><?php echo esc_html($row['year']); ?></td>
                                    <td><?php echo esc_html($row['camp_type']); ?></td>
                                    <td><?php echo esc_html($row['full_week']); ?></td>
                                    <td><?php echo esc_html($row['buyclub']); ?></td>
                                    <td><?php echo esc_html($row['girls_only']); ?></td>
                                    <td>
                                        <?php
                                        $daily_breakdown = [];
                                        foreach ($row['days'] as $day => $count) {
                                            $daily_breakdown[] = substr($day, 0, 3) . ': ' . $count;
                                        }
                                        echo esc_html(implode(', ', $daily_breakdown));
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($row['total']); ?></td>
                                    <td><?php echo esc_html($row['total_range']); ?></td>
                                    <td><?php echo esc_html($row['average_age']); ?></td>
                                    <td><?php echo esc_html($row['gender_distribution']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p><?php _e('No reports found matching the selected filters.', 'intersoccer-reports-rosters'); ?></p>
            <?php endif; ?>
        </div>

        <script>
            var regionAttendeesChartData = {
                labels: <?php echo $region_labels; ?>,
                values: <?php echo $region_values; ?>,
            };
        </script>
        <?php
        error_log('InterSoccer: Rendered Reports page');
    } catch (Exception $e) {
        error_log('InterSoccer: Error rendering reports page: ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the reports page.', 'intersoccer-reports-rosters'));
    }
}


/**
 * Export reports to CSV.
 *
 * @param array $report_data Report data to export.
 */
function intersoccer_export_reports_csv($report_data) {
    try {
        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="camp_reports_' . (isset($_GET['region']) ? sanitize_text_field($_GET['region']) : 'all') . '_' . (isset($_GET['week']) ? sanitize_text_field($_GET['week']) : 'all') . '_' . (isset($_GET['year']) ? sanitize_text_field($_GET['year']) : 'all') . '_' . date('Y-m-d_H-i-s') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        $headers = [
            'Venue',
            'Region',
            'Week',
            'Year',
            'Camp Type',
            'Full Week',
            'BuyClub',
            'Girls Only',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Total',
            'Total Range',
            'Average Age',
            'Gender Distribution',
        ];
        fputcsv($output, $headers);

        foreach ($report_data as $row) {
            $csv_row = [
                $row['venue'],
                $row['region'],
                $row['week'],
                $row['year'],
                $row['camp_type'],
                $row['full_week'],
                $row['buyclub'],
                $row['girls_only'],
                $row['days']['Monday'],
                $row['days']['Tuesday'],
                $row['days']['Wednesday'],
                $row['days']['Thursday'],
                $row['days']['Friday'],
                $row['total'],
                $row['total_range'],
                $row['average_age'],
                $row['gender_distribution'],
            ];
            fputcsv($output, $csv_row);
        }

        fclose($output);
        intersoccer_log_audit('export_reports_csv', 'Exported camp reports to CSV');
        exit;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_export_reports_csv: ' . $e->getMessage());
        wp_die(__('An error occurred while exporting reports to CSV.', 'intersoccer-reports-rosters'));
    }
}
?>
