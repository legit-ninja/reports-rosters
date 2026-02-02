<?php
/**
 * Dependencies Class
 * 
 * Checks and validates plugin dependencies for InterSoccer Reports & Rosters.
 * Ensures all required plugins and system requirements are met.
 * 
 * @package InterSoccer\ReportsRosters\Core
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dependencies Class
 * 
 * Validates plugin dependencies and system requirements
 */
class Dependencies {
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Required plugins
     * 
     * @var array
     */
    private $required_plugins = [
        'woocommerce/woocommerce.php' => [
            'name' => 'WooCommerce',
            'version' => '5.0.0',
            'critical' => true,
            'url' => 'https://wordpress.org/plugins/woocommerce/',
        ],
        'intersoccer-product-variations/intersoccer-product-variations.php' => [
            'name' => 'InterSoccer Product Variations',
            'version' => '1.0.0',
            'critical' => true,
            'url' => 'https://github.com/legit-ninja/intersoccer-product-variations',
        ],
        'player-management/player-management.php' => [
            'name' => 'Player Management',
            'version' => '1.0.0',
            'critical' => true,
            'url' => 'https://github.com/legit-ninja/player-management-plugin',
        ],
    ];
    
    /**
     * Optional plugins (recommended but not required)
     * 
     * @var array
     */
    private $optional_plugins = [
        'wp-debug-log-viewer/wp-debug-log-viewer.php' => [
            'name' => 'WP Debug Log Viewer',
            'version' => '1.0.0',
            'url' => 'https://wordpress.org/plugins/wp-debug-log-viewer/',
            'reason' => 'Enhanced debugging and log viewing capabilities',
        ],
        'query-monitor/query-monitor.php' => [
            'name' => 'Query Monitor',
            'version' => '3.0.0',
            'url' => 'https://wordpress.org/plugins/query-monitor/',
            'reason' => 'Database query monitoring and performance analysis',
        ],
    ];
    
    /**
     * Required WordPress capabilities
     * 
     * @var array
     */
    private $required_capabilities = [
        'manage_options',
        'manage_woocommerce',
        'view_woocommerce_reports',
    ];
    
    /**
     * Required PHP extensions
     * 
     * @var array
     */
    private $required_php_extensions = [
        'json' => 'JSON support for data processing',
        'mysqli' => 'MySQL database connectivity',
        'curl' => 'HTTP requests for API integrations',
        'mbstring' => 'Multi-byte string handling',
    ];
    
