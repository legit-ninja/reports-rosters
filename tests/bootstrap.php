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

// Database output format constants
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

// Composer autoloader (must come AFTER ABSPATH is defined)
require_once dirname(__DIR__) . '/vendor/autoload.php';

// ============================================================================
// WordPress Function Stubs - Defined BEFORE loading classes that use them
// ============================================================================

// Core WordPress functions
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

if (!function_exists('remove_action')) {
    function remove_action($hook, $callback, $priority = 10) { return true; }
}

if (!function_exists('remove_filter')) {
    function remove_filter($hook, $callback, $priority = 10) { return true; }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) { return; }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) { return $value; }
}

if (!function_exists('has_action')) {
    function has_action($hook, $callback = false) { return false; }
}

if (!function_exists('has_filter')) {
    function has_filter($hook, $callback = false) { return false; }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') { return '6.0'; }
}

// URL functions
if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin') {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null) {
        return 'https://example.test/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('site_url')) {
    function site_url($path = '', $scheme = null) {
        return 'https://example.test/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('content_url')) {
    function content_url($path = '') {
        return 'https://example.test/wp-content/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '') {
        return 'https://example.test/wp-content/plugins/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(...$args) {
        if (count($args) === 3) {
            $key = $args[0];
            $value = $args[1];
            $url = $args[2];
            $separator = (strpos($url, '?') === false) ? '?' : '&';
            return $url . $separator . urlencode($key) . '=' . urlencode($value);
        } elseif (count($args) === 2 && is_array($args[0])) {
            $url = $args[1];
            $params = $args[0];
            $separator = (strpos($url, '?') === false) ? '?' : '&';
            $query = http_build_query($params);
            return $url . $separator . $query;
        }
        return $args[0] ?? '';
    }
}

// Plugin functions
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return dirname($file) . '/'; }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'https://example.com/wp-content/plugins/'; }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return 'plugin/plugin.php'; }
}

if (!function_exists('is_plugin_active')) {
    function is_plugin_active($plugin) { return false; }
}

if (!function_exists('get_plugin_data')) {
    function get_plugin_data($plugin_file, $markup = true, $translate = true) {
        return ['Version' => '1.0.0', 'Name' => 'Test Plugin'];
    }
}

if (!function_exists('deactivate_plugins')) {
    function deactivate_plugins($plugins, $silent = false, $network_wide = null) { return null; }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $function) { return true; }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $function) { return true; }
}

// Options API
if (!function_exists('get_option')) {
    function get_option($option, $default = false) { return $default; }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) { return true; }
}

if (!function_exists('delete_option')) {
    function delete_option($option) { return true; }
}

// Transients API
if (!function_exists('get_transient')) {
    function get_transient($transient) { return false; }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) { return true; }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) { return true; }
}

// Post meta functions
if (!class_exists('InterSoccerRRTestMeta')) {
    class InterSoccerRRTestMeta {
        public static $data = [];
        public static function reset() { self::$data = []; }
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        if ($key !== '' && isset(InterSoccerRRTestMeta::$data[$post_id][$key])) {
            return $single ? InterSoccerRRTestMeta::$data[$post_id][$key] : [InterSoccerRRTestMeta::$data[$post_id][$key]];
        }
        return $single ? '' : [];
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value, $prev_value = '') {
        if (!isset(InterSoccerRRTestMeta::$data[$post_id])) {
            InterSoccerRRTestMeta::$data[$post_id] = [];
        }
        InterSoccerRRTestMeta::$data[$post_id][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key, $value = '') {
        unset(InterSoccerRRTestMeta::$data[$post_id][$key]);
        return true;
    }
}

if (!function_exists('add_post_meta')) {
    function add_post_meta($post_id, $meta_key, $meta_value, $unique = false) {
        return update_post_meta($post_id, $meta_key, $meta_value);
    }
}

// User meta functions
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

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        if ($field === 'id' && is_numeric($value) && $value > 0) {
            $user = new stdClass();
            $user->ID = (int) $value;
            $user->user_email = 'test@example.com';
            $user->display_name = 'Test User';
            return $user;
        }
        return false;
    }
}

