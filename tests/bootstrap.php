<?php
/**
 * PHPUnit Bootstrap File for InterSoccer Reports & Rosters
 * 
 * Sets up WordPress testing environment with proper mocking and dependencies
 */

// Define WordPress constants for tests FIRST (before anything else)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
    define('WPINC', 'wp-includes');
    define('WP_DEBUG', true);
    define('INTERSOCCER_TESTING', true);
    
    // WordPress time constants
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
    define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
    define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);
    define('MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS);
    define('YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS);
}

// Composer autoloader (must come AFTER ABSPATH is defined)
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Manually load all class files (since they have ABSPATH checks that prevent PSR-4 autoloading)
// Load in dependency order: interfaces/abstracts first, then implementations

// 1. Load Exceptions first (used by many classes)
foreach (glob(dirname(__DIR__) . '/classes/Exceptions/*.php') as $file) {
    require_once $file;
}

// 2. Load interfaces and abstracts
$interface_files = [
    'classes/export/export-interface.php',
    'classes/reports/report-interface.php',
    'classes/data/repositories/repository-interface.php',
    'classes/data/models/abstract-model.php',
    'classes/data/collections/abstract-collection.php',
    'classes/export/export-exporter.php', // Abstract exporter
    'classes/reports/abstract-report.php',
    // Skip UI abstracts for now - they have inheritance issues
];

foreach ($interface_files as $file) {
    $full_path = dirname(__DIR__) . '/' . $file;
    if (file_exists($full_path)) {
        require_once $full_path;
    }
}

// 3. Load core and utils (used by everything else)
foreach (glob(dirname(__DIR__) . '/classes/core/*.php') as $file) {
    require_once $file;
}
foreach (glob(dirname(__DIR__) . '/classes/utils/*.php') as $file) {
    require_once $file;
}

// 4. Load data layer
foreach (glob(dirname(__DIR__) . '/classes/data/models/*.php') as $file) {
    if (basename($file) !== 'abstract-model.php') {
        require_once $file;
    }
}
foreach (glob(dirname(__DIR__) . '/classes/data/collections/*.php') as $file) {
    if (basename($file) !== 'abstract-collection.php') {
        require_once $file;
    }
}
foreach (glob(dirname(__DIR__) . '/classes/data/repositories/*.php') as $file) {
    if (basename($file) !== 'repository-interface.php') {
        require_once $file;
    }
}

// 5. Load services (may depend on data layer)
foreach (glob(dirname(__DIR__) . '/classes/services/*.php') as $file) {
    if (basename($file) !== 'validation-tests.php') { // Skip manual test script
        require_once $file;
    }
}

// 6. Load remaining components
foreach (glob(dirname(__DIR__) . '/classes/export/*.php') as $file) {
    $basename = basename($file);
    if ($basename !== 'export-interface.php' && $basename !== 'export-exporter.php') {
        require_once $file;
    }
}
foreach (glob(dirname(__DIR__) . '/classes/reports/*.php') as $file) {
    $basename = basename($file);
    if ($basename !== 'report-interface.php' && $basename !== 'abstract-report.php') {
        require_once $file;
    }
}
foreach (glob(dirname(__DIR__) . '/classes/woocommerce/*.php') as $file) {
    require_once $file;
}
foreach (glob(dirname(__DIR__) . '/classes/admin/*.php') as $file) {
    require_once $file;
}

// Skip UI components and pages for now - they have inheritance and visibility issues
// These can be tested separately once core functionality is stable

// Define WordPress functions BEFORE Brain Monkey tries to redefine them
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return ($type === 'mysql') ? date('Y-m-d H:i:s') : time();
    }
}
if (!function_exists('is_admin')) {
    function is_admin() { return false; }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { return true; }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') { return '6.0'; }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return dirname($file) . '/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'https://example.com/wp-content/plugins/'; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return 'plugin/plugin.php'; }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() { return ['basedir' => '/tmp']; }
}
if (!function_exists('determine_locale')) {
    function determine_locale() { return 'en_US'; }
}
if (!function_exists('load_textdomain')) {
    function load_textdomain($domain, $mofile = '') { return true; }
}
if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false) { return true; }
}
if (!function_exists('get_option')) {
    function get_option($option, $default = false) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) { return true; }
}
if (!function_exists('delete_option')) {
    function delete_option($option) { return true; }
}
if (!function_exists('get_transient')) {
    function get_transient($transient) { return false; }
}
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) { return true; }
}
if (!function_exists('delete_transient')) {
    function delete_transient($transient) { return true; }
}
if (!class_exists('InterSoccerRRTestMeta')) {
    class InterSoccerRRTestMeta {
        public static $data = [];
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        if ($key !== '' && isset(InterSoccerRRTestMeta::$data[$post_id][$key])) {
            return InterSoccerRRTestMeta::$data[$post_id][$key];
        }
        return $single ? '' : [];
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        InterSoccerRRTestMeta::$data[$post_id][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key) {
        unset(InterSoccerRRTestMeta::$data[$post_id][$key]);
        return true;
    }
}
if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush() { return true; }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []) {
        if (is_object($args)) {
            $parsed_args = get_object_vars($args);
        } elseif (is_array($args)) {
            $parsed_args = $args;
        } else {
            parse_str($args, $parsed_args);
        }
        return array_merge($defaults, $parsed_args);
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key = '', $single = false) {
        return $single ? '' : [];
    }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $meta_key, $meta_value, $prev_value = '') {
        return true;
    }
}
if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id) {
        global $intersoccer_test_wc_get_order_callback;
        if (is_callable($intersoccer_test_wc_get_order_callback)) {
            return call_user_func($intersoccer_test_wc_get_order_callback, $order_id);
        }
        return false;
    }
}
if (!function_exists('wc_get_orders')) {
    function wc_get_orders($args = []) {
        return [];
    }
}
if (!function_exists('wc_get_product')) {
    function wc_get_product($product_id) {
        return false;
    }
}
if (!function_exists('wc_update_order_item_meta')) {
    function wc_update_order_item_meta($item_id, $key, $value) {
        $GLOBALS['intersoccer_test_item_meta'][(int) $item_id][$key] = $value;
        return true;
    }
}
if (!function_exists('wc_get_order_item_meta')) {
    function wc_get_order_item_meta($item_id, $key, $single = true) {
        return $GLOBALS['intersoccer_test_item_meta'][(int) $item_id][$key] ?? '';
    }
}
if (!function_exists('wc_delete_order_item_meta')) {
    function wc_delete_order_item_meta($item_id, $key) {
        unset($GLOBALS['intersoccer_test_item_meta'][(int) $item_id][$key]);
        return true;
    }
}
if (!function_exists('is_plugin_active')) {
    function is_plugin_active($plugin) {
        return false;
    }
}
if (!function_exists('get_plugin_data')) {
    function get_plugin_data($plugin_file, $markup = true, $translate = true) {
        return ['Version' => '1.0.0'];
    }
}
if (!function_exists('deactivate_plugins')) {
    function deactivate_plugins($plugins, $silent = false, $network_wide = null) {
        return null;
    }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $function) {
        return true;
    }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $function) {
        return true;
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false) {
        return true;
    }
}
if (!function_exists('get_role')) {
    function get_role($role) {
        $mock = new stdClass();
        $mock->add_cap = function() {};
        return $mock;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args) {
        return true;
    }
}
if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', $execute = true) {
        return ['Created table'];
    }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        if ($field === 'ID' && is_numeric($value) && $value > 0) {
            $user = new stdClass();
            $user->ID = (int) $value;
            $user->user_login = 'testuser' . $value;
            $user->user_email = 'test' . $value . '@example.com';
            return $user;
        }
        return false;
    }
}
if (!function_exists('wp_die')) {
    function wp_die($message, $title = '', $args = []) {
        throw new \Exception($message);
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('wp_tempnam')) {
    function wp_tempnam($filename = '', $dir = '') {
        $prefix = $filename !== '' ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $filename) : 'tmp';
        if ($prefix === '') {
            $prefix = 'tmp';
        }
        $base = ($dir !== '' ? rtrim($dir, '/') : sys_get_temp_dir());
        $path = $base . '/' . $prefix . '-' . uniqid('', true);
        $fh = fopen($path, 'w');
        if ($fh === false) {
            return false;
        }
        fclose($fh);
        return $path;
    }
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}
if (!function_exists('_e')) {
    function _e($text, $domain = 'default') {
        echo $text;
    }
}
if (!function_exists('sprintf')) {
    // sprintf is a PHP function, but just in case
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) {
        return true;
    }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null, $flags = 0) {
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null, $flags = 0) {
        echo json_encode(['success' => false, 'data' => $data]);
        exit;
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return 'test_nonce_12345';
    }
}
if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        $screen = new stdClass();
        $screen->id = 'test_screen';
        return $screen;
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        if (file_exists($target)) {
            return @is_dir($target);
        }
        
        $target = str_replace('//', '/', $target);
        $target = rtrim($target, '/');
        
        if (empty($target)) {
            $target = '/';
        }
        
        if (file_exists($target)) {
            return @is_dir($target);
        }
        
        $dir = dirname($target);
        if ($dir === $target) {
            return false;
        }
        
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        
        return @mkdir($target, 0755);
    }
}

