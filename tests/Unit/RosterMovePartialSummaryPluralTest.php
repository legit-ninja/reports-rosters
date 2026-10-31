<?php
/**
 * Partial summary _n() must pluralize on moved count (French agreement).
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterMovePartialSummaryPluralTest extends TestCase {

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

    public function test_partial_summary_pluralization_uses_moved_not_total(): void {
        $GLOBALS['intersoccer_test_recorded_n_numbers'] = [];
        $one = intersoccer_roster_move_format_partial_summary(1, 3, 2);
        $this->assertSame([3], $GLOBALS['intersoccer_test_recorded_n_numbers']);
        $this->assertStringContainsString('1 of 3 players moved. These couldn\'t be moved:', $one);

        $GLOBALS['intersoccer_test_recorded_n_numbers'] = [];
        $two = intersoccer_roster_move_format_partial_summary(2, 3, 1);
        $this->assertSame([3], $GLOBALS['intersoccer_test_recorded_n_numbers']);
        $this->assertStringContainsString('2 of 3 players moved. These couldn\'t be moved:', $two);
    }

    public function test_partial_summary_singular_when_total_is_one(): void {
        $summary = intersoccer_roster_move_format_partial_summary(0, 1, 1);
        $this->assertSame('0 of 1 player moved. These couldn\'t be moved:', $summary);
    }
}
