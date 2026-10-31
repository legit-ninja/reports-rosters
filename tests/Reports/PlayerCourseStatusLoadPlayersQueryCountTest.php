<?php
/**
 * Player course status load_players: batched usermeta/users reads, no writes.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/PlayerCourseStatusQueryCountTest.php';

class PlayerCourseStatusLoadPlayersQueryCountTest extends TestCase {
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
			$GLOBALS['intersoccer_rr_user_account_email_cache'],
			$GLOBALS['intersoccer_test_batch_chunk_size']
		);
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->usermeta_player_rows = [];
			$this->counting_wpdb->user_emails = [];
			$this->counting_wpdb->write_query_count = 0;
		}
		if (function_exists('intersoccer_player_course_status_reset_run_memos')) {
			intersoccer_player_course_status_reset_run_memos();
		}
	}

	/**
	 * @param int $user_count
	 * @return array<int,array{user_id:int,meta_value:string}>
	 */
	private function seed_usermeta(int $user_count): array {
		$rows = [];
		for ($i = 1; $i <= $user_count; $i++) {
			$uid = 3000 + $i;
			$payload = [
				0 => [
					'first_name' => 'Player',
					'last_name' => 'Number' . $i,
					'player_id' => 'uuid-' . $i,
					'dob' => '2015-01-01',
				],
			];
			$rows[] = [
				'user_id' => $uid,
				'meta_value' => $payload,
			];
			if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
				$this->counting_wpdb->user_emails[ $uid ] = 'user' . $i . '@example.test';
			}
		}
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->usermeta_player_rows = $rows;
		}

		return $rows;
	}

	public function test_course_load_players_query_count_independent_of_user_count_and_no_writes(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;

		$this->seed_usermeta(10);
		$this->counting_wpdb->query_count = 0;
		$this->counting_wpdb->write_query_count = 0;
		$small = intersoccer_player_course_status_load_players();
		$small_queries = (int) $this->counting_wpdb->query_count;
		$small_writes = (int) $this->counting_wpdb->write_query_count;

		$this->reset_state();
		$this->seed_usermeta(100);
		$this->counting_wpdb->query_count = 0;
		$this->counting_wpdb->write_query_count = 0;
		$large = intersoccer_player_course_status_load_players();
		$large_queries = (int) $this->counting_wpdb->query_count;
		$large_writes = (int) $this->counting_wpdb->write_query_count;

		$this->assertCount(10, $small);
		$this->assertCount(100, $large);
		$this->assertSame($small_queries, $large_queries);
		$this->assertGreaterThan(0, $small_queries);
		$this->assertSame(0, $small_writes);
		$this->assertSame(0, $large_writes);
	}

	public function test_course_loader_matches_pcs_shape_on_same_usermeta_fixture(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$this->seed_usermeta(5);
		$course_players = intersoccer_player_course_status_load_players();

		global $wpdb;
		$pcs_wpdb = new class extends \InterSoccerTestWpdb {
			public function get_col($query = null, $x = 0, $y = 0) {
				$sql = (string) $query;
				if (stripos($sql, 'intersoccer_players') !== false && stripos($sql, 'DISTINCT') !== false) {
					return array_map('strval', array_keys($GLOBALS['intersoccer_test_user_meta'] ?? []));
				}

				return parent::get_col($query, $x, $y);
			}
		};
		$wpdb = $pcs_wpdb;
		$GLOBALS['intersoccer_test_user_meta'] = [];
		$GLOBALS['intersoccer_test_get_userdata'] = [];
		foreach ($this->counting_wpdb->usermeta_player_rows as $row) {
			$uid = (int) $row['user_id'];
			$GLOBALS['intersoccer_test_user_meta'][ $uid ] = [
				'intersoccer_players' => is_array($row['meta_value']) ? $row['meta_value'] : $row['meta_value'],
			];
			$GLOBALS['intersoccer_test_get_userdata'][ $uid ] = (object) [
				'ID' => $uid,
				'user_email' => $this->counting_wpdb->user_emails[ $uid ] ?? '',
			];
		}
		$pcs_players = intersoccer_player_camp_status_load_players();
		$wpdb = $this->counting_wpdb;

		$this->assertSame($pcs_players, $course_players);
	}
}
