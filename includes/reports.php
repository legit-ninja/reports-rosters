<?php
/**
 * Reports page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.3.58
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render the Reports page with tabs.
 */
function intersoccer_render_reports_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
    $tabs = [
        'general' => __('General Reports', 'intersoccer-reports-rosters'),
        'summer-camps' => __('Summer Camps Report', 'intersoccer-reports-rosters'),
    ];
    ?>
    <div class="wrap intersoccer-reports-rosters-reports">
        <h1><?php _e('InterSoccer Reports', 'intersoccer-reports-rosters'); ?></h1>
        <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab => $label): ?>
                <a href="?page=intersoccer-reports&tab=<?php echo esc_attr($tab); ?>" class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="tab-content">
            <?php
            switch ($active_tab) {
                case 'summer-camps':
                    intersoccer_render_summer_camps_report_tab();
                    break;
                case 'general':
                default:
                    echo '<p>' . __('General reports content goes here.', 'intersoccer-reports-rosters') . '</p>';
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Render the Summer Camps Report tab content.
 */
function intersoccer_render_summer_camps_report_tab() {
    $year = isset($_GET['year']) ? sanitize_text_field($_GET['year']) : date('Y');
    $report_data = intersoccer_get_summer_camps_report($year);

    if (isset($_GET['action']) && $_GET['action'] === 'export' && check_admin_referer('export_summer_camps_nonce')) {
        intersoccer_export_summer_camps_csv($report_data, $year);
    }
    ?>
    <div class="wrap intersoccer-reports-rosters-reports-tab">
        <h2><?php echo esc_html("Summer Camps Numbers $year"); ?></h2>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="intersoccer-reports" />
            <input type="hidden" name="tab" value="summer-camps" />
            <label for="year"><?php _e('Year:', 'intersoccer-reports-rosters'); ?></label>
            <input type="number" name="year" id="year" value="<?php echo esc_attr($year); ?>" />
            <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
        </form>
        <div class="export-section">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=intersoccer-reports&tab=summer-camps&year=$year&action=export"), 'export_summer_camps_nonce')); ?>" class="button button-primary"><?php _e('Export to CSV', 'intersoccer-reports-rosters'); ?></a>
        </div>
        <?php if (empty($report_data)): ?>
            <p><?php _e('No data available for the selected year.', 'intersoccer-reports-rosters'); ?></p>
        <?php else: ?>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <th rowspan="2">Week</th>
                        <th rowspan="2">Canton</th>
                        <th rowspan="2">Venue</th>
                        <th colspan="8">Full Day Camps</th>
                        <th colspan="8">Mini - Half Day Camps</th>
                    </tr>
                    <tr>
                        <th>Full Week</th><th>Individual Days - M</th><th>T</th><th>W</th><th>T</th><th>F</th><th>Total Min-Max</th><th></th>
                        <th>Full Week</th><th>Individual Days - M</th><th>T</th><th>W</th><th>T</th><th>F</th><th>Total Min-Max</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data as $week => $regions): ?>
                        <?php foreach ($regions as $region => $venues): ?>
                            <?php foreach ($venues as $venue => $camp_types): ?>
                                <tr>
                                    <?php if (!isset($current_week) || $current_week !== $week): ?>
                                        <td rowspan="<?php echo count($regions) * count($venues); ?>" style="background-color: #f0f0f0; font-weight: bold;"><?php echo esc_html($week); ?></td>
                                        <?php $current_week = $week; ?>
                                    <?php endif; ?>
                                    <td><?php echo esc_html($region); ?></td>
                                    <td><?php echo esc_html($venue); ?></td>
                                    <?php
                                    $full_day = $camp_types['Full Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                                    $mini = $camp_types['Mini - Half Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                                    ?>
                                    <td><?php echo esc_html($full_day['full_week']); ?></td>
                                    <?php foreach ($full_day['individual_days'] as $count): ?>
                                        <td><?php echo esc_html($count); ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo esc_html($full_day['min_max']); ?></td>
                                    <td></td>
                                    <td><?php echo esc_html($mini['full_week']); ?></td>
                                    <?php foreach ($mini['individual_days'] as $count): ?>
                                        <td><?php echo esc_html($count); ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo esc_html($mini['min_max']); ?></td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php unset($current_week); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Generate Summer Camps Report data for a given year.
 *
 * @param string $year The year to filter the report (default: current year).
 * @return array Structured report data.
 */
function intersoccer_get_summer_camps_report($year = null) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    if (!$year) {
        $year = date('Y');
    }

    // Define weeks for Summer based on camp_terms format
    $weeks = [
        'Week 1: June 24 - June 28' => 'week-1-june-24-28',
        'Week 2: June 30 - July 4' => 'week-2-june-30-july-4',
        'Week 3: July 8 - July 12' => 'week-3-july-8-12',
        'Week 4: July 15 - July 19' => 'week-4-july-15-19',
        'Week 5: July 22 - July 26' => 'week-5-july-22-26',
        'Week 6: July 29 - August 2' => 'week-6-july-29-august-2',
        'Week 7: August 5 - August 9' => 'week-7-august-5-9',
        'Week 8: August 12 - August 16' => 'week-8-august-12-16',
        'Week 9: August 19 - August 23' => 'week-9-august-19-23',
        'Week 10: August 26 - August 30' => 'week-10-august-26-30',
    ];

    // Fetch rosters for the year, filtered by Camp activity type, using pa_program-season
    $query = $wpdb->prepare(
        "SELECT r.*, COALESCE(om_season.meta_value, %s) AS season_year, COALESCE(om_region.meta_value, 'Unknown') AS region
         FROM $rosters_table r
         LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_season
         ON r.order_item_id = om_season.order_item_id AND om_season.meta_key = 'pa_program-season'
         LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_region
         ON r.order_item_id = om_region.order_item_id AND om_region.meta_key = 'Canton / Region'
         WHERE r.activity_type = 'Camp'
         AND (om_season.meta_value = %s OR (om_season.meta_value IS NULL AND r.registration_timestamp LIKE %s))",
        $year, $year, "$year%"
    );
    error_log("InterSoccer: Summer Camps Report Query: $query");
    $rosters = $wpdb->get_results($query, ARRAY_A);
    if (empty($rosters)) {
        error_log("InterSoccer: No rosters found for year $year");
        return [];
    }

    // Enrich roster data with camp type (Full Day vs. Mini - Half Day)
    foreach ($rosters as &$roster) {
        $roster['camp_type'] = (stripos($roster['age_group'], '3-5y') !== false || stripos($roster['age_group'], 'half-day') !== false) ? 'Mini - Half Day' : 'Full Day';
    }
    unset($roster);

    // Group rosters by week, region, venue, and camp type using camp_terms
    $report_data = [];
    foreach ($weeks as $week_name => $week_pattern) {
        $week_entries = array_filter($rosters, function($r) use ($week_pattern) {
            return preg_match("/\b$week_pattern\b/", $r['camp_terms']) === 1;
        });
        if (empty($week_entries)) {
            continue;
        }

        $week_groups = [];
        foreach ($week_entries as $entry) {
            $region = $entry['region'];
            $venue = $entry['venue'];
            $camp_type = $entry['camp_type'];
            $key = "$region|$venue|$camp_type";
            $week_groups[$key][] = $entry;
        }

        $report_data[$week_name] = [];
        foreach ($week_groups as $key => $group) {
            list($region, $venue, $camp_type) = explode('|', $key);

            $full_week = 0;
            $individual_days = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0];
            foreach ($group as $entry) {
                if (strtolower($entry['booking_type']) === 'full-week') {
                    $full_week++;
                } elseif (strtolower($entry['booking_type']) === 'single-days' && !empty($entry['selected_days'])) {
                    $days = array_map('trim', explode(',', $entry['selected_days']));
                    foreach ($days as $day) {
                        if (isset($individual_days[$day])) {
                            $individual_days[$day]++;
                        }
                    }
                }
            }

            $daily_counts = [];
            foreach ($individual_days as $day => $count) {
                $daily_counts[$day] = $full_week + $count;
            }
            $min = !empty($daily_counts) ? min($daily_counts) : 0;
            $max = !empty($daily_counts) ? max($daily_counts) : 0;

            $report_data[$week_name][$region][$venue][$camp_type] = [
                'full_week' => $full_week,
                'individual_days' => $individual_days,
                'min_max' => "$min-$max",
            ];
        }
    }

    return $report_data;
}

