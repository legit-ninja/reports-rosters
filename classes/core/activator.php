<?php
/**
 * Activator Class
 * 
 * Handles plugin activation for InterSoccer Reports & Rosters.
 * Sets up database tables, default settings, and validates environment.
 * 
 * @package InterSoccer\ReportsRosters\Core
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Core;

use InterSoccer\ReportsRosters\Exceptions\DatabaseException;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activator Class
 * 
 * Manages plugin activation process with comprehensive validation
 */
class Activator {
    
    /**
     * Database instance
     * 
     * @var Database
     */
    private $database;
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Activation steps and their status
     * 
     * @var array
     */
    private $activation_steps = [];
    
    /**
     * Default plugin options
     * 
     * @var array
     */
    private $default_options = [
        'intersoccer_plugin_version' => '2.1.27',
        'intersoccer_db_version' => '2.0.0',
        'intersoccer_activation_time' => null,
        'intersoccer_last_roster_rebuild' => null,
        'intersoccer_cache_enabled' => true,
        'intersoccer_cache_expiry' => 3600, // 1 hour
        'intersoccer_log_level' => 'info',
        'intersoccer_export_batch_size' => 500,
        'intersoccer_auto_rebuild_rosters' => true,
        'intersoccer_cleanup_old_logs' => true,
        'intersoccer_max_log_entries' => 1000,
    ];
    
    /**
     * Constructor
     * 
     * @param Database $database Database instance
     * @param Logger $logger Logger instance
     */
    public function __construct(Database $database, Logger $logger) {
        $this->database = $database;
        $this->logger = $logger;
        
        $this->init_activation_steps();
    }
    
    /**
     * Initialize activation steps
     * 
     * @return void
     */
    private function init_activation_steps() {
        $this->activation_steps = [
            'validate_environment' => [
                'name' => 'Validate Environment',
                'status' => 'pending',
                'error' => null,
                'callback' => [$this, 'validate_environment']
            ],
            'create_database_tables' => [
                'name' => 'Create Database Tables',
                'status' => 'pending',
                'error' => null,
                'callback' => [$this, 'create_database_tables']
            ],
            'set_default_options' => [
                'name' => 'Set Default Options',
                'status' => 'pending',
                'error' => null,
                'callback' => [$this, 'set_default_options']
            ],
            'validate_database_schema' => [
                'name' => 'Validate Database Schema',
                'status' => 'pending',
                'error' => null,
                'callback' => [$this, 'validate_database_schema']
            ],
            'setup_capabilities' => [
                'name' => 'Setup User Capabilities',
                'status' => 'pending',
                'error' => null,
                'callback' => [$this, 'setup_capabilities']
            ],
            'initial_data_setup' => [
                'name' => 'Initial Data Setup',
                'status' => 'pending',
                'error' => null,
                'callback' => [$this, 'initial_data_setup']
            ],
            'schedule_cron_jobs' => [
                'name' => 'Schedule Maintenance Tasks',
                'status' => 'pending',
                'error' => null,
                'callback' => [$this, 'schedule_cron_jobs']
            ]
        ];
    }
    
