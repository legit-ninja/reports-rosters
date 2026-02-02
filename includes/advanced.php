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
 * Render the Advanced Features page with tabs.
 */
function intersoccer_render_advanced_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    $current_version = get_option('intersoccer_db_version', '1.0.0');
    $oop_enabled = defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('database');
    $engine_label = $oop_enabled ? __('OOP Migrator', 'intersoccer-reports-rosters') : __('Legacy Migrator', 'intersoccer-reports-rosters');
    
    // Get active tab from URL or default to 'general'
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
    ?>
    <div class="wrap intersoccer-reports-rosters-settings">
        <h1><?php _e('InterSoccer Settings', 'intersoccer-reports-rosters'); ?></h1>
        
        <!-- Tab Navigation -->
        <nav class="nav-tab-wrapper">
            <a href="?page=intersoccer-advanced&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                <?php _e('General', 'intersoccer-reports-rosters'); ?>
            </a>
            <a href="?page=intersoccer-advanced&tab=advanced" class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Advanced', 'intersoccer-reports-rosters'); ?>
            </a>
            <a href="?page=intersoccer-advanced&tab=tools" class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Tools', 'intersoccer-reports-rosters'); ?>
            </a>
            <a href="?page=intersoccer-advanced&tab=edit-rosters" class="nav-tab <?php echo $active_tab === 'edit-rosters' ? 'nav-tab-active' : ''; ?>">
                <?php _e('Edit Rosters', 'intersoccer-reports-rosters'); ?>
            </a>
        </nav>

        <!-- Status Messages Container -->
        <div id="intersoccer-operation-status" style="margin: 20px 0 0 0;"></div>
        
        <style>
            .tab-content {
                margin-top: 20px;
            }
            .tab-content h2 {
                margin-top: 0;
            }
            .settings-section {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .settings-section h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #e5e5e5;
            }
            .settings-section form {
                margin: 15px 0;
            }
            .settings-section p.description {
                color: #646970;
                font-style: italic;
            }
        </style>

        <!-- General Tab -->
        <?php if ($active_tab === 'general') : ?>
            <div class="tab-content tab-general">
                <h2><?php _e('General Settings', 'intersoccer-reports-rosters'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Schema Version', 'intersoccer-reports-rosters'); ?></th>
                        <td>
                            <code><?php echo esc_html($current_version); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Migration Engine', 'intersoccer-reports-rosters'); ?></th>
                        <td>
                            <code><?php echo esc_html($engine_label); ?></code>
                        </td>
                    </tr>
                </table>
                <p class="description">
                    <?php _e('Database operations and advanced tools are available in the Advanced and Tools tabs.', 'intersoccer-reports-rosters'); ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Advanced Tab -->
        <?php if ($active_tab === 'advanced') : ?>
            <div class="tab-content tab-advanced">
                <div class="advanced-options settings-section">
                    <h2><?php _e('Database Management', 'intersoccer-reports-rosters'); ?></h2>
                    <p><?php _e('Perform database upgrades or maintenance tasks.', 'intersoccer-reports-rosters'); ?></p>
                    <form id="intersoccer-upgrade-form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="upgrade-form">
                        <input type="hidden" name="action" value="intersoccer_upgrade_database">
                        <?php wp_nonce_field('intersoccer_rebuild_nonce', 'nonce'); ?>
                        <input type="submit" name="upgrade_database" class="button button-primary" value="<?php _e('Upgrade Database', 'intersoccer-reports-rosters'); ?>" onclick="return confirm('<?php echo esc_js(__('This will modify the database structure and backfill data. Are you sure?', 'intersoccer-reports-rosters')); ?>');">
                    </form>
                    <p><?php _e('Note: This action adds new columns (e.g., financial fields, girls_only) and backfills data. Use with caution.', 'intersoccer-reports-rosters'); ?></p>
                </div>
                <div class="rebuild-options">
                    <h2><?php _e('Roster Management', 'intersoccer-reports-rosters'); ?></h2>
                    <form id="intersoccer-process-processing-form" method="post" action="">
                        <?php wp_nonce_field('intersoccer_rebuild_nonce', 'nonce'); ?>
                        <input type="hidden" name="action" value="intersoccer_process_existing_orders">
                        <button type="submit" class="button button-secondary" id="intersoccer-process-processing-button"><?php _e('Process Orders', 'intersoccer-reports-rosters'); ?></button>
                    </form>
                    <p><?php _e('Note: This will populate missing rosters for existing orders (e.g., processing or on-hold) and complete them if fully populated.', 'intersoccer-reports-rosters'); ?></p>
                    <form id="intersoccer-reconcile-form" method="post" action="">
                        <?php wp_nonce_field('intersoccer_reports_rosters_nonce', 'nonce'); ?>
                        <input type="hidden" name="action" value="intersoccer_reconcile_rosters">
                        <button type="submit" class="button button-secondary" id="intersoccer-reconcile-button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></button>
                    </form>
                    <p><?php _e('Note: This syncs the rosters table with orders, adding missing entries, updating incomplete data, and removing obsolete ones. No order statuses are changed.', 'intersoccer-reports-rosters'); ?></p>
                    <form id="intersoccer-rebuild-signatures-form" method="post" action="">
                        <?php wp_nonce_field('intersoccer_rebuild_nonce', 'nonce'); ?>
                        <input type="hidden" name="action" value="intersoccer_rebuild_event_signatures">
                        <button type="submit" class="button button-secondary" id="intersoccer-rebuild-signatures-button"><?php _e('Rebuild Event Signatures', 'intersoccer-reports-rosters'); ?></button>
                    </form>
                    <p><?php _e('Note: This will regenerate event signatures for all existing rosters to ensure proper grouping across languages.', 'intersoccer-reports-rosters'); ?></p>
                    <form id="intersoccer-rebuild-form" method="post" action="">
                        <?php wp_nonce_field('intersoccer_rebuild_nonce', 'nonce'); ?>
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

                <?php intersoccer_render_placeholder_management_section(); ?>
            </div>
        <?php endif; ?>

        <!-- Tools Tab -->
        <?php if ($active_tab === 'tools') : ?>
            <div class="tab-content tab-tools">
                <?php intersoccer_render_signature_verifier_section(); ?>
            </div>
        <?php endif; ?>

        <!-- Edit Rosters Tab -->
        <?php if ($active_tab === 'edit-rosters') : ?>
            <div class="tab-content tab-edit-rosters">
                <?php intersoccer_render_roster_editor_tab(); ?>
            </div>
        <?php endif; ?>

        <script type="text/javascript">
            // Helper function to show WordPress admin notices (global scope)
            function showAdminNotice(message, type) {
                var $ = jQuery;
                type = type || 'info'; // success, error, warning, info
                var noticeClass = 'notice notice-' + type + ' is-dismissible';
                var notice = $('<div class="' + noticeClass + '"><p><strong>' + message + '</strong></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
                
                $('#intersoccer-operation-status').html(notice);
                
                // Auto-dismiss after 10 seconds for success/info, 15 seconds for warnings/errors
                var dismissDelay = (type === 'success' || type === 'info') ? 10000 : 15000;
                setTimeout(function() {
                    notice.fadeOut(function() {
                        $(this).remove();
                    });
                }, dismissDelay);
                
                // Handle manual dismiss
                notice.find('.notice-dismiss').on('click', function() {
                    notice.fadeOut(function() {
                        $(this).remove();
                    });
                });
                
                // Scroll to notice
                $('html, body').animate({
                    scrollTop: $('#intersoccer-operation-status').offset().top - 50
                }, 300);
            }
            
            jQuery(document).ready(function($) {
                console.log('InterSoccer: Advanced page JS loaded');
                
                // Rebuild Rosters
                $('#intersoccer-rebuild-form').on('submit', function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var $button = $form.find('button[type="submit"]');
                    
                    if (!confirm("<?php echo esc_js(__('Are you sure you want to rebuild the rosters table? This will delete all existing data in the table, recreate it from current WooCommerce orders. This is a last resort action and may cause temporary data inconsistencies until completed.', 'intersoccer-reports-rosters')); ?>")) {
                        return false;
                    }
                    
                    showAdminNotice('<?php echo esc_js(__('Starting: Rebuilding rosters...', 'intersoccer-reports-rosters')); ?>', 'info');
                    $button.prop('disabled', true).text('<?php echo esc_js(__('Running...', 'intersoccer-reports-rosters')); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $form.serialize(),
                        success: function(response) {
                            if (response.success) {
                                showAdminNotice('<?php echo esc_js(__('Finished: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Rosters rebuilt successfully.', 'intersoccer-reports-rosters')); ?>'), 'success');
                            } else {
                                showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>'), 'error');
                            }
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Rebuild Rosters', 'intersoccer-reports-rosters')); ?>');
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                                ? xhr.responseJSON.data.message 
                                : (xhr.responseText || error || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>');
                            showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + errorMsg, 'error');
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Rebuild Rosters', 'intersoccer-reports-rosters')); ?>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
                        }
                    });
                });
                
                // Reconcile Rosters
                $('#intersoccer-reconcile-form').on('submit', function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var $button = $form.find('button[type="submit"]');
                    
                    if (!confirm("<?php echo esc_js(__('Are you sure you want to reconcile rosters? This will sync data from orders, potentially updating existing entries.', 'intersoccer-reports-rosters')); ?>")) {
                        return false;
                    }
                    
                    showAdminNotice('<?php echo esc_js(__('Starting: Reconciling rosters...', 'intersoccer-reports-rosters')); ?>', 'info');
                    $button.prop('disabled', true).text('<?php echo esc_js(__('Running...', 'intersoccer-reports-rosters')); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $form.serialize(),
                        success: function(response) {
                            if (response.success) {
                                showAdminNotice('<?php echo esc_js(__('Finished: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Rosters reconciled successfully.', 'intersoccer-reports-rosters')); ?>'), 'success');
                            } else {
                                showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>'), 'error');
                            }
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Reconcile Rosters', 'intersoccer-reports-rosters')); ?>');
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                                ? xhr.responseJSON.data.message 
                                : (xhr.responseText || error || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>');
                            showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + errorMsg, 'error');
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Reconcile Rosters', 'intersoccer-reports-rosters')); ?>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
                        }
                    });
                });
                
                // Rebuild Event Signatures
                $('#intersoccer-rebuild-signatures-form').on('submit', function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var $button = $form.find('button[type="submit"]');
                    
                    if (!confirm("<?php echo esc_js(__('Are you sure you want to rebuild event signatures? This will regenerate signatures for all roster records to ensure proper grouping.', 'intersoccer-reports-rosters')); ?>")) {
                        return false;
                    }
                    
                    showAdminNotice('<?php echo esc_js(__('Starting: Rebuilding event signatures...', 'intersoccer-reports-rosters')); ?>', 'info');
                    $button.prop('disabled', true).text('<?php echo esc_js(__('Running...', 'intersoccer-reports-rosters')); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $form.serialize(),
                        success: function(response) {
                            if (response.success) {
                                showAdminNotice('<?php echo esc_js(__('Finished: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Event signatures rebuilt successfully.', 'intersoccer-reports-rosters')); ?>'), 'success');
                            } else {
                                showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>'), 'error');
                            }
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Rebuild Event Signatures', 'intersoccer-reports-rosters')); ?>');
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                                ? xhr.responseJSON.data.message 
                                : (xhr.responseText || error || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>');
                            showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + errorMsg, 'error');
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Rebuild Event Signatures', 'intersoccer-reports-rosters')); ?>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
                        }
                    });
                });
                
                // Process Orders
                $('#intersoccer-process-processing-form').on('submit', function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var $button = $form.find('button[type="submit"]');
                    
                    showAdminNotice('<?php echo esc_js(__('Starting: Processing orders...', 'intersoccer-reports-rosters')); ?>', 'info');
                    $button.prop('disabled', true).text('<?php echo esc_js(__('Running...', 'intersoccer-reports-rosters')); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $form.serialize(),
                        success: function(response) {
                            if (response.success) {
                                showAdminNotice('<?php echo esc_js(__('Finished: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Orders processed successfully.', 'intersoccer-reports-rosters')); ?>'), 'success');
                            } else {
                                showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>'), 'error');
                            }
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Process Orders', 'intersoccer-reports-rosters')); ?>');
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                                ? xhr.responseJSON.data.message 
                                : (xhr.responseText || error || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>');
                            showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + errorMsg, 'error');
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Process Orders', 'intersoccer-reports-rosters')); ?>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
                        }
                    });
                });
                
                // Upgrade Database
                $('#intersoccer-upgrade-form').on('submit', function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var $button = $form.find('input[type="submit"]');
                    
                    showAdminNotice('<?php echo esc_js(__('Starting: Upgrading database...', 'intersoccer-reports-rosters')); ?>', 'info');
                    $button.prop('disabled', true).val('<?php echo esc_js(__('Running...', 'intersoccer-reports-rosters')); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $form.serialize(),
                        success: function(response) {
                            if (response.success) {
                                showAdminNotice('<?php echo esc_js(__('Finished: Database upgrade completed successfully.', 'intersoccer-reports-rosters')); ?>', 'success');
                            } else {
                                showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>'), 'error');
                            }
                            $button.prop('disabled', false).val('<?php echo esc_js(__('Upgrade Database', 'intersoccer-reports-rosters')); ?>');
                        },
                        error: function(xhr, status, error) {
                            var errorMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                                ? xhr.responseJSON.data.message 
                                : (xhr.responseText || error || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>');
                            showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + errorMsg, 'error');
                            $button.prop('disabled', false).val('<?php echo esc_js(__('Upgrade Database', 'intersoccer-reports-rosters')); ?>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
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
 * Wrapper to safely populate rosters for an order.
 * OOP-only: delegates to OrderProcessor.
 */
function intersoccer_safe_populate_rosters($order_id) {
    ob_start();
    try {
        if (function_exists('intersoccer_oop_process_order')) {
            $result = intersoccer_oop_process_order($order_id);
            ob_end_clean();
            return (bool) $result;
        }
        ob_end_clean();
        return false;
    } catch (\Throwable $e) {
        error_log('InterSoccer: Error in safe_populate for order ' . $order_id . ': ' . $e->getMessage());
        ob_end_clean();
        return false;
    }
}

/**
 * Process existing orders to populate rosters and complete them.
 */
function intersoccer_process_existing_orders() {
    if (defined('INTERSOCCER_OOP_ACTIVE') && INTERSOCCER_OOP_ACTIVE && function_exists('intersoccer_use_oop_for') && intersoccer_use_oop_for('orders')) {
        try {
            $orders = wc_get_orders([
                'limit' => -1,
                'status' => ['processing', 'on-hold', 'completed'],
                'return' => 'ids',
            ]);

            $result = intersoccer_oop_process_orders_batch($orders);

            return [
                'status' => !empty($result['success']) ? 'success' : 'error',
                'processed' => $result['processed_orders'] ?? 0,
                'completed' => $result['completed_orders'] ?? 0,
                'failed' => count($result['failed_orders'] ?? []),
                'roster_entries' => $result['roster_entries'] ?? 0,
                'message' => $result['message'] ?? (
                    !empty($result['success'])
                        ? __('Processed orders via OOP OrderProcessor. Check logs for per-order details.', 'intersoccer-reports-rosters')
                        : __('OOP order processing failed. Check logs for details.', 'intersoccer-reports-rosters')
                ),
            ];

        } catch (\Exception $e) {
            error_log('InterSoccer (OOP Orders): Batch processing exception - ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => __('OOP processing failed: ', 'intersoccer-reports-rosters') . $e->getMessage(),
            ];
        }
    }

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
                if (in_array($product_type, ['camp', 'course', 'birthday', 'tournament'])) {
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

if (!function_exists('intersoccer_migration_format_attribute_value')) {
    /**
     * Convert attribute values (slugs) into human-friendly labels.
     *
     * @param string $taxonomy Attribute taxonomy (e.g. pa_intersoccer-venues).
     * @param mixed  $value    Attribute value (slug or array of slugs).
     * @return string
     */
    function intersoccer_migration_format_attribute_value($taxonomy, $value) {
        if (is_array($value)) {
            $raw_values = $value;
        } else {
            $raw_values = array_map('trim', explode(',', (string) $value));
        }

        $raw_values = array_filter($raw_values, static function ($val) {
            return $val !== '' && $val !== null;
        });

        if (empty($raw_values)) {
            return '';
        }

        // Special handling for girls-only taxonomy where slugs may not map cleanly to term names.
        if ($taxonomy === 'pa_girls-only') {
            $slug = strtolower(reset($raw_values));
            if (in_array($slug, ['girls-only', 'yes', 'girls'], true)) {
                return __('Yes', 'intersoccer-reports-rosters');
            }
            if (in_array($slug, ['mixed', 'no'], true)) {
                return __('No', 'intersoccer-reports-rosters');
            }
        }

        if (taxonomy_exists($taxonomy)) {
            $names = [];
            foreach ($raw_values as $slug) {
                $term = get_term_by('slug', $slug, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $names[] = $term->name;
                } else {
                    $names[] = ucwords(str_replace(['-', '_'], ' ', (string) $slug));
                }
            }
            return implode(', ', array_unique($names));
        }

        return implode(', ', array_map(static function ($val) {
            return ucwords(str_replace(['-', '_'], ' ', (string) $val));
        }, $raw_values));
    }
}

if (!function_exists('intersoccer_migration_attribute_label_map')) {
    /**
     * Map attribute taxonomies to order item meta keys that should mirror the value.
     *
     * @param string $taxonomy
     * @return array
     */
    function intersoccer_migration_attribute_label_map($taxonomy) {
        static $map = [
            'pa_intersoccer-venues' => ['InterSoccer Venues', 'Sites InterSoccer'],
            'pa_age-group'          => ['Age Group'],
            'pa_camp-terms'         => ['Camp Terms'],
            'pa_course-day'         => ['Course Day'],
            'pa_course-times'       => ['Course Times'],
            'pa_camp-times'         => ['Camp Times'],
            'pa_activity-type'      => ['Activity Type'],
            'pa_program-season'     => ['Season'],
            'pa_booking-type'       => ['Booking Type'],
            'pa_canton-region'      => ['Canton / Region'],
            'pa_city'               => ['City'],
            'pa_girls-only'         => ['Girls Only'],
        ];

        return $map[$taxonomy] ?? [];
    }
}

if (!function_exists('intersoccer_migration_human_alias_map')) {
    /**
     * Map canonical metadata identifiers to their human-readable key variants.
     *
     * @return array<string,array<int,string>>
     */
    function intersoccer_migration_human_alias_map() {
        return [
            'intersoccer_venues' => [
                'InterSoccer Venues',
                'Sites InterSoccer',
                'Lieux InterSoccer',
            ],
            'age_group' => [
                'Age Group',
                "Groupe d'âge",
                'Groupe dage',
            ],
            'course_day' => [
                'Course Day',
                'Jour de cours',
            ],
            'course_times' => [
                'Course Times',
                'Horaires du cours',
            ],
            'camp_times' => [
                'Camp Times',
                'Horaires du camp',
            ],
            'activity_type' => [
                'Activity Type',
                "Type d'activité",
            ],
            'season' => [
                'Season',
                'Saison',
            ],
            'booking_type' => [
                'Booking Type',
                'Type de réservation',
            ],
            'canton_region' => [
                'Canton / Region',
                'Canton / Région',
            ],
            'city' => [
                'City',
                'Ville',
            ],
        ];
    }
}

if (!function_exists('intersoccer_migration_normalize_meta_key')) {
    /**
     * Normalize a metadata key for comparison (lowercase, remove accents/punctuation).
     */
    function intersoccer_migration_normalize_meta_key($key) {
        if (function_exists('intersoccer_normalize_meta_key_for_lookup')) {
            // Shared implementation (keeps migration + OOP ingestion aligned).
            return intersoccer_normalize_meta_key_for_lookup($key);
        }

        // Fallback: previous behavior
        $normalized = strtolower(remove_accents((string) $key));
        $normalized = str_replace(['attribute_', '_'], ['', '-'], $normalized);
        $normalized = preg_replace('/[^a-z0-9\- ]+/u', '', $normalized);
        $normalized = preg_replace('/\s+/', '-', trim($normalized));
        return $normalized;
    }
}

if (!function_exists('intersoccer_migration_build_lookup')) {
    /**
     * Build a lookup from normalized key to canonical identifier.
     *
     * @param array<string,array<int,string>> $alias_map
     * @return array<string,string>
     */
    function intersoccer_migration_build_lookup(array $alias_map) {
        $lookup = [];
        foreach ($alias_map as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[intersoccer_migration_normalize_meta_key($alias)] = $canonical;
            }
            // Ensure canonical itself is recognised
            $lookup[intersoccer_migration_normalize_meta_key($canonical)] = $canonical;
        }
        return $lookup;
    }
}

if (!function_exists('intersoccer_migration_detect_existing_human_meta')) {
    /**
     * Determine which human-readable key a given order item already uses for each canonical alias.
     *
     * @param \WC_Order_Item_Product $item
     * @param array<string,array<int,string>> $alias_map
     * @param array<string,string> $lookup
     * @return array<string,string>
     */
    function intersoccer_migration_detect_existing_human_meta($item, array $alias_map, array $lookup) {
        $existing = [];
        foreach ($item->get_meta_data() as $meta) {
            $normalized = intersoccer_migration_normalize_meta_key($meta->key);
            if (isset($lookup[$normalized])) {
                $canonical = $lookup[$normalized];
                // Prefer the first encountered key (likely the language originally used)
                if (!isset($existing[$canonical])) {
                    $existing[$canonical] = $meta->key;
                }
            }
        }
        return $existing;
    }
}

if (!function_exists('intersoccer_migration_remove_human_meta')) {
    /**
     * Remove all human-readable metadata aliases for the provided item.
     */
    function intersoccer_migration_remove_human_meta($item, array $alias_map) {
        foreach ($alias_map as $canonical => $aliases) {
            $keys = array_unique(array_merge([$canonical], $aliases));
            foreach ($keys as $key) {
                $item->delete_meta_data($key);
            }
        }
    }
}

if (!function_exists('intersoccer_migration_cleanup_item_meta')) {
    /**
     * Remove duplicate human-readable metadata entries, keeping the most recently written value.
     *
     * @param int $item_id
     * @param array<string,array<int,string>> $alias_map
     * @param array<string,string> $lookup
     */
    function intersoccer_migration_cleanup_item_meta($item_id, array $alias_map, array $lookup) {
        $item = new WC_Order_Item_Product($item_id);
        $meta_data = array_reverse($item->get_meta_data());
        $seen = [];

        foreach ($meta_data as $meta) {
            $normalized = intersoccer_migration_normalize_meta_key($meta->key);
            if (!isset($lookup[$normalized])) {
                continue;
            }
            $canonical = $lookup[$normalized];
            if (isset($seen[$canonical])) {
                wc_delete_order_item_meta($item_id, $meta->key, $meta->value);
                continue;
            }
            $seen[$canonical] = $meta->key;
        }
    }
}

if (!function_exists('intersoccer_migration_prune_meta_rows')) {
    /**
     * Ensure only one row per canonical field remains at the database level.
     */
    function intersoccer_migration_prune_meta_rows($item_id, array $alias_map, array $lookup) {
        global $wpdb;

        $canonical_to_keys = array();
        foreach ($alias_map as $canonical => $aliases) {
            $canonical_to_keys[$canonical] = array_unique(array_merge(array($canonical), $aliases));
        }

        $table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        foreach ($canonical_to_keys as $canonical => $keys) {
            $placeholders = implode(',', array_fill(0, count($keys), '%s'));
            $params = array_merge(array($item_id), $keys);

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, meta_key, meta_value FROM {$table} WHERE order_item_id = %d AND meta_key IN ($placeholders) ORDER BY meta_id ASC",
                $params
            )) ?: array();

            $chosen_meta_id = null;
            foreach ($rows as $row) {
                $normalized = intersoccer_migration_normalize_meta_key($row->meta_key);
                if ($normalized !== $canonical && (!isset($lookup[$normalized]) || $lookup[$normalized] !== $canonical)) {
                    continue;
                }

                if ($chosen_meta_id === null) {
                    $chosen_meta_id = (int) $row->meta_id;
                    $chosen_value = $row->meta_value;
                    $chosen_key = $row->meta_key;
                } else {
                    $wpdb->delete($table, array('meta_id' => (int) $row->meta_id), array('%d'));
                }
            }

            if ($chosen_meta_id !== null) {
                $wpdb->update(
                    $table,
                    array('meta_key' => $chosen_key, 'meta_value' => $chosen_value),
                    array('meta_id' => $chosen_meta_id),
                    array('%s', '%s'),
                    array('%d')
                );
            }
        }
    }
}

if (!function_exists('intersoccer_migration_restore_canonical_meta')) {
    /**
     * Reapply canonical checkout metadata after WooCommerce finishes its own replay.
     *
     * @param int   $item_id
     * @param array $human_alias_map
     * @param array $human_lookup
     * @param array $human_meta
     * @param array $other_meta
     */
    function intersoccer_migration_restore_canonical_meta(
        $item_id,
        array $human_alias_map,
        array $human_lookup,
        array $human_meta,
        array $other_meta
    ) {
        $item = new WC_Order_Item_Product($item_id);

        foreach ($human_meta as $canonical => $meta_value) {
            $normalized_value = is_array($meta_value)
                ? implode(', ', array_map('trim', $meta_value))
                : trim((string) $meta_value);

            if ($normalized_value === '') {
                continue;
            }

            $aliases = $human_alias_map[$canonical] ?? [$canonical];
            foreach ($aliases as $alias_key) {
                $item->delete_meta_data($alias_key);
            }

            $item->add_meta_data($aliases[0], $normalized_value, true);
        }

        foreach ($other_meta as $meta_key => $meta_value) {
            $normalized_value = is_array($meta_value)
                ? implode(', ', array_map('trim', $meta_value))
                : trim((string) $meta_value);

            $item->delete_meta_data($meta_key);
            if ($normalized_value === '') {
                continue;
            }

            $item->add_meta_data($meta_key, $normalized_value, true);
        }

        $item->save();

        // Final dedupe to ensure WooCommerce (or other hooks) didn’t introduce duplicates
        intersoccer_migration_cleanup_item_meta($item_id, $human_alias_map, $human_lookup);
        intersoccer_migration_prune_meta_rows($item_id, $human_alias_map, $human_lookup);

        error_log('InterSoccer Migration: Restored canonical metadata for item ' . $item_id);
    }
}

if (!function_exists('intersoccer_build_migration_meta_updates')) {
    /**
     * Build the complete set of order item metadata updates for a variation migration.
     *
     * @param \WC_Product_Variation $variation
     * @return array{meta: array<string,string>, slugs: array<string,string>, product_type: string}
     */
    function intersoccer_build_migration_meta_updates(\WC_Product_Variation $variation) {
        $slug_meta   = array();
        $human_meta  = array();
        $other_meta  = array();
        $expected_slugs = array();

        $human_alias_map = intersoccer_migration_human_alias_map();
        $human_lookup    = intersoccer_migration_build_lookup($human_alias_map);
        $taxonomy_canonical_map = array(
            'pa_intersoccer-venues' => 'intersoccer_venues',
            'pa_age-group'          => 'age_group',
            'pa_camp-terms'         => 'camp_terms',
            'pa_course-day'         => 'course_day',
            'pa_course-times'       => 'course_times',
            'pa_camp-times'         => 'camp_times',
            'pa_activity-type'      => 'activity_type',
            'pa_program-season'     => 'season',
            'pa_booking-type'       => 'booking_type',
            'pa_canton-region'      => 'canton_region',
            'pa_city'               => 'city',
            'pa_girls-only'         => 'girls_only',
        );

        $variation_attributes = $variation->get_variation_attributes();
        foreach ($variation_attributes as $attribute_key => $attribute_value) {
            $clean_key = str_replace('attribute_', '', $attribute_key);
            $slug_meta[$attribute_key] = $attribute_value;
            $slug_meta[$clean_key] = $attribute_value;
            $expected_slugs[$clean_key] = $attribute_value;

            $display_value = intersoccer_migration_format_attribute_value($clean_key, $attribute_value);

            if (isset($taxonomy_canonical_map[$clean_key])) {
                $canonical = $taxonomy_canonical_map[$clean_key];
                if ($canonical === 'girls_only') {
                    $human_meta[$canonical] = $display_value;
                } else {
                    $human_meta[$canonical] = $display_value;
                }
            }
        }

        if (function_exists('intersoccer_get_parent_product_attributes')) {
            $parent_attributes = intersoccer_get_parent_product_attributes($variation->get_parent_id(), $variation->get_id());
            foreach ($parent_attributes as $label => $value) {
                $normalized_label = intersoccer_migration_normalize_meta_key($label);
                $string_value = is_array($value) ? implode(', ', $value) : (string) $value;

                if (isset($human_lookup[$normalized_label])) {
                    $canonical = $human_lookup[$normalized_label];
                    $human_meta[$canonical] = $string_value;
                } else {
                    $other_meta[$label] = $string_value;
                }
            }
        }

        $product_type = '';
        if (function_exists('intersoccer_get_product_type')) {
            $product_type = intersoccer_get_product_type($variation->get_parent_id());
        }

        if ($product_type === 'course' && function_exists('intersoccer_get_course_meta')) {
            $start_date = intersoccer_get_course_meta($variation->get_id(), '_course_start_date', '');
            if (!empty($start_date)) {
                $other_meta['Start Date'] = date_i18n('F j, Y', strtotime($start_date));
            }

            $end_date = intersoccer_get_course_meta($variation->get_id(), '_end_date', '');
            if (!empty($end_date)) {
                $other_meta['End Date'] = date_i18n('F j, Y', strtotime($end_date));
            }

            $holidays = intersoccer_get_course_meta($variation->get_id(), '_course_holiday_dates', []);
            if (!empty($holidays) && is_array($holidays)) {
                $formatted_holidays = array_map(static function ($date) {
                    return date_i18n('F j, Y', strtotime($date));
                }, $holidays);
                $other_meta['Holidays'] = implode(', ', $formatted_holidays);
            } else {
                $other_meta['Holidays'] = '';
            }
        }

        if (!empty($product_type)) {
            $human_meta['activity_type'] = ucfirst($product_type);
        }

        if (function_exists('intersoccer_get_product_season')) {
            $season = intersoccer_get_product_season($variation->get_parent_id());
            if (!empty($season)) {
                $human_meta['season'] = $season;
            }
        }

        $other_meta['Variation ID'] = $variation->get_id();

        return array(
            'slug_meta'  => $slug_meta,
            'human_meta' => $human_meta,
            'other_meta' => $other_meta,
            'slugs'      => $expected_slugs,
            'product_type' => $product_type,
        );
    }
}

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
    $allow_cross_gender = isset($_POST['allow_cross_gender']) && $_POST['allow_cross_gender'] === '1';

    error_log('InterSoccer Migration: Target variation: ' . $target_variation_id);
    error_log('InterSoccer Migration: Order item IDs: ' . implode(', ', $order_item_ids));
    error_log('InterSoccer Migration: Allow cross-gender: ' . ($allow_cross_gender ? 'YES' : 'NO'));

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
            foreach ($order->get_items('line_item') as $order_item) {
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
            $new_attributes = $variation->get_variation_attributes();
            $meta_payload   = intersoccer_build_migration_meta_updates($variation);
            $slug_meta      = $meta_payload['slug_meta'];
            $human_meta     = $meta_payload['human_meta'];
            $other_meta     = $meta_payload['other_meta'];
            $human_alias_map = intersoccer_migration_human_alias_map();
            $human_lookup    = intersoccer_migration_build_lookup($human_alias_map);
            
            // Get product type for metadata repopulation
            if (function_exists('intersoccer_get_product_type')) {
                $new_product_type = intersoccer_get_product_type($new_product_id);
            } else {
                $new_product_type = '';
            }
            
            error_log('InterSoccer Migration: Target variation details:');
            error_log('  - New product ID: ' . $new_product_id);
            error_log('  - New product type: ' . ($new_product_type ?: 'unknown'));
            error_log('  - New attributes: ' . print_r($new_attributes, true));

            // Start transaction-like approach
            $migration_success = true;

            // Step 1: Update the order item variation and product
            try {
                $item->set_product_id($new_product_id);
                $item->set_variation_id($target_variation_id);
                $item->set_name($variation->get_name());
                
                // Preserve original pricing
                $item->set_subtotal($original_subtotal);
                $item->set_total($original_total);
                
                error_log('InterSoccer Migration: Updated item variation and preserved pricing');
            } catch (Exception $e) {
                error_log('InterSoccer Migration: Failed to update item: ' . $e->getMessage());
                $migration_success = false;
            }

            // Step 2: Clear metadata except attendee fields; we'll rebuild after WooCommerce save
            $pending_restore = false;
            if ($migration_success) {
                try {
                    // Delete all existing metadata except Assigned Attendee
                    $all_meta = $item->get_meta_data();
                    foreach ($all_meta as $meta) {
                        $key = $meta->get_data()['key'];
                        // Keep Assigned Attendee and related player fields; remove everything else
                        if (!in_array($key, ['Assigned Attendee', 'assigned_player', 'Player Index'])) {
                            $item->delete_meta_data($key);
                        }
                    }
                    error_log('InterSoccer Migration: Cleared all metadata except Assigned Attendee');

                    if (!empty($assigned_attendee)) {
                        $item->update_meta_data('Assigned Attendee', $assigned_attendee);
                        error_log('InterSoccer Migration: Preserved Assigned Attendee: ' . $assigned_attendee);
                    }

                    $pending_restore = true;
                    $GLOBALS['intersoccer_migrated_items'][$item_id] = [
                        'human_alias_map' => $human_alias_map,
                        'human_lookup'    => $human_lookup,
                        'canonical_meta'  => [
                            'slug_meta'  => $slug_meta,
                            'human_meta' => $human_meta,
                            'other_meta' => $other_meta,
                        ],
                        'restoring'       => false,
                    ];
                } catch (Exception $e) {
                    error_log('InterSoccer Migration: Failed to clear metadata: ' . $e->getMessage());
                    $migration_success = false;
                }
            }

            // Step 3: Save the order item (WooCommerce will automatically add variation attributes)
            if ($migration_success) {
                try {
                    $item->save();
                    
                    // Minimal order recalculation to preserve pricing
                    $order->calculate_taxes();
                    $order->save();

                    if ($pending_restore) {
                        intersoccer_migration_restore_canonical_meta(
                            $item_id,
                            $human_alias_map,
                            $human_lookup,
                            $human_meta,
                            $other_meta
                        );
                    }
                    
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

                    if (function_exists('intersoccer_rebuild_event_signature_for_order_item')) {
                        $signature_updated = intersoccer_rebuild_event_signature_for_order_item($item_id);
                        error_log(
                            'InterSoccer Migration: Event signature rebuild for item ' . $item_id . ': ' .
                            ($signature_updated ? 'success' : 'failed')
                        );

                        if ($signature_updated && function_exists('intersoccer_align_event_signature_for_variation')) {
                            $aligned = intersoccer_align_event_signature_for_variation($target_variation_id, $item_id);
                            error_log(
                                'InterSoccer Migration: Event signature alignment for item ' . $item_id . ': ' .
                                ($aligned ? 'matched existing roster' : 'no matching roster found')
                            );
                        }
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
    $attributes = $variation->get_variation_attributes();
    $girls_only_flag = null;
    foreach ($attributes as $attribute_key => $attribute_value) {
        $clean_key = str_replace('attribute_', '', $attribute_key);
        $display_value = intersoccer_migration_format_attribute_value($clean_key, $attribute_value);

        switch ($clean_key) {
            case 'pa_intersoccer-venues':
                $new_roster_data['venue'] = $display_value;
                break;
            case 'pa_age-group':
                $new_roster_data['age_group'] = $display_value;
                break;
            case 'pa_camp-terms':
                $new_roster_data['camp_terms'] = $display_value;
                break;
            case 'pa_camp-times':
            case 'pa_course-times':
                $new_roster_data['times'] = $display_value;
                break;
            case 'pa_activity-type':
                $new_roster_data['activity_type'] = $display_value;
                break;
            case 'pa_girls-only':
                $girls_only_flag = strtolower(is_array($attribute_value) ? reset($attribute_value) : $attribute_value);
                break;
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
    if ($girls_only_flag !== null) {
        $new_roster_data['girls_only'] = in_array($girls_only_flag, ['girls-only', 'yes', 'girls'], true) ? 1 : 0;
    }

    $update_data = [
        'venue'        => $new_roster_data['venue'] ?? '',
        'age_group'    => $new_roster_data['age_group'] ?? '',
        'camp_terms'   => $new_roster_data['camp_terms'] ?? '',
        'times'        => $new_roster_data['times'] ?? '',
        'activity_type'=> $new_roster_data['activity_type'] ?? '',
        'girls_only'   => isset($new_roster_data['girls_only']) ? (int) $new_roster_data['girls_only'] : 0,
        'course_day'   => $new_roster_data['course_day'] ?? '',
        'variation_id' => $target_variation_id,
        'product_id'   => $variation->get_parent_id(),
        'product_name' => $variation->get_name(),
    ];

    $update_result = $wpdb->update(
        $rosters_table,
        $update_data,
        ['order_item_id' => $item_id],
        ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s'],
        ['%d']
    );
    
    if ($update_result === false) {
        throw new Exception('Database update failed for roster entry');
    }
    
    error_log('InterSoccer Migration: Updated roster entry with new data: ' . print_r($new_roster_data, true));
}

/**
 * Render the Event Signature Verifier section
 */
function intersoccer_render_signature_verifier_section() {
    // Handle form submission
    $test_results = [];
    if (isset($_POST['test_signature']) && check_admin_referer('test_event_signature')) {
        $test_results = intersoccer_test_event_signature_generation($_POST);
    }

    ?>
    <div class="signature-verifier-section" style="margin-top: 30px;">
        <h2><?php _e('Event Signature Verifier', 'intersoccer-reports-rosters'); ?></h2>
        
        <!-- Documentation Link -->
        <div style="background: #e7f3ff; border-left: 4px solid #2271b1; padding: 15px; margin: 15px 0;">
            <p style="margin: 0;">
                <strong><?php _e('About Event Signatures:', 'intersoccer-reports-rosters'); ?></strong> 
                <?php _e('Event signatures ensure that the same physical event generates a unique identifier regardless of which language the customer used when purchasing. This prevents roster fragmentation across languages.', 'intersoccer-reports-rosters'); ?>
            </p>
            <p style="margin: 10px 0 0 0;">
                <em><?php _e('For complete technical documentation, see <code>docs/MULTILINGUAL-EVENT-SIGNATURES.md</code> in the plugin repository.', 'intersoccer-reports-rosters'); ?></em>
            </p>
        </div>

        <!-- Test Form -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 15px 0;">
            <h3><?php _e('Test Event Data', 'intersoccer-reports-rosters'); ?></h3>
            <p><?php _e('Select event data from your existing product attributes. The dropdown values show terms in the currently active WPML language.', 'intersoccer-reports-rosters'); ?></p>

            <?php
            // Show current WPML language if active
            if (function_exists('wpml_get_current_language')) {
                $current_wpml_lang = wpml_get_current_language();
                $lang_names = [
                    'en' => 'English',
                    'fr' => 'Français',
                    'de' => 'Deutsch'
                ];
                $lang_display = $lang_names[$current_wpml_lang] ?? $current_wpml_lang;
                ?>
                <div style="background: #e7f3ff; padding: 10px; margin-bottom: 15px; border-left: 4px solid #2271b1;">
                    <strong><?php _e('Current WPML Language:', 'intersoccer-reports-rosters'); ?></strong> 
                    <span style="font-size: 16px; font-weight: bold;"><?php echo esc_html($lang_display . ' (' . $current_wpml_lang . ')'); ?></span>
                    <p style="margin: 5px 0 0 0; font-size: 12px;">
                        <?php _e('Dropdown values are shown in this language. To test different languages, switch the WPML admin language and refresh this page.', 'intersoccer-reports-rosters'); ?>
                    </p>
                </div>
            <?php } ?>

            <form method="post" action="">
                <?php wp_nonce_field('test_event_signature'); ?>

                <!-- Quick Load from Recent Orders -->
                <div style="background: #f0f6fc; border: 1px solid #c3dafe; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                    <h4 style="margin-top: 0;">⚡ <?php _e('Quick Load from Recent Order', 'intersoccer-reports-rosters'); ?></h4>
                    <p><?php _e('Load event data from a recent order for quick testing:', 'intersoccer-reports-rosters'); ?></p>
                    <?php
                    global $wpdb;
                    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
                    $recent_events = $wpdb->get_results("
                        SELECT DISTINCT 
                            event_signature,
                            activity_type,
                            venue,
                            age_group,
                            camp_terms,
                            course_day,
                            times,
                            season,
                            girls_only,
                            product_id,
                            product_name
                        FROM {$rosters_table}
                        WHERE event_signature != ''
                        ORDER BY created_at DESC
                        LIMIT 20
                    ", ARRAY_A);
                    
                    if (!empty($recent_events)) : ?>
                        <select id="quick_load_event" class="regular-text" style="margin-bottom: 10px;">
                            <option value=""><?php _e('-- Select a Recent Event --', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($recent_events as $idx => $event) : ?>
                                <option value="<?php echo esc_attr(json_encode($event)); ?>">
                                    <?php 
                                    $display = $event['activity_type'] . ': ' . $event['venue'];
                                    if (!empty($event['camp_terms'])) {
                                        $display .= ' - ' . substr($event['camp_terms'], 0, 40);
                                    } elseif (!empty($event['course_day'])) {
                                        $display .= ' - ' . $event['course_day'];
                                    }
                                    $display .= ' (' . $event['season'] . ')';
                                    echo esc_html($display);
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="load_event_btn" class="button button-secondary">
                            ↓ <?php _e('Load Selected Event', 'intersoccer-reports-rosters'); ?>
                        </button>
                    <?php else : ?>
                        <p style="color: #666; font-style: italic;"><?php _e('No recent events found. Process some orders first.', 'intersoccer-reports-rosters'); ?></p>
                    <?php endif; ?>
                </div>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="activity_type"><?php _e('Activity Type', 'intersoccer-reports-rosters'); ?></label>
                        </th>
                        <td>
                            <select name="activity_type" id="activity_type" class="regular-text">
                                <option value="Camp" <?php selected($_POST['activity_type'] ?? '', 'Camp'); ?>>Camp</option>
                                <option value="Course" <?php selected($_POST['activity_type'] ?? '', 'Course'); ?>>Course</option>
                                <option value="Birthday" <?php selected($_POST['activity_type'] ?? '', 'Birthday'); ?>>Birthday</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="venue"><?php _e('Venue', 'intersoccer-reports-rosters'); ?></label>
                        </th>
                        <td>
                            <?php
                            $venues = get_terms(['taxonomy' => 'pa_intersoccer-venues', 'hide_empty' => false]);
                            $selected_venue = $_POST['venue'] ?? '';
                            ?>
                            <select name="venue" id="venue" class="regular-text">
                                <option value=""><?php _e('-- Select a Venue --', 'intersoccer-reports-rosters'); ?></option>
                                <?php if (!is_wp_error($venues) && !empty($venues)) : ?>
                                    <?php foreach ($venues as $venue) : ?>
                                        <option value="<?php echo esc_attr($venue->name); ?>" <?php selected($selected_venue, $venue->name); ?>>
                                            <?php echo esc_html($venue->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php _e('Select from existing venues in your system', 'intersoccer-reports-rosters'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="age_group"><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></label>
                        </th>
                        <td>
                            <?php
                            $age_groups = get_terms(['taxonomy' => 'pa_age-group', 'hide_empty' => false]);
                            $selected_age_group = $_POST['age_group'] ?? '';
                            ?>
                            <select name="age_group" id="age_group" class="regular-text">
                                <option value=""><?php _e('-- Select an Age Group --', 'intersoccer-reports-rosters'); ?></option>
                                <?php if (!is_wp_error($age_groups) && !empty($age_groups)) : ?>
                                    <?php foreach ($age_groups as $age_group) : ?>
                                        <option value="<?php echo esc_attr($age_group->name); ?>" <?php selected($selected_age_group, $age_group->name); ?>>
                                            <?php echo esc_html($age_group->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php _e('Select from existing age groups', 'intersoccer-reports-rosters'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="camp_terms"><?php _e('Camp Terms', 'intersoccer-reports-rosters'); ?></label>
                        </th>
                        <td>
                            <?php
                            $camp_terms_list = get_terms(['taxonomy' => 'pa_camp-terms', 'hide_empty' => false]);
                            $selected_camp_terms = $_POST['camp_terms'] ?? '';
                            ?>
                            <select name="camp_terms" id="camp_terms" class="regular-text">
                                <option value=""><?php _e('-- Select Camp Terms (for camps) --', 'intersoccer-reports-rosters'); ?></option>
                                <?php if (!is_wp_error($camp_terms_list) && !empty($camp_terms_list)) : ?>
                                    <?php foreach ($camp_terms_list as $camp_term) : ?>
                                        <option value="<?php echo esc_attr($camp_term->name); ?>" <?php selected($selected_camp_terms, $camp_term->name); ?>>
                                            <?php echo esc_html($camp_term->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php _e('For camps only - leave empty for courses', 'intersoccer-reports-rosters'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="course_day"><?php _e('Course Day', 'intersoccer-reports-rosters'); ?></label>
                        </th>
                        <td>
                            <?php
                            $course_days = get_terms(['taxonomy' => 'pa_course-day', 'hide_empty' => false]);
                            $selected_course_day = $_POST['course_day'] ?? '';
                            ?>
                            <select name="course_day" id="course_day" class="regular-text">
                                <option value=""><?php _e('-- Select Course Day (for courses) --', 'intersoccer-reports-rosters'); ?></option>
                                <?php if (!is_wp_error($course_days) && !empty($course_days)) : ?>
                                    <?php foreach ($course_days as $course_day) : ?>
                                        <option value="<?php echo esc_attr($course_day->name); ?>" <?php selected($selected_course_day, $course_day->name); ?>>
                                            <?php echo esc_html($course_day->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php _e('For courses only - leave empty for camps', 'intersoccer-reports-rosters'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="times"><?php _e('Times', 'intersoccer-reports-rosters'); ?></label>
                        </th>
                        <td>
                            <?php
                            // Try both camp and course times taxonomies
                            $camp_times = get_terms(['taxonomy' => 'pa_camp-times', 'hide_empty' => false]);
                            $course_times = get_terms(['taxonomy' => 'pa_course-times', 'hide_empty' => false]);
                            $all_times = [];
                            if (!is_wp_error($camp_times)) {
                                $all_times = array_merge($all_times, $camp_times);
                            }
                            if (!is_wp_error($course_times)) {
                                $all_times = array_merge($all_times, $course_times);
                            }
                            // Remove duplicates by name
                            $unique_times = [];
                            $seen_names = [];
                            foreach ($all_times as $time) {
                                if (!in_array($time->name, $seen_names)) {
                                    $unique_times[] = $time;
                                    $seen_names[] = $time->name;
                                }
                            }
                            $selected_times = $_POST['times'] ?? '';
                            ?>
                            <select name="times" id="times" class="regular-text">
                                <option value=""><?php _e('-- Select Times --', 'intersoccer-reports-rosters'); ?></option>
                                <?php if (!empty($unique_times)) : ?>
                                    <?php foreach ($unique_times as $time) : ?>
                                        <option value="<?php echo esc_attr($time->name); ?>" <?php selected($selected_times, $time->name); ?>>
                                            <?php echo esc_html($time->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php _e('Select from existing camp/course times', 'intersoccer-reports-rosters'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="season"><?php _e('Season', 'intersoccer-reports-rosters'); ?></label>
                        </th>
                        <td>
                            <?php
                            $seasons = get_terms(['taxonomy' => 'pa_program-season', 'hide_empty' => false]);
                            $selected_season = $_POST['season'] ?? '';
                            ?>
                            <select name="season" id="season" class="regular-text">
                                <option value=""><?php _e('-- Select a Season --', 'intersoccer-reports-rosters'); ?></option>
                                <?php if (!is_wp_error($seasons) && !empty($seasons)) : ?>
                                    <?php foreach ($seasons as $season) : ?>
                                        <option value="<?php echo esc_attr($season->name); ?>" <?php selected($selected_season, $season->name); ?>>
                                            <?php echo esc_html($season->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php _e('Select from existing seasons', 'intersoccer-reports-rosters'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="product_id"><?php _e('Product ID', 'intersoccer-reports-rosters'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="product_id" id="product_id" class="regular-text" 
                                   placeholder="<?php esc_attr_e('e.g., 12345', 'intersoccer-reports-rosters'); ?>" 
                                   value="<?php echo isset($_POST['product_id']) ? esc_attr($_POST['product_id']) : ''; ?>">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="girls_only"><?php _e('Girls Only', 'intersoccer-reports-rosters'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" name="girls_only" id="girls_only" value="1" 
                                   <?php checked(isset($_POST['girls_only'])); ?>>
                            <span class="description"><?php _e('Is this a girls-only event?', 'intersoccer-reports-rosters'); ?></span>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="test_signature" class="button button-primary">
                        <?php _e('Test Signature Generation', 'intersoccer-reports-rosters'); ?>
                    </button>
                </p>
            </form>
        </div>

        <?php if (!empty($test_results)) : ?>
        <!-- Test Results -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 15px 0;">
            <h3><?php _e('Test Results', 'intersoccer-reports-rosters'); ?></h3>

            <!-- Original Data -->
            <div style="margin: 15px 0; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                <h4><?php _e('1. Original Input Data', 'intersoccer-reports-rosters'); ?></h4>
                <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html(json_encode($test_results['original'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
            </div>

            <!-- Normalized Data -->
            <div style="margin: 15px 0; padding: 15px; background: #e7f3ff; border-radius: 4px;">
                <h4><?php _e('2. Normalized Data (English)', 'intersoccer-reports-rosters'); ?></h4>
                <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html(json_encode($test_results['normalized'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                <?php if (!empty($test_results['changes'])) : ?>
                <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
                    <strong><?php _e('Changed Fields:', 'intersoccer-reports-rosters'); ?></strong>
                    <ul style="margin: 5px 0;">
                        <?php foreach ($test_results['changes'] as $field => $change) : ?>
                            <li>
                                <code><?php echo esc_html($field); ?></code>: 
                                <span style="color: #d63638;"><?php echo esc_html($change['from']); ?></span> 
                                → 
                                <span style="color: #00a32a;"><?php echo esc_html($change['to']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else : ?>
                <div style="margin-top: 10px; padding: 10px; background: #d1f0d1; border-left: 4px solid #00a32a;">
                    <strong>✓ <?php _e('No changes needed - data was already in English!', 'intersoccer-reports-rosters'); ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <!-- Generated Signature -->
            <div style="margin: 15px 0; padding: 15px; background: #d1f0d1; border-radius: 4px;">
                <h4><?php _e('3. Generated Event Signature', 'intersoccer-reports-rosters'); ?></h4>
                <div style="font-size: 18px; font-weight: bold; color: #00a32a; background: #fff; padding: 15px; border: 2px solid #00a32a; border-radius: 4px; font-family: monospace;">
                    <?php echo esc_html($test_results['signature']); ?>
                </div>
                <p style="margin-top: 10px;">
                    <strong><?php _e('Important:', 'intersoccer-reports-rosters'); ?></strong> 
                    <?php _e('This signature should be IDENTICAL for the same event in all languages!', 'intersoccer-reports-rosters'); ?>
                </p>
            </div>

            <!-- Signature Components -->
            <div style="margin: 15px 0; padding: 15px; background: #f6f7f7; border-radius: 4px;">
                <h4><?php _e('4. Signature Components', 'intersoccer-reports-rosters'); ?></h4>
                <p><?php _e('The signature is generated from these normalized components:', 'intersoccer-reports-rosters'); ?></p>
                <pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html(json_encode($test_results['components'], JSON_PRETTY_PRINT)); ?></pre>
            </div>
        </div>

        <!-- Testing Instructions -->
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0;">
            <h4 style="margin-top: 0;"><?php _e('Testing Instructions', 'intersoccer-reports-rosters'); ?></h4>
            <ol>
                <li><?php _e('Use the <strong>Quick Load</strong> dropdown to select a recent event, OR manually select event attributes', 'intersoccer-reports-rosters'); ?></li>
                <li><?php _e('Test in <strong>English</strong>: Switch WPML to English, refresh page, select event, and note the generated signature', 'intersoccer-reports-rosters'); ?></li>
                <li><?php _e('Test in <strong>French</strong>: Switch WPML to French, refresh page, select THE SAME event (translated terms), test again', 'intersoccer-reports-rosters'); ?></li>
                <li><?php _e('Test in <strong>German</strong>: Switch WPML to German, refresh page, select THE SAME event (translated terms), test again', 'intersoccer-reports-rosters'); ?></li>
                <li><?php _e('Verify that ALL THREE generate the <strong>IDENTICAL signature</strong>', 'intersoccer-reports-rosters'); ?></li>
            </ol>
            <p><strong><?php _e('If signatures differ:', 'intersoccer-reports-rosters'); ?></strong></p>
            <ul>
                <li><?php _e('Check that taxonomy terms have complete WPML translations', 'intersoccer-reports-rosters'); ?></li>
                <li><?php _e('Verify term names are consistent across languages', 'intersoccer-reports-rosters'); ?></li>
                <li><?php _e('Check debug.log for normalization warnings', 'intersoccer-reports-rosters'); ?></li>
            </ul>
        </div>
        <?php endif; ?>
        
        <!-- JavaScript for Quick Load functionality -->
        <script>
        jQuery(document).ready(function($) {
            console.log('InterSoccer: Event Signature Verifier loaded');
            
            // Handle Quick Load button click
            $('#load_event_btn').on('click', function() {
                var selectedJson = $('#quick_load_event').val();
                
                if (!selectedJson) {
                    alert('<?php _e('Please select an event from the dropdown', 'intersoccer-reports-rosters'); ?>');
                    return;
                }
                
                try {
                    var eventData = JSON.parse(selectedJson);
                    console.log('Loading event data:', eventData);
                    
                    // Populate form fields
                    $('#activity_type').val(eventData.activity_type || 'Camp');
                    
                    // For select fields, try to find matching option by value
                    if (eventData.venue) {
                        $('#venue option').filter(function() {
                            return $(this).val() === eventData.venue;
                        }).prop('selected', true);
                    }
                    
                    if (eventData.age_group) {
                        $('#age_group option').filter(function() {
                            return $(this).val() === eventData.age_group;
                        }).prop('selected', true);
                    }
                    
                    if (eventData.camp_terms) {
                        $('#camp_terms option').filter(function() {
                            return $(this).val() === eventData.camp_terms;
                        }).prop('selected', true);
                    }
                    
                    if (eventData.course_day) {
                        $('#course_day option').filter(function() {
                            return $(this).val() === eventData.course_day;
                        }).prop('selected', true);
                    }
                    
                    if (eventData.times) {
                        $('#times option').filter(function() {
                            return $(this).val() === eventData.times;
                        }).prop('selected', true);
                    }
                    
                    if (eventData.season) {
                        $('#season option').filter(function() {
                            return $(this).val() === eventData.season;
                        }).prop('selected', true);
                    }
                    
                    $('#product_id').val(eventData.product_id || '');
                    $('#girls_only').prop('checked', eventData.girls_only == '1');
                    
                    // Show success message
                    var productName = eventData.product_name || 'Event';
                    alert('✓ Loaded: ' + productName);
                    
                    // Scroll to form
                    $('html, body').animate({
                        scrollTop: $('#activity_type').offset().top - 100
                    }, 500);
                    
                } catch (e) {
                    console.error('Error parsing event data:', e);
                    alert('<?php _e('Error loading event data. Please try again.', 'intersoccer-reports-rosters'); ?>');
                }
            });
            
            console.log('InterSoccer: Quick Load handler attached');
        });
        </script>
    </div>
    <?php
}

/**
 * Test event signature generation
 */
function intersoccer_test_event_signature_generation($post_data) {
    // Sanitize input
    $original_data = [
        'activity_type' => sanitize_text_field($post_data['activity_type'] ?? 'Camp'),
        'venue' => sanitize_text_field($post_data['venue'] ?? ''),
        'age_group' => sanitize_text_field($post_data['age_group'] ?? ''),
        'camp_terms' => sanitize_text_field($post_data['camp_terms'] ?? ''),
        'course_day' => sanitize_text_field($post_data['course_day'] ?? ''),
        'times' => sanitize_text_field($post_data['times'] ?? ''),
        'season' => sanitize_text_field($post_data['season'] ?? ''),
        'girls_only' => isset($post_data['girls_only']),
        'product_id' => intval($post_data['product_id'] ?? 0),
    ];

    // Normalize the data
    $normalized_data = intersoccer_normalize_event_data_for_signature($original_data);

    // Track changes
    $changes = [];
    foreach ($original_data as $key => $original_value) {
        $normalized_value = $normalized_data[$key] ?? $original_value;
        // Convert boolean to string for comparison
        $original_display = is_bool($original_value) ? ($original_value ? '1' : '0') : $original_value;
        $normalized_display = is_bool($normalized_value) ? ($normalized_value ? '1' : '0') : $normalized_value;
        
        if ($original_display !== $normalized_display && !empty($original_display)) {
            $changes[$key] = [
                'from' => $original_display,
                'to' => $normalized_display
            ];
        }
    }

    // Generate signature
    $signature = intersoccer_generate_event_signature($normalized_data);

    // Get signature components (for display)
    $components = [
        'activity_type' => $normalized_data['activity_type'] ?? '',
        'venue_slug' => intersoccer_get_term_slug_by_name($normalized_data['venue'] ?? '', 'pa_intersoccer-venues'),
        'age_group_slug' => intersoccer_get_term_slug_by_name($normalized_data['age_group'] ?? '', 'pa_age-group'),
        'camp_terms' => $normalized_data['camp_terms'] ?? '',
        'course_day_slug' => intersoccer_get_term_slug_by_name($normalized_data['course_day'] ?? '', 'pa_course-day'),
        'times' => $normalized_data['times'] ?? '',
        'season_slug' => intersoccer_get_term_slug_by_name($normalized_data['season'] ?? '', 'pa_program-season'),
        'girls_only' => $normalized_data['girls_only'] ? '1' : '0',
        'product_id' => $normalized_data['product_id'] ?? '',
    ];

    return [
        'original' => $original_data,
        'normalized' => $normalized_data,
        'changes' => $changes,
        'signature' => $signature,
        'components' => $components,
    ];
}

/**
 * Render the Placeholder Roster Management section
 */
function intersoccer_render_placeholder_management_section() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    // Get placeholder statistics
    $placeholder_count = $wpdb->get_var("SELECT COUNT(*) FROM $rosters_table WHERE is_placeholder = 1");
    $real_roster_count = $wpdb->get_var("SELECT COUNT(*) FROM $rosters_table WHERE is_placeholder = 0");
    $total_count = $placeholder_count + $real_roster_count;
    
    ?>
    <div class="placeholder-management-section">
        <h2>📝 <?php _e('Placeholder Roster Management', 'intersoccer-reports-rosters'); ?></h2>
        <p><?php _e('Placeholder rosters are automatically created for each product variation to allow player migration before the first order is placed.', 'intersoccer-reports-rosters'); ?></p>
        
        <div class="placeholder-stats">
            <div class="stat-box">
                <span class="stat-label"><?php _e('Placeholder Rosters:', 'intersoccer-reports-rosters'); ?></span>
                <span class="stat-value"><?php echo esc_html($placeholder_count); ?></span>
            </div>
            <div class="stat-box">
                <span class="stat-label"><?php _e('Real Rosters:', 'intersoccer-reports-rosters'); ?></span>
                <span class="stat-value"><?php echo esc_html($real_roster_count); ?></span>
            </div>
            <div class="stat-box">
                <span class="stat-label"><?php _e('Total:', 'intersoccer-reports-rosters'); ?></span>
                <span class="stat-value"><?php echo esc_html($total_count); ?></span>
            </div>
        </div>
        
        <form id="intersoccer-sync-placeholders-form" method="post" action="">
            <?php wp_nonce_field('intersoccer_rebuild_nonce', 'nonce'); ?>
            <input type="hidden" name="action" value="intersoccer_sync_placeholders">
            <button type="submit" class="button button-secondary" id="intersoccer-sync-placeholders-button">
                ↻ <?php _e('Sync All Placeholders', 'intersoccer-reports-rosters'); ?>
            </button>
        </form>
        
        <p class="description">
            <?php _e('This will create or update placeholder rosters for all published product variations. Placeholders allow admins to migrate players to events before the first order is placed.', 'intersoccer-reports-rosters'); ?>
        </p>
        
        <style>
            .placeholder-management-section {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .placeholder-stats {
                display: flex;
                gap: 20px;
                margin: 20px 0;
            }
            .stat-box {
                background: #f0f0f1;
                padding: 15px 20px;
                border-radius: 4px;
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            .stat-label {
                font-weight: 600;
                color: #646970;
                font-size: 12px;
                text-transform: uppercase;
            }
            .stat-value {
                font-size: 24px;
                font-weight: bold;
                color: #1d2327;
            }
        </style>
        
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#intersoccer-sync-placeholders-form').on('submit', function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var $button = $form.find('button[type="submit"]');
                    
                    if (!confirm("<?php echo esc_js(__('This will create or update placeholder rosters for all published product variations. Continue?', 'intersoccer-reports-rosters')); ?>")) {
                        return false;
                    }
                    
                    // Use the main notice system
                    if (typeof showAdminNotice === 'function') {
                        showAdminNotice('<?php echo esc_js(__('Starting: Syncing placeholders...', 'intersoccer-reports-rosters')); ?>', 'info');
                    } else {
                        $('#intersoccer-operation-status').html('<div class="notice notice-info"><p><strong><?php echo esc_js(__('Starting: Syncing placeholders...', 'intersoccer-reports-rosters')); ?></strong></p></div>');
                    }
                    $button.prop('disabled', true).text('<?php echo esc_js(__('Running...', 'intersoccer-reports-rosters')); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: $form.serialize(),
                        success: function(response) {
                            console.log('Sync placeholders response:', response);
                            if (response.success) {
                                if (typeof showAdminNotice === 'function') {
                                    showAdminNotice('<?php echo esc_js(__('Finished: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Placeholders synced successfully.', 'intersoccer-reports-rosters')); ?>'), 'success');
                                } else {
                                    $('#intersoccer-operation-status').html('<div class="notice notice-success"><p><strong><?php echo esc_js(__('Finished: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Placeholders synced successfully.', 'intersoccer-reports-rosters')); ?>') + '</strong></p></div>');
                                }
                                // Reload page to update stats after a delay
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                if (typeof showAdminNotice === 'function') {
                                    showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Sync failed.', 'intersoccer-reports-rosters')); ?>'), 'error');
                                } else {
                                    $('#intersoccer-operation-status').html('<div class="notice notice-error"><p><strong><?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + (response.data.message || '<?php echo esc_js(__('Sync failed.', 'intersoccer-reports-rosters')); ?>') + '</strong></p></div>');
                                }
                                $button.prop('disabled', false).text('<?php echo esc_js(__('Sync All Placeholders', 'intersoccer-reports-rosters')); ?>');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', status, error, xhr.responseText);
                            var errorMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message 
                                ? xhr.responseJSON.data.message 
                                : (xhr.responseText || error || '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>');
                            if (typeof showAdminNotice === 'function') {
                                showAdminNotice('<?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + errorMsg, 'error');
                            } else {
                                $('#intersoccer-operation-status').html('<div class="notice notice-error"><p><strong><?php echo esc_js(__('Failed: ', 'intersoccer-reports-rosters')); ?>' + errorMsg + '</strong></p></div>');
                            }
                            $button.prop('disabled', false).text('<?php echo esc_js(__('Sync All Placeholders', 'intersoccer-reports-rosters')); ?>');
                        }
                    });
                });
            });
        </script>
    </div>
    <?php
}

/**
 * AJAX handler to close out a roster (mark event as completed)
 */
function intersoccer_close_out_roster_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    // Get parameters
    $event_signature = isset($_POST['event_signature']) ? sanitize_text_field($_POST['event_signature']) : '';
    
    if (empty($event_signature)) {
        wp_send_json_error(['message' => __('Event signature is required.', 'intersoccer-reports-rosters')]);
    }
    
    // Update all roster entries with this event_signature
    $updated = $wpdb->update(
        $rosters_table,
        ['event_completed' => 1],
        ['event_signature' => $event_signature],
        ['%d'],
        ['%s']
    );
    
    if ($updated === false) {
        wp_send_json_error(['message' => __('Failed to close roster.', 'intersoccer-reports-rosters')]);
    }
    
    // Clear cache
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    
    wp_send_json_success([
        'message' => sprintf(__('Roster closed successfully. %d entries updated.', 'intersoccer-reports-rosters'), $updated),
        'updated' => $updated
    ]);
}

/**
 * AJAX handler to reopen a roster (mark event as not completed)
 */
function intersoccer_reopen_roster_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    // Get parameters
    $event_signature = isset($_POST['event_signature']) ? sanitize_text_field($_POST['event_signature']) : '';
    
    if (empty($event_signature)) {
        wp_send_json_error(['message' => __('Event signature is required.', 'intersoccer-reports-rosters')]);
    }
    
    // Update all roster entries with this event_signature
    $updated = $wpdb->update(
        $rosters_table,
        ['event_completed' => 0],
        ['event_signature' => $event_signature],
        ['%d'],
        ['%s']
    );
    
    if ($updated === false) {
        wp_send_json_error(['message' => __('Failed to reopen roster.', 'intersoccer-reports-rosters')]);
    }
    
    // Clear cache
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    
    wp_send_json_success([
        'message' => sprintf(__('Roster reopened successfully. %d entries updated.', 'intersoccer-reports-rosters'), $updated),
        'updated' => $updated
    ]);
}

/**
 * AJAX handler to bulk close rosters
 */
function intersoccer_bulk_close_rosters_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    // Get parameters
    $event_signatures = isset($_POST['event_signatures']) ? (array) $_POST['event_signatures'] : [];
    $event_signatures = array_map('sanitize_text_field', $event_signatures);
    $event_signatures = array_filter($event_signatures);
    
    if (empty($event_signatures)) {
        wp_send_json_error(['message' => __('No rosters selected.', 'intersoccer-reports-rosters')]);
    }
    
    $total_updated = 0;
    $placeholders = implode(',', array_fill(0, count($event_signatures), '%s'));
    
    // Update all roster entries with these event_signatures
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$rosters_table} SET event_completed = 1 WHERE event_signature IN ($placeholders)",
        $event_signatures
    ));
    
    if ($updated === false) {
        wp_send_json_error(['message' => __('Failed to close rosters.', 'intersoccer-reports-rosters')]);
    }
    
    // Log audit trail
    foreach ($event_signatures as $signature) {
        intersoccer_log_audit('Roster Closed (Bulk)', "Event Signature: $signature");
    }
    
    // Clear cache
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    
    wp_send_json_success([
        'message' => sprintf(__('%d roster(s) closed successfully. %d entries updated.', 'intersoccer-reports-rosters'), count($event_signatures), $updated),
        'updated' => $updated,
        'count' => count($event_signatures)
    ]);
}

/**
 * AJAX handler to bulk reopen rosters
 */
function intersoccer_bulk_reopen_rosters_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    // Get parameters
    $event_signatures = isset($_POST['event_signatures']) ? (array) $_POST['event_signatures'] : [];
    $event_signatures = array_map('sanitize_text_field', $event_signatures);
    $event_signatures = array_filter($event_signatures);
    
    if (empty($event_signatures)) {
        wp_send_json_error(['message' => __('No rosters selected.', 'intersoccer-reports-rosters')]);
    }
    
    $placeholders = implode(',', array_fill(0, count($event_signatures), '%s'));
    
    // Update all roster entries with these event_signatures
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$rosters_table} SET event_completed = 0 WHERE event_signature IN ($placeholders)",
        $event_signatures
    ));
    
    if ($updated === false) {
        wp_send_json_error(['message' => __('Failed to reopen rosters.', 'intersoccer-reports-rosters')]);
    }
    
    // Log audit trail
    foreach ($event_signatures as $signature) {
        intersoccer_log_audit('Roster Reopened (Bulk)', "Event Signature: $signature");
    }
    
    // Clear cache
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    
    wp_send_json_success([
        'message' => sprintf(__('%d roster(s) reopened successfully. %d entries updated.', 'intersoccer-reports-rosters'), count($event_signatures), $updated),
        'updated' => $updated,
        'count' => count($event_signatures)
    ]);
}

/**
 * AJAX handler to close all rosters in a season
 */
function intersoccer_close_season_rosters_ajax() {
    check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'intersoccer-reports-rosters')]);
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    // Get parameters
    $season = isset($_POST['season']) ? sanitize_text_field($_POST['season']) : '';
    $page = isset($_POST['page']) ? sanitize_text_field($_POST['page']) : 'camps';
    
    if (empty($season)) {
        wp_send_json_error(['message' => __('Season is required.', 'intersoccer-reports-rosters')]);
    }
    
    // Normalize the season for matching (handle display format vs database format)
    // The season might be in display format (e.g., "Winter 2025") but stored differently in DB
    // Use case-insensitive matching to find all matching season values
    if (!function_exists('intersoccer_normalize_season_for_display')) {
        // Fallback if function doesn't exist
        $normalized_season = $season;
    } else {
        $normalized_season = intersoccer_normalize_season_for_display($season);
    }
    
    // Use a simpler, more efficient query to find matching seasons
    // Match case-insensitively and handle variations
    $season_escaped = $wpdb->esc_like($season);
    $normalized_escaped = $wpdb->esc_like($normalized_season);
    
    // Get all distinct season values that match (case-insensitive)
    $matching_seasons = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT season FROM {$rosters_table} 
         WHERE (event_completed = 0 OR event_completed IS NULL) 
         AND (LOWER(TRIM(season)) = LOWER(TRIM(%s)) 
              OR LOWER(TRIM(season)) = LOWER(TRIM(%s))
              OR LOWER(season) LIKE LOWER(%s))",
        $season, 
        $normalized_season, 
        '%' . $season_escaped . '%'
    ));
    
    if (empty($matching_seasons)) {
        wp_send_json_error(['message' => sprintf(__('No active rosters found for season "%s".', 'intersoccer-reports-rosters'), esc_html($season))]);
    }
    
    // Build WHERE clause - match any of the found season values
    // Close ALL rosters in the season regardless of page type
    $season_placeholders = implode(',', array_fill(0, count($matching_seasons), '%s'));
    $where_conditions = ["season IN ($season_placeholders)", "(event_completed = 0 OR event_completed IS NULL)"];
    $where_params = $matching_seasons;
    
    // Log what we're about to do
    error_log('InterSoccer: Closing season rosters - Season: ' . $season . ', Matching seasons: ' . implode(', ', $matching_seasons) . ', Page: ' . $page);
    
    // Count how many will be affected
    $count_query = "SELECT COUNT(DISTINCT event_signature) FROM {$rosters_table} WHERE " . implode(' AND ', $where_conditions);
    $roster_count = $wpdb->get_var($wpdb->prepare($count_query, $where_params));
    
    if ($roster_count == 0) {
        wp_send_json_error(['message' => __('No active rosters found for this season.', 'intersoccer-reports-rosters')]);
    }
    
    // Update all roster entries for this season (all types)
    $update_query = "UPDATE {$rosters_table} SET event_completed = 1 WHERE " . implode(' AND ', $where_conditions);
    $updated = $wpdb->query($wpdb->prepare($update_query, $where_params));
    
    error_log('InterSoccer: Closed season rosters - Updated: ' . $updated . ' entries, Rosters: ' . $roster_count);
    
    if ($updated === false) {
        wp_send_json_error(['message' => __('Failed to close rosters in season.', 'intersoccer-reports-rosters')]);
    }
    
    // Log audit trail
    intersoccer_log_audit('Season Rosters Closed', "Season: $season, Page: $page, Rosters: $roster_count, Entries: $updated");
    
    // Clear cache
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    
    wp_send_json_success([
        'message' => sprintf(__('Successfully closed %d roster(s) in season "%s". %d entries updated.', 'intersoccer-reports-rosters'), $roster_count, esc_html($season), $updated),
        'updated' => $updated,
        'roster_count' => $roster_count,
        'season' => $season
    ]);
}

?>