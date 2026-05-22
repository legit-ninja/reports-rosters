<?php
/**
 * ReportsRostersDiagnosticsService tests.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Services\ReportsRostersDiagnosticsService;
use InterSoccer\ReportsRosters\Tests\TestCase;

class ReportsRostersDiagnosticsServiceTest extends TestCase {
    public function test_normalize_comparable_value_treats_unknown_as_empty(): void {
        $this->assertSame('', ReportsRostersDiagnosticsService::normalizeComparableValue('Unknown'));
        $this->assertSame('', ReportsRostersDiagnosticsService::normalizeComparableValue(' n/a '));
        $this->assertSame('', ReportsRostersDiagnosticsService::normalizeComparableValue(''));
        $this->assertSame('geneva', ReportsRostersDiagnosticsService::normalizeComparableValue(' Geneva '));
    }

    public function test_classify_mismatch_missing_in_rosters(): void {
        $woo = [
            'order_item_id' => 100,
            'venue_value' => 'Geneva',
            'course_day_value' => 'Monday',
        ];
        $reasons = ReportsRostersDiagnosticsService::classifyMismatchReasons($woo, null);
        $this->assertSame(['missing_in_rosters'], $reasons);
    }

    public function test_classify_mismatch_missing_in_woo(): void {
        $roster = [
            'order_item_id' => 200,
            'venue' => 'Zurich',
            'course_day' => 'Tuesday',
        ];
        $reasons = ReportsRostersDiagnosticsService::classifyMismatchReasons(null, $roster);
        $this->assertSame(['missing_in_woo'], $reasons);
    }

    public function test_classify_mismatch_detects_venue_and_day_diff(): void {
        $woo = [
            'venue_value' => 'Geneva',
            'course_day_value' => 'Monday',
        ];
        $roster = [
            'venue' => 'Zurich',
            'course_day' => 'Tuesday',
        ];
        $reasons = ReportsRostersDiagnosticsService::classifyMismatchReasons($woo, $roster);
        $this->assertContains('venue_mismatch', $reasons);
        $this->assertContains('course_day_mismatch', $reasons);
    }

    public function test_is_activity_type_scoped_row_requires_activity_type_context(): void {
        $missing = [
            'activity_type' => '',
            'product_activity_type_attr' => '',
        ];
        $this->assertFalse(ReportsRostersDiagnosticsService::isActivityTypeScopedRow($missing, 'Course'));

        $eligible_course = [
            'activity_type' => 'Course',
            'product_activity_type_attr' => 'course',
        ];
        $this->assertTrue(ReportsRostersDiagnosticsService::isActivityTypeScopedRow($eligible_course, 'Course'));
        $this->assertFalse(ReportsRostersDiagnosticsService::isActivityTypeScopedRow($eligible_course, 'Camp'));
    }

    public function test_is_roster_activity_type_scoped_row_uses_requested_activity(): void {
        $roster_course = ['activity_type' => 'Course'];
        $roster_empty = ['activity_type' => ''];

        $this->assertTrue(ReportsRostersDiagnosticsService::isRosterActivityTypeScopedRow($roster_course, 'Course'));
        $this->assertFalse(ReportsRostersDiagnosticsService::isRosterActivityTypeScopedRow($roster_course, 'Camp'));
        $this->assertFalse(ReportsRostersDiagnosticsService::isRosterActivityTypeScopedRow($roster_empty, 'Course'));
    }

    public function test_run_safe_fix_for_order_item_rejects_invalid_id(): void {
        $service = new ReportsRostersDiagnosticsService();
        $result = $service->runSafeFixForOrderItem(0);

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, $result['order_item_id']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_classify_mismatch_detects_incomplete_player_data(): void {
        $woo = [
            'venue_value' => 'Geneva',
            'course_day_value' => 'Monday',
        ];
        $roster = [
            'venue' => 'Geneva',
            'course_day' => 'Monday',
            'first_name' => 'Unknown',
            'player_name' => 'Unknown Player',
            'event_signature' => 'sig-123',
        ];
        $reasons = ReportsRostersDiagnosticsService::classifyMismatchReasons($woo, $roster);
        $this->assertContains('incomplete_player_data', $reasons);
    }

    public function test_roster_item_sync_health_marks_unknown_as_out_of_sync(): void {
        $health = ReportsRostersDiagnosticsService::rosterItemSyncHealth([
            [
                'first_name' => 'Unknown',
                'player_name' => 'Unknown Player',
                'event_signature' => 'sig-123',
            ],
        ]);
        $this->assertFalse($health['in_sync']);
        $this->assertTrue($health['has_incomplete_player']);
        $this->assertSame(1, $health['roster_row_count']);
    }

    public function test_roster_item_sync_health_marks_complete_row_in_sync(): void {
        $health = ReportsRostersDiagnosticsService::rosterItemSyncHealth([
            [
                'first_name' => 'Alex',
                'last_name' => 'Smith',
                'player_name' => 'Alex Smith',
                'event_signature' => 'sig-123',
            ],
        ]);
        $this->assertTrue($health['in_sync']);
        $this->assertFalse($health['has_incomplete_player']);
    }
}

