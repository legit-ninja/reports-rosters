<?php
/**
 * Roster data functions for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.3
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Normalize attribute values for comparison.
 *
 * @param string|null $value Attribute value.
 * @return string Normalized value.
 */
function intersoccer_normalize_attribute($value) {
    return trim(strtolower($value ?? ''));
}

/**
 * Parse date range from camp terms (e.g., "June 30-July 4 (5 days)" or "July 7-11 (5 days)" from camp terms).
 *
 * @param string $camp_terms The camp terms string.
 * @return array|null Array with start and end dates or null if parsing fails.
 */
function intersoccer_parse_camp_dates($camp_terms) {
    // Match pattern: Month Day-Month Day (X days) or Month Day-Day (X days)
    if (preg_match('/(\w+\s+\d+(?:st|nd|rd|th)?)\s*(?:-|\s+-\s+)(\w+\s+\d+(?:st|nd|rd|th)?)\s*\((\d+)\s+days\)/i', $camp_terms, $matches)) {
        $start_date_str = trim($matches[1]);
        $end_date_str = trim($matches[2]);
        $expected_days = (int)$matches[3];

        $start_parts = preg_split('/\s+/', $start_date_str);
        $end_parts = preg_split('/\s+/', $end_date_str);
        if (count($start_parts) >= 2 && count($end_parts) >= 1) {
            $start_month = $start_parts[0];
            $start_day = (int)preg_replace('/(st|nd|rd|th)/', '', $start_parts[1]);
            $end_month = $end_parts[0];
            $end_day = (int)preg_replace('/(st|nd|rd|th)/', '', $end_parts[1] ?? $end_parts[0]);
            $year = date('Y'); // Assume current year

            $start_date = DateTime::createFromFormat('F j Y', "$start_month $start_day $year");
            $end_date = DateTime::createFromFormat('F j Y', "$end_month $end_day $year");

            if ($start_date && $end_date && $end_date >= $start_date) {
                $diff = $start_date->diff($end_date);
                $actual_days = $diff->days + 1; // Inclusive days
                if ($actual_days === $expected_days && in_array($actual_days, [4, 5])) { // Validate 4 or 5 days
                    error_log("InterSoccer: Successfully parsed $camp_terms to " . $start_date->format('Y-m-d') . " - " . $end_date->format('Y-m-d') . " with $actual_days days");
                    return ['start' => $start_date, 'end' => $end_date];
                } else {
                    error_log("InterSoccer: Parsed $camp_terms to " . $start_date->format('Y-m-d') . " - " . $end_date->format('Y-m-d') . " but duration ($actual_days days) does not match expected ($expected_days days) or is not 4/5 days");
                    return null;
                }
            }
        }
        error_log("InterSoccer: Failed to parse dates from camp term: $camp_terms due to invalid date parts");
    } else {
        error_log("InterSoccer: No date range match found in camp term: $camp_terms");
    }
    return null;
}

/**
 * Fetch variations for Camps with assigned players, grouped by configuration.
 *
 * @param array $filters Filter parameters (region, venue, show_no_attendees).
 * @return array Camp variations.
 */
