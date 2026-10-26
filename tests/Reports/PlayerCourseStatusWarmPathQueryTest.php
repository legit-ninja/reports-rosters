<?php
/**
 * PCS warm filter path: real query/delete_option budgets (not stubbed at apply_filters layer).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/PlayerCourseStatusQueryCountTest.php';
require_once __DIR__ . '/PlayerCourseStatusWarmApiCountHelper.php';

class PlayerCourseStatusWarmPathQueryTest extends TestCase {
	/** @var int */
	private const WARM_QUERY_CEILING = 40;

	/** @var int */
	private const WARM_API_TOTAL_CEILING = 0;

	/** @var int */
	private const COLD_API_TOTAL_CEILING = 120;

	/** @var PlayerCourseStatusCountingWpdb|null */
	private $counting_wpdb = null;

	/** @var object|null */
	private $saved_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->saved_wpdb = $wpdb;
		$this->counting_wpdb = new PlayerCourseStatusCountingWpdb();
		$wpdb = $this->counting_wpdb;

		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['reports-data.php', 'final-reports-aggregation.php', 'utils.php', 'roster-report-loader.php', 'winback-report-facets.php', 'winback-report-cache.php', 'player-camp-status.php', 'player-course-status.php', 'player-course-status-warmup.php'] as $file) {
			require_once $includes . '/' . $file;
		}
		require_once $includes . '/winback-report-perf.php';
		$this->reset_state();
		$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = true;
	}

	protected function tearDown(): void {
		$this->reset_state();
		global $wpdb;
		$wpdb = $this->saved_wpdb;
		PlayerCourseStatusWarmApiCountHelper::disable();
		unset(
			$GLOBALS['intersoccer_test_pcs_cache_force_enabled'],
			$GLOBALS['intersoccer_test_pcs_cache_data_version_override'],
			$GLOBALS['intersoccer_test_count_pcs_transient_delete_calls'],
			$GLOBALS['intersoccer_test_pcs_transient_delete_call_count'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'],
			$GLOBALS['intersoccer_test_wc_get_orders_callback']
		);
		parent::tearDown();
	}

	private function reset_state(): void {
		$GLOBALS['intersoccer_test_pcs_transient_store'] = [];
		$GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] = 0;
		unset(
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_batch_chunk_size']
		);
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->course_roster_rows = [];
			$this->counting_wpdb->usermeta_player_rows = [];
			$this->counting_wpdb->user_emails = [];
			$this->counting_wpdb->query_count = 0;
			$this->counting_wpdb->write_query_count = 0;
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function synthetic_row(int $i): array {
		$order_id = 60000 + $i;
		$item_id = 70000 + $i;
		$customer_id = 1000 + ( $i % 5 );
		$venues = ['Geneva Centre', 'Lausanne Ouchy', 'Zurich North'];
		$days = ['Sunday', 'Monday', 'Wednesday'];
		$product_ids = [3000, 3001, 3002];

		return [
			'id' => $i,
			'order_id' => $order_id,
			'order_item_id' => $item_id,
			'activity_type' => 'Course',
			'product_name' => 'Course ' . $i,
			'product_id' => $product_ids[ $i % 3 ],
			'variation_id' => 4000 + ( $i % 3 ),
			'venue' => $venues[ $i % 3 ],
			'course_day' => $days[ $i % 3 ],
			'times' => '10:00',
			'course_times' => '',
			'age_group' => '5-12y',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-01',
			'order_status' => 'completed',
			'customer_id' => $customer_id,
			'player_index' => 0,
			'player_first_name' => 'Player',
			'player_last_name' => 'Number' . $i,
			'girls_only' => 0,
			'is_placeholder' => 0,
		];
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function synthetic_players(int $count): array {
		$players = [];
		for ($i = 1; $i <= $count; $i++) {
			$players[] = [
				'user_id' => 1000 + ( $i % 5 ),
				'user_email' => 'user' . $i . '@example.test',
				'player_id' => 'uuid-' . $i,
				'player_index' => 0,
				'first_name' => 'Player',
				'last_name' => 'Number' . $i,
				'dob' => '2015-01-01',
			];
		}

		return $players;
	}

	private function seed_usermeta_players(int $count): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			return;
		}
		$this->counting_wpdb->usermeta_player_rows = [];
		$this->counting_wpdb->user_emails = [];
		for ($i = 1; $i <= $count; $i++) {
			$uid = 1000 + $i;
			$this->counting_wpdb->usermeta_player_rows[] = [
				'user_id' => $uid,
				'meta_value' => [
					0 => [
						'first_name' => 'Player',
						'last_name' => 'Number' . $i,
						'player_id' => 'uuid-' . $i,
						'dob' => '2015-01-01',
					],
				],
			];
			$this->counting_wpdb->user_emails[ $uid ] = 'user' . $i . '@example.test';
		}
	}

	private function seed_refund_fixture_flags(int $row_count): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			return;
		}
		$this->counting_wpdb->refund_parents = [];
		$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'] = [];
		for ($i = 1; $i <= $row_count; $i++) {
			if ($i % 7 !== 0) {
				continue;
			}
			$order_id = 60000 + $i;
			$item_id = 70000 + $i;
			$this->counting_wpdb->refund_parents[ $order_id ] = true;
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'][ $item_id ] = true;
		}
	}

	/**
	 * @return array{queries:int,inner_calls:int,api:array<string,int>}
	 */
	private function warm_filter_pass(int $row_count, int $player_count): array {
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		$GLOBALS['intersoccer_test_live_order_status_map'] = [];
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [];
		$this->seed_refund_fixture_flags($row_count);
		$rows = [];
		for ($i = 1; $i <= $row_count; $i++) {
			$row = $this->synthetic_row($i);
			$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
			$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
			$rows[] = $row;
		}
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
		$GLOBALS['intersoccer_test_player_course_status_players'] = $this->synthetic_players($player_count);
		$GLOBALS['intersoccer_test_pcs_transient_store'] = [];
		$GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] = 0;

		intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);

		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			return ['queries' => -1, 'inner_calls' => 0, 'api' => []];
		}
		$this->counting_wpdb->query_count = 0;
		PlayerCourseStatusWarmApiCountHelper::enable();
		$GLOBALS['intersoccer_test_count_pcs_transient_delete_calls'] = true;
		$GLOBALS['intersoccer_test_pcs_transient_delete_call_count'] = 0;

		intersoccer_player_course_status_generate([
			'year' => 2026,
			'season_type' => 'Autumn',
			'booked' => 'booked',
		]);

		return [
			'queries' => (int) $this->counting_wpdb->query_count,
			'inner_calls' => (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count'],
			'api' => PlayerCourseStatusWarmApiCountHelper::counts(),
		];
	}

	public function test_warm_filter_path_query_count_independent_of_row_and_player_volume(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$small = $this->warm_filter_pass(10, 10);
		$large = $this->warm_filter_pass(200, 200);
		$this->assertSame(1, $small['inner_calls'], 'warm pass must hit cached year base only');
		$this->assertSame(1, $large['inner_calls'], 'warm pass must hit cached year base only');
		$this->assertSame($small['queries'], $large['queries']);
		$this->assertLessThanOrEqual(self::WARM_QUERY_CEILING, $small['queries']);
	}

	public function test_cold_build_query_count_independent_of_row_volume(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		$run = function (int $row_count) {
			$GLOBALS['intersoccer_test_live_order_status_map'] = [];
			$GLOBALS['intersoccer_test_order_customer_id_map'] = [];
			$rows = [];
			for ($i = 1; $i <= $row_count; $i++) {
				$row = $this->synthetic_row($i);
				$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
				$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
				$rows[] = $row;
			}
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
			$this->seed_usermeta_players(max(10, $row_count));
			unset($GLOBALS['intersoccer_test_player_course_status_players']);
			$this->counting_wpdb->query_count = 0;
			intersoccer_player_course_status_build_year_base_dataset(2026, false);

			return (int) $this->counting_wpdb->query_count;
		};
		$small = $run(10);
		$large = $run(200);
		$this->assertSame($small, $large);
		$this->assertLessThanOrEqual(80, $small);
	}

	public function test_warm_filter_pass_makes_no_transient_delete_calls(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$this->warm_filter_pass(50, 20);
		$this->assertSame(0, (int) ($GLOBALS['intersoccer_test_pcs_transient_delete_call_count'] ?? 0));
	}

	public function test_warm_wordpress_api_calls_constant_across_volume(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$small = $this->warm_filter_pass(10, 10);
		$large = $this->warm_filter_pass(200, 200);
		$this->assertSame($small['api'], $large['api']);
		$this->assertSame(0, (int) ($small['api']['delete_option'] ?? 0));
		$this->assertSame(0, (int) ($small['api']['delete_transient'] ?? 0));
		$this->assertSame(0, (int) ($small['api']['get_transient'] ?? 0));
		$this->assertSame(0, (int) ($small['api']['wc_get_order'] ?? 0));
		$this->assertSame(0, (int) ($small['api']['wc_get_product'] ?? 0));
		$this->assertLessThanOrEqual(self::WARM_API_TOTAL_CEILING, array_sum($small['api']));
	}

	public function test_cold_wordpress_api_calls_constant_across_volume(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$run = function (int $row_count) {
			if (function_exists('intersoccer_rr_product_type_memo_reset')) {
				intersoccer_rr_product_type_memo_reset();
			}
			if (function_exists('intersoccer_player_course_status_reset_run_memos')) {
				intersoccer_player_course_status_reset_run_memos();
			}
			if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
				intersoccer_roster_report_reset_line_refund_check_state();
			}
			if (function_exists('intersoccer_winback_reset_facet_resolution_cache')) {
				intersoccer_winback_reset_facet_resolution_cache();
			}
			$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
			$GLOBALS['intersoccer_test_live_order_status_map'] = [];
			$GLOBALS['intersoccer_test_order_customer_id_map'] = [];
			$this->seed_refund_fixture_flags($row_count);
			$rows = [];
			for ($i = 1; $i <= $row_count; $i++) {
				$row = $this->synthetic_row($i);
				$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
				$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
				$rows[] = $row;
			}
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
			$this->seed_usermeta_players(max(10, $row_count));
			unset($GLOBALS['intersoccer_test_player_course_status_players']);
			$this->counting_wpdb->query_count = 0;
			PlayerCourseStatusWarmApiCountHelper::enable();
			if (function_exists('intersoccer_rr_product_type_memo_begin')) {
				intersoccer_rr_product_type_memo_begin();
			}
			intersoccer_player_course_status_build_year_base_dataset(2026, false);
			if (function_exists('intersoccer_rr_product_type_memo_end')) {
				intersoccer_rr_product_type_memo_end();
			}

			return [
				'queries' => (int) $this->counting_wpdb->query_count,
				'api' => PlayerCourseStatusWarmApiCountHelper::counts(),
			];
		};
		$small = $run(10);
		$large = $run(200);
		$this->assertSame($small['api'], $large['api']);
		$this->assertSame($small['queries'], $large['queries']);
		$this->assertLessThanOrEqual(self::COLD_API_TOTAL_CEILING, array_sum($small['api']));
		$this->assertLessThanOrEqual(3, (int) ($small['api']['wc_get_orders'] ?? 0));
	}

	public function test_different_data_markers_share_one_transient_key_and_mismatch_is_stale(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = $this->synthetic_players(2);
		$row = $this->synthetic_row(1);
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [$row];
		$GLOBALS['intersoccer_test_live_order_status_map'] = [ $row['order_id'] => 'wc-completed' ];
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [ $row['order_id'] => (int) $row['customer_id'] ];

		$GLOBALS['intersoccer_test_pcs_cache_data_version_override'] = 'marker-one';
		intersoccer_player_course_status_generate(['year' => 2026]);
		$key_one = intersoccer_player_course_status_cache_transient_key(2026);
		$this->assertNotNull(intersoccer_player_course_status_cache_read_base(2026));

		$GLOBALS['intersoccer_test_pcs_cache_data_version_override'] = 'marker-two';
		$key_two = intersoccer_player_course_status_cache_transient_key(2026);
		$this->assertSame($key_one, $key_two);
		$this->assertCount(1, $GLOBALS['intersoccer_test_pcs_transient_store']);
		$this->assertNull(intersoccer_player_course_status_cache_read_base(2026));

		$GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] = 0;
		intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
		$this->assertCount(1, $GLOBALS['intersoccer_test_pcs_transient_store']);
		$this->assertNotNull(intersoccer_player_course_status_cache_read_base(2026));
	}
}
