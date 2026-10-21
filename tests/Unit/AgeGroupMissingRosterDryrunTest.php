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

    public function test_main_query_sql_scopes_age_term_to_pa_age_group_taxonomy(): void {
        $sql = intersoccer_agegroup_dryrun_build_main_query_sql(
            "INNER JOIN wp_posts o ON o.ID = oi.order_id",
            'wp_woocommerce_order_items',
            'wp_woocommerce_order_itemmeta',
            'wp_postmeta',
            'wp_terms',
            'wp_term_taxonomy',
            'wp_intersoccer_rosters'
        );

        $this->assertStringContainsString('COALESCE(age_term.name, attr.meta_value)', $sql);
        $this->assertMatchesRegularExpression(
            '/FROM\s+wp_terms\s+t\s+INNER JOIN\s+wp_term_taxonomy\s+tt\s+ON\s+tt\.term_id\s*=\s*t\.term_id\s+AND\s+tt\.taxonomy\s*=\s*\'pa_age-group\'/i',
            $sql
        );
        $this->assertStringContainsString(') age_term ON age_term.slug = attr.meta_value', $sql);
        $this->assertStringNotContainsString('LEFT JOIN wp_terms t', $sql);
    }

    public function test_dedupe_line_items_keeps_first_row_per_order_item_id(): void {
        $rows = [
            [
                'order_item_id' => 900001,
                'age_group_label' => '3-12 Jahre',
                'activity_type' => 'Camp',
            ],
            [
                'order_item_id' => 900001,
                'age_group_label' => 'duplicate-inflated-label',
                'activity_type' => 'Camp',
            ],
            [
                'order_item_id' => 900002,
                'age_group_label' => '5-12 Jahre',
                'activity_type' => 'Course',
            ],
        ];

        $deduped = intersoccer_agegroup_dryrun_dedupe_line_items_first_wins($rows);
        $this->assertCount(2, $deduped);
        $this->assertSame(900001, (int) $deduped[0]['order_item_id']);
        $this->assertSame('3-12 Jahre', $deduped[0]['age_group_label']);

        $this->assertNotContains('duplicate-inflated-label', array_column($deduped, 'age_group_label'));
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
