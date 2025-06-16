<?php
/**
 * Advanced features page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.8
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render the Advanced Features page.
 */
function intersoccer_render_advanced_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }
    ?>
    <div class="wrap">
        <h1><?php _e('InterSoccer Advanced Features', 'intersoccer-reports-rosters'); ?></h1>
        <div id="intersoccer-rebuild-status"></div>
        <div class="advanced-options">
            <h2><?php _e('Database Management', 'intersoccer-reports-rosters'); ?></h2>
            <p><?php _e('Perform database upgrades or maintenance tasks.', 'intersoccer-reports-rosters'); ?></p>
            <form id="intersoccer-upgrade-form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="upgrade-form">
                <input type="hidden" name="action" value="intersoccer_upgrade_database">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_rebuild_nonce')); ?>">
                <input type="submit" name="upgrade_database" class="button button-primary" value="<?php _e('Upgrade Database', 'intersoccer-reports-rosters'); ?>" onclick="return confirm('<?php echo esc_js(__('This will modify the database structure and backfill data. Are you sure?', 'intersoccer-reports-rosters')); ?>');">
            </form>
            <p><?php _e('Note: This action adds the variation_id column and backfills existing data. Use with caution.', 'intersoccer-reports-rosters'); ?></p>
        </div>
        <div class="rebuild-options">
            <h2><?php _e('Roster Management', 'intersoccer-reports-rosters'); ?></h2>
            <form id="intersoccer-rebuild-form" method="post" action="">
                <?php wp_nonce_field('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field'); ?>
                <input type="hidden" name="action" value="intersoccer_rebuild_rosters_and_reports">
                <button type="submit" class="button button-primary" id="intersoccer-rebuild-button"><?php _e('Rebuild Rosters', 'intersoccer-reports-rosters'); ?></button>
            </form>
            <p><?php _e('Note: This will recreate the rosters table and repopulate it with current order data.', 'intersoccer-reports-rosters'); ?></p>
        </div>
        <div class="export-options">
            <h2><?php _e('Export Options', 'intersoccer-reports-rosters'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                <input type="hidden" name="export_type" value="all">
                <input type="hidden" name="format" value="csv">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_export_nonce')); ?>">
                <input type="hidden" name="debug_user" value="<?php echo esc_attr(get_current_user_id()); ?>">
                <input type="submit" name="export_all_csv" class="button button-primary" value="<?php _e('Export All Rosters (CSV)', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#intersoccer-rebuild-form').on('submit', function(e) {
                    e.preventDefault();
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $(this).serialize(),
                        beforeSend: function() {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Rebuilding... Please wait.', 'intersoccer-reports-rosters'); ?></p>');
                        },
                        success: function(response) {
                            $('#intersoccer-rebuild-status').html('<p>' + response.data.message + '</p>');
                            console.log('Rebuild response: ', response);
                        },
                        error: function(xhr, status, error) {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Rebuild failed: ', 'intersoccer-reports-rosters'); ?>' + (xhr.responseJSON ? xhr.responseJSON.message : error) + '</p>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
                        }
                    });
                });

                $('#intersoccer-upgrade-form').on('submit', function(e) {
                    e.preventDefault();
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $(this).serialize(),
                        beforeSend: function() {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Upgrading database... Please wait.', 'intersoccer-reports-rosters'); ?></p>');
                        },
                        success: function(response) {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Database upgrade completed. Check debug.log for details.', 'intersoccer-reports-rosters'); ?></p>');
                            console.log('Upgrade response: ', response);
                        },
                        error: function(xhr, status, error) {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Database upgrade failed: ', 'intersoccer-reports-rosters'); ?>' + error + '</p>');
                            console.error('AJAX Error: ', status, error);
                        }
                    });
                });
            });
        </script>
    </div>
    <?php
}

// Ensure AJAX actions are hooked (already defined in intersoccer-reports-rosters.php)
?>
