<?php
/**
 * Win-back engine memory helpers (column projection, streaming aggregation, contacts).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackReportMemoryTest extends TestCase {
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
			$GLOBALS['intersoccer_test_winback_history_rows'],
			$GLOBALS['intersoccer_test_winback_upcoming_roster_rows'],
			$GLOBALS['intersoccer_test_winback_upcoming_catalogue'],
			$GLOBALS['intersoccer_test_winback_facet_resolution_callback'],
			$GLOBALS['intersoccer_test_get_userdata'],
			$GLOBALS['intersoccer_test_user_meta']
		);
		global $wpdb;
		if ($this->saved_wpdb !== null) {
			$wpdb = $this->saved_wpdb;
		}
		$this->saved_wpdb = null;
		parent::tearDown();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function wide_history_row(int $id, int $customer_id): array {
		$base = [
			'id' => $id,
			'is_placeholder' => 0,
			'customer_id' => $customer_id,
			'player_index' => 0,
			'order_id' => 70000 + $id,
			'order_item_id' => 80000 + $id,
			'order_status' => 'completed',
			'activity_type' => 'Camp',
			'product_id' => 1001,
			'variation_id' => 2001,
			'player_first_name' => 'Player',
			'player_last_name' => 'C' . $customer_id,
			'first_name' => 'Legacy',
			'last_name' => 'C' . $customer_id,
			'player_dob' => '2015-06-01',
			'dob' => '2015-06-01',
			'venue' => 'test-venue',
			'age_group' => '5-12y',
			'start_date' => '2026-03-01',
			'end_date' => '2026-03-05',
			'parent_first_name' => 'Parent',
			'parent_last_name' => 'G' . $customer_id,
			'parent_email' => 'parent' . $customer_id . '@example.test',
			'parent_phone' => '+4100000' . $customer_id,
			'product_name' => 'Camp Week',
			'camp_terms' => 'February',
			'season' => '2026',
			'course_day' => '',
		];
		$bulky = [
			'medical_conditions' => str_repeat('m', 500),
			'player_medical' => str_repeat('m', 500),
			'emergency_contact' => str_repeat('e', 200),
			'discount_codes' => str_repeat('d', 200),
			'day_presence' => str_repeat('p', 200),
		];
		$noise = [];
		for ($i = 0; $i < 40; $i++) {
			$noise[ 'unused_col_' . $i ] = str_repeat('x', 80);
		}

		return $base + $bulky + $noise;
	}

	/**
	 * @param array<int,array<string,mixed>> $history_rows
	 */
	private function seed_engine_fixtures(array $history_rows): void {
		global $wpdb;
		if ($wpdb instanceof WinbackReportCountingWpdb) {
			$wpdb->postmeta_customer_user = [];
			$wpdb->shop_orders = [];
			$wpdb->existing_user_ids = [];
			$wpdb->user_billing_phone = [];
			$wpdb->query_log = [];
			$wpdb->query_count = 0;
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

	public function test_history_row_projection_retains_required_columns(): void {
		$row = $this->wide_history_row(1, 42);
		$row['player_name'] = 'Player C42';
		$row['girls_only'] = 1;
		$row['times'] = 'full-day';
		$row['avs_number'] = '756.1234.5678.90';
		$row['event_signature'] = 'abc123sig';
		$projected = intersoccer_winback_report_engine_project_history_row($row);
		foreach (
			[
				'customer_id',
				'player_name',
				'girls_only',
				'times',
				'avs_number',
				'event_signature',
				'player_first_name',
				'venue',
			] as $column
		) {
			$this->assertArrayHasKey($column, $projected, 'Projected row missing column: ' . $column);
		}
		foreach (intersoccer_winback_report_engine_history_row_bulk_column_names() as $column) {
			$this->assertArrayNotHasKey($column, $projected, 'Bulky column should be stripped: ' . $column);
		}
		$this->assertArrayHasKey('unused_col_0', $projected, 'Unknown columns should be kept');
	}

	public function test_aggregation_retains_o_players_not_o_rows(): void {
		$players = 10;
		$rows_per_player = 500;
		$by_player_kind = [];
		$event_counts = [];
		$identity_map = new \Intersoccer_Roster_Report_Player_Identity_Map();

		for ($p = 1; $p <= $players; $p++) {
			for ($r = 1; $r <= $rows_per_player; $r++) {
				$row = $this->wide_history_row($p * 1000 + $r, 3000 + $p);
				$identity_map->register_row($row, '');
				$key_bits = intersoccer_roster_report_build_player_key($row, '');
				$group_id = $identity_map->group_id($key_bits['key']);
				$entry = [
					'row' => intersoccer_winback_report_engine_project_history_row($row),
					'kind' => 'camp',
					'event_date' => '2026-03-' . str_pad((string) min($r, 28), 2, '0', STR_PAD_LEFT),
					'date_source' => 'roster_start_date',
					'venue_slug' => 'test-venue',
					'age_slug' => '5-12y',
					'key' => $key_bits['key'],
					'tier' => $key_bits['tier'],
					'group_id' => $group_id,
				];
				intersoccer_winback_report_engine_aggregate_history_entry(
					$by_player_kind,
					$event_counts,
					$entry,
					$group_id,
					'camp'
				);
			}
		}

		$this->assertCount($players, $by_player_kind);
		foreach ($event_counts as $pk => $count) {
			$this->assertSame($rows_per_player, $count, 'Unexpected event count for ' . $pk);
		}
	}

	public function test_aggregation_entry_count_independent_of_row_volume(): void {
		$players = 10;
		$by_small = [];
		$counts_small = [];
		$by_large = [];
		$counts_large = [];
		$identity_small = new \Intersoccer_Roster_Report_Player_Identity_Map();
		$identity_large = new \Intersoccer_Roster_Report_Player_Identity_Map();

		$feed = static function ($map, &$by, &$counts, $rows_per_player) use ($players) {
			for ($p = 1; $p <= $players; $p++) {
				for ($r = 1; $r <= $rows_per_player; $r++) {
					$row = [
						'id' => $p * 10000 + $r,
						'customer_id' => 4000 + $p,
						'player_index' => 0,
						'order_id' => 90000 + $p,
						'order_item_id' => 91000 + $p * 1000 + $r,
						'order_status' => 'completed',
						'activity_type' => 'Camp',
						'product_id' => 1,
						'variation_id' => 1,
						'player_first_name' => 'A',
						'player_last_name' => 'B' . $p,
						'start_date' => '2026-04-01',
						'venue' => 'v',
						'age_group' => '5-12y',
					];
					$map->register_row($row, '');
					$key_bits = intersoccer_roster_report_build_player_key($row, '');
					$group_id = $map->group_id($key_bits['key']);
					$entry = [
						'row' => intersoccer_winback_report_engine_project_history_row($row),
						'kind' => 'camp',
						'event_date' => '2026-04-01',
						'date_source' => 'roster_start_date',
						'venue_slug' => 'v',
						'age_slug' => '5-12y',
						'key' => $key_bits['key'],
						'tier' => $key_bits['tier'],
						'group_id' => $group_id,
					];
					intersoccer_winback_report_engine_aggregate_history_entry($by, $counts, $entry, $group_id, 'camp');
				}
			}
		};

		$feed($identity_small, $by_small, $counts_small, 50);
		$feed($identity_large, $by_large, $counts_large, 500);

		$this->assertCount($players, $by_small);
		$this->assertCount($players, $by_large);
		$sample_small = reset($by_small);
		$sample_large = reset($by_large);
		$this->assertIsArray($sample_small);
		$this->assertIsArray($sample_large);
		$this->assertSame(50, intersoccer_winback_report_engine_history_events_in_lookback(
			(string) ( $sample_small['group_id'] ?? '' ),
			'camp',
			$counts_small
		));
		$this->assertSame(500, intersoccer_winback_report_engine_history_events_in_lookback(
			(string) ( $sample_large['group_id'] ?? '' ),
			'camp',
			$counts_large
		));
	}

	public function test_is_later_history_tie_breaks_equal_dates_by_row_id(): void {
		$earlier = [
			'event_date' => '2026-05-01',
			'row' => ['id' => 10],
		];
		$later = [
			'event_date' => '2026-05-01',
			'row' => ['id' => 99],
		];
		$this->assertTrue(intersoccer_winback_report_engine_is_later_history($later, $earlier));
		$this->assertFalse(intersoccer_winback_report_engine_is_later_history($earlier, $later));
	}

	public function test_engine_fixture_events_in_lookback_and_output_shape(): void {
		$history = [];
		for ($i = 1; $i <= 3; $i++) {
			$history[] = $this->wide_history_row($i, 5000 + $i);
		}
		$this->seed_engine_fixtures($history);
		$GLOBALS['intersoccer_test_winback_history_rows'] = $history;

		$result = intersoccer_winback_report_engine_generate([
			'kind' => 'camp',
			'lookback_months' => 24,
		]);

		$this->assertArrayHasKey('rows', $result);
		$this->assertCount(3, $result['rows']);
		foreach ($result['rows'] as $row) {
			$this->assertSame(1, (int) ( $row['events_in_lookback'] ?? 0 ));
			$this->assertArrayHasKey('player', $row);
			$this->assertArrayHasKey('contact', $row);
			$this->assertArrayHasKey('last_event', $row);
			$this->assertSame('acct' . $row['parent']['id'] . '@example.test', $row['contact']['account_email']);
		}
	}

	public function test_prime_contact_users_uses_batched_queries_not_per_user(): void {
		global $wpdb;
		$this->assertInstanceOf(WinbackReportCountingWpdb::class, $wpdb);

		$user_ids = range(1, 1205);
		foreach ($user_ids as $uid) {
			$wpdb->existing_user_ids[ $uid ] = true;
			$wpdb->user_billing_phone[ $uid ] = '+41' . $uid;
			$GLOBALS['intersoccer_test_user_meta'][ $uid ] = ['billing_phone' => '+41' . $uid];
			$GLOBALS['intersoccer_test_get_userdata'][ $uid ] = (object) [
				'ID' => $uid,
				'user_email' => 'user' . $uid . '@example.test',
			];
		}

		$wpdb->query_log = [];
		$wpdb->query_count = 0;

		intersoccer_winback_prime_contact_users($user_ids);

		$phone_queries = 0;
		$user_queries = 0;
		foreach ($wpdb->query_log as $sql) {
			if (stripos($sql, 'billing_phone') !== false) {
				$phone_queries++;
			}
			if (stripos($sql, 'FROM wp_users') !== false || stripos($sql, 'FROM ' . $wpdb->users) !== false) {
				$user_queries++;
			}
		}

		$this->assertSame(0, $phone_queries, 'Testing path should not query billing_phone');
		$this->assertSame(0, $user_queries, 'Testing path should not query users table');

		$contact = intersoccer_winback_report_engine_build_contact(42, [
			'parent_email' => 'bill@example.test',
			'parent_phone' => '',
		]);
		$this->assertSame('user42@example.test', $contact['account_email']);
		$this->assertSame('+4142', $contact['phone']);
	}

	public function test_projected_row_memory_scales_lower_than_wide_select_star_rows(): void {
		$wide = [];
		$projected = [];
		for ($i = 0; $i < 1000; $i++) {
			$wide[] = $this->wide_history_row($i + 1, 6000);
			$projected[] = intersoccer_winback_report_engine_project_history_row($wide[ $i ]);
		}

		$wide_bytes = strlen(serialize($wide));
		$projected_bytes = strlen(serialize($projected));
		$aggregated_bytes = strlen(serialize([
			'by_player_kind' => array_fill(0, 100, ['row' => $projected[0]]),
			'event_counts' => array_fill(0, 100, 50),
		]));

		$this->assertLessThan($wide_bytes, $projected_bytes);
		$this->assertLessThan($wide_bytes / 2, $aggregated_bytes);

		$per_1k_wide_mb = round($wide_bytes / 1024 / 1024, 2);
		$per_1k_projected_mb = round($projected_bytes / 1024 / 1024, 2);
		$this->assertGreaterThan(0.1, $per_1k_wide_mb);
		$this->assertLessThan($per_1k_wide_mb, $per_1k_projected_mb);
	}
}

}
