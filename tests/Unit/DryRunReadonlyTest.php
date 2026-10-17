<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

class DryRunReadonlyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__, 2) . '/includes/dryrun-readonly.php';
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        intersoccer_reports_set_readonly_mode(false);
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_emit_wp_hooks']);
        intersoccer_reports_set_readonly_mode(false);
        parent::tearDown();
    }

    public function test_dryrun_bootstrap_enables_external_object_cache(): void {
        intersoccer_reports_dryrun_bootstrap_readonly();
        $this->assertTrue(wp_using_ext_object_cache());
        $this->assertTrue(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
    }

    public function test_query_guard_blocks_delete_and_counts(): void {
        intersoccer_reports_dryrun_bootstrap_readonly();
        $GLOBALS['wpdb'] = new class {
            public $last_query = '';
        };
        add_filter('query', static function ($query) {
            global $wpdb;
            $wpdb->last_query = $query;
            return $query;
        }, 1);

        $blocked = apply_filters('query', "DELETE FROM wp_options WHERE option_name LIKE '_transient_timeout_%'");
        $this->assertSame('SELECT 1', $blocked);
        $this->assertGreaterThan(0, intersoccer_reports_dryrun_count_blocked_sql_writes());
    }

    public function test_dryrun_evaluate_ok_when_only_memory_transient_writes(): void {
        intersoccer_reports_dryrun_bootstrap_readonly();
        $GLOBALS['intersoccer_dryrun_signature_branch_vs_master_drift'] = 0;
        if (function_exists('intersoccer_reports_note_readonly_memory_write_attempt')) {
            for ($i = 0; $i < 9; $i++) {
                intersoccer_reports_note_readonly_memory_write_attempt();
            }
        }
        $eval = intersoccer_reports_dryrun_evaluate_result();
        $this->assertTrue($eval['valid']);
        $this->assertSame(9, $eval['memory_write_attempts']);
        $this->assertSame(0, $eval['blocked_sql_write_attempts']);
    }

    public function test_dryrun_evaluate_result_invalid_when_sql_errors_present(): void {
        intersoccer_reports_dryrun_bootstrap_readonly();
        $GLOBALS['intersoccer_dryrun_sql_errors'] = [['context' => 't', 'message' => 'err']];
        $eval = intersoccer_reports_dryrun_evaluate_result();
        $this->assertFalse($eval['valid']);
        $this->assertContains('sql_errors=1', $eval['reasons']);
    }

    public function test_dryrun_evaluate_result_ok_when_clean(): void {
        intersoccer_reports_dryrun_bootstrap_readonly();
        $GLOBALS['intersoccer_dryrun_signature_branch_vs_master_drift'] = 0;
        $eval = intersoccer_reports_dryrun_evaluate_result();
        $this->assertTrue($eval['valid']);
    }
}
