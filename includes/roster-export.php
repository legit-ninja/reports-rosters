<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 */

// Export a single roster to CSV
function intersoccer_export_roster_to_csv($variation_id) {
    // Ensure no output has been sent yet
    if (ob_get_length()) {
        ob_end_clean();
    }

    $variation = wc_get_product($variation_id);
    if (!$variation || $variation->get_type() !== 'variation') {
        wp_die(__('Invalid variation ID.', 'intersoccer-reports-rosters'));
    }

    $product_id = $variation->get_parent_id();
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_die(__('Invalid parent product ID.', 'intersoccer-reports-rosters'));
    }

    $roster = intersoccer_pe_get_event_roster_by_variation($variation_id);

    // Get event details
    $event_name = $product->get_name();
    $region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
    $venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
    $age_group = wc_get_product_terms($product_id, 'pa_age-group', ['fields' => 'names'])[0] ?? 'Unknown';
    $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
    $season_terms = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names']);
    error_log('InterSoccer: Product ID ' . $product_id . ' Season Terms in Export: ' . print_r($season_terms, true));
    $season = !empty($season_terms) ? $season_terms[0] : 'N/A';
    $city = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names'])[0] ?? 'Unknown';
    $activity_type = wc_get_product_terms($product_id, 'pa_activity-type', ['fields' => 'names'])[0] ?? 'Unknown';
    $is_camp = in_array($booking_type, ['Full Week', 'single-days']);
    $camp_term = '';
    if ($is_camp) {
        $camp_term = wc_get_product_terms($product_id, 'pa_week', ['fields' => 'names'])[0] ?? '';
        if (!$camp_term) {
            $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? '';
            if ($start_date) {
                $date = DateTime::createFromFormat('d/m/Y', $start_date);
                if ($date) {
                    $camp_term = 'Week ' . $date->format('W');
                }
            }
        }
    }

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="intersoccer_roster_variation_' . $variation_id . '_' . date('Y-m-d_H-i-s') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Write header row
    $headers = [
        'Event Name',
        'Region',
        'Venue',
        'Age Group',
        'Booking Type',
        'Season',
        'City',
        'Activity Type',
        'Camp Term',
        'Player Name',
        'Age',
        'Gender',
        'Selected Days/Discount Info',
        'Parent Country',
        'Parent State',
        'Parent City',
    ];
    fputcsv($output, $headers);

    // Write roster data with event details in each row
    foreach ($roster as $player) {
        $row = [
            $event_name,
            $region,
            $venue,
            $age_group,
            $booking_type,
            $season,
            $city,
            $activity_type,
            $is_camp ? $camp_term : '', // Camp Term only for Camps
            $player['player_name'],
            $player['age'],
            $player['gender'],
            in_array($player['booking_type'], ['Full Week', 'single-days']) ? implode(', ', $player['selected_days']) : $player['discount_info'],
            $player['parent_country'],
            $player['parent_state'],
            $player['parent_city'],
        ];
        fputcsv($output, $row);
    }

    // Add totals row
    if (!empty($roster)) {
        fputcsv($output, ['Total Players: ' . count($roster)]);
    } else {
        fputcsv($output, ['No players found for this event.']);
    }

    fclose($output);
    exit;
}

// Export all rosters to CSV
function intersoccer_export_all_rosters_to_csv($camps, $courses, $export_type = 'all') {
    // Ensure no output has been sent yet
    if (ob_get_length()) {
        ob_end_clean();
    }

    // Set filename based on export type
    $filename_suffix = 'master_rosters';
    if ($export_type === 'camps') {
        $filename_suffix = 'camp_rosters';
    } elseif ($export_type === 'courses') {
        $filename_suffix = 'course_rosters';
    }
    $filename = "intersoccer_{$filename_suffix}_" . date('Y-m-d_H-i-s') . '.csv';

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Write header row
    $headers = [
        'Event Name',
        'Region',
        'Venue',
        'Age Group',
        'Booking Type',
        'Season',
        'City',
        'Activity Type',
        'Camp Term',
        'Player Name',
        'Age',
        'Gender',
        'Selected Days/Discount Info',
        'Parent Country',
        'Parent State',
        'Parent City',
    ];
    fputcsv($output, $headers);

    // Function to write roster data for a list of variations
    $write_rosters = function($variations, $output) use ($headers) {
        foreach ($variations as $variation) {
            $variation_id = $variation['variation_id'];
            $roster = intersoccer_pe_get_event_roster_by_variation($variation_id);

            // Get Camp Term
            $product_id = wc_get_product($variation_id)->get_parent_id();
            $is_camp = in_array($variation['booking_type'], ['Full Week', 'single-days']);
            $camp_term = '';
            if ($is_camp) {
                $camp_term = wc_get_product_terms($product_id, 'pa_week', ['fields' => 'names'])[0] ?? '';
                if (!$camp_term) {
                    $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? '';
                    if ($start_date) {
                        $date = DateTime::createFromFormat('d/m/Y', $start_date);
                        if ($date) {
                            $camp_term = 'Week ' . $date->format('W');
                        }
                    }
                }
            }

            // Write event-level row
            $event_row = [
                $variation['product_name'],
                $variation['region'],
                $variation['venue'],
                $variation['age_group'],
                $variation['booking_type'],
                $variation['season'],
                $variation['city'],
                $variation['activity_type'],
                $is_camp ? $camp_term : '',
                '', // Player Name
                '', // Age
                '', // Gender
                '', // Selected Days/Discount Info
                '', // Parent Country
                '', // Parent State
                '', // Parent City
            ];
            fputcsv($output, $event_row);

            // Write attendee rows
            foreach ($roster as $player) {
                $attendee_row = [
                    '', // Event Name
                    '', // Region
                    '', // Venue
                    '', // Age Group
                    '', // Booking Type
                    '', // Season
                    '', // City
                    '', // Activity Type
                    '', // Camp Term
                    $player['player_name'],
                    $player['age'],
                    $player['gender'],
                    in_array($player['booking_type'], ['Full Week', 'single-days']) ? implode(', ', $player['selected_days']) : $player['discount_info'],
                    $player['parent_country'],
                    $player['parent_state'],
                    $player['parent_city'],
                ];
                fputcsv($output, $attendee_row);
            }

            // Add totals row
            fputcsv($output, ['Total Players: ' . count($roster)]);

            // Add a blank row for separation
            fputcsv($output, array_fill(0, count($headers), ''));
        }
    };

    // Determine which rosters to export based on type
    $has_data = false;
    if ($export_type === 'all' || $export_type === 'camps') {
        if (!empty($camps)) {
            $write_rosters($camps, $output);
            $has_data = true;
        }
    }
    if ($export_type === 'all' || $export_type === 'courses') {
        if (!empty($courses)) {
            $write_rosters($courses, $output);
            $has_data = true;
        }
    }

    // If no rosters, add a message
    if (!$has_data) {
        fputcsv($output, ['No rosters found matching the selected filters for this export type.']);
    }

    fclose($output);
    exit;
}
?>
