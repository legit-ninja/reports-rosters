<?php
/**
 * Roster data functions for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Normalize attribute values for comparison.
 *
 * @param string $value Attribute value.
 * @return string Normalized value.
 */
function intersoccer_normalize_attribute($value) {
    return trim(strtolower($value));
}

/**
 * Fetch variations for Camps with assigned players.
 *
 * @param array $filters Filter parameters (region, venue, etc.).
 * @return array Camp variations.
 */
function intersoccer_pe_get_camp_variations($filters) {
    $variable_products = wc_get_products([
        'type' => 'variable',
        'limit' => -1,
        'status' => 'publish',
    ]);
    error_log('InterSoccer: Found ' . count($variable_products) . ' variable products for Camps');

    $variation_data = [];
    $show_no_attendees = isset($filters['show_no_attendees']) && $filters['show_no_attendees'] === '1';

    $query_args = [
        'post_type' => 'shop_order',
        'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
        'posts_per_page' => -1,
    ];

    $order_query = new WP_Query($query_args);
    $orders = $order_query->posts;

    $variation_players = [];
    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if (!$order || !in_array($order->get_status(), ['completed', 'processing', 'pending', 'on-hold'])) {
            continue;
        }

        foreach ($order->get_items() as $item) {
            $variation_id = $item->get_variation_id();
            if (!$variation_id) {
                continue;
            }

            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }

            $product_id = $variation->get_parent_id();
            $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
            if (!in_array($booking_type, ['Full Week', 'single-days'])) {
                continue;
            }

            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
            if (!$player_name) {
                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                if ($player_name) {
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                }
            }

            if (!$player_name) {
                $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                $user_id = $order->get_user_id();
                $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
                if ($player_index && isset($players[$player_index])) {
                    $player = $players[$player_index];
                    $player_name = $player['first_name'] . ' ' . $player['last_name'];
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                }
            }

            if ($player_name) {
                if (!isset($variation_players[$variation_id])) {
                    $variation_players[$variation_id] = [];
                }
                if (!in_array($player_name, $variation_players[$variation_id])) {
                    $variation_players[$variation_id][] = $player_name;
                }
            }
        }
    }

    wp_reset_postdata();

    $current_date = new DateTime(current_time('Y-m-d'));
    $end_date = (clone $current_date)->modify('+12 weeks');
    $current_date_str = $current_date->format('Y-m-d');
    $end_date_str = $end_date->format('Y-m-d');

    foreach ($variable_products as $product) {
        $product_id = $product->get_id();
        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }

            $variation_region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? '';
            $variation_venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? '';
            $variation_age_group = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names'])[0] ?? '';
            $variation_booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
            $variation_season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? '';
            $variation_city = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names'])[0] ?? '';
            $variation_activity_type = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names'])[0] ?? '';

            if (!in_array($variation_booking_type, ['Full Week', 'single-days'])) {
                continue;
            }

            $variation_region = intersoccer_normalize_attribute($variation_region);
            $variation_venue = intersoccer_normalize_attribute($variation_venue);
            $variation_age_group = intersoccer_normalize_attribute($variation_age_group);
            $variation_season = intersoccer_normalize_attribute($variation_season);
            $variation_city = intersoccer_normalize_attribute($variation_city);
            $variation_activity_type = intersoccer_normalize_attribute($variation_activity_type);

            $filter_region = intersoccer_normalize_attribute($filters['region']);
            $filter_venue = intersoccer_normalize_attribute($filters['venue']);
            $filter_age_group = intersoccer_normalize_attribute($filters['age_group']);
            $filter_season = intersoccer_normalize_attribute($filters['season']);
            $filter_city = intersoccer_normalize_attribute($filters['city']);
            $filter_activity_type = intersoccer_normalize_attribute($filters['activity_type']);

            if ($filter_region && $variation_region !== $filter_region) {
                continue;
            }
            if ($filter_venue && $variation_venue !== $filter_venue) {
                continue;
            }
            if ($filter_age_group && $variation_age_group !== $filter_age_group) {
                continue;
            }
            if ($filter_season && $variation_season !== $filter_season) {
                continue;
            }
            if ($filter_city && $variation_city !== $filter_city) {
                continue;
            }
            if ($filter_activity_type && $variation_activity_type !== $filter_activity_type) {
                continue;
            }

            $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? null;
            $end_date = wc_get_product_terms($product_id, 'pa_end-date', ['fields' => 'names'])[0] ?? null;
            $is_upcoming = false;
            if ($start_date && $end_date) {
                $start = DateTime::createFromFormat('d/m/Y', $start_date);
                $end = DateTime::createFromFormat('d/m/Y', $end_date);
                if ($start && $end) {
                    $start_str = $start->format('Y-m-d');
                    $end_str = $end->format('Y-m-d');
                    if ($start_str >= $current_date_str && $start_str <= $end_date_str) {
                        $is_upcoming = true;
                    }
                }
            } else {
                $is_upcoming = true;
            }

            if (!$is_upcoming) {
                continue;
            }

            $assigned_players = isset($variation_players[$variation_id]) ? $variation_players[$variation_id] : [];

            if (!$show_no_attendees && empty($assigned_players)) {
                continue;
            }

            $week = wc_get_product_terms($product_id, 'pa_week', ['fields' => 'names'])[0] ?? '';
            if (!$week && $start_date) {
                $date = DateTime::createFromFormat('d/m/Y', $start_date);
                if ($date) {
                    $week = 'Week ' . $date->format('W');
                }
            }

            $base_name = $product->get_name();
            $variation_name = $variation->get_name();
            if (stripos($variation_name, $base_name) !== false) {
                $variation_name = trim(str_replace($base_name, '', $variation_name));
            }
            $event_name = $base_name . ($variation_name ? ' - ' . $variation_name : '');
            if ($week) {
                $event_name .= ' (' . $week . ')';
            }

            $variation_data[] = [
                'variation_id' => $variation_id,
                'product_name' => $event_name,
                'region' => $variation_region ?: 'Unknown',
                'venue' => $variation_venue ?: 'Unknown',
                'age_group' => $variation_age_group ?: 'Unknown',
                'booking_type' => $variation_booking_type,
                'season' => $variation_season ?: 'Unknown',
                'city' => $variation_city ?: 'Unknown',
                'activity_type' => $variation_activity_type ?: 'Unknown',
                'total_players' => count($assigned_players),
            ];
        }
    }

    return $variation_data;
}

