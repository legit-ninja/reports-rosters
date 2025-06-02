<?php
/**
 * Roster data retrieval functionality for InterSoccer Reports and Rosters plugin.
 */

// Fetch variations with assigned players
function intersoccer_pe_get_variations_with_players($filters) {
    // Fetch all variable products
    $variable_products = wc_get_products([
        'type' => 'variable',
        'limit' => -1,
        'status' => 'publish',
    ]);
    error_log('InterSoccer: Found ' . count($variable_products) . ' variable products');

    $variation_data = [];
    $show_no_attendees = isset($filters['show_no_attendees']) && $filters['show_no_attendees'] === '1';

    // Log filter conditions
    error_log('InterSoccer: Filter conditions - Region: ' . ($filters['region'] ?: 'None') . ', Venue: ' . ($filters['venue'] ?: 'None') . ', Age Group: ' . ($filters['age_group'] ?: 'None') . ', Booking Type: ' . ($filters['booking_type'] ?: 'None') . ', Season: ' . ($filters['season'] ?: 'None') . ', City: ' . ($filters['city'] ?: 'None') . ', Activity Type: ' . ($filters['activity_type'] ?: 'None') . ', Week: ' . ($filters['week'] ?: 'None') . ', Show No Attendees: ' . ($show_no_attendees ? 'Yes' : 'No'));

    // Fetch all orders with relevant statuses
    $query_args = [
        'post_type' => 'shop_order',
        'post_status' => ['wc-completed', 'wc-processing'],
        'posts_per_page' => -1,
    ];

    // Only apply date filter if a specific week is provided
    if (!empty($filters['week'])) {
        $query_args['date_query'] = [
            [
                'after' => $filters['week'],
                'before' => date('Y-m-d', strtotime($filters['week'] . ' + 6 days')),
                'inclusive' => true,
            ],
        ];
    }

    $order_query = new WP_Query($query_args);
    $orders = $order_query->posts;
    error_log('InterSoccer: Found ' . count($orders) . ' orders with statuses wc-completed, wc-processing');

    // Log each order's details, including user role
    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        $user_id = $order ? $order->get_user_id() : 0;
        $user = $user_id ? get_userdata($user_id) : null;
        $user_roles = $user ? $user->roles : ['guest'];
        error_log('InterSoccer: Order ID ' . $order_post->ID . ' - Status: ' . ($order ? $order->get_status() : 'Invalid') . ', User ID: ' . $user_id . ', User Roles: ' . implode(', ', $user_roles));
    }

    // Build a map of variation_id to assigned players
    $variation_players = [];
    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if (!$order || !in_array($order->get_status(), ['completed', 'processing'])) {
            error_log('InterSoccer: Order ID ' . $order_post->ID . ' skipped - Status: ' . ($order ? $order->get_status() : 'Invalid'));
            continue;
        }

        foreach ($order->get_items() as $item) {
            $variation_id = $item->get_variation_id();
            if (!$variation_id) {
                error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' has no variation ID in Order ID ' . $order->get_id());
                continue;
            }

            // Verify the variation exists
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' from Order Item ID ' . $item->get_id() . ' does not exist');
                continue;
            }

            error_log('InterSoccer: Found Variation ID ' . $variation_id . ' in Order Item ID ' . $item->get_id());

            // Check for Assigned Attendee metadata (current production key)
            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
            if (!$player_name) {
                // Fallback to Assigned Player (legacy key)
                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                if ($player_name) {
                    // Normalize legacy metadata to current format
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                    error_log('InterSoccer: Normalized legacy Assigned Player to Assigned Attendee for Order Item ID ' . $item->get_id());
                }
            }

            // Fallback to user metadata if necessary
            if (!$player_name) {
                $player_index = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
                $user_id = $order->get_user_id();
                $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
                if ($player_index && isset($players[$player_index])) {
                    $player = $players[$player_index];
                    $player_name = $player['first_name'] . ' ' . $player['last_name'];
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                    error_log('InterSoccer: Restored Assigned Attendee metadata for Order Item ID ' . $item->get_id() . ' as ' . $player_name);
                }
            }

            error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - Assigned Attendee: ' . ($player_name ?: 'None'));

            if ($player_name) {
                if (!isset($variation_players[$variation_id])) {
                    $variation_players[$variation_id] = [];
                }
                if (!in_array($player_name, $variation_players[$variation_id])) {
                    $variation_players[$variation_id][] = $player_name;
                    error_log('InterSoccer: Attendee ' . $player_name . ' assigned to Variation ID ' . $variation_id);
                }
            } else {
                error_log('InterSoccer: No attendee assigned to Order Item ID ' . $item->get_id() . ' for Variation ID ' . $variation_id);
            }
        }
    }

    wp_reset_postdata();

    // Log the number of variations with assigned players
    $variations_with_players_count = count($variation_players);
    error_log('InterSoccer: Found ' . $variations_with_players_count . ' variations with assigned attendees');

    // Process variations and apply filters
    foreach ($variable_products as $product) {
        $product_id = $product->get_id();
        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' is invalid');
                continue;
            }

            // Get product attributes
            $variation_region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
            $variation_venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
            $variation_age_group = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names'])[0] ?? 'Unknown';
            $variation_booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
            $variation_season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? 'Unknown';
            $variation_city = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names'])[0] ?? 'Unknown';
            $variation_activity_type = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names'])[0] ?? 'Unknown';

            // Apply filters
            if ($filters['region'] && $filters['region'] !== $variation_region) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' filtered out - Region mismatch: ' . $variation_region . ' != ' . $filters['region']);
                continue;
            }
            if ($filters['venue'] && $filters['venue'] !== $variation_venue) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' filtered out - Venue mismatch: ' . $variation_venue . ' != ' . $filters['venue']);
                continue;
            }
            if ($filters['age_group'] && $filters['age_group'] !== $variation_age_group) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' filtered out - Age Group mismatch: ' . $variation_age_group . ' != ' . $filters['age_group']);
                continue;
            }
            if ($filters['booking_type'] && $filters['booking_type'] !== $variation_booking_type) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' filtered out - Booking Type mismatch: ' . $variation_booking_type . ' != ' . $filters['booking_type']);
                continue;
            }
            if ($filters['season'] && $filters['season'] !== $variation_season) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' filtered out - Season mismatch: ' . $variation_season . ' != ' . $filters['season']);
                continue;
            }
            if ($filters['city'] && $filters['city'] !== $variation_city) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' filtered out - City mismatch: ' . $variation_city . ' != ' . $filters['city']);
                continue;
            }
            if ($filters['activity_type'] && $filters['activity_type'] !== $variation_activity_type) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' filtered out - Activity Type mismatch: ' . $variation_activity_type . ' != ' . $filters['activity_type']);
                continue;
            }

            $assigned_players = isset($variation_players[$variation_id]) ? $variation_players[$variation_id] : [];

            // Default: Show events with attendees; if "Show events with no attendees" is checked, include those too
            if (!$show_no_attendees && empty($assigned_players)) {
                error_log('InterSoccer: Variation ID ' . $variation_id . ' skipped - No attendees and show_no_attendees is not set');
                continue;
            }

            $variation_data[] = [
                'variation_id' => $variation_id,
                'product_name' => $product->get_name() . ' - ' . $variation->get_name(),
                'region' => $variation_region,
                'venue' => $variation_venue,
                'age_group' => $variation_age_group,
                'booking_type' => $variation_booking_type,
                'season' => $variation_season,
                'city' => $variation_city,
                'activity_type' => $variation_activity_type,
                'total_players' => count($assigned_players),
            ];
        }
    }

    error_log('InterSoccer: Retrieved ' . count($variation_data) . ' variations with players');
    return $variation_data;
}

