<?php
/**
 * Advanced features page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.20  // Incremented for fix
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
            <p><?php _e('Note: This action adds the variation_id column and backfill existing data. Use with caution.', 'intersoccer-reports-rosters'); ?></p>
        </div>
        <div class="rebuild-options">
            <h2><?php _e('Roster Management', 'intersoccer-reports-rosters'); ?></h2>
            <form id="intersoccer-process-processing-form" method="post" action="">
                <?php wp_nonce_field('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field'); ?>
                <input type="hidden" name="action" value="intersoccer_process_existing_orders">
                <button type="submit" class="button button-secondary" id="intersoccer-process-processing-button"><?php _e('Process Orders', 'intersoccer-reports-rosters'); ?></button>
            </form>
            <p><?php _e('Note: This will populate missing rosters for existing orders (e.g., processing or on-hold) and complete them if fully populated.', 'intersoccer-reports-rosters'); ?></p>
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
                console.log('InterSoccer: Advanced page JS loaded');  // Log to validate JS execution
                $('#intersoccer-rebuild-form').on('submit', function(e) {
                    e.preventDefault();
                    console.log('InterSoccer: Rebuild form submit triggered');  // Log binding
                    if (!confirm("Are you sure you want to rebuild the rosters table? This will delete all existing data in the table, recreate it from current WooCommerce orders. This is a last resort action and may cause temporary data inconsistencies until completed.")) {
                        console.log('InterSoccer: Rebuild cancelled by user');
                        return false;
                    }
                    console.log('InterSoccer: Rebuild confirmed, proceeding with AJAX');
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

                $('#intersoccer-process-processing-form').on('submit', function(e) {
                    e.preventDefault();
                    console.log('InterSoccer: Process orders form submit triggered');  // Log binding
                    if (!confirm("Are you sure you want to process all pending orders? This will populate rosters for processing or on-hold orders and transition them to completed.")) {
                        console.log('InterSoccer: Process orders cancelled by user');
                        return false;
                    }
                    console.log('InterSoccer: Process orders confirmed, proceeding with AJAX');
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $(this).serialize(),
                        beforeSend: function() {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Processing orders... Please wait.', 'intersoccer-reports-rosters'); ?></p>');
                        },
                        success: function(response) {
                            $('#intersoccer-rebuild-status').html('<p>' + (response.data.message || '<?php _e('Orders processed. Check debug.log for details.', 'intersoccer-reports-rosters'); ?>') + '</p>');
                            console.log('Process response: ', response);
                        },
                        error: function(xhr, status, error) {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Processing failed: ', 'intersoccer-reports-rosters'); ?>' + (xhr.responseJSON ? xhr.responseJSON.message : error) + '</p>');
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

if (!function_exists('dbDelta')) {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
}
if (!class_exists('WC_Order')) {
    require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
}

/**
 * Process existing orders to populate rosters and complete them.
 * Scans for 'processing' and 'on-hold' orders, calls populate on each, log counts.
 */
function intersoccer_process_existing_orders() {
    error_log('InterSoccer: Starting process existing orders');

    $orders = wc_get_orders([
        'limit' => -1,
        'status' => ['wc-processing', 'wc-on-hold'],  // Target legacy statuses
    ]);
    error_log('InterSoccer: Found ' . count($orders) . ' eligible orders to process');

    $processed = 0;
    $inserted = 0;
    $completed = 0;
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    foreach ($orders as $order) {
        $order_id = $order->get_id();
        $initial_status = $order->get_status();
        error_log('InterSoccer: Processing existing order ' . $order_id . ' (initial status: ' . $initial_status . ')');
        
        // Check if already populated
        $existing_rosters = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rosters_table WHERE order_id = %d", $order_id));
        error_log('InterSoccer: Existing rosters for order ' . $order_id . ': ' . $existing_rosters);
        
        // Call the populate function from woocommerce-orders.php
        ob_start();  // Suppress any output
        intersoccer_populate_rosters_and_complete_order($order_id);
        ob_end_clean();
        
        // Verify inserts and status
        $new_inserts = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rosters_table WHERE order_id = %d", $order_id)) - $existing_rosters;
        $new_status = wc_get_order($order_id)->get_status();  // Reload to check change
        if ($new_inserts > 0) {
            $inserted += $new_inserts;
            $processed++;
            error_log('InterSoccer: Added ' . $new_inserts . ' new inserts for order ' . $order_id);
        } else {
            error_log('InterSoccer: No new inserts for order ' . $order_id . ' (already populated or skipped)');
        }
        
        // If populated (existing or new) and still processing, force complete
        $total_rosters = $existing_rosters + $new_inserts;
        if ($total_rosters > 0 && $new_status === 'processing') {
            $order->update_status('completed', 'Completed via admin process (rosters already populated).');
            $new_status = $order->get_status();
            error_log('InterSoccer: Forced complete for populated order ' . $order_id . ' (total rosters: ' . $total_rosters . ')');
        }
        
        if ($new_status === 'completed' && $initial_status !== 'completed') {
            $completed++;
            error_log('InterSoccer: Order ' . $order_id . ' status changed to completed');
        } else {
            error_log('InterSoccer: Order ' . $order_id . ' status not changed (remains ' . $new_status . ')');
        }
    }

    error_log('InterSoccer: Completed processing. Processed ' . $processed . ' orders, total inserts: ' . $inserted . ', completed: ' . $completed);
    return ['status' => 'success', 'processed' => $processed, 'inserted' => $inserted, 'completed' => $completed, 'message' => __('Processed ' . $processed . ' orders, inserted ' . $inserted . ' rosters, completed ' . $completed . ' orders.', 'intersoccer-reports-rosters')];
}

add_action('wp_ajax_intersoccer_process_existing_orders', 'intersoccer_process_existing_orders_ajax');
function intersoccer_process_existing_orders_ajax() {
    check_ajax_referer('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to process orders.', 'intersoccer-reports-rosters'));
    }
    error_log('InterSoccer: AJAX process existing orders request received with data: ' . print_r($_POST, true));
    $result = intersoccer_process_existing_orders();
    if ($result['status'] === 'success') {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(['message' => __('Processing failed.', 'intersoccer-reports-rosters')]);
    }
}
?>