function intersoccer_pe_get_camp_variations($filters) {
    try {
        if (!function_exists('wc_get_products')) {
            error_log('InterSoccer: wc_get_products not available in intersoccer_pe_get_camp_variations');
            return [];
        }

        $variable_products = wc_get_products([
            'type' => 'variable',
            'limit' => -1,
            'status' => 'publish',
        ]);

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
                $activity_types = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']);
                error_log("InterSoccer: Checking variation $variation_id with activity types: " . (is_array($activity_types) ? implode(', ', $activity_types) : 'N/A'));
                $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
                $is_girls_only = in_array('girls\' only', array_map('intersoccer_normalize_attribute', $activity_types ?? []));

                if (!in_array('camp', array_map('intersoccer_normalize_attribute', $activity_types ?? []))) {
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
                    error_log("InterSoccer: Added player $player_name to variation $variation_id, total players: " . count($variation_players[$variation_id]));
                }
            }
        }

        wp_reset_postdata();

        $current_date = new DateTime(current_time('Y-m-d')); // 06:47 PM EDT, June 10, 2025
        $past_date = (clone $current_date)->modify('-52 weeks');
        $end_date = (clone $current_date)->modify('+52 weeks');
        $current_date_str = $current_date->format('Y-m-d');
        $past_date_str = $past_date->format('Y-m-d');
        $end_date_str = $end_date->format('Y-m-d');

        $config_grouped = [];
        foreach ($variable_products as $product) {
            $product_id = $product->get_id();
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }

                $activity_types = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']) ?? [];
                $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
                $is_girls_only = in_array('girls\' only', array_map('intersoccer_normalize_attribute', $activity_types ?? []));

                if (!in_array('camp', array_map('intersoccer_normalize_attribute', $activity_types ?? []))) {
                    continue;
                }

                $variation_region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? '';
                $variation_venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? '';
                $variation_age_group = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names'])[0] ?? '';
                $variation_season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? '';
                $variation_city = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names'])[0] ?? '';
                $variation_camp_terms = wc_get_product_terms($product_id, 'pa_camp-terms', ['fields' => 'names'])[0] ?? 'N/A';

                $variation_region = intersoccer_normalize_attribute($variation_region);
                $variation_venue = intersoccer_normalize_attribute($variation_venue);
                $variation_age_group = intersoccer_normalize_attribute($variation_age_group);
                $variation_season = intersoccer_normalize_attribute($variation_season);
                $variation_city = intersoccer_normalize_attribute($variation_city);

                $filter_region = isset($filters['region']) ? intersoccer_normalize_attribute($filters['region']) : '';
                $filter_venue = isset($filters['venue']) ? intersoccer_normalize_attribute($filters['venue']) : '';

                if ($filter_region && $variation_region !== $filter_region) {
                    continue;
                }
                if ($filter_venue && $variation_venue !== $filter_venue) {
                    continue;
                }

                $camp_dates = intersoccer_parse_camp_dates($variation_camp_terms);
                $is_active_or_upcoming = true; // Temporarily disabled for debugging
                if ($camp_dates) {
                    $start_str = $camp_dates['start']->format('Y-m-d');
                    $end_str = $camp_dates['end']->format('Y-m-d');
                    $diff = $camp_dates['start']->diff($camp_dates['end']);
                    $actual_days = $diff->days + 1;
                    error_log("InterSoccer: Camp term $variation_camp_terms parsed to $start_str - $end_str, duration: $actual_days days, status: " . ($end_str >= $past_date_str && $start_str <= $end_date_str ? 'active/upcoming' : 'excluded'));
                    if ($end_str >= $past_date_str && $start_str <= $end_date_str) {
                        $is_active_or_upcoming = true;
                    }
                } else {
                    error_log("InterSoccer: Failed to parse dates from camp term: $variation_camp_terms, forcing inclusion for debugging");
                }

                if (!$is_active_or_upcoming) {
                    continue;
                }

                $assigned_players = isset($variation_players[$variation_id]) ? $variation_players[$variation_id] : [];

                if (!$show_no_attendees && empty($assigned_players)) {
                    continue;
                }

                $week = wc_get_product_terms($product_id, 'pa_week', ['fields' => 'names'])[0] ?? '';
                if (!$week && $camp_dates) {
                    $date = $camp_dates['start'];
                    if ($date) {
                        $week = 'Week ' . $date->format('W');
                    }
                }

                $base_name = $product->get_name() ?? '';
                $variation_name = $variation->get_name() ?? '';
                if (stripos($variation_name, $base_name) !== false) {
                    $variation_name = trim(str_replace($base_name, '', $variation_name));
                }
                $event_name = $base_name . ($variation_name ? ' - ' . $variation_name : '');
                if ($week) {
                    $event_name .= ' (' . $week . ')';
                }

                // Group by product_name, camp_terms, and venue
                $config_key = $event_name . '|' . $variation_camp_terms . '|' . $variation_venue;
                error_log("InterSoccer: Adding config key: $config_key with " . count($assigned_players) . " players");
                if (!isset($config_grouped[$config_key])) {
                    $config_grouped[$config_key] = [
                        'product_name' => $event_name,
                        'camp_terms' => $variation_camp_terms,
                        'region' => $variation_region ?: 'Unknown',
                        'venue' => $variation_venue ?: 'Unknown',
                        'age_group' => $variation_age_group ?: 'Unknown',
                        'booking_type' => $booking_type,
                        'season' => $variation_season ?: 'Unknown',
                        'city' => $variation_city ?: 'Unknown',
                        'activity_type' => implode(', ', $activity_types),
                        'total_players' => 0,
                        'variation_ids' => [],
                    ];
                }
                $config_grouped[$config_key]['variation_ids'] = array_merge($config_grouped[$config_key]['variation_ids'], [$variation_id]);
                $config_grouped[$config_key]['variation_ids'] = array_unique($config_grouped[$config_key]['variation_ids']);
                // Collect all players across variation_ids and deduplicate
                $all_players = [];
                foreach ($config_grouped[$config_key]['variation_ids'] as $vid) {
                    if (isset($variation_players[$vid])) {
                        $all_players = array_merge($all_players, $variation_players[$vid]);
                    }
                }
                $config_grouped[$config_key]['total_players'] = count(array_unique($all_players));
            }
        }

        error_log("InterSoccer: Total camp configurations found: " . count($config_grouped));
        return $config_grouped;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_pe_get_camp_variations: ' . $e->getMessage());
        return [];
    }
}