// Capabilities
if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args) { return true; }
}

if (!function_exists('get_role')) {
    function get_role($role) {
        $mock = new stdClass();
        $mock->capabilities = [];
        $mock->add_cap = function($cap) {};
        return $mock;
    }
}

// Caching
if (!function_exists('wp_cache_flush')) {
    function wp_cache_flush() { return true; }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '', $force = false, &$found = null) {
        $found = false;
        return false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) { return true; }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') { return true; }
}

// Scheduling
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false) { return true; }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) { return false; }
}

if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, $hook, $args = [], $wp_error = false) { return true; }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = [], $wp_error = false) { return 0; }
}

// Error handling
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

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
        public function get_error_code() { return array_key_first($this->errors) ?? ''; }
        public function get_error_messages($code = '') {
            if (empty($code)) {
                return array_reduce($this->errors, 'array_merge', []);
            }
            return $this->errors[$code] ?? [];
        }
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            $messages = $this->errors[$code] ?? [];
            return $messages[0] ?? '';
        }
        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->error_data[$code] ?? null;
        }
        public function has_errors() { return !empty($this->errors); }
        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }
    }
}

// Escaping and sanitization
if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('esc_url')) {
    function esc_url($url, $protocols = null, $_context = 'display') { return filter_var($url, FILTER_SANITIZE_URL); }
}

if (!function_exists('esc_sql')) {
    function esc_sql($data) {
        global $wpdb;
        if (isset($wpdb) && method_exists($wpdb, 'prepare')) {
            return addslashes((string) $data);
        }
        return addslashes((string) $data);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim(strip_tags((string) $str)); }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) { return filter_var($email, FILTER_SANITIZE_EMAIL); }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) { return abs((int) $maybeint); }
}

// JSON functions
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

// Translation functions
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default') { echo $text; }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default') {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') { return $text; }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return esc_html($text); }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') { echo esc_html($text); }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default') { return esc_attr($text); }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = 'default') { echo esc_attr($text); }
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

// WordPress uploads
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false) {
        return [
            'path' => '/tmp/uploads',
            'url' => 'https://example.test/wp-content/uploads',
            'subdir' => '',
            'basedir' => '/tmp/uploads',
            'baseurl' => 'https://example.test/wp-content/uploads',
            'error' => false,
        ];
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

// Parse/format functions
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

// AJAX functions
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) { return true; }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return 1; }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return 'test_nonce_12345'; }
}

if (!function_exists('wp_send_json')) {
    function wp_send_json($response, $status_code = null, $flags = 0) {
        echo json_encode($response, $flags);
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null, $flags = 0) {
        echo json_encode(['success' => true, 'data' => $data], $flags);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null, $flags = 0) {
        echo json_encode(['success' => false, 'data' => $data], $flags);
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() { return false; }
}

// Database functions
if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', $execute = true) { return ['Created table']; }
}

// Admin functions
if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        $screen = new stdClass();
        $screen->id = 'test_screen';
        $screen->base = 'test';
        $screen->action = '';
        return $screen;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message, $title = '', $args = []) {
        throw new \Exception(is_string($message) ? $message : print_r($message, true));
    }
}

// Term functions (taxonomy)
if (!function_exists('get_term')) {
    function get_term($term, $taxonomy = '', $output = OBJECT, $filter = 'raw') {
        return null;
    }
}

if (!function_exists('get_term_by')) {
    function get_term_by($field, $value, $taxonomy = '', $output = OBJECT, $filter = 'raw') {
        return false;
    }
}

if (!function_exists('get_terms')) {
    function get_terms($args = [], $deprecated = '') {
        return [];
    }
}

// Post type functions
if (!function_exists('register_post_type')) {
    function register_post_type($post_type, $args = []) {
        return new stdClass();
    }
}

if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type) { return false; }
}

// ============================================================================
// WooCommerce Function and Class Stubs
// ============================================================================

