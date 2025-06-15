<?php
/**
 * Rosters page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.11
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render the Rosters page.
 */
function intersoccer_render_rosters_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    intersoccer_reconcile_rosters(); // Reconcile on page load
    $rosters = $wpdb->get_results("SELECT * FROM $rosters_table ORDER BY updated_at DESC");
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' rosters for display on ' . current_time('mysql'));

    ?>
    <div class="wrap">
        <h1><?php _e('InterSoccer Rosters', 'intersoccer-reports-rosters'); ?></h1>
        <?php if (empty($rosters)) : ?>
            <p><?php _e('No rosters available. Please rebuild or wait for reconciliation.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="camps">
                    <input type="submit" name="export_camps" class="button button-primary" value="<?php _e('Export All Camp Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="courses">
                    <input type="submit" name="export_courses" class="button button-primary" value="<?php _e('Export All Course Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="girls_only">
                    <input type="submit" name="export_girls_only" class="button button-primary" value="<?php _e('Export All Girls\' Only Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="events">
                    <input type="submit" name="export_events" class="button button-primary" value="<?php _e('Export All Event Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="nav-tab-wrapper">
                <a href="#tab-camp" class="nav-tab <?php echo (!isset($_GET['tab']) || $_GET['tab'] === 'camp') ? 'nav-tab-active' : ''; ?>"><?php _e('Camp Rosters', 'intersoccer-reports-rosters'); ?></a>
                <a href="#tab-course" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'course' ? 'nav-tab-active' : ''; ?>"><?php _e('Course Rosters', 'intersoccer-reports-rosters'); ?></a>
                <a href="#tab-girls-only" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'girls-only' ? 'nav-tab-active' : ''; ?>"><?php _e('Girls\' Only Rosters', 'intersoccer-reports-rosters'); ?></a>
                <a href="#tab-event" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'event' ? 'nav-tab-active' : ''; ?>"><?php _e('Event Rosters', 'intersoccer-reports-rosters'); ?></a>
                <a href="#tab-other" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'other' ? 'nav-tab-active' : ''; ?>"><?php _e('Other Rosters', 'intersoccer-reports-rosters'); ?></a>
            </div>
            <div id="tab-camp" class="tab-content <?php echo (!isset($_GET['tab']) || $_GET['tab'] === 'camp') ? 'active' : ''; ?>">
                <h2><?php _e('Camp Rosters by Camp Terms', 'intersoccer-reports-rosters'); ?></h2>
                <?php
                $camp_terms = [];
                foreach ($rosters as $roster) {
                    if ($roster->activity_type === 'Camp') {
                        $camp_terms[$roster->camp_terms][] = $roster;
                    }
                }
                ksort($camp_terms);
                foreach ($camp_terms as $term => $term_rosters) {
                    if ($term && $term !== 'N/A') {
                        echo '<h3>' . esc_html($term) . '</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Order Item ID', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Player Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('First Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Last Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Age', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Gender', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Booking Type', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Selected Days', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Parent Phone', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Parent Email', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Medical Conditions', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Late Pickup', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($term_rosters as $roster) {
                            $has_unknown = in_array('Unknown', [$roster->player_name, $roster->first_name, $roster->last_name, $roster->venue]) ? 'style="background-color: #fff3cd;"' : '';
                            echo '<tr ' . $has_unknown . '>';
                            echo '<td>' . esc_html($roster->order_item_id) . '</td>';
                            echo '<td>' . esc_html($roster->player_name) . '</td>';
                            echo '<td>' . esc_html($roster->first_name) . '</td>';
                            echo '<td>' . esc_html($roster->last_name) . '</td>';
                            echo '<td>' . esc_html($roster->age ?? 'N/A') . '</td>';
                            echo '<td>' . esc_html($roster->gender) . '</td>';
                            echo '<td>' . esc_html($roster->booking_type) . '</td>';
                            echo '<td>' . esc_html($roster->selected_days) . '</td>';
                            echo '<td>' . esc_html($roster->venue) . '</td>';
                            echo '<td>' . esc_html($roster->parent_phone) . '</td>';
                            echo '<td>' . esc_html($roster->parent_email) . '</td>';
                            echo '<td>' . esc_html($roster->medical_conditions ?? 'None') . '</td>';
                            echo '<td>' . esc_html($roster->late_pickup) . '</td>';
                            echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $roster->order_item_id)) . '" class="button">View Roster</a></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }
                }
                ?>
            </div>
            <div id="tab-course" class="tab-content <?php echo isset($_GET['tab']) && $_GET['tab'] === 'course' ? 'active' : ''; ?>">
                <h2><?php _e('Course Rosters by Season', 'intersoccer-reports-rosters'); ?></h2>
                <?php
                $current_date = new DateTime();
                $seasons = [];
                foreach ($rosters as $roster) {
                    if ($roster->activity_type === 'Course') {
                        $start_date = new DateTime($roster->start_date);
                        if ($start_date && $start_date <= $current_date) {
                            $seasons[$roster->start_date ?: 'Unknown'][$roster->product_name][] = $roster;
                        }
                    }
                }
                ksort($seasons);
                foreach ($seasons as $date => $season_courses) {
                    if ($date && $date !== 'N/A') {
                        echo '<h3>' . esc_html($date) . '</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Order Item ID', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Player Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('First Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Last Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Age', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Gender', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Booking Type', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Selected Days', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Parent Phone', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Parent Email', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Medical Conditions', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Late Pickup', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($season_courses as $course_rosters) {
                            foreach ($course_rosters as $roster) {
                                $has_unknown = in_array('Unknown', [$roster->player_name, $roster->first_name, $roster->last_name, $roster->venue]) ? 'style="background-color: #fff3cd;"' : '';
                                echo '<tr ' . $has_unknown . '>';
                                echo '<td>' . esc_html($roster->order_item_id) . '</td>';
                                echo '<td>' . esc_html($roster->player_name) . '</td>';
                                echo '<td>' . esc_html($roster->first_name) . '</td>';
                                echo '<td>' . esc_html($roster->last_name) . '</td>';
                                echo '<td>' . esc_html($roster->age ?? 'N/A') . '</td>';
                                echo '<td>' . esc_html($roster->gender) . '</td>';
                                echo '<td>' . esc_html($roster->booking_type) . '</td>';
                                echo '<td>' . esc_html($roster->selected_days) . '</td>';
                                echo '<td>' . esc_html($roster->venue) . '</td>';
                                echo '<td>' . esc_html($roster->parent_phone) . '</td>';
                                echo '<td>' . esc_html($roster->parent_email) . '</td>';
                                echo '<td>' . esc_html($roster->medical_conditions ?? 'None') . '</td>';
                                echo '<td>' . esc_html($roster->late_pickup) . '</td>';
                                echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $roster->order_item_id)) . '" class="button">View Roster</a></td>';
                                echo '</tr>';
                            }
                        }
                        echo '</tbody></table>';
                    }
                }
                ?>
            </div>
            <div id="tab-girls-only" class="tab-content <?php echo isset($_GET['tab']) && $_GET['tab'] === 'girls-only' ? 'active' : ''; ?>">
                <h2><?php _e('Girls\' Only Rosters by Camp Terms', 'intersoccer-reports-rosters'); ?></h2>
                <?php
                $girls_only_terms = [];
                foreach ($rosters as $roster) {
                    if ($roster->activity_type === 'Girls-Only') {
                        $girls_only_terms[$roster->camp_terms][] = $roster;
                    }
                }
                ksort($girls_only_terms);
                foreach ($girls_only_terms as $term => $term_rosters) {
                    if ($term && $term !== 'N/A') {
                        echo '<h3>' . esc_html($term) . '</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th>' . __('Order Item ID', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Player Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('First Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Last Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Age', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Gender', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Booking Type', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Selected Days', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Parent Phone', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Parent Email', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Medical Conditions', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Late Pickup', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($term_rosters as $roster) {
                            $has_unknown = in_array('Unknown', [$roster->player_name, $roster->first_name, $roster->last_name, $roster->venue]) ? 'style="background-color: #fff3cd;"' : '';
                            echo '<tr ' . $has_unknown . '>';
                            echo '<td>' . esc_html($roster->order_item_id) . '</td>';
                            echo '<td>' . esc_html($roster->player_name) . '</td>';
                            echo '<td>' . esc_html($roster->first_name) . '</td>';
                            echo '<td>' . esc_html($roster->last_name) . '</td>';
                            echo '<td>' . esc_html($roster->age ?? 'N/A') . '</td>';
                            echo '<td>' . esc_html($roster->gender) . '</td>';
                            echo '<td>' . esc_html($roster->booking_type) . '</td>';
                            echo '<td>' . esc_html($roster->selected_days) . '</td>';
                            echo '<td>' . esc_html($roster->venue) . '</td>';
                            echo '<td>' . esc_html($roster->parent_phone) . '</td>';
                            echo '<td>' . esc_html($roster->parent_email) . '</td>';
                            echo '<td>' . esc_html($roster->medical_conditions ?? 'None') . '</td>';
                            echo '<td>' . esc_html($roster->late_pickup) . '</td>';
                            echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $roster->order_item_id)) . '" class="button">View Roster</a></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }
                }
                ?>
            </div>
            <div id="tab-event" class="tab-content <?php echo isset($_GET['tab']) && $_GET['tab'] === 'event' ? 'active' : ''; ?>">
                <h2><?php _e('Event Rosters', 'intersoccer-reports-rosters'); ?></h2>
                <?php
                $event_rosters = array_filter($rosters, fn($roster) => $roster->activity_type === 'Event');
                if (!empty($event_rosters)) {
                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead><tr>';
                    echo '<th>' . __('Order Item ID', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Player Name', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('First Name', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Last Name', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Age', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Gender', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Booking Type', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Selected Days', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Parent Phone', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Parent Email', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Medical Conditions', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Late Pickup', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                    echo '</tr></thead><tbody>';
                    foreach ($event_rosters as $roster) {
                        $has_unknown = in_array('Unknown', [$roster->player_name, $roster->first_name, $roster->last_name, $roster->venue]) ? 'style="background-color: #fff3cd;"' : '';
                        echo '<tr ' . $has_unknown . '>';
                        echo '<td>' . esc_html($roster->order_item_id) . '</td>';
                        echo '<td>' . esc_html($roster->player_name) . '</td>';
                        echo '<td>' . esc_html($roster->first_name) . '</td>';
                        echo '<td>' . esc_html($roster->last_name) . '</td>';
                        echo '<td>' . esc_html($roster->age ?? 'N/A') . '</td>';
                        echo '<td>' . esc_html($roster->gender) . '</td>';
                        echo '<td>' . esc_html($roster->booking_type) . '</td>';
                        echo '<td>' . esc_html($roster->selected_days) . '</td>';
                        echo '<td>' . esc_html($roster->venue) . '</td>';
                        echo '<td>' . esc_html($roster->parent_phone) . '</td>';
                        echo '<td>' . esc_html($roster->parent_email) . '</td>';
                        echo '<td>' . esc_html($roster->medical_conditions ?? 'None') . '</td>';
                        echo '<td>' . esc_html($roster->late_pickup) . '</td>';
                        echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $roster->order_item_id)) . '" class="button">View Roster</a></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>' . __('No event rosters available.', 'intersoccer-reports-rosters') . '</p>';
                }
                ?>
            </div>
            <div id="tab-other" class="tab-content <?php echo isset($_GET['tab']) && $_GET['tab'] === 'other' ? 'active' : ''; ?>">
                <h2><?php _e('Other Rosters', 'intersoccer-reports-rosters'); ?></h2>
                <?php
                $other_rosters = array_filter($rosters, fn($roster) => $roster->activity_type === 'Other');
                if (!empty($other_rosters)) {
                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead><tr>';
                    echo '<th>' . __('Order Item ID', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Player Name', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('First Name', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Last Name', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Age', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Gender', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Booking Type', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Selected Days', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Parent Phone', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Parent Email', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Medical Conditions', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Late Pickup', 'intersoccer-reports-rosters') . '</th>';
                    echo '<th>' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                    echo '</tr></thead><tbody>';
                    foreach ($other_rosters as $roster) {
                        $has_unknown = in_array('Unknown', [$roster->player_name, $roster->first_name, $roster->last_name, $roster->venue]) ? 'style="background-color: #fff3cd;"' : '';
                        echo '<tr ' . $has_unknown . '>';
                        echo '<td>' . esc_html($roster->order_item_id) . '</td>';
                        echo '<td>' . esc_html($roster->player_name) . '</td>';
                        echo '<td>' . esc_html($roster->first_name) . '</td>';
                        echo '<td>' . esc_html($roster->last_name) . '</td>';
                        echo '<td>' . esc_html($roster->age ?? 'N/A') . '</td>';
                        echo '<td>' . esc_html($roster->gender) . '</td>';
                        echo '<td>' . esc_html($roster->booking_type) . '</td>';
                        echo '<td>' . esc_html($roster->selected_days) . '</td>';
                        echo '<td>' . esc_html($roster->venue) . '</td>';
                        echo '<td>' . esc_html($roster->parent_phone) . '</td>';
                        echo '<td>' . esc_html($roster->parent_email) . '</td>';
                        echo '<td>' . esc_html($roster->medical_conditions ?? 'None') . '</td>';
                        echo '<td>' . esc_html($roster->late_pickup) . '</td>';
                        echo '<td><a href="' . esc_url(admin_url('admin.php?page=intersoccer-roster-details&order_item_id=' . $roster->order_item_id)) . '" class="button">View Roster</a></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>' . __('No other rosters available.', 'intersoccer-reports-rosters') . '</p>';
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
?>
