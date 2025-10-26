<?php
/**
 * Advanced features page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.20
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
            <p><?php _e('Note: This action adds new columns (e.g., financial fields, girls_only) and backfills data. Use with caution.', 'intersoccer-reports-rosters'); ?></p>
        </div>
        <div class="rebuild-options">
            <h2><?php _e('Roster Management', 'intersoccer-reports-rosters'); ?></h2>
            <form id="intersoccer-process-processing-form" method="post" action="">
                <?php wp_nonce_field('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field'); ?>
                <input type="hidden" name="action" value="intersoccer_process_existing_orders">
                <button type="submit" class="button button-secondary" id="intersoccer-process-processing-button"><?php _e('Process Orders', 'intersoccer-reports-rosters'); ?></button>
            </form>
            <p><?php _e('Note: This will populate missing rosters for existing orders (e.g., processing or on-hold) and complete them if fully populated.', 'intersoccer-reports-rosters'); ?></p>
            <form id="intersoccer-reconcile-form" method="post" action="">
                <?php wp_nonce_field('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field'); ?>
                <input type="hidden" name="action" value="intersoccer_reconcile_rosters">
                <button type="submit" class="button button-secondary" id="intersoccer-reconcile-button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></button>
            </form>
            <p><?php _e('Note: This syncs the rosters table with orders, adding missing entries, updating incomplete data, and removing obsolete ones. No order statuses are changed.', 'intersoccer-reports-rosters'); ?></p>
            <form id="intersoccer-rebuild-signatures-form" method="post" action="">
                <?php wp_nonce_field('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field'); ?>
                <input type="hidden" name="action" value="intersoccer_rebuild_event_signatures">
                <button type="submit" class="button button-secondary" id="intersoccer-rebuild-signatures-button"><?php _e('Rebuild Event Signatures', 'intersoccer-reports-rosters'); ?></button>
            </form>
            <p><?php _e('Note: This will regenerate event signatures for all existing rosters to ensure proper grouping across languages.', 'intersoccer-reports-rosters'); ?></p>
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
                console.log('InterSoccer: Advanced page JS loaded');
                $('#intersoccer-rebuild-form').on('submit', function(e) {
                    e.preventDefault();
                    console.log('InterSoccer: Rebuild form submit triggered');
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
                            console.error('AJAX Raw Response: ', xhr.responseText);
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Rebuild failed: ', 'intersoccer-reports-rosters'); ?>' + (xhr.responseJSON ? xhr.responseJSON.message : (xhr.responseText || error)) + '</p>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
                        }
                    });
                });
                $('#intersoccer-reconcile-form').on('submit', function(e) {
                    e.preventDefault();
                    console.log('InterSoccer: Reconcile form submit triggered');
                    if (!confirm("Are you sure you want to reconcile rosters? This will sync data from orders, potentially updating existing entries.")) {
                        return false;
                    }
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $(this).serialize(),
                        beforeSend: function() {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Reconciling... Please wait.', 'intersoccer-reports-rosters'); ?></p>');
                        },
                        success: function(response) {
                            $('#intersoccer-rebuild-status').html('<p>' + response.data.message + '</p>');
                            console.log('Reconcile response: ', response);
                        },
                        error: function(xhr, status, error) {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Reconcile failed: ', 'intersoccer-reports-rosters'); ?>' + (xhr.responseJSON ? xhr.responseJSON.message : error) + '</p>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
                        }
                    });
                });
                $('#intersoccer-rebuild-signatures-form').on('submit', function(e) {
                    e.preventDefault();
                    console.log('InterSoccer: Rebuild signatures form submit triggered');
                    if (!confirm("Are you sure you want to rebuild event signatures? This will regenerate signatures for all roster records to ensure proper grouping.")) {
                        return false;
                    }
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $(this).serialize(),
                        beforeSend: function() {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Rebuilding event signatures... Please wait.', 'intersoccer-reports-rosters'); ?></p>');
                        },
                        success: function(response) {
                            $('#intersoccer-rebuild-status').html('<p>' + response.data.message + '</p>');
                            console.log('Rebuild signatures response: ', response);
                        },
                        error: function(xhr, status, error) {
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Rebuild signatures failed: ', 'intersoccer-reports-rosters'); ?>' + (xhr.responseJSON ? xhr.responseJSON.message : error) + '</p>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
                        }
                    });
                });
                $('#intersoccer-process-processing-form').on('submit', function(e) {
                    e.preventDefault();
                    console.log('InterSoccer: Process form submit triggered');
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
require_once dirname(__FILE__) . '/utils.php';

/**
 * Wrapper to safely call populate function with output buffering
 */
