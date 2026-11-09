<?php

namespace InterSoccer\ReportsRosters\Tests\Data\Models;

use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerAgeGroupStatusPlaceholderTest extends TestCase {

    private function playerWithDob(string $dob): Player {
        return new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'A',
            'last_name' => 'B',
            'dob' => $dob,
            'gender' => 'male',
        ]);
    }

    public function test_placeholder_dob_is_not_checked_not_outside(): void {
        $player = $this->playerWithDob('1970-01-01');
        $this->assertSame('not_checked', $player->ageGroupStatusAt('3-5y', '2026-07-01'));
    }

    public function test_zero_date_dob_is_not_checked(): void {
        $player = $this->playerWithDob('0000-00-00');
        $this->assertSame('not_checked', $player->ageGroupStatusAt('3-5y', '2026-07-01'));
    }

    public function test_placeholder_start_date_is_not_checked(): void {
        $player = $this->playerWithDob('2018-01-01');
        $this->assertSame('not_checked', $player->ageGroupStatusAt('3-5y', '1970-01-01'));
    }

    public function test_is_missing_or_unparseable_date_helper(): void {
        $this->assertTrue(Player::isMissingOrUnparseableDate(''));
        $this->assertTrue(Player::isMissingOrUnparseableDate('1970-01-01'));
        $this->assertTrue(Player::isMissingOrUnparseableDate('0000-00-00'));
        $this->assertTrue(Player::isMissingOrUnparseableDate('not-a-date'));
        $this->assertFalse(Player::isMissingOrUnparseableDate('2018-03-01'));
    }
}
