<?php
/**
 * Enhanced Advanced features page for InterSoccer Reports and Rosters plugin.
 * Includes discount migration dashboard and enhanced functionality
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.21
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Include the clean discount reporting fix
$discount_fix_file = dirname(__FILE__) . 'complete-discount-reporting-fix.php';
if (file_exists($discount_fix_file)) {
    require_once $discount_fix_file;
}

/**
 * Render the Enhanced Advanced Features page with discount migration dashboard.
 */
function intersoccer_render_advanced_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }
    
    // Get discount migration statistics
    global $wpdb;
    $total_orders = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$wpdb->prefix}posts 
        WHERE post_type = 'shop_order' 
        AND post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND post_date >= '2024-01-01'
    ");
    
    $migrated_orders = $wpdb->get_var("
        SELECT COUNT(DISTINCT post_id) 
        FROM {$wpdb->prefix}postmeta 
        WHERE meta_key = '_intersoccer_total_discounts'
        AND post_id IN (
            SELECT ID FROM {$wpdb->prefix}posts 
            WHERE post_type = 'shop_order' 
            AND post_date >= '2024-01-01'
        )
    ");
    
    $unmigrated_orders = $total_orders - $migrated_orders;
    $migration_percentage = $total_orders > 0 ? round(($migrated_orders / $total_orders) * 100, 1) : 0;
    
    ?>
    <div class="wrap">
        <h1><?php _e('InterSoccer Advanced Features', 'intersoccer-reports-rosters'); ?></h1>
        <div id="intersoccer-rebuild-status"></div>
        
        <!-- NEW: Discount Migration Dashboard -->
        <div class="discount-migration-section">
            <h2><?php echo __('🔄 Discount Migration Dashboard', 'intersoccer-reports-rosters'); ?></h2>
            <p><?php _e('Migrate historical orders to use the enhanced discount reporting system for accurate finance reports.', 'intersoccer-reports-rosters'); ?></p>
            
            <!-- Migration Statistics -->
            <div class="migration-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                <div class="stat-card" style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #0073aa;">Total Orders</h3>
                    <div style="font-size: 36px; font-weight: bold; color: #0073aa;"><?php echo number_format($total_orders); ?></div>
                    <div style="font-size: 14px; color: #666;">Since January 2024</div>
                </div>
                
                <div class="stat-card" style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #28a745;">Migrated Orders</h3>
                    <div style="font-size: 36px; font-weight: bold; color: #28a745;"><?php echo number_format($migrated_orders); ?></div>
                    <div style="font-size: 14px; color: #666;">Enhanced data available</div>
                </div>
                
                <div class="stat-card" style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #dc3545;">Pending Migration</h3>
                    <div style="font-size: 36px; font-weight: bold; color: #dc3545;"><?php echo number_format($unmigrated_orders); ?></div>
                    <div style="font-size: 14px; color: #666;">Need migration</div>
                </div>
                
                <div class="stat-card" style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #ffc107;">Progress</h3>
                    <div style="font-size: 36px; font-weight: bold; color: #ffc107;"><?php echo $migration_percentage; ?>%</div>
                    <div style="font-size: 14px; color: #666;">Migration complete</div>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div style="margin: 20px 0;">
                <div style="background: #f0f0f0; height: 20px; border-radius: 10px; overflow: hidden;">
                    <div style="background: linear-gradient(to right, #28a745, #0073aa); height: 100%; width: <?php echo $migration_percentage; ?>%; transition: width 0.3s;"></div>
                </div>
            </div>
            
            <?php if ($unmigrated_orders > 0): ?>
                <!-- Migration Controls -->
                <div class="migration-controls" style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0;">
                    <h3><?php _e('🚀 Start Migration', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('This will process orders in batches of 100 to prevent timeouts. You can run this multiple times safely.', 'intersoccer-reports-rosters'); ?></p>
                    
                    <div style="margin: 20px 0;">
                        <button id="start-discount-migration" class="button button-primary button-large">
                            <?php _e('Migrate Next 100 Orders', 'intersoccer-reports-rosters'); ?>
                        </button>
                        <button id="migrate-all-discounts" class="button button-secondary" style="margin-left: 10px;">
                            <?php _e('Auto-Migrate All Remaining Orders', 'intersoccer-reports-rosters'); ?>
                        </button>
                    </div>
                    
                    <div id="discount-migration-progress" style="margin-top: 20px; display: none;">
                        <h4><?php _e('Migration Progress', 'intersoccer-reports-rosters'); ?></h4>
                        <div id="discount-progress-bar" style="background: #f0f0f0; height: 30px; border-radius: 15px; overflow: hidden; margin: 10px 0;">
                            <div id="discount-progress-fill" style="background: linear-gradient(to right, #28a745, #0073aa); height: 100%; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <div id="discount-progress-text" style="text-align: center; font-weight: bold;"></div>
                        <div id="discount-migration-log" style="background: #1e1e1e; color: #00ff00; border: 1px solid #333; padding: 10px; margin: 10px 0; height: 200px; overflow-y: auto; font-family: monospace; font-size: 12px;"></div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Migration Complete -->
                <div class="migration-complete" style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; padding: 20px; margin: 20px 0;">
                    <h3 style="color: #155724;"><?php _e('✅ Migration Complete!', 'intersoccer-reports-rosters'); ?></h3>
                    <p style="color: #155724;"><?php _e('All orders have been migrated to the enhanced discount reporting system.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Diagnostic Tools -->
            <div class="diagnostic-tools" style="background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0;">
                <h3><?php _e('🔧 Diagnostic Tools', 'intersoccer-reports-rosters'); ?></h3>
                <p><?php _e('Use these tools to verify data integrity and troubleshoot issues.', 'intersoccer-reports-rosters'); ?></p>
                
                <div style="margin: 20px 0;">
                    <button id="run-discount-diagnostic" class="button button-secondary">
                        <?php _e('Run Discount System Diagnostic', 'intersoccer-reports-rosters'); ?>
                    </button>
                    <button id="validate-recent-orders" class="button button-secondary" style="margin-left: 10px;">
                        <?php _e('Validate Recent Orders', 'intersoccer-reports-rosters'); ?>
                    </button>
                    <button id="check-discount-totals" class="button button-secondary" style="margin-left: 10px;">
                        <?php _e('Verify Discount Totals', 'intersoccer-reports-rosters'); ?>
                    </button>
                </div>
                
                <div id="discount-diagnostic-results" style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin: 10px 0; display: none;">
                    <h4><?php _e('Diagnostic Results', 'intersoccer-reports-rosters'); ?></h4>
                    <div id="discount-diagnostic-output" style="font-family: monospace; font-size: 12px; white-space: pre-wrap;"></div>
                </div>
            </div>
        </div>
        
        <!-- EXISTING: Original Advanced Features -->
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
                console.log('InterSoccer: Enhanced advanced page JS loaded');
                
                var migrationInProgress = false;
                
                // NEW: Discount Migration Functions
                $('#start-discount-migration').on('click', function() {
                    if (migrationInProgress) return;
                    startDiscountMigrationBatch(1);
                });
                
                $('#migrate-all-discounts').on('click', function() {
                    if (migrationInProgress) return;
                    
                    if (confirm('<?php echo esc_js(__('This will migrate all remaining orders automatically. This may take several minutes. Continue?', 'intersoccer-reports-rosters')); ?>')) {
                        startAutoDiscountMigration();
                    }
                });
                
                function startDiscountMigrationBatch(batchNumber) {
                    migrationInProgress = true;
                    $('#discount-migration-progress').show();
                    updateDiscountProgress(0, 'Starting migration batch ' + batchNumber + '...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'intersoccer_migrate_discount_batch',
                            nonce: '<?php echo wp_create_nonce('intersoccer_migrate_discount_batch'); ?>',
                            batch_size: 100
                        },
                        success: function(response) {
                            if (response.success) {
                                var data = response.data;
                                updateDiscountProgress(100, 'Batch ' + batchNumber + ' complete: ' + data.migrated_count + ' orders migrated');
                                logDiscountMessage('✅ Migrated ' + data.migrated_count + ' orders in batch ' + batchNumber);
                                
                                if (data.more_remaining) {
                                    setTimeout(function() {
                                        location.reload(); // Refresh to update stats
                                    }, 2000);
                                } else {
                                    logDiscountMessage('🎉 All orders have been migrated!');
                                    setTimeout(function() {
                                        location.reload();
                                    }, 3000);
                                }
                            } else {
                                updateDiscountProgress(0, 'Error: ' + (response.data ? response.data.message : 'Unknown error'));
                                logDiscountMessage('❌ Error: ' + (response.data ? response.data.message : 'Unknown error'));
                            }
                        },
                        error: function() {
                            updateDiscountProgress(0, 'Connection error during migration');
                            logDiscountMessage('❌ Connection error during migration');
                        },
                        complete: function() {
                            migrationInProgress = false;
                        }
                    });
                }
                
                function startAutoDiscountMigration() {
                    migrationInProgress = true;
                    $('#discount-migration-progress').show();
                    autoMigrateDiscountBatch(1);
                }
                
                function autoMigrateDiscountBatch(batchNumber) {
                    updateDiscountProgress((batchNumber - 1) * 10, 'Auto-migrating batch ' + batchNumber + '...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'intersoccer_migrate_discount_batch',
                            nonce: '<?php echo wp_create_nonce('intersoccer_migrate_discount_batch'); ?>',
                            batch_size: 100
                        },
                        success: function(response) {
                            if (response.success) {
                                var data = response.data;
                                logDiscountMessage('✅ Batch ' + batchNumber + ': ' + data.migrated_count + ' orders migrated');
                                
                                if (data.more_remaining && batchNumber < 50) { // Safety limit
                                    setTimeout(function() {
                                        autoMigrateDiscountBatch(batchNumber + 1);
                                    }, 1000); // 1 second delay between batches
                                } else {
                                    updateDiscountProgress(100, 'Auto-migration complete!');
                                    logDiscountMessage('🎉 Auto-migration finished!');
                                    setTimeout(function() {
                                        location.reload();
                                    }, 3000);
                                }
                            } else {
                                updateDiscountProgress(0, 'Error in auto-migration: ' + (response.data ? response.data.message : 'Unknown error'));
                                logDiscountMessage('❌ Auto-migration error: ' + (response.data ? response.data.message : 'Unknown error'));
                                migrationInProgress = false;
                            }
                        },
                        error: function() {
                            updateDiscountProgress(0, 'Connection error during auto-migration');
                            logDiscountMessage('❌ Connection error during auto-migration');
                            migrationInProgress = false;
                        }
                    });
                }
                
                function updateDiscountProgress(percentage, message) {
                    $('#discount-progress-fill').css('width', percentage + '%');
                    $('#discount-progress-text').text(message);
                }
                
                function logDiscountMessage(message) {
                    var timestamp = new Date().toLocaleTimeString();
                    $('#discount-migration-log').append('[' + timestamp + '] ' + message + '\n');
                    $('#discount-migration-log').scrollTop($('#discount-migration-log')[0].scrollHeight);
                }
                
                // Diagnostic tools
                $('#run-discount-diagnostic').on('click', function() {
                    runDiscountDiagnostic('full_diagnostic');
                });
                
                $('#validate-recent-orders').on('click', function() {
                    runDiscountDiagnostic('validate_recent');
                });
                
                $('#check-discount-totals').on('click', function() {
                    runDiscountDiagnostic('check_totals');
                });
                
                function runDiscountDiagnostic(type) {
                    $('#discount-diagnostic-results').show();
                    $('#discount-diagnostic-output').text('Running ' + type + '...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'intersoccer_run_discount_diagnostic',
                            nonce: '<?php echo wp_create_nonce('intersoccer_discount_diagnostic'); ?>',
                            diagnostic_type: type
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#discount-diagnostic-output').text(response.data.output);
                            } else {
                                $('#discount-diagnostic-output').text('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                            }
                        },
                        error: function() {
                            $('#discount-diagnostic-output').text('Connection error during diagnostic');
                        }
                    });
                }
                
                // EXISTING: Original Advanced Features JavaScript
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
                            $('#intersoccer-rebuild-status').html('<p>' + (response.data ? response.data.message : 'Rebuild completed') + '</p>');
                            console.log('Rebuild response: ', response);
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Raw Response: ', xhr.responseText);
                            $('#intersoccer-rebuild-status').html('<p><?php _e('Rebuild failed: ', 'intersoccer-reports-rosters'); ?>' + (xhr.responseJSON ? xhr.responseJSON.message : (xhr.responseText || error)) + '</p>');
                            console.error('AJAX Error: ', status, error, xhr.responseText);
                        }
                    });
                });

                $('#intersoccer-process-processing-form').on('submit', function(e) {
                    e.preventDefault();
                    console.log('InterSoccer: Process orders form submit triggered');
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
                            $('#intersoccer-rebuild-status').html('<p>' + (response.data && response.data.message ? response.data.message : '<?php _e('Orders processed. Check debug.log for details.', 'intersoccer-reports-rosters'); ?>') + '</p>');
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
        
        <style>
        .migration-stats .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .migration-stats .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        </style>
    </div>
    <?php
}

// NEW: AJAX Handlers for Discount Migration

/**
 * AJAX handler for discount migration batch processing
 */
add_action('wp_ajax_intersoccer_migrate_discount_batch', 'intersoccer_migrate_discount_batch_ajax');
function intersoccer_migrate_discount_batch_ajax() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    check_ajax_referer('intersoccer_migrate_discount_batch', 'nonce');
    
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 100;
    
    try {
        if (function_exists('intersoccer_migrate_discount_data_batch')) {
            $result = intersoccer_migrate_discount_data_batch($batch_size);
            
            wp_send_json_success(array(
                'migrated_count' => $result['migrated_count'],
                'remaining_count' => $result['remaining_count'],
                'more_remaining' => $result['more_remaining'],
                'errors' => $result['errors'],
                'message' => "Successfully migrated {$result['migrated_count']} orders"
            ));
        } else {
            wp_send_json_error(array('message' => 'Migration function not available. Please check if clean-discount-reporting-fix.php is included.'));
        }
        
    } catch (Exception $e) {
        error_log("InterSoccer Migration: Batch migration error: " . $e->getMessage());
        wp_send_json_error(array('message' => $e->getMessage()));
    }
}

