<?php
/**
 * Player camp status join/filter helpers.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerCampStatusTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		if (file_exists($includes . '/reports-data.php')) {
			require_once $includes . '/reports-data.php';
		}
		if (file_exists($includes . '/utils.php')) {
			require_once $includes . '/utils.php';
		}
		if (file_exists($includes . '/player-camp-status.php')) {
			require_once $includes . '/player-camp-status.php';
		}
	}

	public function test_filter_roster_rows_by_year_and_season_type() {
		if (!function_exists('intersoccer_player_camp_status_filter_roster_rows')) {
			$this->markTestSkipped('player-camp-status helpers not loaded');
		}

		$rows = [
			[
				'activity_type' => 'Camp',
				'season' => 'Summer 2026',
				'girls_only' => 0,
				'is_placeholder' => 0,
				'customer_id' => 1,
				'player_index' => 0,
				'first_name' => 'Ada',
				'last_name' => 'Lovelace',
				'product_name' => 'Summer Camp',
				'venue' => 'Geneva',
				'camp_terms' => 'Week 1',
			],
			[
				'activity_type' => 'Camp',
				'season' => 'Winter 2026',
				'girls_only' => 0,
				'is_placeholder' => 0,
				'customer_id' => 1,
				'player_index' => 0,
				'first_name' => 'Ada',
				'last_name' => 'Lovelace',
				'product_name' => 'Winter Camp',
				'venue' => 'Zurich',
				'camp_terms' => 'Week 1',
			],
			[
				'activity_type' => 'Camp',
				'season' => 'Summer 2025',
				'girls_only' => 0,
				'is_placeholder' => 0,
				'customer_id' => 2,
				'player_index' => 0,
				'first_name' => 'Bob',
				'last_name' => 'Smith',
				'product_name' => 'Old Camp',
				'venue' => 'Bern',
				'camp_terms' => 'Week 2',
			],
			[
				'activity_type' => 'Course',
				'season' => 'Summer 2026',
				'girls_only' => 0,
				'is_placeholder' => 0,
				'customer_id' => 3,
				'player_index' => 0,
				'first_name' => 'Cara',
				'last_name' => 'Lee',
				'product_name' => 'Course',
				'venue' => 'Geneva',
				'course_day' => 'Tuesday',
			],
		];

		$filtered = intersoccer_player_camp_status_filter_roster_rows($rows, 2026, 'Summer', 'all');
		$this->assertCount(1, $filtered);
		$this->assertSame('Summer Camp', $filtered[0]['product_name']);

		$all_2026 = intersoccer_player_camp_status_filter_roster_rows($rows, 2026, '', 'all');
		$this->assertCount(2, $all_2026);
	}

	public function test_girls_mode_filter() {
		if (!function_exists('intersoccer_player_camp_status_filter_roster_rows')) {
			$this->markTestSkipped('player-camp-status helpers not loaded');
		}

		$rows = [
			[
				'activity_type' => 'Camp',
				'season' => 'Summer 2026',
				'girls_only' => 0,
				'is_placeholder' => 0,
				'product_name' => 'Mixed',
			],
			[
				'activity_type' => 'Camp, Girls Only',
				'season' => 'Summer 2026',
				'girls_only' => 1,
				'is_placeholder' => 0,
				'product_name' => 'Girls',
			],
			[
				'activity_type' => 'Camp',
				'season' => 'Summer 2026',
				'girls_only' => 0,
				'is_placeholder' => 1,
				'product_name' => 'Placeholder',
			],
		];

		$all = intersoccer_player_camp_status_filter_roster_rows($rows, 2026, '', 'all');
		$this->assertCount(2, $all);

		$mixed = intersoccer_player_camp_status_filter_roster_rows($rows, 2026, '', 'mixed');
		$this->assertCount(1, $mixed);
		$this->assertSame('Mixed', $mixed[0]['product_name']);

		$girls = intersoccer_player_camp_status_filter_roster_rows($rows, 2026, '', 'girls_only');
		$this->assertCount(1, $girls);
		$this->assertSame('Girls', $girls[0]['product_name']);
	}

	public function test_join_matches_player_index_then_name_fallback() {
		if (!function_exists('intersoccer_player_camp_status_join')) {
			$this->markTestSkipped('player-camp-status helpers not loaded');
		}

		$players = [
			[
				'user_id' => 10,
				'user_email' => 'a@example.com',
				'player_id' => 'uuid-a',
				'player_index' => 0,
				'first_name' => 'Ada',
				'last_name' => 'Lovelace',
				'dob' => '2015-01-01',
				'age' => 11,
			],
			[
				'user_id' => 10,
				'user_email' => 'a@example.com',
				'player_id' => 'uuid-b',
				'player_index' => 1,
				'first_name' => 'Alan',
				'last_name' => 'Turing',
				'dob' => '2016-02-02',
				'age' => 10,
			],
			[
				'user_id' => 20,
				'user_email' => 'b@example.com',
				'player_id' => 'uuid-c',
				'player_index' => 0,
				'first_name' => 'Ada',
				'last_name' => 'Lovelace',
				'dob' => '2014-03-03',
				'age' => 12,
			],
		];

		$roster_rows = [
			[
				'customer_id' => 10,
				'player_index' => 0,
				'first_name' => 'Ada',
				'last_name' => 'Lovelace',
				'product_name' => 'Camp A',
				'venue' => 'Geneva',
				'camp_terms' => 'Week 1',
				'season' => 'Summer 2026',
				'start_date' => '2026-07-06',
			],
			[
				'customer_id' => 10,
				'player_index' => 99,
				'first_name' => 'Alan',
				'last_name' => 'Turing',
				'product_name' => 'Camp B',
				'venue' => 'Zurich',
				'camp_terms' => 'Week 2',
				'season' => 'Summer 2026',
				'start_date' => '2026-07-13',
			],
		];

		$index = intersoccer_player_camp_status_build_roster_index($roster_rows);
		$joined = intersoccer_player_camp_status_join($players, $index);

		$this->assertTrue($joined[0]['booked']);
		$this->assertStringContainsString('Camp A', $joined[0]['bookings_display']);

		// Index mismatch → name + customer_id fallback.
		$this->assertTrue($joined[1]['booked']);
		$this->assertStringContainsString('Camp B', $joined[1]['bookings_display']);

		// Same name, different customer → not booked.
		$this->assertFalse($joined[2]['booked']);
	}

	public function test_booked_filter_and_search() {
		if (!function_exists('intersoccer_player_camp_status_filter_rows')) {
			$this->markTestSkipped('player-camp-status helpers not loaded');
		}

		$joined = [
			[
				'first_name' => 'Ada',
				'last_name' => 'Lovelace',
				'user_email' => 'ada@example.com',
				'booked' => true,
				'bookings_display' => 'Camp A',
			],
			[
				'first_name' => 'Bob',
				'last_name' => 'Smith',
				'user_email' => 'bob@example.com',
				'booked' => false,
				'bookings_display' => '',
			],
		];

		$booked = intersoccer_player_camp_status_filter_rows($joined, 'booked');
		$this->assertCount(1, $booked);
		$this->assertSame('Ada', $booked[0]['first_name']);

		$not = intersoccer_player_camp_status_filter_rows($joined, 'not_booked');
		$this->assertCount(1, $not);
		$this->assertSame('Bob', $not[0]['first_name']);

		$search = intersoccer_player_camp_status_filter_rows($joined, 'all', 'bob@');
		$this->assertCount(1, $search);
		$this->assertSame('Bob', $search[0]['first_name']);
	}

	public function test_export_row_shape() {
		if (!function_exists('intersoccer_player_camp_status_export_row')) {
			$this->markTestSkipped('player-camp-status helpers not loaded');
		}

		$exported = intersoccer_player_camp_status_export_row([
			'first_name' => 'Ada',
			'last_name' => 'Lovelace',
			'user_email' => 'ada@example.com',
			'user_id' => 10,
			'dob' => '2015-01-01',
			'age' => 11,
			'booked' => true,
			'bookings_display' => 'Camp A · Geneva · Week 1 · Summer 2026',
		]);

		$this->assertSame('Ada Lovelace', $exported[0]);
		$this->assertSame('ada@example.com', $exported[1]);
		$this->assertSame(10, $exported[2]);
		$this->assertSame('Yes', $exported[5]);
		$this->assertStringContainsString('Camp A', $exported[6]);
	}
}