function intersoccer_safe_populate_rosters($order_id) {
    ob_start();
    try {
        include_once dirname(__FILE__) . '/debug-wrapper.php';
        intersoccer_debug_populate_rosters($order_id);
        $output = ob_get_clean();
        if (!empty($output)) {
            error_log('InterSoccer: Stray output from safe_populate for order ' . $order_id . ': ' . substr($output, 0, 1000));
        } else {
            error_log('InterSoccer: No stray output from safe_populate for order ' . $order_id);
        }
        return true;
    } catch (Exception $e) {
        error_log('InterSoccer: Error in safe_populate for order ' . $order_id . ': ' . $e->getMessage());
        ob_end_clean();
        return false;
    }
}

/**
 * Process existing orders to populate rosters and complete them.
 */
function intersoccer_process_existing_orders() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    error_log('InterSoccer: Starting process existing orders');

    $statuses = ['wc-processing', 'wc-on-hold'];
    if (isset($_POST['include_completed']) && $_POST['include_completed'] === '1') {
        $statuses[] = 'wc-completed';
        error_log('InterSoccer: Including wc-completed orders for processing (debug mode)');
    }

    $orders = wc_get_orders([
        'limit' => -1,
        'status' => $statuses,
    ]);
    error_log('InterSoccer: Found ' . count($orders) . ' eligible orders to process');

    $processed = 0;
    $populated = 0; // Changed from 'inserted' to 'populated'
    $completed = 0;

    try {
        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $initial_status = $order->get_status();
            error_log('InterSoccer: Processing existing order ' . $order_id . ' (initial status: ' . $initial_status . ')');

            // Check if order has any items that need roster entries
            $order_items = $order->get_items();
            $items_needing_rosters = 0;
            $items_with_rosters = 0;
            
            foreach ($order_items as $item_id => $item) {
                // Check if this item should have a roster entry
                $product_id = $item->get_product_id();
                $variation_id = $item->get_variation_id();
                $type_id = $variation_id ?: $product_id;
                
                if (function_exists('intersoccer_get_product_type_safe')) {
                    $product_type = intersoccer_get_product_type_safe($product_id, $variation_id);
                } else {
                    $product_type = intersoccer_get_product_type($type_id);
                }
                
                // Only count items that should have roster entries
                if (in_array($product_type, ['camp', 'course', 'birthday'])) {
                    $assigned_attendee = wc_get_order_item_meta($item_id, 'Assigned Attendee', true);
                    if (!empty($assigned_attendee)) {
                        $items_needing_rosters++;
                        
                        // Check if roster entry already exists
                        $existing_entry = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $rosters_table WHERE order_item_id = %d",
                            $item_id
                        ));
                        
                        if ($existing_entry) {
                            $items_with_rosters++;
                        }
                    }
                }
            }

            error_log('InterSoccer: Order ' . $order_id . ' - Items needing rosters: ' . $items_needing_rosters . ', Items with rosters: ' . $items_with_rosters);

            // If order already has all required roster entries, check if it should be completed
            $all_items_populated = ($items_needing_rosters > 0 && $items_needing_rosters === $items_with_rosters);
            
            if (!$all_items_populated && $items_needing_rosters > 0) {
                // Try to populate missing roster entries
                $populate_success = intersoccer_safe_populate_rosters($order_id);
                
                if ($populate_success) {
                    // Recheck how many items now have rosters
                    $new_items_with_rosters = 0;
                    foreach ($order_items as $item_id => $item) {
                        $existing_entry = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $rosters_table WHERE order_item_id = %d",
                            $item_id
                        ));
                        if ($existing_entry) {
                            $new_items_with_rosters++;
                        }
                    }
                    
                    if ($new_items_with_rosters > $items_with_rosters) {
                        $populated++;
                        error_log('InterSoccer: Successfully populated rosters for order ' . $order_id);
                    }
                    
                    $all_items_populated = ($items_needing_rosters > 0 && $new_items_with_rosters >= $items_needing_rosters);
                }
            }

            // Complete the order if all roster entries are populated
            if ($all_items_populated && $initial_status !== 'completed') {
                $order->update_status('completed', 'Completed via Process Orders (all rosters populated).');
                $completed++;
                error_log('InterSoccer: Completed order ' . $order_id . ' - all ' . $items_needing_rosters . ' roster entries populated');
            } elseif ($all_items_populated) {
                error_log('InterSoccer: Order ' . $order_id . ' already completed with all rosters populated');
            } else {
                error_log('InterSoccer: Order ' . $order_id . ' not completed - ' . $items_with_rosters . '/' . $items_needing_rosters . ' rosters populated');
            }

            if ($items_needing_rosters > 0) {
                $processed++;
            }
        }

        error_log('InterSoccer: Completed processing. Processed ' . $processed . ' orders, populated rosters for ' . $populated . ' orders, completed ' . $completed . ' orders');
        return [
            'status' => 'success',
            'processed' => $processed,
            'populated' => $populated, // Changed from 'inserted'
            'completed' => $completed,
            'message' => __('Processed ' . $processed . ' orders, populated rosters for ' . $populated . ' orders, completed ' . $completed . ' orders.', 'intersoccer-reports-rosters')
        ];
    } catch (Exception $e) {
        error_log('InterSoccer: Process orders failed: ' . $e->getMessage());
        return [
            'status' => 'error',
            'message' => __('Processing failed: ' . $e->getMessage(), 'intersoccer-reports-rosters')
        ];
    }
}