/**
 * AJAX handler for discount diagnostics
 */
add_action('wp_ajax_intersoccer_run_discount_diagnostic', 'intersoccer_run_discount_diagnostic_ajax');
function intersoccer_run_discount_diagnostic_ajax() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    check_ajax_referer('intersoccer_discount_diagnostic', 'nonce');
    
    $diagnostic_type = sanitize_text_field($_POST['diagnostic_type']);
    if (empty($diagnostic_type)) {
        $diagnostic_type = 'full_diagnostic';
    }
    
    try {
        $output = '';
        
        switch ($diagnostic_type) {
            case 'full_diagnostic':
                $output = intersoccer_run_full_discount_diagnostic();
                break;
            case 'validate_recent':
                $output = intersoccer_validate_recent_discount_orders();
                break;
            case 'check_totals':
                $output = intersoccer_check_discount_totals_summary();
                break;
            default:
                $output = 'Unknown diagnostic type';
        }
        
        wp_send_json_success(array('output' => $output));
        
    } catch (Exception $e) {
        wp_send_json_error(array('message' => $e->getMessage()));
    }
}

/**
 * Run full discount system diagnostic
 */
function intersoccer_run_full_discount_diagnostic() {
    global $wpdb;
    
    $output = "=== InterSoccer Discount System Diagnostic ===\n";
    $output .= "Timestamp: " . current_time('Y-m-d H:i:s') . "\n\n";
    
    // Database statistics
    $total_orders = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}posts 
        WHERE post_type = 'shop_order' 
        AND post_date >= '2024-01-01'
    ");
    
    $migrated_orders = $wpdb->get_var("
        SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}postmeta 
        WHERE meta_key = '_intersoccer_total_discounts'
    ");
    
    $orders_with_discounts = $wpdb->get_var("
        SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}postmeta 
        WHERE meta_key = '_intersoccer_total_discounts' 
        AND CAST(meta_value AS DECIMAL(10,2)) > 0
    ");
    
    $output .= "📊 DATABASE STATISTICS:\n";
    $output .= "Total Orders (2024+): {$total_orders}\n";
    $output .= "Migrated Orders: {$migrated_orders}\n";
    $output .= "Orders with Discounts: {$orders_with_discounts}\n";
    $output .= "Migration Coverage: " . round(($migrated_orders / max($total_orders, 1)) * 100, 1) . "%\n\n";
    
    // Check recent orders
    $recent_orders = $wpdb->get_results("
        SELECT p.ID, p.post_date, pm.meta_value as discount_amount
        FROM {$wpdb->prefix}posts p
        LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id 
            AND pm.meta_key = '_intersoccer_total_discounts'
        WHERE p.post_type = 'shop_order' 
        AND p.post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY p.post_date DESC
        LIMIT 10
    ", ARRAY_A);
    
    $output .= "🕒 RECENT ORDERS (Last 7 Days):\n";
    foreach ($recent_orders as $order) {
        $discount_status = $order['discount_amount'] !== null ? 
            "✓ Tracked (" . number_format($order['discount_amount'], 2) . " CHF)" : 
            "⚠ Not tracked";
        $output .= "Order {$order['ID']} ({$order['post_date']}): {$discount_status}\n";
    }
    $output .= "\n";
    
    // System health checks
    $output .= "🔧 SYSTEM HEALTH:\n";
    
    $hooks_active = array(
        'woocommerce_checkout_order_processed' => has_action('woocommerce_checkout_order_processed', 'intersoccer_capture_discount_data'),
        'woocommerce_cart_calculate_fees' => has_action('woocommerce_cart_calculate_fees', 'intersoccer_apply_combo_discounts')
    );
    
    foreach ($hooks_active as $hook => $active) {
        $status = $active ? "✓ Active" : "❌ Missing";
        $output .= "{$hook}: {$status}\n";
    }
    
    $output .= "\n=== Diagnostic Complete ===";
    
    return $output;
}

