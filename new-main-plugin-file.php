<?php
/**
 * Plugin Name: InterSoccer Reports and Rosters
 * Plugin URI: https://github.com/legit-ninja/reports-rosters
 * Description: Advanced event rosters and reports management system for InterSoccer Switzerland. Integrates with WooCommerce to generate comprehensive camp and course rosters with export capabilities, analytics, and Swiss-specific features.
 * Version: 2.0.0
 * Author: Jeremy Lee
 * Author URI: https://legit.ninja
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: intersoccer-reports-rosters
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 * 
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 * 
 * @package InterSoccer\ReportsRosters
 * @version 2.0.0
 * @author Jeremy Lee
 * @copyright 2025 InterSoccer Switzerland
 * @license GPL-2.0-or-later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

// Define plugin constants
define('INTERSOCCER_REPORTS_ROSTERS_VERSION', '2.0.0');
define('INTERSOCCER_REPORTS_ROSTERS_PLUGIN_FILE', __FILE__);
define('INTERSOCCER_REPORTS_ROSTERS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('INTERSOCCER_REPORTS_ROSTERS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('INTERSOCCER_REPORTS_ROSTERS_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN', 'intersoccer-reports-rosters');

/**
 * Main plugin bootstrap function
 * 
 * Initializes the plugin after all dependencies are loaded
 */
function intersoccer_reports_rosters_init() {
    // Verify WordPress version
    if (version_compare($GLOBALS['wp_version'], '5.0', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            printf(
                __('InterSoccer Reports & Rosters requires WordPress 5.0 or higher. Current version: %s', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN),
                $GLOBALS['wp_version']
            );
            echo '</p></div>';
        });
        return;
    }

    // Verify PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            printf(
                __('InterSoccer Reports & Rosters requires PHP 7.4 or higher. Current version: %s', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN),
                PHP_VERSION
            );
            echo '</p></div>';
        });
        return;
    }

    // Load Composer autoloader if available
    $autoloader = INTERSOCCER_REPORTS_ROSTERS_PLUGIN_PATH . 'vendor/autoload.php';
    if (file_exists($autoloader)) {
        require_once $autoloader;
    }

    // Load manual autoloader for classes
    require_once INTERSOCCER_REPORTS_ROSTERS_PLUGIN_PATH . 'includes/class-autoloader.php';
    
    // Initialize autoloader
    InterSoccer\ReportsRosters\Autoloader::init(INTERSOCCER_REPORTS_ROSTERS_PLUGIN_PATH);

    try {
        // Initialize main plugin class
        $plugin = InterSoccer\ReportsRosters\Core\Plugin::get_instance(INTERSOCCER_REPORTS_ROSTERS_PLUGIN_FILE);
        
        // Verify plugin initialized successfully
        if (!$plugin->is_initialized()) {
            throw new Exception('Plugin failed to initialize properly');
        }
        
    } catch (Exception $e) {
        // Log critical error
        error_log('InterSoccer Reports & Rosters: Critical initialization error - ' . $e->getMessage());
        
        // Show admin notice
        add_action('admin_notices', function() use ($e) {
            echo '<div class="error"><p>';
            printf(
                __('InterSoccer Reports & Rosters: Plugin initialization failed - %s', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN),
                esc_html($e->getMessage())
            );
            echo '</p></div>';
        });
        
        return;
    }
}

/**
 * Plugin activation hook
 * 
 * Handles plugin activation tasks
 */
function intersoccer_reports_rosters_activate() {
    // Log activation attempt
    error_log('InterSoccer Reports & Rosters: Plugin activation initiated');
    
    try {
        // Check if autoloader exists
        $autoloader = INTERSOCCER_REPORTS_ROSTERS_PLUGIN_PATH . 'includes/class-autoloader.php';
        if (!file_exists($autoloader)) {
            throw new Exception('Autoloader not found. Plugin files may be corrupted.');
        }
        
        require_once $autoloader;
        InterSoccer\ReportsRosters\Autoloader::init(INTERSOCCER_REPORTS_ROSTERS_PLUGIN_PATH);
        
        // Initialize and activate plugin
        $plugin = InterSoccer\ReportsRosters\Core\Plugin::get_instance(INTERSOCCER_REPORTS_ROSTERS_PLUGIN_FILE);
        $plugin->activate();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        error_log('InterSoccer Reports & Rosters: Plugin activated successfully');
        
    } catch (Exception $e) {
        error_log('InterSoccer Reports & Rosters: Activation failed - ' . $e->getMessage());
        
        // Deactivate plugin on activation failure
        deactivate_plugins(INTERSOCCER_REPORTS_ROSTERS_PLUGIN_BASENAME);
        
        wp_die(
            sprintf(
                __('InterSoccer Reports & Rosters activation failed: %s', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN),
                $e->getMessage()
            ),
            __('Plugin Activation Error', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN),
            array('back_link' => true)
        );
    }
}

/**
 * Plugin deactivation hook
 * 
 * Handles plugin deactivation cleanup
 */
