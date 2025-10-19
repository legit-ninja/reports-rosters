<?php
/**
 * InterSoccer Reports - Data Processing Functions
 *
 * @package InterSoccerReports
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display booking report with filters
 */
function intersoccer_display_booking_report($start_date, $end_date, $activity_type, $venue, $canton) {
    global $wpdb;

    // Build the query
    $query = "SELECT
                p.ID as order_id,
                p.post_date,
                p.post_status,
                oi.order_item_name,
                om_product_id.meta_value as product_id,
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
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta om_product_id ON oi.order_item_id = om_product_id.order_item_id AND om_product_id.meta_key = '_product_id'
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

    if (empty($results)) {
        echo '<p>' . __('No bookings found for the selected criteria.', 'intersoccer-reports-rosters') . '</p>';
        return;
    }

    ?>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Order ID', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Date', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Status', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Product', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Activity Type', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Canton', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Booking Type', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Gender', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Price', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Discounts', 'intersoccer-reports-rosters'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $row): ?>
            <tr>
                <td><?php echo esc_html($row['order_id']); ?></td>
                <td><?php echo esc_html(date('Y-m-d H:i', strtotime($row['post_date']))); ?></td>
                <td><?php echo esc_html($row['post_status']); ?></td>
                <td><?php echo esc_html($row['order_item_name']); ?></td>
                <td><?php echo esc_html($row['activity_type']); ?></td>
                <td><?php echo esc_html($row['canton']); ?></td>
                <td><?php echo esc_html($row['venue']); ?></td>
                <td><?php echo esc_html($row['booking_type']); ?></td>
                <td><?php echo esc_html($row['age_group']); ?></td>
                <td><?php echo esc_html($row['gender']); ?></td>
                <td><?php echo esc_html($row['line_total']); ?></td>
                <td><?php echo esc_html($row['discount_codes']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Get rowspan for week data
 */
function intersoccer_get_rowspan_for_week($week_data) {
    $rowspan = 0;
    foreach ($week_data as $cantons) {
        foreach ($cantons as $venues) {
            foreach ($venues as $camp_types) {
                $rowspan++;
            }
        }
    }
    return $rowspan;
}

/**
 * Get final reports data
 */
function intersoccer_get_final_reports_data($year, $activity_type) {
    global $wpdb;
    $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
    $posts_table = $wpdb->prefix . 'posts';
    $terms_table = $wpdb->prefix . 'terms';

    if ($activity_type === 'Camp') {
        // Existing camp logic
        // Define weeks based on activity type
        $weeks = [
            'Week 1: June 24 - June 28' => ['start' => '06-24', 'end' => '06-28'],
            'Week 2: July 1 - July 5' => ['start' => '07-01', 'end' => '07-05'],
            'Week 3: July 8 - July 12' => ['start' => '07-08', 'end' => '07-12'],
            'Week 4: July 15 - July 19' => ['start' => '07-15', 'end' => '07-19'],
            'Week 5: July 22 - July 26' => ['start' => '07-22', 'end' => '07-26'],
            'Week 6: July 29 - August 2' => ['start' => '07-29', 'end' => '08-02'],
            'Week 7: August 5 - August 9' => ['start' => '08-05', 'end' => '08-09'],
            'Week 8: August 12 - August 16' => ['start' => '08-12', 'end' => '08-16'],
            'Week 9: August 19 - August 23' => ['start' => '08-19', 'end' => '08-23'],
            'Week 10: August 26 - August 30' => ['start' => '08-26', 'end' => '08-30'],
        ];

        // Query orders for camps
        $query = $wpdb->prepare(
            "SELECT
                oi.order_item_id,
                om_canton.meta_value AS canton,
                t.name AS venue,
                om_camp_terms.meta_value AS camp_terms,
                om_booking_type.meta_value AS booking_type,
                om_selected_days.meta_value AS selected_days,
                om_age_group.meta_value AS age_group,
                p.post_date
             FROM $posts_table p
             JOIN $order_items_table oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
             LEFT JOIN $order_itemmeta_table om_canton ON oi.order_item_id = om_canton.order_item_id AND om_canton.meta_key = 'Canton / Region'
             LEFT JOIN $order_itemmeta_table om_venue ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
             LEFT JOIN $terms_table t ON om_venue.meta_value = t.slug
             LEFT JOIN $order_itemmeta_table om_camp_terms ON oi.order_item_id = om_camp_terms.order_item_id AND om_camp_terms.meta_key = 'camp_terms'
             LEFT JOIN $order_itemmeta_table om_booking_type ON oi.order_item_id = om_booking_type.order_item_id AND om_booking_type.meta_key = 'booking_type'
             LEFT JOIN $order_itemmeta_table om_selected_days ON oi.order_item_id = om_selected_days.order_item_id AND om_selected_days.meta_key = 'selected_days'
             LEFT JOIN $order_itemmeta_table om_age_group ON oi.order_item_id = om_age_group.order_item_id AND om_age_group.meta_key = 'age_group'
             LEFT JOIN $order_itemmeta_table om_activity_type ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
             AND om_activity_type.meta_value = %s
             AND YEAR(p.post_date) = %d",
            $activity_type,
            $year
        );

        $rosters = $wpdb->get_results($query, ARRAY_A);
        error_log('InterSoccer Final Reports: Camp query returned ' . count($rosters) . ' records for year ' . $year);
        if (empty($rosters)) {
            error_log('InterSoccer Final Reports: No camp records found for year ' . $year);
            return [];
        }

        // Determine camp type and BuyClub
        $buyclub_count = 0;
        foreach ($rosters as &$roster) {
            $age_group = $roster['age_group'] ?? '';
            $roster['camp_type'] = (!empty($age_group) && (stripos($age_group, '3-5y') !== false || stripos($age_group, 'half-day') !== false)) ? 'Mini - Half Day' : 'Full Day';

            // BuyClub: orders with original price > 0 and final price = 0
            $line_subtotal_meta = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $order_itemmeta_table WHERE order_item_id = %d AND meta_key = '_line_subtotal'",
                $roster['order_item_id']
            ));
            $line_total_meta = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $order_itemmeta_table WHERE order_item_id = %d AND meta_key = '_line_total'",
                $roster['order_item_id']
            ));
            $roster['is_buyclub'] = floatval($line_subtotal_meta) > 0 && floatval($line_total_meta) == 0;
            if ($roster['is_buyclub']) {
                $buyclub_count++;
            }
        }
        error_log('InterSoccer Final Reports: ' . $buyclub_count . ' out of ' . count($rosters) . ' camp records classified as BuyClub');
        unset($roster);

        // Group by week, canton, venue, camp_type
        $report_data = [];
        foreach ($weeks as $week_name => $dates) {
            $week_start = $year . '-' . $dates['start'];
            $week_end = $year . '-' . $dates['end'];

            $week_entries = array_filter($rosters, function($r) use ($week_start, $week_end) {
                if (!empty($r['camp_terms'])) {
                    return preg_match("/week-\d+-$dates[start]-$dates[end]/i", $r['camp_terms']);
                } else {
                    $post_date = strtotime($r['post_date']);
                    return $post_date >= strtotime($week_start) && $post_date <= strtotime($week_end);
                }
            });

            if (empty($week_entries)) continue;

            $week_groups = [];
            foreach ($week_entries as $entry) {
                $canton = $entry['canton'] ?? 'Unknown';
                $venue = $entry['venue'] ?? 'Unknown';
                $camp_type = $entry['camp_type'];
                $key = "$canton|$venue|$camp_type";
                $week_groups[$key][] = $entry;
            }

            $report_data[$week_name] = [];
            foreach ($week_groups as $key => $group) {
                list($canton, $venue, $camp_type) = explode('|', $key);

                $full_week = 0;
                $buyclub = 0;
                $individual_days = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0];

                foreach ($group as $entry) {
                    if ($entry['is_buyclub']) {
                        $buyclub++;
                    } elseif (strtolower($entry['booking_type'] ?? '') === 'full-week') {
                        $full_week++;
                    } elseif (strtolower($entry['booking_type'] ?? '') === 'single-days' && !empty($entry['selected_days'])) {
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
                    $daily_counts[$day] = $full_week + $count + $buyclub;
                }
                $min = !empty($daily_counts) ? min($daily_counts) : 0;
                $max = !empty($daily_counts) ? max($daily_counts) : 0;

                $report_data[$week_name][$canton][$venue][$camp_type] = [
                    'full_week' => $full_week,
                    'buyclub' => $buyclub,
                    'individual_days' => $individual_days,
                    'min_max' => "$min-$max",
                ];
            }
        }

        return $report_data;
    } else {
        // Course logic
        // Query orders for courses
        $query = $wpdb->prepare(
            "SELECT
                oi.order_item_id,
                om_canton.meta_value AS canton,
                t.name AS venue,
                om_product_id.meta_value AS product_id,
                om_booking_type.meta_value AS booking_type,
                om_discount_codes.meta_value AS discount_codes,
                om_gender.meta_value AS gender,
                p.post_date
             FROM $posts_table p
             JOIN $order_items_table oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
             LEFT JOIN $order_itemmeta_table om_canton ON oi.order_item_id = om_canton.order_item_id AND om_canton.meta_key = 'Canton / Region'
             LEFT JOIN $order_itemmeta_table om_venue ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
             LEFT JOIN $terms_table t ON om_venue.meta_value = t.slug
             LEFT JOIN $order_itemmeta_table om_product_id ON oi.order_item_id = om_product_id.order_item_id AND om_product_id.meta_key = '_product_id'
             LEFT JOIN $order_itemmeta_table om_booking_type ON oi.order_item_id = om_booking_type.order_item_id AND om_booking_type.meta_key = 'booking_type'
             LEFT JOIN $order_itemmeta_table om_discount_codes ON oi.order_item_id = om_discount_codes.order_item_id AND om_discount_codes.meta_key = '_applied_discounts'
             LEFT JOIN $order_itemmeta_table om_gender ON oi.order_item_id = om_gender.order_item_id AND om_gender.meta_key = 'gender'
             LEFT JOIN $order_itemmeta_table om_activity_type ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
             AND om_activity_type.meta_value = %s
             AND YEAR(p.post_date) = %d",
            $activity_type,
            $year
        );

        $rosters = $wpdb->get_results($query, ARRAY_A);
        if (empty($rosters)) {
            return [];
        }

        // Determine categories for courses
        foreach ($rosters as &$roster) {
            // BuyClub: orders with original price > 0 and final price = 0
            $line_subtotal_meta = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $order_itemmeta_table WHERE order_item_id = %d AND meta_key = '_line_subtotal'",
                $roster['order_item_id']
            ));
            $line_total_meta = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $order_itemmeta_table WHERE order_item_id = %d AND meta_key = '_line_total'",
                $roster['order_item_id']
            ));
            $roster['is_buyclub'] = floatval($line_subtotal_meta) > 0 && floatval($line_total_meta) == 0;

            // Pitch Side: assume if booking_type contains 'pitch' or specific logic, for now assume not BuyClub and not BO
            // BO: regular bookings, Pitch Side: perhaps from venue or something, for now assume all in BO for now
            $roster['is_pitch_side'] = false; // Placeholder

            // Girls Free: discount code 'GIRLSFREE24' and gender female
            $discount_codes = strtolower($roster['discount_codes'] ?? '');
            $roster['is_girls_free'] = (strpos($discount_codes, 'girlsfree24') !== false && strtolower($roster['gender'] ?? '') === 'female');
        }
        unset($roster);

        // Group by region, course name
        $report_data = [];
        foreach ($rosters as $entry) {
            $region = $entry['canton'] ?? 'Unknown';
            $product_id = $entry['product_id'];
            $course_name = $product_id ? get_the_title($product_id) : 'Unknown';

            if (!isset($report_data[$region])) {
                $report_data[$region] = [];
            }
            if (!isset($report_data[$region][$course_name])) {
                $report_data[$region][$course_name] = [
                    'bo' => 0,
                    'pitch_side' => 0,
                    'buyclub' => 0,
                    'total' => 0,
                    'final' => 0,
                    'girls_free' => 0,
                ];
            }

            if ($entry['is_buyclub']) {
                $report_data[$region][$course_name]['buyclub']++;
            } elseif ($entry['is_pitch_side']) {
                $report_data[$region][$course_name]['pitch_side']++;
            } else {
                $report_data[$region][$course_name]['bo']++;
            }

            $report_data[$region][$course_name]['total']++;
            $report_data[$region][$course_name]['final']++;

            if ($entry['is_girls_free']) {
                $report_data[$region][$course_name]['girls_free']++;
            }
        }

        return $report_data;
    }
}

