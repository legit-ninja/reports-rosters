<?php
/**
 * Main Plugin Class
 * 
 * Central orchestrator for the InterSoccer Reports & Rosters plugin.
 * Handles initialization, dependency management, and core functionality.
 * 
 * @package InterSoccer\ReportsRosters\Core
 * @version 2.0.0
 * @author Jeremy Lee
 */

namespace InterSoccer\ReportsRosters\Core;

use InterSoccer\ReportsRosters\Admin\MenuManager;
use InterSoccer\ReportsRosters\Admin\AssetManager;
use InterSoccer\ReportsRosters\Ajax\AjaxHandler;
use InterSoccer\ReportsRosters\WooCommerce\HooksManager;
use InterSoccer\ReportsRosters\Services\CacheManager;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Plugin Class
 * 
 * Singleton pattern implementation to ensure single instance
 */
final class Plugin {
    
    /**
     * Plugin version
     */
    const VERSION = '2.1.27';
    
    /**
     * Plugin text domain
     */
    const TEXT_DOMAIN = 'intersoccer-reports-rosters';
    
    /**
     * Minimum WordPress version
     */
    const MIN_WP_VERSION = '5.0';
    
    /**
     * Minimum PHP version
     */
    const MIN_PHP_VERSION = '7.4';
    
    /**
     * Singleton instance
     * 
     * @var Plugin|null
     */
    private static $instance = null;
    
    /**
     * Plugin file path
     * 
     * @var string
     */
    private $plugin_file;
    
    /**
     * Plugin directory path
     * 
     * @var string
     */
    private $plugin_path;
    
    /**
     * Plugin URL
     * 
     * @var string
     */
    private $plugin_url;
    
    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Database instance
     * 
     * @var Database
     */
    private $database;
    
    /**
     * Dependencies checker
     * 
     * @var Dependencies
     */
    private $dependencies;
    
    /**
     * Cache manager
     * 
     * @var CacheManager
     */
    private $cache;
    
    /**
     * Plugin initialization status
     * 
     * @var bool
     */
    private $initialized = false;
    
    /**
     * Private constructor (Singleton)
     * 
     * @param string $plugin_file Main plugin file path
     */
    private function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->plugin_path = plugin_dir_path($plugin_file);
        $this->plugin_url = plugin_dir_url($plugin_file);
        
        // Initialize core components
        $this->init_core_components();
        
