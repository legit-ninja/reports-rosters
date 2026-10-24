<?php
/**
 * Player course status: strict-mode DATE SQL and roster query failure warnings.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/PlayerCourseStatusQueryCountTest.php';

class PlayerCourseStatusStrictDateWpdb extends PlayerCourseStatusCountingWpdb {
	/** @var string */
	public $last_error = '';

	/** @var array<int,array<string,mixed>>|null */
	public $forced_results = null;

	public function get_results($query = null, $output = OBJECT) {
		$this->query_count++;
		if ($query !== null && $query !== '') {
			$this->last_query = (string) $query;
		}
		$sql = (string) $query;
		if (stripos($sql, 'FROM ' . $this->prefix . 'intersoccer_rosters') !== false
			&& stripos($sql, 'SELECT') !== false) {
			if ($this->last_error !== '') {
				return null;
			}
			if ($this->forced_results !== null) {
				return $this->forced_results;
			}

			return $this->course_roster_rows;
		}

		return parent::get_results($query, $output);
	}
}

class PlayerCourseStatusStrictDateTest extends TestCase {
	/** @var PlayerCourseStatusStrictDateWpdb|null */
	private $wpdb_stub = null;

	/** @var object|null */
	private $saved_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->saved_wpdb = $wpdb;
		$this->wpdb_stub = new PlayerCourseStatusStrictDateWpdb();
		$wpdb = $this->wpdb_stub;

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
			$GLOBALS['intersoccer_test_player_course_status_error_log'],
			$GLOBALS['intersoccer_test_live_order_status_map']
		);
		if ($this->wpdb_stub !== null) {
			$this->wpdb_stub->last_error = '';
			$this->wpdb_stub->forced_results = null;
			$this->wpdb_stub->course_roster_rows = [];
			$this->wpdb_stub->last_query = '';
		}
		if (function_exists('intersoccer_player_course_status_reset_roster_query_state')) {
			intersoccer_player_course_status_reset_roster_query_state();
		}
		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function roster_row(array $overrides = []): array {
		return array_merge([
			'id' => 1,
			'order_id' => 6001,
			'order_item_id' => 7001,
			'activity_type' => 'Course',
			'product_name' => 'Autumn Skills',
			'product_id' => 3000,
			'variation_id' => 4001,
			'venue' => 'Geneva Centre',
			'course_day' => 'sunday',
			'times' => '10:00',
			'course_times' => '',
			'age_group' => '5-12y',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-01',
			'order_status' => 'completed',
			'customer_id' => 100,
			'player_index' => 0,
			'player_first_name' => 'Test',
			'player_last_name' => 'Player',
			'girls_only' => 0,
			'is_placeholder' => 0,
		], $overrides);
	}

	public function test_course_roster_sql_is_strict_mode_safe_with_year_bounds(): void {
		intersoccer_player_course_status_load_course_roster_rows(2026);
		$sql = (string) $this->wpdb_stub->last_query;
		$this->assertNotSame('', $sql);
		$this->assertDoesNotMatchRegularExpression(
			"/(start_date|end_date)\s*(=|<>|!=)\s*'(|N\\/A|0000-00-00)'/i",
			$sql
		);
		$this->assertDoesNotMatchRegularExpression('/LEFT\\(start_date/i', $sql);
		$this->assertDoesNotMatchRegularExpression('/LEFT\\(end_date/i', $sql);
		$this->assertStringContainsString('start_date IS NULL', $sql);
		$this->assertStringContainsString("BETWEEN '2025-01-01'", $sql);
		$this->assertStringContainsString("AND '2027-12-31'", $sql);
	}

	public function test_roster_query_failure_surfaces_warning_and_logs(): void {
		$this->wpdb_stub->last_error = 'Incorrect DATE value';
		$GLOBALS['intersoccer_test_player_course_status_players'] = [];
		unset($GLOBALS['intersoccer_test_player_course_status_roster_rows']);

		$result = intersoccer_player_course_status_generate(['year' => 2026]);

		$this->assertNotEmpty($result['warnings']);
		$this->assertSame('roster_query_failed', $result['warnings'][0]['code']);
		$this->assertNotEmpty($result['warnings'][0]['message']);
		$this->assertNotEmpty($GLOBALS['intersoccer_test_player_course_status_error_log']);
		$this->assertStringContainsString(
			'roster query failed',
			(string) $GLOBALS['intersoccer_test_player_course_status_error_log'][0]
		);
	}

	public function test_roster_query_success_has_empty_warnings(): void {
		$this->wpdb_stub->course_roster_rows = [$this->roster_row()];
		$GLOBALS['intersoccer_test_player_course_status_players'] = [];
		unset($GLOBALS['intersoccer_test_player_course_status_roster_rows']);

		$result = intersoccer_player_course_status_generate(['year' => 2026]);

		$this->assertSame([], $result['warnings']);
	}

	public function test_null_1970_and_in_range_dates_still_scope_to_year(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->roster_row(['order_item_id' => 7010, 'start_date' => null, 'end_date' => null, 'season' => 'Autumn 2026']),
			$this->roster_row(['order_item_id' => 7011, 'start_date' => '1970-01-01', 'end_date' => '1970-01-01', 'season' => 'Winter 2026']),
			$this->roster_row(['order_item_id' => 7012, 'start_date' => '2025-11-01', 'end_date' => '2026-03-01', 'season' => 'Winter 2025/26']),
			$this->roster_row(['order_item_id' => 7013, 'start_date' => '2024-01-01', 'end_date' => '2024-06-01', 'season' => 'Winter 2024']),
		];
		$scoped = intersoccer_player_course_status_filter_scoped_rows(
			intersoccer_player_course_status_prepare_roster_rows($GLOBALS['intersoccer_test_player_course_status_roster_rows']),
			2026,
			'',
			'all'
		);
		$item_ids = array_map(
			static function ($row) {
				return (int) ($row['order_item_id'] ?? 0);
			},
			$scoped
		);
		$this->assertContains(7010, $item_ids);
		$this->assertContains(7011, $item_ids);
		$this->assertContains(7012, $item_ids);
		$this->assertNotContains(7013, $item_ids);
	}
}
