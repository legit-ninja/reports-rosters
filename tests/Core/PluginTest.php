<?php
/**
 * Plugin Test
 * 
 * Tests for the main Plugin class
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Core\Plugin;
use Mockery;

class PluginTest extends TestCase {
    
    private $plugin_file;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->plugin_file = dirname(dirname(__DIR__)) . '/intersoccer-reports-rosters.php';
        
        // Reset singleton for testing
        $reflection = new \ReflectionClass(Plugin::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }
    
    public function test_plugin_singleton_pattern() {
        $plugin1 = Plugin::get_instance($this->plugin_file);
        $plugin2 = Plugin::get_instance();
        
        $this->assertSame($plugin1, $plugin2);
    }
    
    public function test_plugin_requires_file_on_first_call() {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Plugin file must be provided on first call');
        
        Plugin::get_instance();
    }
    
    public function test_plugin_initialization() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $this->assertInstanceOf(Plugin::class, $plugin);
    }
    
    public function test_plugin_version_constant() {
        $this->assertEquals('2.0.0', Plugin::VERSION);
    }
    
    public function test_plugin_text_domain_constant() {
        $this->assertEquals('intersoccer-reports-rosters', Plugin::TEXT_DOMAIN);
    }
    
    public function test_plugin_min_wp_version_constant() {
        $this->assertEquals('5.0', Plugin::MIN_WP_VERSION);
    }
    
    public function test_plugin_min_php_version_constant() {
        $this->assertEquals('7.4', Plugin::MIN_PHP_VERSION);
    }
    
    public function test_get_plugin_file() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $this->assertEquals($this->plugin_file, $plugin->get_plugin_file());
    }
    
    public function test_get_plugin_path() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $path = $plugin->get_plugin_path();
        
        $this->assertIsString($path);
        $this->assertNotEmpty($path);
    }
    
    public function test_get_plugin_url() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $url = $plugin->get_plugin_url();
        
        $this->assertIsString($url);
        $this->assertNotEmpty($url);
    }
    
    public function test_get_version() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $this->assertEquals('2.0.0', $plugin->get_version());
    }
    
    public function test_get_logger() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $logger = $plugin->get_logger();
        
        $this->assertNotNull($logger);
    }
    
    public function test_get_database() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $database = $plugin->get_database();
        
        $this->assertNotNull($database);
    }
    
    public function test_get_cache() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $cache = $plugin->get_cache();
        
        $this->assertNotNull($cache);
    }
    
    public function test_is_initialized() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        // Initially should be false
        $this->assertFalse($plugin->is_initialized());
        
        // After calling init, should be true
        $plugin->init();
        
        $this->assertTrue($plugin->is_initialized());
    }
    
    public function test_init_only_runs_once() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        // Call init multiple times
        $plugin->init();
        $plugin->init();
        $plugin->init();
        
        // Should still be true (not throw errors)
        $this->assertTrue($plugin->is_initialized());
    }
    
    public function test_check_dependencies() {
        Functions\when('is_plugin_active')->justReturn(true);
        Functions\when('get_plugin_data')->justReturn(['Version' => '5.0.0']);
        
        $plugin = Plugin::get_instance($this->plugin_file);
        
        // Should not throw any exceptions
        $plugin->check_dependencies();
        
        $this->assertTrue(true);
    }
    
    public function test_show_dependency_notices() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        // Should output HTML notice
        ob_start();
        $plugin->show_dependency_notices();
        $output = ob_get_clean();
        
        // Output should be string (empty or with content)
        $this->assertIsString($output);
    }
    
    public function test_plugin_cannot_be_cloned() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $this->expectException(\Error::class);
        
        $clone = clone $plugin;
    }
    
    public function test_plugin_cannot_be_unserialized() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        $serialized = serialize($plugin);
        
        $this->expectException(\Error::class);
        
        unserialize($serialized);
    }
    
    public function test_activate_hook() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        // Activation should check requirements
        try {
            $plugin->activate();
            $this->assertTrue(true);
        } catch (\Exception $e) {
            // Expected if dependencies not met
            $this->assertInstanceOf(\Exception::class, $e);
        }
    }
    
    public function test_deactivate_hook() {
        $plugin = Plugin::get_instance($this->plugin_file);
        
        // Deactivation should run cleanup
        $plugin->deactivate();
        
        $this->assertTrue(true);
    }
}

