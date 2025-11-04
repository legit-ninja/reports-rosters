<?php
/**
 * InterSoccer Reports - UI Rendering Functions
 *
 * @package InterSoccerReports
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the main reports page
 */
function intersoccer_render_reports_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Enqueue necessary scripts and styles
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
    // wp_enqueue_script('intersoccer-reports-js', plugin_dir_url(__FILE__) . '../js/reports.js', ['jquery'], '1.3.99', true);

    // Localize script for AJAX - use consistent object name
    wp_localize_script('intersoccer-reports-js', 'intersoccer_reports_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('intersoccer_reports_nonce')
    ));

    ?>
    <div class="wrap">
        <h1><?php _e('InterSoccer Reports & Rosters', 'intersoccer-reports-rosters'); ?></h1>

        <h2 class="nav-tab-wrapper">
            <a href="#booking-report" class="nav-tab nav-tab-active"><?php _e('Booking Report', 'intersoccer-reports-rosters'); ?></a>
            <a href="#final-reports" class="nav-tab"><?php _e('Final Numbers', 'intersoccer-reports-rosters'); ?></a>
        </h2>

        <div id="booking-report" class="tab-content">
            <?php intersoccer_render_booking_report_tab(); ?>
        </div>

        <div id="final-reports" class="tab-content" style="display: none;">
            <?php intersoccer_render_final_reports_page(); ?>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('.nav-tab').click(function(e) {
            e.preventDefault();
            var target = $(this).attr('href');

            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            $('.tab-content').hide();
            $(target).show();
        });
    });
    </script>
    <?php
}

/**
 * Render the booking report tab
 */
/**
 * Render the Final Reports page.
 */
