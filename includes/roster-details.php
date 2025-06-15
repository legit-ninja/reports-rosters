<?php
/**
 * Roster details page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.4
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

        $variation_id = isset($_GET['variation_id']) ? intval($_GET['variation_id']) : 0;
        if (!$variation_id) {
            error_log('InterSoccer: No variation ID provided in request: ' . json_encode($_GET));
            wp_die(__('No variation ID provided.', 'intersoccer-reports-rosters'));
        }

        $rosters = intersoccer_get_roster_data(['variation_id' => $variation_id]);
        $all_rosters = [];
        foreach ($rosters as $group) {
            $all_rosters = array_merge($all_rosters, $group);
        }
        error_log('InterSoccer: Retrieved ' . count($all_rosters) . ' rosters for variation_id ' . $variation_id);

        if (empty($all_rosters)) {
            wp_die(__('No roster data available for this variation.', 'intersoccer-reports-rosters'));
        }

        $variation = wc_get_product($variation_id);
        $product_id = $variation ? $variation->get_parent_id() : 0;
        $product = $product_id ? wc_get_product($product_id) : null;

        ?>
        <div class="wrap intersoccer-roster-details">
            <h1><?php _e('Roster Details', 'intersoccer-reports-rosters'); ?></h1>
            <p><?php echo esc_html('Event: ' . ($product ? $product->get_name() : $variation->get_name()) . ' (Variation ID: ' . $variation_id . ')'); ?></p>
            <p><?php echo esc_html('Venue: ' . ($all_rosters[0]['venue'] ?? 'Unknown Venue')); ?></p>
            <p><?php echo esc_html('Age Group: ' . ($all_rosters[0]['age_group'] ?? 'N/A')); ?></p>
            <p><?php echo esc_html('Start Date: ' . ($all_rosters[0]['start_date'] ?? 'N/A')); ?></p>
            <p><?php echo esc_html('End Date: ' . ($all_rosters[0]['end_date'] ?? 'N/A')); ?></p>

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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_rosters as $roster) : ?>
                        <tr>
                            <td><?php echo esc_html($roster['first_name']); ?></td>
                            <td><?php echo esc_html($roster['last_name']); ?></td>
                            <td><?php echo esc_html($roster['gender']); ?></td>
                            <td><?php echo esc_html($roster['parent_phone']); ?></td>
                            <td><?php echo esc_html($roster['parent_email']); ?></td>
                            <td><?php echo esc_html($roster['medical_conditions'] ?? 'None'); ?></td>
                            <td><?php echo esc_html($roster['late_pickup'] === '18h' ? 'Yes' : 'No'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                <input type="hidden" name="action" value="intersoccer_export_roster">
                <input type="hidden" name="variation_ids[]" value="<?php echo esc_attr($variation_id); ?>">
                <?php wp_nonce_field('intersoccer_reports_rosters_nonce', 'export_nonce'); ?>
                <input type="submit" name="export_roster" class="button button-primary" value="<?php _e('Export This Roster', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>
        <?php
    } catch (Exception $e) {
        error_log('InterSoccer: Roster Details page error for variation_id ' . $variation_id . ': ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the roster details page.', 'intersoccer-reports-rosters'));
    }
}
?>