/**
 * Fetch variations for Courses with assigned players, grouped by configuration.
 *
 * @param array $filters Filter parameters (region, venue, show_no_attendees).
 * @return array Course variations.
 */
function intersoccer_pe_get_course_variations($filters) {
    try {
        if (!function_exists('wc_get_products')) {
            error_log('InterSoccer: wc_get_products not available in intersoccer_pe_get_course_variations');
            return [];
        }

        $variable_products = wc_get_products([
            'type' => 'variable',
            'limit' => -1,
            'status' => 'publish',
        ]);

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
                $activity_types = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']) ?? [];
                $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
                $is_girls_only = in_array('girls\' only', array_map('intersoccer_normalize_attribute', $activity_types));

                if (!in_array('course', array_map('intersoccer_normalize_attribute', $activity_types)) || 
                    in_array($booking_type, ['Full Week', 'single-days'])) {
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
        $current_date_str = $current_date->format('Y-m-d');

        $config_grouped = [];
        foreach ($variable_products as $product) {
            $product_id = $product->get_id();
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }

                $activity_types = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']) ?? [];
                $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
                $is_girls_only = in_array('girls\' only', array_map('intersoccer_normalize_attribute', $activity_types));

                if (!in_array('course', array_map('intersoccer_normalize_attribute', $activity_types)) || 
                    in_array($booking_type, ['Full Week', 'single-days'])) {
                    continue;
                }

                $variation_region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? '';
                $variation_venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? '';
                $variation_age_group = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names'])[0] ?? '';
                $variation_season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? '';
                $variation_city = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names'])[0] ?? '';

                $variation_region = intersoccer_normalize_attribute($variation_region);
                $variation_venue = intersoccer_normalize_attribute($variation_venue);
                $variation_age_group = intersoccer_normalize_attribute($variation_age_group);
                $variation_season = intersoccer_normalize_attribute($variation_season);
                $variation_city = intersoccer_normalize_attribute($variation_city);

                $filter_region = isset($filters['region']) ? intersoccer_normalize_attribute($filters['region']) : '';
                $filter_venue = isset($filters['venue']) ? intersoccer_normalize_attribute($filters['venue']) : '';

                if ($filter_region && $variation_region !== $filter_region) {
                    continue;
                }
                if ($filter_venue && $variation_venue !== $filter_venue) {
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

                $base_name = $product->get_name() ?? '';
                $variation_name = $variation->get_name() ?? '';
                if (stripos($variation_name, $base_name) !== false) {
                    $variation_name = trim(str_replace($base_name, '', $variation_name));
                }
                $event_name = $base_name . ($variation_name ? ' - ' . $variation_name : '');
                if ($week) {
                    $event_name .= ' (' . $week . ')';
                }

                // Group by product_name and venue
                $config_key = $event_name . '|' . $variation_venue;
                if (!isset($config_grouped[$config_key])) {
                    $config_grouped[$config_key] = [
                        'product_name' => $event_name,
                        'region' => $variation_region ?: 'Unknown',
                        'venue' => $variation_venue ?: 'Unknown',
                        'age_group' => $variation_age_group ?: 'Unknown',
                        'booking_type' => $booking_type,
                        'season' => $variation_season ?: 'Unknown',
                        'city' => $variation_city ?: 'Unknown',
                        'activity_type' => implode(', ', $activity_types),
                        'total_players' => 0,
                        'variation_ids' => [],
                    ];
                }
                $config_grouped[$config_key]['variation_ids'] = array_merge($config_grouped[$config_key]['variation_ids'], [$variation_id]);
                $config_grouped[$config_key]['variation_ids'] = array_unique($config_grouped[$config_key]['variation_ids']);
                // Collect all players across variation_ids and deduplicate
                $all_players = [];
                foreach ($config_grouped[$config_key]['variation_ids'] as $vid) {
                    if (isset($variation_players[$vid])) {
                        $all_players = array_merge($all_players, $variation_players[$vid]);
                    }
                }
                $config_grouped[$config_key]['total_players'] = count(array_unique($all_players));
            }
        }

        return $config_grouped;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_pe_get_course_variations: ' . $e->getMessage());
        return [];
    }
}

