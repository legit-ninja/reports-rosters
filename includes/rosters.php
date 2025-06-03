<?php
/**
 * Rosters page functionality for InterSoccer Reports and Rosters plugin.
 */

// Rosters page
function intersoccer_render_rosters_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $current_date = new DateTime('2025-06-02');
    $current_date_str = $current_date->format('Y-m-d');

    $filters = [
        'region' => isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '',
        'venue' => isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '',
        'age_group' => isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '',
        'booking_type' => isset($_GET['booking_type']) ? sanitize_text_field($_GET['booking_type']) : '',
        'season' => isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '',
        'city' => isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '',
        'activity_type' => isset($_GET['activity_type']) ? sanitize_text_field($_GET['activity_type']) : '',
        'week' => isset($_GET['week']) ? sanitize_text_field($_GET['week']) : $current_date_str, // Default to today
        'show_no_attendees' => isset($_GET['show_no_attendees']) ? sanitize_text_field($_GET['show_no_attendees']) : '',
    ];

    $camps = intersoccer_pe_get_camp_variations($filters);
    $courses = intersoccer_pe_get_course_variations($filters);

    // Check if export is requested
    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'export_all_rosters' && check_admin_referer('export_all_rosters_nonce')) {
            intersoccer_export_all_rosters_to_csv($camps, $courses, 'all');
        } elseif ($_GET['action'] === 'export_camp_rosters' && check_admin_referer('export_camp_rosters_nonce')) {
            intersoccer_export_all_rosters_to_csv($camps, [], 'camps');
        } elseif ($_GET['action'] === 'export_course_rosters' && check_admin_referer('export_course_rosters_nonce')) {
            intersoccer_export_all_rosters_to_csv([], $courses, 'courses');
        }
    }

    // Build query string for export links to preserve filters
    $query_string = http_build_query(array_filter($filters, function($value) {
        return !empty($value);
    }));

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
                </div>

                <div class="filter-row">
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

                    <label for="week"><?php _e('Date:', 'intersoccer-reports-rosters'); ?></label>
                    <input type="date" name="week" id="week" value="<?php echo esc_attr($filters['week']); ?>" />

                    <label for="show_no_attendees"><?php _e('Show Events with No Attendees:', 'intersoccer-reports-rosters'); ?></label>
                    <input type="checkbox" name="show_no_attendees" id="show_no_attendees" value="1" <?php checked($filters['show_no_attendees'], '1'); ?> />
                </div>

                <div class="filter-row">
                    <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
                </div>
            </div>
        </form>

        <!-- Export Buttons -->
        <div class="export-section">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-rosters&action=export_all_rosters' . ($query_string ? '&' . $query_string : '')), 'export_all_rosters_nonce')); ?>" class="button button-primary"><?php _e('Export All Rosters', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-rosters&action=export_camp_rosters' . ($query_string ? '&' . $query_string : '')), 'export_camp_rosters_nonce')); ?>" class="button button-primary"><?php _e('Export All Camp Rosters', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-rosters&action=export_course_rosters' . ($query_string ? '&' . $query_string : '')), 'export_course_rosters_nonce')); ?>" class="button button-primary"><?php _e('Export All Course Rosters', 'intersoccer-reports-rosters'); ?></a>
        </div>

        <!-- Courses Rosters (Ongoing) -->
        <h2><?php _e('Ongoing Courses Rosters', 'intersoccer-reports-rosters'); ?></h2>
        <?php if (!empty($courses)): ?>
            <div class="table-responsive">
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Event Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $variation): ?>
                            <tr>
                                <td><?php echo esc_html($variation['product_name']); ?></td>
                                <td><?php echo esc_html($variation['region']); ?></td>
                                <td><?php echo esc_html($variation['venue']); ?></td>
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
            <p><?php _e('No ongoing course rosters found matching the selected filters.', 'intersoccer-reports-rosters'); ?></p>
        <?php endif; ?>

        <!-- Camps Rosters (Upcoming) -->
        <h2><?php _e('Upcoming Camps Rosters', 'intersoccer-reports-rosters'); ?></h2>
        <?php if (!empty($camps)): ?>
            <div class="table-responsive">
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Event Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($camps as $variation): ?>
                            <tr>
                                <td><?php echo esc_html($variation['product_name']); ?></td>
                                <td><?php echo esc_html($variation['region']); ?></td>
                                <td><?php echo esc_html($variation['venue']); ?></td>
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
            <p><?php _e('No upcoming camp rosters found matching the selected filters.', 'intersoccer-reports-rosters'); ?></p>
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

    // Determine if this is a Camp or Course
    $is_camp = !empty($roster) && in_array($roster[0]['booking_type'], ['Full Week', 'single-days']);

    // Get event details for display
    $variation = wc_get_product($variation_id);
    $product_id = $variation ? $variation->get_parent_id() : 0;
    $product = $product_id ? wc_get_product($product_id) : null;
    $event_name = $product ? $product->get_name() : 'Unknown Event';
    $region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
    $venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
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

    ?>
    <div class="wrap intersoccer-reports-rosters-roster-details">
        <h1><?php printf(__('Roster for %s (Variation ID %d)', 'intersoccer-reports-rosters'), esc_html($event_name), $variation_id); ?></h1>
        <p>
            <strong><?php _e('Region:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html($region); ?><br>
            <strong><?php _e('Venue:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html($venue); ?>
            <?php if ($is_camp && $camp_term): ?>
                <br><strong><?php _e('Camp Term:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html($camp_term); ?>
            <?php endif; ?>
        </p>

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
                        <?php if ($is_camp): ?>
                            <th><?php _e('Selected Days', 'intersoccer-reports-rosters'); ?></th>
                        <?php else: ?>
                            <th><?php _e('Discount Info', 'intersoccer-reports-rosters'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roster as $player): ?>
                        <tr>
                            <td><?php echo esc_html($player['player_name']); ?></td>
                            <td><?php echo esc_html($player['age']); ?></td>
                            <td><?php echo esc_html($player['gender']); ?></td>
                            <?php if ($is_camp): ?>
                                <td><?php echo esc_html(implode(', ', $player['selected_days'])); ?></td>
                            <?php else: ?>
                                <td><?php echo esc_html($player['discount_info']); ?></td>
                            <?php endif; ?>
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
