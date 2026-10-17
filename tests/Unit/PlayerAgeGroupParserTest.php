<?php
/**
 * Player::parseAgeGroup() — ranges without a trailing "y" and localized unit suffixes.
 */

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Utils\ValidationHelper;

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
     * @return array<string, array{0: string}>
     */
    public function gradeAgeGroupMustMatchProvider(): array {
        return [
            'G1-G5' => ['G1-G5'],
            'Pre-K to G5' => ['Pre-K to G5'],
            'KG-GR1' => ['KG-GR1'],
            'GR2-5' => ['GR2-5'],
            'Grade 3' => ['Grade 3'],
            'K-G2' => ['K-G2'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function gradeAgeGroupMustNotMatchProvider(): array {
        return [
            'numeric range' => ['5-12'],
            'U14' => ['U14'],
            'garbage' => ['not-a-valid-age-group'],
            'G alone' => ['G'],
            'GR alone' => ['GR'],
            'Group 5' => ['Group 5'],
            'empty' => [''],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public function gradeEligibleAtAgesProvider(): array {
        $cases = [];
        foreach ($this->gradeAgeGroupMustMatchProvider() as $label => $row) {
            foreach ([3, 9, 16] as $age) {
                $cases["{$label} age {$age}"] = [$row[0], $age];
            }
        }
        return $cases;
    }

    private function resetGradeSkipLog(): void {
        $ref = new \ReflectionClass(ValidationHelper::class);
        $prop = $ref->getProperty('logged_grade_age_check_skipped');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * @dataProvider gradeAgeGroupMustMatchProvider
     */
    public function test_grade_age_groups_do_not_parse_to_numeric_range(string $age_group): void {
        $this->assertNull($this->parseAgeGroup($age_group));
        $this->assertTrue(ValidationHelper::isGradeAgeGroup($age_group));
    }

    /**
     * @dataProvider gradeEligibleAtAgesProvider
     */
    public function test_grade_age_groups_skip_age_check_and_are_eligible(string $age_group, int $age): void {
        $player = new Player(['dob' => $this->dobForAgeAtEvent($age)]);
        $this->assertTrue($player->isEligibleForAgeGroup($age_group, self::EVENT_DATE));
    }

    /**
     * @dataProvider gradeAgeGroupMustNotMatchProvider
     */
    public function test_non_grade_labels_are_not_grade_age_groups(string $age_group): void {
        $this->assertFalse(ValidationHelper::isGradeAgeGroup($age_group));
    }

    public function test_non_grade_unparseable_stays_ineligible(): void {
        $player = new Player(['dob' => $this->dobForAgeAtEvent(9)]);
        $this->assertFalse($player->isEligibleForAgeGroup('not-a-valid-age-group', self::EVENT_DATE));
        $this->assertFalse($player->isEligibleForAgeGroup('G', self::EVENT_DATE));
    }

    public function test_numeric_range_still_follows_bounds_not_grade_skip(): void {
        $young = new Player(['dob' => $this->dobForAgeAtEvent(4)]);
        $in_range = new Player(['dob' => $this->dobForAgeAtEvent(9)]);
        $this->assertFalse($young->isEligibleForAgeGroup('5-12', self::EVENT_DATE));
        $this->assertTrue($in_range->isEligibleForAgeGroup('5-12', self::EVENT_DATE));
    }

    /**
     * @dataProvider gradeAgeGroupMustMatchProvider
     */
    public function test_grade_skip_logs_once_per_term(string $age_group): void {
        $this->resetGradeSkipLog();
        $player = new Player(['dob' => $this->dobForAgeAtEvent(9)]);
        $player->isEligibleForAgeGroup($age_group, self::EVENT_DATE);
        $player->isEligibleForAgeGroup($age_group, self::EVENT_DATE);
        $ref = new \ReflectionClass(ValidationHelper::class);
        $prop = $ref->getProperty('logged_grade_age_check_skipped');
        $prop->setAccessible(true);
        $logged = $prop->getValue();
        $this->assertArrayHasKey($age_group, $logged);
        $this->assertCount(1, array_keys($logged));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public function masterSubstringYLabelProvider(): array {
        return [
            'prefix Ages' => ['Ages 5-12y', 5, 12],
            'suffix Full Day' => ['5-12y Full Day', 5, 12],
            'suffix Half Day' => ['5-12y - Half Day', 5, 12],
        ];
    }

    /**
     * @dataProvider masterSubstringYLabelProvider
     */
    public function test_master_unanchored_y_labels_still_parse(string $age_group, int $min, int $max): void {
        $this->assertSame(['min' => $min, 'max' => $max], $this->parseAgeGroup($age_group));
    }

    public function test_parse_age_group_min_max_loads_via_committed_autoloader(): void {
        require dirname(__DIR__, 2) . '/vendor/autoload.php';
        $this->assertTrue(method_exists(ValidationHelper::class, 'parseAgeGroupMinMax'));
        $this->assertSame(
            ['min' => 5, 'max' => 12],
            ValidationHelper::parseAgeGroupMinMax('5-12', [])
        );
    }

    public function test_predefined_age_group_catalog_matches_master_parser(): void {
        $catalog = ValidationHelper::flattenPredefinedAgeGroupCatalog(Player::AGE_GROUPS);
        foreach ($catalog as $label => $expected) {
            $this->assertSame(
                $expected,
                ValidationHelper::parseAgeGroupMinMaxAsOnRelease21015($label, $catalog),
                "master parser for predefined label {$label}"
            );
            $this->assertSame(
                $expected,
                $this->parseAgeGroup($label),
                "current parser for predefined label {$label}"
            );
        }
    }

    public function test_new_parser_is_superset_of_master_for_varied_strings(): void {
        $catalog = ValidationHelper::flattenPredefinedAgeGroupCatalog(Player::AGE_GROUPS);
        $samples = [
            '5-12',
            '5-12y',
            '5 - 12',
            '5-12 ans',
            '5-12 Jahre',
            '5–12',
            'Ages 5-12y',
            '5-12y Full Day',
            '5-12y - Half Day',
            '5-13y (Full Day)',
            '3-5y (Half-Day)',
            'U14',
            'Under 14',
            '13+',
            'not-a-valid-age-group',
            'G1-G5',
            'Pre-K to G5',
            'GR2-5',
            '9-11y',
            'course label 7-9y extra',
            'bogus 3-4y text',
            '3-5y',
            '8-12y',
            '5-13y',
            '4-5y',
        ];
        foreach (array_keys($catalog) as $label) {
            $samples[] = $label;
        }
        $samples = array_values(array_unique($samples));
        $this->assertGreaterThanOrEqual(20, count($samples));

        foreach ($samples as $label) {
            $master = ValidationHelper::parseAgeGroupMinMaxAsOnRelease21015($label, $catalog);
            $current = ValidationHelper::parseAgeGroupMinMax($label, $catalog);
            if ($master !== null) {
                $this->assertSame($master, $current, "superset check failed for label: {$label}");
            }
        }
    }
}
