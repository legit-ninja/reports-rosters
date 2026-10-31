<?php

namespace InterSoccer\ReportsRosters\Tests\Data\Models;

use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerAgeGroupParserTest extends TestCase {

    /**
     * @dataProvider ageGroupRangeProvider
     */
    public function test_parse_age_group_range(?array $expected, string $label): void {
        $parsed = Player::parseAgeGroupRange($label);
        if ($expected === null) {
            $this->assertNull($parsed, 'Label: ' . $label);
            return;
        }
        $this->assertNotNull($parsed, 'Label: ' . $label);
        $this->assertSame($expected['min'], $parsed['min'], 'Label: ' . $label);
        $this->assertSame($expected['max'], $parsed['max'], 'Label: ' . $label);
    }

    public function ageGroupRangeProvider(): array {
        $cases = require __DIR__ . '/AgeGroupTerms160Cases.php';
        $rows = [];
        foreach ($cases as $label => $expected) {
            if ($label === '(empty)') {
                $label = '';
            }
            $key = $label === '' ? 'empty' : substr(preg_replace('/[^a-z0-9]+/i', '_', $label), 0, 60);
            $rows[$key] = [$expected, $label];
        }
        return $rows;
    }

    /**
     * @dataProvider ageGroupRangeEdgeProvider
     */
    public function test_parse_age_group_range_edges(?array $expected, string $label): void {
        $this->test_parse_age_group_range($expected, $label);
    }

    public function ageGroupRangeEdgeProvider(): array {
        return [
            'en half day duplicate key' => [['min' => 3, 'max' => 5], '3-5y (Half-Day)'],
            'mid word u digit' => [null, 'venue-u12-extra'],
            'combined comma label' => [null, '3-5y (Half-Day), 5-13y (Full Day)'],
            'combined slug' => [null, '3-5y-half-day-5-13y-full-day'],
            'invalid n greater m' => [null, '13-5y'],
            'two ranges' => [null, '3-5 and 5-13'],
            'en dash spaces' => [['min' => 4, 'max' => 11], '4 – 11y'],
            'anchored u12' => [['min' => 0, 'max' => 12], 'U12'],
            'anchored under 14' => [['min' => 0, 'max' => 14], 'Under 14'],
        ];
    }

    public function test_unparseable_is_eligible_for_age_group(): void {
        $player = new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'A',
            'last_name' => 'B',
            'dob' => '2020-01-01',
            'gender' => 'male',
        ]);
        $this->assertTrue($player->isEligibleForAgeGroup('Pre-K to G5', '2026-07-01'));
    }

    public function test_age_group_status_at(): void {
        $player = new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'A',
            'last_name' => 'B',
            'dob' => '2020-01-01',
            'gender' => 'male',
        ]);
        $this->assertSame('outside', $player->ageGroupStatusAt('3-5y', '2026-07-01'));
        $this->assertSame('ok', $player->ageGroupStatusAt('3-8y', '2026-07-01'));
        $this->assertSame('not_checked', $player->ageGroupStatusAt('Pre-K to G5', '2026-07-01'));
        $this->assertSame('not_checked', $player->ageGroupStatusAt('3-8y', ''));
    }
}