if (!class_exists('WooCommerce')) {
    class WooCommerce {
        public $version = '8.0.0';
        public function __construct() {}
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        protected $id = 1;
        protected $data = [];
        protected $meta_data = [];
        protected $items = [];
        protected $refunds = [];
        
        public function __construct($order_id = 0) {
            $this->id = $order_id ?: 1;
        }
        
        public function get_id() { return $this->id; }
        public function get_status() { return 'completed'; }
        public function get_customer_id() { return 1; }
        public function get_billing_email() { return 'test@example.com'; }
        public function get_billing_phone() { return '+41 12 345 67 89'; }
        public function get_billing_first_name() { return 'John'; }
        public function get_billing_last_name() { return 'Doe'; }
        public function get_items($types = 'line_item') { return $this->items; }
        public function set_items($items) { $this->items = $items; }
        public function get_total() { return 100.00; }
        public function get_total_refunded() { return 0.0; }
        public function get_refunds() { return $this->refunds; }
        public function set_refunds($refunds) { $this->refunds = $refunds; }
        public function get_date_created() { return new \WC_DateTime(); }
        public function get_date_completed() { return new \WC_DateTime(); }
        public function get_meta($key, $single = true) {
            return $this->meta_data[$key] ?? ($single ? '' : []);
        }
        public function update_meta_data($key, $value) { $this->meta_data[$key] = $value; }
        public function save() { return true; }
    }
}

if (!class_exists('WC_DateTime')) {
    class WC_DateTime extends \DateTime {
        public function date($format) {
            return $this->format($format);
        }
    }
}

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        protected $data = [];
        protected $id = 1;
        protected $meta_data = [];
        
        public function __construct($item_id = 0) {
            $this->id = $item_id ?: 1;
        }
        
        public function get_id() { return $this->id; }
        public function get_product_id() { return $this->data['product_id'] ?? 1; }
        public function get_variation_id() { return $this->data['variation_id'] ?? 0; }
        public function get_quantity() { return $this->data['quantity'] ?? 1; }
        public function get_total() { return $this->data['total'] ?? 100.00; }
        public function get_subtotal() { return $this->data['subtotal'] ?? 100.00; }
        public function get_name() { return $this->data['name'] ?? 'Test Product'; }
        public function get_product() { return null; }
        public function get_meta_data() { return $this->meta_data; }
        public function get_meta($key, $single = true) {
            return $this->meta_data[$key] ?? ($single ? '' : []);
        }
        public function set_data($data) { $this->data = $data; }
        public function set_meta_data($meta) { $this->meta_data = $meta; }
    }
}

if (!class_exists('WC_Product')) {
    class WC_Product {
        protected $id = 1;
        protected $data = [];
        
        public function __construct($product_id = 0) {
            $this->id = $product_id ?: 1;
        }
        
        public function get_id() { return $this->id; }
        public function get_name() { return $this->data['name'] ?? 'Test Product'; }
        public function get_price() { return $this->data['price'] ?? 100.00; }
        public function get_regular_price() { return $this->data['regular_price'] ?? 100.00; }
        public function get_attributes() { return $this->data['attributes'] ?? []; }
        public function get_type() { return 'simple'; }
        public function is_type($type) { return $type === 'simple'; }
    }
}

if (!class_exists('WC_Product_Variation')) {
    class WC_Product_Variation extends WC_Product {
        public function get_type() { return 'variation'; }
        public function is_type($type) { return $type === 'variation'; }
        public function get_variation_attributes() { return $this->data['variation_attributes'] ?? []; }
    }
}

if (!class_exists('WC_Order_Refund')) {
    class WC_Order_Refund extends WC_Order {
        public function get_amount() { return 0.0; }
        public function get_reason() { return ''; }
    }
}

// WooCommerce functions
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
        global $intersoccer_test_wc_get_orders_callback;
        if (is_callable($intersoccer_test_wc_get_orders_callback)) {
            return call_user_func($intersoccer_test_wc_get_orders_callback, $args);
        }
        return [];
    }
}