add_action('wp_ajax_intersoccer_process_existing_orders', 'intersoccer_process_existing_orders_ajax');
function intersoccer_process_existing_orders_ajax() {
    ob_start();
    error_log('InterSoccer: Process orders AJAX handler started');
    
    // DEBUG: Log all POST data to see what's being sent
    error_log('InterSoccer: AJAX POST data: ' . print_r($_POST, true));
    
    // Check nonce - try multiple possible nonce field names
    $nonce_valid = false;
    if (isset($_POST['intersoccer_rebuild_nonce_field'])) {
        $nonce_valid = wp_verify_nonce($_POST['intersoccer_rebuild_nonce_field'], 'intersoccer_rebuild_nonce');
        error_log('InterSoccer: Nonce check with intersoccer_rebuild_nonce_field: ' . ($nonce_valid ? 'valid' : 'invalid'));
    } elseif (isset($_POST['nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['nonce'], 'intersoccer_rebuild_nonce');
        error_log('InterSoccer: Nonce check with nonce: ' . ($nonce_valid ? 'valid' : 'invalid'));
    }
    
    if (!$nonce_valid) {
        error_log('InterSoccer: Nonce verification failed');
        ob_clean();
        wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'intersoccer-reports-rosters')]);
        return;
    }
    
    if (!current_user_can('manage_options')) {
        error_log('InterSoccer: User permission check failed');
        ob_clean();
        wp_send_json_error(['message' => __('You do not have permission to process orders.', 'intersoccer-reports-rosters')]);
        return;
    }
    
    error_log('InterSoccer: All checks passed, proceeding with order processing');
    
    $result = intersoccer_process_existing_orders();
    error_log('InterSoccer: Final buffer before JSON send: ' . ob_get_contents());
    ob_clean();
    
    if ($result['status'] === 'success') {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(['message' => $result['message']]);
    }
}

add_action('wp_ajax_intersoccer_move_players', 'intersoccer_move_players_ajax');