/**
 * Fetch variations for Girls Only events with assigned players, grouped by configuration.
 *
 * @param array $filters Filter parameters (region, venue, show_no_attendees).
 * @return array Girls Only variations.
 */
function intersoccer_pe_get_girls_only_variations($filters) {
    try {
        if (!function_exists('wc_get_products')) {
            error_log('InterSoccer: wc_get_products not available in intersoccer_pe_get_girls_only_variations');
            return [];
        }

        $variable_products = wc_get_products([
            'type' => 'variable',
            'limit' => -1,
            'status' => 'publish',
        ]);

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
                $activity_types = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']) ?? [];
                $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
                $is_girls_only = in_array('girls\' only', array_map('intersoccer_normalize_attribute', $activity_types));

                if (!$is_girls_only || 
                    (!in_array('camp', array_map('intersoccer_normalize_attribute', $activity_types)) && 
                     !in_array('course', array_map('intersoccer_normalize_attribute', $activity_types)))) {
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
        $current_date_str = $current_date->format('Y-m-d');
        if (in_array('camp', array_map('intersoccer_normalize_attribute', $activity_types))) {
            $end_date = (clone $current_date)->modify('+12 weeks');
            $end_date_str = $end_date->format('Y-m-d');
        } else {
            $end_date_str = $current_date_str;
        }

        $config_grouped = [];
        foreach ($variable_products as $product) {
            $product_id = $product->get_id();
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }

                $activity_types = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names']) ?? [];
                $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
                $is_girls_only = in_array('girls\' only', array_map('intersoccer_normalize_attribute', $activity_types));

                if (!$is_girls_only || 
                    (!in_array('camp', array_map('intersoccer_normalize_attribute', $activity_types)) && 
                     !in_array('course', array_map('intersoccer_normalize_attribute', $activity_types)))) {
                    continue;
                }

                $variation_region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? '';
                $variation_venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? '';
                $variation_age_group = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names'])[0] ?? '';
                $variation_season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? '';
                $variation_city = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names'])[0] ?? '';
                $variation_camp_terms = in_array('camp', array_map('intersoccer_normalize_attribute', $activity_types)) ? 
                    wc_get_product_terms($product_id, 'pa_camp-terms', ['fields' => 'names'])[0] ?? 'N/A' : 'N/A';

                $variation_region = intersoccer_normalize_attribute($variation_region);
                $variation_venue = intersoccer_normalize_attribute($variation_venue);
                $variation_age_group = intersoccer_normalize_attribute($variation_age_group);
                $variation_season = intersoccer_normalize_attribute($variation_season);
                $variation_city = intersoccer_normalize_attribute($variation_city);

                $filter_region = isset($filters['region']) ? intersoccer_normalize_attribute($filters['region']) : '';
                $filter_venue = isset($filters['venue']) ? intersoccer_normalize_attribute($filters['venue']) : '';

                if ($filter_region && $variation_region !== $filter_region) {
                    continue;
                }
                if ($filter_venue && $variation_venue !== $filter_venue) {
                    continue;
                }

                $camp_dates = intersoccer_parse_camp_dates($variation_camp_terms);
                $is_active_or_upcoming = true; // Temporarily disabled for debugging
                if ($camp_dates) {
                    $start_str = $camp_dates['start']->format('Y-m-d');
                    $end_str = $camp_dates['end']->format('Y-m-d');
                    $diff = $camp_dates['start']->diff($camp_dates['end']);
                    $actual_days = $diff->days + 1;
                    error_log("InterSoccer: Girls Only camp term $variation_camp_terms parsed to $start_str - $end_str, duration: $actual_days days, status: " . ($end_str >= $current_date_str && $start_str <= $end_date_str ? 'active/upcoming' : 'excluded'));
                    if ($end_str >= $current_date_str && $start_str <= $end_date_str) {
                        $is_active_or_upcoming = true;
                    }
                } else {
                    error_log("InterSoccer: Failed to parse dates from girls only camp term: $variation_camp_terms, forcing inclusion for debugging");
                }

                if (!$is_active_or_upcoming) {
                    continue;
                }

                $assigned_players = isset($variation_players[$variation_id]) ? $variation_players[$variation_id] : [];

                if (!$show_no_attendees && empty($assigned_players)) {
                    continue;
                }

                $week = wc_get_product_terms($product_id, 'pa_week', ['fields' => 'names'])[0] ?? '';
                if (!$week && $camp_dates) {
                    $date = $camp_dates['start'];
                    if ($date) {
                        $week = 'Week ' . $date->format('W');
                    }
                }

                $base_name = $product->get_name() ?? '';
                $variation_name = $variation->get_name() ?? '';
                if (stripos($variation_name, $base_name) !== false) {
                    $variation_name = trim(str_replace($base_name, '', $variation_name));
                }
                $event_name = $base_name . ($variation_name ? ' - ' . $variation_name : '');
                if ($week) {
                    $event_name .= ' (' . $week . ')';
                }

                // Group by product_name and venue
                $config_key = $event_name . '|' . $variation_venue;
                if (!isset($config_grouped[$config_key])) {
                    $config_grouped[$config_key] = [
                        'product_name' => $event_name,
                        'region' => $variation_region ?: 'Unknown',
                        'venue' => $variation_venue ?: 'Unknown',
                        'age_group' => $variation_age_group ?: 'Unknown',
                        'booking_type' => $booking_type,
                        'season' => $variation_season ?: 'Unknown',
                        'city' => $variation_city ?: 'Unknown',
                        'activity_type' => implode(', ', $activity_types),
                        'total_players' => 0,
                        'variation_ids' => [],
                    ];
                }
                $config_grouped[$config_key]['variation_ids'] = array_merge($config_grouped[$config_key]['variation_ids'], [$variation_id]);
                $config_grouped[$config_key]['variation_ids'] = array_unique($config_grouped[$config_key]['variation_ids']);
                // Collect all players across variation_ids and deduplicate
                $all_players = [];
                foreach ($config_grouped[$config_key]['variation_ids'] as $vid) {
                    if (isset($variation_players[$vid])) {
                        $all_players = array_merge($all_players, $variation_players[$vid]);
                    }
                }
                $config_grouped[$config_key]['total_players'] = count(array_unique($all_players));
            }
        }

        return $config_grouped;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_pe_get_girls_only_variations: ' . $e->getMessage());
        return [];
    }
}

