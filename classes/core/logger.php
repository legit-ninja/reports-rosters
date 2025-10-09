<?php
/**
 * Logger Class
 * 
 * Centralized logging system for the InterSoccer Reports & Rosters plugin.
 * Provides structured logging with different levels and contexts.
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
 * Logger Class
 * 
 * PSR-3 compatible logging implementation for WordPress
 */
class Logger {
    
    /**
     * Log levels
     */
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';
    
    /**
     * Log level priorities (higher number = more severe)
     */
    private static $levels = [
        self::DEBUG => 100,
        self::INFO => 200,
        self::NOTICE => 250,
        self::WARNING => 300,
        self::ERROR => 400,
        self::CRITICAL => 500,
        self::ALERT => 550,
        self::EMERGENCY => 600,
    ];
    
    /**
     * Plugin prefix for log entries
     */
    const LOG_PREFIX = 'InterSoccer';
    
    /**
     * Current log level threshold
     * 
     * @var string
     */
    private $log_level;
    
    /**
     * Whether to log to WordPress debug.log
     * 
     * @var bool
     */
    private $log_to_file;
    
    /**
     * Whether to log to database
     * 
     * @var bool
     */
    private $log_to_db;
    
    /**
     * Custom log file path
     * 
     * @var string|null
     */
    private $custom_log_file;
    
    /**
     * Context data to include in all log entries
     * 
     * @var array
     */
    private $global_context;
    
    /**
     * Constructor
     * 
     * @param array $config Logger configuration
     */
    public function __construct($config = []) {
        $this->log_level = $config['level'] ?? $this->get_default_log_level();
        $this->log_to_file = $config['log_to_file'] ?? true;
        $this->log_to_db = $config['log_to_db'] ?? false;
        $this->custom_log_file = $config['custom_log_file'] ?? null;
        $this->global_context = $config['global_context'] ?? [];
        
        // Add default global context
        $this->global_context = array_merge([
            'plugin_version' => '2.0.0',
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
        ], $this->global_context);
    }
    
    /**
     * Log emergency message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function emergency($message, array $context = []) {
        $this->log(self::EMERGENCY, $message, $context);
    }
    
    /**
     * Log alert message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function alert($message, array $context = []) {
        $this->log(self::ALERT, $message, $context);
    }
    
    /**
     * Log critical message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function critical($message, array $context = []) {
        $this->log(self::CRITICAL, $message, $context);
    }
    
    /**
     * Log error message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function error($message, array $context = []) {
        $this->log(self::ERROR, $message, $context);
    }
    
    /**
     * Log warning message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function warning($message, array $context = []) {
        $this->log(self::WARNING, $message, $context);
    }
    
    /**
     * Log notice message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function notice($message, array $context = []) {
        $this->log(self::NOTICE, $message, $context);
    }
    
    /**
     * Log info message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function info($message, array $context = []) {
        $this->log(self::INFO, $message, $context);
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function debug($message, array $context = []) {
        $this->log(self::DEBUG, $message, $context);
    }
    
    /**
     * Main logging method
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context data
     * @return void
     */
    public function log($level, $message, array $context = []) {
        // Validate log level
        if (!isset(self::$levels[$level])) {
            $level = self::ERROR;
            $message = "Invalid log level provided. Original message: {$message}";
        }
        
        // Check if we should log this level
        if (!$this->should_log($level)) {
            return;
        }
        
        try {
            // Merge context data
            $full_context = array_merge($this->global_context, $context);
            
            // Format the log entry
            $formatted_message = $this->format_message($level, $message, $full_context);
            
            // Write to configured outputs
            if ($this->log_to_file) {
                $this->write_to_file($formatted_message, $level, $full_context);
            }
            
            if ($this->log_to_db) {
                $this->write_to_database($level, $message, $full_context);
            }
            
            // For critical errors, also trigger WordPress error handling
            if (self::$levels[$level] >= self::$levels[self::CRITICAL]) {
                $this->trigger_wp_error($level, $message, $full_context);
            }
            
        } catch (\Exception $e) {
            // Fallback logging - don't let logging errors break the plugin
            error_log(self::LOG_PREFIX . ' Logger Error: ' . $e->getMessage());
            error_log(self::LOG_PREFIX . ' Original Message: ' . $message);
        }
    }
    
