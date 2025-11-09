<?php
/**
 * Adapter: Get girls-only roster listings via OOP
 */
function intersoccer_oop_get_girls_only_listings($filters = [], $context = []) {
    try {
        return intersoccer_oop_get_roster_listing_service()->getGirlsOnlyListings($filters, $context);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error getting girls-only listings - ' . $e->getMessage());
        return [
            'query_time' => 0,
            'all_groups' => [],
            'display_groups' => [],
            'grouped_camps' => [],
            'grouped_courses' => [],
            'filters' => [
                'seasons' => [],
                'venues' => [],
                'camp_terms' => [],
                'course_days' => [],
                'age_groups' => [],
                'cities' => [],
            ],
        ];
    }
}

/**
 * Adapter: Get tournament roster listings via OOP
 */
function intersoccer_oop_get_tournament_listings($filters = [], $context = []) {
    try {
        return intersoccer_oop_get_roster_listing_service()->getTournamentListings($filters, $context);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error getting tournament listings - ' . $e->getMessage());
        return [
            'query_time' => 0,
            'all_groups' => [],
            'display_groups' => [],
            'grouped' => [],
            'filters' => [
                'seasons' => [],
                'venues' => [],
                'age_groups' => [],
                'cities' => [],
                'times' => [],
            ],
        ];
    }
}

/**
 * OOP Adapter - Bridges Legacy Functions to New OOP Classes
 * 
 * This file provides adapter functions that map legacy procedural functions
 * to the new OOP architecture. This allows gradual migration while maintaining
 * backward compatibility.
 * 
 * @package InterSoccer\ReportsRosters
 * @version 2.0.0
 */

defined('ABSPATH') or die('Restricted access');

// Only load if OOP is enabled
if (!defined('INTERSOCCER_OOP_ENABLED') || !INTERSOCCER_OOP_ENABLED) {
    error_log('InterSoccer OOP Adapter: OOP not enabled, skipping adapter layer');
    return;
}

use InterSoccer\ReportsRosters\Core\Plugin;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\DatabaseMigrator;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\EventSignatureGenerator;
use InterSoccer\ReportsRosters\Services\PlaceholderManager;
use InterSoccer\ReportsRosters\Services\RosterExportService;
use InterSoccer\ReportsRosters\Services\FinancialReportService;
use InterSoccer\ReportsRosters\Services\RosterDetailsService;
use InterSoccer\ReportsRosters\Services\RosterListingService;
use InterSoccer\ReportsRosters\WooCommerce\OrderProcessor;
use InterSoccer\ReportsRosters\WooCommerce\DiscountCalculator;
use InterSoccer\ReportsRosters\Ajax\RosterAjaxHandler;
use InterSoccer\ReportsRosters\Reports\CampReport;
use InterSoccer\ReportsRosters\Reports\OverviewReport;
use InterSoccer\ReportsRosters\Export\ExcelExporter;
use InterSoccer\ReportsRosters\Export\CSVExporter;

error_log('InterSoccer OOP Adapter: Loading adapter layer');

/**
 * Get OOP Plugin instance
 * 
 * @return Plugin
 */
function intersoccer_oop_get_plugin() {
    return Plugin::get_instance();
}

/**
 * Get OOP Database instance
 * 
 * @return Database
 */
function intersoccer_oop_get_database() {
    $plugin = intersoccer_oop_get_plugin();
    return $plugin->get_database();
}

/**
 * Get OOP RosterRepository instance
 * 
 * @return RosterRepository
 */
function intersoccer_oop_get_roster_repository() {
    static $repository = null;
    if ($repository === null) {
        $plugin = intersoccer_oop_get_plugin();
        $repository = new RosterRepository(
            $plugin->get_logger(),
            $plugin->get_database(),
            $plugin->get_cache()
        );
    }
    return $repository;
}

/**
 * Get OOP PlayerRepository instance
 * 
 * @return PlayerRepository
 */
