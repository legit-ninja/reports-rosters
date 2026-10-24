<?php
/**
 * Win-back engine path: query / heavy-call scaling (10 vs 100 players).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackReportQueryCountTest extends TestCase {
	/**
	 * @return array<string,int>
	 */
	private function reset_perf_counters(): array {
		$GLOBALS['intersoccer_test_wc_get_order_call_count'] = 0;
		$GLOBALS['intersoccer_test_get_post_meta_call_count'] = 0;
		$GLOBALS['intersoccer_test_get_term_by_call_count'] = 0;
		$GLOBALS['intersoccer_test_get_userdata_call_count'] = 0;
		$GLOBALS['intersoccer_test_get_user_meta_call_count'] = 0;
		$GLOBALS['intersoccer_test_wc_get_order_item_meta_call_count'] = 0;
		$GLOBALS['intersoccer_test_live_order_status_batch_load_calls'] = 0;

		return [
			'wc_get_order' => 0,
			'get_post_meta' => 0,
			'get_term_by' => 0,
			'get_userdata' => 0,
			'get_user_meta' => 0,
			'wc_get_order_item_meta' => 0,
			'live_status_batches' => 0,
		];
	}

	/**
	 * @return array<string,int>
	 */
	private function read_perf_counters(): array {
		return [
			'wc_get_order' => (int) ( $GLOBALS['intersoccer_test_wc_get_order_call_count'] ?? 0 ),
			'get_post_meta' => (int) ( $GLOBALS['intersoccer_test_get_post_meta_call_count'] ?? 0 ),
			'get_term_by' => (int) ( $GLOBALS['intersoccer_test_get_term_by_call_count'] ?? 0 ),
			'get_userdata' => (int) ( $GLOBALS['intersoccer_test_get_userdata_call_count'] ?? 0 ),
			'get_user_meta' => (int) ( $GLOBALS['intersoccer_test_get_user_meta_call_count'] ?? 0 ),
			'wc_get_order_item_meta' => (int) ( $GLOBALS['intersoccer_test_wc_get_order_item_meta_call_count'] ?? 0 ),
			'live_status_batches' => (int) ( $GLOBALS['intersoccer_test_live_order_status_batch_load_calls'] ?? 0 ),
		];
	}

	/**
	 * @param int $player_count
	 * @return array<int,array<string,mixed>>
	 */
	private function synthetic_history_rows(int $player_count): array {
		$rows = [];
		for ($i = 1; $i <= $player_count; $i++) {
			$customer_id = $i % 3 === 0 ? 0 : (1000 + $i);
			$order_id = 50000 + $i;
			$rows[] = [
				'id' => $i,
				'customer_id' => $customer_id,
				'player_index' => 0,
				'order_id' => $order_id,
				'order_item_id' => 60000 + $i,
				'order_status' => 'completed',
				'activity_type' => $i % 5 === 0 ? 'Course' : 'Camp',
				'player_first_name' => 'Player',
				'player_last_name' => 'N' . $i,
				'player_dob' => '2015-06-01',
				'venue' => 'test-venue',
				'age_group' => '5-12y',
				'start_date' => '2025-08-01',
				'end_date' => '2025-08-05',
				'parent_email' => 'parent' . $i . '@example.test',
				'parent_phone' => '+410000000' . $i,
			];
		}

		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $history_rows
	 */
	private function run_winback_perf_slice(array $history_rows): void {
		$today = '2026-06-15';
		$lookback_start = '2025-06-15';

		$order_map = [];
		$created_map = [];
		$status_map = [];
		foreach ($history_rows as $row) {
			$oid = (int) $row['order_id'];
			$cid = (int) $row['customer_id'];
			if ($cid <= 0) {
				$cid = 2000 + $oid;
			}
			$order_map[ $oid ] = $cid;
			$created_map[ $oid ] = '2025-04-01';
			$status_map[ $oid ] = 'wc-completed';
		}
		$order_map[59999] = 3001;
		$created_map[59999] = '2025-04-01';
		$status_map[59999] = 'wc-completed';
		$GLOBALS['intersoccer_test_order_customer_id_map'] = $order_map;
		$GLOBALS['intersoccer_test_order_created_date_map'] = $created_map;
		$GLOBALS['intersoccer_test_live_order_status_map'] = $status_map;
		$GLOBALS['intersoccer_test_orders_with_refunds'] = [59999 => true];
		$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'] = [69999 => true];
		$GLOBALS['intersoccer_test_get_userdata'] = [];
		foreach (array_unique(array_values($order_map)) as $uid) {
			$GLOBALS['intersoccer_test_get_userdata'][ (int) $uid ] = (object) [
				'ID' => (int) $uid,
				'user_email' => 'acct' . $uid . '@example.test',
			];
		}

		$running_row = [
			'is_placeholder' => 0,
			'order_item_id' => 69999,
			'order_id' => 59999,
			'order_status' => 'completed',
			'activity_type' => 'Course',
			'customer_id' => 3001,
			'player_index' => 0,
			'player_first_name' => 'Running',
			'player_last_name' => 'Course',
			'start_date' => '2026-01-01',
			'end_date' => '2026-12-31',
		];
		$GLOBALS['intersoccer_test_winback_current_course_term_rows'] = [ $running_row ];

		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
		if (function_exists('intersoccer_roster_report_reset_order_customer_id_cache')) {
			intersoccer_roster_report_reset_order_customer_id_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_fake_customer_cache')) {
			intersoccer_roster_report_reset_fake_customer_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		intersoccer_winback_reset_order_created_date_cache();
		intersoccer_winback_reset_current_course_term_cache();
		intersoccer_winback_reset_j8_lookup_cache();

		$rows = intersoccer_roster_report_rows_apply_live_order_status($history_rows);
		intersoccer_roster_report_prime_order_customer_ids_from_rows($rows);
		intersoccer_roster_report_prime_line_refund_checks_from_rows($rows);
		intersoccer_winback_prime_order_created_dates_from_rows($rows);
		intersoccer_roster_report_prime_fake_customer_checks(
			intersoccer_roster_report_collect_resolved_customer_ids_from_rows($rows)
		);

		$identity_map = new \Intersoccer_Roster_Report_Player_Identity_Map();
		$by_player_kind = [];
		foreach ($rows as $row) {
			$row = intersoccer_roster_report_row_with_resolved_customer($row);
			if (intersoccer_roster_report_is_fake_customer((int) $row['customer_id'])) {
				continue;
			}
			if (!intersoccer_roster_report_row_counts_for_winback($row, 'history')) {
				continue;
			}
			$key_bits = intersoccer_roster_report_build_player_key($row, '');
			if ($key_bits['key'] === '') {
				continue;
			}
			$identity_map->register_row($row, '');
			$pk = $identity_map->group_id($key_bits['key']) . '|camp';
			$by_player_kind[ $pk ] = [
				'row' => $row,
				'key' => $key_bits['key'],
				'venue_slug' => 'test-venue',
				'age_slug' => '5-12y',
			];
		}

		intersoccer_winback_report_engine_build_j8_lookup($today, $identity_map);

		$catalogue = [
			'camp|test-venue|5-12y' => [
				[
					'kind' => 'camp',
					'variation_id' => 1,
					'source_variation_id' => 1,
					'product_id' => 1,
					'start_date' => '2026-08-01',
					'product_name' => 'Camp',
					'venue_slug' => 'test-venue',
					'age_group_slug' => '5-12y',
					'full' => false,
				],
			],
		];
		intersoccer_winback_reset_catalogue_target_cache();

		$customer_ids = [];
		foreach ($by_player_kind as $history) {
			$customer_id = (int) $history['row']['customer_id'];
			if ($customer_id > 0) {
				$customer_ids[ $customer_id ] = $customer_id;
			}
			if (intersoccer_winback_report_engine_player_in_current_course_term(
				$history['key'],
				'course',
				$today,
				[],
				$identity_map
			)) {
				continue;
			}
			intersoccer_winback_resolve_catalogue_target_and_more(
				$catalogue,
				'camp',
				$history['venue_slug'],
				$history['age_slug'],
				'2015-06-01'
			);
		}
		if (!empty($customer_ids)) {
			intersoccer_winback_prime_contact_users(array_values($customer_ids));
			foreach ($by_player_kind as $history) {
				intersoccer_winback_report_engine_build_contact(
					(int) $history['row']['customer_id'],
					$history['row']
				);
			}
		}
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_orders_with_refunds'],
			$GLOBALS['intersoccer_test_wc_get_order_call_count'],
			$GLOBALS['intersoccer_test_get_post_meta_call_count'],
			$GLOBALS['intersoccer_test_get_term_by_call_count'],
			$GLOBALS['intersoccer_test_get_userdata_call_count'],
			$GLOBALS['intersoccer_test_get_user_meta_call_count'],
			$GLOBALS['intersoccer_test_wc_get_order_item_meta_call_count'],
			$GLOBALS['intersoccer_test_winback_current_course_term_rows']
		);
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		require_once $includes . '/roster-report-loader.php';
		require_once $includes . '/winback-report-facets.php';
		require_once $includes . '/winback-catalogue.php';
		require_once $includes . '/winback-report-engine.php';
	}

	public function test_winback_slice_query_and_heavy_calls_scale_sublinearly_with_player_count(): void {
		$this->reset_perf_counters();
		$this->run_winback_perf_slice($this->synthetic_history_rows(10));
		$small = $this->read_perf_counters();

		$this->reset_perf_counters();
		$this->run_winback_perf_slice($this->synthetic_history_rows(100));
		$large = $this->read_perf_counters();

		$delta_wc = $large['wc_get_order'] - $small['wc_get_order'];
		$this->assertLessThanOrEqual(
			2,
			$delta_wc,
			'wc_get_order should not scale per player (only refund orders): small='
			. $small['wc_get_order'] . ' large=' . $large['wc_get_order']
		);
		$this->assertLessThanOrEqual(1, $small['wc_get_order']);
		$this->assertLessThanOrEqual(1, $large['wc_get_order']);

		$delta_status_batches = $large['live_status_batches'] - $small['live_status_batches'];
		$this->assertLessThanOrEqual(
			1,
			$delta_status_batches,
			'live status batch loads should differ by at most one chunk: small='
			. $small['live_status_batches'] . ' large=' . $large['live_status_batches']
		);

		$scaling_keys = ['get_post_meta', 'get_term_by', 'wc_get_order_item_meta'];
		foreach ($scaling_keys as $key) {
			$delta = $large[ $key ] - $small[ $key ];
			$this->assertLessThanOrEqual(
				15,
				$delta,
				$key . ' calls should grow by at most a small constant: small=' . $small[ $key ] . ' large=' . $large[ $key ]
			);
		}

		foreach (['get_userdata', 'get_user_meta'] as $key) {
			$this->assertLessThanOrEqual(
				12 * max(1, $small[ $key ]),
				$large[ $key ],
				$key . ' should scale roughly with player count, not per-row N+1: small=' . $small[ $key ] . ' large=' . $large[ $key ]
			);
		}
	}
}

}

namespace {
	if (!function_exists('get_userdata')) {
		function get_userdata($id) {
			if (isset($GLOBALS['intersoccer_test_get_userdata_call_count'])) {
				$GLOBALS['intersoccer_test_get_userdata_call_count']++;
			}

			return $GLOBALS['intersoccer_test_get_userdata'][(int) $id] ?? false;
		}
	}
}
