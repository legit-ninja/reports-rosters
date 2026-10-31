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
        return [
            'empty' => [null, ''],
            'en half day' => [['min' => 3, 'max' => 5], '3-5y (Half-Day)'],
            'en full day' => [['min' => 5, 'max' => 13], '5-13y (Full Day)'],
            'no y suffix' => [['min' => 5, 'max' => 12], '5-12'],
            'fr name' => [['min' => 3, 'max' => 12], '3-12 ans'],
            'de halbtags' => [['min' => 3, 'max' => 5], '3-5 Jahre (halbtags)'],
            'de ganztaegig' => [['min' => 5, 'max' => 13], '5-13 Jahre (ganztägig)'],
            'de jahre alt' => [['min' => 3, 'max' => 11], '3-11 Jahre alt'],
            'slug ans journee' => [['min' => 5, 'max' => 13], '5-13-ans-journee-complete'],
            'slug fr' => [['min' => 5, 'max' => 12], '5-12-fr'],
            'en dash spaces' => [['min' => 4, 'max' => 11], '4 – 11y'],
            'anchored u12' => [['min' => 0, 'max' => 12], 'U12'],
            'anchored under 14' => [['min' => 0, 'max' => 14], 'Under 14'],
            'mid word u digit' => [null, 'venue-u12-extra'],
            'combined comma label' => [null, '3-5y (Half-Day), 5-13y (Full Day)'],
            'combined slug' => [null, '3-5y-half-day-5-13y-full-day'],
            'pre-k to g5' => [null, 'Pre-K to G5'],
            'g1-g5' => [null, 'G1-G5'],
            'kg gr' => [null, 'KG-GR1, GR2-5'],
            'invalid n greater m' => [null, '13-5y'],
            'two ranges' => [null, '3-5 and 5-13'],
            'predefined camp key' => [['min' => 3, 'max' => 5], '3-5y (Half-Day)'],
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
