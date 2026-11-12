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

	protected function tearDown(): void {
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		unset(
			$GLOBALS['intersoccer_test_current_user_caps'],
			$GLOBALS['intersoccer_test_current_user']
		);
		parent::tearDown();
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
		if (function_exists('intersoccer_test_ensure_pm_filter_stub')) {
			intersoccer_test_ensure_pm_filter_stub();
		}
		if (function_exists('intersoccer_test_set_user_caps')) {
			intersoccer_test_set_user_caps(['manage_options' => true]);
		}
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
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
		$this->assertSame('2015-01-01', $exported[3]);
		$this->assertSame('Yes', $exported[5]);
		$this->assertStringContainsString('Camp A', $exported[6]);
	}

	public function test_fully_refunded_line_is_not_booked(): void {
		$players = [$this->campPlayer()];
		$rows = [$this->campRow([
			'line_fully_refunded' => true,
		])];

		$joined = intersoccer_player_camp_status_join(
			$players,
			intersoccer_player_camp_status_build_roster_index($rows)
		);

		$this->assertFalse($joined[0]['booked']);
		$this->assertSame('', $joined[0]['bookings_display']);
	}

	public function test_partial_refund_reduces_booked_quantity(): void {
		$players = [$this->campPlayer()];
		$rows = [$this->campRow([
			'quantity' => 3,
			'qty_refunded' => 1,
		])];

		$joined = intersoccer_player_camp_status_join(
			$players,
			intersoccer_player_camp_status_build_roster_index($rows)
		);

		$this->assertTrue($joined[0]['booked']);
		$this->assertSame(2.0, $joined[0]['bookings'][0]['quantity']);
	}

	public function test_refunded_quantity_covering_the_line_is_not_booked(): void {
		$players = [$this->campPlayer()];
		$rows = [$this->campRow([
			'quantity' => 2,
			'qty_refunded' => 2,
		])];

		$joined = intersoccer_player_camp_status_join(
			$players,
			intersoccer_player_camp_status_build_roster_index($rows)
		);

		$this->assertFalse($joined[0]['booked']);
	}

	public function test_order_partial_refund_reduces_quantity_and_full_line_refund_does_not_book(): void {
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}

		$line = new class {
			public function get_quantity() { return 4.0; }
			public function get_total() { return 400.0; }
			public function get_subtotal() { return 400.0; }
		};
		$order = new class($line) {
			private $line;
			public function __construct($line) { $this->line = $line; }
			public function get_id() { return 701; }
			public function get_status() { return 'completed'; }
			public function get_item($id) { return (int) $id === 801 ? $this->line : null; }
			public function get_qty_refunded_for_item($id) { return (int) $id === 801 ? -1.0 : 0.0; }
			public function get_refunds() { return []; }
		};

		$GLOBALS['intersoccer_test_orders_with_refunds'] = [701 => true];
		$GLOBALS['intersoccer_test_wc_get_orders_callback'] = static function () use ($order) {
			return [$order];
		};
		$GLOBALS['intersoccer_test_wc_get_order_callback'] = static function () use ($order) {
			return $order;
		};

		try {
			$partial = $this->campRow([
				'order_id' => 701,
				'order_item_id' => 801,
			]);
			intersoccer_roster_report_prime_line_refund_item_results_from_rows([$partial]);
			$joined = intersoccer_player_camp_status_join(
				[$this->campPlayer()],
				intersoccer_player_camp_status_build_roster_index([$partial])
			);
			$this->assertTrue($joined[0]['booked']);
			$this->assertSame(3.0, $joined[0]['bookings'][0]['quantity']);

			$full = $this->campRow([
				'order_item_id' => 802,
				'line_fully_refunded' => true,
			]);
			$not_booked = intersoccer_player_camp_status_join(
				[$this->campPlayer()],
				intersoccer_player_camp_status_build_roster_index([$full])
			);
			$this->assertFalse($not_booked[0]['booked']);
		} finally {
			unset(
				$GLOBALS['intersoccer_test_orders_with_refunds'],
				$GLOBALS['intersoccer_test_wc_get_orders_callback'],
				$GLOBALS['intersoccer_test_wc_get_order_callback']
			);
			if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
				intersoccer_roster_report_reset_line_refund_check_state();
			}
		}
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function campPlayer(): array {
		return [
			'user_id' => 10,
			'user_email' => 'ada@example.com',
			'player_id' => 'uuid-a',
			'player_index' => 0,
			'first_name' => 'Ada',
			'last_name' => 'Example',
			'dob' => '2015-01-01',
			'age' => 11,
		];
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function campRow(array $overrides = []): array {
		return array_merge([
			'customer_id' => 10,
			'player_index' => 0,
			'order_id' => 501,
			'order_item_id' => 901,
			'order_status' => 'completed',
			'first_name' => 'Ada',
			'last_name' => 'Example',
			'product_name' => 'Summer Camp',
			'venue' => 'Example Venue',
			'camp_terms' => 'Week 1',
			'season' => 'Summer 2026',
			'start_date' => '2026-07-06',
		], $overrides);
	}
}