/**
 * Validate recent orders for discount data
 */
function intersoccer_validate_recent_discount_orders() {
    global $wpdb;
    
    $output = "=== Recent Orders Validation ===\n";
    $output .= "Checking last 20 orders for discount data integrity...\n\n";
    
    $recent_orders = $wpdb->get_results("
        SELECT p.ID, p.post_date
        FROM {$wpdb->prefix}posts p
        WHERE p.post_type = 'shop_order' 
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY p.post_date DESC
        LIMIT 20
    ", ARRAY_A);
    
    $valid_count = 0;
    $invalid_count = 0;
    
    foreach ($recent_orders as $order_row) {
        $order_id = $order_row['ID'];
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $output .= "❌ Order {$order_id}: Order object not found\n";
            $invalid_count++;
            continue;
        }
        
        $discount_amount = get_post_meta($order_id, '_intersoccer_total_discounts', true);
        
        if ($discount_amount !== '') {
            $output .= "✓ Order {$order_id}: Tracked (" . number_format($discount_amount, 2) . " CHF)\n";
            $valid_count++;
        } else {
            $output .= "❓ Order {$order_id}: No discount data (may need migration)\n";
            $invalid_count++;
        }
    }
    
    $output .= "\n📊 VALIDATION SUMMARY:\n";
    $output .= "Valid Orders: {$valid_count}\n";
    $output .= "Invalid/Missing: {$invalid_count}\n";
    $output .= "Tracking Rate: " . round(($valid_count / max($valid_count + $invalid_count, 1)) * 100, 1) . "%\n";
    
    return $output;
}