    /**
     * Check if we should log this level
     * 
     * @param string $level Log level to check
     * @return bool
     */
    private function should_log($level) {
        $current_level_priority = self::$levels[$this->log_level] ?? self::$levels[self::INFO];
        $message_level_priority = self::$levels[$level] ?? self::$levels[self::ERROR];
        
        return $message_level_priority >= $current_level_priority;
    }
    
    /**
     * Format log message
     * 
     * @param string $level Log level
     * @param string $message Original message
     * @param array $context Context data
     * @return string Formatted message
     */
    private function format_message($level, $message, array $context = []) {
        $timestamp = current_time('c');
        $level_upper = strtoupper($level);
        
        // Interpolate context variables in message
        $interpolated_message = $this->interpolate($message, $context);
        
        // Build formatted message
        $formatted = "[{$timestamp}] {$level_upper}: " . self::LOG_PREFIX . ": {$interpolated_message}";
        
        // Add context data if present (excluding already interpolated values)
        $remaining_context = $this->get_remaining_context($message, $context);
        if (!empty($remaining_context)) {
            $formatted .= " | Context: " . json_encode($remaining_context, JSON_UNESCAPED_SLASHES);
        }
        
        return $formatted;
    }
    
    /**
     * Interpolate context variables in message
     * 
     * @param string $message Message with placeholders
     * @param array $context Context data
     * @return string Interpolated message
     */
    private function interpolate($message, array $context = []) {
        // Build replacement array
        $replace = [];
        foreach ($context as $key => $val) {
            // Check that the value can be cast to string
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        
        return strtr($message, $replace);
    }
    
    /**
     * Get context data not used in message interpolation
     * 
     * @param string $message Original message
     * @param array $context Full context
     * @return array Remaining context
     */
    private function get_remaining_context($message, array $context) {
        $used_keys = [];
        
        // Find interpolated keys
        if (preg_match_all('/\{(\w+)\}/', $message, $matches)) {
            $used_keys = $matches[1];
        }
        
        // Return context excluding used keys and global context keys
        $remaining = array_diff_key($context, array_flip($used_keys), $this->global_context);
        
        // Filter out non-serializable values
        return array_filter($remaining, function($value) {
            return is_scalar($value) || is_array($value) || (is_object($value) && method_exists($value, '__toString'));
        });
    }
    
    /**
     * Write log entry to file
     * 
     * @param string $formatted_message Formatted log message
     * @param string $level Log level
     * @param array $context Context data
     * @return void
     */
    private function write_to_file($formatted_message, $level, array $context = []) {
        // Use custom log file if specified
        if ($this->custom_log_file && is_writable(dirname($this->custom_log_file))) {
            error_log($formatted_message . PHP_EOL, 3, $this->custom_log_file);
            return;
        }
        
        // Use WordPress debug.log if available
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($formatted_message);
            return;
        }
        
        // Fallback to PHP error log
        error_log($formatted_message);
    }
    