function intersoccer_oop_get_player_repository() {
    static $repository = null;
    if ($repository === null) {
        $plugin = intersoccer_oop_get_plugin();
        $repository = new PlayerRepository(
            $plugin->get_logger(),
            $plugin->get_database(),
            $plugin->get_cache()
        );
    }
    return $repository;
}

// ============================================================================
// DATABASE OPERATIONS - Adapters
// ============================================================================

/**
 * Adapter: Create rosters table using OOP
 * 
 * @return bool Success status
 */
function intersoccer_oop_create_rosters_table() {
    try {
        $database = intersoccer_oop_get_database();
        $result = $database->create_tables();
        error_log('InterSoccer OOP: Created rosters table via Database class');
        return $result;
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error creating table - ' . $e->getMessage());
        return false;
    }
}

/**
 * Adapter: Validate rosters table using OOP
 * 
 * @return bool Validation passed
 */
function intersoccer_oop_validate_rosters_table() {
    try {
        $database = intersoccer_oop_get_database();
        $validation = $database->validate_table_schema('intersoccer_rosters');
        
        if (!$validation['exists']) {
            error_log('InterSoccer OOP: Rosters table does not exist');
            return false;
        }
        
        if (!empty($validation['missing_columns'])) {
            error_log('InterSoccer OOP: Missing columns in rosters table: ' . implode(', ', $validation['missing_columns']));
        }
        
        return $validation['exists'];
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error validating table - ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// ORDER PROCESSING - Adapters
// ============================================================================

/**
 * Adapter: Process order using OOP
 * 
 * @param int $order_id Order ID
 * @return bool Success status
 */
function intersoccer_oop_process_order($order_id) {
    try {
        $processor = intersoccer_oop_get_order_processor();
        return $processor->processOrder($order_id);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error processing order - ' . $e->getMessage());
        return false;
    }
}

/**
 * Adapter: Process batch of orders using OOP
 * 
 * @param array $order_ids Array of order IDs
 * @return array Results
 */
function intersoccer_oop_process_orders_batch($order_ids) {
    try {
        $processor = intersoccer_oop_get_order_processor();
        return $processor->process_batch($order_ids);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error processing orders batch - ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================================================
// ROSTER OPERATIONS - Adapters
// ============================================================================

/**
 * Adapter: Get roster entries using OOP
 * 
 * @param array $filters Filter criteria
 * @return array Roster entries
 */
function intersoccer_oop_get_rosters($filters = []) {
    try {
        $repository = intersoccer_oop_get_roster_repository();
        $rosters = $repository->findBy($filters);
        return $rosters->toArray();
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error getting rosters - ' . $e->getMessage());
        return [];
    }
}

/**
 * Adapter: Get roster by ID using OOP
 * 
 * @param int $roster_id Roster ID
 * @return array|null Roster data or null
 */
function intersoccer_oop_get_roster($roster_id) {
    try {
        $repository = intersoccer_oop_get_roster_repository();
        $roster = $repository->find($roster_id);
        return $roster ? $roster->toArray() : null;
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error getting roster - ' . $e->getMessage());
        return null;
    }
}

// ============================================================================
// REPORT GENERATION - Adapters
// ============================================================================

/**
 * Adapter: Generate camp report using OOP
 * 
 * @param array $filters Report filters
 * @return array Report data
 */
function intersoccer_oop_generate_camp_report($filters = []) {
    try {
        $plugin = intersoccer_oop_get_plugin();
        $report = new CampReport($plugin->get_logger());
        return $report->generate($filters);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error generating camp report - ' . $e->getMessage());
        return [];
    }
}

/**
 * Adapter: Generate overview report using OOP
 * 
 * @param array $filters Report filters
 * @return array Report data
 */
function intersoccer_oop_generate_overview_report($filters = []) {
    try {
        $plugin = intersoccer_oop_get_plugin();
        $report = new OverviewReport($plugin->get_logger());
        return $report->generate($filters);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error generating overview report - ' . $e->getMessage());
        return [];
    }
}

// ============================================================================
// EXPORT OPERATIONS - Adapters
// ============================================================================

/**
 * Adapter: Export to Excel using OOP
 * 
 * @param array $data Data to export
 * @param string $filename Filename
 * @return string|false File path or false
 */
function intersoccer_oop_export_excel($data, $filename = null) {
    try {
        $plugin = intersoccer_oop_get_plugin();
        $exporter = new ExcelExporter($plugin->get_logger());
        return $exporter->export($data, $filename);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error exporting to Excel - ' . $e->getMessage());
        return false;
    }
}

/**
 * Adapter: Export to CSV using OOP
 * 
 * @param array $data Data to export
 * @param string $filename Filename
 * @return string|false File path or false
 */
function intersoccer_oop_export_csv($data, $filename = null) {
    try {
        $plugin = intersoccer_oop_get_plugin();
        $exporter = new CSVExporter($plugin->get_logger());
        return $exporter->export($data, $filename);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error exporting to CSV - ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// MIGRATION FLAGS - Feature flags for gradual migration
// ============================================================================

/**
 * Check if OOP should be used for a specific feature
 * 
 * @param string $feature Feature name (database, orders, rosters, reports, etc.)
 * @return bool Use OOP for this feature
 */
function intersoccer_use_oop_for($feature) {
    if (!defined('INTERSOCCER_OOP_ENABLED') || !INTERSOCCER_OOP_ENABLED) {
        return false;
    }

    $defaults = [
        'database' => true,
        'ajax' => true,
        'orders' => true,
        'rosters' => true,
        'reports' => true,
        'export' => true,
        'admin' => false,
        'all' => false,
    ];

    $stored_flags = get_option('intersoccer_oop_features', []);
    $feature_flags = is_array($stored_flags) ? array_merge($defaults, $stored_flags) : $defaults;

    return isset($feature_flags[$feature]) ? (bool) $feature_flags[$feature] : false;
}

/**
 * Enable OOP for a specific feature
 * 
 * @param string $feature Feature name
 * @return bool Success
 */
function intersoccer_enable_oop_feature($feature) {
    $features = get_option('intersoccer_oop_features', []);
    $features[$feature] = true;
    return update_option('intersoccer_oop_features', $features);
}

/**
 * Disable OOP for a specific feature (rollback)
 * 
 * @param string $feature Feature name
 * @return bool Success
 */
function intersoccer_disable_oop_feature($feature) {
    $features = get_option('intersoccer_oop_features', []);
    $features[$feature] = false;
    return update_option('intersoccer_oop_features', $features);
}

/**
 * Ensure default OOP features are enabled when migrating
 *
 * @param array $features Feature keys to enable
 * @return void
 */
function intersoccer_oop_enable_defaults(array $features) {
    $version_option = 'intersoccer_oop_defaults_version';
    $current_version = '2025-11-07-db-ajax-orders-reports-rosters';

    $applied_version = get_option($version_option);
    if ($applied_version === $current_version) {
        return;
    }

    $flags = get_option('intersoccer_oop_features', []);
    $changed = false;

    foreach ($features as $feature) {
        if (!isset($flags[$feature]) || $flags[$feature] !== true) {
            $flags[$feature] = true;
            $changed = true;
        }
    }

    if ($changed) {
        update_option('intersoccer_oop_features', $flags);
    }

    update_option($version_option, $current_version);
}

error_log('InterSoccer OOP Adapter: Adapter layer loaded successfully');

// Register AJAX handlers when enabled via feature flag
if (intersoccer_use_oop_for('ajax') || intersoccer_use_oop_for('database')) {
    intersoccer_oop_register_roster_ajax_handlers();
}

// Enable default features now that OOP paths are stable
intersoccer_oop_enable_defaults(['database', 'ajax', 'orders', 'reports', 'export', 'rosters']);


// ============================================================================
// ORDER PROCESSING - Adapters
// ============================================================================

/**
 * Get OOP OrderProcessor instance
 * 
 * @return OrderProcessor
 */
function intersoccer_oop_get_order_processor() {
    static $processor = null;
    if ($processor === null) {
        $plugin = intersoccer_oop_get_plugin();
        $processor = new OrderProcessor(
            $plugin->get_logger(),
            intersoccer_oop_get_roster_repository(),
            intersoccer_oop_get_roster_builder()
        );
    }
    return $processor;
}

/**
 * Get OOP RosterBuilder instance
 * 
 * @return RosterBuilder
 */
function intersoccer_oop_get_roster_builder() {
    static $builder = null;
    if ($builder === null) {
        $plugin = intersoccer_oop_get_plugin();
        $builder = new RosterBuilder(
            $plugin->get_logger(),
            $plugin->get_database(),
            intersoccer_oop_get_player_repository(),
            intersoccer_oop_get_roster_repository(),
            new DataValidator($plugin->get_logger()),
            new EventMatcher($plugin->get_logger()),
            new PlayerMatcher($plugin->get_logger())
        );
    }
    return $builder;
}

/**
 * Get OOP EventSignatureGenerator instance
 * 
 * @return EventSignatureGenerator
 */
function intersoccer_oop_get_signature_generator() {
    static $generator = null;
    if ($generator === null) {
        $plugin = intersoccer_oop_get_plugin();
        $generator = new EventSignatureGenerator($plugin->get_logger());
    }
    return $generator;
}

/**
 * Get OOP PlaceholderManager instance
 * 
 * @return PlaceholderManager
 */
function intersoccer_oop_get_placeholder_manager() {
    static $manager = null;
    if ($manager === null) {
        $plugin = intersoccer_oop_get_plugin();
        $manager = new PlaceholderManager(
            $plugin->get_logger(),
            intersoccer_oop_get_roster_repository(),
            intersoccer_oop_get_signature_generator()
        );
    }
    return $manager;
}

/**
 * Get OOP RosterExportService instance
 */
function intersoccer_oop_get_roster_export_service() {
    static $service = null;
    if ($service === null) {
        $plugin = intersoccer_oop_get_plugin();
        $service = new RosterExportService(
            $plugin->get_logger(),
            $plugin->get_database()
        );
    }
    return $service;
}

/**
 * Get OOP FinancialReportService instance
 */
function intersoccer_oop_get_financial_report_service() {
    static $service = null;
    if ($service === null) {
        $plugin = intersoccer_oop_get_plugin();
        $service = new FinancialReportService(
            $plugin->get_logger(),
            $plugin->get_database()
        );
    }
    return $service;
}

/**
 * Get OOP RosterDetailsService instance
 */
function intersoccer_oop_get_roster_details_service() {
    static $service = null;
    if ($service === null) {
        $plugin = intersoccer_oop_get_plugin();
        $service = new RosterDetailsService(
            $plugin->get_logger(),
            intersoccer_oop_get_roster_repository(),
            $plugin->get_database()
        );
    }
    return $service;
}

/**
 * Get OOP RosterListingService instance
 */
function intersoccer_oop_get_roster_listing_service() {
    static $service = null;
    if ($service === null) {
        $service = new RosterListingService(
            intersoccer_oop_get_plugin()->get_logger(),
            intersoccer_oop_get_roster_repository()
        );
    }
    return $service;
}

/**
 * Get OOP Roster AJAX handler instance
 *
 * @return RosterAjaxHandler
 */
function intersoccer_oop_get_roster_ajax_handler() {
    static $handler = null;
    if ($handler === null) {
        $plugin = intersoccer_oop_get_plugin();
        $handler = new RosterAjaxHandler(
            $plugin->get_logger(),
            intersoccer_oop_get_roster_repository(),
            intersoccer_oop_get_roster_builder(),
            intersoccer_oop_get_placeholder_manager(),
            intersoccer_oop_get_signature_generator(),
            intersoccer_oop_get_database_migrator()
        );
    }
    return $handler;
}

/**
 * Register OOP AJAX handlers
 *
 * @return void
 */
function intersoccer_oop_register_roster_ajax_handlers() {
    intersoccer_oop_get_roster_ajax_handler()->register();
}

/**
 * Get OOP DatabaseMigrator instance
 * 
 * @return DatabaseMigrator
 */
function intersoccer_oop_get_database_migrator() {
    static $migrator = null;
    if ($migrator === null) {
        $plugin = intersoccer_oop_get_plugin();
        $migrator = new DatabaseMigrator($plugin->get_logger());
    }
    return $migrator;
}

/**
 * Adapter: Rebuild rosters using OOP
 * 
 * @param array $options Rebuild options
 * @return array Results
 */
function intersoccer_oop_rebuild_rosters($options = []) {
    try {
        $builder = intersoccer_oop_get_roster_builder();
        return $builder->rebuildAll($options);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error rebuilding rosters - ' . $e->getMessage());
        return ['processed' => 0, 'created' => 0, 'errors' => 1, 'error_messages' => [$e->getMessage()]];
    }
}

/**
 * Adapter: Reconcile rosters using OOP
 * 
 * @param array $options Reconcile options
 * @return array Results
 */
function intersoccer_oop_reconcile_rosters($options = []) {
    try {
        $builder = intersoccer_oop_get_roster_builder();
        return $builder->reconcile($options);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error reconciling rosters - ' . $e->getMessage());
        return ['synced' => 0, 'deleted' => 0, 'errors' => 1];
    }
}

/**
 * Adapter: Rebuild event signatures using OOP
 * 
 * @return array Results
 */
function intersoccer_oop_rebuild_event_signatures() {
    try {
        $builder = intersoccer_oop_get_roster_builder();
        return $builder->rebuildSignatures();
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error rebuilding event signatures - ' . $e->getMessage());
        return ['updated' => 0, 'errors' => 1];
    }
}

/**
 * Adapter: Process order item using OOP
 * 
 * @param int $order_id Order ID
 * @param int $item_id Order item ID
 * @return array Results
 */
function intersoccer_oop_update_roster_entry($order_id, $item_id) {
    try {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['success' => false, 'message' => 'Invalid order ID'];
        }
        
        $item = $order->get_item($item_id);
        if (!$item) {
            return ['success' => false, 'message' => 'Invalid item ID'];
        }
        
        $processor = intersoccer_oop_get_order_processor();
        return $processor->processOrderItem($order, $item, $item_id);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error updating roster entry - ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Adapter: Create placeholders for product using OOP
 * 
 * @param int $product_id Product ID
 * @return array Results
 */
function intersoccer_oop_create_placeholders_for_product($product_id) {
    try {
        $manager = intersoccer_oop_get_placeholder_manager();
        return $manager->createForProduct($product_id);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error creating placeholders - ' . $e->getMessage());
        return ['created' => 0, 'updated' => 0, 'skipped' => 0];
    }
}

/**
 * Adapter: Delete placeholders for product using OOP
 * 
 * @param int $product_id Product ID
 * @return int Number deleted
 */
function intersoccer_oop_delete_placeholders_for_product($product_id) {
    try {
        $manager = intersoccer_oop_get_placeholder_manager();
        return $manager->deleteForProduct($product_id);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error deleting placeholders - ' . $e->getMessage());
        return 0;
    }
}

/**
 * Adapter: Delete placeholders by signature using OOP
 * 
 * @param string $event_signature Event signature
 * @return int Number deleted
 */
function intersoccer_oop_delete_placeholder_by_signature($event_signature) {
    try {
        $manager = intersoccer_oop_get_placeholder_manager();
        return $manager->deleteBySignature($event_signature);
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error deleting placeholder - ' . $e->getMessage());
        return 0;
    }
}

/**
 * Adapter: Upgrade database using OOP
 * 
 * @return bool Success status
 */
function intersoccer_oop_upgrade_database() {
    try {
        $migrator = intersoccer_oop_get_database_migrator();
        return $migrator->migrate();
    } catch (\Exception $e) {
        error_log('InterSoccer OOP: Error upgrading database - ' . $e->getMessage());
        return false;
    }
}
