<?php

namespace InterSoccer\Reports\Tests\Reports;

use PHPUnit\Framework\TestCase;

/**
 * Signature hashes frozen from master @ 80bf153 (computed via detached worktree, not a hand copy).
 */
class EventSignatureMasterParityTest extends TestCase {
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

    /** @var array<string, array{generate: string, from_event_data: string}> */
    private const MASTER_HASHES_80BF153 = [
        'en_composite' => [
            'generate' => '5164cee7d68c2ce594db095cac7361a4',
            'from_event_data' => 'e4928226262c2294b4c29bdc4f31ad9f',
        ],
        'fr_composite' => [
            'generate' => 'f7a1809ea488fd4ae21383db31857ec8',
            'from_event_data' => 'e4928226262c2294b4c29bdc4f31ad9f',
        ],
        'de_composite' => [
            'generate' => 'b7ee439e7a229f8dcc0058a00a3881d3',
            'from_event_data' => 'd8bf97adb11627877cac5f5a7bd13550',
        ],
        'lower_composite' => [
            'generate' => '1d2b998debf89997f001a86bff44e804',
            'from_event_data' => 'e4928226262c2294b4c29bdc4f31ad9f',
        ],
        'girls_only_alone' => [
            'generate' => 'c88bb205038366f0d7794fbf74819382',
            'from_event_data' => 'c88bb205038366f0d7794fbf74819382',
        ],
        'course_composite' => [
            'generate' => '49aebefad5c037a02e90b817f6bc1647',
            'from_event_data' => '3ac58d9cfa6d4245b6be3e3ea049ea76',
        ],
        'tournament_iso' => [
            'generate' => '5f96904b61b81537c5f600c5587de453',
            'from_event_data' => '5f96904b61b81537c5f600c5587de453',
        ],
        'camp_flag' => [
            'generate' => 'd8bf97adb11627877cac5f5a7bd13550',
            'from_event_data' => 'd8bf97adb11627877cac5f5a7bd13550',
        ],
        'apostrophe_composite' => [
            'generate' => 'c01d87cd32ce33fea3b3b117539500b6',
            'from_event_data' => 'e4928226262c2294b4c29bdc4f31ad9f',
        ],
        'tournament_non_iso' => [
            'generate' => '5f96904b61b81537c5f600c5587de453',
            'from_event_data' => '5f96904b61b81537c5f600c5587de453',
        ],
        'tournament_no_date' => [
            'generate' => '89fe2c567613a4aee403bd60500ef24e',
            'from_event_data' => '89fe2c567613a4aee403bd60500ef24e',
        ],
    ];

    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
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

    public function test_signatures_match_frozen_master_hashes(): void {
        if (!function_exists('intersoccer_generate_event_signature')
            || !function_exists('intersoccer_event_signature_from_event_data')) {
            $this->fail('Signature helpers must be loaded');
        }

        $shapes = [
            'en_composite' => [
                'activity_type' => 'Camp, Girls Only',
                'girls_only' => 0,
            ],
            'fr_composite' => [
                'activity_type' => 'Camp, Filles uniquement',
                'course_day' => '',
            ],
            'de_composite' => [
                'activity_type' => 'Camp, Nur Mädchen',
                'girls_only' => 1,
                'course_day' => '',
            ],
            'lower_composite' => [
                'activity_type' => 'camp girls only',
                'course_day' => '',
            ],
            'girls_only_alone' => [
                'activity_type' => 'Girls Only',
                'girls_only' => 1,
                'camp_terms' => 'N/A',
                'course_day' => 'N/A',
            ],
            'course_composite' => [
                'activity_type' => 'Course, Girls Only',
                'camp_terms' => '',
                'course_day' => 'wednesday',
            ],
            'tournament_iso' => [
                'activity_type' => 'Tournament',
                'camp_terms' => '',
                'course_day' => '',
                'start_date' => '2026-06-14',
            ],
            'camp_flag' => [
                'activity_type' => 'Camp',
                'girls_only' => 1,
                'course_day' => '',
            ],
            'apostrophe_composite' => [
                'activity_type' => "Camp, Girls' only",
                'girls_only' => 0,
                'course_day' => '',
            ],
            'tournament_non_iso' => [
                'activity_type' => 'Tournament',
                'camp_terms' => '',
                'course_day' => '',
                'start_date' => 'Dimanche 14 juin 2026',
            ],
            'tournament_no_date' => [
                'activity_type' => 'Tournament',
                'camp_terms' => '',
                'course_day' => '',
            ],
        ];

        foreach ($shapes as $key => $overrides) {
            $payload = array_merge(self::BASE, $overrides);
            $frozen = self::MASTER_HASHES_80BF153[$key];
            $generate = intersoccer_generate_event_signature($payload);
            $from_event = intersoccer_event_signature_from_event_data($payload);
            $this->assertSame($frozen['generate'], $generate, "generate hash for {$key}");
            $this->assertSame($frozen['from_event_data'], $from_event, "from_event_data hash for {$key}");
        }
    }
}
