<?php
/**
 * Single-day camp rows without a recorded day selection are shown as "Days unknown".
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/utils.php';

class RosterDaysUnknownTest extends TestCase {

    public function test_single_day_without_days_is_unknown() {
        $this->assertTrue(intersoccer_roster_days_unknown('Single Day(s)', ''));
        $this->assertTrue(intersoccer_roster_days_unknown('single-days', null));
        $this->assertTrue(intersoccer_roster_days_unknown('Single Day(s)', []));
        $this->assertTrue(intersoccer_roster_days_unknown('Single Day(s)', ' , '));
    }

    public function test_german_single_day_label_is_recognised() {
        $this->assertSame('single-days', intersoccer_normalize_booking_type_slug_for_reports('Einzelner Tage'));
        $this->assertTrue(intersoccer_roster_days_unknown('Einzelner Tage', ''));
        $this->assertTrue(intersoccer_roster_days_unknown('einzelner-tage', ''));
    }

    public function test_single_day_with_days_is_known() {
        $this->assertFalse(intersoccer_roster_days_unknown('Single Day(s)', 'Monday, Wednesday'));
        $this->assertSame('Monday, Wednesday', intersoccer_roster_selected_days_display('Single Day(s)', 'Monday, Wednesday'));
    }

    public function test_full_week_is_never_days_unknown() {
        $this->assertFalse(intersoccer_roster_days_unknown('Full Week', ''));
        $this->assertSame('', intersoccer_roster_selected_days_display('Full Week', ''));
    }

    public function test_display_label_for_unknown_days() {
        $this->assertSame('Days unknown', intersoccer_roster_selected_days_display('Single Day(s)', ''));
    }

    public function test_day_presence_stays_all_no_when_days_unknown() {
        $presence = intersoccer_compute_day_presence('Single Day(s)', '');
        $this->assertSame(['No'], array_values(array_unique($presence)));
    }
}
