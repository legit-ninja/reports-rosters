<?php
/**
 * Rosters Tabs AJAX Handler
 *
 * Supports the roster listing pages (Camps/Courses/etc) expand-row details UI.
 *
 * @package InterSoccer\ReportsRosters\Ajax
 */

namespace InterSoccer\ReportsRosters\Ajax;

defined('ABSPATH') or die('Restricted access');

class RostersTabsAjaxHandler {
    public function register(): void {
        add_action('wp_ajax_intersoccer_get_roster_details', [$this, 'getRosterDetails']);
    }

    public function getRosterDetails(): void {
        check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');

        if (!current_user_can('manage_options') && !current_user_can('coach')) {
            wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
        }

        $variation_id = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;
        if ($variation_id <= 0) {
            wp_send_json_error(['message' => __('Invalid variation ID.', 'intersoccer-reports-rosters')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE variation_id = %d",
            $variation_id
        ));

        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT id, player_name, first_name, last_name, age, gender, booking_type, selected_days, day_presence, order_id, order_item_id, event_signature, event_completed
             FROM {$table}
             WHERE variation_id = %d
             ORDER BY last_name ASC, first_name ASC, id ASC
             LIMIT 250",
            $variation_id
        ), ARRAY_A);

        if (!is_array($entries) || empty($entries)) {
            wp_send_json_success([
                'details' => '<p>' . esc_html__('No roster entries found for this variation.', 'intersoccer-reports-rosters') . '</p>',
            ]);
        }

        $first = $entries[0];
        $event_signature = (string) ($first['event_signature'] ?? '');
        $event_completed = (int) ($first['event_completed'] ?? 0);

        $details_url_params = ['page' => 'intersoccer-roster-details', 'variation_id' => $variation_id];
        if ($event_signature !== '') {
            $details_url_params['event_signature'] = $event_signature;
        }
        $details_url = add_query_arg($details_url_params, admin_url('admin.php'));

        ob_start();
        ?>
        <div class="intersoccer-roster-details">
            <p>
                <strong><?php echo esc_html(sprintf(__('Total entries: %d', 'intersoccer-reports-rosters'), $total)); ?></strong>
                <?php if ($event_signature !== ''): ?>
                    <span style="margin-left: 10px;"><?php echo esc_html(sprintf(__('Event signature: %s', 'intersoccer-reports-rosters'), $event_signature)); ?></span>
                <?php endif; ?>
                <span style="margin-left: 10px;">
                    <?php echo $event_completed ? esc_html__('Status: Completed', 'intersoccer-reports-rosters') : esc_html__('Status: Active', 'intersoccer-reports-rosters'); ?>
                </span>
            </p>

            <p>
                <a class="button button-secondary" href="<?php echo esc_url($details_url); ?>">
                    <?php esc_html_e('Open roster details', 'intersoccer-reports-rosters'); ?>
                </a>
            </p>

            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php esc_html_e('Player', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php esc_html_e('Age', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php esc_html_e('Gender', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php esc_html_e('Booking', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php esc_html_e('Selected days', 'intersoccer-reports-rosters'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['player_name'] ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?></td>
                        <td><?php echo esc_html($row['age']); ?></td>
                        <td><?php echo esc_html($row['gender']); ?></td>
                        <td><?php echo esc_html($row['booking_type']); ?></td>
                        <td><?php echo esc_html($row['selected_days']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(['details' => $html]);
    }
}