    /**
     * Run plugin activation
     * 
     * @throws \Exception If activation fails
     * @return bool Success status
     */
    public function activate() {
        try {
            $this->logger->info('Plugin activation started');
            
            $start_time = microtime(true);
            $success_count = 0;
            $total_steps = count($this->activation_steps);
            
            // Execute each activation step
            foreach ($this->activation_steps as $step_key => &$step) {
                try {
                    $this->logger->debug("Executing activation step: {$step['name']}");
                    
                    $step_start = microtime(true);
                    $result = call_user_func($step['callback']);
                    $step_duration = microtime(true) - $step_start;
                    
                    if ($result === true) {
                        $step['status'] = 'completed';
                        $success_count++;
                        $this->logger->debug("Activation step completed: {$step['name']}", [
                            'duration' => round($step_duration, 3) . 's'
                        ]);
                    } else {
                        throw new \Exception("Step returned false: {$step['name']}");
                    }
                    
                } catch (\Exception $e) {
                    $step['status'] = 'failed';
                    $step['error'] = $e->getMessage();
                    
                    $this->logger->error("Activation step failed: {$step['name']}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    // Attempt rollback
                    $this->rollback_activation();
                    
                    throw new \Exception("Activation failed at step '{$step['name']}': {$e->getMessage()}");
                }
            }
            
            $total_duration = microtime(true) - $start_time;
            
            // Set activation timestamp
            update_option('intersoccer_activation_time', current_time('mysql'));
            update_option('intersoccer_activation_status', 'completed');
            
            $this->logger->info('Plugin activation completed successfully', [
                'total_steps' => $total_steps,
                'successful_steps' => $success_count,
                'total_duration' => round($total_duration, 3) . 's'
            ]);
            
            // Trigger activation complete hook
            do_action('intersoccer_plugin_activated');
            
            return true;
            
        } catch (\Exception $e) {
            update_option('intersoccer_activation_status', 'failed');
            update_option('intersoccer_activation_error', $e->getMessage());
            
            $this->logger->critical('Plugin activation failed', [
                'error' => $e->getMessage(),
                'steps_status' => $this->get_activation_status()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Validate environment before activation
     * 
     * @return bool Validation passed
     */
    private function validate_environment() {
        // Check if this is a fresh activation or upgrade
        $current_version = get_option('intersoccer_plugin_version');
        $is_upgrade = !empty($current_version);
        
        if ($is_upgrade) {
            $this->logger->info('Detected plugin upgrade', [
                'from_version' => $current_version,
                'to_version' => $this->default_options['intersoccer_plugin_version']
            ]);
        }
        
        // Validate WordPress environment
        if (!current_user_can('activate_plugins')) {
            throw new \Exception('Insufficient permissions to activate plugin');
        }
        
        // Check for conflicting plugins
        $this->check_conflicting_plugins();
        
        // Validate file system permissions
        $this->validate_file_permissions();
        
        $this->logger->debug('Environment validation completed');
        return true;
    }
    
    /**
     * Check for conflicting plugins
     * 
     * @throws \Exception If conflicting plugins found
     * @return void
     */
    private function check_conflicting_plugins() {
        $conflicting_plugins = [
            // Add any known conflicting plugins here
        ];
        
        $active_conflicts = [];
        foreach ($conflicting_plugins as $plugin_path => $plugin_name) {
            if (is_plugin_active($plugin_path)) {
                $active_conflicts[] = $plugin_name;
            }
        }
        
        if (!empty($active_conflicts)) {
            throw new \Exception('Conflicting plugins detected: ' . implode(', ', $active_conflicts));
        }
    }
    
    /**
     * Validate file system permissions
     * 
     * @throws \Exception If permissions inadequate
     * @return void
     */
    private function validate_file_permissions() {
        $required_paths = [
            WP_CONTENT_DIR . '/uploads',
            WP_CONTENT_DIR . '/cache',
        ];
        
        $permission_issues = [];
        foreach ($required_paths as $path) {
            if (file_exists($path) && !is_writable($path)) {
                $permission_issues[] = $path;
            }
        }
        
        if (!empty($permission_issues)) {
            $this->logger->warning('File permission issues detected', [
                'paths' => $permission_issues
            ]);
            // Don't fail activation for this, just log it
        }
    }
    
    /**
     * Create database tables
     * 
     * @return bool Success status
     */
    private function create_database_tables() {
        try {
            $this->logger->debug('Creating database tables');
            
            $result = $this->database->create_tables();
            
            if (!$result) {
                throw new DatabaseException('Failed to create one or more database tables');
            }
            
            $this->logger->info('Database tables created successfully');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Database table creation failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Set default plugin options
     * 
     * @return bool Success status
     */
    private function set_default_options() {
        try {
            $this->logger->debug('Setting default plugin options');
            
            foreach ($this->default_options as $option_name => $default_value) {
                // Only set if option doesn't exist (preserve existing settings)
                if (get_option($option_name) === false) {
                    $value = $default_value;
                    
                    // Set dynamic default values
                    if ($option_name === 'intersoccer_activation_time') {
                        $value = current_time('mysql');
                    }
                    
                    add_option($option_name, $value);
                    
                    $this->logger->debug("Set default option: {$option_name}", [
                        'value' => $value
                    ]);
                }
            }
            
            $this->logger->info('Default options configured');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to set default options', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Validate database schema
     * 
     * @return bool Validation passed
     */
    public function validate_database_schema() {
        try {
            $this->logger->debug('Validating database schema');
            
            $tables_to_validate = [
                'intersoccer_rosters',
                'intersoccer_roster_cache'
            ];
            
            $validation_results = [];
            foreach ($tables_to_validate as $table) {
                $validation = $this->database->validate_table_schema($table);
                $validation_results[$table] = $validation;
                
                if (!$validation['exists']) {
                    throw new DatabaseException("Required table does not exist: {$table}");
                }
                
                if (!empty($validation['missing_columns'])) {
                    $this->logger->warning("Table has missing columns: {$table}", [
                        'missing_columns' => $validation['missing_columns']
                    ]);
                }
            }
            
            $this->logger->info('Database schema validation completed', $validation_results);
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Database schema validation failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Setup user capabilities
     * 
     * @return bool Success status
     */
    public function setup_capabilities() {
        try {
            $this->logger->debug('Setting up user capabilities');
            
            // Define custom capabilities
            $capabilities = [
                'manage_intersoccer_rosters' => ['administrator', 'shop_manager'],
                'export_intersoccer_rosters' => ['administrator', 'shop_manager'],
                'view_intersoccer_reports' => ['administrator', 'shop_manager', 'coach'],
                'manage_intersoccer_settings' => ['administrator'],
            ];
            
            foreach ($capabilities as $capability => $roles) {
                foreach ($roles as $role_name) {
                    $role = get_role($role_name);
                    if ($role) {
                        $role->add_cap($capability);
                        $this->logger->debug("Added capability {$capability} to role {$role_name}");
                    }
                }
            }
            
            $this->logger->info('User capabilities configured');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to setup capabilities', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Initial data setup
     * 
     * @return bool Success status
     */
    private function initial_data_setup() {
        try {
            $this->logger->debug('Performing initial data setup');
            
            // Check if this is first time activation
            $existing_rosters = $this->database->get_roster_entries_count();
            
            if ($existing_rosters === 0) {
                $this->logger->info('First time activation - no existing roster data');
                
                // Optionally trigger initial roster build
                $auto_rebuild = get_option('intersoccer_auto_rebuild_rosters', true);
                if ($auto_rebuild) {
                    $this->logger->info('Auto-rebuild enabled - scheduling roster rebuild');
                    wp_schedule_single_event(time() + 60, 'intersoccer_rebuild_rosters_cron');
                }
            } else {
                $this->logger->info('Existing roster data found', [
                    'existing_entries' => $existing_rosters
                ]);
            }
            
            // Set up any default taxonomy terms or meta values
            $this->setup_default_taxonomy_terms();
            
            $this->logger->info('Initial data setup completed');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Initial data setup failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Setup default taxonomy terms
     * 
     * @return void
     */
    private function setup_default_taxonomy_terms() {
        // Set up default terms for WooCommerce product attributes
        $default_terms = [
            'pa_activity-type' => ['Camp', 'Course', 'Birthday Party'],
            'pa_age-group' => ['3-5y (Half-Day)', '5-13y (Full Day)', '3-12y'],
            'pa_booking-type' => ['Full Week', 'Single Day(s)', 'Full Term'],
        ];
        
        foreach ($default_terms as $taxonomy => $terms) {
            if (taxonomy_exists($taxonomy)) {
                foreach ($terms as $term_name) {
                    if (!term_exists($term_name, $taxonomy)) {
                        wp_insert_term($term_name, $taxonomy);
                        $this->logger->debug("Created taxonomy term: {$term_name} in {$taxonomy}");
                    }
                }
            }
        }
    }
    
    /**
     * Schedule cron jobs
     * 
     * @return bool Success status
     */
    private function schedule_cron_jobs() {
        try {
            $this->logger->debug('Scheduling cron jobs');
            
            // Schedule cleanup job (daily)
            if (!wp_next_scheduled('intersoccer_daily_cleanup')) {
                wp_schedule_event(time(), 'daily', 'intersoccer_daily_cleanup');
                $this->logger->debug('Scheduled daily cleanup job');
            }
            
            // Schedule cache cleanup (hourly)
            if (!wp_next_scheduled('intersoccer_cache_cleanup')) {
                wp_schedule_event(time(), 'hourly', 'intersoccer_cache_cleanup');
                $this->logger->debug('Scheduled cache cleanup job');
            }
            
            // Schedule log cleanup (weekly)
            if (!wp_next_scheduled('intersoccer_log_cleanup')) {
                wp_schedule_event(time(), 'weekly', 'intersoccer_log_cleanup');
                $this->logger->debug('Scheduled log cleanup job');
            }
            
            $this->logger->info('Cron jobs scheduled');
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to schedule cron jobs', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Rollback activation on failure
     * 
     * @return void
     */
    private function rollback_activation() {
        try {
            $this->logger->warning('Starting activation rollback');
            
            // Remove created options
            foreach (array_keys($this->default_options) as $option_name) {
                delete_option($option_name);
            }
            
            // Remove capabilities
            $capabilities = [
                'manage_intersoccer_rosters',
                'export_intersoccer_rosters',
                'view_intersoccer_reports',
                'manage_intersoccer_settings',
            ];
            
            $roles = ['administrator', 'shop_manager', 'coach'];
            foreach ($roles as $role_name) {
                $role = get_role($role_name);
                if ($role) {
                    foreach ($capabilities as $capability) {
                        $role->remove_cap($capability);
                    }
                }
            }
            
            // Remove cron jobs
            wp_clear_scheduled_hook('intersoccer_daily_cleanup');
            wp_clear_scheduled_hook('intersoccer_cache_cleanup');
            wp_clear_scheduled_hook('intersoccer_log_cleanup');
            wp_clear_scheduled_hook('intersoccer_rebuild_rosters_cron');
            
            // Note: We don't drop database tables during rollback
            // as they might contain important data
            
            update_option('intersoccer_activation_status', 'rolled_back');
            
            $this->logger->info('Activation rollback completed');
            
        } catch (\Exception $e) {
            $this->logger->error('Rollback failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get activation status
     * 
     * @return array Activation status and step details
     */
    public function get_activation_status() {
        $completed_steps = array_filter($this->activation_steps, function($step) {
            return $step['status'] === 'completed';
        });
        
        $failed_steps = array_filter($this->activation_steps, function($step) {
            return $step['status'] === 'failed';
        });
        
        return [
            'total_steps' => count($this->activation_steps),
            'completed_steps' => count($completed_steps),
            'failed_steps' => count($failed_steps),
            'steps' => $this->activation_steps,
            'overall_status' => empty($failed_steps) ? 'success' : 'failed'
        ];
    }
    
    /**
     * Check if plugin is properly activated
     * 
     * @return bool Plugin is properly activated
     */
    public static function is_properly_activated() {
        $activation_status = get_option('intersoccer_activation_status');
        $plugin_version = get_option('intersoccer_plugin_version');
        
        return $activation_status === 'completed' && !empty($plugin_version);
    }
    
    /**
     * Get activation error if any
     * 
     * @return string|null Activation error message
     */
    public static function get_activation_error() {
        return get_option('intersoccer_activation_error', null);
    }
    
    /**
     * Force reactivation
     * 
     * @return bool Success status
     */
    public function force_reactivation() {
        try {
            $this->logger->info('Forcing plugin reactivation');
            
            // Clear activation status
            delete_option('intersoccer_activation_status');
            delete_option('intersoccer_activation_error');
            
            // Reset step status
            $this->init_activation_steps();
            
            // Run activation
            return $this->activate();
            
        } catch (\Exception $e) {
            $this->logger->error('Forced reactivation failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Upgrade from previous version
     * 
     * @param string $from_version Previous version
     * @param string $to_version New version
     * @return bool Success status
     */
    public function upgrade($from_version, $to_version) {
        try {
            $this->logger->info('Starting plugin upgrade', [
                'from' => $from_version,
                'to' => $to_version
            ]);
            
            // Run version-specific upgrade routines
            $upgrade_methods = [
                '1.0.0' => 'upgrade_from_1_0_0',
                '1.4.0' => 'upgrade_from_1_4_0',
            ];
            
            foreach ($upgrade_methods as $version => $method) {
                if (version_compare($from_version, $version, '<=') && 
                    version_compare($to_version, $version, '>')) {
                    
                    if (method_exists($this, $method)) {
                        $this->logger->debug("Running upgrade method: {$method}");
                        call_user_func([$this, $method]);
                    }
                }
            }
            
            // Update version numbers
            update_option('intersoccer_plugin_version', $to_version);
            update_option('intersoccer_db_version', $to_version);
            update_option('intersoccer_last_upgrade', current_time('mysql'));
            
            $this->logger->info('Plugin upgrade completed', [
                'from' => $from_version,
                'to' => $to_version
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Plugin upgrade failed', [
                'error' => $e->getMessage(),
                'from' => $from_version,
                'to' => $to_version
            ]);
            return false;
        }
    }
    
    /**
     * Upgrade from version 1.0.0
     * 
     * @return void
     */
    private function upgrade_from_1_0_0() {
        $this->logger->info('Upgrading from version 1.0.0');
        
        // Add any specific upgrade logic for 1.0.0 -> 2.0.0
        // Example: migrate old data format, add new columns, etc.
        
        // Rebuild rosters with new structure
        wp_schedule_single_event(time() + 300, 'intersoccer_rebuild_rosters_cron');
    }
    
    /**
     * Upgrade from version 1.4.0
     * 
     * @return void
     */
    private function upgrade_from_1_4_0() {
        $this->logger->info('Upgrading from version 1.4.x');
        
        // Add any specific upgrade logic for 1.4.x -> 2.0.0
        // Example: new indexes, updated schemas, etc.
    }
    
    /**
     * Validate activation requirements before running
     * 
     * @throws \Exception If requirements not met
     * @return void
     */
    public function validate_activation_requirements() {
        // Check if WordPress is properly loaded
        if (!function_exists('add_option')) {
            throw new \Exception('WordPress not properly loaded');
        }
        
        // Check database connectivity
        global $wpdb;
        if (!$wpdb) {
            throw new \Exception('Database connection not available');
        }
        
        // Test database connection
        $db_test = $wpdb->get_var("SELECT 1");
        if ($db_test !== '1') {
            throw new \Exception('Database connection test failed');
        }
        
        // Check if user has required permissions
        if (!current_user_can('activate_plugins')) {
            throw new \Exception('Insufficient permissions to activate plugin');
        }
        
        $this->logger->debug('Activation requirements validated');
    }
    
    /**
     * Get detailed activation report
     * 
     * @return array Detailed activation report
     */
    public function get_activation_report() {
        $status = $this->get_activation_status();
        
        return [
            'activation_time' => get_option('intersoccer_activation_time'),
            'plugin_version' => get_option('intersoccer_plugin_version'),
            'db_version' => get_option('intersoccer_db_version'),
            'activation_status' => get_option('intersoccer_activation_status'),
            'activation_error' => get_option('intersoccer_activation_error'),
            'last_upgrade' => get_option('intersoccer_last_upgrade'),
            'steps_status' => $status,
            'cron_jobs' => [
                'daily_cleanup' => wp_next_scheduled('intersoccer_daily_cleanup'),
                'cache_cleanup' => wp_next_scheduled('intersoccer_cache_cleanup'),
                'log_cleanup' => wp_next_scheduled('intersoccer_log_cleanup'),
            ],
            'database_tables' => [
                'rosters' => $this->database->table_exists('intersoccer_rosters'),
                'cache' => $this->database->table_exists('intersoccer_roster_cache'),
                'logs' => $this->database->table_exists('intersoccer_logs'),
            ]
        ];
    }
}