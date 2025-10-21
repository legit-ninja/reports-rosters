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
 * Get final reports data
 */
function intersoccer_get_final_reports_data($year, $activity_type) {
    global $wpdb;
    $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
    $posts_table = $wpdb->prefix . 'posts';
    $terms_table = $wpdb->prefix . 'terms';

    if ($activity_type === 'Camp') {
        error_log('InterSoccer Final Reports: Starting camp data processing for year ' . $year);
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
                COALESCE(om_activity_type.meta_value, pm_activity_type.meta_value) AS activity_type,
                p.post_date
             FROM $posts_table p
             JOIN $order_items_table oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
             LEFT JOIN $order_itemmeta_table om_canton ON oi.order_item_id = om_canton.order_item_id AND om_canton.meta_key = 'Canton / Region'
             LEFT JOIN $order_itemmeta_table om_venue ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
             LEFT JOIN $terms_table t ON om_venue.meta_value = t.slug
             LEFT JOIN $order_itemmeta_table om_camp_terms ON oi.order_item_id = om_camp_terms.order_item_id AND om_camp_terms.meta_key = 'camp_terms'
             LEFT JOIN $order_itemmeta_table om_booking_type ON oi.order_item_id = om_booking_type.order_item_id AND om_booking_type.meta_key = 'pa_booking-type'
             LEFT JOIN $order_itemmeta_table om_selected_days ON oi.order_item_id = om_selected_days.order_item_id AND om_selected_days.meta_key = 'Days of Week'
             LEFT JOIN $order_itemmeta_table om_age_group ON oi.order_item_id = om_age_group.order_item_id AND om_age_group.meta_key = 'pa_age-group'
             LEFT JOIN $order_itemmeta_table om_activity_type ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
             LEFT JOIN $order_itemmeta_table om_product_id ON oi.order_item_id = om_product_id.order_item_id AND om_product_id.meta_key = '_product_id'
             LEFT JOIN {$wpdb->postmeta} pm_activity_type ON om_product_id.meta_value = pm_activity_type.post_id AND pm_activity_type.meta_key = 'pa_activity-type'
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
             AND COALESCE(om_activity_type.meta_value, pm_activity_type.meta_value) = %s
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

            error_log('InterSoccer Final Reports: Week ' . $week_name . ' - ' . count($week_entries) . ' entries found out of ' . count($rosters) . ' total');

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
                $individual_days = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0];
                $processed_count = 0;
                $skipped_buyclub = 0;

                foreach ($group as $entry) {
                    // Skip BuyClub orders entirely
                    if ($entry['is_buyclub']) {
                        $skipped_buyclub++;
                        continue;
                    }

                    $processed_count++;
                    $booking_type = strtolower($entry['booking_type'] ?? '');
                    if ($booking_type === 'full-week') {
                        $full_week++;
                    } elseif ($booking_type === 'single-days' && !empty($entry['selected_days'])) {
                        $days = array_map('trim', explode(',', $entry['selected_days']));
                        foreach ($days as $day) {
                            if (isset($individual_days[$day])) {
                                $individual_days[$day]++;
                            }
                        }
                    }
                }

                error_log("InterSoccer Camp Processing: $canton|$venue|$camp_type - Processed: $processed_count, Skipped BuyClub: $skipped_buyclub, Full Week: $full_week, Days: " . json_encode($individual_days));

                $daily_counts = [];
                foreach ($individual_days as $day => $count) {
                    $daily_counts[$day] = $full_week + $count;
                }
                $min = !empty($daily_counts) ? min($daily_counts) : 0;
                $max = !empty($daily_counts) ? max($daily_counts) : 0;

                $report_data[$week_name][$canton][$venue][$camp_type] = [
                    'full_week' => $full_week,
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
                om_course_day.meta_value AS course_day,
                COALESCE(om_activity_type.meta_value, pm_activity_type.meta_value) AS activity_type,
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
             LEFT JOIN $order_itemmeta_table om_course_day ON oi.order_item_id = om_course_day.order_item_id AND om_course_day.meta_key = 'pa_course-day'
             LEFT JOIN {$wpdb->postmeta} pm_activity_type ON om_product_id.meta_value = pm_activity_type.post_id AND pm_activity_type.meta_key = 'pa_activity-type'
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
             AND COALESCE(om_activity_type.meta_value, pm_activity_type.meta_value) = %s
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

            // Course Day: from pa_course-day attribute
            $roster['course_day'] = $roster['course_day'] ?? 'Unknown';

            // Girls Free: discount code 'GIRLSFREE24' and gender female
            $discount_codes = strtolower($roster['discount_codes'] ?? '');
            $roster['is_girls_free'] = (strpos($discount_codes, 'girlsfree24') !== false && strtolower($roster['gender'] ?? '') === 'female');
        }
        unset($roster);

        // Group by region, course name, course day
        $report_data = [];
        foreach ($rosters as $entry) {
            // Skip BuyClub orders entirely
            if ($entry['is_buyclub']) {
                continue;
            }

            $region = $entry['canton'] ?? 'Unknown';
            $product_id = $entry['product_id'];
            $course_name = $product_id ? get_the_title($product_id) : 'Unknown';
            $course_day = $entry['course_day'] ?? 'Unknown';

            if (!isset($report_data[$region])) {
                $report_data[$region] = [];
            }
            if (!isset($report_data[$region][$course_name])) {
                $report_data[$region][$course_name] = [];
            }
            if (!isset($report_data[$region][$course_name][$course_day])) {
                $report_data[$region][$course_name][$course_day] = [
                    'online' => 0,
                    'total' => 0,
                    'final' => 0,
                    'girls_free' => 0,
                ];
            }

            $report_data[$region][$course_name][$course_day]['online']++;
            $report_data[$region][$course_name][$course_day]['total']++;
            $report_data[$region][$course_name][$course_day]['final']++;

            if ($entry['is_girls_free']) {
                $report_data[$region][$course_name][$course_day]['girls_free']++;
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
            'full_day' => ['full_week' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0], 'online' => 0, 'total' => 0],
            'mini' => ['full_week' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0], 'online' => 0, 'total' => 0],
            'all' => ['full_week' => 0, 'individual_days' => ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0], 'online' => 0, 'total' => 0],
        ];

        foreach ($report_data as $week => $cantons) {
            foreach ($cantons as $canton => $venues) {
                foreach ($venues as $venue => $camp_types) {
                    foreach ($camp_types as $camp_type => $data) {
                        if ($camp_type === 'Full Day') {
                            $totals['full_day']['full_week'] += $data['full_week'];
                            foreach ($data['individual_days'] as $day => $count) {
                                $totals['full_day']['individual_days'][$day] += $count;
                            }
                            $totals['full_day']['online'] = $totals['full_day']['full_week'] + array_sum($totals['full_day']['individual_days']);
                            $totals['full_day']['total'] += $data['full_week'] + array_sum($data['individual_days']);
                        } elseif ($camp_type === 'Mini - Half Day') {
                            $totals['mini']['full_week'] += $data['full_week'];
                            foreach ($data['individual_days'] as $day => $count) {
                                $totals['mini']['individual_days'][$day] += $count;
                            }
                            $totals['mini']['online'] = $totals['mini']['full_week'] + array_sum($totals['mini']['individual_days']);
                            $totals['mini']['total'] += $data['full_week'] + array_sum($data['individual_days']);
                        }

                        // All camps total
                        $totals['all']['full_week'] += $data['full_week'];
                        foreach ($data['individual_days'] as $day => $count) {
                            $totals['all']['individual_days'][$day] += $count;
                        }
                        $totals['all']['online'] = $totals['all']['full_week'] + array_sum($totals['all']['individual_days']);
                        $totals['all']['total'] += $data['full_week'] + array_sum($data['individual_days']);
                    }
                }
            }
        }

        return $totals;
    } else {
        // Course totals
        $totals = [
            'regions' => [],
            'all' => [
                'online' => 0,
                'total' => 0,
                'final' => 0,
                'girls_free' => 0,
            ]
        ];

        foreach ($report_data as $region => $courses) {
            $totals['regions'][$region] = [
                'online' => 0,
                'total' => 0,
                'final' => 0,
                'girls_free' => 0,
                'prev_year' => 0, // Placeholder for previous year data
            ];

            foreach ($courses as $course_name => $course_days) {
                foreach ($course_days as $course_day => $data) {
                    $totals['regions'][$region]['online'] += $data['online'];
                    $totals['regions'][$region]['total'] += $data['total'];
                    $totals['regions'][$region]['final'] += $data['final'];
                    $totals['regions'][$region]['girls_free'] += $data['girls_free'];

                    $totals['all']['online'] += $data['online'];
                    $totals['all']['total'] += $data['total'];
                    $totals['all']['final'] += $data['final'];
                    $totals['all']['girls_free'] += $data['girls_free'];
                }
            }
        }

        return $totals;
    }
}