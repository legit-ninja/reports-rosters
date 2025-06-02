<?php
/**
 * Rosters page functionality for InterSoccer Reports and Rosters plugin.
 */

// Rosters page
function intersoccer_render_rosters_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $filters = [
        'region' => isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '',
        'venue' => isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '',
        'age_group' => isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '',
        'booking_type' => isset($_GET['booking_type']) ? sanitize_text_field($_GET['booking_type']) : '',
        'season' => isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '',
        'city' => isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '',
        'activity_type' => isset($_GET['activity_type']) ? sanitize_text_field($_GET['activity_type']) : '',
        'week' => isset($_GET['week']) ? sanitize_text_field($_GET['week']) : '',
        'show_no_attendees' => isset($_GET['show_no_attendees']) ? sanitize_text_field($_GET['show_no_attendees']) : '',
    ];

    $variations = intersoccer_pe_get_variations_with_players($filters);

    ?>
    <div class="wrap intersoccer-reports-rosters-rosters">
        <h1><?php _e('InterSoccer Rosters', 'intersoccer-reports-rosters'); ?></h1>

        <!-- Filter Form -->
        <form id="roster-filter-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="intersoccer-rosters" />
            <div class="filter-section">
                <h2><?php _e('Filter Rosters', 'intersoccer-reports-rosters'); ?></h2>
                <div class="filter-row">
                    <label for="region"><?php _e('Region:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="region" id="region">
                        <option value=""><?php _e('All Regions', 'intersoccer-reports-rosters'); ?></option>
                        <?php
                        $regions = wc_get_product_terms(null, 'pa_canton-region', ['fields' => 'names']);
                        foreach ($regions as $region) {
                            ?>
                            <option value="<?php echo esc_attr($region); ?>" <?php selected($filters['region'], $region); ?>><?php echo esc_html($region); ?></option>
                            <?php
                        }
                        ?>
                    </select>

                    <label for="venue"><?php _e('Venue:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="venue" id="venue">
                        <option value=""><?php _e('All Venues', 'intersoccer-reports-rosters'); ?></option>
                        <?php
                        $venues = wc_get_product_terms(null, 'pa_intersoccer-venues', ['fields' => 'names']);
                        foreach ($venues as $venue) {
                            ?>
                            <option value="<?php echo esc_attr($venue); ?>" <?php selected($filters['venue'], $venue); ?>><?php echo esc_html($venue); ?></option>
                            <?php
                        }
                        ?>
                    </select>

                    <label for="age_group"><?php _e('Age Group:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="age_group" id="age_group">
                        <option value=""><?php _e('All Age Groups', 'intersoccer-reports-rosters'); ?></option>
                        <?php
                        $age_groups = wc_get_product_terms(null, 'pa_age-group', ['fields' => 'names']);
                        foreach ($age_groups as $age_group) {
                            ?>
                            <option value="<?php echo esc_attr($age_group); ?>" <?php selected($filters['age_group'], $age_group); ?>><?php echo esc_html($age_group); ?></option>
                            <?php
                        }
                        ?>
                    </select>
                </div>

                <div class="filter-row">
                    <label for="booking_type"><?php _e('Booking Type:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="booking_type" id="booking_type">
                        <option value=""><?php _e('All Booking Types', 'intersoccer-reports-rosters'); ?></option>
                        <?php
                        $booking_types = wc_get_product_terms(null, 'pa_booking-type', ['fields' => 'names']);
                        foreach ($booking_types as $booking_type) {
                            ?>
                            <option value="<?php echo esc_attr($booking_type); ?>" <?php selected($filters['booking_type'], $booking_type); ?>><?php echo esc_html($booking_type); ?></option>
                            <?php
                        }
                        ?>
                    </select>

                    <label for="season"><?php _e('Season:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="season" id="season">
                        <option value=""><?php _e('All Seasons', 'intersoccer-reports-rosters'); ?></option>
                        <?php
                        $seasons = wc_get_product_terms(null, 'pa_season', ['fields' => 'names']);
                        foreach ($seasons as $season) {
                            ?>
                            <option value="<?php echo esc_attr($season); ?>" <?php selected($filters['season'], $season); ?>><?php echo esc_html($season); ?></option>
                            <?php
                        }
                        ?>
                    </select>

                    <label for="city"><?php _e('City:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="city" id="city">
                        <option value=""><?php _e('All Cities', 'intersoccer-reports-rosters'); ?></option>
                        <?php
                        $cities = wc_get_product_terms(null, 'pa_city', ['fields' => 'names']);
                        foreach ($cities as $city) {
                            ?>
                            <option value="<?php echo esc_attr($city); ?>" <?php selected($filters['city'], $city); ?>><?php echo esc_html($city); ?></option>
                            <?php
                        }
                        ?>
                    </select>
                </div>

                <div class="filter-row">
                    <label for="activity_type"><?php _e('Activity Type:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="activity_type" id="activity_type">
                        <option value=""><?php _e('All Activity Types', 'intersoccer-reports-rosters'); ?></option>
                        <?php
                        $activity_types = wc_get_product_terms(null, 'pa_activity-type', ['fields' => 'names']);
                        foreach ($activity_types as $activity_type) {
                            ?>
                            <option value="<?php echo esc_attr($activity_type); ?>" <?php selected($filters['activity_type'], $activity_type); ?>><?php echo esc_html($activity_type); ?></option>
                            <?php
                        }
                        ?>
                    </select>

                    <label for="week"><?php _e('Week:', 'intersoccer-reports-rosters'); ?></label>
                    <input type="date" name="week" id="week" value="<?php echo esc_attr($filters['week']); ?>" />

                    <label for="show_no_attendees"><?php _e('Show Events with No Attendees:', 'intersoccer-reports-rosters'); ?></label>
                    <input type="checkbox" name="show_no_attendees" id="show_no_attendees" value="1" <?php checked($filters['show_no_attendees'], '1'); ?> />
                </div>

                <div class="filter-row">
                    <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
                </div>
            </div>
        </form>

        <!-- Rosters Table -->
        <?php if (!empty($variations)): ?>
            <h2><?php _e('Available Rosters', 'intersoccer-reports-rosters'); ?></h2>
            <div class="table-responsive">
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Event Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Booking Type', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Season', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('City', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Activity Type', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($variations as $variation): ?>
                            <tr>
                                <td><?php echo esc_html($variation['product_name']); ?></td>
                                <td><?php echo esc_html($variation['region']); ?></td>
                                <td><?php echo esc_html($variation['venue']); ?></td>
                                <td><?php echo esc_html($variation['age_group']); ?></td>
                                <td><?php echo esc_html($variation['booking_type']); ?></td>
                                <td><?php echo esc_html($variation['season']); ?></td>
                                <td><?php echo esc_html($variation['city']); ?></td>
                                <td><?php echo esc_html($variation['activity_type']); ?></td>
                                <td><?php echo esc_html($variation['total_players']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . $variation['variation_id'])); ?>" class="button"><?php _e('View Roster', 'intersoccer-reports-rosters'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p><?php _e('No rosters found matching the selected filters.', 'intersoccer-reports-rosters'); ?></p>
        <?php endif; ?>
    </div>
    <?php
    error_log('InterSoccer: Rendered Rosters page');
}

// Roster details page
function intersoccer_render_roster_details_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $variation_id = isset($_GET['variation_id']) ? intval($_GET['variation_id']) : 0;
    if (!$variation_id) {
        wp_die(__('Invalid variation ID.', 'intersoccer-reports-rosters'));
    }

    // Check if export is requested
    if (isset($_GET['action']) && $_GET['action'] === 'export' && check_admin_referer('export_roster_nonce')) {
        intersoccer_export_roster_to_csv($variation_id);
        exit; // Ensure no further output after export
    }

    $roster = intersoccer_pe_get_event_roster_by_variation($variation_id);

    ?>
    <div class="wrap intersoccer-reports-rosters-roster-details">
        <h1><?php printf(__('Roster for Variation ID %d', 'intersoccer-reports-rosters'), $variation_id); ?></h1>

        <!-- Export Button -->
        <div class="export-section">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . $variation_id . '&action=export'), 'export_roster_nonce')); ?>" class="button button-primary"><?php _e('Export to CSV', 'intersoccer-reports-rosters'); ?></a>
        </div>

        <?php if (!empty($roster)): ?>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <th><?php _e('Player Name', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Age', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Gender', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Booking Type', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Selected Days', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Parent Country', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Parent State', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Parent City', 'intersoccer-reports-rosters'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roster as $player): ?>
                        <tr>
                            <td><?php echo esc_html($player['player_name']); ?></td>
                            <td><?php echo esc_html($player['age']); ?></td>
                            <td><?php echo esc_html($player['gender']); ?></td>
                            <td><?php echo esc_html($player['booking_type']); ?></td>
                            <td><?php echo esc_html(implode(', ', $player['selected_days'])); ?></td>
                            <td><?php echo esc_html($player['venue']); ?></td>
                            <td><?php echo esc_html($player['region']); ?></td>
                            <td><?php echo esc_html($player['parent_country']); ?></td>
                            <td><?php echo esc_html($player['parent_state']); ?></td>
                            <td><?php echo esc_html($player['parent_city']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p><?php _e('No players found for this event.', 'intersoccer-reports-rosters'); ?></p>
        <?php endif; ?>

        <p><a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-rosters')); ?>" class="button"><?php _e('Back to Rosters', 'intersoccer-reports-rosters'); ?></a></p>
    </div>
    <?php
    error_log('InterSoccer: Rendered Roster Details page for Variation ID ' . $variation_id);
}
?>
