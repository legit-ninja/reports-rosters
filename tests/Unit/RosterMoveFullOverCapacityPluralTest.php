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

    public function test_de_ch_mo_full_and_over_capacity_plural_forms(): void {
        require_once __DIR__ . '/RosterMoveTranslationsMoTest.php';
        $catalog = RosterMoveMoCatalog::fromFile(
            dirname(__DIR__, 2) . '/languages/intersoccer-reports-rosters-de_CH.mo'
        );

        $full_singular = $catalog->pluralForm(
            'This roster is full: %1$d of %2$d place taken.',
            'This roster is full: %1$d of %2$d places taken.',
            1
        );
        $this->assertSame('Diese Gruppe ist voll: %1$d von %2$d Platz belegt.', $full_singular);

        $full_plural = $catalog->pluralForm(
            'This roster is full: %1$d of %2$d place taken.',
            'This roster is full: %1$d of %2$d places taken.',
            24
        );
        $this->assertSame('Diese Gruppe ist voll: %1$d von %2$d Plätzen belegt.', $full_plural);

        $over_singular = $catalog->pluralForm(
            'Moving will put it over capacity by %d place.',
            'Moving will put it over capacity by %d places.',
            1
        );
        $this->assertSame('Danach ist die Gruppe um %d Platz überbucht.', $over_singular);

        $over_plural = $catalog->pluralForm(
            'Moving will put it over capacity by %d place.',
            'Moving will put it over capacity by %d places.',
            2
        );
        $this->assertSame('Danach ist die Gruppe um %d Plätze überbucht.', $over_plural);
    }

    public function test_fr_ch_mo_full_and_over_capacity_plural_forms(): void {
        require_once __DIR__ . '/RosterMoveTranslationsMoTest.php';
        $catalog = RosterMoveMoCatalog::fromFile(
            dirname(__DIR__, 2) . '/languages/intersoccer-reports-rosters-fr_CH.mo'
        );
        $nbsp = "\u{00A0}";

        $full_singular = $catalog->pluralForm(
            'This roster is full: %1$d of %2$d place taken.',
            'This roster is full: %1$d of %2$d places taken.',
            1
        );
        $this->assertSame('Ce groupe est complet' . $nbsp . ': %1$d place prise sur %2$d.', $full_singular);

        $full_plural = $catalog->pluralForm(
            'This roster is full: %1$d of %2$d place taken.',
            'This roster is full: %1$d of %2$d places taken.',
            24
        );
        $this->assertSame('Ce groupe est complet' . $nbsp . ': %1$d places prises sur %2$d.', $full_plural);

        $over_singular = $catalog->pluralForm(
            'Moving will put it over capacity by %d place.',
            'Moving will put it over capacity by %d places.',
            1
        );
        $this->assertSame('Le groupe dépassera alors sa capacité de %d place.', $over_singular);

        $over_plural = $catalog->pluralForm(
            'Moving will put it over capacity by %d place.',
            'Moving will put it over capacity by %d places.',
            2
        );
        $this->assertSame('Le groupe dépassera alors sa capacité de %d places.', $over_plural);
    }
}
