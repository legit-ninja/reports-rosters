<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Signature drift: branch vs master full pipelines must agree; stored drift is a separate artifact.
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

    public function test_branch_and_master_pipelines_match_on_camp_and_course_shapes(): void {
        if (!function_exists('intersoccer_event_signature_master_pipeline')
            || !function_exists('intersoccer_event_signature_branch_pipeline')) {
            $this->fail('Pipeline signature helpers must be loaded');
        }
        $shapes = [
            'camp' => ['activity_type' => 'Camp', 'course_day' => '', 'start_date' => null, 'end_date' => null],
            'course' => ['activity_type' => 'Course', 'camp_terms' => '', 'start_date' => null, 'end_date' => null],
            'camp_girls_flag' => ['activity_type' => 'Camp', 'girls_only' => 1, 'course_day' => '', 'start_date' => null, 'end_date' => null],
            'legacy_composite_label' => ['activity_type' => 'Camp, Girls Only', 'girls_only' => 1, 'course_day' => '', 'start_date' => null, 'end_date' => null],
        ];
        foreach ($shapes as $label => $overrides) {
            $payload = array_merge(self::BASE, $overrides);
            $branch = intersoccer_event_signature_branch_pipeline($payload);
            $master = intersoccer_event_signature_master_pipeline($payload);
            $this->assertSame($master, $branch, "branch vs master pipeline must match for {$label}");
            $this->assertNotSame('', $branch);
        }
    }

    public function test_start_date_null_vs_legacy_sentinel_matches_for_camp_and_tournament(): void {
        foreach (['Camp' => ['course_day' => ''], 'Tournament' => ['camp_terms' => '', 'course_day' => '']] as $activity => $extra) {
            $payload = array_merge(self::BASE, $extra, [
                'activity_type' => $activity,
                'start_date' => null,
                'end_date' => null,
            ]);
            $with_null = intersoccer_event_signature_branch_pipeline($payload);
            $payload['start_date'] = '1970-01-01';
            $payload['end_date'] = '1970-01-01';
            $with_sentinel = intersoccer_event_signature_branch_pipeline($payload);
            $this->assertSame($with_null, $with_sentinel, "{$activity}: NULL vs 1970-01-01 must not change signature");
            $this->assertSame(
                intersoccer_event_signature_master_pipeline(array_merge(self::BASE, $extra, [
                    'activity_type' => $activity,
                    'start_date' => null,
                    'end_date' => null,
                ])),
                $with_null,
                "{$activity}: master pipeline must match branch for NULL storage dates"
            );
        }
    }
}