function intersoccer_move_players_ajax() {
    // Log start of migration request
    error_log('InterSoccer Migration: Starting player migration request');
    error_log('InterSoccer Migration: POST data: ' . print_r($_POST, true));
    
    check_ajax_referer('intersoccer_move_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        error_log('InterSoccer Migration: Permission denied for user ' . get_current_user_id());
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
    }

    $target_variation_id = intval($_POST['target_variation_id']);
    $order_item_ids = array_map('intval', (array) $_POST['order_item_ids']);

    error_log('InterSoccer Migration: Target variation: ' . $target_variation_id);
    error_log('InterSoccer Migration: Order item IDs: ' . implode(', ', $order_item_ids));

    // Validation
    if (!$target_variation_id || empty($order_item_ids)) {
        error_log('InterSoccer Migration: Invalid input - target_variation_id: ' . $target_variation_id . ', order_item_ids count: ' . count($order_item_ids));
        wp_send_json_error(['message' => __('Invalid input.', 'intersoccer-reports-rosters')]);
    }

    // Validate target variation exists and is available
    $variation = wc_get_product($target_variation_id);
    if (!$variation || !$variation->is_type('variation')) {
        error_log('InterSoccer Migration: Invalid target variation - ID: ' . $target_variation_id . ', exists: ' . ($variation ? 'yes' : 'no') . ', type: ' . ($variation ? $variation->get_type() : 'N/A'));
        wp_send_json_error(['message' => __('Invalid target variation.', 'intersoccer-reports-rosters')]);
    }

    // Check if variation is purchasable
    if (!$variation->is_purchasable()) {
        error_log('InterSoccer Migration: Target variation not purchasable - ID: ' . $target_variation_id);
        wp_send_json_error(['message' => __('Target variation is not available for purchase.', 'intersoccer-reports-rosters')]);
    }

    $moved_count = 0;
    $errors = [];
    global $wpdb;

    foreach ($order_item_ids as $item_id) {
        error_log('InterSoccer Migration: Processing item ID: ' . $item_id);
        
        try {
            // Get order from item
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d", 
                $item_id
            ));
            
            if (!$order_id) {
                $error = 'Order not found for item ' . $item_id;
                error_log('InterSoccer Migration: ' . $error);
                $errors[] = $error;
                continue;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                $error = 'Order object not loaded for order ' . $order_id;
                error_log('InterSoccer Migration: ' . $error);
                $errors[] = $error;
                continue;
            }

            // Find the specific order item
            $item = null;
            foreach ($order->get_items() as $order_item) {
                if ($order_item->get_id() === $item_id) {
                    $item = $order_item;
                    break;
                }
            }
            
            if (!$item) {
                $error = 'Order item not found: ' . $item_id;
                error_log('InterSoccer Migration: ' . $error);
                $errors[] = $error;
                continue;
            }

            // Log original state
            $original_variation_id = $item->get_variation_id();
            $original_product_id = $item->get_product_id();
            $original_subtotal = $item->get_subtotal();
            $original_total = $item->get_total();
            $assigned_attendee = wc_get_order_item_meta($item_id, 'Assigned Attendee', true);
            
            error_log('InterSoccer Migration: Item ' . $item_id . ' original state:');
            error_log('  - Original variation: ' . $original_variation_id);
            error_log('  - Original product: ' . $original_product_id);
            error_log('  - Original subtotal: ' . $original_subtotal);
            error_log('  - Original total: ' . $original_total);
            error_log('  - Assigned attendee: ' . $assigned_attendee);

            // Get target product information
            $new_product_id = $variation->get_parent_id();
            $new_attributes = $variation->get_attributes();
            
            error_log('InterSoccer Migration: Target variation details:');
            error_log('  - New product ID: ' . $new_product_id);
            error_log('  - New attributes: ' . print_r($new_attributes, true));

            // Start transaction-like approach
            $migration_success = true;

            // Step 1: Update the order item variation and product
            try {
                $item->set_product_id($new_product_id);
                $item->set_variation_id($target_variation_id);
                
                // Preserve original pricing
                $item->set_subtotal($original_subtotal);
                $item->set_total($original_total);
                
                error_log('InterSoccer Migration: Updated item variation and preserved pricing');
            } catch (Exception $e) {
                error_log('InterSoccer Migration: Failed to update item: ' . $e->getMessage());
                $migration_success = false;
            }

            // Step 2: Update variation attributes in order item metadata
            if ($migration_success) {
                try {
                    foreach ($new_attributes as $key => $value) {
                        wc_update_order_item_meta($item_id, $key, $value);
                        error_log('InterSoccer Migration: Updated meta ' . $key . ' = ' . $value);
                    }
                    
                    // Preserve critical metadata
                    if (!empty($assigned_attendee)) {
                        wc_update_order_item_meta($item_id, 'Assigned Attendee', $assigned_attendee);
                        error_log('InterSoccer Migration: Preserved Assigned Attendee: ' . $assigned_attendee);
                    }
                } catch (Exception $e) {
                    error_log('InterSoccer Migration: Failed to update metadata: ' . $e->getMessage());
                    $migration_success = false;
                }
            }

            // Step 3: Save the order item and order
            if ($migration_success) {
                try {
                    $item->save();
                    
                    // Minimal order recalculation to preserve pricing
                    $order->calculate_taxes();
                    $order->save();
                    
                    error_log('InterSoccer Migration: Saved order item and order');
                } catch (Exception $e) {
                    error_log('InterSoccer Migration: Failed to save order: ' . $e->getMessage());
                    $migration_success = false;
                }
            }

            // Step 4: Update roster entry
            if ($migration_success) {
                try {
                    // Check if roster update function exists
                    if (function_exists('intersoccer_update_roster_entry')) {
                        intersoccer_update_roster_entry($order_id, $item_id);
                        error_log('InterSoccer Migration: Updated roster entry via intersoccer_update_roster_entry');
                    } else {
                        // Fallback: manual roster update
                        intersoccer_manual_update_roster_entry($order_id, $item_id, $target_variation_id);
                        error_log('InterSoccer Migration: Updated roster entry via manual method');
                    }
                } catch (Exception $e) {
                    error_log('InterSoccer Migration: Failed to update roster: ' . $e->getMessage());
                    $migration_success = false;
                }
            }

            // Step 5: Verify the migration
            if ($migration_success) {
                // Reload and verify
                $updated_item = new WC_Order_Item_Product($item_id);
                $new_variation_id = $updated_item->get_variation_id();
                $preserved_subtotal = $updated_item->get_subtotal();
                $preserved_total = $updated_item->get_total();
                
                if ($new_variation_id == $target_variation_id && 
                    $preserved_subtotal == $original_subtotal && 
                    $preserved_total == $original_total) {
                    
                    $moved_count++;
                    error_log('InterSoccer Migration: Successfully migrated item ' . $item_id);
                    error_log('  - Verification: variation=' . $new_variation_id . ', subtotal=' . $preserved_subtotal . ', total=' . $preserved_total);
                } else {
                    $error = 'Migration verification failed for item ' . $item_id;
                    error_log('InterSoccer Migration: ' . $error);
                    error_log('  - Expected variation: ' . $target_variation_id . ', got: ' . $new_variation_id);
                    error_log('  - Expected subtotal: ' . $original_subtotal . ', got: ' . $preserved_subtotal);
                    error_log('  - Expected total: ' . $original_total . ', got: ' . $preserved_total);
                    $errors[] = $error;
                }
            } else {
                $errors[] = 'Migration failed for item ' . $item_id;
            }

        } catch (Exception $e) {
            $error = 'Exception during migration of item ' . $item_id . ': ' . $e->getMessage();
            error_log('InterSoccer Migration: ' . $error);
            $errors[] = $error;
        }
    }

    // Final logging and response
    error_log('InterSoccer Migration: Completed. Moved: ' . $moved_count . ', Errors: ' . count($errors));
    if (!empty($errors)) {
        error_log('InterSoccer Migration: Errors encountered: ' . implode('; ', $errors));
    }

    if ($moved_count > 0) {
        $message = sprintf(__('Successfully moved %d players.', 'intersoccer-reports-rosters'), $moved_count);
        if (!empty($errors)) {
            $message .= ' ' . sprintf(__('%d errors occurred.', 'intersoccer-reports-rosters'), count($errors));
        }
        wp_send_json_success(['message' => $message]);
    } else {
        wp_send_json_error(['message' => __('No players moved. Check logs for details.', 'intersoccer-reports-rosters')]);
    }
}

