<?php
/**
 * Rosters pages for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.13
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

    // Handle manual actions
    if (isset($_GET['action']) && $_GET['action'] === 'reconcile' && check_admin_referer('intersoccer_reconcile')) {
        intersoccer_reconcile_rosters();
        echo '<div class="notice notice-success"><p>' . __('Rosters reconciled successfully.', 'intersoccer-reports-rosters') . '</p></div>';
    } elseif (isset($_GET['action']) && $_GET['action'] === 'rebuild' && check_admin_referer('intersoccer_rebuild')) {
        intersoccer_rebuild_rosters();
        echo '<div class="notice notice-success"><p>' . __('Rosters rebuilt successfully.', 'intersoccer-reports-rosters') . '</p></div>';
    }

    // Fetch and display roster data (read-only)
    $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC");
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' rosters for display on ' . current_time('mysql'));

    ?>
    <div class="wrap">
        <h1><?php _e('All Rosters', 'intersoccer-reports-rosters'); ?></h1>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-all-rosters&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-all-rosters&action=rebuild'), 'intersoccer_rebuild'); ?>" class="button" onclick="return confirm('<?php _e('Are you sure you want to rebuild the rosters? This will delete all existing data and rebuild from scratch.', 'intersoccer-reports-rosters'); ?>');"><?php _e('Rebuild Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No rosters available. Please rebuild or wait for reconciliation.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="all">
                    <input type="submit" name="export_all" class="button button-primary" value="<?php _e('Export All Rosters', 'intersoccer-reports-rosters'); ?>">
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
                        echo '<h3>' . esc_html($venue) . ' (' . count($venue_rosters) . ' players)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Event Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        echo '<tr>';
                        echo '<td>' . esc_html($venue_rosters[0]->product_name) . '</td>';
                        echo '<td>' . esc_html($venue) . '</td>';
                        echo '<td>' . esc_html(count($venue_rosters)) . '</td>';
                        echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $venue_rosters[0]->order_item_id)) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
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
 * Render the Camps page.
 */
function intersoccer_render_camps_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Fetch and display roster data (read-only)
    $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE activity_type = 'Camp' ORDER BY updated_at DESC");
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' camp rosters for display on ' . current_time('mysql'));

    ?>
    <div class="wrap">
        <h1><?php _e('Camps', 'intersoccer-reports-rosters'); ?></h1>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-camps&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-camps&action=rebuild'), 'intersoccer_rebuild'); ?>" class="button" onclick="return confirm('<?php _e('Are you sure you want to rebuild the rosters? This will delete all existing data and rebuild from scratch.', 'intersoccer-reports-rosters'); ?>');"><?php _e('Rebuild Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No camp rosters available. Please rebuild or wait for reconciliation.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="camps">
                    <?php wp_nonce_field('intersoccer_reports_rosters_nonce', 'export_nonce'); ?>
                    <input type="submit" name="export_camps" class="button button-primary" value="<?php _e('Export All Camp Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                $unique_camp_terms = [];
                foreach ($rosters as $roster) {
                    $camp_terms = $roster->product_name . ' - ' . $roster->start_date . ' to ' . $roster->end_date;
                    if ($camp_terms && !isset($unique_camp_terms[$camp_terms])) {
                        $unique_camp_terms[$camp_terms] = true;
                        $venue_rosters = array_filter($rosters, fn($r) => $r->product_name . ' - ' . $r->start_date . ' to ' . $r->end_date === $camp_terms);
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html($camp_terms) . ' (' . count($venue_rosters) . ' players total)</h3>';
                        echo '<ul class="venue-list">';
                        foreach ($venue_rosters as $venue_roster) {
                            if ($venue_roster->venue && $venue_roster->venue !== 'Unknown Venue') {
                                echo '<li>';
                                echo esc_html($venue_roster->venue) . ' (' . 1 . ' player)';
                                echo '<a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $venue_roster->order_item_id)) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a>';
                                echo '</li>';
                            }
                        }
                        echo '</ul>';
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

    // Fetch and display roster data (read-only)
    $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE activity_type = 'Course' ORDER BY updated_at DESC");
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' course rosters for display on ' . current_time('mysql'));

    ?>
    <div class="wrap">
        <h1><?php _e('Courses', 'intersoccer-reports-rosters'); ?></h1>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-courses&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-courses&action=rebuild'), 'intersoccer_rebuild'); ?>" class="button" onclick="return confirm('<?php _e('Are you sure you want to rebuild the rosters? This will delete all existing data and rebuild from scratch.', 'intersoccer-reports-rosters'); ?>');"><?php _e('Rebuild Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No course rosters available. Please rebuild or wait for reconciliation.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="courses">
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
                        echo '<h3>' . esc_html($venue) . ' (' . count($venue_rosters) . ' players)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Event Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        echo '<tr>';
                        echo '<td>' . esc_html($venue_rosters[0]->product_name) . '</td>';
                        echo '<td>' . esc_html($venue) . '</td>';
                        echo '<td>' . esc_html(count($venue_rosters)) . '</td>';
                        echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $venue_rosters[0]->order_item_id)) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
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

    // Fetch and display roster data (read-only)
    $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE activity_type = 'Girls Only' ORDER BY updated_at DESC");
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' girls only rosters for display on ' . current_time('mysql'));

    ?>
    <div class="wrap">
        <h1><?php _e('Girls Only', 'intersoccer-reports-rosters'); ?></h1>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-girls-only&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-girls-only&action=rebuild'), 'intersoccer_rebuild'); ?>" class="button" onclick="return confirm('<?php _e('Are you sure you want to rebuild the rosters? This will delete all existing data and rebuild from scratch.', 'intersoccer-reports-rosters'); ?>');"><?php _e('Rebuild Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No girls only rosters available. Please rebuild or wait for reconciliation.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="girls_only">
                    <input type="submit" name="export_girls_only" class="button button-primary" value="<?php _e('Export All Girls\' Only Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                foreach ($rosters as $roster) {
                    if ($roster->venue && $roster->venue !== 'Unknown Venue') {
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html($roster->product_name . ' - ' . $roster->venue) . ' (' . 1 . ' player)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Event Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        echo '<tr>';
                        echo '<td>' . esc_html($roster->product_name) . '</td>';
                        echo '<td>' . esc_html($roster->venue) . '</td>';
                        echo '<td>' . esc_html(1) . '</td>';
                        echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $roster->order_item_id)) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
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

    // Fetch and display roster data (read-only)
    $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE activity_type IN ('Event', 'Other') ORDER BY updated_at DESC");
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' other event rosters for display on ' . current_time('mysql'));

    ?>
    <div class="wrap">
        <h1><?php _e('Other Events', 'intersoccer-reports-rosters'); ?></h1>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-other-events&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-other-events&action=rebuild'), 'intersoccer_rebuild'); ?>" class="button" onclick="return confirm('<?php _e('Are you sure you want to rebuild the rosters? This will delete all existing data and rebuild from scratch.', 'intersoccer-reports-rosters'); ?>');"><?php _e('Rebuild Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No other event rosters available. Please rebuild or wait for reconciliation.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="other">
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
                        echo '<h3>' . esc_html($venue) . ' (' . count($venue_rosters) . ' players)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Event Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        echo '<tr>';
                        echo '<td>' . esc_html($venue_rosters[0]->product_name) . '</td>';
                        echo '<td>' . esc_html($venue) . '</td>';
                        echo '<td>' . esc_html(count($venue_rosters)) . '</td>';
                        echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $venue_rosters[0]->order_item_id)) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
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
