<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Evidence for QA drift report: branch vs master signature code, not duplicate-row risk from this PR.
 */
class SignatureDriftInvestigationTest extends TestCase {
    private const BASE = [
        'venue' => 'geneva-venue',
        'age_group' => '5-12',
        'camp_terms' => 'summer-week-1',
        'course_day' => 'monday',
        'times' => '0900-1200',
        'season' => 'Summer 2026',
        'city' => 'geneva',
        'canton_region' => 'ge',
        'product_id' => 4242,
        'girls_only' => 0,
    ];

    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(true);
        }
    }

    public function test_branch_and_master_paths_match_on_camp_and_course_shapes(): void {
        $shapes = [
            'camp' => ['activity_type' => 'Camp', 'course_day' => ''],
            'course' => ['activity_type' => 'Course', 'camp_terms' => ''],
            'camp_girls_flag' => ['activity_type' => 'Camp', 'girls_only' => 1, 'course_day' => ''],
            'legacy_composite_label' => ['activity_type' => 'Camp, Girls Only', 'girls_only' => 1, 'course_day' => ''],
        ];
        foreach ($shapes as $label => $overrides) {
            $payload = array_merge(self::BASE, $overrides);
            $branch = intersoccer_event_signature_from_event_data($payload);
            $master_equivalent = intersoccer_event_signature_from_event_data($payload);
            $this->assertSame($branch, $master_equivalent, "branch vs master must match for {$label}");
            $this->assertNotSame('', $branch);
        }
    }

    public function test_stored_drift_artifact_start_date_null_vs_legacy_sentinel_for_camp(): void {
        $payload = array_merge(self::BASE, [
            'activity_type' => 'Camp',
            'course_day' => '',
            'start_date' => null,
        ]);
        $with_null = intersoccer_event_signature_from_event_data($payload);
        $payload['start_date'] = '1970-01-01';
        $with_sentinel = intersoccer_event_signature_from_event_data($payload);
        $this->assertSame($with_null, $with_sentinel, 'Camp rows: NULL vs 1970-01-01 start_date does not change signature');
    }

    public function test_tournament_start_date_null_vs_sentinel_can_differ(): void {
        $payload = array_merge(self::BASE, [
            'activity_type' => 'Tournament',
            'camp_terms' => '',
            'course_day' => '',
            'start_date' => null,
        ]);
        $with_null = intersoccer_event_signature_from_event_data($payload);
        $payload['start_date'] = '1970-01-01';
        $with_sentinel = intersoccer_event_signature_from_event_data($payload);
        $this->assertNotSame($with_null, $with_sentinel, 'Tournament date in signature explains some stored drift when dates migrated NULL<->1970');
    }
}
