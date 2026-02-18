<?php
/**
 * Roster AJAX Handler Class
 * 
 * Handles all AJAX requests related to roster operations.
 * Provides secure, validated AJAX endpoints for roster management.
 * 
 * @package InterSoccer\ReportsRosters\Ajax
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Ajax;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Services\PlaceholderManager;
use InterSoccer\ReportsRosters\Services\EventSignatureGenerator;
use InterSoccer\ReportsRosters\Core\DatabaseMigrator;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Roster AJAX Handler Class
 * 
 * Handles AJAX endpoints for roster operations
 */
class RosterAjaxHandler {
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Roster repository instance
     * 
     * @var RosterRepository
     */
    private $roster_repository;
    
    /**
     * Roster builder instance
     * 
     * @var RosterBuilder
     */
    private $roster_builder;
    
    /**
     * Placeholder manager instance
     * 
     * @var PlaceholderManager
     */
    private $placeholder_manager;
    
    /**
     * Event signature generator instance
     * 
     * @var EventSignatureGenerator
     */
    private $signature_generator;
    
    /**
     * Database migrator instance
     * 
     * @var DatabaseMigrator
     */
    private $database_migrator;
    
    /**
     * Constructor
     * 
     * @param Logger|null $logger Logger instance
     * @param RosterRepository|null $roster_repository Roster repository instance
     * @param RosterBuilder|null $roster_builder Roster builder instance
     * @param PlaceholderManager|null $placeholder_manager Placeholder manager instance
     * @param EventSignatureGenerator|null $signature_generator Event signature generator instance
     * @param DatabaseMigrator|null $database_migrator Database migrator instance
     */
    public function __construct(
        Logger $logger = null,
        RosterRepository $roster_repository = null,
        RosterBuilder $roster_builder = null,
        PlaceholderManager $placeholder_manager = null,
        EventSignatureGenerator $signature_generator = null,
        DatabaseMigrator $database_migrator = null
    ) {
        $this->logger = $logger ?: new Logger();
        $this->roster_repository = $roster_repository ?: new RosterRepository($this->logger);
        $this->roster_builder = $roster_builder ?: new RosterBuilder($this->logger);
        $this->placeholder_manager = $placeholder_manager ?: new PlaceholderManager($this->logger, $this->roster_repository);
        $this->signature_generator = $signature_generator ?: new EventSignatureGenerator($this->logger);
        $this->database_migrator = $database_migrator ?: new DatabaseMigrator($this->logger);
    }
    
    /**
     * Register AJAX handlers
     * 
     * @return void
     */
    public function register() {
        // Roster operations
        add_action('wp_ajax_intersoccer_rebuild_rosters_and_reports', [$this, 'handleRebuildRosters']);
        add_action('wp_ajax_intersoccer_rebuild_rosters', [$this, 'handleRebuildRosters']);
        add_action('wp_ajax_intersoccer_reconcile_rosters', [$this, 'handleReconcileRosters']);
        add_action('wp_ajax_intersoccer_mark_event_completed', [$this, 'handleMarkEventCompleted']);
        
        // Event signature operations
        add_action('wp_ajax_intersoccer_rebuild_event_signatures', [$this, 'handleRebuildEventSignatures']);
        
        // Database operations
        add_action('wp_ajax_intersoccer_upgrade_database', [$this, 'handleUpgradeDatabase']);
        
        // Placeholder operations
        add_action('wp_ajax_intersoccer_sync_placeholders', [$this, 'handleSyncPlaceholders']);
        add_action('wp_ajax_intersoccer_cleanup_placeholders', [$this, 'handleCleanupPlaceholders']);
        
        $this->logger->debug('Roster AJAX handlers registered');
    }
    
    /**
     * Handle rebuild rosters AJAX request
     * 
     * @return void
     */
    public function handleRebuildRosters() {
        try {
            // Verify nonce
            check_ajax_referer('intersoccer_rebuild_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'intersoccer-reports-rosters')]);
            }
            
            $this->logger->info('AJAX: Rebuild rosters request started');
            
            // Run rebuild
            $results = $this->roster_builder->rebuildAll([
                'clear_existing' => true,
                'batch_size' => 100
            ]);
            
            wp_send_json_success([
                'message' => sprintf(
                    __('Rebuild completed. Processed: %d, Created: %d, Errors: %d', 'intersoccer-reports-rosters'),
                    $results['processed'],
                    $results['created'],
                    $results['errors']
                ),
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX: Rebuild rosters failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => __('Rebuild failed: ', 'intersoccer-reports-rosters') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle reconcile rosters AJAX request
     * 
     * @return void
     */
    public function handleReconcileRosters() {
        try {
            // Verify nonce
            check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'intersoccer-reports-rosters')]);
            }
            
            $this->logger->info('AJAX: Reconcile rosters request started');
            
            $options = ['delete_obsolete' => true];
            if (!empty($_POST['date_from'])) {
                $options['date_from'] = sanitize_text_field($_POST['date_from']);
            }
            if (!empty($_POST['date_to'])) {
                $options['date_to'] = sanitize_text_field($_POST['date_to']);
            }
            if (!empty($options['date_from']) || !empty($options['date_to'])) {
                $options['delete_obsolete'] = false;
            }
            
            // Run reconcile
            $results = $this->roster_builder->reconcile($options);
            
            wp_send_json_success([
                'message' => sprintf(
                    __('Reconciliation completed. Synced: %d, Deleted: %d, Errors: %d', 'intersoccer-reports-rosters'),
                    $results['synced'],
                    $results['deleted'],
                    $results['errors']
                ),
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX: Reconcile rosters failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => __('Reconciliation failed: ', 'intersoccer-reports-rosters') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle mark event completed AJAX request
     * 
     * @return void
     */
    public function handleMarkEventCompleted() {
        try {
            // Verify nonce
            check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'intersoccer-reports-rosters')]);
            }
            
            $event_signature = sanitize_text_field($_POST['event_signature'] ?? '');
            
            if (empty($event_signature)) {
                wp_send_json_error(['message' => __('Invalid event signature', 'intersoccer-reports-rosters')]);
            }
            
            $this->logger->info('AJAX: Mark event completed request', [
                'event_signature' => $event_signature
            ]);
            
            // Count affected entries
            global $wpdb;
            $table = $wpdb->prefix . 'intersoccer_rosters';
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE event_signature = %s",
                $event_signature
            ));
            
