<?php
/**
 * Player course status: pending / on-hold booking_status labels.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/WinbackReportQueryCountTest.php';
require_once __DIR__ . '/PlayerCourseStatusQueryCountTest.php';

class PlayerCourseStatusPendingTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		if (function_exists('intersoccer_test_ensure_pm_filter_stub')) {
			intersoccer_test_ensure_pm_filter_stub();
		}
		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['medical-access.php', 'final-reports-aggregation.php', 'utils.php', 'winback-report-facets.php', 'player-camp-status.php', 'player-course-status.php'] as $file) {
			$path = $includes . '/' . $file;
			if (file_exists($path)) {
				require_once $path;
			}
		}
		$this->reset_fixtures();
	}

	protected function tearDown(): void {
		$this->reset_fixtures();
		parent::tearDown();
	}

	private function reset_fixtures(): void {
		unset(
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'],
			$GLOBALS['intersoccer_test_order_item_meta'],
			$GLOBALS['intersoccer_test_batch_chunk_size']
		);
		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
		if (function_exists('intersoccer_roster_report_reset_order_customer_id_cache')) {
			intersoccer_roster_report_reset_order_customer_id_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		if (function_exists('intersoccer_roster_report_reset_order_item_meta_cache')) {
			intersoccer_roster_report_reset_order_item_meta_cache();
		}
		if (function_exists('intersoccer_winback_reset_facet_resolution_cache')) {
			intersoccer_winback_reset_facet_resolution_cache();
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function base_course_row(array $overrides = []): array {
		return array_merge([
			'activity_type' => 'Course',
			'product_name' => 'Autumn Skills',
			'venue' => 'Geneva Centre',
			'course_day' => 'Sunday',
			'times' => '10:00-11:30',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-15',
			'order_id' => 5001,
			'order_item_id' => 9001,
			'order_status' => 'completed',
			'customer_id' => 10,
			'player_index' => 0,
			'player_first_name' => 'Ada',
			'player_last_name' => 'Lovelace',
			'girls_only' => 0,
			'is_placeholder' => 0,
			'age_group' => '5-12y',
		], $overrides);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function base_player(array $overrides = []): array {
		return array_merge([
			'user_id' => 10,
			'user_email' => 'parent@example.test',
			'player_id' => 'uuid-ada',
			'player_index' => 0,
			'first_name' => 'Ada',
			'last_name' => 'Lovelace',
			'dob' => '2015-01-01',
		], $overrides);
	}

	public function test_pending_only_player_booking_status_pending_payment(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [ $this->base_player() ];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row(['order_status' => 'pending']),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$row = $result['rows'][0];
		$this->assertFalse($row['booked']);
		$this->assertSame('pending_payment', $row['booking_status']);
		$this->assertSame('Pending payment', $row['booking_status_label']);
		$this->assertCount(1, $row['pending_bookings']);
		$this->assertSame([], $row['bookings']);
		$this->assertCount(0, $result['unmatched_bookings']);
	}

	public function test_on_hold_only_player_booking_status_on_hold(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [ $this->base_player() ];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row(['order_status' => 'on-hold', 'order_item_id' => 9002]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$row = $result['rows'][0];
		$this->assertSame('on_hold', $row['booking_status']);
		$this->assertSame('On hold', $row['booking_status_label']);
	}

	public function test_pending_plus_completed_booking_status_booked(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [ $this->base_player() ];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row(['order_status' => 'pending', 'order_item_id' => 9010]),
			$this->base_course_row(['order_status' => 'completed', 'order_item_id' => 9011]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$row = $result['rows'][0];
		$this->assertTrue($row['booked']);
		$this->assertSame('booked', $row['booking_status']);
		$this->assertSame('Booked', $row['booking_status_label']);
		$this->assertCount(1, $row['bookings']);
		$this->assertCount(1, $row['pending_bookings']);
	}

	public function test_pending_plus_on_hold_without_completed_pending_payment_precedence(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [ $this->base_player() ];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row(['order_status' => 'on-hold', 'order_item_id' => 9020]),
			$this->base_course_row(['order_status' => 'pending', 'order_item_id' => 9021]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$row = $result['rows'][0];
		$this->assertSame('pending_payment', $row['booking_status']);
	}

	public function test_cancelled_live_status_not_booked_empty_pending_bookings(): void {
		$GLOBALS['intersoccer_test_live_order_status_map'] = [5001 => 'wc-cancelled'];
		$GLOBALS['intersoccer_test_player_course_status_players'] = [ $this->base_player() ];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row(['order_status' => 'completed']),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$row = $result['rows'][0];
		$this->assertSame('not_booked', $row['booking_status']);
		$this->assertSame([], $row['pending_bookings']);
	}

	public function test_refunded_and_failed_live_status_not_booked(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [ $this->base_player() ];
		foreach (['wc-refunded', 'wc-failed'] as $live) {
			$this->reset_fixtures();
			$GLOBALS['intersoccer_test_live_order_status_map'] = [5001 => $live];
			$GLOBALS['intersoccer_test_player_course_status_players'] = [ $this->base_player() ];
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
				$this->base_course_row(['order_status' => 'pending']),
			];
			$result = intersoccer_player_course_status_generate(['year' => 2026]);
			$this->assertSame('not_booked', $result['rows'][0]['booking_status']);
			$this->assertSame([], $result['rows'][0]['pending_bookings']);
		}
	}

	public function test_venue_facet_pending_at_other_venue_not_booked(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [ $this->base_player() ];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row([
				'order_status' => 'pending',
				'venue' => 'Zurich North',
			]),
		];
		$slug = intersoccer_player_course_status_resolve_venue_facet(
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'][0]
		)['slug'];
		$geneva_slug = intersoccer_player_course_status_resolve_venue_facet(
			$this->base_course_row(['venue' => 'Geneva Centre'])
		)['slug'];
		$this->assertNotSame($geneva_slug, $slug);
		$result = intersoccer_player_course_status_generate([
			'year' => 2026,
			'venue' => $geneva_slug,
		]);
		$row = $result['rows'][0];
		$this->assertSame('not_booked', $row['booking_status']);
		$this->assertCount(1, $row['pending_bookings']);
	}

	public function test_legacy_customer_zero_pending_matches_by_name(): void {
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [5001 => 42];
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			$this->base_player([
				'user_id' => 42,
				'player_id' => 'uuid-a',
				'first_name' => 'Ada',
				'last_name' => 'Lovelace',
			]),
			$this->base_player([
				'user_id' => 42,
				'player_id' => 'uuid-b',
				'player_index' => 1,
				'first_name' => 'Alan',
				'last_name' => 'Turing',
			]),
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row([
				'customer_id' => 0,
				'order_status' => 'pending',
				'player_index' => 0,
				'player_first_name' => 'Alan',
				'player_last_name' => 'Turing',
				'order_item_id' => 9100,
			]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$by_first = [];
		foreach ($result['rows'] as $row) {
			$by_first[ $row['player']['first_name'] ] = $row;
		}
		$this->assertSame('not_booked', $by_first['Ada']['booking_status']);
		$this->assertSame('pending_payment', $by_first['Alan']['booking_status']);
		$this->assertSame('name', $by_first['Alan']['player']['tier']);
	}

	public function test_totals_mixed_fixture_counts_pending_and_on_hold(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			$this->base_player(['player_id' => 'p-booked', 'user_id' => 1]),
			$this->base_player(['player_id' => 'p-pending', 'user_id' => 2, 'first_name' => 'P', 'last_name' => 'Pending']),
			$this->base_player(['player_id' => 'p-hold', 'user_id' => 3, 'first_name' => 'O', 'last_name' => 'Hold']),
			$this->base_player(['player_id' => 'p-none', 'user_id' => 4, 'first_name' => 'N', 'last_name' => 'One']),
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row(['customer_id' => 1, 'player_index' => 0, 'order_item_id' => 9201]),
			$this->base_course_row([
				'customer_id' => 2,
				'player_index' => 0,
				'player_first_name' => 'P',
				'player_last_name' => 'Pending',
				'order_status' => 'pending',
				'order_item_id' => 9202,
			]),
			$this->base_course_row([
				'customer_id' => 3,
				'player_index' => 0,
				'player_first_name' => 'O',
				'player_last_name' => 'Hold',
				'order_status' => 'on-hold',
				'order_item_id' => 9203,
			]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertSame(1, $result['totals']['booked']);
		$this->assertSame(3, $result['totals']['not_booked']);
		$this->assertSame(1, $result['totals']['pending_payment']);
		$this->assertSame(1, $result['totals']['on_hold']);
	}

	public function test_booked_not_booked_filter_keeps_pending_players(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			$this->base_player(['user_id' => 2, 'player_id' => 'p2', 'first_name' => 'P', 'last_name' => 'Two']),
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row([
				'customer_id' => 2,
				'player_first_name' => 'P',
				'player_last_name' => 'Two',
				'order_status' => 'pending',
			]),
		];
		$result = intersoccer_player_course_status_generate([
			'year' => 2026,
			'booked' => 'not_booked',
		]);
		$this->assertCount(1, $result['rows']);
	}

	public function test_booking_status_filter_pending_payment_only(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			$this->base_player(['user_id' => 2, 'player_id' => 'p2', 'first_name' => 'P', 'last_name' => 'Two']),
			$this->base_player(['user_id' => 3, 'player_id' => 'p3', 'first_name' => 'O', 'last_name' => 'Hold', 'player_index' => 0]),
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row([
				'customer_id' => 2,
				'player_first_name' => 'P',
				'player_last_name' => 'Two',
				'order_status' => 'pending',
				'order_item_id' => 9301,
			]),
			$this->base_course_row([
				'customer_id' => 3,
				'player_first_name' => 'O',
				'player_last_name' => 'Hold',
				'order_status' => 'on-hold',
				'order_item_id' => 9302,
			]),
		];
		$result = intersoccer_player_course_status_generate([
			'year' => 2026,
			'booking_status' => 'pending_payment',
		]);
		$this->assertCount(1, $result['rows']);
		$this->assertSame('P', $result['rows'][0]['player']['first_name']);
	}

	public function test_export_headers_status_after_booked_and_row_values(): void {
		intersoccer_test_set_user_caps(['manage_options' => true]);
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		$headers = intersoccer_player_course_status_export_headers();
		$booked_idx = array_search('Booked', $headers, true);
		$this->assertNotFalse($booked_idx);
		$this->assertSame('Status', $headers[ $booked_idx + 1 ]);

		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			$this->base_player(['user_id' => 1]),
			$this->base_player(['user_id' => 2, 'player_id' => 'p2', 'first_name' => 'P', 'last_name' => 'Pay']),
			$this->base_player(['user_id' => 3, 'player_id' => 'p3', 'first_name' => 'O', 'last_name' => 'Hold']),
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row(['customer_id' => 1, 'order_item_id' => 9401]),
			$this->base_course_row([
				'customer_id' => 2,
				'player_first_name' => 'P',
				'player_last_name' => 'Pay',
				'order_status' => 'pending',
				'order_item_id' => 9402,
			]),
			$this->base_course_row([
				'customer_id' => 3,
				'player_first_name' => 'O',
				'player_last_name' => 'Hold',
				'order_status' => 'on-hold',
				'order_item_id' => 9403,
			]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$status_idx = $booked_idx + 1;
		foreach ($result['rows'] as $row) {
			$export = intersoccer_player_course_status_export_row($row);
			$this->assertCount(count($headers), $export);
			$expected = (string) $row['booking_status_label'];
			$this->assertSame($expected, $export[ $status_idx ]);
		}

		intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		$headers_no_dob = intersoccer_player_course_status_export_headers();
		$booked_idx2 = array_search('Booked', $headers_no_dob, true);
		$this->assertSame('Status', $headers_no_dob[ $booked_idx2 + 1 ]);
	}

	public function test_generate_query_count_with_pending_rows_independent_of_volume(): void {
		global $wpdb;
		$saved = $wpdb;
		$counting = new PlayerCourseStatusCountingWpdb();
		$wpdb = $counting;
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		$GLOBALS['intersoccer_test_live_order_status_map'] = [];
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [];

		$build = function (int $n) {
			$rows = [];
			$players = [];
			for ($i = 1; $i <= $n; $i++) {
				$order_id = 61000 + $i;
				$status = $i % 3 === 0 ? 'pending' : ($i % 3 === 1 ? 'on-hold' : 'completed');
				$rows[] = [
					'activity_type' => 'Course',
					'product_name' => 'Course',
					'venue' => 'Geneva Centre',
					'course_day' => 'Sunday',
					'times' => '10:00',
					'season' => 'Autumn 2026',
					'start_date' => '2026-09-01',
					'end_date' => '2026-12-01',
					'order_id' => $order_id,
					'order_item_id' => 71000 + $i,
					'order_status' => $status,
					'customer_id' => 1000 + ($i % 5),
					'player_index' => 0,
					'player_first_name' => 'Player',
					'player_last_name' => 'N' . $i,
					'girls_only' => 0,
					'is_placeholder' => 0,
					'age_group' => '5-12y',
				];
				$GLOBALS['intersoccer_test_live_order_status_map'][ $order_id ] = 'wc-' . $status;
				$GLOBALS['intersoccer_test_order_customer_id_map'][ $order_id ] = 1000 + ($i % 5);
				$players[] = [
					'user_id' => 1000 + ($i % 5),
					'user_email' => 'u' . $i . '@t.test',
					'player_id' => 'uuid-' . $i,
					'player_index' => 0,
					'first_name' => 'Player',
					'last_name' => 'N' . $i,
					'dob' => '2015-01-01',
				];
			}
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
			$GLOBALS['intersoccer_test_player_course_status_players'] = $players;
		};

		$build(10);
		$counting->query_count = 0;
		intersoccer_player_course_status_generate(['year' => 2026]);
		$small = $counting->query_count;

		$build(100);
		$counting->query_count = 0;
		intersoccer_player_course_status_generate(['year' => 2026]);
		$large = $counting->query_count;

		$wpdb = $saved;
		$this->assertSame($small, $large);
	}
}