// InterSoccer-specific stub with delegate support for test overrides
if (!function_exists('intersoccer_schedule_order_completion_check')) {
    function intersoccer_schedule_order_completion_check($order_id, $delay = null) {
        global $intersoccer_test_schedule_completion_callback;
        if (is_callable($intersoccer_test_schedule_completion_callback)) {
            call_user_func($intersoccer_test_schedule_completion_callback, $order_id, $delay);
        }
    }
}

// WooCommerce class stubs needed by tests
if (!class_exists('WooCommerce')) {
    class WooCommerce {
        public $version = '8.0.0';
    }
}
if (!class_exists('WC_Order')) {
    class WC_Order {
        public function get_id() { return 1; }
        public function get_status() { return 'completed'; }
        public function get_customer_id() { return 1; }
        public function get_billing_email() { return 'test@example.com'; }
        public function get_billing_phone() { return '+41 12 345 67 89'; }
        public function get_billing_first_name() { return 'John'; }
        public function get_billing_last_name() { return 'Doe'; }
        public function get_items($type = 'line_item') { return []; }
        public function get_meta($key, $single = true) { return ''; }
        public function update_meta_data($key, $value) {}
        public function save() { return true; }
        public function get_refunds() { return []; }
        public function get_total() { return 0.0; }
    }
}
if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        public function get_product_id() { return 1; }
        public function get_variation_id() { return 0; }
        public function get_quantity() { return 1; }
        public function get_product() { return null; }
        public function get_meta_data() { return []; }
        public function get_meta($key, $single = true) { return ''; }
        public function get_id() { return 1; }
    }
}
if (!class_exists('WC_Product')) {
    class WC_Product {
        public function get_id() { return 1; }
        public function get_name() { return 'Test Product'; }
        public function get_price() { return 100.00; }
        public function get_attributes() { return []; }
    }
}
if (!class_exists('WC_Product_Variation')) {
    class WC_Product_Variation extends WC_Product {
        public function get_attributes() { return []; }
        public function get_variation_attributes() { return []; }
    }
}

