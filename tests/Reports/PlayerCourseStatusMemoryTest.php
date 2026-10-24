<?php
/**
 * Synthetic peak memory: course status generate() (stub-based harness).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/PlayerCourseStatusQueryCountTest.php';

class PlayerCourseStatusMemoryTest extends TestCase {
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
		foreach (['final-reports-aggregation.php', 'utils.php', 'winback-report-facets.php', 'player-camp-status.php', 'player-course-status.php'] as $file) {
			require_once $includes . '/' . $file;
		}
		$this->reset_state();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->saved_wpdb;
		$this->reset_state();
		parent::tearDown();
	}

	private function reset_state(): void {
		unset(
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_batch_chunk_size']
		);
		if (function_exists('intersoccer_player_course_status_reset_run_memos')) {
			intersoccer_player_course_status_reset_run_memos();
		}
	}

	/**
	 * @return array<string,int>
	 */
	private function run_memory_slice(int $player_count, int $row_count): array {
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		$GLOBALS['intersoccer_test_live_order_status_map'] = [];
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [];

		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->usermeta_player_rows = [];
			$this->counting_wpdb->user_emails = [];
			for ($i = 1; $i <= $player_count; $i++) {
				$uid = 4000 + $i;
				$this->counting_wpdb->usermeta_player_rows[] = [
					'user_id' => $uid,
					'meta_value' => [
						0 => [
							'first_name' => 'P',
							'last_name' => 'N' . $i,
							'player_id' => 'uuid-mem-' . $i,
							'dob' => '2015-01-01',
						],
					],
				];
				$this->counting_wpdb->user_emails[ $uid ] = 'mem' . $i . '@example.test';
			}
		}

		$rows = [];
		for ($i = 1; $i <= $row_count; $i++) {
			$order_id = 80000 + $i;
			$item_id = 81000 + $i;
			$customer_id = 4000 + ( $i % max(1, $player_count) ) + 1;
			$rows[] = [
				'order_id' => $order_id,
				'order_item_id' => $item_id,
				'activity_type' => 'Course',
				'product_name' => 'Course ' . $i,
				'variation_id' => 4000 + $i,
				'venue' => 'Geneva Centre',
				'course_day' => 'sunday',
				'times' => '10:00',
				'course_times' => '',
				'age_group' => '5-12y',
				'season' => 'Autumn 2026',
				'start_date' => '2026-09-01',
				'end_date' => '2026-12-01',
				'order_status' => $i % 7 === 0 ? 'pending' : 'completed',
				'customer_id' => $customer_id,
				'player_index' => 0,
				'player_first_name' => 'P',
				'player_last_name' => 'N' . $i,
				'girls_only' => 0,
				'is_placeholder' => 0,
			];
			$GLOBALS['intersoccer_test_live_order_status_map'][ $order_id ] = 'wc-completed';
			$GLOBALS['intersoccer_test_order_customer_id_map'][ $order_id ] = $customer_id;
		}
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
		unset($GLOBALS['intersoccer_test_player_course_status_players']);

		$before = memory_get_usage(true);
		$peak_before = memory_get_peak_usage(true);
		intersoccer_player_course_status_generate(['year' => 2026]);
		$after = memory_get_usage(true);
		$peak_after = memory_get_peak_usage(true);

		return [
			'delta_bytes' => $after - $before,
			'peak_delta_bytes' => $peak_after - $peak_before,
			'peak_after' => $peak_after,
		];
	}

	public function test_synthetic_memory_harness_reports_peak_delta(): void {
		$small = $this->run_memory_slice(100, 100);
		$this->reset_state();
		$large = $this->run_memory_slice(4000, 5000);

		$this->assertGreaterThan(0, $small['peak_after']);
		$this->assertGreaterThan(0, $large['peak_after']);
		$this->assertGreaterThanOrEqual($small['peak_after'], $large['peak_after']);
		$this->assertLessThan(512 * 1024 * 1024, $large['peak_after']);
	}
}
