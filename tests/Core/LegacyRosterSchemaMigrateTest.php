<?php

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class LegacyRosterSchemaMigrateTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $db = dirname(__DIR__, 2) . '/includes/db.php';
        if (file_exists($db)) {
            require_once $db;
        }
    }

    public function test_legacy_2_4_0_aborts_when_backup_insert_fails(): void {
        $wpdb = Mockery::mock('wpdb');
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $queries = [];
        $wpdb->last_error = 'insert failed';
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return (string) $sql;
        });
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$queries) {
            $queries[] = (string) $sql;
            if (strpos((string) $sql, 'CREATE TABLE') !== false) {
                return 0;
            }
            if (strpos((string) $sql, 'INSERT INTO') !== false) {
                return false;
            }
            return 0;
        });
        $wpdb->shouldReceive('get_col')->never();

        $this->assertFalse(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));
        $joined = implode("\n", $queries);
        $this->assertStringContainsString("CREATE TABLE {$backup}", $joined);
        $this->assertStringNotContainsString('MODIFY event_type', $joined);
    }

    public function test_legacy_2_4_0_noop_when_backup_already_exists(): void {
        $wpdb = Mockery::mock('wpdb');
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $queries = [];
        $wpdb->last_error = '';
        $wpdb->shouldReceive('get_var')->andReturn($backup);
        $wpdb->shouldReceive('get_col')->with("DESCRIBE {$table}", 0)->andReturn(['event_type', 'camp_times']);
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$queries) {
            $queries[] = (string) $sql;
            return 0;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return (string) $sql;
        });

        $this->assertTrue(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));
        $joined = implode("\n", $queries);
        $this->assertStringNotContainsString("CREATE TABLE {$backup}", $joined);
        $this->assertStringContainsString('MODIFY event_type', $joined);
    }
}
