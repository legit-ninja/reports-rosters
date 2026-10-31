<?php
/**
 * Win-back runtime object-cache flush helper and engine phase boundaries.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackReportRuntimeCacheFlushTest extends TestCase {
	/** @var object|null */
	private $saved_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/WinbackReportQueryCountTest.php';

		global $wpdb;
		$this->saved_wpdb = $wpdb;
		if (!($wpdb instanceof WinbackReportCountingWpdb)) {
			$wpdb = new WinbackReportCountingWpdb();
		}

		$includes = dirname(__DIR__, 2) . '/includes';
		require_once $includes . '/roster-report-loader.php';
		require_once $includes . '/winback-report-perf.php';
		require_once $includes . '/utils.php';
		require_once $includes . '/winback-report-facets.php';
		require_once $includes . '/winback-catalogue.php';
		require_once $includes . '/winback-report-engine.php';
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['intersoccer_test_wp_cache_supports_map'],
			$GLOBALS['intersoccer_test_wp_cache_flush_runtime_called'],
			$GLOBALS['intersoccer_test_runtime_cache_flush_call_count'],
			$GLOBALS['intersoccer_test_winback_history_rows'],
			$GLOBALS['intersoccer_test_winback_upcoming_roster_rows'],
			$GLOBALS['intersoccer_test_winback_upcoming_catalogue'],
			$GLOBALS['intersoccer_test_winback_facet_resolution_callback'],
			$GLOBALS['intersoccer_test_get_userdata']
		);
		global $wpdb;
		if ($this->saved_wpdb !== null) {
			$wpdb = $this->saved_wpdb;
		}
		$this->saved_wpdb = null;
		parent::tearDown();
	}

	public function test_runtime_cache_flush_skips_wp_cache_flush_runtime_when_not_supported(): void {
		$GLOBALS['intersoccer_test_wp_cache_supports_map'] = ['flush_runtime' => false];
		unset($GLOBALS['intersoccer_test_wp_cache_flush_runtime_called']);

		intersoccer_winback_report_engine_runtime_cache_flush();

		$this->assertArrayNotHasKey('intersoccer_test_wp_cache_flush_runtime_called', $GLOBALS);
	}

	public function test_runtime_cache_flush_calls_wp_cache_flush_runtime_when_supported(): void {
		$GLOBALS['intersoccer_test_wp_cache_supports_map'] = ['flush_runtime' => true];
		unset($GLOBALS['intersoccer_test_wp_cache_flush_runtime_called']);

		intersoccer_winback_report_engine_runtime_cache_flush();

		$this->assertTrue(!empty($GLOBALS['intersoccer_test_wp_cache_flush_runtime_called']));
	}

	/**
	 * @param int $row_count
	 * @return array<int,array<string,mixed>>
	 */
	private function camp_history_fixture(int $row_count): array {
		$rows = [];
		for ($i = 1; $i <= $row_count; $i++) {
			$customer_id = 2000 + $i;
			$rows[] = [
				'id' => $i,
				'is_placeholder' => 0,
				'customer_id' => $customer_id,
				'player_index' => 0,
				'order_id' => 50000 + $i,
				'order_item_id' => 60000 + $i,
				'order_status' => 'completed',
				'activity_type' => 'Camp',
				'product_id' => 1001,
				'variation_id' => 2001,
				'player_first_name' => 'Player',
				'player_last_name' => 'N' . $i,
				'player_dob' => '2015-06-01',
				'venue' => 'test-venue',
				'age_group' => '5-12y',
				'start_date' => '2026-03-01',
				'end_date' => '2026-03-05',
				'parent_email' => 'parent' . $i . '@example.test',
				'parent_phone' => '+410000000' . $i,
			];
		}

		return $rows;
	}

	private function seed_generate_fixtures(array $history_rows): void {
		global $wpdb;
		if ($wpdb instanceof WinbackReportCountingWpdb) {
			$wpdb->postmeta_customer_user = [];
			$wpdb->shop_orders = [];
			$wpdb->existing_user_ids = [];
			$wpdb->user_billing_phone = [];
			foreach ($history_rows as $row) {
				$oid = (int) $row['order_id'];
				$cid = (int) $row['customer_id'];
				$wpdb->postmeta_customer_user[ $oid ] = (string) $cid;
				$wpdb->shop_orders[ $oid ] = [
					'post_status' => 'wc-completed',
					'post_date' => '2026-01-15 10:00:00',
				];
				$wpdb->existing_user_ids[ $cid ] = true;
				$wpdb->user_billing_phone[ $cid ] = (string) ( $row['parent_phone'] ?? '' );
			}
		}

		$GLOBALS['intersoccer_test_get_userdata'] = [];
		foreach ($history_rows as $row) {
			$cid = (int) $row['customer_id'];
			if ($cid <= 0) {
				continue;
			}
			$GLOBALS['intersoccer_test_get_userdata'][ $cid ] = (object) [
				'ID' => $cid,
				'user_email' => 'acct' . $cid . '@example.test',
			];
		}

		$GLOBALS['intersoccer_test_winback_facet_resolution_callback'] = static function (array $row, $kind) {
			$venue = sanitize_title((string) ( $row['venue'] ?? '' ));
			$age = sanitize_title((string) ( $row['age_group'] ?? '' ));

			return $kind === 'venue' ? $venue : $age;
		};

		$GLOBALS['intersoccer_test_winback_upcoming_catalogue'] = [
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
		$GLOBALS['intersoccer_test_winback_upcoming_roster_rows'] = [];
	}

	private function run_generate_and_count_flushes(int $history_row_count): int {
		$history = $this->camp_history_fixture($history_row_count);
		$this->seed_generate_fixtures($history);
		$GLOBALS['intersoccer_test_winback_history_rows'] = $history;
		$GLOBALS['intersoccer_test_runtime_cache_flush_call_count'] = 0;
		$GLOBALS['intersoccer_test_wp_cache_supports_map'] = ['flush_runtime' => true];

		intersoccer_winback_report_engine_generate([
			'kind' => 'camp',
			'lookback_months' => 24,
		]);

		return (int) ( $GLOBALS['intersoccer_test_runtime_cache_flush_call_count'] ?? 0 );
	}

	public function test_engine_generate_flush_count_is_constant_not_proportional_to_history_rows(): void {
		$flush_300 = $this->run_generate_and_count_flushes(300);
		$flush_600 = $this->run_generate_and_count_flushes(600);

		$this->assertGreaterThan(0, $flush_300);
		$this->assertLessThanOrEqual(3, $flush_300, 'Expected phase-boundary flushes only, not per-row history/output loops');
		$this->assertSame($flush_300, $flush_600, 'Flush count should not grow with row volume');
	}

	public function test_engine_generate_flush_count_constant_across_multi_chunk_history(): void {
		$flush_small = $this->run_generate_and_count_flushes(300);
		$flush_multi_chunk = $this->run_generate_and_count_flushes(2500);

		$this->assertSame(2, $flush_small, 'Phase-boundary flushes only (after aggregation and after output)');
		$this->assertSame($flush_small, $flush_multi_chunk, 'Flush count must not grow with chunked history volume');
	}
}

}
