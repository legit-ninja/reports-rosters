<?php
/**
 * Roster details / tabs camp row display: days_selected fallback, Days unknown label, Unknown day cells.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/utils.php';

class RosterCampBookingDisplayTest extends TestCase {

    public function test_single_day_without_days_shows_days_unknown_label_and_unknown_cells() {
        $row = (object) [
            'booking_type'  => 'Single Day(s)',
            'selected_days' => '',
            'days_selected' => '',
            'event_details' => json_encode(['days_unknown' => true]),
        ];

        $ctx = intersoccer_roster_camp_booking_display_context($row);

        $this->assertTrue($ctx['days_unknown']);
        $this->assertSame('Days unknown', $ctx['selected_days_display']);
        $this->assertSame('Unknown', $ctx['day_presence']['Monday']);
        $this->assertSame('Unknown', $ctx['day_presence']['Friday']);
    }

    public function test_days_selected_fallback_when_selected_days_empty() {
        $row = (object) [
            'booking_type'  => 'Single Day(s)',
            'selected_days' => '',
            'days_selected' => 'Tuesday, Thursday',
        ];

        $ctx = intersoccer_roster_camp_booking_display_context($row);

        $this->assertFalse($ctx['days_unknown']);
        $this->assertSame('Tuesday, Thursday', $ctx['selected_days']);
        $this->assertSame('Tuesday, Thursday', $ctx['selected_days_display']);
        $this->assertSame('No', $ctx['day_presence']['Monday']);
        $this->assertSame('Yes', $ctx['day_presence']['Tuesday']);
    }

    public function test_german_slug_booking_type_normalizes_for_display() {
        $row = (object) [
            'booking_type'  => 'einzelner-tage',
            'selected_days' => '',
            'days_selected' => '',
        ];

        $ctx = intersoccer_roster_camp_booking_display_context($row);

        $this->assertSame('Single Day(s)', $ctx['booking_type']);
        $this->assertSame('Days unknown', $ctx['selected_days_display']);
    }
}