function intersoccer_reports_rosters_deactivate() {
    error_log('InterSoccer Reports & Rosters: Plugin deactivation initiated');
    
    try {
        // Only run deactivation if plugin was properly initialized
        if (class_exists('InterSoccer\ReportsRosters\Core\Plugin')) {
            $plugin = InterSoccer\ReportsRosters\Core\Plugin::get_instance();
            $plugin->deactivate();
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        error_log('InterSoccer Reports & Rosters: Plugin deactivated successfully');
        
    } catch (Exception $e) {
        error_log('InterSoccer Reports & Rosters: Deactivation error - ' . $e->getMessage());
    }
}

/**
 * Plugin uninstall hook
 * 
 * Handles complete plugin removal
 */
function intersoccer_reports_rosters_uninstall() {
    error_log('InterSoccer Reports & Rosters: Plugin uninstall initiated');
    
    try {
        // Load autoloader for uninstall
        require_once INTERSOCCER_REPORTS_ROSTERS_PLUGIN_PATH . 'includes/class-autoloader.php';
        InterSoccer\ReportsRosters\Autoloader::init(INTERSOCCER_REPORTS_ROSTERS_PLUGIN_PATH);
        
        // Run uninstaller
        $uninstaller = new InterSoccer\ReportsRosters\Core\Uninstaller();
        $uninstaller->uninstall();
        
        error_log('InterSoccer Reports & Rosters: Plugin uninstalled successfully');
        
    } catch (Exception $e) {
        error_log('InterSoccer Reports & Rosters: Uninstall error - ' . $e->getMessage());
    }
}

/**
 * Check WooCommerce integration status
 * 
 * @return bool True if WooCommerce is properly integrated
 */
function intersoccer_reports_rosters_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        return false;
    }
    
    // Check minimum WooCommerce version
    if (version_compare(WC()->version, '4.0', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            printf(
                __('InterSoccer Reports & Rosters requires WooCommerce 4.0 or higher. Current version: %s', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN),
                WC()->version
            );
            echo '</p></div>';
        });
        return false;
    }
    
    return true;
}

/**
 * Load plugin textdomain for translations
 */
function intersoccer_reports_rosters_load_textdomain() {
    load_plugin_textdomain(
        INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN,
        false,
        dirname(INTERSOCCER_REPORTS_ROSTERS_PLUGIN_BASENAME) . '/languages'
    );
}

/**
 * Add plugin action links
 * 
 * @param array $links Existing action links
 * @return array Modified action links
 */
function intersoccer_reports_rosters_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=intersoccer-reports-rosters') . '">' . 
        __('Dashboard', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN) . '</a>',
        '<a href="' . admin_url('admin.php?page=intersoccer-advanced') . '">' . 
        __('Settings', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN) . '</a>',
    );
    
    return array_merge($plugin_links, $links);
}

/**
 * Add plugin meta links
 * 
 * @param array  $links Existing meta links
 * @param string $file  Plugin file
 * @return array Modified meta links
 */
function intersoccer_reports_rosters_meta_links($links, $file) {
    if ($file === INTERSOCCER_REPORTS_ROSTERS_PLUGIN_BASENAME) {
        $links[] = '<a href="https://github.com/legit-ninja/reports-rosters" target="_blank">' . 
                   __('GitHub', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN) . '</a>';
        $links[] = '<a href="https://intersoccer.ch" target="_blank">' . 
                   __('InterSoccer', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN) . '</a>';
    }
    
    return $links;
}

/**
 * Display admin notices for plugin status
 */
function intersoccer_reports_rosters_admin_notices() {
    // Check if this is a plugin-related page
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'intersoccer') === false) {
        return;
    }
    
    // Check for plugin updates
    $current_version = get_option('intersoccer_plugin_version', '1.0.0');
    if (version_compare($current_version, INTERSOCCER_REPORTS_ROSTERS_VERSION, '<')) {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>' . __('InterSoccer Reports & Rosters:', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN) . '</strong> ';
        printf(
            __('Plugin updated to version %s. Visit the %s to run any necessary database updates.', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN),
            INTERSOCCER_REPORTS_ROSTERS_VERSION,
            '<a href="' . admin_url('admin.php?page=intersoccer-advanced') . '">' . __('Advanced Tools page', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN) . '</a>'
        );
        echo '</p></div>';
    }
}

// Register hooks
register_activation_hook(__FILE__, 'intersoccer_reports_rosters_activate');
register_deactivation_hook(__FILE__, 'intersoccer_reports_rosters_deactivate');
register_uninstall_hook(__FILE__, 'intersoccer_reports_rosters_uninstall');

// WordPress hooks
add_action('plugins_loaded', 'intersoccer_reports_rosters_init', 10);
add_action('init', 'intersoccer_reports_rosters_load_textdomain');
add_action('admin_notices', 'intersoccer_reports_rosters_admin_notices');

// Plugin page hooks
add_filter('plugin_action_links_' . INTERSOCCER_REPORTS_ROSTERS_PLUGIN_BASENAME, 'intersoccer_reports_rosters_action_links');
add_filter('plugin_row_meta', 'intersoccer_reports_rosters_meta_links', 10, 2);

// WooCommerce integration check
add_action('plugins_loaded', function() {
    if (!intersoccer_reports_rosters_check_woocommerce()) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            _e('InterSoccer Reports & Rosters requires WooCommerce to be installed and activated.', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN);
            echo ' <a href="' . admin_url('plugin-install.php?s=woocommerce&tab=search&type=term') . '">';
            _e('Install WooCommerce', INTERSOCCER_REPORTS_ROSTERS_TEXT_DOMAIN);
            echo '</a></p></div>';
        });
    }
}, 20);

// Add High-Performance Order Storage (HPOS) compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            INTERSOCCER_REPORTS_ROSTERS_PLUGIN_FILE,
            true
        );
    }
});

// Development helper - remove in production
if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
    add_action('wp_loaded', function() {
        error_log('InterSoccer Reports & Rosters: Plugin loaded successfully - Version ' . INTERSOCCER_REPORTS_ROSTERS_VERSION);
    });
}