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
        require_once dirname(__DIR__) . '/shims/is-multisite.php';
        require_once dirname(__DIR__) . '/shims/core-extension-loaded.php';
        unset($GLOBALS['intersoccer_test_is_admin'], $GLOBALS['intersoccer_test_current_user_caps'], $GLOBALS['intersoccer_test_loaded_extensions']);
        
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
        $this->satisfyDependencyChecks();
        
        $this->assertTrue($this->dependencies->check_all());
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
        Functions\when('is_plugin_active')->justReturn(false);
        Functions\when('get_option')->justReturn([]);
        unset($GLOBALS['intersoccer_test_loaded_extensions']);
        
        $missing = $this->dependencies->get_missing_dependencies();
        
        $this->assertContains('WooCommerce', $missing);
        $this->assertContains('InterSoccer Product Variations', $missing);
        $this->assertContains('Player Management', $missing);
        $this->assertNotContains('PHP mysqli extension', $missing);
    }
    
    public function test_check_php_extensions() {
        unset($GLOBALS['intersoccer_test_loaded_extensions']);
        
        $this->assertTrue($this->dependencies->check_php_extensions());
    }
    
    public function test_check_missing_php_extension() {
        $GLOBALS['intersoccer_test_loaded_extensions'] = [
            'json' => true,
            'mysqli' => false,
            'curl' => true,
            'mbstring' => true,
        ];
        
        $this->assertFalse($this->dependencies->check_php_extensions());
        unset($GLOBALS['intersoccer_test_loaded_extensions']);
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
        $this->actAsAdminUser([
            'manage_options' => true,
            'manage_woocommerce' => true,
            'view_woocommerce_reports' => true,
        ]);
        
        $this->assertTrue($this->dependencies->check_user_capabilities());
    }
    
    public function test_check_missing_capability() {
        $this->actAsAdminUser([
            'manage_options' => true,
            'manage_woocommerce' => false,
            'view_woocommerce_reports' => true,
        ]);
        
        $this->assertFalse($this->dependencies->check_user_capabilities());
    }
    
    public function test_get_dependency_report() {
        $this->satisfyDependencyChecks();
        
        $report = $this->dependencies->generate_report();
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('required_plugins', $report);
        $this->assertArrayHasKey('php_extensions', $report);
        $this->assertArrayHasKey('system_requirements', $report);
        $this->assertTrue($report['php_extensions']['status']);
        $this->assertContains('mysqli', $report['php_extensions']['required']);
    }
    
    public function test_force_refresh_clears_cache() {
        $this->satisfyDependencyChecks();
        
        $this->assertTrue($this->dependencies->check_all(false));
        $this->dependencies->clear_cache();
        $this->assertTrue($this->dependencies->check_all(true));
    }

    private function actAsAdminUser(array $caps = null): void {
        $GLOBALS['intersoccer_test_is_admin'] = true;
        $GLOBALS['intersoccer_test_current_user'] = new class {
            public $ID = 7;

            public function exists() {
                return true;
            }
        };
        if ($caps !== null) {
            $GLOBALS['intersoccer_test_current_user_caps'] = $caps;
        }
    }

    private function satisfyDependencyChecks(): void {
        Functions\when('is_plugin_active')->justReturn(true);
        Functions\when('get_plugin_data')->justReturn(['Version' => '8.0.0']);
        Functions\when('get_option')->justReturn([]);
        unset($GLOBALS['intersoccer_test_loaded_extensions']);

        global $wp_version;
        $wp_version = '6.0';

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_var')->andReturn('8.0.0');
        $wpdb->shouldReceive('query')->andReturn(true);
    }
}

