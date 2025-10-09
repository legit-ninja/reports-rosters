<?php
/**
 * InterSoccer Cache Manager Service
 * 
 * Handles caching for:
 * - Roster data
 * - WooCommerce order queries
 * - Player metadata
 * - Report data
 * - Chart data for dashboard
 * 
 * Supports multiple cache backends:
 * - WordPress transients (default)
 * - File-based caching
 * - Redis (if available)
 * 
 * @package InterSoccer_Reports_Rosters
 * @subpackage Services
 * @version 1.0.0
 */

namespace InterSoccer\Services;

use InterSoccer\Core\Logger;
use InterSoccer\Utils\ValidationHelper;
use InterSoccer\Exceptions\PluginException;

if (!defined('ABSPATH')) {
    exit;
}

class CacheManager {

    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;

    /**
     * Cache backend type
     * 
     * @var string
     */
    private $backend;

    /**
     * Cache configuration
     * 
     * @var array
     */
    private $config;

    /**
     * Default cache expiration times (in seconds)
     * 
     * @var array
     */
    private $default_ttl = [
        'roster_data' => 3600,      // 1 hour
        'order_queries' => 1800,    // 30 minutes
        'player_data' => 7200,      // 2 hours
        'report_data' => 1800,      // 30 minutes
        'chart_data' => 900,        // 15 minutes
        'export_data' => 600,       // 10 minutes
        'stats_data' => 3600        // 1 hour
    ];

    /**
     * Cache key prefix
     * 
     * @var string
     */
    private $key_prefix = 'intersoccer_';

    /**
     * Redis connection (if available)
     * 
     * @var \Redis|null
     */
    private $redis = null;

    /**
     * File cache directory
     * 
     * @var string
     */
    private $cache_dir;

    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     * @param array  $config Cache configuration
     */
    public function __construct(Logger $logger = null, array $config = []) {
        $this->logger = $logger ?: new Logger();
        $this->config = wp_parse_args($config, [
            'backend' => 'transients', // transients, file, redis
            'ttl_multiplier' => 1.0,
            'compression' => true,
            'max_file_cache_size' => 50 * 1024 * 1024, // 50MB
            'cleanup_probability' => 0.01 // 1% chance on each operation
        ]);

        $this->cache_dir = WP_CONTENT_DIR . '/cache/intersoccer/';
        $this->initialize_backend();
        
        $this->logger->debug('CacheManager initialized', [
            'backend' => $this->backend,
            'config' => $this->config
        ]);
    }

