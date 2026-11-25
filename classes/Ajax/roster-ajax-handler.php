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
use InterSoccer\ReportsRosters\Services\ReportsRostersDiagnosticsService;
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
        add_action('wp_ajax_intersoccer_reconcile_alignment_batch', [$this, 'handleReconcileAlignmentBatch']);
        add_action('wp_ajax_intersoccer_mark_event_completed', [$this, 'handleMarkEventCompleted']);
        
        // Event signature operations
        add_action('wp_ajax_intersoccer_rebuild_event_signatures', [$this, 'handleRebuildEventSignatures']);
        add_action('wp_ajax_intersoccer_renormalize_roster_language', [$this, 'handleRenormalizeRosterLanguage']);
        
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
        if (function_exists('intersoccer_rr_repair_block_legacy_ajax')) {
            intersoccer_rr_repair_block_legacy_ajax('rebuild_rosters');
            return;
        }
        wp_send_json_error([
            'message' => __('This repair is not available in admin.', 'intersoccer-reports-rosters'),
        ], 403);
    }
    
    /**
     * Handle reconcile rosters AJAX request
     * 
     * @return void
     */
    public function handleReconcileRosters() {
        if (function_exists('intersoccer_rr_repair_block_legacy_ajax')) {
            intersoccer_rr_repair_block_legacy_ajax('reconcile_rosters');
            return;
        }
        wp_send_json_error([
            'message' => __('This repair is not available in admin.', 'intersoccer-reports-rosters'),
        ], 403);
    }

    /**
     * Process the next batch of post-reconcile sync queue alignment.
     *
     * @return void
     */
    public function handleReconcileAlignmentBatch() {
        if (function_exists('intersoccer_rr_repair_block_legacy_ajax')) {
            intersoccer_rr_repair_block_legacy_ajax('reconcile_rosters');
            return;
        }
        wp_send_json_error([
            'message' => __('This repair is not available in admin.', 'intersoccer-reports-rosters'),
        ], 403);
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
    public function handleRenormalizeRosterLanguage() {
        if (function_exists('intersoccer_rr_repair_block_legacy_ajax')) {
            intersoccer_rr_repair_block_legacy_ajax('normalize_language');
            return;
        }
        wp_send_json_error([
            'message' => __('This repair is not available in admin.', 'intersoccer-reports-rosters'),
        ], 403);
    }

    public function handleRebuildEventSignatures() {
        if (function_exists('intersoccer_rr_repair_block_legacy_ajax')) {
            intersoccer_rr_repair_block_legacy_ajax('rebuild_event_signatures');
            return;
        }
        wp_send_json_error([
            'message' => __('This repair is not available in admin.', 'intersoccer-reports-rosters'),
        ], 403);
    }
    
    /**
     * Map DatabaseMigrator::migrate() result to AJAX payload (testable without wp_send_json_*).
     *
     * @param string|bool $result One of DatabaseMigrator::RESULT_* (legacy false => failed).
     * @return array{success:bool,data:array{message:string,status:string,version:string}}
     */
    public static function buildUpgradeDatabaseAjaxPayload($result) {
        $version = get_option(DatabaseMigrator::VERSION_OPTION, '1.0.0');
        $schema_label = DatabaseMigrator::CURRENT_VERSION;

        if ($result === DatabaseMigrator::RESULT_ALREADY_RUNNING) {
            return [
                'success' => true,
                'data' => [
                    'message' => __('Migration already running. Please wait and refresh.', 'intersoccer-reports-rosters'),
                    'status' => DatabaseMigrator::RESULT_ALREADY_RUNNING,
                    'version' => $version,
                ],
            ];
        }

        if ($result === DatabaseMigrator::RESULT_SKIPPED_UNRECOGNISED_BACKUP) {
            return [
                'success' => true,
                'data' => [
                    'message' => __('Skipped: unrecognised backup table present. Drop the foreign roster backup or contact support before retrying.', 'intersoccer-reports-rosters'),
                    'status' => DatabaseMigrator::RESULT_SKIPPED_UNRECOGNISED_BACKUP,
                    'version' => $version,
                ],
            ];
        }

        if ($result === DatabaseMigrator::RESULT_ALREADY_CURRENT) {
            return [
                'success' => true,
                'data' => [
                    'message' => sprintf(
                        /* translators: %s: schema version */
                        __('Database is already up to date (schema %s). No changes were made.', 'intersoccer-reports-rosters'),
                        $schema_label
                    ),
                    'status' => DatabaseMigrator::RESULT_ALREADY_CURRENT,
                    'version' => $version,
                ],
            ];
        }

        if ($result === DatabaseMigrator::RESULT_UPGRADED) {
            return [
                'success' => true,
                'data' => [
                    'message' => sprintf(
                        /* translators: %s: schema version */
                        __('Database upgrade completed. Schema version is now %s.', 'intersoccer-reports-rosters'),
                        $version
                    ),
                    'status' => DatabaseMigrator::RESULT_UPGRADED,
                    'version' => $version,
                ],
            ];
        }

        return [
            'success' => false,
            'data' => [
                'message' => __('Database upgrade failed. Check logs for details.', 'intersoccer-reports-rosters'),
                'status' => DatabaseMigrator::RESULT_FAILED,
                'version' => $version,
            ],
        ];
    }

    /**
     * Handle upgrade database AJAX request
     * 
     * @return void
     */
    public function handleUpgradeDatabase() {
        if (function_exists('intersoccer_rr_repair_block_legacy_ajax')) {
            intersoccer_rr_repair_block_legacy_ajax('upgrade_database');
            return;
        }
        wp_send_json_error([
            'message' => __('This repair is not available in admin.', 'intersoccer-reports-rosters'),
        ], 403);
    }
    
    /**
     * Handle sync placeholders AJAX request
     * 
     * @return void
     */
    public function handleSyncPlaceholders() {
        if (function_exists('intersoccer_rr_repair_block_legacy_ajax')) {
            intersoccer_rr_repair_block_legacy_ajax('sync_placeholders');
            return;
        }
        wp_send_json_error([
            'message' => __('This repair is not available in admin.', 'intersoccer-reports-rosters'),
        ], 403);
    }
    
    /**
     * Handle cleanup placeholders AJAX request
     * 
     * @return void
     */
    public function handleCleanupPlaceholders() {
        if (function_exists('intersoccer_rr_repair_block_legacy_ajax')) {
            intersoccer_rr_repair_block_legacy_ajax('sync_placeholders');
            return;
        }
        wp_send_json_error([
            'message' => __('This repair is not available in admin.', 'intersoccer-reports-rosters'),
        ], 403);
    }
}