/**
 * Calculate final reports totals
 */
function intersoccer_calculate_final_reports_totals($report_data, $activity_type) {
    if ($activity_type === 'Camp') {
        $totals = [
            'full_day' => ['full_week' => 0, 'buyclub' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0], 'total' => 0],
            'mini' => ['full_week' => 0, 'buyclub' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0], 'total' => 0],
            'all' => ['full_week' => 0, 'buyclub' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0], 'total' => 0],
        ];

        foreach ($report_data as $week => $cantons) {
            foreach ($cantons as $canton => $venues) {
                foreach ($venues as $venue => $camp_types) {
                    foreach ($camp_types as $camp_type => $data) {
                        if ($camp_type === 'Full Day') {
                            $totals['full_day']['full_week'] += $data['full_week'];
                            $totals['full_day']['buyclub'] += $data['buyclub'];
                            foreach ($data['individual_days'] as $day => $count) {
                                $totals['full_day']['individual_days'][$day] += $count;
                            }
                            $totals['full_day']['total'] += $data['full_week'] + $data['buyclub'] + array_sum($data['individual_days']);
                        } elseif ($camp_type === 'Mini - Half Day') {
                            $totals['mini']['full_week'] += $data['full_week'];
                            $totals['mini']['buyclub'] += $data['buyclub'];
                            foreach ($data['individual_days'] as $day => $count) {
                                $totals['mini']['individual_days'][$day] += $count;
                            }
                            $totals['mini']['total'] += $data['full_week'] + $data['buyclub'] + array_sum($data['individual_days']);
                        }
                    }
                }
            }
        }

        // Calculate all totals
        foreach (['full_day', 'mini'] as $type) {
            $totals['all']['full_week'] += $totals[$type]['full_week'];
            $totals['all']['buyclub'] += $totals[$type]['buyclub'];
            foreach ($totals[$type]['individual_days'] as $day => $count) {
                $totals['all']['individual_days'][$day] += $count;
            }
            $totals['all']['total'] += $totals[$type]['total'];
        }

        return $totals;
    } else {
        // Course totals
        $totals = [
            'regions' => [],
            'all' => ['bo' => 0, 'pitch_side' => 0, 'buyclub' => 0, 'total' => 0, 'final' => 0, 'girls_free' => 0],
        ];

        foreach ($report_data as $region => $courses) {
            $totals['regions'][$region] = ['bo' => 0, 'pitch_side' => 0, 'buyclub' => 0, 'total' => 0, 'final' => 0, 'girls_free' => 0];

            foreach ($courses as $course => $data) {
                foreach (['bo', 'pitch_side', 'buyclub', 'total', 'final', 'girls_free'] as $key) {
                    $totals['regions'][$region][$key] += $data[$key];
                    $totals['all'][$key] += $data[$key];
                }
            }
        }

        return $totals;
    }
}

