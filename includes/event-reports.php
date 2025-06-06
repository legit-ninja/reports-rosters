<?php
/**
 * Event reports functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.3
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
 * @return array Report data.
 */
function intersoccer_pe_get_camp_report_data($region = '', $week = '', $camp_type = '', $year = '') {
    try {
        if (!function_exists('wc_get_products')) {
            error_log('InterSoccer: wc_get_products not available in intersoccer_pe_get_camp_report_data');
            return [];
        }

        $args = [
            'type' => 'variable',
            'limit' => -1,
            'status' => 'publish',
        ];
        $products = wc_get_products($args);
        $report_data = [];

        $query_args = [
            'post_type' => 'shop_order',
            'post_status' => ['wc-completed', 'wc-processing'],
            'posts_per_page' => -1,
        ];

        if ($week) {
            $query_args['date_query'] = [
                [
                    'after' => $week,
                    'before' => date('Y-m-d', strtotime($week . ' + 6 days')),
                    'inclusive' => true,
                ],
            ];
        }

        if ($year) {
            $query_args['date_query'] = [
                [
                    'year' => $year,
                ],
            ];
        }

        $orders = get_posts($query_args);

        foreach ($products as $product) {
            $product_id = $product->get_id();
            $variations = $product->get_children();

            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }

                $variation_region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
                $variation_venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
                $variation_camp_type = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names'])[0] ?? 'Unknown';
                $variation_booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';

                if ($region && $region !== $variation_region) {
                    continue;
                }
                if ($camp_type && $camp_type !== $variation_camp_type) {
                    continue;
                }

                $days = [
                    'Monday' => 0,
                    'Tuesday' => 0,
                    'Wednesday' => 0,
                    'Thursday' => 0,
                    'Friday' => 0,
                ];

                $total = 0;
                $total_range = 0;
                $buyclub = 0;
                $girls_only = 0;
                $full_week = 0;
                $ages = [];
                $genders = ['Male' => 0, 'Female' => 0, 'Other' => 0];

                foreach ($orders as $order_post) {
                    $order = wc_get_order($order_post->ID);
                    if (!$order) {
                        continue;
                    }

                    foreach ($order->get_items() as $item) {
                        if ($item->get_variation_id() != $variation_id) {
                            continue;
                        }

                        $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
                        if (!$player_name) {
                            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                            if ($player_name) {
                                wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                                error_log('InterSoccer: Reports - Normalized Assigned Player to Assigned Attendee for Order Item ID ' . $item->get_id());
                            }
                        }

                        $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                        $user_id = $order->get_user_id();
                        $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];

                        if (!$player_name && $player_index && isset($players[$player_index])) {
                            $player = $players[$player_index];
                            $player_name = $player['first_name'] . ' ' . $player['last_name'];
                            wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                            error_log('InterSoccer: Reports - Restored Assigned Attendee metadata for Order Item ID ' . $item->get_id() . ' as ' . $player_name);
                        }

                        if (!$player_name) {
                            error_log('InterSoccer: Reports - No Assigned Attendee for Order Item ID ' . $item->get_id());
                            continue;
                        }

                        $age = 'N/A';
                        $gender = 'N/A';
                        if ($player_index && isset($players[$player_index])) {
                            $player = $players[$player_index];
                            if (isset($player['dob']) && !empty($player['dob'])) {
                                $dob = DateTime::createFromFormat('Y-m-d', $player['dob']);
                                if ($dob) {
                                    $current_date = new DateTime(current_time('Y-m-d'));
                                    $interval = $dob->diff($current_date);
                                    $age = $interval->y;
                                } else {
                                    error_log('InterSoccer: Reports - Invalid DOB format for Order Item ID ' . $item->get_id() . ': ' . $player['dob']);
                                }
                            }
                            $gender = isset($player['gender']) && !empty($player['gender']) ? ucfirst($player['gender']) : 'Other';
                        }

                        $days_of_week = wc_get_order_item_meta($item->get_id(), 'Days of Week', true);
                        $days_array = $days_of_week ? explode(',', $days_of_week) : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

                        if ($variation_booking_type === 'Full Week') {
                            $full_week++;
                            foreach ($days as $day => $count) {
                                $days[$day]++;
                            }
                        } else {
                            foreach ($days_array as $day) {
                                $day = trim($day);
                                if (isset($days[$day])) {
                                    $days[$day]++;
                                }
                            }
                        }

                        $total++;
                        $buyclub += (int) wc_get_order_item_meta($item->get_id(), 'buyclub', true);
                        $girls_only += (int) wc_get_order_item_meta($item->get_id(), 'girls_only', true);

                        if (is_numeric($age)) {
                            $ages[] = $age;
                        }
                        if (in_array($gender, ['Male', 'Female', 'Other'])) {
                            $genders[$gender]++;
                        } else {
                            error_log('InterSoccer: Reports - Invalid gender for Order Item ID ' . $item->get_id() . ': ' . $gender);
                        }
                    }
                }

                $average_age = !empty($ages) ? round(array_sum($ages) / count($ages), 1) : 'N/A';
                $gender_distribution = sprintf(
                    'Male: %d, Female: %d, Other: %d',
                    $genders['Male'],
                    $genders['Female'],
                    $genders['Other']
                );

                $report_data[] = [
                    'venue' => $variation_venue,
                    'region' => $variation_region,
                    'week' => $week ?: 'All',
                    'year' => $year,
                    'camp_type' => $variation_camp_type,
                    'full_week' => $full_week,
                    'buyclub' => $buyclub,
                    'girls_only' => $girls_only,
                    'days' => $days,
                    'average_age' => $average_age,
                    'gender_distribution' => $gender_distribution,
                    'total' => $total,
                    'total_range' => $total * 5,
                ];
            }
        }

        return $report_data;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_pe_get_camp_report_data: ' . $e->getMessage());
        return [];
    }
}
?>
