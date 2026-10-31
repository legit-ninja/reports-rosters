<?php

namespace InterSoccer\Reports\Tests\Reports;

use PHPUnit\Framework\TestCase;

/**
 * Master writer dates (1970-01-01) vs branch NULL storage must yield identical signatures.
 * Frozen MD5s from master_pipeline @ 80bf153 semantics with branch date fix applied.
 */
class EventSignaturePipelineParityTest extends TestCase {
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

    /** @var array<string, string> master_pipeline MD5 per shape key */
    private const MASTER_PIPELINE_HASHES = [
        'camp_real_dates' => 'e4928226262c2294b4c29bdc4f31ad9f',
        'camp_null_dates' => 'e4928226262c2294b4c29bdc4f31ad9f',
        'course_real_dates' => 'e78abb3fc195bdf58c88dd79f580f733',
        'course_null_dates' => 'e78abb3fc195bdf58c88dd79f580f733',
        'tournament_real_date' => '5f96904b61b81537c5f600c5587de453',
        'tournament_null_dates' => '47e9ae70a021dc360c5b639d89ce85ed',
        'de_camp_girls' => 'd8bf97adb11627877cac5f5a7bd13550',
        'fr_course_girls' => '0d9687c0d49ad74dfa193d72facad2b8',
        'en_camp_composite' => 'e4928226262c2294b4c29bdc4f31ad9f',
    ];

    private const SHAPES = [
        'camp_real_dates' => [
            'activity_type' => 'Camp',
            'course_day' => '',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-05',
        ],
        'camp_null_dates' => [
            'activity_type' => 'Camp',
            'course_day' => '',
            'start_date' => null,
            'end_date' => null,
        ],
        'course_real_dates' => [
            'activity_type' => 'Course',
            'camp_terms' => '',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-01',
        ],
        'course_null_dates' => [
            'activity_type' => 'Course',
            'camp_terms' => '',
            'start_date' => null,
            'end_date' => null,
        ],
        'tournament_real_date' => [
            'activity_type' => 'Tournament',
            'camp_terms' => '',
            'course_day' => '',
            'start_date' => '2026-06-14',
            'end_date' => '2026-06-14',
        ],
        'tournament_null_dates' => [
            'activity_type' => 'Tournament',
            'camp_terms' => '',
            'course_day' => '',
            'start_date' => null,
            'end_date' => null,
        ],
        'de_camp_girls' => [
            'activity_type' => 'Camp, Nur Mädchen',
            'girls_only' => 1,
            'course_day' => '',
            'start_date' => null,
            'end_date' => null,
        ],
        'fr_course_girls' => [
            'activity_type' => 'Course, Filles uniquement',
            'girls_only' => 1,
            'camp_terms' => '',
            'start_date' => null,
            'end_date' => null,
        ],
        'en_camp_composite' => [
            'activity_type' => 'Camp, Girls Only',
            'girls_only' => 0,
            'course_day' => '',
            'start_date' => null,
            'end_date' => null,
        ],
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

    protected function tearDown(): void {
        if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
            intersoccer_set_bulk_normalize_quiet(false);
        }
        parent::tearDown();
    }

    public function test_master_and_branch_pipelines_match_and_match_frozen_hashes(): void {
        if (!function_exists('intersoccer_event_signature_master_pipeline')
            || !function_exists('intersoccer_event_signature_branch_pipeline')) {
            $this->fail('Pipeline signature helpers must be loaded');
        }

        foreach (self::SHAPES as $key => $overrides) {
            $payload = array_merge(self::BASE, $overrides);
            $master = intersoccer_event_signature_master_pipeline($payload);
            $branch = intersoccer_event_signature_branch_pipeline($payload);
            $this->assertSame($master, $branch, "master vs branch pipeline for {$key}");
            $frozen = self::MASTER_PIPELINE_HASHES[$key];
            $this->assertSame($frozen, $master, "frozen master_pipeline hash for {$key}");
        }
    }

    public function test_signature_date_coercion_required_for_tournament_null_storage(): void {
        $payload = array_merge(self::BASE, self::SHAPES['tournament_null_dates']);
        $branch = intersoccer_event_signature_branch_pipeline($payload);
        $this->assertSame(self::MASTER_PIPELINE_HASHES['tournament_null_dates'], $branch);

        if (!function_exists('intersoccer_event_signature_from_event_data')) {
            $this->fail('from_event_data required');
        }
        $raw = $payload;
        $normalized = intersoccer_normalize_event_data_for_signature($raw);
        if (array_key_exists('start_date', $raw)) {
            $normalized['start_date'] = $raw['start_date'];
        }
        $without_coercion = intersoccer_generate_event_signature($normalized);
        $this->assertNotSame($branch, $without_coercion, 'reverting signature date coercion must change tournament NULL hash');
    }
}
