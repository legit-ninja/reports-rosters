<?php
/**
 * Roster details page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.3
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render the Roster Details page.
 */
function intersoccer_render_roster_details_page() {
    try {
        if (!current_user_can('read')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
        }

        $order_item_id = isset($_GET['order_item_id']) ? intval($_GET['order_item_id']) : 0;
        if (!$order_item_id) {
            wp_die(__('No order item ID provided.', 'intersoccer-reports-rosters'));
        }

        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $roster = $wpdb->get_row($wpdb->prepare("SELECT * FROM $rosters_table WHERE order_item_id = %d", $order_item_id), ARRAY_A);
        if (!$roster) {
            wp_die(__('No roster data available for this order item.', 'intersoccer-reports-rosters'));
        }

        $order = wc_get_order($wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d", $order_item_id)));
        $item = $order ? $order->get_item($order_item_id) : null;
        $variation_id = $item ? ($item->get_variation_id() ?: $item->get_product_id()) : 0;
        $variation = $variation_id ? wc_get_product($variation_id) : null;
        $product_id = $variation ? $variation->get_parent_id() : 0;
        $product = $product_id ? wc_get_product($product_id) : null;

        ?>
        <div class="wrap intersoccer-roster-details">
            <h1><?php _e('Roster Details', 'intersoccer-reports-rosters'); ?></h1>
            <p><?php echo esc_html('Event: ' . ($product ? $product->get_name() : $roster['product_name']) . ' (Order Item ID: ' . $order_item_id . ')'); ?></p>
            <p><?php echo esc_html('Venue: ' . ($roster['venue'] ?? 'Unknown Venue')); ?></p>
            <p><?php echo esc_html('Age Group: ' . ($roster['age_group'] ?? 'N/A')); ?></p>
            <p><?php echo esc_html('Start Date: ' . ($roster['start_date'] ?? 'N/A')); ?></p>
            <p><?php echo esc_html('End Date: ' . ($roster['end_date'] ?? 'N/A')); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Player Name', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Age', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Gender', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Medical Conditions', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Late Pickup', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Parent Phone', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php _e('Parent Email', 'intersoccer-reports-rosters'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo esc_html($roster['player_name']); ?></td>
                        <td><?php echo esc_html($roster['age'] ?? 'N/A'); ?></td>
                        <td><?php echo esc_html($roster['gender']); ?></td>
                        <td><?php echo esc_html($roster['medical_conditions'] ?? 'None'); ?></td>
                        <td><?php echo esc_html($roster['late_pickup'] === '18h' ? 'Yes' : 'No'); ?></td>
                        <td><?php echo esc_html($roster['parent_phone']); ?></td>
                        <td><?php echo esc_html($roster['parent_email']); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
        error_log('InterSoccer: Rendered Roster Details page for order item ID ' . $order_item_id);
    } catch (Exception $e) {
        error_log('InterSoccer: Roster Details page error: ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the roster details page.', 'intersoccer-reports-rosters'));
    }
}
?>