if (!function_exists('wc_get_product')) {
    function wc_get_product($product_id) {
        global $intersoccer_test_wc_get_product_callback;
        if (is_callable($intersoccer_test_wc_get_product_callback)) {
            return call_user_func($intersoccer_test_wc_get_product_callback, $product_id);
        }
        return false;
    }
}

if (!function_exists('wc_get_order_item_meta')) {
    function wc_get_order_item_meta($item_id, $key, $single = true) {
        return $single ? '' : [];
    }
}

if (!function_exists('wc_update_order_item_meta')) {
    function wc_update_order_item_meta($item_id, $meta_key, $meta_value, $prev_value = '') {
        return true;
    }
}

if (!function_exists('wc_delete_order_item_meta')) {
    function wc_delete_order_item_meta($item_id, $meta_key, $meta_value = '', $delete_all = false) {
        return true;
    }
}

// ============================================================================
// InterSoccer-specific stubs
// ============================================================================

if (!function_exists('intersoccer_schedule_order_completion_check')) {
    function intersoccer_schedule_order_completion_check($order_id, $delay = null) {
        global $intersoccer_test_schedule_completion_callback;
        if (is_callable($intersoccer_test_schedule_completion_callback)) {
            call_user_func($intersoccer_test_schedule_completion_callback, $order_id, $delay);
        }
    }
}

// Note: intersoccer_get_term_name is defined in includes/utils.php 
// Do NOT define it here to avoid redeclaration

// ============================================================================
// Mock WordPress database - defined as a proper mock with all needed methods
// ============================================================================

// Create a simple wpdb mock class for tests
// Using a real class rather than Mockery allows setting properties directly
if (!class_exists('InterSoccerTestWpdb')) {
    class InterSoccerTestWpdb {
        public $prefix = 'wp_';
        public $posts = 'wp_posts';
        public $postmeta = 'wp_postmeta';
        public $options = 'wp_options';
        public $users = 'wp_users';
        public $usermeta = 'wp_usermeta';
        public $termmeta = 'wp_termmeta';
        public $terms = 'wp_terms';
        public $term_taxonomy = 'wp_term_taxonomy';
        public $term_relationships = 'wp_term_relationships';
        public $comments = 'wp_comments';
        public $commentmeta = 'wp_commentmeta';
        public $links = 'wp_links';
        public $last_error = '';
        public $last_query = '';
        public $insert_id = 1;
        public $num_rows = 0;
        public $rows_affected = 1;
        
        public function prepare($query, ...$args) {
            if (empty($args)) {
                return $query;
            }
            $flat_args = [];
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    $flat_args = array_merge($flat_args, $arg);
                } else {
                    $flat_args[] = $arg;
                }
            }
            $safe_args = array_map(function($v) {
                return is_string($v) ? addslashes($v) : $v;
            }, $flat_args);
            return @vsprintf(str_replace(['%s', '%d', '%f'], ["'%s'", '%d', '%f'], $query), $safe_args) ?: $query;
        }
        
        public function get_results($query = null, $output = OBJECT) { return []; }
        public function get_row($query = null, $output = OBJECT, $y = 0) { return null; }
        public function get_var($query = null, $x = 0, $y = 0) { return null; }
        public function get_col($query = null, $x = 0) { return []; }
        public function query($query) { return true; }
        public function insert($table, $data, $format = null) { return 1; }
        public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
        public function delete($table, $where, $where_format = null) { return 1; }
        public function replace($table, $data, $format = null) { return 1; }
        public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
        public function esc_like($text) { return addcslashes($text, '_%\\'); }
        public function tables($scope = 'all', $prefix = true, $blog_id = 0) { return []; }
        public function show_errors($show = true) { return true; }
        public function hide_errors() { return true; }
        public function suppress_errors($suppress = true) { return true; }
    }
}

global $wpdb;
$wpdb = new InterSoccerTestWpdb();

// ============================================================================
// Load application classes
// ============================================================================

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
    'classes/export/export-exporter.php',
    'classes/reports/abstract-report.php',
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
    if (basename($file) !== 'validation-tests.php') {
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