/**
 * Check discount totals summary
 */
function intersoccer_check_discount_totals_summary() {
    global $wpdb;
    
    $output = "=== Discount Totals Analysis ===\n\n";
    
    $total_discounts = $wpdb->get_var("
        SELECT SUM(CAST(meta_value AS DECIMAL(10,2))) 
        FROM {$wpdb->prefix}postmeta 
        WHERE meta_key = '_intersoccer_total_discounts'
        AND CAST(meta_value AS DECIMAL(10,2)) > 0
    ");
    
    $output .= "💰 TOTAL DISCOUNTS: " . number_format($total_discounts, 2) . " CHF\n\n";
    
    // Monthly breakdown
    $monthly_discounts = $wpdb->get_results("
        SELECT 
            DATE_FORMAT(p.post_date, '%Y-%m') as month,
            COUNT(*) as order_count,
            SUM(CAST(pm.meta_value AS DECIMAL(10,2))) as total_discount
        FROM {$wpdb->prefix}posts p
        JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
        WHERE p.post_type = 'shop_order'
        AND pm.meta_key = '_intersoccer_total_discounts'
        AND CAST(pm.meta_value AS DECIMAL(10,2)) > 0
        AND p.post_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(p.post_date, '%Y-%m')
        ORDER BY month DESC
    ", ARRAY_A);
    
    $output .= "📅 MONTHLY BREAKDOWN (Last 12 Months):\n";
    foreach ($monthly_discounts as $month_data) {
        $avg_discount = $month_data['order_count'] > 0 ? 
            $month_data['total_discount'] / $month_data['order_count'] : 0;
        
        $output .= "{$month_data['month']}: {$month_data['order_count']} orders, " . 
                  number_format($month_data['total_discount'], 2) . " CHF total, " .
                  number_format($avg_discount, 2) . " CHF avg\n";
    }
    
    return $output;
}

// EXISTING: Keep all original functions from advanced.php
if (!function_exists('dbDelta')) {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
}
if (!class_exists('WC_Order')) {
    require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
}

/**
 * EXISTING: Wrapper to safely call populate function with output buffering
 */
function intersoccer_safe_populate_rosters($order_id) {
    ob_start();
    try {
        // Use debug wrapper for testing
        $debug_wrapper_file = dirname(__FILE__) . '/debug-wrapper.php';
        if (file_exists($debug_wrapper_file)) {
            include_once $debug_wrapper_file;
            if (function_exists('intersoccer_debug_populate_rosters')) {
                intersoccer_debug_populate_rosters($order_id);
            }
        }
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
 * EXISTING: Process existing orders to populate rosters and complete them.
 */
function intersoccer_process_existing_orders() {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    error_log('InterSoccer: Starting process existing orders');

    // Allow processing completed orders for debugging if ?include_completed=1
    $statuses = array('wc-processing', 'wc-on-hold');
    if (isset($_POST['include_completed']) && $_POST['include_completed'] === '1') {
        $statuses[] = 'wc-completed';
        error_log('InterSoccer: Including wc-completed orders for processing (debug mode)');
    }

    $orders = wc_get_orders(array(
        'limit' => -1,
        'status' => $statuses,
    ));
    error_log('InterSoccer: Found ' . count($orders) . ' eligible orders to process');

    $processed = 0;
    $inserted = 0;
    $completed = 0;

    try {
        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $initial_status = $order->get_status();
            error_log('InterSoccer: Processing existing order ' . $order_id . ' (initial status: ' . $initial_status . ')');

            // Check if already populated
            $existing_rosters = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rosters_table WHERE order_id = %d", $order_id));
            error_log('InterSoccer: Existing rosters for order ' . $order_id . ': ' . $existing_rosters);

            // Call safe populate
            $populate_success = intersoccer_safe_populate_rosters($order_id);

            // Verify inserts and status
            $new_inserts = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rosters_table WHERE order_id = %d", $order_id)) - $existing_rosters;
            $new_status = wc_get_order($order_id)->get_status();
            if ($new_inserts > 0 && $populate_success) {
                $inserted += $new_inserts;
                $processed++;
                error_log('InterSoccer: Added ' . $new_inserts . ' new inserts for order ' . $order_id);
            } else {
                error_log('InterSoccer: No new inserts for order ' . $order_id . ' (already populated or populate failed)');
            }

            // If populated and still processing (skip for completed in debug mode)
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
        return array(
            'status' => 'success',
            'processed' => $processed,
            'inserted' => $inserted,
            'completed' => $completed,
            'message' => __('Processed ' . $processed . ' orders, inserted ' . $inserted . ' rosters, completed ' . $completed . ' orders.', 'intersoccer-reports-rosters')
        );
    } catch (Exception $e) {
        error_log('InterSoccer: Process orders failed: ' . $e->getMessage());
        return array(
            'status' => 'error',
            'message' => __('Processing failed: ' . $e->getMessage(), 'intersoccer-reports-rosters')
        );
    }
}

add_action('wp_ajax_intersoccer_process_existing_orders', 'intersoccer_process_existing_orders_ajax');
function intersoccer_process_existing_orders_ajax() {
    ob_start();
    error_log('InterSoccer: Process orders AJAX handler started; initial buffer: ' . ob_get_contents());

    check_ajax_referer('intersoccer_rebuild_nonce', 'intersoccer_rebuild_nonce_field');
    if (!current_user_can('manage_options')) {
        ob_clean();
        wp_send_json_error(array('message' => __('You do not have permission to process orders.', 'intersoccer-reports-rosters')));
    }
    error_log('InterSoccer: AJAX process existing orders request received with data: ' . print_r($_POST, true));

    $result = intersoccer_process_existing_orders();
    error_log('InterSoccer: Final buffer before JSON send: ' . ob_get_contents());
    ob_clean();
    if ($result['status'] === 'success') {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}

error_log('InterSoccer: Enhanced advanced page with discount migration dashboard loaded');
?>