// WP_Error class needed by some code paths
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];
        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }
        public function get_error_codes() { return array_keys($this->errors); }
        public function get_error_code() { $codes = $this->get_error_codes(); return reset($codes); }
        public function get_error_messages($code = '') {
            if (empty($code)) {
                $all = [];
                foreach ($this->errors as $msgs) { $all = array_merge($all, $msgs); }
                return $all;
            }
            return $this->errors[$code] ?? [];
        }
        public function get_error_message($code = '') {
            if (empty($code)) { $code = $this->get_error_code(); }
            $msgs = $this->get_error_messages($code);
            return reset($msgs);
        }
        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!empty($data)) { $this->error_data[$code] = $data; }
        }
        public function has_errors() { return !empty($this->errors); }
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        return false;
    }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = []) {
        return 0;
    }
}
if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, $hook, $args = []) {
        return true;
    }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = [], $wp_error = false) {
        return true;
    }
}
if (!function_exists('register_post_type')) {
    function register_post_type($post_type, $args = []) {
        return new stdClass();
    }
}
if (!function_exists('add_meta_box')) {
    function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $callback_args = null) {
        return;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}
if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url, $protocols = null, $_context = 'display') {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return 1;
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) {
        return $data;
    }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin') {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null) {
        return 'https://example.com/' . ltrim($path, '/');
    }
}
if (!function_exists('site_url')) {
    function site_url($path = '', $scheme = null) {
        return 'https://example.com/' . ltrim($path, '/');
    }
}

// Define WordPress constants
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('OBJECT_K')) {
    define('OBJECT_K', 'OBJECT_K');
}

// Mock WordPress database
global $wpdb;
$wpdb = Mockery::mock('wpdb');
$wpdb->prefix = 'wp_';
$wpdb->shouldReceive('prepare')->andReturnUsing(function($query) {
    return vsprintf(str_replace('%s', "'%s'", str_replace('%d', '%d', $query)), array_slice(func_get_args(), 1));
});
$wpdb->shouldReceive('get_charset_collate')->andReturn('');

// Check if WP test suite is available
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

// If WordPress test suite is available, use it
if (file_exists($_tests_dir . '/includes/functions.php')) {
    require_once $_tests_dir . '/includes/functions.php';
    
    /**
     * Manually load plugin and dependencies for tests
     */
    function _manually_load_plugin() {
        // Mock WooCommerce if not present
        if (!class_exists('WooCommerce')) {
            require_once __DIR__ . '/Mocks/WooCommerceMock.php';
        }
        
        // Load main plugin file
        require dirname(__DIR__) . '/intersoccer-reports-rosters.php';
    }
    
    tests_add_filter('muplugins_loaded', '_manually_load_plugin');
    
    require_once $_tests_dir . '/includes/bootstrap.php';
} else {
    // Fallback: Basic WordPress function mocking
    if (!function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
            return true;
        }
    }
    
    if (!function_exists('add_filter')) {
        function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
            return true;
        }
    }
    
    if (!function_exists('wp_die')) {
        function wp_die($message, $title = '', $args = []) {
            throw new Exception($message);
        }
    }
    
    if (!function_exists('esc_html')) {
        function esc_html($text) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }
    
    if (!function_exists('__')) {
        function __($text, $domain = 'default') {
            return $text;
        }
    }
    
    if (!function_exists('_e')) {
        function _e($text, $domain = 'default') {
            echo $text;
        }
    }
    
    if (!function_exists('is_admin')) {
        function is_admin() {
            return false;
        }
    }
    
    if (!function_exists('current_user_can')) {
        function current_user_can($capability) {
            return true;
        }
    }
}

// Set up test environment
define('WP_DEBUG', true);
define('INTERSOCCER_TESTING', true);