/**
 * Fetch variations for Courses with assigned players.
 *
 * @param array $filters Filter parameters (region, venue, etc.).
 * @return array Course variations.
 */
function intersoccer_pe_get_course_variations($filters) {
    $variable_products = wc_get_products([
        'type' => 'variable',
        'limit' => -1,
        'status' => 'publish',
    ]);
    error_log('InterSoccer: Found ' . count($variable_products) . ' variable products for Courses');

    $variation_data = [];
    $show_no_attendees = isset($filters['show_no_attendees']) && $show_no_attendees !='';

    $query_args = [
        'post_type' => 'shop_order',
        'post_status' => ['wc-completed'],
        'posts_per_page' => -1,
    ];

    $order_query = new WP_Query($query_args);
    $orders = $order_query->posts;

    $variation_players = [];
    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if (!$order || !in_array($order->get_status(), ['completed'])) {
            continue;
        }

        foreach ($order->get_items() as $item) {
            $variation_id = $item->get_variation_id();
            if (!$item) {
                continue;
            }

            $variation = wc_get_product($item->get_id());
            if (!$variation) {
                continue;
            }

            $product_id = $variation->get_parent_id();
            $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
            if (in_array($booking_type, ['Full Week', 'single-days'])) {
                continue;
            }

            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
            if (!$player_name) {
                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                if ($player_name) {
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                }
            }

            if (!$player_name) {
                $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                $user_id = $order->get_user_id();
                $players = get_user_meta($user_id, ['intersoccer_players'], true) ?: [];
                if ($player_index && isset($players[$player_index])) {
                    $player = $players[$player_index];
                    $player_name = $player['first_name'] . ' ' . $player['last_name'];
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                }
            }

            if ($player_name) {
                if (!isset($variation_players[$variation_id])) {
                    $variation_players[$variation_id] = [];
                }
                if (!in_array($player_name, $variation_players[$variation_id])) {
                    $variation_players[$variation_id][] = $player_name;
                }
            }
        }
    }

    wp_reset_postdata();

    $current_date = new DateTime(current_time('Y-m-d'));
    $current_date_str = $current_date->format('Y-m-d');

    foreach ($variable_products as $product) {
        $product_id = $product->get_id();
        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }

            $variation_region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? '';
            $variation_venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? '';
            $variation_age_group = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names'])[0] ?? '';
            $variation_booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
            $variation_season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? '';
            $variation_city = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names'])[0] ?? '';
            $variation_activity_type = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names'])[0] ?? '';

            if (in_array($variation_booking_type, ['Full Week', 'single-days'])) {
                continue;
            }

            $variation_region = intersoccer_normalize_attribute($variation_region);
            $variation_venue = intersoccer_normalize_attribute($variation_venue);
            $variation_age_group = intersoccer_normalize_attribute($variation_age_group);
            $variation_season = intersoccer_normalize_attribute($variation_season);
            $variation_city = intersoccer_normalize_attribute($variation_city);
            $variation_activity_type = intersoccer_normalize_attribute($variation_activity_type);

            $filter_region = intersoccer_normalize_attribute($filters['region']);
            $filter_venue = intersoccer_normalize_attribute($filters['venue']);
            $filter_age_group = intersoccer_normalize_attribute($filters['age_group']);
            $filter_season = intersoccer_normalize_attribute($filters['season']);
            $filter_city = intersoccer_normalize_attribute($filters['city']);
            $filter_activity_type = intersoccer_normalize_attribute($filters['activity_type']);

            if ($filter_region && $variation_region !== $filter_region) {
                continue;
            }
            if ($filter_venue && $variation_venue !== $filter_venue) {
                continue;
            }
            if ($filter_age_group && $variation_age_group !== $filter_age_group) {
                continue;
            }
            if ($filter_season && $variation_season !== $filter_season) {
                continue;
            }
            if ($filter_city && $variation_city !== $filter_city) {
                continue;
            }
            if ($filter_activity_type && $variation_activity_type !== $filter_activity_type) {
                continue;
            }

            $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? null;
            $end_date = wc_get_product_terms($product_id, 'pa_end-date', ['fields' => 'names'])[0] ?? null;
            $is_ongoing = false;
            if ($start_date && $end_date) {
                $start = DateTime::createFromFormat('d/m/Y', $start_date);
                $end = DateTime::createFromFormat('d/m/Y', $end_date);
                if ($start && $end) {
                    $start_str = $start->format('Y-m-d');
                    $end_str = $end->format('Y-m-d');
                    if ($current_date_str >= $start_str && $current_date_str <= $end_str) {
                        $is_ongoing = true;
                    }
                }
            } else {
                $is_ongoing = true;
            }

            if (!$is_ongoing) {
                continue;
            }

            $assigned_players = isset($variation_players[$variation_id]) ? $variation_players[$variation_id] : [];

            if (!$show_no_attendees && empty($assigned_players)) {
                continue;
            }

            $week = wc_get_product_terms($product_id, 'pa_week', ['fields' => 'names'])[0] ?? '';
            if (!$week && $start_date) {
                $date = DateTime::createFromFormat('d/m/Y', $start_date);
                if ($date) {
                    $week = 'Week ' . $date->format('W');
                }
            }

            $base_name = $product->get_name();
            $variation_name = $variation->get_name();
            if (stripos($variation_name, $base_name) !== false) {
                $variation_name = trim(str_replace($base_name, '', $variation_name));
            }
            $event_name = $base_name . ($variation_name ? ' - ' . $variation_name : '');
            if ($week) {
                $event_name .= ' (' . $week . ')';
            }

            $variation_data[] = [
                'variation_id' => $variation_id,
                'product_name' => $event_name,
                'region' => $variation_region ?: 'Unknown',
                'venue' => $variation_venue ?: 'Unknown',
                'age_group' => $variation_age_group ?: 'Unknown',
                'booking_type' => $variation_booking_type,
                'season' => $variation_season ?: 'Unknown',
                'city' => $variation_city ?: 'Unknown',
                'activity_type' => $variation_activity_type ?: 'Unknown',
                'total_players' => count($assigned_players),
            ];
        }
    }

    return $variation_data;
}

