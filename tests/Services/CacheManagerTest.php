<?php
/**
 * CacheManager Test
 * 
 * Tests for the CacheManager service
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\CacheManager;
use InterSoccer\ReportsRosters\Core\Logger;
use Brain\Monkey\Functions;
use Mockery;

class CacheManagerTest extends TestCase {
    
    private $cacheManager;
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->logger = Mockery::mock(Logger::class);
        $this->logger->shouldReceive('debug')->andReturn(null);
        $this->logger->shouldReceive('info')->andReturn(null);
        $this->logger->shouldReceive('warning')->andReturn(null);
        $this->logger->shouldReceive('error')->andReturn(null);
        
        $this->cacheManager = new CacheManager($this->logger);
    }
    
    public function test_cache_manager_initialization() {
        $this->assertInstanceOf(CacheManager::class, $this->cacheManager);
    }
    
    public function test_set_cache() {
        Functions\expect('set_transient')
            ->once()
            ->with('intersoccer_test_key', 'test_value', 3600)
            ->andReturn(true);
        
        $result = $this->cacheManager->set('test_key', 'test_value', 3600);
        
        $this->assertTrue($result);
    }
    
    public function test_get_cache() {
        Functions\expect('get_transient')
            ->once()
            ->with('intersoccer_test_key')
            ->andReturn('cached_value');
        
        $result = $this->cacheManager->get('test_key');
        
        $this->assertEquals('cached_value', $result);
    }
    
    public function test_get_cache_miss() {
        Functions\expect('get_transient')
            ->once()
            ->with('intersoccer_missing_key')
            ->andReturn(false);
        
        $result = $this->cacheManager->get('missing_key');
        
        $this->assertFalse($result);
    }
    
    public function test_delete_cache() {
        Functions\expect('delete_transient')
            ->once()
            ->with('intersoccer_test_key')
            ->andReturn(true);
        
        $result = $this->cacheManager->delete('test_key');
        
        $this->assertTrue($result);
    }
    
    public function test_clear_all_cache() {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('query')
            ->once()
            ->with(Mockery::pattern('/DELETE.*intersoccer/'))
            ->andReturn(5);
        
        $result = $this->cacheManager->clear_all();
        
        $this->assertEquals(5, $result);
    }
    
    public function test_cache_key_generation() {
        $key = $this->cacheManager->generate_key('roster', ['venue' => 'Zurich', 'date' => '2024-06-01']);
        
        $this->assertIsString($key);
        $this->assertStringContainsString('roster', $key);
    }
    
    public function test_remember_caches_and_returns_value() {
        // remember() signature is: remember($key, callable $callback, $group = 'default', $ttl = null)
        $result = $this->cacheManager->remember('computed_key', function() {
            return 'computed_value';
        }, 'default', 3600);
        
        $this->assertEquals('computed_value', $result);
    }
    
    public function test_remember_returns_cached_value() {
        // First set a value
        $this->cacheManager->set('cached_key', 'existing_value');
        
        // Then remember should return it without calling callback
        $result = $this->cacheManager->remember('cached_key', function() {
            return 'should_not_compute';
        });
        
        $this->assertEquals('existing_value', $result);
    }
    
    public function test_flush_pattern() {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('query')
            ->once()
            ->with(Mockery::pattern('/DELETE.*roster/'))
            ->andReturn(3);
        
        $result = $this->cacheManager->flush_pattern('roster');
        
        $this->assertEquals(3, $result);
    }
    
    public function test_get_cache_stats() {
        $stats = $this->cacheManager->get_stats();
        
        $this->assertIsArray($stats);
        // Stats may have different keys depending on backend
    }
    
    public function test_cleanup_expired_cache() {
        $result = $this->cacheManager->cleanup_expired();
        
        // Should return count of deleted items (may be 0 in tests)
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }
    
    public function test_has_cache_entry() {
        Functions\expect('get_transient')
            ->once()
            ->with('intersoccer_exists_key')
            ->andReturn('value');
        
        $result = $this->cacheManager->has('exists_key');
        
        $this->assertTrue($result);
    }
    
    public function test_has_no_cache_entry() {
        Functions\expect('get_transient')
            ->once()
            ->with('intersoccer_missing_key')
            ->andReturn(false);
        
        $result = $this->cacheManager->has('missing_key');
        
        $this->assertFalse($result);
    }
}

