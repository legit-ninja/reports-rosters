<?php
/**
 * PCS + win-back cold builds: batched priming, zero per-row PV / wc_get_product calls.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/PlayerCourseStatusQueryCountTest.php';
require_once __DIR__ . '/PlayerCourseStatusWarmApiCountHelper.php';
require_once __DIR__ . '/WinbackReportQueryCountTest.php';

class PlayerCourseStatusColdPathBatchTest extends TestCase {
	/** @var int */
	private const BATCH_TERM_READ_CEILING = 2;

	/** @var int */
	private const PV_CALL_CEILING = 0;

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
		foreach (['reports-data.php', 'final-reports-aggregation.php', 'utils.php', 'roster-report-loader.php', 'winback-report-facets.php', 'winback-report-cache.php', 'winback-catalogue.php', 'winback-report-engine.php', 'player-camp-status.php', 'player-course-status.php'] as $file) {
			require_once $includes . '/' . $file;
		}
		require_once $includes . '/winback-report-perf.php';
		$GLOBALS['intersoccer_test_get_product_type_calls'] = [];
		$this->reset_pcs_state();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->saved_wpdb;
		PlayerCourseStatusWarmApiCountHelper::disable();
		unset($GLOBALS['intersoccer_test_get_product_type_calls']);
		parent::tearDown();
	}

	private function reset_pcs_state(): void {
		$GLOBALS['intersoccer_test_get_product_type_calls'] = [];
		unset(
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_rr_product_type_batch_load_call_count'],
			$GLOBALS['intersoccer_rr_product_type_batch_term_read_calls']
		);
		if (function_exists('intersoccer_rr_product_type_memo_reset')) {
			intersoccer_rr_product_type_memo_reset();
		}
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->query_count = 0;
		}
	}

	private function pv_call_total(): int {
		$calls = $GLOBALS['intersoccer_test_get_product_type_calls'] ?? [];
		if (!is_array($calls)) {
			return 0;
		}

		return (int) array_sum($calls);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function pcs_synthetic_row(int $i): array {
		$product_ids = [3000, 3001, 3002, 3003, 3004];
		return [
			'id' => $i,
			'order_id' => 60000 + $i,
			'order_item_id' => 70000 + $i,
			'activity_type' => 'Course',
			'product_name' => 'Course ' . $i,
			'product_id' => $product_ids[ $i % count($product_ids) ],
			'variation_id' => 4000 + ( $i % 5 ),
			'venue' => 'Geneva Centre',
			'course_day' => 'Monday',
			'times' => '10:00',
			'age_group' => '5-12y',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-01',
			'order_status' => 'completed',
			'customer_id' => 1000 + ( $i % 5 ),
			'player_index' => 0,
			'player_first_name' => 'Player',
			'player_last_name' => 'N' . $i,
			'girls_only' => 0,
			'is_placeholder' => 0,
		];
	}

	/**
	 * @return array{pv:int,batch_load:int,term_reads:int,api:array<string,int>}
	 */
	private function run_pcs_cold_build(int $row_count): array {
		$this->reset_pcs_state();
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		for ($pid = 3000; $pid <= 3004; $pid++) {
			\InterSoccerRRTestMeta::$data[ $pid ]['_intersoccer_product_type'] = 'course';
		}
		for ($vid = 4000; $vid <= 4004; $vid++) {
			\InterSoccerRRTestMeta::$data[ $vid ]['_intersoccer_product_type'] = 'course';
		}
		$rows = [];
		for ($i = 1; $i <= $row_count; $i++) {
			$row = $this->pcs_synthetic_row($i);
			$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
			$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
			$rows[] = $row;
		}
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
		$players = [];
		for ($p = 0; $p < max(10, $row_count); $p++) {
			$players[] = [
				'user_id' => 1000 + ( $p % 5 ),
				'player_id' => 'uuid-' . $p,
				'first_name' => 'P',
				'last_name' => (string) $p,
			];
		}
		$GLOBALS['intersoccer_test_player_course_status_players'] = $players;

		PlayerCourseStatusWarmApiCountHelper::enable();
		if (function_exists('intersoccer_rr_product_type_memo_begin')) {
			intersoccer_rr_product_type_memo_begin();
		}
		intersoccer_player_course_status_build_year_base_dataset(2026, false);
		if (function_exists('intersoccer_rr_product_type_memo_end')) {
			intersoccer_rr_product_type_memo_end();
		}

		return [
			'pv' => $this->pv_call_total(),
			'batch_load' => (int) ( $GLOBALS['intersoccer_rr_product_type_batch_load_call_count'] ?? 0 ),
			'term_reads' => (int) ( $GLOBALS['intersoccer_rr_product_type_batch_term_read_calls'] ?? 0 ),
			'api' => PlayerCourseStatusWarmApiCountHelper::counts(),
		];
	}

	public function test_pcs_cold_build_uses_single_batch_load_and_no_pv_calls(): void {
		$small = $this->run_pcs_cold_build(10);
		$large = $this->run_pcs_cold_build(200);
		$this->assertSame($small['pv'], $large['pv']);
		$this->assertSame($small['batch_load'], $large['batch_load']);
		$this->assertSame($small['term_reads'], $large['term_reads']);
		$this->assertSame($small['api']['wp_get_object_terms'], $large['api']['wp_get_object_terms']);
		$this->assertLessThanOrEqual(self::PV_CALL_CEILING, $small['pv']);
		$this->assertSame(1, $small['batch_load']);
		$this->assertLessThanOrEqual(self::BATCH_TERM_READ_CEILING, $small['term_reads']);
		$this->assertSame(0, (int) ( $small['api']['wc_get_product'] ?? 0 ));
		$this->assertLessThanOrEqual(2, (int) ( $small['api']['wp_get_object_terms'] ?? 0 ));
	}

	/**
	 * Win-back cold product-type path: prime + per-row kind resolution (engine scope).
	 *
	 * @return array{pv:int,batch_load:int,term_reads:int,api:array<string,int>}
	 */
	private function run_winback_cold_product_type_path(int $row_count): array {
		$this->reset_pcs_state();
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		for ($parent = 1001; $parent <= 1005; $parent++) {
			\InterSoccerRRTestMeta::$data[ $parent ]['_intersoccer_product_type'] = ($parent % 2 === 1) ? 'camp' : 'course';
		}
		$parent_ids = [1001, 1002, 1003, 1004, 1005];
		$variation_ids = [2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2010];
		$rows = [];
		for ($i = 0; $i < $row_count; $i++) {
			$rows[] = [
				'id' => $i + 1,
				'order_item_id' => 70000 + $i,
				'product_id' => $parent_ids[ $i % 5 ],
				'variation_id' => $variation_ids[ $i % 10 ],
				'activity_type' => $i % 2 === 0 ? 'Camp' : 'Course',
				'order_id' => 80000 + $i,
				'order_status' => 'completed',
			];
		}

		PlayerCourseStatusWarmApiCountHelper::enable();
		intersoccer_rr_product_type_memo_begin();
		intersoccer_rr_winback_prime_product_types_from_rows($rows);
		foreach ($rows as $row) {
			intersoccer_winback_report_engine_row_kind($row);
			intersoccer_winback_resolve_row_event_date($row);
			intersoccer_roster_report_row_counts_for_winback($row, 'history');
		}
		intersoccer_rr_product_type_memo_end();

		return [
			'pv' => $this->pv_call_total(),
			'batch_load' => (int) ( $GLOBALS['intersoccer_rr_product_type_batch_load_call_count'] ?? 0 ),
			'term_reads' => (int) ( $GLOBALS['intersoccer_rr_product_type_batch_term_read_calls'] ?? 0 ),
			'api' => PlayerCourseStatusWarmApiCountHelper::counts(),
		];
	}

	public function test_winback_cold_product_type_path_uses_single_batch_and_no_pv_calls(): void {
		$small = $this->run_winback_cold_product_type_path(10);
		$large = $this->run_winback_cold_product_type_path(200);
		$this->assertSame($small['pv'], $large['pv']);
		$this->assertSame($small['batch_load'], $large['batch_load']);
		$this->assertSame($small['term_reads'], $large['term_reads']);
		$this->assertSame($small['api']['wp_get_object_terms'], $large['api']['wp_get_object_terms']);
		$this->assertLessThanOrEqual(self::PV_CALL_CEILING, $small['pv']);
		$this->assertSame(1, $small['batch_load']);
		$this->assertLessThanOrEqual(self::BATCH_TERM_READ_CEILING, $small['term_reads']);
		$this->assertSame(0, (int) ( $small['api']['wc_get_product'] ?? 0 ));
	}
}
