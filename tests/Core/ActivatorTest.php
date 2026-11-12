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
        $this->logger->shouldReceive('critical')->andReturn(null);
        
        $this->database = Mockery::mock(Database::class);
        $this->database->shouldReceive('validate_table_schema')->andReturn([
            'exists' => true,
            'missing_columns' => [],
        ])->byDefault();
        $this->database->shouldReceive('get_roster_entries_count')->andReturn(0)->byDefault();
        
        $this->activator = new Activator($this->database, $this->logger);
    }

    private function allowActivationSideEffects(): void {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('current_time')->justReturn('2026-06-01 12:00:00');
        Functions\when('get_role')->justReturn(new class {
            public function add_cap($cap, $grant = true) {
            }

            public function remove_cap($cap) {
            }
        });
    }
    
    public function test_activator_initialization() {
        $this->assertInstanceOf(Activator::class, $this->activator);
    }
    
    public function test_activate_creates_database_tables() {
        $this->allowActivationSideEffects();
        $this->database->shouldReceive('create_tables')->once()->andReturn(true);
        
        global $wp_version;
        $wp_version = '6.0';
        
        $result = $this->activator->activate();
        
        $this->assertTrue($result);
        $this->logger->shouldHaveReceived('info')->with('Plugin activation started');
    }
    
    public function test_activate_sets_default_options() {
        $this->allowActivationSideEffects();
        $added = [];
        Functions\expect('add_option')->andReturnUsing(function ($name, $value) use (&$added) {
            $added[$name] = $value;
            return true;
        });
        $this->database->shouldReceive('create_tables')->once()->andReturn(true);
        
        global $wp_version;
        $wp_version = '6.0';
        
        $this->assertTrue($this->activator->activate());
        $this->assertSame('2.5.15', $added['intersoccer_plugin_version']);
        $this->assertSame('2.0.0', $added['intersoccer_db_version']);
        $this->assertSame('2026-06-01 12:00:00', $added['intersoccer_activation_time']);
        $this->assertTrue($added['intersoccer_cache_enabled']);
        $this->assertSame('info', $added['intersoccer_log_level']);
        $this->assertTrue($added['intersoccer_auto_rebuild_rosters']);
    }
    
    public function test_activate_validates_environment() {
        $this->allowActivationSideEffects();
        $this->database->shouldReceive('create_tables')->once()->andReturn(true);
        
        global $wp_version;
        $wp_version = '6.0';
        
        $this->assertTrue($this->activator->activate());
    }
    
    public function test_activate_schedules_cron_jobs() {
        $this->allowActivationSideEffects();
        $hooks = [];
        Functions\expect('wp_schedule_event')->times(3)->andReturnUsing(function ($timestamp, $recurrence, $hook) use (&$hooks) {
            $hooks[] = [$recurrence, $hook];
            return true;
        });
        $this->database->shouldReceive('create_tables')->once()->andReturn(true);
        
        global $wp_version;
        $wp_version = '6.0';
        
        $this->assertTrue($this->activator->activate());
        $this->assertContains(['daily', 'intersoccer_daily_cleanup'], $hooks);
        $this->assertContains(['hourly', 'intersoccer_cache_cleanup'], $hooks);
        $this->assertContains(['weekly', 'intersoccer_log_cleanup'], $hooks);
    }
    
    public function test_activate_handles_database_creation_failure() {
        $this->allowActivationSideEffects();
        $this->database->shouldReceive('create_tables')
            ->once()
            ->andThrow(new \Exception('Database creation failed'));
        
        global $wp_version;
        $wp_version = '6.0';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database creation failed');
        
        $this->activator->activate();
    }
    
    public function test_activate_sets_activation_timestamp() {
        $this->allowActivationSideEffects();
        $updated = [];
        Functions\expect('update_option')->andReturnUsing(function ($name, $value) use (&$updated) {
            $updated[$name] = $value;
            return true;
        });
        $this->database->shouldReceive('create_tables')->once()->andReturn(true);
        
        global $wp_version;
        $wp_version = '6.0';
        
        $this->activator->activate();

        $this->assertSame('2026-06-01 12:00:00', $updated['intersoccer_activation_time']);
        $this->assertSame('completed', $updated['intersoccer_activation_status']);
    }
    
    public function test_get_activation_status() {
        $status = $this->activator->get_activation_status();
        
        $this->assertSame(7, $status['total_steps']);
        $this->assertSame(0, $status['completed_steps']);
        $this->assertSame(0, $status['failed_steps']);
        $this->assertSame('success', $status['overall_status']);
        $this->assertArrayHasKey('validate_environment', $status['steps']);
        $this->assertArrayHasKey('create_database_tables', $status['steps']);
        $this->assertArrayHasKey('setup_capabilities', $status['steps']);
    }
    
    public function test_validate_database_schema() {
        $this->assertTrue($this->activator->validate_database_schema());
        $this->database->shouldHaveReceived('validate_table_schema')->with('intersoccer_rosters');
        $this->database->shouldHaveReceived('validate_table_schema')->with('intersoccer_roster_cache');
        $this->database->shouldHaveReceived('validate_table_schema')->with('intersoccer_roster_admin_log');
    }
    
    public function test_setup_user_capabilities() {
        $added = [];
        $role = Mockery::mock();
        $role->shouldReceive('add_cap')->andReturnUsing(function ($cap) use (&$added) {
            $added[] = $cap;
        });
        Functions\expect('get_role')->andReturn($role);
        
        $this->assertTrue($this->activator->setup_capabilities());
        $this->assertContains('manage_intersoccer_rosters', $added);
        $this->assertContains('export_intersoccer_rosters', $added);
        $this->assertContains('view_intersoccer_reports', $added);
        $this->assertContains('manage_intersoccer_settings', $added);
    }
}