// Fetch roster for a specific variation
function intersoccer_pe_get_event_roster_by_variation($variation_id) {
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

    // Fetch days-of-week attribute
    $days_of_week = wc_get_product_terms($product_id, 'pa_days-of-week', ['fields' => 'names']);
    $default_days = !empty($days_of_week) ? $days_of_week : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    error_log('InterSoccer: Days of Week for Variation ID ' . $variation_id . ': ' . implode(', ', $default_days));

    $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
    $is_camp = in_array($booking_type, ['Full Week', 'single-days']);

    // Fetch start and end dates for discount calculation (for courses)
    $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? null;
    $end_date = wc_get_product_terms($product_id, 'pa_end-date', ['fields' => 'names'])[0] ?? null;

    // Build order query using WP_Query
    $query_args = [
        'post_type' => 'shop_order',
        'post_status' => ['wc-completed', 'wc-processing'],
        'posts_per_page' => -1,
    ];

    $order_query = new WP_Query($query_args);
    $orders = $order_query->posts;
    $roster = [];

    foreach ($orders as $order_post) {
        $order = wc_get_order($order_post->ID);
        if (!$order || !in_array($order->get_status(), ['completed', 'processing'])) {
            error_log('InterSoccer: Order ID ' . $order_post->ID . ' skipped in roster - Status: ' . ($order ? $order->get_status() : 'Invalid'));
            continue;
        }

        foreach ($order->get_items() as $item) {
            if ($item->get_variation_id() != $variation_id) {
                continue;
            }

            // Check for Assigned Attendee metadata (current production key)
            $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Attendee', true);
            if (!$player_name) {
                // Fallback to Assigned Player (legacy key)
                $player_name = wc_get_order_item_meta($item->get_id(), 'Assigned Player', true);
                if ($player_name) {
                    wc_update_order_item_meta($item->get_id(), 'Assigned Attendee', $player_name);
                    error_log('InterSoccer: Normalized legacy Assigned Player to Assigned Attendee for Order Item ID ' . $item->get_id());
                }
            }

            error_log('InterSoccer: Order Item ID ' . $item->get_id() . ' - Assigned Attendee: ' . ($player_name ?: 'None'));

            if (!$player_name) {
                error_log('InterSoccer: No Assigned Attendee found for Order Item ID ' . $item->get_id());
                continue;
            }

            // Fetch user metadata
            $user_id = $order->get_user_id();
            $players = get_user_meta($user_id, 'intersoccer_players', true) ?: [];
            $player_data = null;
            $player_index = null;

            // First attempt: Use assigned_player index if available
            $player_index_from_order = wc_get_order_item_meta($item->get_id(), 'assigned_player', true);
            if ($player_index_from_order && isset($players[$player_index_from_order])) {
                $player = $players[$player_index_from_order];
                $constructed_name = trim($player['first_name'] . ' ' . $player['last_name']);
                if (strtolower(trim($constructed_name)) === strtolower(trim($player_name))) {
                    $player_data = $player;
                    $player_index = $player_index_from_order;
                    error_log('InterSoccer: Matched attendee via assigned_player index for Order Item ID ' . $item->get_id() . ': ' . $player_name);
                } else {
                    error_log('InterSoccer: Name mismatch via assigned_player index for Order Item ID ' . $item->get_id() . '. Expected: ' . $player_name . ', Found: ' . $constructed_name);
                }
            }

            // Fallback: Match by name in intersoccer_players with more flexible matching
            if (!$player_data) {
                foreach ($players as $index => $player) {
                    $constructed_name = trim($player['first_name'] . ' ' . $player['last_name']);
                    $player_name_clean = strtolower(trim($player_name));
                    $constructed_name_clean = strtolower(trim($constructed_name));

                    // Exact match
                    if ($player_name_clean === $constructed_name_clean) {
                        $player_data = $player;
                        $player_index = $index;
                        wc_update_order_item_meta($item->get_id(), 'assigned_player', $index);
                        error_log('InterSoccer: Matched attendee by exact name and updated assigned_player index for Order Item ID ' . $item->get_id() . ': ' . $player_name);
                        break;
                    }

                    // Partial match (e.g., first name and last name separately)
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
                            error_log('InterSoccer: Matched attendee by partial name (first and last) and updated assigned_player index for Order Item ID ' . $item->get_id() . ': ' . $player_name);
                            break;
                        }
                    }
                }
            }

            if (!$player_data) {
                error_log('InterSoccer: Failed to match attendee name ' . $player_name . ' in intersoccer_players for Order Item ID ' . $item->get_id());
                // Log the intersoccer_players array for debugging
                error_log('InterSoccer: intersoccer_players for User ID ' . $user_id . ': ' . print_r($players, true));
                continue;
            }

            // Initialize age, gender, and AVS number
            $age = 'N/A';
            $gender = 'N/A';
            $avs_number = 'N/A';

            // Retrieve age from user meta
            if (isset($player_data['dob']) && !empty($player_data['dob'])) {
                $dob = DateTime::createFromFormat('Y-m-d', $player_data['dob']);
                if ($dob) {
                    $current_date = new DateTime('2025-06-02'); // Current date as of June 2, 2025
                    $interval = $dob->diff($current_date);
                    $age = $interval->y;
                    // Validate age range (2-15 years as per exceptions)
                    if ($age < 2 || $age > 15) {
                        error_log('InterSoccer: Attendee ' . $player_name . ' age ' . $age . ' is outside allowed range (2-15)');
                        continue; // Skip players outside the allowed age range
                    }
                } else {
                    error_log('InterSoccer: Invalid DOB format for attendee ' . $player_name . ': ' . $player_data['dob']);
                }
            }

            // Retrieve gender from user meta
            if (isset($player_data['gender']) && !empty($player_data['gender'])) {
                $gender = esc_html(ucfirst($player_data['gender']));
            }

            // Retrieve AVS number from user meta
            if (isset($player_data['avs_number']) && !empty($player_data['avs_number'])) {
                $avs_number = esc_html($player_data['avs_number']);
            }

            // Handle selected days based on booking type
            $selected_days = wc_get_order_item_meta($item->get_id(), 'Days of Week', true);
            $days_to_display = [];

            if ($is_camp) {
                // For Camps: Use Monday-Friday, adjusted by Selected Days
                $days_to_display = $default_days;
                if ($booking_type === 'single-days' && !empty($selected_days)) {
                    $selected_days_array = explode(',', esc_html($selected_days));
                    $days_to_display = array_intersect($default_days, $selected_days_array);
                }
            } else {
                // For Courses: Use the recurring days (e.g., every Monday, Wednesday)
                $days_to_display = !empty($selected_days) ? explode(',', esc_html($selected_days)) : $default_days;
            }

            error_log('InterSoccer: Selected days for Order Item ID ' . $item->get_id() . ': ' . implode(', ', $days_to_display));

            // Calculate discount for courses if start date has passed
            $discount_info = '';
            if (!$is_camp && $start_date && $end_date) {
                $start = DateTime::createFromFormat('d/m/Y', $start_date);
                $current_date = new DateTime('2025-06-02');
                if ($start && $current_date > $start) {
                    $end = DateTime::createFromFormat('d/m/Y', $end_date);
                    if ($end) {
                        $total_days = $start->diff($end)->days + 1;
                        $days_passed = $start->diff($current_date)->days;
                        $weeks_passed = floor($days_passed / 7);
                        $total_weeks = ceil($total_days / 7);
                        $weeks_remaining = max(0, $total_weeks - $weeks_passed);
                        $discount_info = sprintf(__('Discount: %d weeks remaining', 'intersoccer-reports-rosters'), $weeks_remaining);
                        error_log('InterSoccer: Course discount for Order Item ID ' . $item->get_id() . ': ' . $discount_info);
                    }
                }
            }

            // Retrieve Region and Season from product attributes
            $region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
            $venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
            $season = wc_get_order_item_meta($item->get_id(), 'pa_season', true);
            if (!$season) {
                $season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? 'Unknown';
            }

            // Get parent billing location details
            $parent_country = $order->get_billing_country() ?: 'N/A';
            $parent_state = $order->get_billing_state() ?: 'N/A';
            $parent_city = $order->get_billing_city() ?: 'N/A';

            $roster[] = [
                'player_name' => esc_html($player_name),
                'age' => $age,
                'gender' => $gender,
                'avs_number' => $avs_number,
                'booking_type' => esc_html($booking_type),
                'selected_days' => $days_to_display,
                'discount_info' => $discount_info,
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
    error_log('InterSoccer: Generated roster for variation ID ' . $variation_id . ': ' . count($roster) . ' attendees');
    return $roster;
}
?>
