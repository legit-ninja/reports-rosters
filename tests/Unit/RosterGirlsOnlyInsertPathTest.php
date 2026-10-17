<?php
/**
 * Girls-only flag must survive Database::sanitize_roster_data() and insert_roster_entry().
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use Mockery;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

class RosterGirlsOnlyInsertPathTest extends PHPUnitTestCase {
    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__) . '/bootstrap.php';
    }

    protected function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sanitize_roster_data_keeps_girls_only_and_event_signature(): void {
        $logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
        $database = new Database($logger);

        $ref = new \ReflectionMethod(Database::class, 'sanitize_roster_data');
        $ref->setAccessible(true);

        $signature = 'd0d63a992530b24393d2e1906151b446';
        $sanitized = $ref->invoke($database, [
            'activity_type' => 'Camp',
            'girls_only' => 1,
            'event_signature' => $signature,
            'order_id' => 1,
        ]);

        $this->assertSame(1, $sanitized['girls_only']);
        $this->assertSame($signature, $sanitized['event_signature']);

        $sanitized_zero = $ref->invoke($database, [
            'activity_type' => 'Camp',
            'girls_only' => 0,
            'event_signature' => '1f8ad679689dda1fb28a20639fcc24a6',
        ]);
        $this->assertSame(0, $sanitized_zero['girls_only']);
    }

    public function test_insert_roster_entry_passes_girls_only_to_wpdb_insert(): void {
        global $wpdb;

        $logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_var')->andReturn(0);

        $captured = null;
        $wpdb->shouldReceive('insert')
            ->once()
            ->withArgs(function ($table, $data) use (&$captured) {
                $captured = $data;
                return $table === 'wp_intersoccer_rosters';
            })
            ->andReturn(1);
        $wpdb->insert_id = 9001;

        $database = new Database($logger);
        $signature = 'd0d63a992530b24393d2e1906151b446';
        $id = $database->insert_roster_entry([
            'order_id' => 62467,
            'order_item_id' => 7887,
            'product_id' => 51723,
            'customer_id' => 46231,
            'player_index' => 0,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'activity_type' => 'Camp',
            'girls_only' => 1,
            'event_signature' => $signature,
        ]);

        $this->assertSame(9001, $id);
        $this->assertIsArray($captured);
        $this->assertSame(1, $captured['girls_only']);
        $this->assertSame($signature, $captured['event_signature']);
    }
}
