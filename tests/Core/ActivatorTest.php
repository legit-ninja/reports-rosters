<?php
/**
 * Activator Test
 * 
 * Tests for the Activator class
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Core\Activator;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use Brain\Monkey\Functions;
use Mockery;

class ActivatorTest extends TestCase {
    
    private $activator;
    private $database;
    private $logger;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->logger = Mockery::mock(Logger::class);
        $this->logger->shouldReceive('info')->andReturn(null);
        $this->logger->shouldReceive('debug')->andReturn(null);
        $this->logger->shouldReceive('warning')->andReturn(null);
        $this->logger->shouldReceive('error')->andReturn(null);
        
        $this->database = Mockery::mock(Database::class);
        $this->database->shouldReceive('table_exists')->andReturn(false);
        $this->database->shouldReceive('create_table')->andReturn(true);
        $this->database->shouldReceive('get_table_name')->andReturnUsing(function($name) {
            return 'wp_' . $name;
        });
        
        $this->activator = new Activator($this->database, $this->logger);
    }
    
    public function test_activator_initialization() {
        $this->assertInstanceOf(Activator::class, $this->activator);
    }
    
    public function test_activate_creates_database_tables() {
        $this->database->shouldReceive('create_tables')->once()->andReturn(true);
        
        Functions\expect('update_option')->andReturn(true);
        Functions\expect('get_option')->andReturn(false);
        Functions\expect('wp_schedule_event')->andReturn(true);
        Functions\expect('get_role')->andReturn(Mockery::mock());
        Functions\when('time')->justReturn(time());
        
        global $wp_version;
        $wp_version = '6.0';
        
        $result = $this->activator->activate();
        
        $this->assertTrue($result);
        $this->logger->shouldHaveReceived('info')->with('Plugin activation started');
    }
    
    public function test_activate_sets_default_options() {
        $this->database->shouldReceive('create_tables')->andReturn(true);
        
        Functions\expect('update_option')
            ->with('intersoccer_plugin_version', '2.0.0')
            ->once()
            ->andReturn(true);
        
        Functions\expect('update_option')
            ->with('intersoccer_db_version', '2.0.0')
            ->once()
            ->andReturn(true);
        
        Functions\expect('update_option')
            ->with(Mockery::anyOf(
                'intersoccer_activation_time',
                'intersoccer_cache_enabled',
                'intersoccer_log_level',
                'intersoccer_auto_rebuild_rosters'
            ), Mockery::any())
            ->andReturn(true);
        
        Functions\when('get_option')->justReturn(false);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('get_role')->justReturn(Mockery::mock());
        Functions\when('time')->justReturn(time());
        
        global $wp_version;
        $wp_version = '6.0';
        
        $result = $this->activator->activate();
        
        $this->assertTrue($result);
    }
    
    public function test_activate_validates_environment() {
        $this->database->shouldReceive('create_tables')->andReturn(true);
        
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('get_role')->justReturn(Mockery::mock());
        Functions\when('time')->justReturn(time());
        
        global $wp_version;
        $wp_version = '6.0';
        
        $result = $this->activator->activate();
        
        $this->assertTrue($result);
    }
    
    public function test_activate_schedules_cron_jobs() {
        $this->database->shouldReceive('create_tables')->andReturn(true);
        
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_role')->justReturn(Mockery::mock());
        Functions\when('time')->justReturn(time());
        
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(Mockery::any(), 'daily', 'intersoccer_daily_cleanup')
            ->andReturn(true);
        
        global $wp_version;
        $wp_version = '6.0';
        
        $result = $this->activator->activate();
        
        $this->assertTrue($result);
    }
    
    public function test_activate_handles_database_creation_failure() {
        $this->database->shouldReceive('create_tables')
            ->once()
            ->andThrow(new \Exception('Database creation failed'));
        
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('time')->justReturn(time());
        
        global $wp_version;
        $wp_version = '6.0';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database creation failed');
        
        $this->activator->activate();
    }
    
    public function test_activate_sets_activation_timestamp() {
        $this->database->shouldReceive('create_tables')->andReturn(true);
        
        $current_time = time();
        Functions\when('time')->justReturn($current_time);
        
        Functions\expect('update_option')
            ->with('intersoccer_activation_time', $current_time)
            ->once()
            ->andReturn(true);
        
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('wp_schedule_event')->justReturn(true);
        Functions\when('get_role')->justReturn(Mockery::mock());
        
        global $wp_version;
        $wp_version = '6.0';
        
        $this->activator->activate();
    }
    
    public function test_get_activation_status() {
        Functions\expect('get_option')
            ->with('intersoccer_activation_time', null)
            ->andReturn(time());
        
        Functions\expect('get_option')
            ->with('intersoccer_plugin_version', '1.0.0')
            ->andReturn('2.0.0');
        
        $status = $this->activator->get_activation_status();
        
        $this->assertIsArray($status);
        $this->assertArrayHasKey('is_activated', $status);
        $this->assertArrayHasKey('activation_time', $status);
        $this->assertArrayHasKey('plugin_version', $status);
    }
    
    public function test_validate_database_schema() {
        $this->database->shouldReceive('table_exists')
            ->with('intersoccer_rosters')
            ->andReturn(true);
        
        $this->database->shouldReceive('validate_table_schema')
            ->with('intersoccer_rosters')
            ->andReturn(true);
        
        $result = $this->activator->validate_database_schema();
        
        $this->assertTrue($result);
    }
    
    public function test_setup_user_capabilities() {
        $admin_role = Mockery::mock();
        $admin_role->shouldReceive('add_cap')
            ->with('manage_intersoccer_reports')
            ->once();
        
        $admin_role->shouldReceive('add_cap')
            ->with('view_intersoccer_reports')
            ->once();
        
        Functions\expect('get_role')
            ->with('administrator')
            ->andReturn($admin_role);
        
        $result = $this->activator->setup_capabilities();
        
        $this->assertTrue($result);
    }
}

