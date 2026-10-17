<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Legacy stored rows: activity_type "Girls Only" with girls_only=1 must not relabel to Camp on repair.
 */
class GirlsOnlyRepairNormalizeTest extends TestCase {
    private const MASTER_GIRLS_ONLY_ALONE_FROM_EVENT = 'c88bb205038366f0d7794fbf74819382';

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

    public function test_migration_repair_keeps_girls_only_activity_type(): void {
        if (!function_exists('intersoccer_migration_activity_type_with_girls_only_flag')) {
            $this->fail('migration-meta helpers must be loaded');
        }

        $activity = intersoccer_migration_activity_type_with_girls_only_flag('Girls Only', 1);
        $this->assertSame('Girls Only', $activity);
        $this->assertNotSame('Camp', $activity);
    }

    public function test_normalize_and_facet_repair_preserves_girls_only_and_master_signature(): void {
        if (!function_exists('intersoccer_normalize_roster_facets_for_storage')
            || !function_exists('intersoccer_build_roster_facet_db_update')
            || !function_exists('intersoccer_event_signature_from_event_data')) {
            $this->fail('Repair/normalize helpers must be loaded');
        }

        $record = array_merge(self::BASE, [
            'activity_type' => 'Girls Only',
            'girls_only' => 1,
            'camp_terms' => 'N/A',
            'course_day' => 'N/A',
            'start_date' => null,
            'product_name' => 'Geneva Girls Only Summer Camp',
        ]);

        $original_data = [
            'activity_type' => $record['activity_type'],
            'venue' => $record['venue'],
            'age_group' => $record['age_group'],
            'camp_terms' => $record['camp_terms'],
            'course_day' => $record['course_day'],
            'times' => $record['times'],
            'season' => $record['season'],
            'girls_only' => $record['girls_only'],
            'city' => $record['city'],
            'canton_region' => $record['canton_region'],
            'product_id' => $record['product_id'],
            'start_date' => $record['start_date'],
        ];

        $normalized = intersoccer_normalize_roster_facets_for_storage($original_data);
        $this->assertSame('Girls Only', $normalized['activity_type']);

        $update = intersoccer_build_roster_facet_db_update(
            $normalized,
            $record,
            (string) $record['product_name']
        );

        $this->assertSame('Girls Only', $update['activity_type']);
        $this->assertSame(
            self::MASTER_GIRLS_ONLY_ALONE_FROM_EVENT,
            $update['event_signature'],
            'event_signature must match frozen master girls_only_alone hash'
        );

        $direct = intersoccer_event_signature_from_event_data(array_merge(self::BASE, [
            'activity_type' => 'Girls Only',
            'girls_only' => 1,
            'camp_terms' => 'N/A',
            'course_day' => 'N/A',
        ]));
        $this->assertSame(self::MASTER_GIRLS_ONLY_ALONE_FROM_EVENT, $direct);
    }
}