    /**
     * Missing dependencies cache
     * 
     * @var array|null
     */
    private $missing_cache = null;
    
    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }
    
    /**
     * Check all dependencies
     * 
     * @param bool $force_refresh Force refresh of dependency check
     * @return bool All dependencies satisfied
     */
    public function check_all($force_refresh = false) {
        if ($force_refresh) {
            $this->missing_cache = null;
        }
        
        try {
            $this->logger->debug('Starting comprehensive dependency check');
            
            $checks = [
                'system' => $this->check_system_requirements(),
                'plugins' => $this->check_required_plugins(),
                'capabilities' => $this->check_user_capabilities(),
                'php_extensions' => $this->check_php_extensions(),
                'database' => $this->check_database_requirements(),
            ];
            
            $all_passed = array_reduce($checks, function($carry, $check) {
                return $carry && $check;
            }, true);
            
            $this->logger->info('Dependency check completed', [
                'all_passed' => $all_passed,
                'individual_results' => $checks
            ]);
            
            return $all_passed;
            
        } catch (\Exception $e) {
            $this->logger->error('Dependency check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
    
    /**
     * Check system requirements
     * 
     * @return bool System requirements met
     */
    public function check_system_requirements() {
        try {
            $requirements = [
                'wordpress_version' => $this->check_wordpress_version(),
                'php_version' => $this->check_php_version(),
                'memory_limit' => $this->check_memory_limit(),
                'max_execution_time' => $this->check_execution_time(),
            ];
            
            $passed = array_reduce($requirements, function($carry, $check) {
                return $carry && $check;
            }, true);
            
            $this->logger->debug('System requirements check', [
                'passed' => $passed,
                'individual_checks' => $requirements
            ]);
            
            return $passed;
            
        } catch (\Exception $e) {
            $this->logger->error('System requirements check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check WordPress version
     * 
     * @return bool WordPress version is adequate
     */
    private function check_wordpress_version() {
        global $wp_version;
        
        $min_version = '5.0';
        $adequate = version_compare($wp_version, $min_version, '>=');
        
        if (!$adequate) {
            $this->logger->warning('WordPress version inadequate', [
                'current' => $wp_version,
                'required' => $min_version
            ]);
        }
        
        return $adequate;
    }
    
    /**
     * Check PHP version
     * 
     * @return bool PHP version is adequate
     */
    private function check_php_version() {
        $min_version = '7.4';
        $adequate = version_compare(PHP_VERSION, $min_version, '>=');
        
        if (!$adequate) {
            $this->logger->warning('PHP version inadequate', [
                'current' => PHP_VERSION,
                'required' => $min_version
            ]);
        }
        
        return $adequate;
    }
    
    /**
     * Check memory limit
     * 
     * @return bool Memory limit is adequate
     */
    private function check_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->convert_to_bytes($memory_limit);
        $min_memory = 128 * 1024 * 1024; // 128MB
        
        $adequate = $memory_bytes >= $min_memory || $memory_bytes === -1; // -1 means no limit
        
        if (!$adequate) {
            $this->logger->warning('Memory limit may be inadequate', [
                'current' => $memory_limit,
                'recommended' => '128M'
            ]);
        }
        
        return $adequate;
    }
    
    /**
     * Check max execution time
     * 
     * @return bool Execution time is adequate
     */
    private function check_execution_time() {
        $max_execution = ini_get('max_execution_time');
        $min_execution = 60; // 60 seconds
        
        // 0 means no limit, which is fine
        $adequate = $max_execution == 0 || $max_execution >= $min_execution;
        
        if (!$adequate) {
            $this->logger->warning('Max execution time may be inadequate for large operations', [
                'current' => $max_execution,
                'recommended' => $min_execution
            ]);
        }
        
        return $adequate;
    }
    
    /**
     * Check required plugins
     * 
     * @return bool All required plugins are active
     */
    public function check_required_plugins() {
        $all_active = true;
        $missing_plugins = [];
        
        foreach ($this->required_plugins as $plugin_path => $plugin_info) {
            $is_active = $this->is_plugin_active($plugin_path);
            
            if (!$is_active) {
                $all_active = false;
                $missing_plugins[] = $plugin_info['name'];
                
                $this->logger->warning('Required plugin not active', [
                    'plugin' => $plugin_info['name'],
                    'path' => $plugin_path,
                    'critical' => $plugin_info['critical']
                ]);
            } else {
                // Check version if plugin is active
                $version_ok = $this->check_plugin_version($plugin_path, $plugin_info);
                if (!$version_ok) {
                    $all_active = false;
                }
            }
        }
        
        if (!empty($missing_plugins)) {
            $this->logger->error('Missing required plugins', [
                'missing' => $missing_plugins
            ]);
        }
        
        return $all_active;
    }
    
    /**
     * Check if plugin is active
     * 
     * @param string $plugin_path Plugin path
     * @return bool Plugin is active
     */
    private function is_plugin_active($plugin_path) {
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        return is_plugin_active($plugin_path);
    }
    
    /**
     * Check plugin version
     * 
     * @param string $plugin_path Plugin path
     * @param array $plugin_info Plugin information
     * @return bool Plugin version is adequate
     */
    private function check_plugin_version($plugin_path, array $plugin_info) {
        if (!isset($plugin_info['version'])) {
            return true; // No version requirement
        }
        
        try {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_path);
            $current_version = $plugin_data['Version'] ?? '0.0.0';
            
            $adequate = version_compare($current_version, $plugin_info['version'], '>=');
            
            if (!$adequate) {
                $this->logger->warning('Plugin version inadequate', [
                    'plugin' => $plugin_info['name'],
                    'current' => $current_version,
                    'required' => $plugin_info['version']
                ]);
            }
            
            return $adequate;
            
        } catch (\Exception $e) {
            $this->logger->warning('Could not check plugin version', [
                'plugin' => $plugin_info['name'],
                'error' => $e->getMessage()
            ]);
            return true; // Assume OK if we can't check
        }
    }
    
    /**
     * Check user capabilities
     * 
     * @return bool User has required capabilities
     */
    public function check_user_capabilities() {
        if (!is_admin()) {
            return true; // Only check in admin area
        }
        
        $current_user = wp_get_current_user();
        if (!$current_user->exists()) {
            return false;
        }
        
        $missing_caps = [];
        foreach ($this->required_capabilities as $capability) {
            if (!current_user_can($capability)) {
                $missing_caps[] = $capability;
            }
        }
        
        if (!empty($missing_caps)) {
            $this->logger->warning('User missing required capabilities', [
                'user_id' => $current_user->ID,
                'missing_capabilities' => $missing_caps
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Check PHP extensions
     * 
     * @return bool All required PHP extensions are loaded
     */
    public function check_php_extensions() {
        $missing_extensions = [];
        
        foreach ($this->required_php_extensions as $extension => $description) {
            if (!extension_loaded($extension)) {
                $missing_extensions[] = $extension;
                $this->logger->warning('Required PHP extension not loaded', [
                    'extension' => $extension,
                    'description' => $description
                ]);
            }
        }
        
        if (!empty($missing_extensions)) {
            $this->logger->error('Missing required PHP extensions', [
                'missing' => $missing_extensions
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Check database requirements
     * 
     * @return bool Database requirements are met
     */
    public function check_database_requirements() {
        global $wpdb;
        
        try {
            // Check database connectivity
            $db_version = $wpdb->get_var("SELECT VERSION()");
            if (empty($db_version)) {
                $this->logger->error('Cannot connect to database');
                return false;
            }
            
            // Check MySQL/MariaDB version
            $min_mysql_version = '5.6';
            $version_adequate = version_compare($db_version, $min_mysql_version, '>=');
            
            if (!$version_adequate) {
                $this->logger->warning('Database version may be inadequate', [
                    'current' => $db_version,
                    'recommended' => $min_mysql_version
                ]);
            }
            
            // Check database permissions
            $permissions_ok = $this->check_database_permissions();
            
            // Check character set support
            $charset_ok = $this->check_database_charset();
            
            $all_ok = $version_adequate && $permissions_ok && $charset_ok;
            
            $this->logger->debug('Database requirements check', [
                'version' => $db_version,
                'version_adequate' => $version_adequate,
                'permissions_ok' => $permissions_ok,
                'charset_ok' => $charset_ok,
                'all_ok' => $all_ok
            ]);
            
            return $all_ok;
            
        } catch (\Exception $e) {
            $this->logger->error('Database requirements check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check database permissions
     * 
     * @return bool Database permissions are adequate
     */
    private function check_database_permissions() {
        global $wpdb;
        
        try {
            // Test CREATE TABLE permission
            $test_table = $wpdb->prefix . 'intersoccer_test_permissions';
            
            $create_result = $wpdb->query("CREATE TEMPORARY TABLE {$test_table} (id INT)");
            if ($create_result === false) {
                $this->logger->warning('Database CREATE permission test failed');
                return false;
            }
            
            // Test INSERT permission
            $insert_result = $wpdb->query("INSERT INTO {$test_table} (id) VALUES (1)");
            if ($insert_result === false) {
                $this->logger->warning('Database INSERT permission test failed');
                return false;
            }
            
            // Test SELECT permission
            $select_result = $wpdb->get_var("SELECT COUNT(*) FROM {$test_table}");
            if ($select_result === null) {
                $this->logger->warning('Database SELECT permission test failed');
                return false;
            }
            
            // Test UPDATE permission
            $update_result = $wpdb->query("UPDATE {$test_table} SET id = 2 WHERE id = 1");
            if ($update_result === false) {
                $this->logger->warning('Database UPDATE permission test failed');
                return false;
            }
            
            // Test DELETE permission
            $delete_result = $wpdb->query("DELETE FROM {$test_table} WHERE id = 2");
            if ($delete_result === false) {
                $this->logger->warning('Database DELETE permission test failed');
                return false;
            }
            
            // Clean up is automatic for TEMPORARY tables
            return true;
            
        } catch (\Exception $e) {
            $this->logger->warning('Database permissions check error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check database character set support
     * 
     * @return bool Database character set is adequate
     */
    private function check_database_charset() {
        global $wpdb;
        
        try {
            $charset = $wpdb->get_var("SELECT @@character_set_database");
            $collation = $wpdb->get_var("SELECT @@collation_database");
            
            // Check for UTF-8 support
            $utf8_support = strpos($charset, 'utf8') !== false;
            
            if (!$utf8_support) {
                $this->logger->warning('Database does not use UTF-8 character set', [
                    'charset' => $charset,
                    'collation' => $collation
                ]);
                return false;
            }
            
            $this->logger->debug('Database character set check passed', [
                'charset' => $charset,
                'collation' => $collation
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->warning('Database character set check error', [
                'error' => $e->getMessage()
            ]);
            return true; // Assume OK if we can't check
        }
    }
    
    /**
     * Get missing dependencies
     * 
     * @return array Missing dependencies
     */
    public function get_missing_dependencies() {
        if ($this->missing_cache !== null) {
            return $this->missing_cache;
        }
        
        $missing = [];
        
        // Check required plugins
        foreach ($this->required_plugins as $plugin_path => $plugin_info) {
            if (!$this->is_plugin_active($plugin_path)) {
                $missing[] = $plugin_info['name'];
            }
        }
        
        // Check PHP extensions
        foreach ($this->required_php_extensions as $extension => $description) {
            if (!extension_loaded($extension)) {
                $missing[] = "PHP {$extension} extension";
            }
        }
        
        // Check system requirements
        global $wp_version;
        if (version_compare($wp_version, '5.0', '<')) {
            $missing[] = 'WordPress 5.0+';
        }
        
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $missing[] = 'PHP 7.4+';
        }
        
        $this->missing_cache = $missing;
        return $missing;
    }
    
    /**
     * Get optional plugin recommendations
     * 
     * @return array Optional plugins that could be installed
     */
    public function get_optional_recommendations() {
        $recommendations = [];
        
        foreach ($this->optional_plugins as $plugin_path => $plugin_info) {
            if (!$this->is_plugin_active($plugin_path)) {
                $recommendations[] = [
                    'name' => $plugin_info['name'],
                    'reason' => $plugin_info['reason'],
                    'url' => $plugin_info['url']
                ];
            }
        }
        
        return $recommendations;
    }
    
    /**
     * Get system information for debugging
     * 
     * @return array System information
     */
    public function get_system_info() {
        global $wp_version, $wpdb;
        
        $info = [
            'wordpress' => [
                'version' => $wp_version,
                'multisite' => is_multisite(),
                'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
                'memory_limit' => ini_get('memory_limit'),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'post_max_size' => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'extensions' => array_filter(array_keys($this->required_php_extensions), 'extension_loaded')
            ],
            'database' => [
                'version' => $wpdb->get_var("SELECT VERSION()"),
                'charset' => $wpdb->get_var("SELECT @@character_set_database"),
                'collation' => $wpdb->get_var("SELECT @@collation_database"),
            ],
            'server' => [
                'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'php_sapi' => php_sapi_name(),
                'os' => PHP_OS,
            ]
        ];
        
        return $info;
    }
    
    /**
     * Convert memory limit string to bytes
     * 
     * @param string $val Memory limit value (e.g., '128M', '1G')
     * @return int Memory limit in bytes
     */
    private function convert_to_bytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = intval($val);
        
        switch ($last) {
            case 'g':
                $val *= 1024;
                // fall through
            case 'm':
                $val *= 1024;
                // fall through
            case 'k':
                $val *= 1024;
        }
        
        return $val;
    }
    
    /**
     * Generate dependency report
     * 
     * @return array Comprehensive dependency report
     */
    public function generate_report() {
        $report = [
            'timestamp' => current_time('c'),
            'overall_status' => $this->check_all(),
            'system_requirements' => [
                'status' => $this->check_system_requirements(),
                'details' => $this->get_system_info()
            ],
            'required_plugins' => [
                'status' => $this->check_required_plugins(),
                'missing' => $this->get_missing_dependencies()
            ],
            'optional_plugins' => [
                'recommendations' => $this->get_optional_recommendations()
            ],
            'php_extensions' => [
                'status' => $this->check_php_extensions(),
                'required' => array_keys($this->required_php_extensions),
                'loaded' => get_loaded_extensions()
            ],
            'database' => [
                'status' => $this->check_database_requirements()
            ],
            'user_capabilities' => [
                'status' => $this->check_user_capabilities(),
                'required' => $this->required_capabilities
            ]
        ];
        
        $this->logger->info('Dependency report generated', [
            'overall_status' => $report['overall_status'],
            'timestamp' => $report['timestamp']
        ]);
        
        return $report;
    }
    
    /**
     * Clear dependency cache
     * 
     * @return void
     */
    public function clear_cache() {
        $this->missing_cache = null;
        $this->logger->debug('Dependency cache cleared');
    }
    
    /**
     * Add custom dependency check
     * 
     * @param string $plugin_path Plugin path
     * @param array $plugin_info Plugin information
     * @param bool $required Is the plugin required
     * @return void
     */
    public function add_dependency($plugin_path, array $plugin_info, $required = true) {
        if ($required) {
            $this->required_plugins[$plugin_path] = $plugin_info;
        } else {
            $this->optional_plugins[$plugin_path] = $plugin_info;
        }
        
        $this->clear_cache();
        
        $this->logger->debug('Custom dependency added', [
            'plugin_path' => $plugin_path,
            'required' => $required,
            'plugin_info' => $plugin_info
        ]);
    }
    
    /**
     * Remove dependency check
     * 
     * @param string $plugin_path Plugin path to remove
     * @return void
     */
    public function remove_dependency($plugin_path) {
        unset($this->required_plugins[$plugin_path]);
        unset($this->optional_plugins[$plugin_path]);
        
        $this->clear_cache();
        
        $this->logger->debug('Dependency removed', [
            'plugin_path' => $plugin_path
        ]);
    }
    
    /**
     * Check if a specific plugin is active and meets version requirements
     * 
     * @param string $plugin_path Plugin path (e.g., 'woocommerce/woocommerce.php')
     * @return bool True if plugin is active and meets requirements
     */
    public function check_plugin($plugin_path) {
        try {
            // Check if plugin is active
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            
            if (!is_plugin_active($plugin_path)) {
                $this->logger->debug('Plugin not active', ['plugin' => $plugin_path]);
                return false;
            }
            
            // Check if plugin is in our required or optional list
            $plugin_info = null;
            if (isset($this->required_plugins[$plugin_path])) {
                $plugin_info = $this->required_plugins[$plugin_path];
            } elseif (isset($this->optional_plugins[$plugin_path])) {
                $plugin_info = $this->optional_plugins[$plugin_path];
            }
            
            // If we have version requirements, check them
            if ($plugin_info && !empty($plugin_info['version'])) {
                return $this->check_plugin_version($plugin_path, $plugin_info);
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Error checking plugin', [
                'plugin' => $plugin_path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}