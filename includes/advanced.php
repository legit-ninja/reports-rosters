<?php
/**
 * Advanced features page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.3
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render the Advanced Features page.
 */
function intersoccer_render_advanced_page() {
    try {
        if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
        }

        // Check if migration is requested
        if (isset($_GET['action']) && $_GET['action'] === 'migrate_player_data' && check_admin_referer('migrate_player_data_nonce')) {
            intersoccer_migrate_player_data_to_orders();
        }

        $needs_migration = intersoccer_orders_need_migration();
        $diagnostic = intersoccer_diagnose_assigned_player_metadata();

        ?>
        <div class="wrap intersoccer-reports-rosters-advanced">
            <h1><?php _e('InterSoccer Advanced Features', 'intersoccer-reports-rosters'); ?></h1>
            <p><?php _e('This page provides advanced tools for managing InterSoccer data, including attendance tracking, coach notes, and data migration utilities.', 'intersoccer-reports-rosters'); ?></p>

            <div class="filter-section">
                <h2><?php _e('Data Migration', 'intersoccer-reports-rosters'); ?></h2>
                <?php if ($needs_migration): ?>
                    <p><?php _e('Some orders need to be updated with player metadata. Click the button below to run the migration.', 'intersoccer-reports-rosters'); ?></p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-advanced&action=migrate_player_data'), 'migrate_player_data_nonce')); ?>" class="button button-primary"><?php _e('Migrate Player Data to Orders', 'intersoccer-reports-rosters'); ?></a>
                <?php else: ?>
                    <p><?php _e('All orders already have player metadata. No migration is needed.', 'intersoccer-reports-rosters'); ?></p>
                    <button class="button button-primary" disabled><?php _e('Migrate Player Data to Orders', 'intersoccer-reports-rosters'); ?></button>
                <?php endif; ?>
            </div>

            <div class="filter-section">
                <h2><?php _e('Assigned Attendee Metadata Diagnostic', 'intersoccer-reports-rosters'); ?></h2>
                <p><?php _e('Total Orders: ', 'intersoccer-reports-rosters'); echo esc_html($diagnostic['total_orders']); ?></p>
                <p><?php _e('Orders with Assigned Attendees: ', 'intersoccer-reports-rosters'); echo esc_html($diagnostic['orders_with_assigned_players']); ?></p>
                <p><?php _e('Orders Missing Assigned Attendees: ', 'intersoccer-reports-rosters'); echo esc_html($diagnostic['orders_missing_assigned_players']); ?></p>
                <p><?php _e('Orders with Attendees but Missing Metadata (Can Be Restored): ', 'intersoccer-reports-rosters'); echo esc_html($diagnostic['orders_with_players_but_missing_metadata']); ?></p>
                <?php if ($diagnostic['migration_needed']): ?>
                    <p style="color: red;"><?php _e('A data migration is recommended to restore missing metadata for orders. Run the migration script above.', 'intersoccer-reports-rosters'); ?></p>
                <?php else: ?>
                    <p style="color: green;"><?php _e('No data migration is needed for Assigned Attendee metadata.', 'intersoccer-reports-rosters'); ?></p>
                <?php endif; ?>
            </div>

            <div class="filter-section">
                <h2><?php _e('Additional Advanced Features', 'intersoccer-reports-rosters'); ?></h2>
                <p><?php _e('More advanced features like attendance tracking and coach notes will be added in future updates.', 'intersoccer-reports-rosters'); ?></p>
            </div>
        </div>
        <?php
        error_log('InterSoccer: Rendered Advanced Features page');
    } catch (Exception $e) {
        error_log('InterSoccer: Advanced page error: ' . $e->getMessage());
        wp_die(__('An error occurred while rendering the advanced features page.', 'intersoccer-reports-rosters'));
    }
}
?>