    /**
     * Write log entry to database
     * 
     * @param string $level Log level
     * @param string $message Original message
     * @param array $context Context data
     * @return void
     */
    private function write_to_database($level, $message, array $context = []) {
        global $wpdb;
        
        try {
            $table_name = $wpdb->prefix . 'intersoccer_logs';
            
            // Create logs table if it doesn't exist
            $this->maybe_create_logs_table();
            
            $wpdb->insert(
                $table_name,
                [
                    'level' => $level,
                    'message' => $message,
                    'context' => json_encode($context),
                    'created_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%s']
            );
            
            // Clean up old logs (keep last 1000 entries)
            if (rand(1, 100) === 1) { // 1% chance to run cleanup
                $this->cleanup_old_logs();
            }
            
        } catch (\Exception $e) {
            // Don't let database logging errors break the application
            error_log(self::LOG_PREFIX . ' DB Logging Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Trigger WordPress error for critical issues
     * 
     * @param string $level Log level
     * @param string $message Error message
     * @param array $context Context data
     * @return void
     */
    private function trigger_wp_error($level, $message, array $context = []) {
        if (function_exists('wp_trigger_error')) {
            wp_trigger_error('', self::LOG_PREFIX . " {$level}: {$message}", E_USER_WARNING);
        }
        
        // Also add admin notice for critical errors in admin area
        if (is_admin() && self::$levels[$level] >= self::$levels[self::ERROR]) {
            add_action('admin_notices', function() use ($message) {
                printf(
                    '<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
                    esc_html(self::LOG_PREFIX),
                    esc_html($message)
                );
            });
        }
    }
    
    /**
     * Get default log level based on WordPress debug settings
     * 
     * @return string Default log level
     */
    private function get_default_log_level() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return self::DEBUG;
        }
        
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            return self::INFO;
        }
        
        return self::WARNING;
    }
    
    /**
     * Maybe create logs table
     * 
     * @return void
     */
    private function maybe_create_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'intersoccer_logs';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE {$table_name} (
                id int(11) NOT NULL AUTO_INCREMENT,
                level varchar(20) NOT NULL,
                message text NOT NULL,
                context longtext,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY level_idx (level),
                KEY created_at_idx (created_at)
            ) {$charset_collate};";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    /**
     * Clean up old log entries
     * 
     * @return void
     */
    private function cleanup_old_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'intersoccer_logs';
        
        // Keep only the latest 1000 entries
        $wpdb->query("
            DELETE FROM {$table_name} 
            WHERE id NOT IN (
                SELECT id FROM (
                    SELECT id FROM {$table_name} 
                    ORDER BY id DESC 
                    LIMIT 1000
                ) temp_table
            )
        ");
    }
    
    /**
     * Get recent log entries
     * 
     * @param int $limit Number of entries to retrieve
     * @param string|null $level Filter by log level
     * @return array Log entries
     */
    public function get_recent_logs($limit = 100, $level = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'intersoccer_logs';
        
        $sql = "SELECT * FROM {$table_name}";
        $params = [];
        
        if ($level) {
            $sql .= " WHERE level = %s";
            $params[] = $level;
        }
        
        $sql .= " ORDER BY id DESC LIMIT %d";
        $params[] = $limit;
        
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }
        
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }
    
    /**
     * Clear all log entries
     * 
     * @return void
     */
    public function clear_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'intersoccer_logs';
        $wpdb->query("TRUNCATE TABLE {$table_name}");
        
        $this->info('Log entries cleared');
    }
    
    /**
     * Set log level
     * 
     * @param string $level New log level
     * @return void
     */
    public function set_log_level($level) {
        if (isset(self::$levels[$level])) {
            $this->log_level = $level;
            $this->info("Log level changed to {$level}");
        }
    }
    
    /**
     * Get current log level
     * 
     * @return string Current log level
     */
    public function get_log_level() {
        return $this->log_level;
    }
    
    /**
     * Add global context data
     * 
     * @param array $context Context data to add
     * @return void
     */
    public function add_global_context(array $context) {
        $this->global_context = array_merge($this->global_context, $context);
    }
    
    /**
     * Remove global context key
     * 
     * @param string $key Context key to remove
     * @return void
     */
    public function remove_global_context($key) {
        unset($this->global_context[$key]);
    }
    
    /**
     * Get all available log levels
     * 
     * @return array Log levels with priorities
     */
    public static function get_available_levels() {
        return self::$levels;
    }
}
?>