/**
 * Display camp final report
 */
function intersoccer_display_camp_final_report($year) {
    $report_data = intersoccer_get_final_reports_data($year, 'Camp');
    $totals = intersoccer_calculate_final_reports_totals($report_data, 'Camp');

    if (empty($report_data)) {
        echo '<p>' . __('No camp data found for the selected year.', 'intersoccer-reports-rosters') . '</p>';
        return;
    }

    ?>
    <table class="widefat fixed" style="margin-bottom: 20px;">
        <thead>
            <tr>
                <th rowspan="2"><?php _e('Week', 'intersoccer-reports-rosters'); ?></th>
                <th rowspan="2"><?php _e('Canton', 'intersoccer-reports-rosters'); ?></th>
                <th rowspan="2"><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                <th colspan="9"><?php _e('Full Day Camps', 'intersoccer-reports-rosters'); ?></th>
                <th colspan="9"><?php _e('Mini - Half Day Camps', 'intersoccer-reports-rosters'); ?></th>
            </tr>
            <tr>
                <th><?php _e('Full Week', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Mon', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Tue', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Wed', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Thu', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Fri', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('BuyClub', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Min-Max', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Total', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Full Week', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Mon', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Tue', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Wed', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Thu', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Fri', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('BuyClub', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Min-Max', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Total', 'intersoccer-reports-rosters'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report_data as $week_name => $cantons): ?>
                <?php $week_rowspan = intersoccer_get_rowspan_for_week($cantons); ?>
                <?php $first_row = true; ?>
                <?php foreach ($cantons as $canton => $venues): ?>
                    <?php foreach ($venues as $venue => $camp_types): ?>
                        <?php foreach ($camp_types as $camp_type => $data): ?>
                            <tr>
                                <?php if ($first_row): ?>
                                    <td rowspan="<?php echo $week_rowspan; ?>"><?php echo esc_html($week_name); ?></td>
                                    <?php $first_row = false; ?>
                                <?php endif; ?>
                                <td><?php echo esc_html($canton); ?></td>
                                <td><?php echo esc_html($venue); ?></td>

                                <?php if ($camp_type === 'Full Day'): ?>
                                    <td><?php echo esc_html($data['full_week']); ?></td>
                                    <?php foreach ($data['individual_days'] as $day => $count): ?>
                                        <td><?php echo esc_html($count); ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo esc_html($data['buyclub']); ?></td>
                                    <td><?php echo esc_html($data['min_max']); ?></td>
                                    <td><?php echo esc_html($data['full_week'] + $data['buyclub'] + array_sum($data['individual_days'])); ?></td>
                                    <!-- Mini columns empty for Full Day row -->
                                    <td colspan="9"></td>
                                <?php elseif ($camp_type === 'Mini - Half Day'): ?>
                                    <!-- Full Day columns empty for Mini row -->
                                    <td colspan="9"></td>
                                    <td><?php echo esc_html($data['full_week']); ?></td>
                                    <?php foreach ($data['individual_days'] as $day => $count): ?>
                                        <td><?php echo esc_html($count); ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo esc_html($data['buyclub']); ?></td>
                                    <td><?php echo esc_html($data['min_max']); ?></td>
                                    <td><?php echo esc_html($data['full_week'] + $data['buyclub'] + array_sum($data['individual_days'])); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Camp Totals -->
    <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
        <h3><?php _e('Camp Totals', 'intersoccer-reports-rosters'); ?></h3>
        <table class="widefat fixed" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th><?php _e('Camp Type', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Full Week', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Monday', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Tuesday', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Wednesday', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Thursday', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Friday', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('BuyClub', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Total', 'intersoccer-reports-rosters'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php _e('Full Day Camps', 'intersoccer-reports-rosters'); ?></td>
                    <td><?php echo esc_html($totals['full_day']['full_week']); ?></td>
                    <?php foreach ($totals['full_day']['individual_days'] as $day => $count): ?>
                        <td><?php echo esc_html($count); ?></td>
                    <?php endforeach; ?>
                    <td><?php echo esc_html($totals['full_day']['buyclub']); ?></td>
                    <td><?php echo esc_html($totals['full_day']['total']); ?></td>
                </tr>
                <tr>
                    <td><?php _e('Mini - Half Day Camps', 'intersoccer-reports-rosters'); ?></td>
                    <td><?php echo esc_html($totals['mini']['full_week']); ?></td>
                    <?php foreach ($totals['mini']['individual_days'] as $day => $count): ?>
                        <td><?php echo esc_html($count); ?></td>
                    <?php endforeach; ?>
                    <td><?php echo esc_html($totals['mini']['buyclub']); ?></td>
                    <td><?php echo esc_html($totals['mini']['total']); ?></td>
                </tr>
                <tr style="font-weight: bold; background: #e9ecef;">
                    <td><?php _e('All Camps', 'intersoccer-reports-rosters'); ?></td>
                    <td><?php echo esc_html($totals['all']['full_week']); ?></td>
                    <?php foreach ($totals['all']['individual_days'] as $day => $count): ?>
                        <td><?php echo esc_html($count); ?></td>
                    <?php endforeach; ?>
                    <td><?php echo esc_html($totals['all']['buyclub']); ?></td>
                    <td><?php echo esc_html($totals['all']['total']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Display course final report
 */
function intersoccer_display_course_final_report($year) {
    $report_data = intersoccer_get_final_reports_data($year, 'Course');
    $totals = intersoccer_calculate_final_reports_totals($report_data, 'Course');

    if (empty($report_data)) {
        echo '<p>' . __('No course data found for the selected year.', 'intersoccer-reports-rosters') . '</p>';
        return;
    }

    ?>
    <table class="widefat fixed" style="margin-bottom: 20px;">
        <thead>
            <tr>
                <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Course Name', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('BO', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Pitch Side', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('BuyClub', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Total', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Final', 'intersoccer-reports-rosters'); ?></th>
                <th><?php _e('Girls Free', 'intersoccer-reports-rosters'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report_data as $region => $courses): ?>
                <?php foreach ($courses as $course_name => $data): ?>
                    <tr>
                        <td><?php echo esc_html($region); ?></td>
                        <td><?php echo esc_html($course_name); ?></td>
                        <td><?php echo esc_html($data['bo']); ?></td>
                        <td><?php echo esc_html($data['pitch_side']); ?></td>
                        <td><?php echo esc_html($data['buyclub']); ?></td>
                        <td><?php echo esc_html($data['total']); ?></td>
                        <td><?php echo esc_html($data['final']); ?></td>
                        <td><?php echo esc_html($data['girls_free']); ?></td>
                    </tr>
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
                    <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('BO', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Pitch Side', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('BuyClub', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Total', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Final', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Girls Free', 'intersoccer-reports-rosters'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($totals['regions'] as $region => $region_total): ?>
                    <tr>
                        <td><?php echo esc_html($region); ?></td>
                        <td><?php echo esc_html($region_total['bo']); ?></td>
                        <td><?php echo esc_html($region_total['pitch_side']); ?></td>
                        <td><?php echo esc_html($region_total['buyclub']); ?></td>
                        <td><?php echo esc_html($region_total['total']); ?></td>
                        <td><?php echo esc_html($region_total['final']); ?></td>
                        <td><?php echo esc_html($region_total['girls_free']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="font-weight: bold; background: #e9ecef;">
                    <td><?php _e('TOTAL', 'intersoccer-reports-rosters'); ?></td>
                    <td><?php echo esc_html($totals['all']['bo']); ?></td>
                    <td><?php echo esc_html($totals['all']['pitch_side']); ?></td>
                    <td><?php echo esc_html($totals['all']['buyclub']); ?></td>
                    <td><?php echo esc_html($totals['all']['total']); ?></td>
                    <td><?php echo esc_html($totals['all']['final']); ?></td>
                    <td><?php echo esc_html($totals['all']['girls_free']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}