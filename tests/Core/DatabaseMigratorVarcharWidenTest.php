<?php
/**
 * Schema 2.4.0: widen event_type / camp_times with backup-before-ALTER (mocked wpdb only).
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Core\DatabaseMigrator;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class DatabaseMigratorVarcharWidenTest extends TestCase {

    public function test_migrate_2_4_0_snapshots_before_alter(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';

        $queries = [];
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use (&$queries) {
            $queries[] = (string) $sql;
            return null;
        });
        $wpdb->shouldReceive('get_col')->with("DESCRIBE {$table}", 0)->andReturn(['event_type', 'camp_times']);
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$queries) {
            $queries[] = (string) $sql;
            return 0;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql) {
            return (string) $sql;
        });

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $migrator = new DatabaseMigrator($logger);
        $method = new \ReflectionMethod(DatabaseMigrator::class, 'migrate_2_4_0');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($migrator));

        $joined = implode("\n", $queries);
        $snapshot_pos = strpos($joined, "CREATE TABLE {$backup}");
        $alter_pos = strpos($joined, 'MODIFY event_type');
        $this->assertNotFalse($snapshot_pos);
        $this->assertNotFalse($alter_pos);
        $this->assertLessThan($alter_pos, $snapshot_pos);
    }

    public function test_migrate_skips_when_stored_version_is_current(): void {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info')->andReturnNull();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('query')->never();

        $migrator = new class($logger) extends DatabaseMigrator {
            public function getCurrentVersion() {
                return self::CURRENT_VERSION;
            }
        };

        $this->assertTrue($migrator->migrate());
    }

    public function test_migrate_2_4_0_skips_backup_create_when_backup_exists(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';

        $queries = [];
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($backup) {
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return $backup;
            }
            return null;
        });
        $wpdb->shouldReceive('get_col')->with("DESCRIBE {$table}", 0)->andReturn(['event_type', 'camp_times']);
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$queries) {
            $queries[] = (string) $sql;
            return 0;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return (string) $sql;
        });

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $migrator = new DatabaseMigrator($logger);
        $method = new \ReflectionMethod(DatabaseMigrator::class, 'migrate_2_4_0');
        $method->setAccessible(true);
        $this->assertTrue($method->invoke($migrator));

        $joined = implode("\n", $queries);
        $this->assertStringNotContainsString("CREATE TABLE {$backup}", $joined);
        $this->assertStringNotContainsString("INSERT INTO {$backup}", $joined);
    }
}
