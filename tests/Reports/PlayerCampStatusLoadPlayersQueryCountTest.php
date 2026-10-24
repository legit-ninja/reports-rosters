<?php
/**
 * Player camp status load_players: batched cache_users, query count vs user volume.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/WinbackReportQueryCountTest.php';

class PlayerCampStatusLoadPlayersCountingWpdb extends WinbackReportCountingWpdb {
	/** @var int[] */
	public $distinct_player_user_ids = [];

	private function count_query($query = null): void {
		$this->query_count++;
		if ($query !== null && $query !== '') {
			$this->last_query = (string) $query;
		}
	}

	public function get_col($query = null, $x = 0, $y = 0) {
		$this->count_query($query);
		$sql = (string) $query;
		if (stripos($sql, 'intersoccer_players') !== false && stripos($sql, 'DISTINCT user_id') !== false) {
			return array_map('strval', $this->distinct_player_user_ids);
		}

		return parent::get_col($query, $x, $y);
	}
}

class PlayerCampStatusLoadPlayersQueryCountTest extends TestCase {
	/** @var PlayerCampStatusLoadPlayersCountingWpdb|null */
	private $counting_wpdb = null;

	/** @var object|null */
	private $saved_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->saved_wpdb = $wpdb;
		$this->counting_wpdb = new PlayerCampStatusLoadPlayersCountingWpdb();
		$wpdb = $this->counting_wpdb;

		require_once dirname(__DIR__, 2) . '/includes/player-camp-status.php';
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
			$GLOBALS['intersoccer_test_cache_users_calls'],
			$GLOBALS['intersoccer_test_get_user_meta_call_count'],
			$GLOBALS['intersoccer_test_user_meta'],
			$GLOBALS['intersoccer_test_get_userdata']
		);
	}

	/**
	 * @param int $user_count
	 * @return int[]
	 */
	private function seed_users(int $user_count): array {
		$ids = [];
		for ($i = 1; $i <= $user_count; $i++) {
			$uid = 2000 + $i;
			$ids[] = $uid;
			$GLOBALS['intersoccer_test_get_userdata'][ $uid ] = (object) [
				'ID' => $uid,
				'user_email' => 'user' . $i . '@example.test',
			];
			$GLOBALS['intersoccer_test_user_meta'][ $uid ] = [
				'intersoccer_players' => [
					0 => [
						'first_name' => 'Player',
						'last_name' => 'Number' . $i,
						'player_id' => 'uuid-' . $i,
						'dob' => '2015-01-01',
					],
				],
			];
		}

		return $ids;
	}

	public function test_load_players_query_count_independent_of_user_count_and_cache_users_once(): void {
		if (!$this->counting_wpdb instanceof PlayerCampStatusLoadPlayersCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}

		$this->counting_wpdb->distinct_player_user_ids = $this->seed_users(10);
		$expected_small_ids = array_map('intval', $this->counting_wpdb->distinct_player_user_ids);
		$this->counting_wpdb->query_count = 0;
		$small_players = intersoccer_player_camp_status_load_players();
		$small_queries = (int) $this->counting_wpdb->query_count;
		$small_cache_calls = $GLOBALS['intersoccer_test_cache_users_calls'] ?? [];

		$this->reset_state();
		$this->counting_wpdb->distinct_player_user_ids = $this->seed_users(100);
		$expected_large_ids = array_map('intval', $this->counting_wpdb->distinct_player_user_ids);
		$this->counting_wpdb->query_count = 0;
		$large_players = intersoccer_player_camp_status_load_players();
		$large_queries = (int) $this->counting_wpdb->query_count;
		$large_cache_calls = $GLOBALS['intersoccer_test_cache_users_calls'] ?? [];

		$this->assertCount(10, $small_players);
		$this->assertCount(100, $large_players);
		$this->assertSame($small_queries, $large_queries);
		$this->assertGreaterThan(0, $small_queries);
		$this->assertCount(1, $small_cache_calls);
		$this->assertCount(1, $large_cache_calls);
		$this->assertSame($expected_small_ids, $small_cache_calls[0]);
		$this->assertSame($expected_large_ids, $large_cache_calls[0]);
	}
}

}
