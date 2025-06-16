<?php
/**
 * Roster details page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.9
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render the Roster Details page.
 */
function intersoccer_render_roster_details_page() {
    try {
        $allowed_roles = ['manage_options', 'coach', 'event_organizer', 'shop_manager'];
        $has_permission = false;
        foreach ($allowed_roles as $role) {
            if (current_user_can($role)) {
                $has_permission = true;
                break;
            }
        }
        if (!$has_permission) {
            error_log('InterSoccer: Roster details access denied for user ID ' . get_current_user_id() . ' with roles: ' . implode(', ', wp_get_current_user()->roles));
            wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
        }

        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $rosters = [];

        if (isset($_GET['order_item_id'])) {
            $order_item_id = intval($_GET['order_item_id']);
            if (!$order_item_id) {
                wp_die(__('Invalid order_item_id provided.', 'intersoccer-reports-rosters'));
            }
            $rosters = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $rosters_table WHERE order_item_id = %d ORDER BY updated_at DESC", $order_item_id),
                ARRAY_A
            );
            $title = __('Roster Details for Order Item ID: ', 'intersoccer-reports-rosters') . $order_item_id;
        } elseif (isset($_GET['product_name']) && isset($_GET['venue']) && isset($_GET['age_group'])) {
            $product_name = trim(sanitize_text_field($_GET['product_name']));
            $venue = trim(sanitize_text_field($_GET['venue']));
            $age_group = trim(sanitize_text_field($_GET['age_group']));
            $rosters = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $rosters_table WHERE product_name = %s AND venue = %s AND age_group = %s ORDER BY updated_at DESC",
                    $product_name,
                    $venue,
                    $age_group
                ),
                ARRAY_A
            );
            $venue_name = intersoccer_get_term_name($venue, 'pa_intersoccer-venues');
            $age_group_name = intersoccer_get_term_name($age_group, 'pa_age-group');
            $title = __('Roster Details for ', 'intersoccer-reports-rosters') . esc_html("$product_name - $venue_name - $age_group_name");
        } else {
            wp_die(__('Invalid parameters provided.', 'intersoccer-reports-rosters'));
        }

        error_log('InterSoccer: Retrieved ' . count($rosters) . ' rosters from wp_intersoccer_rosters for display on ' . current_time('mysql'));
        if (empty($rosters)) {
            wp_die(__('No roster data available for the given parameters.', 'intersoccer-reports-rosters'));
        }

        ?>
        <div class="wrap intersoccer-roster-details">
            <h1><?php echo esc_html($title); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('First Name', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Last Name', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Gender', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Parent Phone', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Parent Email', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Medical/Dietary', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Late Pickup', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Age', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Booking Type', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Selected Days', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Camp Terms', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Day Presence', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Event Dates', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Product Name', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Activity Type', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Updated At', 'intersoccer-reports-rosters'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rosters as $roster) : ?>
                        <tr>
                            <td><?php echo esc_html($roster['first_name']); ?></td>
                            <td><?php echo esc_html($roster['last_name']); ?></td>
                            <td><?php echo esc_html($roster['gender']); ?></td>
                            <td><?php echo esc_html($roster['parent_phone']); ?></td>
                            <td><?php echo esc_html($roster['parent_email']); ?></td>
                            <td><?php echo esc_html($roster['medical_conditions'] ?? 'None'); ?></td>
                            <td><?php echo esc_html($roster['late_pickup'] === '18h' ? 'Yes' : 'No'); ?></td>
                            <td><?php echo esc_html($roster['age'] ?? 'N/A'); ?></td>
                            <td><?php echo esc_html($roster['booking_type']); ?></td>
                            <td><?php echo esc_html($roster['selected_days'] ?? 'N/A'); ?></td>
                            <td><?php echo esc_html(intersoccer_get_term_name($roster['camp_terms'], 'pa_camp-terms')); ?></td>
                            <td><?php echo esc_html(json_decode($roster['day_presence'], true) ? json_encode(json_decode($roster['day_presence'], true)) : 'N/A'); ?></td>
                            <td><?php echo esc_html($roster['event_dates'] ?? 'N/A'); ?></td>
                            <td><?php echo esc_html($roster['product_name']); ?></td>
                            <td><?php echo esc_html($roster['activity_type']); ?></td>
                            <td><?php echo esc_html($roster['updated_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                <input type="hidden" name="action" value="intersoccer_export_roster">
                <?php foreach ($rosters as $roster) : ?>
                    <input type="hidden" name="variation_ids[]" value="<?php echo esc_attr($roster['order_item_id']); ?>">
                <?php endforeach; ?>
                <?php wp_nonce_field('intersoccer_reports_rosters_nonce', 'export_nonce'); ?>
                <input type="submit" name="export_roster" class="button button-primary" value="<?php _e('Export This Roster', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>
        <?php
    } catch (Exception $e) {
        error_log('InterSoccer: Roster Details page error: ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the roster details page.', 'intersoccer-reports-rosters'));
    }
}
?>
