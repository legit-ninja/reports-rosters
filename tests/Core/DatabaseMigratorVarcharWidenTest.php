<?php
/**
 * Schema 2.4.0: widen event_type / camp_times with backup-before-ALTER (mocked wpdb only).
 */

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\DatabaseMigrator;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class DatabaseMigratorVarcharWidenTest extends TestCase {

    /** @var mixed */
    private $previous_wpdb;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->previous_wpdb = $wpdb;
        $db = dirname(__DIR__, 2) . '/includes/db.php';
        if (file_exists($db)) {
            require_once $db;
        }
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previous_wpdb;
        parent::tearDown();
    }

    private function mockWpdbForMigrate24(): \Mockery\MockInterface {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static function ($s) {
            return (string) $s;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return (string) $sql;
        });
        return $wpdb;
    }

    public function test_migrate_2_4_0_snapshots_before_alter(): void {
        $wpdb = $this->mockWpdbForMigrate24();
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';

        $queries = [];
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use (&$queries, $backup, $table) {
            $queries[] = (string) $sql;
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return null;
            }
            if (strpos((string) $sql, 'COUNT') !== false) {
                if (strpos((string) $sql, $backup) !== false) {
                    return '5';
                }
                if (strpos((string) $sql, $table) !== false) {
                    return '5';
                }
            }
            return null;
        });
        $wpdb->shouldReceive('get_col')->with("DESCRIBE {$table}", 0)->andReturn(['event_type', 'camp_times', 'times']);
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$queries) {
            $queries[] = (string) $sql;
            return 0;
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
        $this->assertStringContainsString('MODIFY camp_times', $joined);
        $this->assertStringContainsString('MODIFY times', $joined);
    }

    public function test_migrate_2_4_0_widens_times_when_camp_times_absent(): void {
        $wpdb = $this->mockWpdbForMigrate24();
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $queries = [];
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($backup, $table) {
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return null;
            }
            if (strpos((string) $sql, 'COUNT') !== false) {
                return '2';
            }
            return null;
        });
        $wpdb->shouldReceive('get_col')->with("DESCRIBE {$table}", 0)->andReturn(['event_type', 'times']);
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$queries) {
            $queries[] = (string) $sql;
            return 0;
        });

        $this->assertTrue(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));

        $joined = implode("\n", $queries);
        $this->assertStringContainsString('MODIFY event_type', $joined);
        $this->assertStringContainsString('MODIFY times', $joined);
        $this->assertStringNotContainsString('MODIFY camp_times', $joined);
    }

    public function test_create_tables_schema_keeps_varchar_50_for_migration_widen_columns(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_charset_collate')->andReturn('');
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug')->andReturnNull();
        $database = new Database($logger);
        $reflection = new \ReflectionProperty(Database::class, 'table_schemas');
        $reflection->setAccessible(true);
        $schemas = $reflection->getValue($database);
        $columns = $schemas['intersoccer_rosters']['columns'];
        $this->assertStringContainsString('varchar(50)', $columns['event_type']);
        $this->assertStringContainsString('varchar(50)', $columns['camp_times']);
        $this->assertStringNotContainsString('varchar(191)', $columns['event_type']);
        $this->assertStringNotContainsString('varchar(191)', $columns['camp_times']);
    }

    public function test_legacy_create_rosters_table_sql_keeps_times_varchar_50(): void {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/db.php');
        $this->assertIsString($src);
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE \$rosters_table\s*\([^;]+;/s',
            $src,
            'Legacy CREATE TABLE SQL not found in db.php'
        );
        preg_match('/CREATE TABLE \$rosters_table\s*\([^;]+;/s', $src, $matches);
        $create = $matches[0];
        $this->assertStringContainsString('times varchar(50)', $create);
        $this->assertStringNotContainsString('varchar(191)', $create);
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

    public function test_migrate_2_4_0_rejects_pre_existing_backup_without_alter(): void {
        $wpdb = $this->mockWpdbForMigrate24();
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';

        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($backup) {
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return $backup;
            }
            return null;
        });
        $wpdb->shouldReceive('query')->never();

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $migrator = new DatabaseMigrator($logger);
        $method = new \ReflectionMethod(DatabaseMigrator::class, 'migrate_2_4_0');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke($migrator));
    }

    public function test_migrate_2_4_0_aborts_when_backup_insert_fails_and_drops_backup(): void {
        $wpdb = $this->mockWpdbForMigrate24();
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $queries = [];
        $wpdb->last_error = 'insert failed';
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('get_col')->never();
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

        $this->assertFalse(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));

        $joined = implode("\n", $queries);
        $this->assertStringContainsString("DROP TABLE IF EXISTS {$backup}", $joined);
        $this->assertStringNotContainsString('MODIFY event_type', $joined);
    }

    public function test_migrate_2_4_0_skips_alter_when_backup_count_mismatch(): void {
        $wpdb = $this->mockWpdbForMigrate24();
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $queries = [];
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($backup, $table) {
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return $backup;
            }
            if (strpos((string) $sql, 'COUNT') !== false) {
                if (strpos((string) $sql, $backup) !== false) {
                    return '9';
                }
                if (strpos((string) $sql, $table) !== false) {
                    return '10';
                }
            }
            return null;
        });
        $wpdb->shouldReceive('query')->never();
        $wpdb->shouldReceive('get_col')->never();

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $migrator = new DatabaseMigrator($logger);
        $method = new \ReflectionMethod(DatabaseMigrator::class, 'migrate_2_4_0');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke($migrator));

        $joined = implode("\n", $queries);
        $this->assertStringNotContainsString('MODIFY event_type', $joined);
    }
}