        // Hook into WordPress
        $this->init_hooks();
    }
    
    /**
     * Get singleton instance
     * 
     * @param string $plugin_file Main plugin file path
     * @return Plugin
     */
    public static function get_instance($plugin_file = null) {
        if (self::$instance === null) {
            if ($plugin_file === null) {
                throw new \InvalidArgumentException('Plugin file must be provided on first call');
            }
            self::$instance = new self($plugin_file);
        }
        return self::$instance;
    }
    
    /**
     * Initialize core components
     * 
     * @return void
     */
    private function init_core_components() {
        try {
            // Initialize logger first (other components may need it)
            $this->logger = new Logger();
            $this->logger->info('InterSoccer Plugin: Initializing core components');
            
            // Initialize database
            $this->database = new Database($this->logger);
            
            // Initialize dependencies checker
            $this->dependencies = new Dependencies($this->logger);
            
            // Initialize cache manager
            $this->cache = new CacheManager($this->logger);
            
            $this->logger->info('InterSoccer Plugin: Core components initialized successfully');
            
        } catch (\Exception $e) {
            // Fallback logging if logger fails
            error_log('InterSoccer Plugin Fatal Error: ' . $e->getMessage());
            
            // Show admin notice
            add_action('admin_notices', function() use ($e) {
                $message = sprintf(
                    __('InterSoccer Reports & Rosters plugin failed to initialize: %s', self::TEXT_DOMAIN),
                    $e->getMessage()
                );
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
            });
        }
    }
    
    /**
     * Initialize WordPress hooks
     * 
     * @return void
     */
    private function init_hooks() {
        // Plugin lifecycle hooks
        register_activation_hook($this->plugin_file, [$this, 'activate']);
        register_deactivation_hook($this->plugin_file, [$this, 'deactivate']);
        
        // WordPress initialization hooks
        add_action('init', [$this, 'init'], 0);
        add_action('plugins_loaded', [$this, 'check_dependencies'], 10);
        // admin_menu fires BEFORE admin_init - must register menu from init
        add_action('admin_menu', [$this, 'register_admin_menu'], 5);
        add_action('admin_init', [$this, 'init_admin'], 10);
        add_action('plugins_loaded', [$this, 'init_woocommerce'], 20);
        
        // Add debugging for hook execution
        add_action('wp_loaded', function() {
            $this->logger->debug('InterSoccer Plugin: WordPress loaded, plugin hooks initialized');
        });
    }
    
    /**
     * Plugin activation handler
     * 
     * @return void
     */
    public function activate() {
        try {
            $this->logger->info('InterSoccer Plugin: Activation started');
            
            // Check system requirements
            $this->check_system_requirements();
            
            // Check dependencies
            if (!$this->dependencies->check_all()) {
                $missing = $this->dependencies->get_missing_dependencies();
                throw new \Exception('Missing required dependencies: ' . implode(', ', $missing));
            }
            
            // Run activator
            $activator = new Activator($this->database, $this->logger);
            $activator->activate();
            
            $this->logger->info('InterSoccer Plugin: Activation completed successfully');
            
        } catch (\Exception $e) {
            $this->logger->error('InterSoccer Plugin: Activation failed', ['error' => $e->getMessage()]);
            
            // Deactivate plugin if activation fails
            deactivate_plugins(plugin_basename($this->plugin_file));
            
            // Show error message
            wp_die(
                sprintf(
                    __('InterSoccer Reports & Rosters activation failed: %s', self::TEXT_DOMAIN),
                    $e->getMessage()
                ),
                __('Plugin Activation Error', self::TEXT_DOMAIN),
                ['back_link' => true]
            );
        }
    }
    
    /**
     * Plugin deactivation handler
     * 
     * @return void
     */
    public function deactivate() {
        try {
            $this->logger->info('InterSoccer Plugin: Deactivation started');
            
            $deactivator = new Deactivator($this->database, $this->logger);
            $deactivator->deactivate();
            
            // Clear any cached data
            $this->cache->clear_all();
            
            $this->logger->info('InterSoccer Plugin: Deactivation completed');
            
        } catch (\Exception $e) {
            $this->logger->error('InterSoccer Plugin: Deactivation error', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Initialize plugin
     * 
     * @return void
     */
    public function init() {
        if ($this->initialized) {
            return;
        }
        
        try {
            $this->logger->info('InterSoccer Plugin: Main initialization started');
            
            // Load text domain for translations
            $this->load_textdomain();
            
            $this->initialized = true;
            $this->logger->info('InterSoccer Plugin: Main initialization completed');
            
        } catch (\Exception $e) {
            $this->logger->error('InterSoccer Plugin: Initialization failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Check plugin dependencies
     * 
     * @return void
     */
    public function check_dependencies() {
        try {
            if (!$this->dependencies->check_all()) {
                $this->logger->warning('InterSoccer Plugin: Missing dependencies detected');
                
                add_action('admin_notices', [$this, 'show_dependency_notices']);
                return;
            }
            
            $this->logger->debug('InterSoccer Plugin: All dependencies satisfied');
            
        } catch (\Exception $e) {
            $this->logger->error('InterSoccer Plugin: Dependency check failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Register admin menu (must run on admin_menu hook, which fires before admin_init)
     *
     * @return void
     */
    public function register_admin_menu(): void {
        if (!is_admin()) {
            return;
        }
        // Load rosters.php early (before admin_head) when on a roster page so card CSS is registered
        $roster_pages = ['intersoccer-camps', 'intersoccer-courses', 'intersoccer-girls-only', 'intersoccer-tournaments', 'intersoccer-other-events', 'intersoccer-all-rosters'];
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if (in_array($page, $roster_pages, true)) {
            $rosters_file = $this->plugin_path . 'includes/rosters.php';
            if (file_exists($rosters_file)) {
                require_once $rosters_file;
            }
        }
        $services = [
            'logger' => $this->logger,
            'database' => $this->database,
            'cache' => $this->cache,
        ];
        $menu_manager = new MenuManager($this->plugin_file, $this->logger, $services);
        $menu_manager->register_menus();
    }

    /**
     * Initialize admin area
     *
     * @return void
     */
    public function init_admin() {
        if (!is_admin()) {
            return;
        }
        
        try {
            $this->logger->debug('InterSoccer Plugin: Admin initialization started');
            
            $roster_export_file = $this->plugin_path . 'includes/roster-export.php';
            if (file_exists($roster_export_file)) {
                require_once $roster_export_file;
            }
            
            $asset_manager = new AssetManager($this->plugin_url, self::VERSION, $this->logger);
            $asset_manager->init();

            $ajax_handler = new AjaxHandler($this->logger);
            $ajax_handler->init();
            
            $this->logger->debug('InterSoccer Plugin: Admin initialization completed');
            
        } catch (\Exception $e) {
            $this->logger->error('InterSoccer Plugin: Admin initialization failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Initialize WooCommerce hooks.
     *
     * @return void
     */
    public function init_woocommerce() {
        try {
            if (!class_exists('WooCommerce')) {
                return;
            }

            $hooks_manager = new HooksManager($this->logger);
            $hooks_manager->init();
        } catch (\Throwable $e) {
            $this->logger->error('InterSoccer Plugin: WooCommerce initialization failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Check system requirements
     * 
     * @throws \Exception If requirements not met
     * @return void
     */
    private function check_system_requirements() {
        global $wp_version;
        
        // Check WordPress version
        if (version_compare($wp_version, self::MIN_WP_VERSION, '<')) {
            throw new \Exception(sprintf(
                __('WordPress %s or higher is required (current: %s)', self::TEXT_DOMAIN),
                self::MIN_WP_VERSION,
                $wp_version
            ));
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            throw new \Exception(sprintf(
                __('PHP %s or higher is required (current: %s)', self::TEXT_DOMAIN),
                self::MIN_PHP_VERSION,
                PHP_VERSION
            ));
        }
    }
    
    /**
     * Load plugin text domain
     * 
     * @return void
     */
    private function load_textdomain() {
        $locale = determine_locale();
        $mo_file = $this->plugin_path . 'languages/' . self::TEXT_DOMAIN . '-' . $locale . '.mo';
        
        load_textdomain(self::TEXT_DOMAIN, $mo_file);
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename($this->plugin_file)) . '/languages');
    }
    
    /**
     * Show dependency notices
     * 
     * @return void
     */
    public function show_dependency_notices() {
        $missing = $this->dependencies->get_missing_dependencies();
        
        if (empty($missing)) {
            return;
        }
        
        $message = sprintf(
            __('InterSoccer Reports & Rosters requires the following plugins to be active: %s', self::TEXT_DOMAIN),
            '<strong>' . implode(', ', $missing) . '</strong>'
        );
        
        printf('<div class="notice notice-error"><p>%s</p></div>', $message);
    }
    
    /**
     * Get plugin file path
     * 
     * @return string
     */
    public function get_plugin_file() {
        return $this->plugin_file;
    }
    
    /**
     * Get plugin directory path
     * 
     * @return string
     */
    public function get_plugin_path() {
        return $this->plugin_path;
    }
    
    /**
     * Get plugin URL
     * 
     * @return string
     */
    public function get_plugin_url() {
        return $this->plugin_url;
    }
    
    /**
     * Get logger instance
     * 
     * @return Logger
     */
    public function get_logger() {
        return $this->logger;
    }
    
    /**
     * Get database instance
     * 
     * @return Database
     */
    public function get_database() {
        return $this->database;
    }
    
    /**
     * Get cache manager instance
     * 
     * @return CacheManager
     */
    public function get_cache() {
        return $this->cache;
    }
    
    /**
     * Get plugin version
     * 
     * @return string
     */
    public function get_version() {
        return self::VERSION;
    }
    
    /**
     * Check if plugin is properly initialized
     * 
     * @return bool
     */
    public function is_initialized() {
        return $this->initialized;
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {}
}