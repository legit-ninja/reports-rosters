<?php
/**
 * Win-back engine path: query / heavy-call scaling (10 vs 100 players).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

/**
 * Counts DB reads and serves seeded win-back perf fixtures.
 */
class WinbackReportCountingWpdb extends \InterSoccerTestWpdb {
	/** @var int */
	public $query_count = 0;

	/** @var array<int,string> */
	public $postmeta_customer_user = [];

	/** @var array<int,array{post_status:string,post_date:string}> */
	public $shop_orders = [];

	/** @var array<int,true> */
	public $refund_parents = [];

	/** @var array<int,true> */
	public $existing_user_ids = [];

	/** @var array<int,string> */
	public $user_billing_phone = [];

	/** @var array<int,array<int,int>> */
	public $variation_posts_by_parent = [];

	/** @var string[] */
	public $query_log = [];

	private function bump($query = null): void {
		$this->query_count++;
		if ($query !== null && $query !== '') {
			$this->last_query = (string) $query;
			$this->query_log[] = preg_replace('/\s+/', ' ', substr((string) $query, 0, 120));
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

	public function get_results($query = null, $output = OBJECT) {
		$this->bump($query);
		$sql = (string) $query;
		$ids = $this->extract_in_list_ints($sql);

		if (stripos($sql, 'FROM ' . $this->postmeta) !== false && stripos($sql, '_customer_user') !== false) {
			$rows = [];
			foreach ($ids as $id) {
				if (!isset($this->postmeta_customer_user[ $id ])) {
					continue;
				}
				$rows[] = [
					'post_id' => $id,
					'meta_value' => (string) $this->postmeta_customer_user[ $id ],
				];
			}

			return $rows;
		}

		if (stripos($sql, 'post_status') !== false && stripos($sql, 'shop_order') !== false && stripos($sql, 'post_type') !== false) {
			$rows = [];
			foreach ($ids as $id) {
				if (!isset($this->shop_orders[ $id ])) {
					continue;
				}
				$rows[] = [
					'ID' => $id,
					'post_status' => $this->shop_orders[ $id ]['post_status'],
				];
			}

			return $rows;
		}

		if (stripos($sql, 'post_date') !== false && stripos($sql, 'shop_order') !== false) {
			$rows = [];
			foreach ($ids as $id) {
				if (!isset($this->shop_orders[ $id ])) {
					continue;
				}
				$rows[] = [
					'ID' => $id,
					'post_date' => $this->shop_orders[ $id ]['post_date'],
				];
			}

			return $rows;
		}

		if (stripos($sql, 'shop_order_refund') !== false && stripos($sql, 'post_parent') !== false) {
			$rows = [];
			foreach ($ids as $id) {
				if (!empty($this->refund_parents[ $id ])) {
					$rows[] = ['post_parent' => $id];
				}
			}

			return $rows;
		}

		if (stripos($sql, 'FROM ' . $this->users) !== false && stripos($sql, 'SELECT ID') !== false) {
			$rows = [];
			foreach ($ids as $id) {
				if (!empty($this->existing_user_ids[ $id ])) {
					$rows[] = ['ID' => $id];
				}
			}

			return $rows;
		}

		if (stripos($sql, 'product_variation') !== false && stripos($sql, 'post_parent') !== false) {
			$rows = [];
			foreach ($ids as $parent_id) {
				foreach ($this->variation_posts_by_parent[ $parent_id ] ?? [] as $variation_id) {
					$rows[] = [
						'ID' => (int) $variation_id,
						'post_parent' => (int) $parent_id,
						'post_status' => 'publish',
					];
				}
			}

			return $rows;
		}

		if (stripos($sql, 'billing_phone') !== false && stripos($sql, $this->usermeta) !== false) {
			$rows = [];
			foreach ($ids as $id) {
				if (!array_key_exists($id, $this->user_billing_phone)) {
					continue;
				}
				$rows[] = [
					'user_id' => $id,
					'meta_value' => $this->user_billing_phone[ $id ],
				];
			}

			return $rows;
		}

		if (stripos($sql, 'DESCRIBE') !== false) {
			return [];
		}

		return [];
	}

	public function get_var($query = null, $x = 0, $y = 0) {
		$this->bump($query);
		$sql = (string) $query;

		if (stripos($sql, 'SHOW TABLES LIKE') !== false) {
			return null;
		}

		if (stripos($sql, 'post_date') !== false && stripos($sql, 'LIMIT 1') !== false) {
			if (preg_match('/WHERE ID = (\d+)/', $sql, $m)) {
				$id = (int) $m[1];
				if (isset($this->shop_orders[ $id ])) {
					return $this->shop_orders[ $id ]['post_date'];
				}
			}
		}

		if (stripos($sql, '_customer_user') !== false && stripos($sql, 'LIMIT 1') !== false) {
			if (preg_match('/post_id = (\d+)/', $sql, $m)) {
				$id = (int) $m[1];
				if (isset($this->postmeta_customer_user[ $id ])) {
					return $this->postmeta_customer_user[ $id ];
				}
			}
		}

		return null;
	}

	public function get_col($query = null, $x = 0) {
		$this->bump($query);

		return [];
	}

	public function get_row($query = null, $output = OBJECT, $y = 0) {
		$this->bump($query);

		return null;
	}

	public function query($query) {
		$this->bump($query);

		return true;
	}
}

class WinbackReportQueryCountTest extends TestCase {
	/** @var object|null */
	private $saved_wpdb = null;

	/** @var WinbackReportCountingWpdb|null */
	private $counting_wpdb = null;

	/** @var array<string,int> */
	private $last_query_histogram = [];

	/**
	 * Clear win-back / roster-report memos and test globals that leak across PHPUnit tests.
	 */
	private function reset_winback_loader_isolation_state(): void {
		unset(
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_created_date_map'],
			$GLOBALS['intersoccer_test_orders_with_refunds'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'],
			$GLOBALS['intersoccer_test_fake_customer_map'],
			$GLOBALS['intersoccer_test_batch_chunk_size'],
			$GLOBALS['intersoccer_test_hpos_orders_enabled'],
			$GLOBALS['intersoccer_test_wc_get_order_callback'],
			$GLOBALS['intersoccer_test_winback_current_course_term_rows'],
			$GLOBALS['intersoccer_rr_user_billing_phone_cache'],
			$GLOBALS['intersoccer_rr_user_account_email_cache']
		);
		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
		if (function_exists('intersoccer_roster_report_reset_order_customer_id_cache')) {
			intersoccer_roster_report_reset_order_customer_id_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_fake_customer_cache')) {
			intersoccer_roster_report_reset_fake_customer_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		if (function_exists('intersoccer_winback_reset_order_created_date_cache')) {
			intersoccer_winback_reset_order_created_date_cache();
		}
		if (function_exists('intersoccer_winback_reset_current_course_term_cache')) {
			intersoccer_winback_reset_current_course_term_cache();
		}
		if (function_exists('intersoccer_winback_reset_j8_lookup_cache')) {
			intersoccer_winback_reset_j8_lookup_cache();
		}
		if (function_exists('intersoccer_winback_reset_catalogue_target_cache')) {
			intersoccer_winback_reset_catalogue_target_cache();
		}
		if (function_exists('intersoccer_winback_reset_catalogue_enumeration_memo')) {
			intersoccer_winback_reset_catalogue_enumeration_memo();
		}
		if (function_exists('intersoccer_rr_product_type_memo_reset')) {
			intersoccer_rr_product_type_memo_reset();
		}
	}

	/**
	 * @param int[] $user_ids
	 */
	private function seed_test_users_exist(array $user_ids): void {
		if (!isset($GLOBALS['intersoccer_test_get_userdata']) || !is_array($GLOBALS['intersoccer_test_get_userdata'])) {
			$GLOBALS['intersoccer_test_get_userdata'] = [];
		}
		foreach ($user_ids as $user_id) {
			$user_id = (int) $user_id;
			if ($user_id <= 0) {
				continue;
			}
			$GLOBALS['intersoccer_test_get_userdata'][ $user_id ] = (object) [
				'ID' => $user_id,
				'user_email' => 'user' . $user_id . '@example.test',
			];
			if ($this->counting_wpdb instanceof WinbackReportCountingWpdb) {
				$this->counting_wpdb->existing_user_ids[ $user_id ] = true;
			}
		}
	}

	/**
	 * @return array<string,int>
	 */
	private function reset_perf_counters(): array {
		$GLOBALS['intersoccer_test_wc_get_order_call_count'] = 0;
		$GLOBALS['intersoccer_test_get_post_meta_call_count'] = 0;
		$GLOBALS['intersoccer_test_get_term_by_call_count'] = 0;
		$GLOBALS['intersoccer_test_get_userdata_call_count'] = 0;
		$GLOBALS['intersoccer_test_get_user_meta_call_count'] = 0;
		$GLOBALS['intersoccer_test_wc_get_order_item_meta_call_count'] = 0;
		if ($this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->counting_wpdb->query_count = 0;
		}

		return [
			'wpdb_queries' => 0,
			'wc_get_order' => 0,
			'get_post_meta' => 0,
			'get_term_by' => 0,
			'get_userdata' => 0,
			'get_user_meta' => 0,
			'wc_get_order_item_meta' => 0,
		];
	}

	/**
	 * @return array<string,int>
	 */
	private function read_perf_counters(): array {
		$wpdb_queries = $this->counting_wpdb instanceof WinbackReportCountingWpdb
			? (int) $this->counting_wpdb->query_count
			: 0;

		return [
			'wpdb_queries' => $wpdb_queries,
			'wc_get_order' => (int) ( $GLOBALS['intersoccer_test_wc_get_order_call_count'] ?? 0 ),
			'get_post_meta' => (int) ( $GLOBALS['intersoccer_test_get_post_meta_call_count'] ?? 0 ),
			'get_term_by' => (int) ( $GLOBALS['intersoccer_test_get_term_by_call_count'] ?? 0 ),
			'get_userdata' => (int) ( $GLOBALS['intersoccer_test_get_userdata_call_count'] ?? 0 ),
			'get_user_meta' => (int) ( $GLOBALS['intersoccer_test_get_user_meta_call_count'] ?? 0 ),
			'wc_get_order_item_meta' => (int) ( $GLOBALS['intersoccer_test_wc_get_order_item_meta_call_count'] ?? 0 ),
		];
	}

	/**
	 * @param int $player_count
	 * @return array<int,array<string,mixed>>
	 */
	private function synthetic_history_rows(int $player_count): array {
		$rows = [];
		for ($i = 1; $i <= $player_count; $i++) {
			$customer_id = $i % 3 === 0 ? 0 : (1000 + $i);
			$order_id = 50000 + $i;
			$rows[] = [
				'id' => $i,
				'customer_id' => $customer_id,
				'player_index' => 0,
				'order_id' => $order_id,
				'order_item_id' => 60000 + $i,
				'order_status' => 'completed',
				'activity_type' => $i % 5 === 0 ? 'Course' : 'Camp',
				'player_first_name' => 'Player',
				'player_last_name' => 'N' . $i,
				'player_dob' => '2015-06-01',
				'venue' => 'test-venue',
				'age_group' => '5-12y',
				'start_date' => '2025-08-01',
				'end_date' => '2025-08-05',
				'parent_email' => 'parent' . $i . '@example.test',
				'parent_phone' => '+410000000' . $i,
			];
		}
		$rows[] = [
			'id' => 90001,
			'customer_id' => 3001,
			'player_index' => 0,
			'order_id' => 59999,
			'order_item_id' => 69999,
			'order_status' => 'completed',
			'activity_type' => 'Camp',
			'player_first_name' => 'Refund',
			'player_last_name' => 'Line',
			'player_dob' => '2015-06-01',
			'venue' => 'test-venue',
			'age_group' => '5-12y',
			'start_date' => '2025-07-01',
			'end_date' => '2025-07-05',
			'parent_email' => 'refund@example.test',
			'parent_phone' => '+4100000099',
		];

		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $history_rows
	 */
	private function seed_wpdb_fixtures(array $history_rows): void {
		if (!$this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			return;
		}
		$db = $this->counting_wpdb;
		$db->postmeta_customer_user = [];
		$db->shop_orders = [];
		$db->refund_parents = [];
		$db->existing_user_ids = [];
		$db->user_billing_phone = [];

		$customer_ids = [];
		foreach ($history_rows as $row) {
			$oid = (int) $row['order_id'];
			$cid = (int) $row['customer_id'];
			if ($cid <= 0) {
				$cid = 2000 + $oid;
			}
			$db->postmeta_customer_user[ $oid ] = (string) $cid;
			$db->shop_orders[ $oid ] = [
				'post_status' => 'wc-completed',
				'post_date' => '2025-04-01 10:00:00',
			];
			$customer_ids[ $cid ] = $cid;
		}
		$db->refund_parents[59999] = true;

		foreach ($customer_ids as $uid) {
			$db->existing_user_ids[ $uid ] = true;
			$db->user_billing_phone[ $uid ] = '+4100000' . $uid;
			$GLOBALS['intersoccer_test_get_userdata'][ $uid ] = (object) [
				'ID' => $uid,
				'user_email' => 'acct' . $uid . '@example.test',
			];
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $history_rows
	 * @return array<string,int>
	 */
	private function run_winback_perf_slice(array $history_rows): array {
		$this->seed_wpdb_fixtures($history_rows);

		unset(
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_order_created_date_map'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_orders_with_refunds'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids']
		);
		$GLOBALS['intersoccer_test_hpos_orders_enabled'] = false;

		$GLOBALS['intersoccer_test_wc_get_order_callback'] = static function ($order_id) {
			if ((int) $order_id !== 59999) {
				return false;
			}

			return new class() {
				public function get_customer_id() {
					return 3001;
				}

				public function get_refunds() {
					return [new class() {
						public function get_id() {
							return 1;
						}
					}];
				}
			};
		};

		$running_row = [
			'is_placeholder' => 0,
			'order_item_id' => 69999,
			'order_id' => 59999,
			'order_status' => 'completed',
			'activity_type' => 'Course',
			'customer_id' => 3001,
			'player_index' => 0,
			'player_first_name' => 'Running',
			'player_last_name' => 'Course',
			'start_date' => '2026-01-01',
			'end_date' => '2026-12-31',
		];
		$GLOBALS['intersoccer_test_winback_current_course_term_rows'] = [ $running_row ];

		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
		if (function_exists('intersoccer_roster_report_reset_order_customer_id_cache')) {
			intersoccer_roster_report_reset_order_customer_id_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_fake_customer_cache')) {
			intersoccer_roster_report_reset_fake_customer_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		intersoccer_winback_reset_order_created_date_cache();
		intersoccer_winback_reset_current_course_term_cache();
		intersoccer_winback_reset_j8_lookup_cache();
		unset($GLOBALS['intersoccer_rr_user_billing_phone_cache'], $GLOBALS['intersoccer_rr_user_account_email_cache']);

		$rows = intersoccer_roster_report_rows_apply_live_order_status($history_rows);
		intersoccer_roster_report_prime_order_customer_ids_from_rows($rows);
		intersoccer_roster_report_prime_line_refund_checks_from_rows($rows);
		intersoccer_winback_prime_order_created_dates_from_rows($rows);
		intersoccer_roster_report_prime_fake_customer_checks(
			intersoccer_roster_report_collect_resolved_customer_ids_from_rows($rows)
		);

		$identity_map = new \Intersoccer_Roster_Report_Player_Identity_Map();
		$by_player_kind = [];
		foreach ($rows as $row) {
			$row = intersoccer_roster_report_row_with_resolved_customer($row);
			if (intersoccer_roster_report_is_fake_customer((int) $row['customer_id'])) {
				continue;
			}
			if (!intersoccer_roster_report_row_counts_for_winback($row, 'history')) {
				continue;
			}
			$key_bits = intersoccer_roster_report_build_player_key($row, '');
			if ($key_bits['key'] === '') {
				continue;
			}
			$identity_map->register_row($row, '');
			$pk = $identity_map->group_id($key_bits['key']) . '|camp';
			$by_player_kind[ $pk ] = [
				'row' => $row,
				'key' => $key_bits['key'],
				'venue_slug' => 'test-venue',
				'age_slug' => '5-12y',
			];
		}

		$today = '2026-06-15';
		intersoccer_winback_report_engine_build_j8_lookup($today, $identity_map);

		$catalogue = [
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
		intersoccer_winback_reset_catalogue_target_cache();

		$customer_ids = [];
		foreach ($by_player_kind as $history) {
			$customer_id = (int) $history['row']['customer_id'];
			if ($customer_id > 0) {
				$customer_ids[ $customer_id ] = $customer_id;
			}
			if (intersoccer_winback_report_engine_player_in_current_course_term(
				$history['key'],
				'course',
				$today,
				[],
				$identity_map
			)) {
				continue;
			}
			intersoccer_winback_resolve_catalogue_target_and_more(
				$catalogue,
				'camp',
				$history['venue_slug'],
				$history['age_slug'],
				'2015-06-01'
			);
		}
		if (!empty($customer_ids)) {
			intersoccer_winback_prime_contact_users(array_values($customer_ids));
			foreach ($by_player_kind as $history) {
				intersoccer_winback_report_engine_build_contact(
					(int) $history['row']['customer_id'],
					$history['row']
				);
			}
		}

		$counters = $this->read_perf_counters();
		if ($this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$hist = [];
			foreach ($this->counting_wpdb->query_log as $sql) {
				if (stripos($sql, '_customer_user') !== false) {
					$bucket = 'customer_user';
				} elseif (stripos($sql, 'post_status') !== false) {
					$bucket = 'order_status';
				} elseif (stripos($sql, 'post_date') !== false) {
					$bucket = 'order_created';
				} elseif (stripos($sql, 'shop_order_refund') !== false) {
					$bucket = 'refunds';
				} elseif (stripos($sql, 'wp_users') !== false) {
					$bucket = 'users';
				} elseif (stripos($sql, 'billing_phone') !== false) {
					$bucket = 'billing_phone';
				} else {
					$bucket = 'other';
				}
				$hist[ $bucket ] = (int) ( $hist[ $bucket ] ?? 0 ) + 1;
			}
			$this->last_query_histogram = $hist;
		}

		return $counters;
	}

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->saved_wpdb = $wpdb;
		$this->counting_wpdb = new WinbackReportCountingWpdb();
		$this->counting_wpdb->postmeta_customer_user = [];
		$this->counting_wpdb->shop_orders = [];
		$this->counting_wpdb->refund_parents = [];
		$this->counting_wpdb->existing_user_ids = [];
		$this->counting_wpdb->user_billing_phone = [];
		$this->counting_wpdb->variation_posts_by_parent = [];
		$this->counting_wpdb->query_count = 0;
		$this->counting_wpdb->query_log = [];
		$wpdb = $this->counting_wpdb;

		$includes = dirname(__DIR__, 2) . '/includes';
		require_once $includes . '/roster-report-loader.php';
		require_once $includes . '/winback-report-perf.php';
		require_once $includes . '/utils.php';
		require_once $includes . '/winback-report-facets.php';
		require_once $includes . '/winback-catalogue.php';
		require_once $includes . '/winback-report-engine.php';

		$GLOBALS['intersoccer_test_get_userdata'] = [];
		$this->reset_winback_loader_isolation_state();
	}

	protected function tearDown(): void {
		$this->reset_winback_loader_isolation_state();
		global $wpdb;
		if ($this->saved_wpdb !== null) {
			$wpdb = $this->saved_wpdb;
		}
		unset(
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_order_created_date_map'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_orders_with_refunds'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'],
			$GLOBALS['intersoccer_test_hpos_orders_enabled'],
			$GLOBALS['intersoccer_test_wc_get_order_callback'],
			$GLOBALS['intersoccer_test_wc_get_order_call_count'],
			$GLOBALS['intersoccer_test_get_post_meta_call_count'],
			$GLOBALS['intersoccer_test_get_term_by_call_count'],
			$GLOBALS['intersoccer_test_get_userdata_call_count'],
			$GLOBALS['intersoccer_test_get_user_meta_call_count'],
			$GLOBALS['intersoccer_test_wc_get_order_item_meta_call_count'],
			$GLOBALS['intersoccer_test_winback_current_course_term_rows'],
			$GLOBALS['intersoccer_test_get_userdata']
		);
		unset($GLOBALS['intersoccer_test_batch_chunk_size']);
		$this->saved_wpdb = null;
		$this->counting_wpdb = null;
		parent::tearDown();
	}

	public function test_winback_slice_query_and_heavy_calls_scale_sublinearly_with_player_count(): void {
		$this->reset_perf_counters();
		$small = $this->run_winback_perf_slice($this->synthetic_history_rows(10));
		$small_hist = $this->last_query_histogram;

		$this->reset_perf_counters();
		$large = $this->run_winback_perf_slice($this->synthetic_history_rows(100));
		$large_hist = $this->last_query_histogram;

		$extra_chunks_10 = (int) max(0, intdiv(count($this->synthetic_history_rows(10)), 500));
		$extra_chunks_100 = (int) max(0, intdiv(count($this->synthetic_history_rows(100)), 500));
		$extra_chunk_delta = $extra_chunks_100 - $extra_chunks_10;

		$delta_wpdb = $large['wpdb_queries'] - $small['wpdb_queries'];
		$log_tail = '';
		if ($this->counting_wpdb instanceof WinbackReportCountingWpdb && !empty($this->counting_wpdb->query_log)) {
			$log_tail = ' sample=' . implode(' | ', array_slice($this->counting_wpdb->query_log, -5));
		}
		$this->assertLessThanOrEqual(
			$extra_chunk_delta + 3,
			$delta_wpdb,
			'$wpdb queries should grow by chunk count only: small=' . $small['wpdb_queries']
			. ' large=' . $large['wpdb_queries'] . ' delta=' . $delta_wpdb
			. ' extra_chunks=' . $extra_chunk_delta
			. ' hist_small=' . json_encode($small_hist)
			. ' hist_large=' . json_encode($large_hist)
			. $log_tail
		);

		$delta_wc = $large['wc_get_order'] - $small['wc_get_order'];
		$this->assertLessThanOrEqual(
			2,
			$delta_wc,
			'wc_get_order should not scale per player: small=' . $small['wc_get_order']
			. ' large=' . $large['wc_get_order']
		);
		$this->assertLessThanOrEqual(1, $small['wc_get_order']);
		$this->assertLessThanOrEqual(1, $large['wc_get_order']);

		$scaling_keys = ['get_post_meta', 'get_term_by', 'wc_get_order_item_meta'];
		foreach ($scaling_keys as $key) {
			$delta = $large[ $key ] - $small[ $key ];
			$this->assertLessThanOrEqual(
				3,
				$delta,
				$key . ' calls should grow by at most a small constant: small=' . $small[ $key ]
				. ' large=' . $large[ $key ]
			);
		}

		$delta_userdata = $large['get_userdata'] - $small['get_userdata'];
		$this->assertLessThanOrEqual(
			3,
			$delta_userdata,
			'get_userdata should not scale per player after priming: small=' . $small['get_userdata']
			. ' large=' . $large['get_userdata']
		);

		$summary = 'perf_10_vs_100 wpdb=' . $small['wpdb_queries'] . '/' . $large['wpdb_queries']
			. ' wc_get_order=' . $small['wc_get_order'] . '/' . $large['wc_get_order']
			. ' get_post_meta=' . $small['get_post_meta'] . '/' . $large['get_post_meta']
			. ' get_term_by=' . $small['get_term_by'] . '/' . $large['get_term_by']
			. ' get_userdata=' . $small['get_userdata'] . '/' . $large['get_userdata']
			. ' wc_get_order_item_meta=' . $small['wc_get_order_item_meta'] . '/' . $large['wc_get_order_item_meta'];
		$this->assertNotEmpty($summary);
		$GLOBALS['intersoccer_test_winback_perf_summary'] = $summary;
	}

	public function test_batch_loaders_preserve_integer_order_id_keys_across_chunks(): void {
		if (!$this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->markTestSkipped('Counting wpdb not available');
		}
		global $wpdb;
		$wpdb = $this->counting_wpdb;
		$this->reset_winback_loader_isolation_state();
		$GLOBALS['intersoccer_test_hpos_orders_enabled'] = false;
		$order_ids = [62545, 70001, 81234, 90017];
		foreach ($order_ids as $i => $oid) {
			$this->counting_wpdb->postmeta_customer_user[ $oid ] = (string) (9100 + $i);
			$this->counting_wpdb->shop_orders[ $oid ] = [
				'post_status' => 'wc-completed',
				'post_date' => '2025-03-' . str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT) . ' 12:00:00',
			];
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 2;

		$customer_map = intersoccer_roster_report_batch_load_order_customer_ids($order_ids);
		$this->assertSame($order_ids, array_keys($customer_map));
		$this->assertSame(9100, $customer_map[62545]);

		$status_map = intersoccer_roster_report_batch_load_live_order_statuses($order_ids);
		$this->assertSame($order_ids, array_keys($status_map));
		$this->assertSame('wc-completed', $status_map[90017]);

		$date_map = intersoccer_roster_report_batch_load_order_created_dates($order_ids);
		$this->assertSame($order_ids, array_keys($date_map));
		$this->assertSame('2025-03-10', $date_map[62545]);

		unset($GLOBALS['intersoccer_test_batch_chunk_size']);
	}

	public function test_live_status_and_customer_resolution_with_non_sequential_order_ids(): void {
		if (!$this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->markTestSkipped('Counting wpdb not available');
		}
		global $wpdb;
		$wpdb = $this->counting_wpdb;
		$this->reset_winback_loader_isolation_state();
		$GLOBALS['intersoccer_test_hpos_orders_enabled'] = false;
		if ($this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->counting_wpdb->postmeta_customer_user[81234] = '44201';
			$this->counting_wpdb->shop_orders[62545] = ['post_status' => 'wc-refunded', 'post_date' => '2025-01-01 00:00:00'];
			$this->counting_wpdb->shop_orders[70001] = ['post_status' => 'wc-completed', 'post_date' => '2025-01-02 00:00:00'];
			$this->counting_wpdb->shop_orders[81234] = ['post_status' => 'wc-on-hold', 'post_date' => '2025-01-03 00:00:00'];
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 2;

		$refunded = intersoccer_roster_report_rows_apply_live_order_status([
			['order_id' => 62545, 'order_status' => 'completed'],
		]);
		$this->assertFalse(intersoccer_roster_report_row_counts_for_winback($refunded[0], 'history'));

		$revived = intersoccer_roster_report_rows_apply_live_order_status([
			['order_id' => 70001, 'order_status' => 'cancelled'],
		]);
		$this->assertTrue(intersoccer_roster_report_row_counts_for_winback($revived[0], 'history'));

		$on_hold = intersoccer_roster_report_rows_apply_live_order_status([
			['order_id' => 81234, 'order_status' => 'completed'],
		]);
		$this->assertFalse(intersoccer_roster_report_row_counts_for_winback($on_hold[0], 'history'));
		$this->assertTrue(intersoccer_roster_report_row_counts_for_winback($on_hold[0], 'upcoming_rebooked'));

		unset($GLOBALS['intersoccer_test_order_customer_id_map']);
		$legacy = [
			'order_id' => 90017,
			'customer_id' => 0,
			'player_index' => 0,
			'player_first_name' => 'Legacy',
			'player_last_name' => 'Customer',
		];
		if ($this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->counting_wpdb->postmeta_customer_user[90017] = '55009';
		}
		$this->seed_test_users_exist([55009]);
		if (function_exists('intersoccer_roster_report_reset_fake_customer_cache')) {
			intersoccer_roster_report_reset_fake_customer_cache();
		}
		$resolved = intersoccer_roster_report_row_with_resolved_customer($legacy);
		$this->assertSame(55009, (int) $resolved['customer_id']);
		$key = intersoccer_roster_report_build_player_key($resolved, '');
		$this->assertSame('name', $key['tier']);
		$this->assertSame(55009, (int) $resolved['customer_id']);
		$this->assertFalse(intersoccer_roster_report_is_fake_customer((int) $resolved['customer_id']));

		unset($GLOBALS['intersoccer_test_batch_chunk_size']);
	}

	public function test_order_created_date_prime_avoids_per_order_fallback_queries(): void {
		if (!$this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->markTestSkipped('Counting wpdb not available');
		}
		global $wpdb;
		$wpdb = $this->counting_wpdb;
		$this->reset_winback_loader_isolation_state();
		$GLOBALS['intersoccer_test_hpos_orders_enabled'] = false;
		$rows = [
			['order_id' => 62545],
			['order_id' => 70001],
			['order_id' => 81234],
		];
		if ($this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->counting_wpdb->shop_orders[62545] = ['post_status' => 'wc-completed', 'post_date' => '2025-04-01 10:00:00'];
			$this->counting_wpdb->shop_orders[70001] = ['post_status' => 'wc-completed', 'post_date' => '2025-04-02 10:00:00'];
			$this->counting_wpdb->shop_orders[81234] = ['post_status' => 'wc-completed', 'post_date' => '2025-04-03 10:00:00'];
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 2;
		intersoccer_winback_prime_order_created_dates_from_rows($rows);
		if ($this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->counting_wpdb->query_count = 0;
		}
		foreach ([62545, 70001, 81234] as $oid) {
			$this->assertSame(
				substr($this->counting_wpdb->shop_orders[ $oid ]['post_date'], 0, 10),
				intersoccer_winback_get_order_created_ymd($oid)
			);
		}
		if ($this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->assertSame(0, $this->counting_wpdb->query_count);
		}
		unset($GLOBALS['intersoccer_test_batch_chunk_size']);
	}

	public function test_catalogue_batch_variation_query_scales_by_parent_chunk_count(): void {
		if (!$this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->markTestSkipped('Counting wpdb not available');
		}
		global $wpdb;
		$wpdb = $this->counting_wpdb;
		$this->reset_winback_loader_isolation_state();
		$make_parents = static function (int $count): array {
			$map = [];
			for ($p = 1; $p <= $count; $p++) {
				$parent_id = 10000 + $p;
				$map[ $parent_id ] = [20000 + $p, 20000 + $p + 1000];
			}

			return $map;
		};
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 2;

		$this->counting_wpdb->variation_posts_by_parent = $make_parents(5);
		$this->counting_wpdb->query_count = 0;
		$small = intersoccer_winback_catalogue_batch_variation_posts(array_keys($this->counting_wpdb->variation_posts_by_parent));
		$small_queries = $this->counting_wpdb->query_count;
		$this->assertCount(10, $small);

		$this->counting_wpdb->variation_posts_by_parent = $make_parents(50);
		$this->counting_wpdb->query_count = 0;
		$large = intersoccer_winback_catalogue_batch_variation_posts(array_keys($this->counting_wpdb->variation_posts_by_parent));
		$large_queries = $this->counting_wpdb->query_count;
		$this->assertCount(100, $large);

		$this->assertSame(3, $small_queries);
		$this->assertSame(25, $large_queries);
		$this->assertSame(22, $large_queries - $small_queries);

		unset($GLOBALS['intersoccer_test_batch_chunk_size']);
	}

	public function test_winback_product_type_memo_limits_pv_calls_and_preserves_kinds(): void {
		$this->reset_winback_loader_isolation_state();
		$GLOBALS['intersoccer_test_get_product_type_calls'] = [];
		for ($parent = 1001; $parent <= 1005; $parent++) {
			\InterSoccerRRTestMeta::$data[ $parent ]['_intersoccer_product_type'] = ($parent % 2 === 1) ? 'camp' : 'course';
		}
		$rows = [];
		$parent_ids = [1001, 1002, 1003, 1004, 1005];
		$variation_ids = [2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2010];
		for ($i = 0; $i < 200; $i++) {
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

		$kinds_off = [];
		foreach ($rows as $row) {
			$kinds_off[] = intersoccer_winback_report_engine_row_kind($row);
		}

		$GLOBALS['intersoccer_test_get_product_type_calls'] = [];
		intersoccer_rr_product_type_memo_begin();
		intersoccer_rr_winback_prime_product_types_from_rows($rows);
		$kinds_on = [];
		foreach ($rows as $row) {
			$kind = intersoccer_winback_report_engine_row_kind($row);
			intersoccer_winback_resolve_row_event_date($row);
			intersoccer_roster_report_row_counts_for_winback($row, 'history');
			$kinds_on[] = $kind;
		}
		intersoccer_rr_product_type_memo_end();

		$this->assertSame($kinds_off, $kinds_on);
		foreach ($GLOBALS['intersoccer_test_get_product_type_calls'] as $id => $count) {
			$this->assertLessThanOrEqual(1, $count, 'PV lookup should run at most once per post ID ' . $id);
		}
		$this->assertLessThanOrEqual(15, count($GLOBALS['intersoccer_test_get_product_type_calls']));
		$this->assertSame(0, array_sum($GLOBALS['intersoccer_test_get_product_type_calls'] ?? []));
	}

	public function test_product_type_safe_without_memo_scope_calls_through_each_time(): void {
		$this->reset_winback_loader_isolation_state();
		$GLOBALS['intersoccer_test_get_product_type_calls'] = [];
		if (function_exists('intersoccer_rr_product_type_memo_reset')) {
			intersoccer_rr_product_type_memo_reset();
		}
		intersoccer_get_product_type_safe(1001, null);
		intersoccer_get_product_type_safe(1001, null);
		$this->assertSame(2, $GLOBALS['intersoccer_test_get_product_type_calls'][1001] ?? 0);
	}

	public function test_refund_batch_queries_bounded_after_prime_and_no_repeat_for_clean_orders(): void {
		if (!$this->counting_wpdb instanceof WinbackReportCountingWpdb) {
			$this->markTestSkipped('Counting wpdb not available');
		}
		global $wpdb;
		$wpdb = $this->counting_wpdb;
		$this->reset_winback_loader_isolation_state();
		$GLOBALS['intersoccer_test_hpos_orders_enabled'] = false;
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 3;
		$GLOBALS['intersoccer_test_wc_get_order_call_count'] = 0;

		$order_ids = [62545, 70001, 81234, 90017, 95001];
		$rows = [];
		foreach ($order_ids as $i => $oid) {
			$rows[] = [
				'order_id' => $oid,
				'order_item_id' => 60000 + $i,
				'order_status' => 'completed',
			];
			$this->counting_wpdb->shop_orders[ $oid ] = [
				'post_status' => 'wc-completed',
				'post_date' => '2025-01-01 00:00:00',
			];
		}
		$this->counting_wpdb->refund_parents[70001] = true;
		$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'] = [
			60001 => true,
		];

		$this->counting_wpdb->query_count = 0;
		intersoccer_roster_report_prime_line_refund_checks_from_rows($rows);
		$prime_queries = $this->counting_wpdb->query_count;
		$this->assertSame(2, $prime_queries);

		$this->counting_wpdb->query_count = 0;
		foreach ($rows as $row) {
			intersoccer_roster_report_row_line_is_fully_refunded($row);
		}
		$this->assertSame(0, $this->counting_wpdb->query_count);
		$this->assertTrue(intersoccer_roster_report_row_line_is_fully_refunded($rows[1]));
		$this->assertFalse(intersoccer_roster_report_row_line_is_fully_refunded($rows[0]));

		unset(
			$GLOBALS['intersoccer_test_batch_chunk_size'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids']
		);
	}
}

}

namespace {
	if (!function_exists('intersoccer_get_product_type')) {
		function intersoccer_get_product_type($post_id) {
			$post_id = (int) $post_id;
			$golden_map = $GLOBALS['intersoccer_test_golden_product_type_by_id'] ?? null;
			if (is_array($golden_map) && array_key_exists($post_id, $golden_map)) {
				return (string) $golden_map[ $post_id ];
			}
			if (!isset($GLOBALS['intersoccer_test_get_product_type_calls'])) {
				$GLOBALS['intersoccer_test_get_product_type_calls'] = [];
			}
			$GLOBALS['intersoccer_test_get_product_type_calls'][ $post_id ]
				= (int) ( $GLOBALS['intersoccer_test_get_product_type_calls'][ $post_id ] ?? 0 ) + 1;
			if ($post_id >= 2000 && $post_id < 3000) {
				return '';
			}
			if ($post_id >= 1000 && $post_id < 2000) {
				return ($post_id % 2 === 1) ? 'camp' : 'course';
			}

			return '';
		}
	}
	if (!function_exists('get_userdata')) {
		function get_userdata($id) {
			if (isset($GLOBALS['intersoccer_test_get_userdata_call_count'])) {
				$GLOBALS['intersoccer_test_get_userdata_call_count']++;
			}

			return $GLOBALS['intersoccer_test_get_userdata'][(int) $id] ?? false;
		}
	}
}
