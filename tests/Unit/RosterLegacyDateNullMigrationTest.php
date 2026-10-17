<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

class RosterLegacyDateNullMigrationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
    }

    public function test_null_and_1970_dates_produce_identical_event_signature(): void {
        if (!function_exists('intersoccer_event_signature_branch_pipeline')) {
            $this->fail('Branch signature pipeline must be loaded');
        }

        $base = [
            'activity_type' => 'Camp',
            'venue' => 'geneva-venue',
            'age_group' => '5-12',
            'camp_terms' => '',
            'course_day' => '',
            'times' => '0900-1200',
            'season' => 'Summer 2026',
            'city' => 'geneva',
            'canton_region' => 'ge',
            'product_id' => 100,
            'girls_only' => 0,
        ];

        $with_null = intersoccer_event_signature_branch_pipeline(array_merge($base, [
            'start_date' => null,
            'end_date' => null,
        ]));
        $with_legacy = intersoccer_event_signature_branch_pipeline(array_merge($base, [
            'start_date' => '1970-01-01',
            'end_date' => '1970-01-01',
        ]));

        $this->assertSame($with_null, $with_legacy);
    }
}
