<?php
/**
 * Reports page functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.4
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
                '1.0.4',
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
            'year' => isset($_GET['year']) ? sanitize_text_field($_GET['year']) : '',
        ];
        $report_data = intersoccer_pe_get_camp_report_data($filters['region'], '', '', $filters['year'], true);

        if (isset($_GET['action']) && $_GET['action'] === 'export_all' && check_admin_referer('export_all_reports_nonce')) {
            intersoccer_export_reports_csv($report_data, true);
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
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-reports&action=export_all' . ($filters['region'] ? '&region=' . urlencode($filters['region']) : '') . ($filters['year'] ? '&year=' . urlencode($filters['year']) : '')), 'export_all_reports_nonce')); ?>" class="button button-primary"><?php _e('Export All Reports', 'intersoccer-reports-rosters'); ?></a>
                </div>

                <h2><?php _e('Event Reports', 'intersoccer-reports-rosters'); ?></h2>
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Variation ID', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Variation Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Action', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['variation_id']); ?></td>
                                <td><?php echo esc_html($row['variation_name']); ?></td>
                                <td><?php echo esc_html($row['total']); ?></td>
                                <td><a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-event-reports&variation_id=' . $row['variation_id'])); ?>" class="button"><?php _e('View Report', 'intersoccer-reports-rosters'); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('No reports found matching the selected filters.', 'intersoccer-reports-rosters'); ?></p>
            <?php endif; ?>
        </div>
        <?php
        error_log('InterSoccer: Rendered Reports page');
    } catch (Exception $e) {
        error_log('InterSoccer: Error rendering reports page: ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the reports page.', 'intersoccer-reports-rosters'));
    }
}
?>