/**
 * Export Summer Camps Report to CSV.
 *
 * @param array $report_data The report data to export.
 * @param string $year The year of the report.
 */
function intersoccer_export_summer_camps_csv($report_data, $year) {
    if (ob_get_length()) ob_end_clean();

    $filename = "summer_camps_numbers_$year_" . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Headers
    fputcsv($output, ['', 'SUMMER CAMPS NUMBERS ' . $year]);
    fputcsv($output, ['', '', 'Full Day Camps', '', '', '', '', '', '', 'Mini - Half Day Camps']);
    fputcsv($output, ['', '', 'Full Week', 'Individual Days', '', '', '', '', 'Total Min-Max', 'Full Week', 'Individual Days', '', '', '', '', 'Total Min-Max']);
    fputcsv($output, ['', 'Week', 'Canton', 'Venue', 'M', 'T', 'W', 'T', 'F', '', 'M', 'T', 'W', 'T', 'F', '']);

    // Data
    foreach ($report_data as $week => $regions) {
        foreach ($regions as $region => $venues) {
            foreach ($venues as $venue => $camp_types) {
                $full_day = $camp_types['Full Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                $mini = $camp_types['Mini - Half Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                fputcsv($output, [
                    '',
                    $week,
                    $region,
                    $venue,
                    $full_day['full_week'],
                    $full_day['individual_days']['Monday'],
                    $full_day['individual_days']['Tuesday'],
                    $full_day['individual_days']['Wednesday'],
                    $full_day['individual_days']['Thursday'],
                    $full_day['individual_days']['Friday'],
                    $full_day['min_max'],
                    $mini['full_week'],
                    $mini['individual_days']['Monday'],
                    $mini['individual_days']['Tuesday'],
                    $mini['individual_days']['Wednesday'],
                    $mini['individual_days']['Thursday'],
                    $mini['individual_days']['Friday'],
                    $mini['min_max'],
                ]);
            }
        }
    }

    fclose($output);
    exit;
}
?>
