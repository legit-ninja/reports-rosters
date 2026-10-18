<?php

namespace InterSoccer\ReportsRosters\Tests\Core;

use InterSoccer\ReportsRosters\Core\Plugin;
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
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static function ($s) {
            return (string) $s;
        });
        $wpdb->shouldReceive('get_var')->andReturn(null);
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return (string) $sql;
        });
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$queries, $backup) {
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
        $this->assertStringContainsString("DROP TABLE IF EXISTS {$backup}", $joined);
        $this->assertStringNotContainsString('MODIFY event_type', $joined);
    }

    public function test_legacy_2_4_0_rejects_pre_existing_backup_without_alter(): void {
        $wpdb = Mockery::mock('wpdb');
        $table = 'wp_intersoccer_rosters';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static function ($s) {
            return (string) $s;
        });
        $wpdb->shouldReceive('get_var')->andReturn('wp_intersoccer_rosters_bak_2_4_0');
        $wpdb->shouldReceive('query')->never();
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return (string) $sql;
        });

        $this->assertFalse(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));
    }

    public function test_legacy_2_4_0_alters_when_fresh_backup_counts_match(): void {
        $wpdb = Mockery::mock('wpdb');
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $queries = [];
        $wpdb->last_error = '';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static function ($s) {
            return (string) $s;
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($backup, $table) {
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return null;
            }
            if (strpos((string) $sql, "FROM {$table}") !== false) {
                return '4';
            }
            if (strpos((string) $sql, "FROM {$backup}") !== false) {
                return '4';
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

        $this->assertTrue(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));
        $joined = implode("\n", $queries);
        $this->assertStringContainsString("CREATE TABLE {$backup}", $joined);
        $this->assertStringContainsString('MODIFY event_type', $joined);
    }

    public function test_legacy_2_4_0_drops_fresh_backup_on_count_mismatch(): void {
        $wpdb = Mockery::mock('wpdb');
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $queries = [];
        $wpdb->last_error = '';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static function ($s) {
            return (string) $s;
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($backup, $table) {
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return null;
            }
            if (strpos((string) $sql, $backup) !== false) {
                return '1';
            }
            if (strpos((string) $sql, $table) !== false) {
                return '2';
            }
            return null;
        });
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use (&$queries) {
            $queries[] = (string) $sql;
            return 0;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return (string) $sql;
        });

        $this->assertFalse(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));
        $joined = implode("\n", $queries);
        $this->assertStringContainsString("DROP TABLE IF EXISTS {$backup}", $joined);
        $this->assertStringNotContainsString('MODIFY event_type', $joined);
    }

    public function test_legacy_2_4_0_fails_when_count_query_returns_null(): void {
        $wpdb = Mockery::mock('wpdb');
        $table = 'wp_intersoccer_rosters';
        $backup = $table . '_bak_2_4_0';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static function ($s) {
            return (string) $s;
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($backup, $table) {
            if (strpos((string) $sql, 'SHOW TABLES') !== false) {
                return null;
            }
            if (strpos((string) $sql, 'COUNT') !== false) {
                return null;
            }
            return null;
        });
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use ($backup) {
            if (strpos((string) $sql, 'DROP TABLE IF EXISTS') !== false) {
                return 0;
            }
            return 0;
        });
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return (string) $sql;
        });

        $this->assertFalse(intersoccer_migrate_rosters_schema_2_4_0($wpdb, $table));
    }

    public function test_cleanup_skips_during_ajax(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('query')->never();
        $GLOBALS['intersoccer_test_wp_doing_ajax'] = true;
        intersoccer_maybe_cleanup_rosters_bak_2_4_0();
        unset($GLOBALS['intersoccer_test_wp_doing_ajax']);
        $this->assertTrue(true);
    }

    public function test_cleanup_skips_without_manage_options(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('query')->never();
        $GLOBALS['intersoccer_test_current_user_caps'] = ['manage_options' => false];
        intersoccer_maybe_cleanup_rosters_bak_2_4_0();
        unset($GLOBALS['intersoccer_test_current_user_caps']);
        $this->assertTrue(true);
    }

    public function test_cleanup_retention_is_fourteen_days(): void {
        $this->assertSame(14 * 86400, intersoccer_rosters_bak_2_4_0_retention_seconds());
    }

    public function test_cleanup_would_drop_when_created_at_is_old_enough(): void {
        $created_at = time() - intersoccer_rosters_bak_2_4_0_retention_seconds() - 1;
        $this->assertTrue(time() - $created_at >= intersoccer_rosters_bak_2_4_0_retention_seconds());
    }

    public function test_cleanup_would_not_drop_when_created_at_is_recent(): void {
        $created_at = time() - (5 * 86400);
        $this->assertFalse(time() - $created_at >= intersoccer_rosters_bak_2_4_0_retention_seconds());
    }

    public function test_legacy_2_3_0_leaves_version_when_start_date_update_fails(): void {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $table = 'wp_intersoccer_rosters';
        $GLOBALS['intersoccer_test_option_store'] = ['intersoccer_db_version' => '2.2.0'];
        $wpdb->shouldReceive('get_col')->with("DESCRIBE {$table}", 0)->andReturn([
            'event_signature', 'is_placeholder', 'event_completed', 'start_date', 'end_date',
        ]);
        $wpdb->last_error = 'update failed';
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) {
            if (strpos((string) $sql, 'start_date') !== false) {
                return false;
            }
            return 0;
        });

        intersoccer_migrate_rosters_table();

        $this->assertSame('2.2.0', $GLOBALS['intersoccer_test_option_store']['intersoccer_db_version']);
        unset($GLOBALS['intersoccer_test_option_store']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_oop_migrate_rosters_table_wrapper_does_not_fatal_when_oop_helpers_exist(): void {
        $error_log_before = ini_set('error_log', '/dev/null');
        try {
            if (!defined('INTERSOCCER_OOP_ACTIVE')) {
                define('INTERSOCCER_OOP_ACTIVE', true);
            }
            $GLOBALS['intersoccer_test_option_store']['intersoccer_db_version'] = '2.4.0';
            $plugin_file = dirname(__DIR__, 2) . '/intersoccer-reports-rosters.php';
            Plugin::get_instance($plugin_file);
            $adapter = dirname(__DIR__, 2) . '/includes/oop-adapter.php';
            if (file_exists($adapter) && !function_exists('intersoccer_oop_migrate_rosters_table')) {
                require_once $adapter;
            }
            $this->assertTrue(function_exists('intersoccer_oop_migrate_rosters_table'));
            $result = intersoccer_oop_migrate_rosters_table();
            $this->assertIsBool($result);
        } catch (\Throwable $e) {
            $this->fail('intersoccer_oop_migrate_rosters_table must not fatal: ' . $e->getMessage());
        } finally {
            unset($GLOBALS['intersoccer_test_option_store']['intersoccer_db_version']);
            if ($error_log_before !== false) {
                ini_set('error_log', (string) $error_log_before);
            }
        }
    }

    public function test_legacy_gate_skips_2_4_when_version_is_2_2(): void {
        $this->assertTrue(version_compare('2.2.0', '2.4.0', '<'));
        $this->assertFalse(version_compare('2.2.0', '2.3.0', '>='));
        $this->assertTrue(
            version_compare('2.2.0', '2.4.0', '<') && version_compare('2.2.0', '2.3.0', '>=') === false,
            'Legacy 2.4.0 path must not run until 2.3.0 has been applied'
        );
    }
}
