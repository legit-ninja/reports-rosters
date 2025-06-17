<?php
/**
 * Rosters pages for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.3.49
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render the All Rosters page.
 */
function intersoccer_render_all_rosters_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $product_names = $wpdb->get_col("SELECT DISTINCT product_name FROM $rosters_table ORDER BY product_name");
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
                            "SELECT variation_id, product_name, venue, age_group, COUNT(*) as total_players
                             FROM $rosters_table
                             GROUP BY variation_id, product_name, venue, age_group
                             ORDER BY product_name, venue, age_group",
                            $product_name
                        ),
                        ARRAY_A
                    );
                    if (!empty($groups)) {
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html($product_name) . ' (' . array_sum(array_column($groups, 'total_players')) . ' players)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Age Group', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($groups as $group) {
                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . urlencode($group['variation_id']));
                            echo '<tr>';
                            echo '<td>' . esc_html(intersoccer_get_term_name($group['venue'], 'pa_intersoccer-venues')) . '</td>';
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

    $camp_terms_list = $wpdb->get_col("SELECT DISTINCT camp_terms FROM $rosters_table WHERE FIND_IN_SET('Camp', activity_type) > 0 ORDER BY camp_terms");
    error_log('InterSoccer: Retrieved ' . count($camp_terms_list) . ' unique camp terms for Camps on ' . current_time('mysql'));

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('Camps', 'intersoccer-reports-rosters'); ?></h1>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-camps&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        <?php if (empty($camp_terms_list)) : ?>
            <p><?php _e('No camp rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="camps">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_camps" class="button button-primary" value="<?php _e('Export All Camp Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                foreach ($camp_terms_list as $camp_terms) {
                    $camp_terms_name = intersoccer_get_term_name($camp_terms, 'pa_camp-terms');
                    $groups = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT variation_id, product_name, venue, age_group, camp_terms, COUNT(*) as total_players
                             FROM $rosters_table
                             WHERE FIND_IN_SET('Camp', activity_type) > 0 AND camp_terms = %s
                             GROUP BY variation_id, product_name, venue, age_group, camp_terms
                             ORDER BY product_name, venue",
                            $camp_terms
                        ),
                        ARRAY_A
                    );
                    if (!empty($groups)) {
                        echo '<div class="roster-group">';
                        echo '<h2>' . esc_html($camp_terms_name) . ' (' . array_sum(array_column($groups, 'total_players')) . ' players)</h2>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th style="width:30%">' . __('Product Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:30%">' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:20%">' . __('Age Group', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($groups as $group) {
                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . urlencode($group['variation_id']));
                            echo '<tr>';
                            echo '<td>' . esc_html($group['product_name']) . '</td>';
                            echo '<td>' . esc_html(intersoccer_get_term_name($group['venue'], 'pa_intersoccer-venues')) . '</td>';
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

    $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE FIND_IN_SET('Course', activity_type) > 0 ORDER BY updated_at DESC");
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' course rosters for display on ' . current_time('mysql'));

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('Courses', 'intersoccer-reports-rosters'); ?></h1>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-courses&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No course rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="courses">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_courses" class="button button-primary" value="<?php _e('Export All Course Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                $venues = [];
                foreach ($rosters as $roster) {
                    $venues[$roster->venue][] = $roster;
                }
                ksort($venues);
                foreach ($venues as $venue => $venue_rosters) {
                    if ($venue && $venue !== 'Unknown Venue') {
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html(intersoccer_get_term_name($venue, 'pa_intersoccer-venues')) . ' (' . count($venue_rosters) . ' players)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Event Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        echo '<tr>';
                        echo '<td>' . esc_html($venue_rosters[0]->product_name) . '</td>';
                        echo '<td>' . esc_html(intersoccer_get_term_name($venue, 'pa_intersoccer-venues')) . '</td>';
                        echo '<td>' . esc_html(count($venue_rosters)) . '</td>';
                        echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . $venue_rosters[0]->variation_id)) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
                        echo '</tr>';
                        echo '</tbody></table>';
                        echo '</div>';
                    }
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

    $rosters = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $rosters_table WHERE activity_type LIKE %s OR activity_type LIKE %s ORDER BY updated_at DESC",
            '%' . $wpdb->esc_like('Girls Only') . '%',
            '%' . $wpdb->esc_like('Girls') . '%'
        ),
        ARRAY_A
    );
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' Girls Only rosters on ' . current_time('mysql'));

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('Girls Only', 'intersoccer-reports-rosters'); ?></h1>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-girls-only&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No Girls Only rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="girls_only">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_girls_only" class="button button-primary" value="<?php _e('Export All Girls Only Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                $variations = [];
                foreach ($rosters as $roster) {
                    $variations[$roster['variation_id']][] = $roster;
                }
                foreach ($variations as $variation_id => $variation_rosters) {
                    if (!empty($variation_rosters)) {
                        $first_roster = $variation_rosters[0];
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html($first_roster['product_name']) . ' - ' . esc_html(intersoccer_get_term_name($first_roster['venue'], 'pa_intersoccer-venues')) . ' (' . count($variation_rosters) . ' players)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Event Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        echo '<tr>';
                        echo '<td>' . esc_html($first_roster['product_name']) . '</td>';
                        echo '<td>' . esc_html(intersoccer_get_term_name($first_roster['venue'], 'pa_intersoccer-venues')) . '</td>';
                        echo '<td>' . esc_html(count($variation_rosters)) . '</td>';
                        echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . $variation_id)) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
                        echo '</tr>';
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the Other Events page.
 */
function intersoccer_render_other_events_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE FIND_IN_SET('Event', activity_type) > 0 OR FIND_IN_SET('Other', activity_type) > 0 ORDER BY updated_at DESC");
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' other event rosters for display on ' . current_time('mysql'));

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('Other Events', 'intersoccer-reports-rosters'); ?></h1>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-other-events&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No other event rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="other">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_other" class="button button-primary" value="<?php _e('Export All Other Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                $venues = [];
                foreach ($rosters as $roster) {
                    $venues[$roster->venue][] = $roster;
                }
                ksort($venues);
                foreach ($venues as $venue => $venue_rosters) {
                    if ($venue && $venue !== 'Unknown Venue') {
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html(intersoccer_get_term_name($venue, 'pa_intersoccer-venues')) . ' (' . count($venue_rosters) . ' players)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Event Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        echo '<tr>';
                        echo '<td>' . esc_html($venue_rosters[0]->product_name) . '</td>';
                        echo '<td>' . esc_html(intersoccer_get_term_name($venue, 'pa_intersoccer-venues')) . '</td>';
                        echo '<td>' . esc_html(count($venue_rosters)) . '</td>';
                        echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . $venue_rosters[0]->variation_id)) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
                        echo '</tr>';
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the Reports page (placeholder - to be implemented).
 */
function intersoccer_render_reports_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }
    ?>
    <div class="wrap">
        <h1><?php _e('InterSoccer Reports', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php _e('Reports functionality to be implemented.', 'intersoccer-reports-rosters'); ?></p>
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