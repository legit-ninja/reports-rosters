<?php
/**
 * Player::parseAgeGroup() — ranges without a trailing "y" and localized unit suffixes.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerAgeGroupParserTest extends TestCase {

    private const EVENT_DATE = '2026-09-02';

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public function fiveTwelveFormProvider(): array {
        return [
            'plain range' => ['5-12', 5, 12],
            'with y' => ['5-12y', 5, 12],
            'y spaced' => ['5-12 y', 5, 12],
            'years suffix' => ['5-12 years', 5, 12],
            'fr ans' => ['5-12 ans', 5, 12],
            'de jahre' => ['5-12 Jahre', 5, 12],
            'en dash' => ['5–12', 5, 12],
            'extra whitespace' => ['5 - 12', 5, 12],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public function existingLabelProvider(): array {
        return [
            'existing camp label' => ['5-13y (Full Day)', 5, 13],
            'existing half-day label' => ['3-5y (Half-Day)', 3, 5],
        ];
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    private function boundaryExpectations(int $min, int $max): array {
        return [
            'one below lower bound' => [$min - 1, false],
            'lower bound' => [$min, true],
            'upper bound' => [$max, true],
            'one above upper bound' => [$max + 1, false],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: bool}>
     */
    public function eligibilityMatrixProvider(): array {
        $cases = [];
        $all_forms = array_merge($this->fiveTwelveFormProvider(), $this->existingLabelProvider());
        foreach ($all_forms as $form_label => $form_row) {
            [$form, $min, $max] = $form_row;
            foreach ($this->boundaryExpectations($min, $max) as $age_label => $age_row) {
                $cases["{$form_label} / {$age_label}"] = [$form, $min, $max, $age_row[0], $age_row[1]];
            }
        }
        return $cases;
    }

    /**
     * @param string $age_group
     * @return array{min: int, max: int}|null
     */
    private function parseAgeGroup(string $age_group) {
        $player = new Player(['dob' => '2010-01-01']);
        $method = new \ReflectionMethod(Player::class, 'parseAgeGroup');
        $method->setAccessible(true);
        return $method->invoke($player, $age_group);
    }

    private function dobForAgeAtEvent(int $age): string {
        $event = new \DateTime(self::EVENT_DATE);
        $event->modify('-' . $age . ' years');
        return $event->format('Y-m-d');
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public function allParseFormProvider(): array {
        return array_merge($this->fiveTwelveFormProvider(), $this->existingLabelProvider());
    }

    /**
     * @dataProvider allParseFormProvider
     */
    public function test_parses_expected_min_max(string $age_group, int $min, int $max): void {
        $this->assertSame(
            ['min' => $min, 'max' => $max],
            $this->parseAgeGroup($age_group)
        );
    }

    /**
     * @dataProvider eligibilityMatrixProvider
     */
    public function test_eligibility_at_boundaries(
        string $age_group,
        int $min,
        int $max,
        int $age,
        bool $expected_eligible
    ): void {
        $player = new Player(['dob' => $this->dobForAgeAtEvent($age)]);
        $this->assertSame(
            $expected_eligible,
            $player->isEligibleForAgeGroup($age_group, self::EVENT_DATE)
        );
    }

    public function test_under_age_group_still_parses(): void {
        $this->assertSame(['min' => 0, 'max' => 14], $this->parseAgeGroup('U14'));
    }

    public function test_unparseable_age_group_returns_null_and_does_not_treat_everyone_as_eligible(): void {
        $this->assertNull($this->parseAgeGroup('not-a-valid-age-group'));
        $player = new Player(['dob' => $this->dobForAgeAtEvent(9)]);
        $this->assertFalse($player->isEligibleForAgeGroup('not-a-valid-age-group', self::EVENT_DATE));
    }

    /**
     * School/grade labels are not mapped to numeric ages anywhere in RR today (validator, matcher, or Player).
     *
     * @return array<string, array{0: string}>
     */
    public function gradeStyleAgeGroupProvider(): array {
        return [
            'G1-G5' => ['G1-G5'],
            'Pre-K to G5' => ['Pre-K to G5'],
            'KG-GR1' => ['KG-GR1'],
            'GR2-5' => ['GR2-5'],
        ];
    }

    /**
     * @dataProvider gradeStyleAgeGroupProvider
     */
    public function test_grade_style_age_groups_remain_unparsed(string $age_group): void {
        $this->assertNull($this->parseAgeGroup($age_group));
        $player = new Player(['dob' => $this->dobForAgeAtEvent(9)]);
        $this->assertFalse($player->isEligibleForAgeGroup($age_group, self::EVENT_DATE));
    }
}