/**
 * Fetch roster for a specific variation or group of variations.
 *
 * @param array|int $variation_ids The WooCommerce variation ID(s).
 * @param array $context Optional context data (e.g., camp_terms, start_date, end_date, variation_players).
 * @return array Roster data.
 */
function intersoccer_pe_get_event_roster_by_variation($variation_ids, $context = []) {
    try {
        if (!function_exists('wc_get_product')) {
            error_log('InterSoccer: wc_get_product not available in intersoccer_pe_get_event_roster_by_variation');
            return [];
        }

        $variation_ids = is_array($variation_ids) ? $variation_ids : [$variation_ids];
        $cache_key = 'roster_variations_' . md5(implode('_', $variation_ids));
        $roster = get_transient($cache_key);
        if ($roster !== false) {
            return $roster;
        }

        $roster = [];
        $processed_players = [];

        // Use pre-aggregated variation_players if provided in context
        $variation_players = $context['variation_players'] ?? [];
        foreach ($variation_ids as $vid) {
            if (isset($variation_players[$vid])) {
                foreach ($variation_players[$vid] as $player_name) {
                    if (!in_array($player_name, $processed_players)) {
                        $processed_players[] = $player_name;
                    }
                }
            }
        }

        $query_args = [
            'post_type' => 'shop_order',
            'post_status' => ['wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold'],
            'posts_per_page' => -1,
        ];

        $order_query = new WP_Query($query_args);
        $orders = $order_query->posts;

        error_log("InterSoccer: Starting roster fetch for variation IDs: " . implode(',', $variation_ids) . " with initial " . count($processed_players) . " players");

        foreach ($orders as $order_post) {
            $order = wc_get_order($order_post->ID);
            if (!$order || !in_array($order->get_status(), ['completed', 'processing', 'pending', 'on-hold'])) {
                continue;
            }

            foreach ($order->get_items() as $item) {
                $variation_id = $item->get_variation_id();
                if (!in_array($variation_id, $variation_ids)) {
                    continue;
                }

                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    error_log("InterSoccer: Invalid variation ID $variation_id skipped in roster fetch");
                    continue;
                }

                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
                if (!$player_name) {
                    $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                    if ($player_name) {
                        wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                    }
                }

                if (!$player_name || in_array($player_name, $processed_players)) {
                    continue;
                }

                $user_id = $order->get_user_id();
                $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
                $player_data = null;

                foreach ($players as $p) {
                    $constructed_name = trim($p['first_name'] . ' ' . $p['last_name']);
                    if (strtolower(trim($constructed_name)) === strtolower(trim($player_name))) {
                        $player_data = $p;
                        break;
                    }
                }

                if (!$player_data) {
                    continue;
                }

                $product_id = $variation->get_parent_id();
                $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
                $is_camp = in_array($booking_type, ['Full Week', 'single-days']);
                $camp_terms = $context['camp_terms'] ?? (wc_get_product_terms($product_id, 'pa_camp-terms', ['fields' => 'names'])[0] ?? 'N/A');
                $start_date = $context['start_date'] ?? (wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? 'N/A');
                $end_date = $context['end_date'] ?? (wc_get_product_terms($product_id, 'pa_end-date', ['fields' => 'names'])[0] ?? 'N/A');

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
                    $days_to_display = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                    if ($booking_type === 'single-days' && !empty($selected_days)) {
                        $selected_days_array = explode(',', esc_html($selected_days));
                        $days_to_display = array_intersect($days_to_display, $selected_days_array);
                        usort($days_to_display, function($a, $b) {
                            $order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                            return array_search($a, $order) - array_search($b, $order);
                        });
                    }
                } else {
                    $days_to_display = !empty($selected_days) ? explode(',', esc_html($selected_days)) : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                }

                $discount_info = '';
                if (!$is_camp && $start_date !== 'N/A' && $end_date !== 'N/A') {
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

                $roster_entry = [
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
                    'selected_days' => $days_to_display,
                    'discount_info' => esc_html($discount_info),
                    'venue' => esc_html($venue),
                    'region' => esc_html($region),
                    'season' => esc_html($season),
                    'parent_country' => esc_html($parent_country),
                    'parent_state' => esc_html($parent_state),
                    'parent_city' => esc_html($parent_city),
                    'camp_terms' => esc_html($camp_terms),
                    'start_date' => esc_html($start_date),
                    'end_date' => esc_html($end_date),
                ];

                $roster[] = $roster_entry;
                $processed_players[] = $player_name;
            }
        }

        wp_reset_postdata();
        set_transient($cache_key, $roster, HOUR_IN_SECONDS);
        error_log("InterSoccer: Fetched roster for variation IDs " . implode(',', $variation_ids) . " with " . count($roster) . " attendees");
        return $roster;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in intersoccer_pe_get_event_roster_by_variation: ' . $e->getMessage());
        return [];
    }
}
?>