/**
 * Fetch roster for a specific variation.
 *
 * @param int $variation_id The WooCommerce variation ID.
 * @return array Roster data.
 */
function intersoccer_pe_get_event_roster_by_variation($variation_id) {
    $cache_key = 'roster_variation_' . $variation_id;
    $roster = get_transient($cache_key);
    if ($roster !== false) {
        return $roster;
    }

    $variation = wc_get_product($variation_id);
    if (!$variation || $variation->get_type() !== 'variation') {
        error_log('InterSoccer: Invalid variation ID ' . $variation_id);
        return [];
    }

    $product_id = $variation->get_parent_id();
    $product = wc_get_product($product_id);
    if (!$product) {
        error_log('InterSoccer: Invalid parent product ID ' . $product_id);
        return [];
    }

    $days_of_week = wc_get_product_terms($product_id, 'pa_days-of-week', ['fields' => 'names']);
    $default_days = !empty($days_of_week) ? $days_of_week : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
    $is_camp = in_array($booking_type, ['Full Week', 'single-days']);

    $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? null;
    $end_date = wc_get_product_terms($product_id, 'pa_end-date', ['fields' => 'names'])[0] ?? null;

    $query_args = [
        'post_type' => 'shop_order',
        'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
        'posts_per_page' => -1,
    ];

    $order_query = new WP_Query($query_args);
    $orders = $order_query->posts;
    $roster = [];

    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if (!$order || !in_array($order->get_status(), ['completed', 'processing', 'pending', 'on-hold'])) {
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
                }
            }

            if (!$player_name) {
                continue;
            }

            $user_id = $order->get_user_id();
            $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
            $player_data = null;
            $player_index = null;

            $player_index_from_order = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
            if ($player_index_from_order && isset($players[$player_index_from_order])) {
                $player = $players[$player_index_from_order];
                $constructed_name = trim($player['first_name'] . ' ' . $player['last_name']);
                if (strtolower(trim($constructed_name)) === strtolower(trim($player_name))) {
                    $player_data = $player;
                    $player_index = $player_index_from_order;
                }
            }

            if (!$player_data) {
                foreach ($players as $index => $player) {
                    $constructed_name = trim($player['first_name'] . ' ' . $player['last_name']);
                    $player_name_clean = strtolower(trim($player_name));
                    $constructed_name_clean = strtolower(trim($constructed_name));

                    if ($player_name_clean === $constructed_name_clean) {
                        $player_data = $player;
                        $player_index = $index;
                        wc_update_order_item_meta($item->get_id(), 'assigned_player', $index);
                        break;
                    }

                    $player_name_parts = explode(' ', $player_name_clean);
                    $constructed_name_parts = explode(' ', $constructed_name_clean);
                    if (count($player_name_parts) >= 2 && count($constructed_name_parts) >= 2) {
                        $player_first_name = $player_name_parts[0];
                        $player_last_name = end($player_name_parts);
                        $constructed_first_name = $constructed_name_parts[0];
                        $constructed_last_name = end($constructed_name_parts);

                        if ($player_first_name === $constructed_first_name && $player_last_name === $constructed_last_name) {
                            $player_data = $player;
                            $player_index = $index;
                            wc_update_order_item_meta($item->get_id(), 'assigned_player', $index);
                            break;
                        }
                    }
                }
            }

            if (!$player_data) {
                continue;
            }

            $age = 'N/A';
            if (isset($player_data['dob']) && !empty($player_data['dob'])) {
                $dob = DateTime::createFromFormat('Y-m-d', $player_data['dob']);
                if ($dob) {
                    $current_date = new DateTime(current_time('Y-m-d'));
                    $interval = $dob->diff($current_date);
                    $age = $interval->y;
                }
            }

            $gender = isset($player_data['gender']) && !empty($player_data['gender']) ? ucfirst($player_data['gender']) : 'N/A';
            $medical_conditions = isset($player_data['medical_conditions']) ? $player_data['medical_conditions'] : 'None';
            $late_pickup = wc_get_order_item_meta($item->get_id(), 'late_pickup', true) ?: '';

            $selected_days = wc_get_order_item_meta($item->get_id(), 'Days of Week', true);
            $days_to_display = [];

            if ($is_camp) {
                $days_to_display = $default_days;
                if ($booking_type === 'single-days' && !empty($selected_days)) {
                    $selected_days_array = explode(',', esc_html($selected_days));
                    $days_to_display = array_intersect($default_days, $selected_days_array);
                }
            } else {
                $days_to_display = !empty($selected_days) ? explode(',', esc_html($selected_days)) : $default_days;
            }

            $discount_info = '';
            if (!$is_camp && $start_date && $end_date) {
                $start = DateTime::createFromFormat('d/m/Y', $start_date);
                $current_date = new DateTime(current_time('Y-m-d'));
                if ($start && $current_date > $start) {
                    $end = DateTime::createFromFormat('d/m/Y', $end_date);
                    if ($end) {
                        $total_days = $start->diff($end)->days + 1;
                        $days_passed = $start->diff($current_date)->days;
                        $weeks_passed = floor($days_passed / 7);
                        $total_weeks = ceil($total_days / 7);
                        $weeks_remaining = max(0, $total_weeks - $weeks_passed);
                        $discount_info = sprintf(__('Discount: %d weeks remaining', 'intersoccer-reports-rosters'), $weeks_remaining);
                    }
                }
            }

            $region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
            $venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
            $season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? 'Unknown';
            $parent_phone = $order->get_billing_phone() ?: 'N/A';
            $parent_email = $order->get_billing_email() ?: 'N/A';
            $parent_country = $order->get_billing_country() ?: 'N/A';
            $parent_state = $order->get_billing_state() ?: 'N/A';
            $parent_city = $order->get_billing_city() ?: 'N/A';

            $first_name = $player_data['first_name'] ?? '';
            $last_name = $player_data['last_name'] ?? '';

            $roster[] = [
                'player_name' => esc_html($player_name),
                'first_name' => esc_html($first_name),
                'last_name' => esc_html($last_name),
                'age' => $age,
                'gender' => $gender,
                'parent_phone' => esc_html($parent_phone),
                'parent_email' => esc_html($parent_email),
                'medical_conditions' => wp_kses_post($medical_conditions),
                'late_pickup' => $late_pickup,
                'booking_type' => esc_html($booking_type),
                'selected_days' => array_map('esc_html', $days_to_display),
                'discount_info' => esc_html($discount_info),
                'venue' => esc_html($venue),
                'region' => esc_html($region),
                'season' => esc_html($season),
                'parent_country' => esc_html($parent_country),
                'parent_state' => esc_html($parent_state),
                'parent_city' => esc_html($parent_city),
            ];
        }
    }

    wp_reset_postdata();
    set_transient($cache_key, $roster, HOUR_IN_SECONDS);
    return $roster;
}
?>
