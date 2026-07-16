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

// Include reports-data.php to access helper functions
require_once plugin_dir_path(__FILE__) . 'reports-data.php';

/**
 * Render a Final Numbers cell with urgency heatmap styling.
 *
 * @param string $display_text Escaped or plain text to show (will be escaped).
 * @param string $band         Urgency CSS class from intersoccer_reports_urgency_band().
 */
function intersoccer_reports_render_urgency_heat_cell($display_text, $band) {
	$band = (string) $band;
	printf(
		'<td class="intersoccer-urgency-heat" style="border: 1px solid #ddd; padding: 8px; text-align: center;"><span class="count-number %1$s" style="display:inline-block;padding:4px 8px;border-radius:4px;color:#fff;font-weight:600;">%2$s</span></td>',
		esc_attr($band),
		esc_html((string) $display_text)
	);
}

/**
 * Render the main reports page
 */
function intersoccer_render_reports_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
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
    $season_type = isset($_GET['season_type']) ? sanitize_text_field($_GET['season_type']) : '';
    $region = isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '';
    $exclude_buyclub = !empty($_GET['exclude_buyclub']);
    $live = !empty($_GET['live']);
    $urgency_only = !empty($_GET['urgency_only']);
    $status_mode = $live ? 'live' : 'final';

    // Determine current page for form action
    $current_page = isset($_GET['page']) ? $_GET['page'] : 'intersoccer-final-reports';
    $show_activity_type_filter = !in_array($current_page, ['intersoccer-final-camp-reports', 'intersoccer-final-course-reports']);

    // Query unique values for filter dropdowns
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $year_int = intval($year);

    $final_statuses = function_exists('intersoccer_reports_final_report_order_statuses')
        ? intersoccer_reports_final_report_order_statuses($status_mode)
        : ($live ? ['wc-completed', 'wc-processing'] : ['wc-completed']);
    $final_status_sql = function_exists('intersoccer_reports_sql_in_placeholders')
        ? intersoccer_reports_sql_in_placeholders($final_statuses)
        : '%s';
    
    // Get unique season types for the selected year and activity type
    $season_types_query = $wpdb->prepare(
        "SELECT DISTINCT r.season 
         FROM $rosters_table r
         JOIN {$wpdb->prefix}woocommerce_order_items oi ON r.order_item_id = oi.order_item_id
         JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
         WHERE r.activity_type = %s
         AND r.season LIKE %s
         AND p.post_type = 'shop_order'
         AND p.post_status IN ({$final_status_sql})
         AND r.season IS NOT NULL
         AND r.season != ''
         ORDER BY r.season ASC",
        array_merge([$activity_type, '%' . $year_int . '%'], $final_statuses)
    );
    $seasons = $wpdb->get_col($season_types_query);
    
    // Extract unique season types
    $unique_season_types = [];
    foreach ($seasons as $season) {
        $extracted_type = intersoccer_extract_season_type($season);
        if ($extracted_type && !in_array($extracted_type, $unique_season_types)) {
            $unique_season_types[] = $extracted_type;
        }
    }
    sort($unique_season_types);
    
    // Region options: canton_region is often empty; region column holds the value (log H1: 0 vs 8).
    $listing_activity_types = function_exists('intersoccer_roster_listing_activity_types')
        ? intersoccer_roster_listing_activity_types(strtolower($activity_type) === 'course' ? 'course' : 'camp')
        : [$activity_type];
    $listing_activity_sql = function_exists('intersoccer_reports_sql_in_placeholders')
        ? intersoccer_reports_sql_in_placeholders($listing_activity_types)
        : '%s';

    $regions_query = $wpdb->prepare(
        "SELECT DISTINCT COALESCE(NULLIF(TRIM(r.canton_region), ''), NULLIF(TRIM(r.region), ''))
         FROM $rosters_table r
         JOIN {$wpdb->prefix}woocommerce_order_items oi ON r.order_item_id = oi.order_item_id
         JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
         WHERE r.activity_type IN ({$listing_activity_sql})
         AND r.season LIKE %s
         AND p.post_type = 'shop_order'
         AND p.post_status IN ({$final_status_sql})
         AND COALESCE(NULLIF(TRIM(r.canton_region), ''), NULLIF(TRIM(r.region), '')) IS NOT NULL
         AND COALESCE(NULLIF(TRIM(r.canton_region), ''), NULLIF(TRIM(r.region), '')) != ''
         ORDER BY 1 ASC",
        array_merge($listing_activity_types, ['%' . $year_int . '%'], $final_statuses)
    );
    $regions = $wpdb->get_col($regions_query);
    $regions = array_values(array_filter(array_map('trim', is_array($regions) ? $regions : [])));
    sort($regions, SORT_NATURAL | SORT_FLAG_CASE);

    $report_data = intersoccer_get_final_reports_data($year, $activity_type, $season_type ?: null, $region ?: null, $exclude_buyclub, $live);

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

    ?>
    <script>
    var intersoccer_reports_ajax = {
        ajax_url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('intersoccer_reports_nonce')); ?>'
    };
    </script>
    <div class="wrap intersoccer-reports-rosters-final-reports">
        <h1><?php echo $live
            ? esc_html__('Live Numbers Report', 'intersoccer-reports-rosters')
            : esc_html__('Final Numbers Report', 'intersoccer-reports-rosters'); ?></h1>
        <?php if ($live): ?>
            <div class="notice notice-info" style="margin: 12px 0 16px;">
                <p style="margin:0.5em 0;"><strong><?php esc_html_e('Live figures (completed + processing)', 'intersoccer-reports-rosters'); ?></strong>
                — <?php esc_html_e('Includes processing orders so enrollment reflects current bookings. Classic Final Reports remain completed-only for historical comparisons.', 'intersoccer-reports-rosters'); ?></p>
            </div>
        <?php else: ?>
            <p><?php _e('Aggregated booking numbers for camps and courses by week, canton, and venue.', 'intersoccer-reports-rosters'); ?></p>
        <?php endif; ?>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="<?php echo esc_attr($current_page); ?>" />
            <?php if ($live): ?>
                <input type="hidden" name="live" value="1" />
            <?php endif; ?>
            <label for="year"><?php _e('Year:', 'intersoccer-reports-rosters'); ?></label>
            <input type="number" name="year" id="year" value="<?php echo esc_attr($year); ?>" min="2020" max="<?php echo date('Y') + 2; ?>" />
            <?php if ($show_activity_type_filter): ?>
                <label for="activity_type"><?php _e('Activity Type:', 'intersoccer-reports-rosters'); ?></label>
                <select name="activity_type" id="activity_type">
                    <option value="Camp" <?php selected($activity_type, 'Camp'); ?>><?php _e('Camp', 'intersoccer-reports-rosters'); ?></option>
                    <option value="Course" <?php selected($activity_type, 'Course'); ?>><?php _e('Course', 'intersoccer-reports-rosters'); ?></option>
                </select>
            <?php endif; ?>
            <?php if ($activity_type === 'Camp'): ?>
                <label for="season_type"><?php _e('Season Type:', 'intersoccer-reports-rosters'); ?></label>
                <select name="season_type" id="season_type">
                    <option value=""><?php _e('All Seasons', 'intersoccer-reports-rosters'); ?></option>
                    <?php foreach ($unique_season_types as $st): ?>
                        <option value="<?php echo esc_attr($st); ?>" <?php selected($season_type, $st); ?>><?php echo esc_html($st); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="region"><?php _e('Region:', 'intersoccer-reports-rosters'); ?></label>
                <select name="region" id="region">
                    <option value=""><?php _e('All Regions', 'intersoccer-reports-rosters'); ?></option>
                    <?php foreach ($regions as $reg): ?>
                        <option value="<?php echo esc_attr($reg); ?>" <?php selected($region, $reg); ?>><?php echo esc_html($reg); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <label style="margin-left:8px;">
                <input type="checkbox" name="exclude_buyclub" value="1" <?php checked($exclude_buyclub); ?> />
                <?php _e('Exclude BuyClub (100% coupon / already paid via buyclub.ch)', 'intersoccer-reports-rosters'); ?>
            </label>
            <label style="margin-left:8px;">
                <input type="checkbox" name="urgency_only" value="1" <?php checked($urgency_only); ?> />
                <?php esc_html_e('Show Critical + Low only', 'intersoccer-reports-rosters'); ?>
            </label>
            <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
        </form>

        <p class="description" style="margin-bottom:12px;">
            <strong><?php esc_html_e('Enrollment heatmap:', 'intersoccer-reports-rosters'); ?></strong>
            <?php esc_html_e('Critical ≤7 · Low ≤20 · Good ≤29 · Optimal 30+', 'intersoccer-reports-rosters'); ?>
            <?php if ($activity_type === 'Camp'): ?>
                — <?php esc_html_e('Camps: Total min–max cells use the max of the range.', 'intersoccer-reports-rosters'); ?>
            <?php else: ?>
                — <?php esc_html_e('Courses: Registrations cells are colored.', 'intersoccer-reports-rosters'); ?>
            <?php endif; ?>
        </p>
        <style>
            .intersoccer-reports-rosters-final-reports .count-number.count-critical { background: #dc2626; }
            .intersoccer-reports-rosters-final-reports .count-number.count-low { background: #d97706; }
            .intersoccer-reports-rosters-final-reports .count-number.count-good { background: #059669; }
            .intersoccer-reports-rosters-final-reports .count-number.count-optimal { background: #1d4ed8; }
        </style>

        <div class="export-section" style="margin-bottom: 20px;<?php echo $live ? ' position: sticky; top: 32px; z-index: 10; background: #f0f0f1; padding: 12px; border: 1px solid #c3c4c7; border-radius: 4px;' : ''; ?>">
            <label style="margin-right:12px;"><input type="checkbox" id="final-reports-sync-office365" value="1" /> <?php _e('Also sync to Office 365', 'intersoccer-reports-rosters'); ?></label>
            <button type="button" id="export-final-reports" class="button button-primary button-hero"><?php echo $live
                ? esc_html__('Export Live Figures to Excel', 'intersoccer-reports-rosters')
                : esc_html__('Export to Excel', 'intersoccer-reports-rosters'); ?></button>
        </div>

        <?php if (empty($report_data)): ?>
            <p><?php _e('No data available for the selected filters.', 'intersoccer-reports-rosters'); ?></p>
        <?php else: ?>
            <?php if ($activity_type === 'Camp'): ?>
                <?php
                $camp_grand = function_exists('intersoccer_reports_camp_grand_totals')
                    ? intersoccer_reports_camp_grand_totals($report_data, $urgency_only)
                    : null;
                ?>
                <!-- Camp Report Table (summer camps numbers grid without Pitchside) -->
                <h2 style="margin: 16px 0 8px;"><?php echo esc_html(sprintf(__('SUMMER CAMPS NUMBERS %s', 'intersoccer-reports-rosters'), $year)); ?></h2>
                <p class="description"><?php esc_html_e('Full Week + BuyClub = All registrations. BuyClub rows are WooCommerce registrations with a 100% coupon (already paid via buyclub.ch)—use “Exclude BuyClub” to omit them. Individual days are summed separately across M–F. Total min–max: min = Full Week + BuyClub; max = that base plus the weekday with the most single-day bookings.', 'intersoccer-reports-rosters'); ?></p>
                <style>
                .camp-reports-table { table-layout: auto; width: 100%; border-collapse: collapse; font-size: 12px; }
                .camp-reports-table th, .camp-reports-table td { border: 1px solid #ddd; padding: 4px 6px; text-align: center; }
                .camp-reports-table .week-header td { background: #f0f0f0; font-weight: bold; text-align: left; }
                .camp-reports-table .grand-total td { background: #e8e8e8; font-weight: bold; }
                .camp-reports-table .all-reg td { background: #f8f8f8; font-weight: bold; }
                </style>
                <table class="widefat striped camp-reports-table">
                    <thead>
                        <tr style="background-color: #f1f1f1;">
                            <th rowspan="3"><?php esc_html_e('Canton', 'intersoccer-reports-rosters'); ?></th>
                            <th rowspan="3"><?php esc_html_e('Venue / Week', 'intersoccer-reports-rosters'); ?></th>
                            <th colspan="8" style="background-color: #e8f4f8;"><?php esc_html_e('Full Day Camps', 'intersoccer-reports-rosters'); ?></th>
                            <th colspan="8" style="background-color: #f8e8f4;"><?php esc_html_e('Mini - Half Day Camps', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                        <tr style="background-color: #f8f8f8;">
                            <th><?php esc_html_e('Full Week', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('BuyClub', 'intersoccer-reports-rosters'); ?></th>
                            <th colspan="5"><?php esc_html_e('Individual days', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Total min-max', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Full Week', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('BuyClub', 'intersoccer-reports-rosters'); ?></th>
                            <th colspan="5"><?php esc_html_e('Individual days', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Total min-max', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                        <tr style="background-color: #fafafa;">
                            <th></th><th></th>
                            <th>M</th><th>T</th><th>W</th><th>T</th><th>F</th>
                            <th></th>
                            <th></th><th></th>
                            <th>M</th><th>T</th><th>W</th><th>T</th><th>F</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $week => $cantons): ?>
                            <?php
                            if ($week === '__player_registration_totals__' || !is_array($cantons)) {
                                continue;
                            }
                            $week_venue_rows = [];
                            foreach ($cantons as $canton => $venues) {
                                if (!is_array($venues)) {
                                    continue;
                                }
                                foreach ($venues as $venue => $data) {
                                    if (!is_array($data)) {
                                        continue;
                                    }
                                    if ($urgency_only && function_exists('intersoccer_reports_camp_venue_is_urgent')
                                        && !intersoccer_reports_camp_venue_is_urgent($data)) {
                                        continue;
                                    }
                                    $week_venue_rows[] = [
                                        'canton' => $canton,
                                        'venue' => $venue,
                                        'data' => $data,
                                    ];
                                }
                            }
                            if (empty($week_venue_rows)) {
                                continue;
                            }
                            ?>
                            <tr class="week-header">
                                <td></td>
                                <td colspan="17"><?php echo esc_html($week); ?></td>
                            </tr>
                            <?php
                            $previous_canton = null;
                            foreach ($week_venue_rows as $venue_row):
                                $canton = $venue_row['canton'];
                                $venue = $venue_row['venue'];
                                $data = $venue_row['data'];
                                $full_day = function_exists('intersoccer_reports_normalize_camp_metrics')
                                    ? intersoccer_reports_normalize_camp_metrics($data['Full Day'] ?? null)
                                    : ($data['Full Day'] ?? []);
                                $mini = function_exists('intersoccer_reports_normalize_camp_metrics')
                                    ? intersoccer_reports_normalize_camp_metrics($data['Mini - Half Day'] ?? null)
                                    : ($data['Mini - Half Day'] ?? []);
                                ?>
                                <tr>
                                    <td style="background-color: #f8f9fa; font-weight: bold;<?php echo ($previous_canton === $canton) ? ' visibility: hidden;' : ''; ?>"><?php echo esc_html($canton); ?></td>
                                    <td style="text-align: left;"><?php echo esc_html($venue); ?></td>
                                    <?php intersoccer_reports_echo_camp_metrics_cells($full_day); ?>
                                    <?php intersoccer_reports_echo_camp_metrics_cells($mini); ?>
                                </tr>
                                <?php $previous_canton = $canton; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>

                        <?php if (is_array($camp_grand)): ?>
                            <tr class="grand-total">
                                <td></td>
                                <td style="text-align: left;"><?php esc_html_e('TOTAL', 'intersoccer-reports-rosters'); ?></td>
                                <td><?php echo esc_html((string) (int) $camp_grand['full_day']['full_week']); ?></td>
                                <td><?php echo esc_html((string) (int) $camp_grand['full_day']['buyclub']); ?></td>
                                <td colspan="5"><?php echo esc_html((string) (int) $camp_grand['full_day']['individual_day_slots']); ?></td>
                                <td></td>
                                <td><?php echo esc_html((string) (int) $camp_grand['mini']['full_week']); ?></td>
                                <td><?php echo esc_html((string) (int) $camp_grand['mini']['buyclub']); ?></td>
                                <td colspan="5"><?php echo esc_html((string) (int) $camp_grand['mini']['individual_day_slots']); ?></td>
                                <td></td>
                            </tr>
                            <tr class="all-reg">
                                <td></td>
                                <td style="text-align: left;"><?php esc_html_e('All registrations', 'intersoccer-reports-rosters'); ?></td>
                                <td colspan="2"><?php echo esc_html((string) (int) $camp_grand['full_day']['all_registrations']); ?></td>
                                <td colspan="5"><?php echo esc_html((string) (int) $camp_grand['full_day']['individual_day_slots']); ?></td>
                                <td></td>
                                <td colspan="2"><?php echo esc_html((string) (int) $camp_grand['mini']['all_registrations']); ?></td>
                                <td colspan="5"><?php echo esc_html((string) (int) $camp_grand['mini']['individual_day_slots']); ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <!-- Course Report Table -->
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Course Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Course Day', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Times', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Registrations', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($report_data as $region => $venues): ?>
                            <?php if ($region === '__player_registration_totals__' || !is_array($venues)) { continue; } ?>
                            <?php
                            $region_rows = [];
                            $region_regs = 0;
                            foreach ($venues as $venue => $course_rows) {
                                if (!is_array($course_rows)) {
                                    continue;
                                }
                                foreach ($course_rows as $course_data) {
                                    if (!is_array($course_data)) {
                                        continue;
                                    }
                                    if ($urgency_only && function_exists('intersoccer_reports_course_row_is_urgent')
                                        && !intersoccer_reports_course_row_is_urgent($course_data)) {
                                        continue;
                                    }
                                    $regs = (int) ($course_data['registrations'] ?? 0);
                                    $region_regs += $regs;
                                    $region_rows[] = [
                                        'venue' => $venue,
                                        'course_data' => $course_data,
                                    ];
                                }
                            }
                            if (empty($region_rows)) {
                                continue;
                            }
                            ?>
                            <tr style="background-color: #f0f0f0; font-weight: bold;">
                                <td colspan="3"><?php echo esc_html($region); ?> - TOTAL</td>
                                <td></td>
                                <td></td>
                                <?php
                                intersoccer_reports_render_urgency_heat_cell(
                                    (string) $region_regs,
                                    function_exists('intersoccer_reports_urgency_band')
                                        ? intersoccer_reports_urgency_band($region_regs)
                                        : 'count-critical'
                                );
                                ?>
                            </tr>
                            <?php foreach ($region_rows as $row): ?>
                                <?php
                                $course_data = $row['course_data'];
                                $regs = (int) ($course_data['registrations'] ?? 0);
                                $band = function_exists('intersoccer_reports_urgency_band')
                                    ? intersoccer_reports_urgency_band($regs)
                                    : 'count-critical';
                                ?>
                                    <tr>
                                        <td></td>
                                        <td><?php echo esc_html($row['venue']); ?></td>
                                        <td><?php echo esc_html($course_data['course_name'] ?? 'Unknown'); ?></td>
                                        <td><?php echo esc_html($course_data['course_day'] ?? 'Unknown'); ?></td>
                                        <td><?php echo esc_html($course_data['times'] ?? '-'); ?></td>
                                        <?php intersoccer_reports_render_urgency_heat_cell((string) $regs, $band); ?>
                                    </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Course Overall Totals -->
                <div style="margin-top: 30px; padding: 20px; background: #f9f9fa; border-radius: 8px;">
                    <h3><?php _e('Overall Totals', 'intersoccer-reports-rosters'); ?></h3>
                    <p class="description" style="margin-top:0;"><?php _e('Grid and footer both count one completed registration per roster row after all filters (same basis as Courses Rosters).', 'intersoccer-reports-rosters'); ?></p>
                    <table class="widefat fixed" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th><?php _e('Category', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Registrations', 'intersoccer-reports-rosters'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="font-weight: bold; background: #e9ecef;">
                                <td><?php _e('All Courses', 'intersoccer-reports-rosters'); ?></td>
                                <td><?php
                                    $course_all_disp = $course_player_registration_totals !== null
                                        ? (int) $course_player_registration_totals['all']
                                        : (int) $totals['all']['registrations'];
                                    echo esc_html($course_all_disp);
                                ?></td>
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
            var season_type = $('select[name="season_type"]').val() || '';
            var region = $('select[name="region"]').val() || '';
            var exclude_buyclub = $('input[name="exclude_buyclub"]').is(':checked') ? 1 : 0;
            var urgency_only = $('input[name="urgency_only"]').is(':checked') ? 1 : 0;
            var live = <?php echo $live ? '1' : '0'; ?>;
            
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
                    activity_type: activity_type,
                    season_type: season_type,
                    region: region,
                    exclude_buyclub: exclude_buyclub,
                    urgency_only: urgency_only,
                    live: live,
                    sync_to_office365: $('#final-reports-sync-office365').is(':checked') ? 1 : 0
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
                        
                        // Show success notification; include Office 365 sync status if present
                        var msg = "<?php echo esc_js(__('Export completed successfully!', 'intersoccer-reports-rosters')); ?>";
                        if (response.data.synced === true) {
                            msg = "<?php echo esc_js(__('Export completed and synced to Office 365.', 'intersoccer-reports-rosters')); ?>";
                        } else if (response.data.synced === false && response.data.sync_error) {
                            msg = "<?php echo esc_js(__('Export completed. Sync to Office 365 failed:', 'intersoccer-reports-rosters')); ?> " + response.data.sync_error;
                        }
                        showNotification(msg, response.data.synced === false && response.data.sync_error ? "warning" : "success");
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
 * Echo Full Day or Mini metric cells: Full Week | BuyClub | M–F | Total min-max.
 *
 * @param array $metrics Camp metrics block.
 */
function intersoccer_reports_echo_camp_metrics_cells(array $metrics) {
    $m = function_exists('intersoccer_reports_normalize_camp_metrics')
        ? intersoccer_reports_normalize_camp_metrics($metrics)
        : $metrics;
    $band = function_exists('intersoccer_reports_camp_metrics_urgency_band')
        ? intersoccer_reports_camp_metrics_urgency_band($m)
        : 'count-critical';
    echo '<td style="border: 1px solid #ddd; padding: 4px; text-align: center;">' . esc_html((string) (int) $m['full_week']) . '</td>';
    echo '<td style="border: 1px solid #ddd; padding: 4px; text-align: center;">' . esc_html((string) (int) $m['buyclub']) . '</td>';
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $day) {
        echo '<td style="border: 1px solid #ddd; padding: 4px; text-align: center;">' . esc_html((string) (int) ($m['individual_days'][$day] ?? 0)) . '</td>';
    }
    if (function_exists('intersoccer_reports_render_urgency_heat_cell')) {
        intersoccer_reports_render_urgency_heat_cell($m['min_max'] ?? '0-0', $band);
    } else {
        echo '<td style="border: 1px solid #ddd; padding: 4px; text-align: center;">' . esc_html((string) ($m['min_max'] ?? '0-0')) . '</td>';
    }
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