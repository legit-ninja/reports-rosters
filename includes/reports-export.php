<?php
/**
 * InterSoccer Reports - Export Functions
 *
 * @package InterSoccerReports
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export booking report CSV
 */
function intersoccer_export_booking_report_callback() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'intersoccer_reports_nonce')) {
        wp_die(__('Security check failed', 'intersoccer-reports-rosters'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions', 'intersoccer-reports-rosters'));
    }

    $start_date = sanitize_text_field($_POST['start_date']);
    $end_date = sanitize_text_field($_POST['end_date']);
    $activity_type = sanitize_text_field($_POST['activity_type']);
    $venue = sanitize_text_field($_POST['venue']);
    $canton = sanitize_text_field($_POST['canton']);

    // Include the data processing file
    require_once plugin_dir_path(__FILE__) . 'reports-data.php';

    // Get the data (reuse the display function logic)
    global $wpdb;

    // Build the query
    $query = "SELECT
                p.ID as order_id,
                p.post_date,
                p.post_status,
                oi.order_item_name,
                om_activity_type.meta_value as activity_type,
                om_canton.meta_value as canton,
                t.name as venue,
                om_booking_type.meta_value as booking_type,
                om_selected_days.meta_value as selected_days,
                om_age_group.meta_value as age_group,
                om_gender.meta_value as gender,
                om_line_total.meta_value as line_total,
                om_discount_codes.meta_value as discount_codes
             FROM {$wpdb->posts} p
             JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_activity_type ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_canton ON oi.order_item_id = om_canton.order_item_id AND om_canton.meta_key = 'Canton / Region'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_venue ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
             LEFT JOIN {$wpdb->terms} t ON om_venue.meta_value = t.slug
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_booking_type ON oi.order_item_id = om_booking_type.order_item_id AND om_booking_type.meta_key = 'booking_type'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_selected_days ON oi.order_item_id = om_selected_days.order_item_id AND om_selected_days.meta_key = 'selected_days'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_age_group ON oi.order_item_id = om_age_group.order_item_id AND om_age_group.meta_key = 'age_group'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_gender ON oi.order_item_id = om_gender.order_item_id AND om_gender.meta_key = 'gender'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_line_total ON oi.order_item_id = om_line_total.order_item_id AND om_line_total.meta_key = '_line_total'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_discount_codes ON oi.order_item_id = om_discount_codes.order_item_id AND om_discount_codes.meta_key = '_applied_discounts'
             WHERE p.post_type = 'shop_order'
             AND p.post_date BETWEEN %s AND %s";

    $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');

    if (!empty($activity_type)) {
        $query .= " AND om_activity_type.meta_value = %s";
        $params[] = $activity_type;
    }

    if (!empty($venue)) {
        $query .= " AND t.name = %s";
        $params[] = $venue;
    }

    if (!empty($canton)) {
        $query .= " AND om_canton.meta_value = %s";
        $params[] = $canton;
    }

    $query .= " ORDER BY p.post_date DESC";

    $results = $wpdb->get_results($wpdb->prepare($query, $params), ARRAY_A);

    // Generate CSV
    $filename = 'booking-report-' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // CSV headers
    fputcsv($output, array(
        'Order ID',
        'Date',
        'Status',
        'Product',
        'Activity Type',
        'Canton',
        'Venue',
        'Booking Type',
        'Age Group',
        'Gender',
        'Price',
        'Discounts'
    ));

    // CSV data
    foreach ($results as $row) {
        fputcsv($output, array(
            $row['order_id'],
            date('Y-m-d H:i', strtotime($row['post_date'])),
            $row['post_status'],
            $row['order_item_name'],
            $row['activity_type'],
            $row['canton'],
            $row['venue'],
            $row['booking_type'],
            $row['age_group'],
            $row['gender'],
            $row['line_total'],
            $row['discount_codes']
        ));
    }

    fclose($output);
    exit;
}

/**
 * Export final reports CSV
 */
function intersoccer_export_final_reports_csv($year, $activity_type) {
    // Include the data processing file
    require_once plugin_dir_path(__FILE__) . 'reports-data.php';

    $report_data = intersoccer_get_final_reports_data($year, $activity_type);
    $totals = intersoccer_calculate_final_reports_totals($report_data, $activity_type);

    $filename = 'final-reports-' . strtolower($activity_type) . '-' . $year . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    if ($activity_type === 'Camp') {
        // Camp CSV headers
        fputcsv($output, array(
            'Week',
            'Canton',
            'Venue',
            'Camp Type',
            'Full Week',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'BuyClub',
            'Min-Max',
            'Total'
        ));

        // Camp CSV data
        foreach ($report_data as $week_name => $cantons) {
            foreach ($cantons as $canton => $venues) {
                foreach ($venues as $venue => $camp_types) {
                    foreach ($camp_types as $camp_type => $data) {
                        fputcsv($output, array(
                            $week_name,
                            $canton,
                            $venue,
                            $camp_type,
                            $data['full_week'],
                            $data['individual_days']['Monday'],
                            $data['individual_days']['Tuesday'],
                            $data['individual_days']['Wednesday'],
                            $data['individual_days']['Thursday'],
                            $data['individual_days']['Friday'],
                            $data['buyclub'],
                            $data['min_max'],
                            $data['full_week'] + $data['buyclub'] + array_sum($data['individual_days'])
                        ));
                    }
                }
            }
        }

        // Camp totals
        fputcsv($output, array('', '', '', '', '', '', '', '', ''));
        fputcsv($output, array('TOTALS', '', '', '', '', '', '', '', ''));
        fputcsv($output, array('', '', '', 'Full Day Camps', $totals['full_day']['full_week'], $totals['full_day']['individual_days']['Monday'], $totals['full_day']['individual_days']['Tuesday'], $totals['full_day']['individual_days']['Wednesday'], $totals['full_day']['individual_days']['Thursday'], $totals['full_day']['individual_days']['Friday'], $totals['full_day']['buyclub'], '', $totals['full_day']['total']));
        fputcsv($output, array('', '', '', 'Mini - Half Day Camps', $totals['mini']['full_week'], $totals['mini']['individual_days']['Monday'], $totals['mini']['individual_days']['Tuesday'], $totals['mini']['individual_days']['Wednesday'], $totals['mini']['individual_days']['Thursday'], $totals['mini']['individual_days']['Friday'], $totals['mini']['buyclub'], '', $totals['mini']['total']));
        fputcsv($output, array('', '', '', 'All Camps', $totals['all']['full_week'], $totals['all']['individual_days']['Monday'], $totals['all']['individual_days']['Tuesday'], $totals['all']['individual_days']['Wednesday'], $totals['all']['individual_days']['Thursday'], $totals['all']['individual_days']['Friday'], $totals['all']['buyclub'], '', $totals['all']['total']));
    } else {
        // Course CSV headers
        fputcsv($output, array(
            'Region',
            'Course Name',
            'BO',
            'Pitch Side',
            'BuyClub',
            'Total',
            'Final',
            'Girls Free'
        ));

        // Course CSV data
        foreach ($report_data as $region => $courses) {
            foreach ($courses as $course_name => $data) {
                fputcsv($output, array(
                    $region,
                    $course_name,
                    $data['bo'],
                    $data['pitch_side'],
                    $data['buyclub'],
                    $data['total'],
                    $data['final'],
                    $data['girls_free']
                ));
            }
        }

        // Course totals
        fputcsv($output, array('', '', '', '', '', '', '', ''));
        fputcsv($output, array('TOTALS', '', '', '', '', '', '', ''));
        foreach ($totals['regions'] as $region => $region_total) {
            fputcsv($output, array(
                $region,
                '',
                $region_total['bo'],
                $region_total['pitch_side'],
                $region_total['buyclub'],
                $region_total['total'],
                $region_total['final'],
                $region_total['girls_free']
            ));
        }
        fputcsv($output, array(
            'TOTAL:',
            '',
            $totals['all']['bo'],
            $totals['all']['pitch_side'],
            $totals['all']['buyclub'],
            $totals['all']['total'],
            $totals['all']['final'],
            $totals['all']['girls_free']
        ));
    }

    fclose($output);
    exit;
}