<?php
/**
 * Dependencies Test
 * 
 * Tests for the Dependencies class
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Core\Dependencies;
use InterSoccer\ReportsRosters\Core\Logger;
use Brain\Monkey\Functions;
use Mockery;

class DependenciesTest extends TestCase {
    
    private $dependencies;
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->logger = Mockery::mock(Logger::class);
        $this->logger->shouldReceive('debug')->andReturn(null);
        $this->logger->shouldReceive('info')->andReturn(null);
        $this->logger->shouldReceive('warning')->andReturn(null);
        $this->logger->shouldReceive('error')->andReturn(null);
        
        $this->dependencies = new Dependencies($this->logger);
    }
    
    public function test_dependencies_initialization() {
        $this->assertInstanceOf(Dependencies::class, $this->dependencies);
    }
    
    public function test_check_all_with_all_dependencies_met() {
        Functions\expect('is_plugin_active')
            ->times(3)
            ->andReturn(true);
        
        Functions\expect('get_plugin_data')
            ->times(3)
            ->andReturn(['Version' => '5.0.0']);
        
        Functions\expect('extension_loaded')
            ->times(4)
            ->andReturn(true);
        
        Functions\when('current_user_can')->justReturn(true);
        
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_var')->andReturn('1');
        
        $result = $this->dependencies->check_all();
        
        $this->assertTrue($result);
    }
    
    public function test_check_woocommerce_dependency() {
        Functions\expect('is_plugin_active')
            ->with('woocommerce/woocommerce.php')
            ->once()
            ->andReturn(true);
        
        Functions\expect('get_plugin_data')
            ->once()
            ->andReturn(['Version' => '8.0.0']);
        
        $result = $this->dependencies->check_plugin('woocommerce/woocommerce.php');
        
        $this->assertTrue($result);
    }
    
    public function test_check_missing_plugin() {
        Functions\expect('is_plugin_active')
            ->with('fake-plugin/fake-plugin.php')
            ->once()
            ->andReturn(false);
        
        $result = $this->dependencies->check_plugin('fake-plugin/fake-plugin.php');
        
        $this->assertFalse($result);
    }
    
    public function test_get_missing_dependencies() {
        Functions\expect('is_plugin_active')
            ->andReturn(false);
        
        Functions\expect('extension_loaded')
            ->andReturn(true);
        
        Functions\when('current_user_can')->justReturn(true);
        
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_var')->andReturn('1');
        
        $this->dependencies->check_all();
        $missing = $this->dependencies->get_missing_dependencies();
        
        $this->assertIsArray($missing);
        $this->assertNotEmpty($missing);
    }
    
    public function test_check_php_extensions() {
        Functions\expect('extension_loaded')
            ->times(4)
            ->with(Mockery::anyOf('json', 'mysqli', 'curl', 'mbstring'))
            ->andReturn(true);
        
        $result = $this->dependencies->check_php_extensions();
        
        $this->assertTrue($result);
    }
    
    public function test_check_missing_php_extension() {
        Functions\expect('extension_loaded')
            ->with('json')
            ->andReturn(true);
        
        Functions\expect('extension_loaded')
            ->with('mysqli')
            ->andReturn(false);
        
        Functions\expect('extension_loaded')
            ->with('curl')
            ->andReturn(true);
        
        Functions\expect('extension_loaded')
            ->with('mbstring')
            ->andReturn(true);
        
        $result = $this->dependencies->check_php_extensions();
        
        $this->assertFalse($result);
    }
    
    public function test_check_system_requirements() {
        Functions\when('version_compare')->alias(function($a, $b, $op) {
            return version_compare($a, $b, $op);
        });
        
        global $wp_version;
        $wp_version = '6.0';
        
        $result = $this->dependencies->check_system_requirements();
        
        $this->assertTrue($result);
    }
    
    public function test_check_database_requirements() {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_var')
            ->with('SELECT VERSION()')
            ->andReturn('8.0.0');
        
        $result = $this->dependencies->check_database_requirements();
        
        $this->assertTrue($result);
    }
    
    public function test_check_user_capabilities() {
        Functions\expect('current_user_can')
            ->times(3)
            ->andReturn(true);
        
        $result = $this->dependencies->check_user_capabilities();
        
        $this->assertTrue($result);
    }
    
    public function test_check_missing_capability() {
        Functions\expect('current_user_can')
            ->with('manage_options')
            ->andReturn(true);
        
        Functions\expect('current_user_can')
            ->with('manage_woocommerce')
            ->andReturn(false);
        
        Functions\expect('current_user_can')
            ->with('view_woocommerce_reports')
            ->andReturn(true);
        
        $result = $this->dependencies->check_user_capabilities();
        
        $this->assertFalse($result);
    }
    
    public function test_get_dependency_report() {
        Functions\when('is_plugin_active')->justReturn(true);
        Functions\when('get_plugin_data')->justReturn(['Version' => '1.0.0']);
        Functions\when('extension_loaded')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_var')->andReturn('8.0.0');
        
        global $wp_version;
        $wp_version = '6.0';
        
        $report = $this->dependencies->get_dependency_report();
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('plugins', $report);
        $this->assertArrayHasKey('php_extensions', $report);
        $this->assertArrayHasKey('system', $report);
    }
    
    public function test_force_refresh_clears_cache() {
        Functions\when('is_plugin_active')->justReturn(true);
        Functions\when('get_plugin_data')->justReturn(['Version' => '1.0.0']);
        Functions\when('extension_loaded')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('get_var')->andReturn('8.0.0');
        
        // First check
        $this->dependencies->check_all(false);
        
        // Second check with force refresh should re-check
        $result = $this->dependencies->check_all(true);
        
        $this->assertTrue($result);
    }
}

