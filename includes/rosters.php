<?php
/**
 * Rosters pages for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.04
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Include utility functions
require_once plugin_dir_path(__FILE__) . 'utils.php';

/**
 * Add inline CSS for side-by-side export buttons
 */
add_action('admin_head', function () {
    echo '<style>
        .export-buttons { display: flex; gap: 10px; }
        .export-buttons form { display: inline-block; }
    </style>';
});

/**
 * Render the All Rosters page.
 */
function intersoccer_render_all_rosters_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    error_log('InterSoccer: Database prefix: ' . $wpdb->prefix);

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    $product_names = $wpdb->get_col("SELECT DISTINCT product_name FROM $rosters_table WHERE product_name IS NOT NULL ORDER BY product_name");
    error_log('InterSoccer: Retrieved ' . count($product_names) . ' unique product names for All Rosters on ' . current_time('mysql'));

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('All Rosters', 'intersoccer-reports-rosters'); ?></h1>
        <?php if (empty($product_names)) : ?>
            <p><?php _e('No rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="all">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_all" class="button button-primary" value="<?php _e('Export All Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                foreach ($product_names as $product_name) {
                    $groups = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT variation_id, product_name, venue, age_group, COUNT(DISTINCT order_item_id) as total_players
                             FROM $rosters_table
                             WHERE product_name = %s
                             GROUP BY variation_id, product_name, venue, age_group
                             ORDER BY product_name, venue, age_group",
                            $product_name
                        ),
                        ARRAY_A
                    );
                    error_log('InterSoccer: All rosters query: ' . $wpdb->last_query);
                    error_log('InterSoccer: All rosters results: ' . json_encode($groups));
                    if (!empty($groups)) {
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html($product_name) . ' (' . array_sum(array_column($groups, 'total_players')) . ' players)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th style="width:22.5%">' . __('Product Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:22.5%">' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:12.5%">' . __('Age Group', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($groups as $group) {
                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . urlencode($group['variation_id']) . '&age_group=' . urlencode($group['age_group']));
                            echo '<tr>';
                            echo '<td>' . esc_html(intersoccer_get_term_name($group['product_name'], 'product')) . '</td>';
                            echo '<td>' . esc_html(intersoccer_get_term_name($group['venue'] ?: 'N/A', 'pa_intersoccer-venues')) . '</td>';
                            echo '<td>' . esc_html(intersoccer_get_term_name($group['age_group'], 'pa_age-group')) . '</td>';
                            echo '<td>' . esc_html($group['total_players']) . '</td>';
                            echo '<td><a href="' . esc_url($view_url) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        <?php endif; ?>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-all-rosters&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
    </div>
    <?php
}

/**
 * Render the Camps page.
 */