            // Update all entries with this event signature
            $updated = $wpdb->update(
                $table,
                ['event_completed' => 1],
                ['event_signature' => $event_signature],
                ['%d'],
                ['%s']
            );
            
            if ($updated === false) {
                throw new \Exception($wpdb->last_error);
            }
            
            // Clear caches
            $this->roster_repository->clearAllCaches();
            
            wp_send_json_success([
                'message' => sprintf(
                    __('%d roster entries marked as completed', 'intersoccer-reports-rosters'),
                    $count
                ),
                'count' => $count
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX: Mark event completed failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => __('Failed to mark event as completed: ', 'intersoccer-reports-rosters') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle rebuild event signatures AJAX request
     * 
     * @return void
     */
    public function handleRebuildEventSignatures() {
        try {
            // Verify nonce
            check_ajax_referer('intersoccer_rebuild_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'intersoccer-reports-rosters')]);
            }
            
            $this->logger->info('AJAX: Rebuild event signatures request started');
            
            // Run rebuild
            $results = $this->roster_builder->rebuildSignatures();
            
            wp_send_json_success([
                'message' => sprintf(
                    __('Event signatures rebuilt. Updated: %d, Errors: %d', 'intersoccer-reports-rosters'),
                    $results['updated'],
                    $results['errors']
                ),
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX: Rebuild event signatures failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => __('Event signature rebuild failed: ', 'intersoccer-reports-rosters') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle upgrade database AJAX request
     * 
     * @return void
     */
    public function handleUpgradeDatabase() {
        try {
            // Verify nonce
            check_ajax_referer('intersoccer_rebuild_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'intersoccer-reports-rosters')]);
            }
            
            $this->logger->info('AJAX: Upgrade database request started');
            
            // Run migration
            $success = $this->database_migrator->migrate();
            
            if ($success) {
                wp_send_json_success([
                    'message' => __('Database upgraded successfully', 'intersoccer-reports-rosters'),
                    'version' => DatabaseMigrator::CURRENT_VERSION
                ]);
            } else {
                wp_send_json_error([
                    'message' => __('Database upgrade failed', 'intersoccer-reports-rosters')
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX: Upgrade database failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => __('Database upgrade failed: ', 'intersoccer-reports-rosters') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle sync placeholders AJAX request
     * 
     * @return void
     */
    public function handleSyncPlaceholders() {
        try {
            // Verify nonce
            check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'intersoccer-reports-rosters')]);
            }
            
            $this->logger->info('AJAX: Sync placeholders request started');
            
            // Run sync
            $results = $this->placeholder_manager->syncAll();
            
            wp_send_json_success([
                'message' => sprintf(
                    __('Placeholder sync completed. Processed: %d products, Created: %d, Updated: %d', 'intersoccer-reports-rosters'),
                    $results['processed'],
                    $results['created'],
                    $results['updated']
                ),
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX: Sync placeholders failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => __('Placeholder sync failed: ', 'intersoccer-reports-rosters') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle cleanup placeholders AJAX request
     * 
     * @return void
     */
    public function handleCleanupPlaceholders() {
        try {
            // Verify nonce
            check_ajax_referer('intersoccer_reports_rosters_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Unauthorized', 'intersoccer-reports-rosters')]);
            }
            
            $this->logger->info('AJAX: Cleanup placeholders request started');
            
            // Run cleanup
            $results = $this->placeholder_manager->cleanup();
            
            wp_send_json_success([
                'message' => sprintf(
                    __('Placeholder cleanup completed. Deleted: %d orphaned placeholders', 'intersoccer-reports-rosters'),
                    $results['deleted']
                ),
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX: Cleanup placeholders failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => __('Placeholder cleanup failed: ', 'intersoccer-reports-rosters') . $e->getMessage()
            ]);
        }
    }
}



