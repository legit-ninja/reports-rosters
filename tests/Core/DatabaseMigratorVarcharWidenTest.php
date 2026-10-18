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
        delete_option(DatabaseMigrator::MIGRATION_LOCK_OPTION);
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
        $wpdb->shouldReceive('get_results')->andReturnUsing(static function ($sql) {
            if (strpos((string) $sql, 'DESCRIBE') !== false) {
                return [
                    ['Field' => 'event_type', 'Type' => 'varchar(50)'],
                    ['Field' => 'camp_times', 'Type' => 'varchar(50)'],
                    ['Field' => 'times', 'Type' => 'varchar(50)'],
                ];
            }
            return [];
        })->byDefault();
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

    public function test_create_tables_schema_uses_varchar_191_so_dbdelta_does_not_shrink(): void {
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
        $this->assertStringContainsString('varchar(191)', $columns['event_type']);
        $this->assertStringContainsString('varchar(191)', $columns['camp_times']);
        $this->assertStringNotContainsString('varchar(50)', $columns['event_type']);
        $this->assertStringNotContainsString('varchar(50)', $columns['camp_times']);
    }

    public function test_oop_create_table_sql_requests_varchar_191_for_widen_columns(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug')->andReturnNull();
        $database = new Database($logger);
        $method = new \ReflectionMethod(Database::class, 'build_create_table_sql');
        $method->setAccessible(true);
        $schema_prop = new \ReflectionProperty(Database::class, 'table_schemas');
        $schema_prop->setAccessible(true);
        $schema = $schema_prop->getValue($database)['intersoccer_rosters'];
        $sql = $method->invoke($database, 'wp_intersoccer_rosters', $schema, '');
        $this->assertStringContainsString('event_type varchar(191)', $sql);
        $this->assertStringContainsString('camp_times varchar(191)', $sql);
        $this->assertStringNotContainsString('event_type varchar(50)', $sql);
        $this->assertStringNotContainsString('camp_times varchar(50)', $sql);
    }

    public function test_legacy_create_rosters_table_sql_uses_times_varchar_191(): void {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/db.php');
        $this->assertIsString($src);
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE \$rosters_table\s*\([^;]+;/s',
            $src,
            'Legacy CREATE TABLE SQL not found in db.php'
        );
        preg_match('/CREATE TABLE \$rosters_table\s*\([^;]+;/s', $src, $matches);
        $create = $matches[0];
        $this->assertStringContainsString('times varchar(191)', $create);
        $this->assertStringNotContainsString('times varchar(50)', $create);
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

        $wpdb->shouldReceive('get_results')->andReturn([
            ['Field' => 'times', 'Type' => 'varchar(50)'],
        ]);
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
        $this->assertSame(DatabaseMigrator::RESULT_SKIPPED_UNRECOGNISED_BACKUP, $method->invoke($migrator));
    }

    public function test_migrate_2_4_0_succeeds_without_backup_when_columns_already_wide(): void {
        $wpdb = $this->mockWpdbForMigrate24();
        $table = 'wp_intersoccer_rosters';
        $wpdb->shouldReceive('get_results')->andReturn([
            ['Field' => 'event_type', 'Type' => 'varchar(191)'],
            ['Field' => 'times', 'Type' => 'varchar(191)'],
        ]);
        $wpdb->shouldReceive('get_var')->never();
        $wpdb->shouldReceive('query')->never();

        $this->assertTrue(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));
    }

    public function test_migrate_2_4_0_reuses_stamped_backup_when_present(): void {
        $wpdb = $this->mockWpdbForMigrate24();
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $GLOBALS['intersoccer_test_option_store'][intersoccer_rosters_bak_2_4_0_created_option_name()] = time();
        $queries = [];
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($backup) {
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return $backup;
            }
            if (strpos((string) $sql, 'COUNT') !== false) {
                return '3';
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
        $this->assertStringNotContainsString("CREATE TABLE {$backup}", $joined);
        $this->assertStringContainsString('MODIFY event_type', $joined);
        unset($GLOBALS['intersoccer_test_option_store'][intersoccer_rosters_bak_2_4_0_created_option_name()]);
    }

    public function test_migration_lock_blocks_second_acquire_until_released(): void {
        delete_option(DatabaseMigrator::MIGRATION_LOCK_OPTION);
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info')->andReturnNull();
        $migrator = new DatabaseMigrator($logger);
        $this->assertTrue($migrator->acquireMigrationLock());
        $this->assertFalse($migrator->acquireMigrationLock());
        $migrator->releaseMigrationLock();
        $this->assertTrue($migrator->acquireMigrationLock());
        $migrator->releaseMigrationLock();
    }

    public function test_migration_lock_stale_is_taken_over(): void {
        delete_option(DatabaseMigrator::MIGRATION_LOCK_OPTION);
        $logger = Mockery::mock(Logger::class);
        $migrator = new DatabaseMigrator($logger);
        update_option(DatabaseMigrator::MIGRATION_LOCK_OPTION, time() - (DatabaseMigrator::MIGRATION_LOCK_STALE_SECONDS + 5), '', 'no');
        $this->assertTrue($migrator->acquireMigrationLock());
        $migrator->releaseMigrationLock();
    }

    public function test_migrate_2_4_0_aborts_when_backup_insert_fails_and_drops_backup(): void {
        $wpdb = $this->mockWpdbForMigrate24();
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $queries = [];
        $wpdb->last_error = 'insert failed';
        $wpdb->shouldReceive('get_var')->andReturn(null);
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

    public function test_migrate_2_4_0_drops_backup_on_alter_failure_and_allows_retry(): void {
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';

        $run_failed_alter = function () use ($table, $backup) {
            $wpdb = $this->mockWpdbForMigrate24();
            $queries = [];
            $wpdb->last_error = "Duplicate column name 'foo'";
            $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) {
                if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                    return null;
                }
                if (strpos((string) $sql, 'COUNT') !== false) {
                    return '1';
                }
                return null;
            });
            $wpdb->shouldReceive('get_col')->with("DESCRIBE {$table}", 0)->andReturn(['event_type']);
            $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$queries) {
                $queries[] = (string) $sql;
                if (strpos((string) $sql, 'MODIFY event_type') !== false) {
                    return false;
                }
                return 0;
            });
            $this->assertFalse(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));
            return $queries;
        };

        $failed_queries = $run_failed_alter();
        $joined = implode("\n", $failed_queries);
        $this->assertStringContainsString("DROP TABLE IF EXISTS {$backup}", $joined);

        $wpdb = $this->mockWpdbForMigrate24();
        $retry_queries = [];
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) {
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return null;
            }
            if (strpos((string) $sql, 'COUNT') !== false) {
                return '1';
            }
            return null;
        });
        $wpdb->shouldReceive('get_col')->with("DESCRIBE {$table}", 0)->andReturn(['event_type']);
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$retry_queries) {
            $retry_queries[] = (string) $sql;
            return 0;
        });
        $this->assertTrue(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));
        $retry_joined = implode("\n", $retry_queries);
        $this->assertStringContainsString("CREATE TABLE {$backup}", $retry_joined);
        $this->assertStringContainsString('MODIFY event_type', $retry_joined);
    }

    public function test_migrate_2_4_0_skips_alter_when_backup_count_mismatch(): void {
        $wpdb = $this->mockWpdbForMigrate24();
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $queries = [];
        $GLOBALS['intersoccer_test_option_store'][intersoccer_rosters_bak_2_4_0_created_option_name()] = time();
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

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $migrator = new DatabaseMigrator($logger);
        $method = new \ReflectionMethod(DatabaseMigrator::class, 'migrate_2_4_0');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke($migrator));

        $joined = implode("\n", $queries);
        $this->assertStringNotContainsString('MODIFY event_type', $joined);
        unset($GLOBALS['intersoccer_test_option_store'][intersoccer_rosters_bak_2_4_0_created_option_name()]);
    }
}