/**
 * Manual roster update function as fallback
 */
function intersoccer_manual_update_roster_entry($order_id, $item_id, $target_variation_id) {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    error_log('InterSoccer Migration: Manual roster update for item ' . $item_id);
    
    // Get the new variation product
    $variation = wc_get_product($target_variation_id);
    if (!$variation) {
        throw new Exception('Cannot load target variation ' . $target_variation_id);
    }
    
    $parent_product = wc_get_product($variation->get_parent_id());
    if (!$parent_product) {
        throw new Exception('Cannot load parent product for variation ' . $target_variation_id);
    }
    
    // Extract new roster data from variation
    $new_roster_data = [];
    
    // Get attributes from variation
    $attributes = $variation->get_attributes();
    foreach ($attributes as $key => $value) {
        switch ($key) {
            case 'pa_intersoccer-venues':
                $new_roster_data['venue'] = $value;
                break;
            case 'pa_age-group':
                $new_roster_data['age_group'] = $value;
                break;
            case 'pa_camp-terms':
                $new_roster_data['camp_terms'] = $value;
                break;
            case 'pa_camp-times':
            case 'pa_course-times':
                $new_roster_data['times'] = $value;
                break;
            // Add other mappings as needed
        }
    }
    
    // Get course day from order item metadata or product
    $course_day = wc_get_order_item_meta($item_id, 'Course Day', true);
    if (empty($course_day)) {
        // Try to get from product terms
        $course_days = wc_get_product_terms($parent_product->get_id(), 'pa_course-day', ['fields' => 'names']);
        if (!empty($course_days)) {
            $course_day = $course_days[0];
        }
    }
    if (!empty($course_day)) {
        $new_roster_data['course_day'] = $course_day;
    }
    
    // Update the roster entry
    $update_result = $wpdb->update(
        $rosters_table,
        array_merge($new_roster_data, [
            'variation_id' => $target_variation_id,
            'product_id' => $variation->get_parent_id(),
            'product_name' => $parent_product->get_name()
        ]),
        ['order_item_id' => $item_id],
        ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'], // format for new data
        ['%d'] // format for where clause
    );
    
    if ($update_result === false) {
        throw new Exception('Database update failed for roster entry');
    }
    
    error_log('InterSoccer Migration: Updated roster entry with new data: ' . print_r($new_roster_data, true));
}
?>