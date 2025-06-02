<?php
/**
 * Reports functionality for InterSoccer Reports and Rosters plugin.
 */

// Include external dependencies if needed
require_once plugin_dir_path(__FILE__) . 'event-reports.php';

// Render Reports page
function intersoccer_render_reports_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    // Filter parameters
    $filters = [
        'region' => isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '',
        'week' => isset($_GET['week']) ? sanitize_text_field($_GET['week']) : '',
        'camp_type' => isset($_GET['camp_type']) ? sanitize_text_field($_GET['camp_type']) : '',
        'year' => isset($_GET['year']) ? sanitize_text_field($_GET['year']) : date('Y'),
    ];

    // Pagination and sorting
    $per_page_options = [10, 25, 50, 100];
    $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
    if (!in_array($per_page, $per_page_options)) {
        $per_page = 10;
    }
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($page - 1) * $per_page;
    $sort_by = isset($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : 'week';
    $sort_order = isset($_GET['sort_order']) && $_GET['sort_order'] === 'desc' ? 'DESC' : 'ASC';

    // Fetch attribute terms dynamically
    $regions = intersoccer_get_attribute_terms('pa_canton-region');
    $camp_terms = intersoccer_get_attribute_terms('pa_camp-terms');
    $course_days = intersoccer_get_attribute_terms('pa_course-day');
    $weeks = array_merge($camp_terms, $course_days);
    $camp_types = intersoccer_get_attribute_terms('pa_age-group');
    $years = array_unique(array_merge(
        array_map(function($term) { return substr($term, 0, 4); }, array_filter($weeks, function($term) { return preg_match('/\d{4}/', $term); })),
        [date('Y')]
    ));
    sort($years);

    // Fetch data with pagination
    $report_data = intersoccer_pe_get_camp_report_data($filters['region'], $filters['week'], $filters['camp_type'], $filters['year']);
    $total_items = count($report_data);

    // Sort data
    usort($report_data, function ($a, $b) use ($sort_by, $sort_order) {
        $value_a = $a[$sort_by] ?? '';
        $value_b = $b[$sort_by] ?? '';
        if ($sort_by === 'days') {
            $value_a = $a['days']['Monday'] ?? 0;
            $value_b = $b['days']['Monday'] ?? 0;
        }
        if ($sort_order === 'ASC') {
            return $value_a <=> $value_b;
        }
        return $value_b <=> $value_a;
    });

    // Paginate
    $report_data = array_slice($report_data, $offset, $per_page);

    // Handle CSV export
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        intersoccer_export_reports_csv($filters['region'], $filters['week'], $filters['camp_type'], $filters['year']);
        exit;
    }

    // Start output buffering
    ob_start();
    ?>
    <div class="wrap intersoccer-reports-rosters-dashboard">
        <h1><?php _e('InterSoccer Reports', 'intersoccer-reports-rosters'); ?></h1>

        <!-- Filter Form -->
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="filter-form">
            <input type="hidden" name="page" value="intersoccer-reports" />

            <div class="filter-section">
                <h4><?php _e('Report Filters', 'intersoccer-reports-rosters'); ?></h4>
                <div class="filter-row">
                    <label for="region"><?php _e('Region:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="region" id="region">
                        <option value=""><?php _e('All Regions', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($regions as $r): ?>
                            <option value="<?php echo esc_attr($r); ?>" <?php selected($filters['region'], $r); ?>><?php echo esc_html($r); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="week"><?php _e('Week/Day:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="week" id="week">
                        <option value=""><?php _e('All Weeks/Days', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($weeks as $w): ?>
                            <option value="<?php echo esc_attr($w); ?>" <?php selected($filters['week'], $w); ?>><?php echo esc_html($w); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="camp_type"><?php _e('Camp Type:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="camp_type" id="camp_type">
                        <option value=""><?php _e('All Camp Types', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($camp_types as $ct): ?>
                            <option value="<?php echo esc_attr($ct); ?>" <?php selected($filters['camp_type'], $ct); ?>><?php echo esc_html($ct); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="year"><?php _e('Year:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="year" id="year">
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo esc_attr($y); ?>" <?php selected($filters['year'], $y); ?>><?php echo esc_html($y); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filter-section">
                <h4><?php _e('Actions', 'intersoccer-reports-rosters'); ?></h4>
                <div class="filter-row">
                    <button type="submit" class="button"><?php _e('Apply Filters', 'intersoccer-reports-rosters'); ?></button>
                    <a href="<?php echo esc_url(add_query_arg('export', 'csv')); ?>" class="button"><?php _e('Export to CSV', 'intersoccer-reports-rosters'); ?></a>
                </div>
            </div>
        </form>

        <!-- Data Table -->
        <?php if (empty($report_data)): ?>
            <p><?php _e('No report data available.', 'intersoccer-reports-rosters'); ?></p>
        <?php else: ?>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'venue', 'sort_order' => $sort_by === 'venue' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Venue', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'venue' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'region', 'sort_order' => $sort_by === 'region' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Region', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'region' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'week', 'sort_order' => $sort_by === 'week' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Week', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'week' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'year', 'sort_order' => $sort_by === 'year' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Year', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'year' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'camp_type', 'sort_order' => $sort_by === 'camp_type' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Camp Type', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'camp_type' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'full_week', 'sort_order' => $sort_by === 'full_week' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Full Week', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'full_week' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'buyclub', 'sort_order' => $sort_by === 'buyclub' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('BuyClub', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'buyclub' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'girls_only', 'sort_order' => $sort_by === 'girls_only' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Girls-Only', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'girls_only' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'days', 'sort_order' => $sort_by === 'days' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Monday', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'days' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><?php _e('Tuesday', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Wednesday', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Thursday', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Friday', 'intersoccer-reports-rosters'); ?></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'average_age', 'sort_order' => $sort_by === 'average_age' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Average Age', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'average_age' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'gender_distribution', 'sort_order' => $sort_by === 'gender_distribution' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Gender Distribution', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'gender_distribution' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><a href="<?php echo esc_url(add_query_arg(['sort_by' => 'total', 'sort_order' => $sort_by === 'total' && $sort_order === 'ASC' ? 'desc' : 'asc'])); ?>"><?php _e('Total', 'intersoccer-reports-rosters'); ?> <?php echo $sort_by === 'total' ? ($sort_order === 'ASC' ? '↑' : '↓') : ''; ?></a></th>
                        <th><?php _e('Total Range', 'intersoccer-reports-rosters'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item['venue']); ?></td>
                            <td><?php echo esc_html($item['region']); ?></td>
                            <td><?php echo esc_html($item['week']); ?></td>
                            <td><?php echo esc_html($item['year']); ?></td>
                            <td><?php echo esc_html($item['camp_type']); ?></td>
                            <td><?php echo esc_html($item['full_week']); ?></td>
                            <td><?php echo esc_html($item['buyclub']); ?></td>
                            <td><?php echo esc_html($item['girls_only']); ?></td>
                            <td><?php echo esc_html($item['days']['Monday']); ?></td>
                            <td><?php echo esc_html($item['days']['Tuesday']); ?></td>
                            <td><?php echo esc_html($item['days']['Wednesday']); ?></td>
                            <td><?php echo esc_html($item['days']['Thursday']); ?></td>
                            <td><?php echo esc_html($item['days']['Friday']); ?></td>
                            <td><?php echo esc_html($item['average_age']); ?></td>
                            <td><?php echo esc_html($item['gender_distribution']); ?></td>
                            <td><?php echo esc_html($item['total']); ?></td>
                            <td><?php echo esc_html($item['total_range']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php
            $total_pages = ceil($total_items / $per_page);
            $pagination_args = [
                'base' => add_query_arg('paged', '%#%', admin_url('admin.php?page=intersoccer-reports')),
                'format' => '',
                'current' => $page,
                'total' => $total_pages,
                'prev_text' => __('« Previous'),
                'next_text' => __('Next »'),
            ];
            foreach ($filters as $key => $value) {
                if ($value) $pagination_args['add_args'][$key] = $value;
            }
            $pagination_args['add_args']['per_page'] = $per_page;
            if ($sort_by) $pagination_args['add_args']['sort_by'] = $sort_by;
            if ($sort_order) $pagination_args['add_args']['sort_order'] = $sort_order;
            ?>
            <div class="pagination">
                <?php echo paginate_links($pagination_args); ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    error_log('InterSoccer: Rendered Reports page');
}

// CSV export for reports
function intersoccer_export_reports_csv($region, $week, $camp_type, $year) {
    $report_data = intersoccer_pe_get_camp_report_data($region, $week, $camp_type, $year);

    header('Content-Type: text/csv; charset=utf-8');
    $filename = 'camp_reports_' . ($region ?: 'all') . '_' . ($week ?: 'all') . '_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');

    // Headers matching the table columns
    fputcsv($output, [
        'Venue',
        'Region',
        'Week',
        'Year',
        'Camp Type',
        'Full Week',
        'BuyClub',
        'Girls-Only',
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Average Age',
        'Gender Distribution',
        'Total',
        'Total Range'
    ]);

    // Data
    foreach ($report_data as $item) {
        fputcsv($output, [
            $item['venue'],
            $item['region'],
            $item['week'],
            $item['year'],
            $item['camp_type'],
            $item['full_week'],
            $item['buyclub'],
            $item['girls_only'],
            $item['days']['Monday'],
            $item['days']['Tuesday'],
            $item['days']['Wednesday'],
            $item['days']['Thursday'],
            $item['days']['Friday'],
            $item['average_age'],
            $item['gender_distribution'],
            $item['total'],
            $item['total_range']
        ]);
    }

    fclose($output);
}
?>
