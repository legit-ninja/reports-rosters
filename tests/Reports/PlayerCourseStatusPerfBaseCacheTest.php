<?php
/**
 * Year-base PCS cache: filter-from-base equivalence, TTL/schema, warm-up, Summer exactness.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/PlayerCourseStatusQueryCountTest.php';

class PlayerCourseStatusPerfBaseCacheTest extends TestCase {
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
		foreach (['reports-data.php', 'final-reports-aggregation.php', 'utils.php', 'roster-report-loader.php', 'winback-report-facets.php', 'winback-report-cache.php', 'player-camp-status.php', 'player-course-status.php', 'player-course-status-warmup.php'] as $file) {
			require_once $includes . '/' . $file;
		}
		$this->reset_state();
		$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = true;
	}

	protected function tearDown(): void {
		$this->reset_state();
		global $wpdb;
		$wpdb = $this->saved_wpdb;
		unset($GLOBALS['intersoccer_test_pcs_cache_ttl_override']);
		parent::tearDown();
	}

	private function reset_state(): void {
		$GLOBALS['intersoccer_test_pcs_transient_store'] = [];
		$GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] = 0;
		$GLOBALS['intersoccer_test_wp_schedule_single_event_log'] = [];
		$GLOBALS['intersoccer_test_wp_next_scheduled'] = [];
		$GLOBALS['intersoccer_test_wp_schedule_event_log'] = [];
		unset(
			$GLOBALS['intersoccer_test_pcs_warmup_allow_schedule'],
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_customer_id_map']
		);
	}

	/**
	 * @return PlayerCourseStatusQueryCountTest
	 */
	private function query_fixture(): PlayerCourseStatusQueryCountTest {
		$helper = new PlayerCourseStatusQueryCountTest('helper');
		return $helper;
	}

	private function seed_dual_season_fixture(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 42,
				'user_email' => 'p@example.test',
				'player_id' => 'uuid-1',
				'player_index' => 0,
				'first_name' => 'Kid',
				'last_name' => 'One',
				'dob' => '2015-01-01',
			],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->course_row(['season' => 'Summer 2026', 'order_item_id' => 8001, 'order_id' => 7001]),
			$this->course_row(['season' => 'Spring/Summer 2026', 'order_item_id' => 8002, 'order_id' => 7002]),
			$this->course_row(['season' => 'Autumn 2026', 'order_item_id' => 8003, 'order_id' => 7003]),
		];
		foreach ([7001, 7002, 7003] as $oid) {
			$GLOBALS['intersoccer_test_live_order_status_map'][ $oid ] = 'wc-completed';
			$GLOBALS['intersoccer_test_order_customer_id_map'][ $oid ] = 42;
		}
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function course_row(array $overrides = []): array {
		return array_merge([
			'activity_type' => 'Course',
			'product_name' => 'Course A',
			'venue' => 'Geneva Centre',
			'course_day' => 'Sunday',
			'times' => '10:00',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-01',
			'order_id' => 7001,
			'order_item_id' => 8001,
			'order_status' => 'completed',
			'customer_id' => 42,
			'player_index' => 0,
			'player_first_name' => 'Kid',
			'player_last_name' => 'One',
			'girls_only' => 0,
			'is_placeholder' => 0,
			'age_group' => '5-12y',
		], $overrides);
	}

	public function test_schema_version_is_v3_and_legacy_v2_blob_is_not_used(): void {
		$this->assertSame(5, intersoccer_player_course_status_cache_schema_version());
		$key = intersoccer_player_course_status_cache_transient_key(['year' => 2026]);
		$legacy_payload = base64_encode((string) gzcompress(maybe_serialize(['rows' => []]), 6));
		$GLOBALS['intersoccer_test_pcs_transient_store'][ $key ] = $legacy_payload;
		$this->assertNull(intersoccer_player_course_status_cache_read_base(2026));
	}

	public function test_default_ttl_is_twelve_hours_and_filter_overrides(): void {
		$expected = defined('HOUR_IN_SECONDS') ? 12 * HOUR_IN_SECONDS : 43200;
		$this->assertSame($expected, intersoccer_player_course_status_cache_ttl());
		$GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
		add_filter('intersoccer_player_course_status_cache_ttl', static function () {
			return 99;
		});
		$this->assertSame(99, intersoccer_player_course_status_cache_ttl());
		unset($GLOBALS['intersoccer_test_emit_wp_hooks']);
	}

	public function test_summer_filter_excludes_spring_summer_compound_rows(): void {
		$this->seed_dual_season_fixture();
		$scoped = intersoccer_player_course_status_filter_scoped_rows(
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			2026,
			'Summer',
			'all'
		);
		$this->assertCount(1, $scoped);
		$this->assertSame('Summer 2026', $scoped[0]['season']);
	}

	public function test_different_season_filters_same_year_reuse_single_base_build(): void {
		$this->seed_dual_season_fixture();
		intersoccer_player_course_status_generate(['year' => 2026, 'season_type' => 'Summer']);
		intersoccer_player_course_status_generate(['year' => 2026, 'season_type' => 'Autumn']);
		$this->assertSame(1, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
	}

	public function test_warm_generate_does_zero_wpdb_and_api_lookups(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$this->seed_dual_season_fixture();
		intersoccer_player_course_status_generate(['year' => 2026]);
		$GLOBALS['intersoccer_rr_test_api_counting_enabled'] = true;
		$GLOBALS['intersoccer_rr_test_api_call_counts'] = [];
		$this->counting_wpdb->query_count = 0;
		intersoccer_player_course_status_generate(['year' => 2026, 'season_type' => 'Autumn', 'booked' => 'booked']);
		$this->assertSame(0, (int) $this->counting_wpdb->query_count);
		$api_total = array_sum($GLOBALS['intersoccer_rr_test_api_call_counts'] ?? []);
		$this->assertSame(0, $api_total);
		unset($GLOBALS['intersoccer_rr_test_api_counting_enabled'], $GLOBALS['intersoccer_rr_test_api_call_counts']);
	}

	public function test_cold_build_query_count_bounded_across_player_volume(): void {
		if (!$this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->markTestSkipped('Counting wpdb missing');
		}
		$GLOBALS['intersoccer_test_batch_chunk_size'] = 500;
		$helper = $this->query_fixture();
		$run = function (int $player_count) use ($helper) {
			$reflection = new \ReflectionClass($helper);
			$seed_meta = $reflection->getMethod('seed_usermeta_players');
			$seed_meta->setAccessible(true);
			$seed_roster = $reflection->getMethod('seed_roster_rows');
			$seed_roster->setAccessible(true);
			$seed_roster->invoke($helper, max(10, $player_count));
			$seed_meta->invoke($helper, $player_count);
			unset($GLOBALS['intersoccer_test_player_course_status_players'], $GLOBALS['intersoccer_test_player_course_status_roster_rows']);
			$this->counting_wpdb->query_count = 0;
			intersoccer_player_course_status_build_year_base_dataset(2026, false);

			return (int) $this->counting_wpdb->query_count;
		};
		$small = $run(10);
		$large = $run(100);
		$delta = abs($large - $small);
		$this->assertLessThanOrEqual(12, $delta, "query delta {$delta} (small={$small}, large={$large}) must stay within constant bound");
	}

	public function test_order_touch_schedules_one_pcs_and_one_winback_event_without_inline_build(): void {
		$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = true;
		$GLOBALS['intersoccer_test_winback_cache_force_enabled'] = true;
		$GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
		intersoccer_rr_pcs_warmup_bootstrap();
		$GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] = 0;
		$GLOBALS['intersoccer_test_winback_engine_generate_call_count'] = 0;
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->counting_wpdb->query_count = 0;
		}
		for ($i = 0; $i < 5; $i++) {
			do_action('woocommerce_order_status_changed', 100 + $i, 'pending', 'completed', null);
			do_action('woocommerce_update_order', 200 + $i, null);
		}
		$this->assertSame(0, (int) $GLOBALS['intersoccer_test_pcs_generate_inner_call_count']);
		$this->assertSame(0, (int) ($GLOBALS['intersoccer_test_winback_engine_generate_call_count'] ?? 0));
		if ($this->counting_wpdb instanceof PlayerCourseStatusCountingWpdb) {
			$this->assertSame(0, (int) $this->counting_wpdb->query_count);
		}
		$log = $GLOBALS['intersoccer_test_wp_schedule_single_event_log'] ?? [];
		$pcs = 0;
		$wb = 0;
		foreach ($log as $entry) {
			if (($entry['hook'] ?? '') === intersoccer_rr_pcs_warmup_year_event_hook()) {
				$pcs++;
			}
			if (($entry['hook'] ?? '') === intersoccer_rr_winback_warmup_event_hook()) {
				$wb++;
			}
		}
		$this->assertSame(1, $pcs);
		$this->assertSame(1, $wb);
		unset($GLOBALS['intersoccer_test_emit_wp_hooks']);
	}

	public function test_daily_warmup_cron_registered_on_bootstrap(): void {
		$GLOBALS['intersoccer_test_wp_schedule_event_log'] = [];
		if (function_exists('intersoccer_rr_pcs_warmup_bootstrap')) {
			intersoccer_rr_pcs_warmup_bootstrap();
		}
		$hooks = array_column($GLOBALS['intersoccer_test_wp_schedule_event_log'] ?? [], 'hook');
		$this->assertContains(intersoccer_rr_pcs_warmup_daily_cron_hook(), $hooks);
	}
}
