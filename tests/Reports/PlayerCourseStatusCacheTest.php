<?php
/**
 * Courses report: full-result transient cache per filter set.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/PlayerCourseStatusQueryCountTest.php';

class PlayerCourseStatusCacheTest extends TestCase {
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
		foreach (['reports-data.php', 'final-reports-aggregation.php', 'utils.php', 'roster-report-loader.php', 'winback-report-facets.php', 'player-camp-status.php', 'player-course-status.php'] as $file) {
			require_once $includes . '/' . $file;
		}
		$this->reset_cache_state();
		$this->enable_cache();
	}

	protected function tearDown(): void {
		$this->reset_cache_state();
		global $wpdb;
		$wpdb = $this->saved_wpdb;
		parent::tearDown();
	}

	private function enable_cache(): void {
		$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = true;
	}

	private function reset_cache_state(): void {
		$GLOBALS['intersoccer_test_pcs_transient_store'] = [];
		$GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] = 0;
		unset(
			$GLOBALS['intersoccer_test_pcs_cache_force_enabled'],
			$GLOBALS['intersoccer_test_pcs_cache_max_bytes'],
			$GLOBALS['intersoccer_test_pcs_cache_last_stored_bytes'],
			$GLOBALS['intersoccer_test_pcs_verify_refresh_nonce'],
			$GLOBALS['intersoccer_test_current_user_caps'],
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_customer_id_map']
		);
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->usermeta_player_rows = [];
			$this->counting_wpdb->user_emails = [];
			$this->counting_wpdb->course_roster_rows = [];
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function seed_minimal_fixture(): array {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 10,
				'user_email' => 'parent@example.test',
				'player_id' => 'uuid-cache',
				'player_index' => 0,
				'first_name' => 'Cache',
				'last_name' => 'Kid',
				'dob' => '2015-06-01',
			],
		];
		$row = [
			'activity_type' => 'Course',
			'product_name' => 'Course A',
			'venue' => 'Geneva Centre',
			'course_day' => 'Sunday',
			'times' => '10:00',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-01',
			'order_id' => 90001,
			'order_item_id' => 91001,
			'order_status' => 'completed',
			'customer_id' => 10,
			'player_index' => 0,
			'player_first_name' => 'Cache',
			'player_last_name' => 'Kid',
			'girls_only' => 0,
			'is_placeholder' => 0,
			'age_group' => '5-12y',
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [$row];
		$GLOBALS['intersoccer_test_live_order_status_map'] = [90001 => 'wc-completed'];
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [90001 => 10];

		return ['year' => 2026];
	}

	public function test_cache_slim_expand_roundtrip_matches_full_result(): void {
		$filters = $this->seed_minimal_fixture();
		$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = false;
		$full = intersoccer_player_course_status_generate($filters);
		$slim = intersoccer_player_course_status_cache_slim_result($full);
		$roundtrip = intersoccer_player_course_status_cache_expand_result($slim);
		$this->assertSame(maybe_serialize($full), maybe_serialize($roundtrip));
	}

	public function test_second_generate_with_same_filters_uses_cache(): void {
		$filters = $this->seed_minimal_fixture();
		intersoccer_player_course_status_generate($filters);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
		intersoccer_player_course_status_generate($filters);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_different_filters_rerun_generator(): void {
		$this->seed_minimal_fixture();
		intersoccer_player_course_status_generate(['year' => 2026]);
		intersoccer_player_course_status_generate(['year' => 2025]);
		$this->assertSame(2, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_result_with_warnings_is_not_cached(): void {
		$this->seed_minimal_fixture();
		unset($GLOBALS['intersoccer_test_player_course_status_roster_rows']);
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->simulate_roster_last_error = 'simulated failure';
			$this->counting_wpdb->last_error = '';
		}
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertNotEmpty($result['warnings']);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
		intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertSame(2, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_data_version_marker_change_invalidates_cache(): void {
		$filters = $this->seed_minimal_fixture();
		intersoccer_player_course_status_generate($filters);
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->roster_count = 99;
			$this->counting_wpdb->roster_max_id = 500;
		}
		intersoccer_player_course_status_generate($filters);
		$this->assertSame(2, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_cache_disabled_via_filter(): void {
		$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = false;
		$filters = $this->seed_minimal_fixture();
		intersoccer_player_course_status_generate($filters);
		intersoccer_player_course_status_generate($filters);
		$this->assertSame(2, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_force_refresh_regenerates_and_rewrites_cache(): void {
		$filters = $this->seed_minimal_fixture();
		intersoccer_player_course_status_generate($filters);
		intersoccer_player_course_status_generate($filters, ['force_cache_refresh' => true]);
		$this->assertSame(2, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
		intersoccer_player_course_status_generate($filters);
		$this->assertSame(2, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_refresh_request_invalid_nonce_does_not_bypass(): void {
		$filters = $this->seed_minimal_fixture();
		$_REQUEST['pcs_refresh'] = '1';
		$_REQUEST['_pcs_nonce'] = 'bad';
		$GLOBALS['intersoccer_test_pcs_verify_refresh_nonce'] = false;
		intersoccer_player_course_status_generate($filters);
		intersoccer_player_course_status_generate($filters);
		unset($_REQUEST['pcs_refresh'], $_REQUEST['_pcs_nonce'], $GLOBALS['intersoccer_test_pcs_verify_refresh_nonce']);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_refresh_request_without_capability_does_not_bypass(): void {
		$filters = $this->seed_minimal_fixture();
		$GLOBALS['intersoccer_test_current_user_caps'] = ['manage_options' => false];
		$_REQUEST['pcs_refresh'] = '1';
		$_REQUEST['_pcs_nonce'] = 'ok';
		$GLOBALS['intersoccer_test_pcs_verify_refresh_nonce'] = true;
		intersoccer_player_course_status_generate($filters);
		intersoccer_player_course_status_generate($filters);
		unset($_REQUEST['pcs_refresh'], $_REQUEST['_pcs_nonce'], $GLOBALS['intersoccer_test_current_user_caps'], $GLOBALS['intersoccer_test_pcs_verify_refresh_nonce']);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_oversize_result_is_not_stored(): void {
		$GLOBALS['intersoccer_test_pcs_cache_max_bytes'] = 64;
		$filters = $this->seed_minimal_fixture();
		intersoccer_player_course_status_generate($filters);
		intersoccer_player_course_status_generate($filters);
		unset($GLOBALS['intersoccer_test_pcs_cache_max_bytes']);
		$this->assertSame(2, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_data_version_marker_query_count(): void {
		if (!($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb)) {
			$this->markTestSkipped('Counting wpdb required');
		}
		$before = $this->counting_wpdb->query_count;
		intersoccer_player_course_status_cache_data_version();
		$delta = $this->counting_wpdb->query_count - $before;
		$this->assertSame(3, $delta);
	}

	public function test_refresh_url_includes_nonce_and_arg(): void {
		$url = intersoccer_player_course_status_refresh_url(['year' => 2026]);
		$this->assertStringContainsString('pcs_refresh=1', $url);
		$this->assertStringContainsString('_pcs_nonce=', $url);
		$this->assertStringContainsString('intersoccer-player-course-status', $url);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function seed_4100_player_fixture(): array {
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			for ($i = 1; $i <= 4100; $i++) {
				$uid = 5000 + $i;
				$this->counting_wpdb->usermeta_player_rows[] = [
					'user_id' => $uid,
					'meta_value' => [
						0 => [
							'first_name' => 'P',
							'last_name' => 'N' . $i,
							'player_id' => 'uuid-size-' . $i,
							'dob' => '2015-01-01',
						],
					],
				];
				$this->counting_wpdb->user_emails[ $uid ] = 'size' . $i . '@example.test';
			}
		}
		$rows = [];
		for ($i = 1; $i <= 4100; $i++) {
			$order_id = 120000 + $i;
			$rows[] = [
				'order_id' => $order_id,
				'order_item_id' => 121000 + $i,
				'activity_type' => 'Course',
				'product_name' => 'Course ' . $i,
				'venue' => 'Geneva Centre',
				'course_day' => 'sunday',
				'times' => '10:00',
				'season' => 'Autumn 2026',
				'start_date' => '2026-09-01',
				'end_date' => '2026-12-01',
				'order_status' => 'completed',
				'customer_id' => 5000 + $i,
				'player_index' => 0,
				'player_first_name' => 'P',
				'player_last_name' => 'N' . $i,
				'girls_only' => 0,
				'is_placeholder' => 0,
				'age_group' => '5-12y',
			];
			$GLOBALS['intersoccer_test_live_order_status_map'][ $order_id ] = 'wc-completed';
			$GLOBALS['intersoccer_test_order_customer_id_map'][ $order_id ] = 5000 + $i;
		}
		unset($GLOBALS['intersoccer_test_player_course_status_players']);
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;

		return ['year' => 2026];
	}

	public function test_synthetic_4100_player_fixture_stores_and_hits_cache(): void {
		$filters = $this->seed_4100_player_fixture();
		$uncached = intersoccer_player_course_status_generate($filters);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);

		$key = intersoccer_player_course_status_cache_transient_key(
			intersoccer_player_course_status_normalize_filters($filters)
		);
		$this->assertArrayHasKey($key, $GLOBALS['intersoccer_test_pcs_transient_store']);
		$stored = $GLOBALS['intersoccer_test_pcs_transient_store'][ $key ];
		$this->assertIsString($stored);
		$stored_bytes = strlen($stored);
		$this->assertLessThanOrEqual(
			intersoccer_player_course_status_cache_max_bytes(),
			$stored_bytes,
			'stored base64 payload must fit under default cap'
		);
		$raw_bytes = strlen(maybe_serialize($uncached));
		$GLOBALS['intersoccer_test_pcs_fixture_4100_raw_serialized_bytes'] = $raw_bytes;
		$GLOBALS['intersoccer_test_pcs_fixture_4100_stored_bytes'] = $stored_bytes;

		$cached = intersoccer_player_course_status_generate($filters);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
		$this->assertSame(maybe_serialize($uncached), maybe_serialize($cached));
	}

	public function test_corrupt_transient_regenerates_without_errors(): void {
		$filters = $this->seed_minimal_fixture();
		$key = intersoccer_player_course_status_cache_transient_key(
			intersoccer_player_course_status_normalize_filters($filters)
		);
		$GLOBALS['intersoccer_test_pcs_transient_store'][ $key ] = '%%%not-base64-gzip%%%';
		$result = intersoccer_player_course_status_generate($filters);
		$this->assertIsArray($result);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
		$this->assertArrayHasKey($key, $GLOBALS['intersoccer_test_pcs_transient_store']);
		$this->assertIsString($GLOBALS['intersoccer_test_pcs_transient_store'][ $key ]);

		$GLOBALS['intersoccer_test_pcs_transient_store'][ $key ] = base64_encode('not-gzip-data');
		intersoccer_player_course_status_generate($filters);
		$this->assertSame(2, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_cap_applies_to_stored_compressed_payload(): void {
		$GLOBALS['intersoccer_test_pcs_cache_max_bytes'] = 64;
		$filters = $this->seed_minimal_fixture();
		intersoccer_player_course_status_generate($filters);
		$key = intersoccer_player_course_status_cache_transient_key(
			intersoccer_player_course_status_normalize_filters($filters)
		);
		$this->assertArrayNotHasKey($key, $GLOBALS['intersoccer_test_pcs_transient_store']);
		unset($GLOBALS['intersoccer_test_pcs_cache_max_bytes']);
	}
}
