<?php
/**
 * Full and over-capacity reason lines use _n() on capacity / over count.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterMoveFullOverCapacityPluralTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/roster-move-reason-messages.php';
        $GLOBALS['intersoccer_test_record_n_calls'] = true;
        $GLOBALS['intersoccer_test_recorded_n_numbers'] = [];
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_record_n_calls'], $GLOBALS['intersoccer_test_recorded_n_numbers']);
        parent::tearDown();
    }

    public function test_full_line_pluralizes_on_capacity_singular_and_plural(): void {
        $GLOBALS['intersoccer_test_recorded_n_numbers'] = [];
        $singular = intersoccer_roster_move_format_full_line(1, 1);
        $this->assertSame([1], $GLOBALS['intersoccer_test_recorded_n_numbers']);
        $this->assertSame('This roster is full: 1 of 1 place taken.', $singular);

        $GLOBALS['intersoccer_test_recorded_n_numbers'] = [];
        $plural = intersoccer_roster_move_format_full_line(24, 24);
        $this->assertSame([24], $GLOBALS['intersoccer_test_recorded_n_numbers']);
        $this->assertSame('This roster is full: 24 of 24 places taken.', $plural);
    }

    public function test_over_capacity_line_pluralizes_on_over_count(): void {
        $GLOBALS['intersoccer_test_recorded_n_numbers'] = [];
        $one = intersoccer_roster_move_format_over_capacity_line(1);
        $this->assertSame([1], $GLOBALS['intersoccer_test_recorded_n_numbers']);
        $this->assertSame('Moving will put it over capacity by 1 place.', $one);

        $GLOBALS['intersoccer_test_recorded_n_numbers'] = [];
        $two = intersoccer_roster_move_format_over_capacity_line(2);
        $this->assertSame([2], $GLOBALS['intersoccer_test_recorded_n_numbers']);
        $this->assertSame('Moving will put it over capacity by 2 places.', $two);
    }
}