    /**
     * Initialize cache backend
     */
    private function initialize_backend() {
        // Try Redis first if enabled
        if ($this->config['backend'] === 'redis' && class_exists('Redis')) {
            try {
                $this->redis = new \Redis();
                if ($this->redis->connect('127.0.0.1', 6379)) {
                    $this->backend = 'redis';
                    $this->logger->info('Redis cache backend initialized');
                    return;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to connect to Redis, falling back', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Try file cache if enabled and directory is writable
        if ($this->config['backend'] === 'file' || $this->config['backend'] === 'redis') {
            if (!file_exists($this->cache_dir)) {
                wp_mkdir_p($this->cache_dir);
            }
            
            if (is_writable($this->cache_dir)) {
                $this->backend = 'file';
                $this->logger->info('File cache backend initialized', [
                    'cache_dir' => $this->cache_dir
                ]);
                return;
            }
        }

        // Default to WordPress transients
        $this->backend = 'transients';
        $this->logger->info('WordPress transients cache backend initialized');
    }

    /**
     * Store data in cache
     * 
     * @param string $key        Cache key
     * @param mixed  $data       Data to cache
     * @param string $group      Cache group for TTL
     * @param int    $ttl        Custom TTL (optional)
     * 
     * @return bool Success status
     */
    public function set($key, $data, $group = 'default', $ttl = null) {
        try {
            $cache_key = $this->generate_cache_key($key, $group);
            $ttl = $ttl ?: $this->get_ttl($group);
            
            // Compress data if enabled
            $cache_data = $this->config['compression'] ? $this->compress_data($data) : $data;
            
            $success = false;
            
            switch ($this->backend) {
                case 'redis':
                    $success = $this->set_redis($cache_key, $cache_data, $ttl);
                    break;
                case 'file':
                    $success = $this->set_file($cache_key, $cache_data, $ttl);
                    break;
                case 'transients':
                default:
                    $success = set_transient($cache_key, $cache_data, $ttl);
                    break;
            }
            
            if ($success) {
                $this->logger->debug('Cache set successful', [
                    'key' => $cache_key,
                    'group' => $group,
                    'ttl' => $ttl,
                    'backend' => $this->backend
                ]);
            }
            
            // Probabilistic cleanup
            if (mt_rand(1, 100) <= ($this->config['cleanup_probability'] * 100)) {
                $this->cleanup_expired();
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->logger->error('Cache set failed', [
                'key' => $key,
                'group' => $group,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Retrieve data from cache
     * 
     * @param string $key     Cache key
     * @param string $group   Cache group
     * @param mixed  $default Default value if not found
     * 
     * @return mixed Cached data or default value
     */
    public function get($key, $group = 'default', $default = null) {
        try {
            $cache_key = $this->generate_cache_key($key, $group);
            
            $data = null;
            
            switch ($this->backend) {
                case 'redis':
                    $data = $this->get_redis($cache_key);
                    break;
                case 'file':
                    $data = $this->get_file($cache_key);
                    break;
                case 'transients':
                default:
                    $data = get_transient($cache_key);
                    break;
            }
            
            if ($data === false || $data === null) {
                $this->logger->debug('Cache miss', [
                    'key' => $cache_key,
                    'group' => $group,
                    'backend' => $this->backend
                ]);
                return $default;
            }
            
            // Decompress data if needed
            $result = $this->config['compression'] ? $this->decompress_data($data) : $data;
            
            $this->logger->debug('Cache hit', [
                'key' => $cache_key,
                'group' => $group,
                'backend' => $this->backend
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('Cache get failed', [
                'key' => $key,
                'group' => $group,
                'error' => $e->getMessage()
            ]);
            return $default;
        }
    }

    /**
     * Delete item from cache
     * 
     * @param string $key   Cache key
     * @param string $group Cache group
     * 
     * @return bool Success status
     */
    public function delete($key, $group = 'default') {
        try {
            $cache_key = $this->generate_cache_key($key, $group);
            
            $success = false;
            
            switch ($this->backend) {
                case 'redis':
                    $success = $this->redis->del($cache_key) > 0;
                    break;
                case 'file':
                    $file_path = $this->get_file_path($cache_key);
                    $success = file_exists($file_path) ? unlink($file_path) : true;
                    break;
                case 'transients':
                default:
                    $success = delete_transient($cache_key);
                    break;
            }
            
            $this->logger->debug('Cache delete', [
                'key' => $cache_key,
                'group' => $group,
                'success' => $success,
                'backend' => $this->backend
            ]);
            
            return $success;
            
        } catch (\Exception $e) {
            $this->logger->error('Cache delete failed', [
                'key' => $key,
                'group' => $group,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clear all cache for a group
     * 
     * @param string $group Cache group
     * 
     * @return bool Success status
     */
    public function clear_group($group) {
        try {
            $pattern = $this->generate_cache_key('*', $group);
            $count = 0;
            
            switch ($this->backend) {
                case 'redis':
                    $keys = $this->redis->keys($pattern);
                    if ($keys) {
                        $count = $this->redis->del($keys);
                    }
                    break;
                    
                case 'file':
                    $files = glob($this->cache_dir . $this->key_prefix . $group . '_*');
                    foreach ($files as $file) {
                        if (unlink($file)) {
                            $count++;
                        }
                    }
                    break;
                    
                case 'transients':
                default:
                    // WordPress doesn't have built-in group deletion
                    // We'll track keys in an index for this
                    $index_key = $this->key_prefix . 'index_' . $group;
                    $keys = get_transient($index_key);
                    if (is_array($keys)) {
                        foreach ($keys as $key) {
                            if (delete_transient($key)) {
                                $count++;
                            }
                        }
                        delete_transient($index_key);
                    }
                    break;
            }
            
            $this->logger->info('Cache group cleared', [
                'group' => $group,
                'keys_deleted' => $count,
                'backend' => $this->backend
            ]);
            
            return $count > 0;
            
        } catch (\Exception $e) {
            $this->logger->error('Cache group clear failed', [
                'group' => $group,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clear all cache
     * 
     * @return bool Success status
     */
    public function clear_all() {
        try {
            $count = 0;
            
            switch ($this->backend) {
                case 'redis':
                    $keys = $this->redis->keys($this->key_prefix . '*');
                    if ($keys) {
                        $count = $this->redis->del($keys);
                    }
                    break;
                    
                case 'file':
                    $files = glob($this->cache_dir . $this->key_prefix . '*');
                    foreach ($files as $file) {
                        if (unlink($file)) {
                            $count++;
                        }
                    }
                    break;
                    
                case 'transients':
                default:
                    // Clear all our transients
                    global $wpdb;
                    $result = $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                            '_transient_' . $this->key_prefix . '%',
                            '_transient_timeout_' . $this->key_prefix . '%'
                        )
                    );
                    $count = $result ?: 0;
                    break;
            }
            
            $this->logger->info('All cache cleared', [
                'keys_deleted' => $count,
                'backend' => $this->backend
            ]);
            
            return $count > 0;
            
        } catch (\Exception $e) {
            $this->logger->error('Clear all cache failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get or set cache data with callback
     * 
     * @param string   $key      Cache key
     * @param callable $callback Function to generate data if not cached
     * @param string   $group    Cache group
     * @param int      $ttl      Custom TTL
     * 
     * @return mixed Cached or generated data
     */
    public function remember($key, callable $callback, $group = 'default', $ttl = null) {
        $data = $this->get($key, $group);
        
        if ($data !== null) {
            return $data;
        }
        
        // Generate data
        try {
            $data = $callback();
            $this->set($key, $data, $group, $ttl);
            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Cache remember callback failed', [
                'key' => $key,
                'group' => $group,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Cache roster data with automatic invalidation
     * 
     * @param string $roster_key Unique roster identifier
     * @param array  $roster_data Roster data to cache
     * 
     * @return bool Success status
     */
    public function cache_roster($roster_key, $roster_data) {
        $success = $this->set($roster_key, $roster_data, 'roster_data');
        
        if ($success) {
            // Track roster keys for group operations
            $this->add_to_index('roster_data', $roster_key);
        }
        
        return $success;
    }

    /**
     * Get cached roster data
     * 
     * @param string $roster_key Unique roster identifier
     * 
     * @return array|null Roster data or null if not cached
     */
    public function get_roster($roster_key) {
        return $this->get($roster_key, 'roster_data');
    }

    /**
     * Invalidate roster cache when orders change
     * 
     * @param int $order_id WooCommerce order ID
     */
    public function invalidate_roster_cache($order_id = null) {
        if ($order_id) {
            // Invalidate specific order-related caches
            $this->delete("order_{$order_id}", 'order_queries');
            
            // Get product IDs from order and invalidate related rosters
            $order = wc_get_order($order_id);
            if ($order) {
                foreach ($order->get_items() as $item) {
                    $product_id = $item->get_product_id();
                    $variation_id = $item->get_variation_id();
                    
                    // Invalidate product-specific roster caches
                    $this->delete("roster_product_{$product_id}", 'roster_data');
                    if ($variation_id) {
                        $this->delete("roster_variation_{$variation_id}", 'roster_data');
                    }
                }
            }
        } else {
            // Clear all roster caches
            $this->clear_group('roster_data');
        }
        
        // Also clear report data as it depends on roster data
        $this->clear_group('report_data');
        
        $this->logger->info('Roster cache invalidated', [
            'order_id' => $order_id
        ]);
    }

    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function get_stats() {
        $stats = [
            'backend' => $this->backend,
            'total_keys' => 0,
            'total_size' => 0,
            'groups' => []
        ];
        
        try {
            switch ($this->backend) {
                case 'redis':
                    $keys = $this->redis->keys($this->key_prefix . '*');
                    $stats['total_keys'] = count($keys);
                    // Redis memory usage would require additional commands
                    break;
                    
                case 'file':
                    $files = glob($this->cache_dir . $this->key_prefix . '*');
                    $stats['total_keys'] = count($files);
                    foreach ($files as $file) {
                        $stats['total_size'] += filesize($file);
                    }
                    break;
                    
                case 'transients':
                default:
                    global $wpdb;
                    $result = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT COUNT(*) as count, SUM(LENGTH(option_value)) as size 
                             FROM {$wpdb->options} 
                             WHERE option_name LIKE %s",
                            '_transient_' . $this->key_prefix . '%'
                        )
                    );
                    $stats['total_keys'] = $result->count ?? 0;
                    $stats['total_size'] = $result->size ?? 0;
                    break;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to get cache stats', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $stats;
    }

    /**
     * Generate cache key with prefix and group
     * 
     * @param string $key   Base key
     * @param string $group Cache group
     * 
     * @return string Full cache key
     */
    private function generate_cache_key($key, $group) {
        return $this->key_prefix . $group . '_' . md5($key);
    }

    /**
     * Get TTL for cache group
     * 
     * @param string $group Cache group
     * 
     * @return int TTL in seconds
     */
    private function get_ttl($group) {
        $base_ttl = $this->default_ttl[$group] ?? $this->default_ttl['roster_data'];
        return intval($base_ttl * $this->config['ttl_multiplier']);
    }

    /**
     * Compress data for storage
     * 
     * @param mixed $data Data to compress
     * 
     * @return string Compressed data
     */
    private function compress_data($data) {
        return gzcompress(serialize($data), 6);
    }

    /**
     * Decompress stored data
     * 
     * @param string $data Compressed data
     * 
     * @return mixed Decompressed data
     */
    private function decompress_data($data) {
        return unserialize(gzuncompress($data));
    }

    /**
     * Set data in Redis
     * 
     * @param string $key  Cache key
     * @param mixed  $data Data to store
     * @param int    $ttl  TTL in seconds
     * 
     * @return bool Success status
     */
    private function set_redis($key, $data, $ttl) {
        if (!$this->redis) {
            return false;
        }
        
        return $this->redis->setex($key, $ttl, serialize($data));
    }

    /**
     * Get data from Redis
     * 
     * @param string $key Cache key
     * 
     * @return mixed Data or false if not found
     */
    private function get_redis($key) {
        if (!$this->redis) {
            return false;
        }
        
        $data = $this->redis->get($key);
        return $data !== false ? unserialize($data) : false;
    }

    /**
     * Set data in file cache
     * 
     * @param string $key  Cache key
     * @param mixed  $data Data to store
     * @param int    $ttl  TTL in seconds
     * 
     * @return bool Success status
     */
    private function set_file($key, $data, $ttl) {
        $file_path = $this->get_file_path($key);
        
        $cache_data = [
            'data' => $data,
            'expires' => time() + $ttl,
            'created' => time()
        ];
        
        return file_put_contents($file_path, serialize($cache_data), LOCK_EX) !== false;
    }

    /**
     * Get data from file cache
     * 
     * @param string $key Cache key
     * 
     * @return mixed Data or false if not found/expired
     */
    private function get_file($key) {
        $file_path = $this->get_file_path($key);
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        $cache_data = unserialize(file_get_contents($file_path));
        
        // Check expiration
        if ($cache_data['expires'] < time()) {
            unlink($file_path);
            return false;
        }
        
        return $cache_data['data'];
    }

    /**
     * Get file path for cache key
     * 
     * @param string $key Cache key
     * 
     * @return string File path
     */
    private function get_file_path($key) {
        return $this->cache_dir . $key . '.cache';
    }

    /**
     * Add key to group index (for transients)
     * 
     * @param string $group Cache group
     * @param string $key   Cache key
     */
    private function add_to_index($group, $key) {
        if ($this->backend === 'transients') {
            $index_key = $this->key_prefix . 'index_' . $group;
            $keys = get_transient($index_key) ?: [];
            $cache_key = $this->generate_cache_key($key, $group);
            
            if (!in_array($cache_key, $keys)) {
                $keys[] = $cache_key;
                set_transient($index_key, $keys, $this->get_ttl($group));
            }
        }
    }

    /**
     * Cleanup expired cache entries
     */
    private function cleanup_expired() {
        try {
            switch ($this->backend) {
                case 'file':
                    $files = glob($this->cache_dir . $this->key_prefix . '*.cache');
                    foreach ($files as $file) {
                        $cache_data = @unserialize(file_get_contents($file));
                        if ($cache_data && $cache_data['expires'] < time()) {
                            unlink($file);
                        }
                    }
                    break;
                    
                // Redis and transients handle expiration automatically
            }
        } catch (\Exception $e) {
            $this->logger->warning('Cache cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
}