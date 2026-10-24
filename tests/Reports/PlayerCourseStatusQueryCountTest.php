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

	/** @var array<int,array{user_id:int,meta_value:string}> */
	public $usermeta_player_rows = [];

	/** @var array<int,string> */
	public $user_emails = [];

	/** @var int */
	public $write_query_count = 0;

	/** @var array<string,int> */
	public $query_label_histogram = [];

	/** @var string[] */
	public $roster_columns = [
		'order_id', 'order_item_id', 'player_first_name', 'player_last_name',
		'product_name', 'variation_id', 'venue', 'course_day', 'times', 'course_times', 'age_group',
		'season', 'start_date', 'end_date', 'activity_type', 'order_status', 'customer_id', 'player_index',
		'girls_only', 'is_placeholder',
	];

	private function count_query($query = null): void {
		$this->query_count++;
		if ($query !== null && $query !== '') {
			$sql = (string) $query;
			$this->last_query = $sql;
			if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) {
				$this->write_query_count++;
			}
			$label = $this->label_query_origin();
			if ($label !== '') {
				if (!isset($this->query_label_histogram[ $label ])) {
					$this->query_label_histogram[ $label ] = 0;
				}
				$this->query_label_histogram[ $label ]++;
			}
		}
	}

	/**
	 * @return int[]
	 */
	private function extract_in_list_ints(string $sql): array {
		if (!preg_match('/\bIN\s*\(([^)]+)\)/i', $sql, $m)) {
			return [];
		}
		preg_match_all('/\b(\d+)\b/', $m[1], $nums);

		return array_map('intval', $nums[1] ?? []);
	}

	private function label_query_origin(): string {
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
		foreach ($trace as $frame) {
			if (!isset($frame['file'])) {
				continue;
			}
			$file = (string) $frame['file'];
			if (strpos($file, 'player-course-status.php') === false
				&& strpos($file, 'roster-report-loader.php') === false
				&& strpos($file, 'winback-report-engine.php') === false) {
				continue;
			}
			$fn = isset($frame['function']) ? (string) $frame['function'] : '';
			return basename($file) . ':' . $fn;
		}

		return '';
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
		if (stripos($sql, 'meta_key = \'intersoccer_players\'') !== false
			|| (stripos($sql, 'intersoccer_players') !== false && stripos($sql, $this->usermeta) !== false)) {
			return $this->usermeta_player_rows;
		}
		if (stripos($sql, 'user_email') !== false && stripos($sql, $this->users) !== false) {
			$ids = $this->extract_in_list_ints($sql);
			$rows = [];
			foreach ($ids as $id) {
				if (!isset($this->user_emails[ $id ])) {
					continue;
				}
				$rows[] = [
					'ID' => $id,
					'user_email' => $this->user_emails[ $id ],
				];
			}

			return $rows;
		}
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
			$GLOBALS['intersoccer_test_batch_chunk_size'],
			$GLOBALS['intersoccer_rr_user_account_email_cache']
		);
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->course_roster_rows = [];
			$this->counting_wpdb->usermeta_player_rows = [];
			$this->counting_wpdb->user_emails = [];
			$this->counting_wpdb->write_query_count = 0;
			$this->counting_wpdb->query_label_histogram = [];
		}
		if (function_exists('intersoccer_player_course_status_reset_run_memos')) {
			intersoccer_player_course_status_reset_run_memos();
		}
		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
		if (function_exists('intersoccer_roster_report_reset_order_customer_id_cache')) {
			intersoccer_roster_report_reset_order_customer_id_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_order_item_meta_cache')) {
			intersoccer_roster_report_reset_order_item_meta_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		if (function_exists('intersoccer_winback_reset_facet_resolution_cache')) {
			intersoccer_winback_reset_facet_resolution_cache();
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
			'order_status' => $i % 9 === 0 ? 'pending' : ( $i % 11 === 0 ? 'on-hold' : 'completed' ),
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
	 */
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

	/**
	 * @param int $row_count
	 */
	private function seed_roster_rows(int $row_count): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			return;
		}
		$this->counting_wpdb->course_roster_rows = [];
		$GLOBALS['intersoccer_test_live_order_status_map'] = [];
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [];
		for ($i = 1; $i <= $row_count; $i++) {
			$row = $this->synthetic_row($i);
			$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
			$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
			$this->counting_wpdb->course_roster_rows[] = $row;
		}
	}

	public function test_load_course_roster_query_count_independent_of_row_count(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;

		$this->seed_roster_rows(10);
		$this->counting_wpdb->query_count = 0;
		intersoccer_player_course_status_load_course_roster_rows(2026);
		$small_queries = $this->counting_wpdb->query_count;

		$this->seed_roster_rows(100);
		$this->counting_wpdb->query_count = 0;
		intersoccer_player_course_status_load_course_roster_rows(2026);
		$large_queries = $this->counting_wpdb->query_count;

		$this->assertSame($small_queries, $large_queries);
		$this->assertGreaterThan(0, $small_queries);
	}

	public function test_generate_query_count_independent_of_player_count_with_batched_loader(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		$this->seed_roster_rows(50);
		unset($GLOBALS['intersoccer_test_player_course_status_players'], $GLOBALS['intersoccer_test_player_course_status_roster_rows']);

		$this->seed_usermeta_players(10);
		$this->counting_wpdb->query_count = 0;
		$this->counting_wpdb->write_query_count = 0;
		intersoccer_player_course_status_generate(['year' => 2026]);
		$small = (int) $this->counting_wpdb->query_count;
		$writes_small = (int) $this->counting_wpdb->write_query_count;

		$this->seed_usermeta_players(100);
		$this->counting_wpdb->query_count = 0;
		$this->counting_wpdb->write_query_count = 0;
		intersoccer_player_course_status_generate(['year' => 2026]);
		$large = (int) $this->counting_wpdb->query_count;
		$writes_large = (int) $this->counting_wpdb->write_query_count;

		$this->assertSame($small, $large);
		$this->assertSame(0, $writes_small);
		$this->assertSame(0, $writes_large);
	}

	public function test_generate_query_count_independent_of_roster_row_count_with_fixture_rows(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		$this->seed_usermeta_players(20);
		unset($GLOBALS['intersoccer_test_player_course_status_players']);

		$run = function (int $row_count) {
			$rows = [];
			$GLOBALS['intersoccer_test_live_order_status_map'] = [];
			$GLOBALS['intersoccer_test_order_customer_id_map'] = [];
			for ($i = 1; $i <= $row_count; $i++) {
				$row = $this->synthetic_row($i);
				$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
				$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
				$rows[] = $row;
			}
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
			$this->counting_wpdb->query_count = 0;
			$this->counting_wpdb->write_query_count = 0;
			intersoccer_player_course_status_generate(['year' => 2026]);

			return [
				'queries' => (int) $this->counting_wpdb->query_count,
				'writes' => (int) $this->counting_wpdb->write_query_count,
			];
		};

		$small = $run(10);
		$large = $run(100);
		$this->assertSame($small['queries'], $large['queries']);
		$this->assertSame(0, $small['writes']);
		$this->assertSame(0, $large['writes']);
	}

	public function test_generate_with_fixture_rows_query_count_independent_of_volume(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;

		$run = function (int $row_count) {
			$rows = [];
			$GLOBALS['intersoccer_test_live_order_status_map'] = [];
			$GLOBALS['intersoccer_test_order_customer_id_map'] = [];
			for ($i = 1; $i <= $row_count; $i++) {
				$row = $this->synthetic_row($i);
				$GLOBALS['intersoccer_test_live_order_status_map'][ $row['order_id'] ] = 'wc-completed';
				$GLOBALS['intersoccer_test_order_customer_id_map'][ $row['order_id'] ] = (int) $row['customer_id'];
				$rows[] = $row;
			}
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
			$players = [];
			for ($i = 1; $i <= $row_count; $i++) {
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
			$GLOBALS['intersoccer_test_player_course_status_players'] = $players;
			$this->counting_wpdb->query_count = 0;
			intersoccer_player_course_status_generate(['year' => 2026]);

			return (int) $this->counting_wpdb->query_count;
		};

		$small = $run(10);
		$large = $run(100);
		$this->assertSame($small, $large);
	}
}