function intersoccer_render_final_reports_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    // Enqueue scripts and localize for AJAX
    wp_enqueue_script('jquery');
    wp_enqueue_script('intersoccer-final-reports-js', '', ['jquery'], '1.0.0', true);
    wp_localize_script('intersoccer-final-reports-js', 'intersoccer_reports_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('intersoccer_reports_nonce')
    ));

    $year = isset($_GET['year']) ? sanitize_text_field($_GET['year']) : date('Y');
    $activity_type = isset($_GET['activity_type']) ? sanitize_text_field($_GET['activity_type']) : 'Camp';

    // Determine current page for form action
    $current_page = isset($_GET['page']) ? $_GET['page'] : 'intersoccer-final-reports';
    $show_activity_type_filter = !in_array($current_page, ['intersoccer-final-camp-reports', 'intersoccer-final-course-reports']);

    $report_data = intersoccer_get_final_reports_data($year, $activity_type);
    $totals = intersoccer_calculate_final_reports_totals($report_data, $activity_type);

    ?>
    <script>
    var intersoccer_reports_ajax = {
        ajax_url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('intersoccer_reports_nonce')); ?>'
    };
    </script>
    <div class="wrap intersoccer-reports-rosters-final-reports">
        <h1><?php _e('Final Numbers Report', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php _e('Aggregated booking numbers for camps and courses by week, canton, and venue.', 'intersoccer-reports-rosters'); ?></p>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="<?php echo esc_attr($current_page); ?>" />
            <label for="year"><?php _e('Year:', 'intersoccer-reports-rosters'); ?></label>
            <input type="number" name="year" id="year" value="<?php echo esc_attr($year); ?>" min="2020" max="<?php echo date('Y') + 2; ?>" />
            <?php if ($show_activity_type_filter): ?>
                <label for="activity_type"><?php _e('Activity Type:', 'intersoccer-reports-rosters'); ?></label>
                <select name="activity_type" id="activity_type">
                    <option value="Camp" <?php selected($activity_type, 'Camp'); ?>><?php _e('Camp', 'intersoccer-reports-rosters'); ?></option>
                    <option value="Course" <?php selected($activity_type, 'Course'); ?>><?php _e('Course', 'intersoccer-reports-rosters'); ?></option>
                </select>
            <?php endif; ?>
            <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
        </form>

        <div class="export-section" style="margin-bottom: 20px;">
            <button type="button" id="export-final-reports" class="button button-primary"><?php _e('Export to Excel', 'intersoccer-reports-rosters'); ?></button>
        </div>

        <?php if (empty($report_data)): ?>
            <p><?php _e('No data available for the selected filters.', 'intersoccer-reports-rosters'); ?></p>
        <?php else: ?>
            <?php if ($activity_type === 'Camp'): ?>
                <!-- Camp Report Table -->
                <style>
                .camp-reports-table {
                    table-layout: fixed;
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 12px;
                }
                .camp-reports-table th, .camp-reports-table td {
                    border: 1px solid #ddd;
                    padding: 4px 8px;
                    text-align: center;
                }
                .camp-reports-table col:nth-child(4),
                .camp-reports-table col:nth-child(11) {
                    width: 80px; /* Full Week columns */
                }
                .camp-reports-table col:nth-child(5), .camp-reports-table col:nth-child(6),
                .camp-reports-table col:nth-child(7), .camp-reports-table col:nth-child(8),
                .camp-reports-table col:nth-child(9),
                .camp-reports-table col:nth-child(12), .camp-reports-table col:nth-child(13),
                .camp-reports-table col:nth-child(14), .camp-reports-table col:nth-child(15),
                .camp-reports-table col:nth-child(16) {
                    width: 30px; /* Day columns (M, T, W, T, F) */
                }
                .camp-reports-table col:nth-child(10),
                .camp-reports-table col:nth-child(17) {
                    width: 80px; /* Total min-max columns */
                }
                </style>
                <table class="widefat fixed striped camp-reports-table">
                    <colgroup>
                        <col style="width: 120px;"> <!-- Week -->
                        <col style="width: 120px;"> <!-- Canton -->
                        <col style="width: 120px;"> <!-- Venue -->
                        <col style="width: 80px;">  <!-- Full Day Full Week -->
                        <col style="width: 30px;">  <!-- Full Day M -->
                        <col style="width: 30px;">  <!-- Full Day T -->
                        <col style="width: 30px;">  <!-- Full Day W -->
                        <col style="width: 30px;">  <!-- Full Day T -->
                        <col style="width: 30px;">  <!-- Full Day F -->
                        <col style="width: 80px;">  <!-- Full Day Total -->
                        <col style="width: 80px;">  <!-- Mini Full Week -->
                        <col style="width: 30px;">  <!-- Mini M -->
                        <col style="width: 30px;">  <!-- Mini T -->
                        <col style="width: 30px;">  <!-- Mini W -->
                        <col style="width: 30px;">  <!-- Mini T -->
                        <col style="width: 30px;">  <!-- Mini F -->
                        <col style="width: 80px;">  <!-- Mini Total -->
                    </colgroup>
                    <thead>
                        <tr style="background-color: #f1f1f1;">
                            <th rowspan="2" style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php _e('Week', 'intersoccer-reports-rosters'); ?></th>
                            <th rowspan="2" style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php _e('Canton', 'intersoccer-reports-rosters'); ?></th>
                            <th rowspan="2" style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                            <th colspan="7" style="border: 1px solid #ddd; padding: 8px; text-align: center; background-color: #e8f4f8;"><?php _e('Full Day Camps', 'intersoccer-reports-rosters'); ?></th>
                            <th colspan="7" style="border: 1px solid #ddd; padding: 8px; text-align: center; background-color: #f8e8f4;"><?php _e('Mini - Half Day Camps', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                        <tr style="background-color: #f8f8f8;">
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('Full Week', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('M', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('T', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('W', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('T', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('F', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('Total min-max', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('Full Week', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('M', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('T', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('W', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('T', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('F', 'intersoccer-reports-rosters'); ?></th>
                            <th style="border: 1px solid #ddd; padding: 4px; text-align: center;"><?php _e('Total min-max', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $previous_week = null;
                        $previous_canton = null;
                        ?>
                        <?php foreach ($report_data as $week => $cantons): ?>
                            <?php 
                            // Calculate week totals
                            $week_totals = [
                                'full_day' => ['full_week' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0]],
                                'mini' => ['full_week' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0]]
                            ];
                            
                            foreach ($cantons as $canton => $venues) {
                                foreach ($venues as $venue => $data) {
                                    $full_day = $data['Full Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0)];
                                    $mini = $data['Mini - Half Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0)];
                                    
                                    $week_totals['full_day']['full_week'] += $full_day['full_week'];
                                    foreach ($full_day['individual_days'] as $day => $count) {
                                        $week_totals['full_day']['individual_days'][$day] += $count;
                                    }
                                    
                                    $week_totals['mini']['full_week'] += $mini['full_week'];
                                    foreach ($mini['individual_days'] as $day => $count) {
                                        $week_totals['mini']['individual_days'][$day] += $count;
                                    }
                                }
                            }
                            ?>
                            
                            <!-- Week Total Row -->
                            <tr style="background-color: #f0f0f0; font-weight: bold;">
                                <td colspan="3" style="text-align: left; padding: 12px;"><?php echo esc_html($week); ?> - TOTAL</td>
                                <!-- Full Day Totals -->
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['full_day']['full_week']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['full_day']['individual_days']['Monday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['full_day']['individual_days']['Tuesday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['full_day']['individual_days']['Wednesday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['full_day']['individual_days']['Thursday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['full_day']['individual_days']['Friday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php 
                                    $full_day_counts = array_values($week_totals['full_day']['individual_days']);
                                    $full_day_min = !empty($full_day_counts) ? min($full_day_counts) : 0;
                                    $full_day_max = !empty($full_day_counts) ? max($full_day_counts) : 0;
                                    echo esc_html($full_day_min . '-' . $full_day_max);
                                ?></td>
                                <!-- Mini Totals -->
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['mini']['full_week']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['mini']['individual_days']['Monday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['mini']['individual_days']['Tuesday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['mini']['individual_days']['Wednesday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['mini']['individual_days']['Thursday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($week_totals['mini']['individual_days']['Friday']); ?></td>
                                <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php 
                                    $mini_counts = array_values($week_totals['mini']['individual_days']);
                                    $mini_min = !empty($mini_counts) ? min($mini_counts) : 0;
                                    $mini_max = !empty($mini_counts) ? max($mini_counts) : 0;
                                    echo esc_html($mini_min . '-' . $mini_max);
                                ?></td>
                            </tr>
                            
                            <?php foreach ($cantons as $canton => $venues): ?>
                                <?php foreach ($venues as $venue => $data): ?>
                                    <tr style="border: 1px solid #ddd;">
                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"></td>
                                        <td style="background-color: #f8f9fa; font-weight: bold; border: 1px solid #ddd; padding: 8px; text-align: center; <?php if ($previous_canton === $canton && $previous_week === $week) echo 'visibility: hidden;'; ?>"><?php echo esc_html($canton); ?></td>
                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($venue); ?></td>
                                        <?php
                                        $full_day = $data['Full Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                                        $mini = $data['Mini - Half Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                                        ?>
                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($full_day['full_week']); ?></td>
                                        <?php foreach ($full_day['individual_days'] as $count): ?>
                                            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($count); ?></td>
                                        <?php endforeach; ?>
                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($full_day['min_max']); ?></td>
                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($mini['full_week']); ?></td>
                                        <?php foreach ($mini['individual_days'] as $count): ?>
                                            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($count); ?></td>
                                        <?php endforeach; ?>
                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo esc_html($mini['min_max']); ?></td>
                                    </tr>
                                    <?php $previous_canton = $canton; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            <?php $previous_week = $week; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Camp Totals -->
                <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                    <h3><?php _e('Totals', 'intersoccer-reports-rosters'); ?></h3>
                    <table class="widefat fixed" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th><?php _e('Category', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Direct Online', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Total Registrations', 'intersoccer-reports-rosters'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php _e('Full Day Camps', 'intersoccer-reports-rosters'); ?></td>
                                <td><?php echo esc_html($totals['full_day']['online']); ?></td>
                                <td><?php echo esc_html($totals['full_day']['total']); ?></td>
                            </tr>
                            <tr>
                                <td><?php _e('Mini - Half Day Camps', 'intersoccer-reports-rosters'); ?></td>
                                <td><?php echo esc_html($totals['mini']['online']); ?></td>
                                <td><?php echo esc_html($totals['mini']['total']); ?></td>
                            </tr>
                            <tr style="font-weight: bold; background: #e9ecef;">
                                <td><?php _e('All Registrations', 'intersoccer-reports-rosters'); ?></td>
                                <td><?php echo esc_html($totals['all']['online']); ?></td>
                                <td><?php echo esc_html($totals['all']['total']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <!-- Course Report Table -->
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Course Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Course Day', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Direct Online', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Total', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Final', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $current_region = '';
                        $current_course = '';
                        foreach ($report_data as $region => $courses): ?>
                            <?php $region_total = $totals['regions'][$region] ?? ['bo' => 0, 'pitch_side' => 0, 'buyclub' => 0, 'total' => 0, 'final' => 0]; ?>
                            <tr style="background-color: #f0f0f0; font-weight: bold;">
                                <td colspan="2"><?php echo esc_html($region); ?> - TOTAL</td>
                                <td></td>
                                <td><?php echo esc_html($region_total['online']); ?></td>
                                <td><?php echo esc_html($region_total['total']); ?></td>
                                <td><?php echo esc_html($region_total['final']); ?></td>
                            </tr>
                            <?php foreach ($courses as $course_name => $data): ?>
                                <?php foreach ($data as $course_day => $course_data): ?>
                                    <tr>
                                        <td></td>
                                        <td><?php echo esc_html($course_name); ?></td>
                                        <td><?php echo esc_html($course_day); ?></td>
                                        <td><?php echo esc_html($course_data['online']); ?></td>
                                        <td><?php echo esc_html($course_data['total']); ?></td>
                                        <td><?php echo esc_html($course_data['final']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Course Overall Totals -->
                <div style="margin-top: 30px; padding: 20px; background: #f9f9fa; border-radius: 8px;">
                    <h3><?php _e('Overall Totals', 'intersoccer-reports-rosters'); ?></h3>
                    <table class="widefat fixed" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th><?php _e('Category', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Online', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Total', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Final', 'intersoccer-reports-rosters'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="font-weight: bold; background: #e9ecef;">
                                <td><?php _e('All Courses', 'intersoccer-reports-rosters'); ?></td>
                                <td><?php echo esc_html($totals['all']['online']); ?></td>
                                <td><?php echo esc_html($totals['all']['total']); ?></td>
                                <td><?php echo esc_html($totals['all']['final']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <script>
    jQuery(document).ready(function($) {
        // Handle final reports export
        $('#export-final-reports').click(function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originalText = $button.text();
            
            // Disable button and show loading
            $button.prop('disabled', true).text('<?php _e('Exporting...', 'intersoccer-reports-rosters'); ?>');
            
            // Get current filter values
            var year = $('input[name="year"]').val();
            var activity_type = $('select[name="activity_type"]').val();
            
            // If no select element (on specific camp/course pages), use the PHP variable
            if (!activity_type) {
                activity_type = '<?php echo esc_js($activity_type); ?>';
            }
            
            $.ajax({
                url: intersoccer_reports_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'intersoccer_export_final_reports',
                    nonce: intersoccer_reports_ajax.nonce,
                    year: year,
                    activity_type: activity_type
                },
                success: function(response) {
                    if (response.success) {
                        // Create and trigger download (same as booking reports)
                        var binary = atob(response.data.content);
                        var array = new Uint8Array(binary.length);
                        for (var i = 0; i < binary.length; i++) {
                            array[i] = binary.charCodeAt(i);
                        }
                        var blob = new Blob([array], {
                            type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        });
                        var link = document.createElement("a");
                        link.href = window.URL.createObjectURL(blob);
                        link.download = response.data.filename;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        
                        // Show success notification (same as booking reports)
                        showNotification("<?php _e('Export completed successfully!', 'intersoccer-reports-rosters'); ?>", "success");
                    } else {
                        showNotification("<?php _e('Export failed:', 'intersoccer-reports-rosters'); ?> " + (response.data.message || "<?php _e('Unknown error', 'intersoccer-reports-rosters'); ?>"), "error");
                        console.error("Export error:", response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    if (status === "timeout") {
                        showNotification("<?php _e('Export timeout. Please try again.', 'intersoccer-reports-rosters'); ?>", "error");
                    } else {
                        showNotification("<?php _e('Export failed: Connection error', 'intersoccer-reports-rosters'); ?>", "error");
                    }
                    console.error("AJAX export error:", error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Notification system (same as booking reports)
        function showNotification(message, type) {
            var $notification = $("<div class=\"notice notice-" + type + " is-dismissible\"><p>" + message + "</p></div>");
            $(".wrap h1").after($notification);
            setTimeout(function() {
                $notification.fadeOut();
            }, 5000);
        }
    });
    </script>
    <?php
}

/**
 * Get rowspan for a week in the final reports table.
 */
function intersoccer_get_rowspan_for_week($week_data) {
    $count = 0;
    foreach ($week_data as $cantons) {
        foreach ($cantons as $venues) {
            $count += count($venues);
        }
    }
    return $count;
}

/**
 * Get rowspan for a canton in the final reports table.
 */
function intersoccer_get_rowspan_for_canton($canton_data) {
    $count = 0;
    foreach ($canton_data as $venues) {
        $count += count($venues);
    }
    return $count;
}

/**
 * Render the Final Camp Reports page
 */
function intersoccer_render_final_camp_reports_page() {
    // Set activity type to Camp and call the main final reports function
    $_GET['activity_type'] = 'Camp';
    intersoccer_render_final_reports_page();
}

/**
 * Render the Final Course Reports page
 */
function intersoccer_render_final_course_reports_page() {
    // Set activity type to Course and call the main final reports function
    $_GET['activity_type'] = 'Course';
    intersoccer_render_final_reports_page();
}