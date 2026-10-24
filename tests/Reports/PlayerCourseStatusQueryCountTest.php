<?php
/**
 * Player course status: query count must not scale with player/row volume.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/WinbackReportQueryCountTest.php';

class PlayerCourseStatusCountingWpdb extends WinbackReportCountingWpdb {
	/** @var array<int,array<string,mixed>> */
	public $course_roster_rows = [];

	/** @var string[] */
	public $roster_columns = [
		'id', 'order_id', 'order_item_id', 'first_name', 'last_name', 'player_first_name', 'player_last_name',
		'product_name', 'product_id', 'variation_id', 'venue', 'course_day', 'times', 'course_times', 'age_group',
		'season', 'start_date', 'end_date', 'activity_type', 'order_status', 'customer_id', 'player_index',
		'girls_only', 'is_placeholder',
	];

	private function count_query($query = null): void {
		$this->query_count++;
		if ($query !== null && $query !== '') {
			$this->last_query = (string) $query;
		}
	}

	public function get_var($query = null, $x = 0, $y = 0) {
		$this->count_query($query);
		$sql = (string) $query;
		if (stripos($sql, 'SHOW TABLES') !== false) {
			return $this->prefix . 'intersoccer_rosters';
		}

		return parent::get_var($query, $x, $y);
	}

	public function get_col($query = null, $x = 0, $y = 0) {
		$this->count_query($query);
		$sql = (string) $query;
		if (stripos($sql, 'DESCRIBE') !== false && stripos($sql, 'intersoccer_rosters') !== false) {
			return $this->roster_columns;
		}

		return parent::get_col($query, $x, $y);
	}

	public function get_results($query = null, $output = OBJECT) {
		$this->count_query($query);
		$sql = (string) $query;
		if (stripos($sql, 'FROM ' . $this->prefix . 'intersoccer_rosters') !== false
			&& stripos($sql, 'SELECT') !== false) {
			return $this->course_roster_rows;
		}

		return parent::get_results($query, $output);
	}
}

class PlayerCourseStatusQueryCountTest extends TestCase {
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
			$GLOBALS['intersoccer_test_order_item_meta'],
			$GLOBALS['intersoccer_test_batch_chunk_size']
		);
		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
		if (function_exists('intersoccer_roster_report_reset_order_customer_id_cache')) {
			intersoccer_roster_report_reset_order_customer_id_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_order_item_meta_cache')) {
			intersoccer_roster_report_reset_order_item_meta_cache();
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function synthetic_row(int $i): array {
		$order_id = 60000 + $i;
		$item_id = 70000 + $i;
		$customer_id = 1000 + ($i % 5);

		return [
			'id' => $i,
			'order_id' => $order_id,
			'order_item_id' => $item_id,
			'activity_type' => 'Course',
			'product_name' => 'Course ' . $i,
			'product_id' => 3000,
			'variation_id' => 4000 + $i,
			'venue' => 'Geneva Centre',
			'course_day' => 'sunday',
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
	 * @param int $count
	 * @return array<int,array<string,mixed>>
	 */
	private function synthetic_players(int $count): array {
		$players = [];
		for ($i = 1; $i <= $count; $i++) {
			$players[] = [
				'user_id' => 1000 + ($i % 5),
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

	public function test_load_course_roster_query_count_independent_of_row_count(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		$GLOBALS['intersoccer_test_live_order_status_map'] = [];
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [];

		$this->counting_wpdb->course_roster_rows = [];
		for ($i = 1; $i <= 10; $i++) {
			$row = $this->synthetic_row($i);
			$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
			$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
			$this->counting_wpdb->course_roster_rows[] = $row;
		}
		$this->counting_wpdb->query_count = 0;
		intersoccer_player_course_status_load_course_roster_rows(2026);
		$small_queries = $this->counting_wpdb->query_count;

		$this->counting_wpdb->course_roster_rows = [];
		for ($i = 1; $i <= 100; $i++) {
			$row = $this->synthetic_row($i);
			$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
			$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
			$this->counting_wpdb->course_roster_rows[] = $row;
		}
		$this->counting_wpdb->query_count = 0;
		intersoccer_player_course_status_load_course_roster_rows(2026);
		$large_queries = $this->counting_wpdb->query_count;

		$this->assertSame($small_queries, $large_queries);
		$this->assertGreaterThan(0, $small_queries);
	}

	public function test_generate_with_fixture_rows_query_count_independent_of_volume(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		$GLOBALS['intersoccer_test_live_order_status_map'] = [];
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [];

		$run = function (int $row_count) {
			$rows = [];
			for ($i = 1; $i <= $row_count; $i++) {
				$row = $this->synthetic_row($i);
				$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
				$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
				$rows[] = $row;
			}
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
			$GLOBALS['intersoccer_test_player_course_status_players'] = $this->synthetic_players($row_count);
			$this->counting_wpdb->query_count = 0;
			intersoccer_player_course_status_generate(['year' => 2026]);

			return (int) $this->counting_wpdb->query_count;
		};

		$small = $run(10);
		$large = $run(100);
		$this->assertSame($small, $large);
	}
}
