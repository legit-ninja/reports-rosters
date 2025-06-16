<?php
/**
 * Rosters pages for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.17
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

    // Check if reconcile is needed (compare with completed orders)
    $completed_items = $wpdb->get_col(
        "SELECT oi.order_item_id FROM {$wpdb->prefix}woocommerce_order_items oi
         JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id
         WHERE o.status = 'completed' AND oi.order_item_type = 'line_item'"
    );
    $existing_rosters = $wpdb->get_col("SELECT order_item_id FROM $rosters_table");
    $reconcile_needed = array_diff($completed_items, $existing_rosters) || array_diff($existing_rosters, $completed_items);

    // Fetch unique product names
    $product_names = $wpdb->get_col("SELECT DISTINCT product_name FROM $rosters_table ORDER BY product_name");
    error_log('InterSoccer: Retrieved ' . count($product_names) . ' unique product names for All Rosters on ' . current_time('mysql'));

    $export_nonce = wp_create_nonce('intersoccer_export_nonce');
    ?>
    <div class="wrap">
        <h1><?php _e('All Rosters', 'intersoccer-reports-rosters'); ?></h1>
        <?php if ($reconcile_needed) : ?>
            <div class="notice notice-warning"><p><?php _e('Reconcile is needed to sync rosters with completed orders.', 'intersoccer-reports-rosters'); ?></p></div>
        <?php endif; ?>
        <?php if (empty($product_names)) : ?>
            <p><?php _e('No rosters available. Please rebuild or reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="all">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($export_nonce); ?>">
                    <input type="hidden" name="debug_user" value="<?php echo esc_attr(get_current_user_id()); ?>">
                    <input type="submit" name="export_all" class="button button-primary" value="<?php _e('Export All Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                foreach ($product_names as $product_name) {
                    // Fetch grouped data for this product_name
                    $groups = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT venue, age_group, COUNT(*) as total_players
                             FROM $rosters_table
                             WHERE product_name = %s
                             GROUP BY venue, age_group
                             ORDER BY venue, age_group",
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
                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&product_name=' . urlencode($product_name) . '&venue=' . urlencode($group['venue']) . '&age_group=' . urlencode($group['age_group']));
                            echo '<tr>';
                            echo '<td>' . esc_html($group['venue']) . '</td>';
                            echo '<td>' . esc_html($group['age_group']) . '</td>';
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
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-all-rosters&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
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

    // Fetch unique camp terms for Camps
    $camp_terms_list = $wpdb->get_col("SELECT DISTINCT camp_terms FROM $rosters_table WHERE activity_type = 'Camp' ORDER BY camp_terms");
    error_log('InterSoccer: Retrieved ' . count($camp_terms_list) . ' unique camp terms for Camps on ' . current_time('mysql'));

    $export_nonce = wp_create_nonce('intersoccer_export_nonce');
    ?>
    <div class="wrap">
        <h1><?php _e('Camps', 'intersoccer-reports-rosters'); ?></h1>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-camps&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
        <?php if (empty($camp_terms_list)) : ?>
            <p><?php _e('No camp rosters available. Please rebuild or reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="camps">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($export_nonce); ?>">
                    <input type="hidden" name="debug_user" value="<?php echo esc_attr(get_current_user_id()); ?>">
                    <input type="submit" name="export_camps" class="button button-primary" value="<?php _e('Export All Camp Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                foreach ($camp_terms_list as $camp_terms) {
                    $groups = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT product_name, venue, age_group, COUNT(*) as total_players
                             FROM $rosters_table
                             WHERE activity_type = 'Camp' AND camp_terms = %s
                             GROUP BY product_name, venue, age_group
                             ORDER BY product_name, venue, age_group",
                            $camp_terms
                        ),
                        ARRAY_A
                    );
                    if (!empty($groups)) {
                        echo '<div class="roster-group">';
                        echo '<h2>' . esc_html($camp_terms) . ' (' . array_sum(array_column($groups, 'total_players')) . ' players)</h2>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Product Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Age Group', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($groups as $group) {
                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&product_name=' . urlencode($group['product_name']) . '&venue=' . urlencode($group['venue']) . '&age_group=' . urlencode($group['age_group']));
                            echo '<tr>';
                            echo '<td>' . esc_html($group['product_name']) . '</td>';
                            echo '<td>' . esc_html($group['venue']) . '</td>';
                            echo '<td>' . esc_html($group['age_group']) . '</td>';
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

    // Fetch and display roster data (read-only)
    $rosters = $wpdb->get_results("SELECT * FROM $rosters_table WHERE activity_type = 'Course' ORDER BY updated_at DESC");
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' course rosters for display on ' . current_time('mysql'));

    $export_nonce = wp_create_nonce('intersoccer_export_nonce');
    ?>
    <div class="wrap">
        <h1><?php _e('Courses', 'intersoccer-reports-rosters'); ?></h1>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-courses&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No course rosters available. Please rebuild or reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="courses">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($export_nonce); ?>">
                    <input type="hidden" name="debug_user" value="<?php echo esc_attr(get_current_user_id()); ?>">
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

    $export_nonce = wp_create_nonce('intersoccer_export_nonce');
    ?>
    <div class="wrap">
        <h1><?php _e('Girls Only', 'intersoccer-reports-rosters'); ?></h1>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-girls-only&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No girls only rosters available. Please rebuild or reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="girls_only">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($export_nonce); ?>">
                    <input type="hidden" name="debug_user" value="<?php echo esc_attr(get_current_user_id()); ?>">
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

    $export_nonce = wp_create_nonce('intersoccer_export_nonce');
    ?>
    <div class="wrap">
        <h1><?php _e('Other Events', 'intersoccer-reports-rosters'); ?></h1>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-other-events&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a>
        </p>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No other event rosters available. Please rebuild or reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="other">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($export_nonce); ?>">
                    <input type="hidden" name="debug_user" value="<?php echo esc_attr(get_current_user_id()); ?>">
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
?>