function intersoccer_render_camps_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    // Fetch unique camp terms for the filter
    $camp_terms_list = $wpdb->get_col("SELECT DISTINCT COALESCE(camp_terms, 'N/A') FROM $rosters_table WHERE activity_type = 'Camp' ORDER BY COALESCE(camp_terms, 'N/A')");

    // Get the selected camp term from the request, default to empty
    $selected_camp_term = isset($_GET['camp_term']) ? sanitize_text_field($_GET['camp_term']) : '';

    // Build the query to fetch camp rosters grouped by camp_terms, venue, and age_group
    $base_query = "SELECT COALESCE(camp_terms, 'N/A') as camp_terms, 
                          COALESCE(venue, 'N/A') as venue, 
                          age_group, 
                          COUNT(DISTINCT order_item_id) as total_players,
                          GROUP_CONCAT(DISTINCT variation_id) as variation_ids
                   FROM $rosters_table
                   WHERE activity_type = 'Camp'";
    if ($selected_camp_term) {
        $base_query .= $wpdb->prepare(" AND COALESCE(camp_terms, 'N/A') = %s", $selected_camp_term);
    }
    $base_query .= " GROUP BY camp_terms, venue, age_group ORDER BY camp_terms, venue, age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    error_log('InterSoccer: Camps query: ' . $wpdb->last_query);
    error_log('InterSoccer: Camps results count: ' . count($groups));
    if ($groups) {
        error_log('InterSoccer: Camps data sample: ' . json_encode(array_slice($groups, 0, 5)));
        // Log detailed player data for the specific event
        if ($selected_camp_term === 'summer-week-3-july-7-11-5-days') {
            $player_query = $wpdb->prepare(
                "SELECT order_item_id, player_name, activity_type, booking_type, day_presence
                 FROM $rosters_table
                 WHERE activity_type = 'Camp'
                 AND camp_terms = %s
                 AND venue = %s
                 AND age_group = %s",
                'summer-week-3-july-7-11-5-days',
                'nyon-centre-sportif-colovray',
                '5-13y-full-day'
            );
            $players = $wpdb->get_results($player_query, ARRAY_A);
            error_log('InterSoccer: Camps player details for summer-week-3-july-7-11-5-days: ' . json_encode($players));
        }
    }

    // Log excluded Girls Only records for verification
    if ($selected_camp_term === 'summer-week-3-july-7-11-5-days') {
        $girls_only_query = $wpdb->prepare(
            "SELECT order_item_id, player_name, activity_type, booking_type, day_presence
             FROM $rosters_table
             WHERE activity_type IN ('Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only')
             AND camp_terms = %s
             AND venue = %s
             AND age_group = %s",
            'summer-week-3-july-7-11-5-days',
            'nyon-centre-sportif-colovray',
            '5-13y-full-day'
        );
        $girls_only_players = $wpdb->get_results($girls_only_query, ARRAY_A);
        error_log('InterSoccer: Excluded Girls Only players for summer-week-3-july-7-11-5-days: ' . json_encode($girls_only_players));
    }

    // Group the results by camp term
    $grouped_by_term = [];
    foreach ($groups as $group) {
        $term = $group['camp_terms'];
        if (!isset($grouped_by_term[$term])) {
            $grouped_by_term[$term] = [];
        }
        $grouped_by_term[$term][] = $group;
    }

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('Camps', 'intersoccer-reports-rosters'); ?></h1>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-camps&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        <?php if (empty($camp_terms_list)) : ?>
            <p><?php _e('No camp rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <!-- Camp Term Filter -->
            <form method="get" action="" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="intersoccer-camps">
                <label for="camp_term"><?php _e('Filter by Camp Term:', 'intersoccer-reports-rosters'); ?></label>
                <select name="camp_term" id="camp_term" onchange="this.form.submit()">
                    <option value=""><?php _e('All Camp Terms', 'intersoccer-reports-rosters'); ?></option>
                    <?php foreach ($camp_terms_list as $camp_term) : ?>
                        <option value="<?php echo esc_attr($camp_term); ?>" <?php selected($selected_camp_term, $camp_term); ?>>
                            <?php echo esc_html($camp_term); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="camps">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_camps" class="button button-primary" value="<?php _e('Export All Camp Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>

            <div class="roster-groups">
                <?php foreach ($grouped_by_term as $term => $term_groups) : ?>
                    <?php
                    $term_total_players = array_sum(array_column($term_groups, 'total_players'));
                    ?>
                    <div class="camp-term-group">
                        <h2><?php echo esc_html(intersoccer_get_term_name($term, 'pa_camp-terms')) . ' (' . esc_html($term_total_players) . ' players)'; ?></h2>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                                    <th><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></th>
                                    <th><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                                    <th><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($term_groups as $group) : ?>
                                    <?php
                                    $view_url = admin_url('admin.php?page=intersoccer-roster-details&camp_terms=' . urlencode($term) . '&venue=' . urlencode($group['venue']) . '&age_group=' . urlencode($group['age_group']));
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html(intersoccer_get_term_name($group['venue'], 'pa_intersoccer-venues')); ?></td>
                                        <td><?php echo esc_html(intersoccer_get_term_name($group['age_group'], 'pa_age-group')); ?></td>
                                        <td><?php echo esc_html($group['total_players']); ?></td>
                                        <td><a href="<?php echo esc_url($view_url); ?>" class="button"><?php _e('View Roster', 'intersoccer-reports-rosters'); ?></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($grouped_by_term)) : ?>
                    <p><?php _e('No camp rosters available for the selected term.', 'intersoccer-reports-rosters'); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the Courses page.
 */
function intersoccer_render_courses_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    error_log('InterSoccer: Database prefix: ' . $wpdb->prefix);

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    // Fetch unique course days for the filter
    $course_days_list = $wpdb->get_col("SELECT DISTINCT course_day FROM $rosters_table WHERE activity_type = 'Course' AND course_day != 'N/A' ORDER BY course_day");
    error_log('InterSoccer: Retrieved ' . count($course_days_list) . ' unique course days for Courses filter on ' . current_time('mysql'));

    // Get the selected course day from the request, default to empty
    $selected_course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';

    // Build separate queries for full-day and half-day courses
    $base_query_full_day = "SELECT COALESCE(venue, 'N/A') as venue, course_day, age_group, 
                           COUNT(DISTINCT order_item_id) as total_players,
                           SUM(CASE WHEN player_name = 'Unknown Attendee' THEN 1 ELSE 0 END) as unknown_count,
                           GROUP_CONCAT(DISTINCT variation_id) as variation_ids
                           FROM $rosters_table
                           WHERE activity_type = 'Course'
                           AND (age_group LIKE '%Full Day%' OR age_group LIKE '%full-day%' OR age_group = '5-13y-full-day')";
    $base_query_half_day = "SELECT COALESCE(venue, 'N/A') as venue, course_day, age_group, 
                           COUNT(DISTINCT order_item_id) as total_players,
                           SUM(CASE WHEN player_name = 'Unknown Attendee' THEN 1 ELSE 0 END) as unknown_count,
                           GROUP_CONCAT(DISTINCT variation_id) as variation_ids
                           FROM $rosters_table
                           WHERE activity_type = 'Course'
                           AND (age_group LIKE '%Half-Day%' OR age_group LIKE '%half-day%' OR age_group = '3-5y-half-day')";

    $query_full_day = $base_query_full_day;
    $query_half_day = $base_query_half_day;
    if ($selected_course_day) {
        $query_full_day .= $wpdb->prepare(" AND course_day = %s", $selected_course_day);
        $query_half_day .= $wpdb->prepare(" AND course_day = %s", $selected_course_day);
    }
    $query_full_day .= " GROUP BY venue, course_day, age_group ORDER BY course_day, venue, age_group";
    $query_half_day .= " GROUP BY venue, course_day, age_group ORDER BY course_day, venue, age_group";

    $full_day_groups = $wpdb->get_results($query_full_day, ARRAY_A);
    $half_day_groups = $wpdb->get_results($query_half_day, ARRAY_A);
    error_log('InterSoccer: Courses full-day query: ' . $wpdb->last_query);
    error_log('InterSoccer: Courses full-day groups: ' . json_encode($full_day_groups));
    error_log('InterSoccer: Courses half-day query: ' . $wpdb->last_query);
    error_log('InterSoccer: Courses half-day groups: ' . json_encode($half_day_groups));
    error_log('InterSoccer: Last SQL error: ' . $wpdb->last_error);

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('Courses', 'intersoccer-reports-rosters'); ?></h1>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-courses&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        <?php if (empty($course_days_list)) : ?>
            <p><?php _e('No course rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <!-- Course Day Filter -->
            <form method="get" action="" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="intersoccer-courses">
                <label for="course_day"><?php _e('Filter by Course Day:', 'intersoccer-reports-rosters'); ?></label>
                <select name="course_day" id="course_day" onchange="this.form.submit()">
                    <option value=""><?php _e('All Course Days', 'intersoccer-reports-rosters'); ?></option>
                    <?php foreach ($course_days_list as $course_day) : ?>
                        <option value="<?php echo esc_attr($course_day); ?>" <?php selected($selected_course_day, $course_day); ?>>
                            <?php echo esc_html($course_day); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="courses_full_day">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_courses_full_day" class="button button-primary" value="<?php _e('Export Full-Day Course Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="courses_half_day">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_courses_half_day" class="button button-primary" value="<?php _e('Export Half-Day Course Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>

            <!-- Full-Day Courses -->
            <div class="roster-groups">
                <h2><?php _e('Full-Day Courses', 'intersoccer-reports-rosters'); ?></h2>
                <?php
                if (!empty($full_day_groups)) {
                    foreach ($full_day_groups as $group) {
                        $key = $group['course_day'] . '|' . $group['venue'] . '|' . $group['age_group'];
                        $total_players = $group['total_players'] ?? 0;
                        $unknown_count = $group['unknown_count'] ?? 0;
                        $variation_ids = explode(',', $group['variation_ids'] ?? '');
                        error_log('InterSoccer: Rendering full-day course group for key ' . $key . ' - total_players: ' . $total_players . ', unknown_count: ' . $unknown_count . ', variation_ids: ' . implode(', ', $variation_ids));
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html($group['course_day']) . ' - ' . esc_html(intersoccer_get_term_name($group['venue'], 'pa_intersoccer-venues')) . ' - ' . esc_html($group['age_group']) . ' (' . $total_players . ' players)</h3>';
                        if ($unknown_count > 0) {
                            echo '<p style="color: red;">' . esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)) . '</p>';
                        }
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th style="width:22.5%">' . __('Player Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:15%">' . __('First Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:15%">' . __('Last Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Age', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Gender', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Medical Conditions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        $variation_groups = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT variation_id, player_name, first_name, last_name, age, gender, medical_conditions, parent_phone, parent_email, booking_type
                                 FROM $rosters_table
                                 WHERE activity_type = 'Course' AND course_day = %s AND (venue = %s OR (venue IS NULL AND %s = 'N/A')) AND (age_group LIKE '%Full Day%' OR age_group LIKE '%full-day%' OR age_group = '5-13y-full-day')",
                                $group['course_day'],
                                $group['venue'] === 'N/A' ? '' : $group['venue'],
                                $group['venue']
                            ),
                            ARRAY_A
                        );
                        error_log('InterSoccer: Courses full-day variation groups query: ' . $wpdb->last_query);
                        error_log('InterSoccer: Courses full-day variation groups results: ' . json_encode($variation_groups));
                        if (!empty($variation_groups)) {
                            foreach ($variation_groups as $v_group) {
                                echo '<tr>';
                                echo '<td>' . esc_html($v_group['player_name']) . '</td>';
                                echo '<td>' . esc_html($v_group['first_name']) . '</td>';
                                echo '<td>' . esc_html($v_group['last_name']) . '</td>';
                                echo '<td>' . esc_html($v_group['age']) . '</td>';
                                echo '<td>' . esc_html($v_group['gender']) . '</td>';
                                echo '<td>' . esc_html($v_group['medical_conditions']) . '</td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="6">' . __('No roster details available.', 'intersoccer-reports-rosters') . '</td></tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                } else {
                    echo '<p>' . __('No full-day course rosters available.', 'intersoccer-reports-rosters') . '</p>';
                }
                ?>
            </div>

            <!-- Half-Day Courses -->
            <div class="roster-groups">
                <h2><?php _e('Half-Day Courses', 'intersoccer-reports-rosters'); ?></h2>
                <?php
                if (!empty($half_day_groups)) {
                    foreach ($half_day_groups as $group) {
                        $key = $group['course_day'] . '|' . $group['venue'] . '|' . $group['age_group'];
                        $total_players = $group['total_players'] ?? 0;
                        $unknown_count = $group['unknown_count'] ?? 0;
                        $variation_ids = explode(',', $group['variation_ids'] ?? '');
                        error_log('InterSoccer: Rendering half-day course group for key ' . $key . ' - total_players: ' . $total_players . ', unknown_count: ' . $unknown_count . ', variation_ids: ' . implode(', ', $variation_ids));
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html($group['course_day']) . ' - ' . esc_html(intersoccer_get_term_name($group['venue'], 'pa_intersoccer-venues')) . ' - ' . esc_html($group['age_group']) . ' (' . $total_players . ' players)</h3>';
                        if ($unknown_count > 0) {
                            echo '<p style="color: red;">' . esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)) . '</p>';
                        }
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th style="width:22.5%">' . __('Player Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:15%">' . __('First Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:15%">' . __('Last Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Age', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Gender', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Medical Conditions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        $variation_groups = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT variation_id, player_name, first_name, last_name, age, gender, medical_conditions, parent_phone, parent_email, booking_type
                                 FROM $rosters_table
                                 WHERE activity_type = 'Course' AND course_day = %s AND (venue = %s OR (venue IS NULL AND %s = 'N/A')) AND (age_group LIKE '%Half-Day%' OR age_group LIKE '%half-day%' OR age_group = '3-5y-half-day')",
                                $group['course_day'],
                                $group['venue'] === 'N/A' ? '' : $group['venue'],
                                $group['venue']
                            ),
                            ARRAY_A
                        );
                        error_log('InterSoccer: Courses half-day variation groups query: ' . $wpdb->last_query);
                        error_log('InterSoccer: Courses half-day variation groups results: ' . json_encode($variation_groups));
                        if (!empty($variation_groups)) {
                            foreach ($variation_groups as $v_group) {
                                echo '<tr>';
                                echo '<td>' . esc_html($v_group['player_name']) . '</td>';
                                echo '<td>' . esc_html($v_group['first_name']) . '</td>';
                                echo '<td>' . esc_html($v_group['last_name']) . '</td>';
                                echo '<td>' . esc_html($v_group['age']) . '</td>';
                                echo '<td>' . esc_html($v_group['gender']) . '</td>';
                                echo '<td>' . esc_html($v_group['medical_conditions']) . '</td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="6">' . __('No roster details available.', 'intersoccer-reports-rosters') . '</td></tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                } else {
                    echo '<p>' . __('No half-day course rosters available.', 'intersoccer-reports-rosters') . '</p>';
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the Girls Only page.
 */
function intersoccer_render_girls_only_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';
    error_log('InterSoccer: Database prefix: ' . $wpdb->prefix);

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    // Fetch unique parent product IDs for Girls Only rosters
    $parent_product_ids = $wpdb->get_results(
        "SELECT DISTINCT p.post_parent as parent_id, parent.post_title as product_name
         FROM $rosters_table r
         JOIN $posts_table p ON r.variation_id = p.ID
         JOIN $posts_table parent ON p.post_parent = parent.ID
         WHERE r.activity_type IN ('Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only')
         ORDER BY parent.post_title",
        ARRAY_A
    );
    error_log('InterSoccer: Retrieved ' . count($parent_product_ids) . ' unique parent product IDs for Girls Only on ' . current_time('mysql'));

    // Get the selected product ID from the request, default to empty
    $selected_product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

    // Build the query for Girls Only rosters
    $base_query = "SELECT r.variation_id, r.product_name, r.venue, r.age_group, r.camp_terms, 
                          COUNT(DISTINCT r.order_item_id) as total_players,
                          SUM(CASE WHEN r.player_name = 'Unknown Attendee' THEN 1 ELSE 0 END) as unknown_count,
                          p.post_parent as parent_id,
                          parent.post_title as parent_product_name
                   FROM $rosters_table r
                   JOIN $posts_table p ON r.variation_id = p.ID
                   JOIN $posts_table parent ON p.post_parent = parent.ID
                   WHERE r.activity_type IN ('Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only')";
    if ($selected_product_id) {
        $base_query .= $wpdb->prepare(" AND p.post_parent = %d", $selected_product_id);
    }
    $base_query .= " GROUP BY r.variation_id, r.product_name, r.venue, r.age_group, r.camp_terms, p.post_parent, parent.post_title
                    ORDER BY parent.post_title, r.camp_terms, r.venue, r.age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    error_log('InterSoccer: Girls Only query: ' . $wpdb->last_query);
    error_log('InterSoccer: Girls Only results: ' . json_encode($groups));
    error_log('InterSoccer: Last SQL error: ' . $wpdb->last_error);

    // Group by parent product ID
    $grouped_by_product = [];
    foreach ($groups as $group) {
        $parent_id = $group['parent_id'];
        if (!isset($grouped_by_product[$parent_id])) {
            $grouped_by_product[$parent_id] = [
                'product_name' => $group['parent_product_name'],
                'rosters' => []
            ];
        }
        $grouped_by_product[$parent_id]['rosters'][] = $group;
    }

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('Girls Only', 'intersoccer-reports-rosters'); ?></h1>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-girls-only&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        <?php if (empty($parent_product_ids)) : ?>
            <p><?php _e('No Girls Only rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <!-- Product ID Filter -->
            <form method="get" action="" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="intersoccer-girls-only">
                <label for="product_id"><?php _e('Filter by Product:', 'intersoccer-reports-rosters'); ?></label>
                <select name="product_id" id="product_id" onchange="this.form.submit()">
                    <option value=""><?php _e('All Products', 'intersoccer-reports-rosters'); ?></option>
                    <?php foreach ($parent_product_ids as $product) : ?>
                        <option value="<?php echo esc_attr($product['parent_id']); ?>" <?php selected($selected_product_id, $product['parent_id']); ?>>
                            <?php echo esc_html($product['product_name'] . ' (ID: ' . $product['parent_id'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="girls_only_full_day">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_girls_only_full_day" class="button button-primary" value="<?php _e('Export Full-Day Girls Only Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="girls_only_half_day">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_girls_only_half_day" class="button button-primary" value="<?php _e('Export Half-Day Girls Only Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>

            <div class="roster-groups">
                <?php foreach ($grouped_by_product as $parent_id => $product_data) : ?>
                    <?php
                    $total_players = array_sum(array_column($product_data['rosters'], 'total_players'));
                    ?>
                    <div class="product-group">
                        <h2><?php echo esc_html($product_data['product_name']) . ' (ID: ' . esc_html($parent_id) . ', ' . esc_html($total_players) . ' players)'; ?></h2>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                                    <th><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></th>
                                    <th><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                                    <th><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($product_data['rosters'] as $roster) : ?>
                                    <?php
                                    $unknown_count = $roster['unknown_count'] ?? 0;
                                    $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . urlencode($roster['variation_id']) . '&age_group=' . urlencode($roster['age_group']));
                                    error_log('InterSoccer: Girls Only View Roster URL for variation ' . $roster['variation_id'] . ': ' . $view_url);
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html(intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues')); ?></td>
                                        <td><?php echo esc_html(intersoccer_get_term_name($roster['age_group'], 'pa_age-group')); ?></td>
                                        <td><?php echo esc_html($roster['total_players']); ?></td>
                                        <td><a href="<?php echo esc_url($view_url); ?>" class="button"><?php _e('View Roster', 'intersoccer-reports-rosters'); ?></a></td>
                                    </tr>
                                    <?php if ($unknown_count > 0) : ?>
                                        <tr>
                                            <td colspan="4" style="color: red;">
                                                <?php echo esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)); ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($grouped_by_product)) : ?>
                    <p><?php _e('No Girls Only rosters available.', 'intersoccer-reports-rosters'); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * AJAX handler for reconcile
 */
add_action('wp_ajax_intersoccer_reconcile_rosters', 'intersoccer_reconcile_rosters_ajax');
function intersoccer_reconcile_rosters_ajax() {
    check_ajax_referer('intersoccer_reconcile', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_send_json_error(__('You do not have permission to reconcile rosters.', 'intersoccer-reports-rosters'));
    }
    $result = intersoccer_rebuild_rosters_and_reports();
    if ($result['status'] === 'success') {
        wp_send_json_success(__('Rosters reconciled successfully. Inserted ' . $result['inserted'] . ' records.', 'intersoccer-reports-rosters'));
    } else {
        wp_send_json_error(__('Roster reconciliation failed: ' . $result['message'], 'intersoccer-reports-rosters'));
    }
}
?>