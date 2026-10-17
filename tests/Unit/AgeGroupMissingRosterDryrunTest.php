<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Utils\ValidationHelper;

class AgeGroupMissingRosterDryrunTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!defined('INTERSOCCER_AGEGROUP_DRYRUN_TEST_LOAD')) {
            define('INTERSOCCER_AGEGROUP_DRYRUN_TEST_LOAD', true);
        }
        require_once dirname(__DIR__, 2) . '/scripts/age-group-missing-roster-dryrun.php';
        intersoccer_agegroup_dryrun_reset_gate_state();
    }

    public function test_evaluate_result_ok_when_no_errors_or_blocks(): void {
        $result = intersoccer_agegroup_dryrun_evaluate_result([
            'sql_error' => '',
            'blocked' => [],
        ]);
        $this->assertSame('OK', $result['status']);
        $this->assertSame('', $result['reason']);
    }

    public function test_evaluate_result_invalid_on_sql_error(): void {
        $result = intersoccer_agegroup_dryrun_evaluate_result([
            'sql_error' => "Unknown column 'tt.slug' in 'on clause'",
            'blocked' => [],
        ]);
        $this->assertSame('INVALID', $result['status']);
        $this->assertStringContainsString('SQL error', $result['reason']);
    }

    public function test_evaluate_result_invalid_on_blocked_write(): void {
        $result = intersoccer_agegroup_dryrun_evaluate_result([
            'sql_error' => '',
            'blocked' => [
                ['type' => 'UPDATE', 'table' => 'wp_options', 'query' => 'UPDATE wp_options SET'],
            ],
        ]);
        $this->assertSame('INVALID', $result['status']);
        $this->assertStringContainsString('blocked non-read-only', $result['reason']);
    }

    public function test_query_filter_blocks_update_and_allows_select(): void {
        intersoccer_agegroup_dryrun_reset_gate_state();
        intersoccer_agegroup_dryrun_register_readonly_guards();
        $filtered = intersoccer_agegroup_dryrun_filter_query('UPDATE wp_options SET option_value = 1');
        $this->assertSame('SELECT 1', $filtered);
        $state = intersoccer_agegroup_dryrun_gate_state();
        $this->assertCount(1, $state['blocked']);
        $this->assertSame('UPDATE', $state['blocked'][0]['type']);

        $select = intersoccer_agegroup_dryrun_filter_query('SELECT id FROM wp_posts');
        $this->assertSame('SELECT id FROM wp_posts', $select);
    }

    public function test_sql_is_read_only_classifier(): void {
        $this->assertTrue(intersoccer_agegroup_dryrun_sql_is_read_only('SELECT 1'));
        $this->assertTrue(intersoccer_agegroup_dryrun_sql_is_read_only('SHOW TABLES LIKE \'wp_posts\''));
        $this->assertFalse(intersoccer_agegroup_dryrun_sql_is_read_only('INSERT INTO wp_options VALUES (1,2)'));
    }

    private function resetValidationLogs(): void {
        foreach (['logged_unparseable_age_groups', 'logged_grade_age_check_skipped'] as $prop_name) {
            $ref = new \ReflectionClass(ValidationHelper::class);
            $prop = $ref->getProperty($prop_name);
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }
    }

    public function test_grade_term_does_not_log_unparseable_before_skip(): void {
        $this->resetValidationLogs();
        ValidationHelper::parseAgeGroupMinMax('pre-k-to-g5', []);
        $ref = new \ReflectionClass(ValidationHelper::class);
        $unparseable = $ref->getProperty('logged_unparseable_age_groups');
        $unparseable->setAccessible(true);
        $this->assertSame([], $unparseable->getValue());

        $player = new Player(['dob' => '2017-05-01']);
        $this->assertTrue($player->isEligibleForAgeGroup('pre-k-to-g5', '2026-09-02'));
        $skipped = $ref->getProperty('logged_grade_age_check_skipped');
        $skipped->setAccessible(true);
        $logged = $skipped->getValue();
        $this->assertArrayHasKey('pre-k-to-g5', $logged);
        $this->assertSame([], $unparseable->getValue());
    }
}
