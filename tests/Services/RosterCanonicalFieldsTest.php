<?php
/**
 * Dual-read keep/drop helpers for roster canonical fields.
 *
 * @package InterSoccer_Reports_Rosters
 */

use PHPUnit\Framework\TestCase;

class RosterCanonicalFieldsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname(__DIR__, 2) . '/includes/roster-canonical-fields.php';
	}

	public function test_keep_first_prefers_player_first_name(): void {
		$row = [
			'player_first_name' => 'Ada',
			'first_name'        => 'Legacy',
		];
		$this->assertSame('Ada', intersoccer_roster_field_player_first_name($row));
	}

	public function test_falls_back_to_drop_when_keep_empty(): void {
		$row = [
			'player_first_name' => '',
			'first_name'        => 'Legacy',
			'player_last_name'  => '',
			'last_name'         => 'Name',
		];
		$this->assertSame('Legacy', intersoccer_roster_field_player_first_name($row));
		$this->assertSame('Name', intersoccer_roster_field_player_last_name($row));
	}

	public function test_selected_days_keep_first(): void {
		$row = [
			'selected_days' => 'Monday, Wednesday',
			'days_selected' => 'Tuesday',
		];
		$this->assertSame('Monday, Wednesday', intersoccer_roster_field_selected_days($row));
		$row2 = [ 'selected_days' => '', 'days_selected' => 'Friday' ];
		$this->assertSame('Friday', intersoccer_roster_field_selected_days($row2));
	}

	public function test_apply_canonical_write_mirrors_keep_to_drop(): void {
		$out = intersoccer_roster_apply_canonical_write_fields([
			'player_first_name' => 'Sam',
			'player_last_name'  => 'Lee',
			'player_medical'    => 'None',
			'selected_days'     => 'Monday',
		]);
		$this->assertSame('Sam', $out['first_name']);
		$this->assertSame('Lee', $out['last_name']);
		$this->assertSame('None', $out['medical_conditions']);
		$this->assertSame('Monday', $out['days_selected']);
		$this->assertSame('Sam Lee', $out['player_name']);
	}

	public function test_normalize_export_row_prefers_keep_columns(): void {
		$row = intersoccer_roster_normalize_export_row([
			'player_first_name' => 'Ada',
			'first_name'        => 'Legacy',
			'player_last_name'  => 'Lovelace',
			'last_name'         => 'Drop',
			'player_gender'     => 'female',
			'gender'            => 'male',
			'player_medical'    => 'Peanut allergy',
			'medical_conditions'=> 'Old',
			'player_dob'        => '2015-01-02',
			'dob'               => '2000-01-01',
		]);
		$this->assertSame('Ada', $row['first_name']);
		$this->assertSame('Lovelace', $row['last_name']);
		$this->assertSame('female', $row['gender']);
		$this->assertSame('Peanut allergy', $row['medical_conditions']);
		$this->assertSame('2015-01-02', $row['player_dob']);
		$this->assertSame('Ada Lovelace', $row['player_name']);
	}
}
