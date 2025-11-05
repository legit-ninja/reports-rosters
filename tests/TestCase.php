<?php
/**
 * Base TestCase for InterSoccer Reports & Rosters Tests
 * 
 * Provides common testing utilities and setup/teardown
 */

namespace InterSoccer\ReportsRosters\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Mockery;

abstract class TestCase extends PHPUnitTestCase {
    
    /**
     * Setup before each test
     */
    protected function setUp(): void {
        parent::setUp();
        
        // Setup global mocks
        $this->setupGlobalMocks();
    }
    
    /**
     * Teardown after each test
     */
    protected function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }
    
    /**
     * Setup global WordPress and database mocks
     */
    protected function setupGlobalMocks(): void {
        global $wpdb;
        
        if (!isset($wpdb) || !is_object($wpdb)) {
            $wpdb = Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';
            $wpdb->shouldReceive('prepare')->andReturnUsing(function($query) {
                $args = func_get_args();
                array_shift($args);
                return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
            });
        }
    }
    
    /**
     * Create a mock wpdb instance
     * 
     * @return \Mockery\MockInterface
     */
    protected function createWpdbMock(): \Mockery\MockInterface {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function($query) {
            $args = func_get_args();
            array_shift($args);
            return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
        });
        return $wpdb;
    }
    
    /**
     * Assert array has keys
     * 
     * @param array $keys Expected keys
     * @param array $array Array to check
     * @param string $message Optional message
     */
    protected function assertArrayHasKeys(array $keys, array $array, string $message = ''): void {
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Array missing expected key: {$key}");
        }
    }
    
    /**
     * Assert value is between range
     * 
     * @param mixed $value Value to check
     * @param mixed $min Minimum value
     * @param mixed $max Maximum value
     * @param string $message Optional message
     */
    protected function assertBetween($value, $min, $max, string $message = ''): void {
        $this->assertGreaterThanOrEqual($min, $value, $message);
        $this->assertLessThanOrEqual($max, $value, $message);
    }
}

