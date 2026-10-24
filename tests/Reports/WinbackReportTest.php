<?php
/**
 * Win-back report helpers (status, keying, dates, catalogue dedupe).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackReportTest extends TestCase {
	protected function tearDown(): void {
		unset(
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_live_order_status_batch_load_calls'],
			$GLOBALS['intersoccer_test_wc_get_order_callback']
		);
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['roster-report-loader.php', 'winback-report-facets.php', 'winback-catalogue.php', 'utils.php'] as $file) {
			$path = $includes . '/' . $file;
			if (file_exists($path)) {
				require_once $path;
			}
		}
		if (function_exists('intersoccer_winback_reset_unmapped_facets')) {
			intersoccer_winback_reset_unmapped_facets();
		}
	}

	public function test_live_order_status_overrides_stale_roster_for_policy() {
		$GLOBALS['intersoccer_test_live_order_status_map'] = [
			101 => 'wc-cancelled',
			102 => 'wc-completed',
		];

		$stale_completed = [
			'order_id' => 101,
			'order_status' => 'completed',
		];
		$rows = intersoccer_roster_report_rows_apply_live_order_status([$stale_completed]);
		$this->assertSame('wc-cancelled', $rows[0]['order_status']);
		$this->assertFalse(intersoccer_roster_report_status_counts_as_history($rows[0]['order_status']));
		$this->assertFalse(intersoccer_roster_report_status_counts_as_booked($rows[0]['order_status']));

		$stale_cancelled = [
			'order_id' => 102,
			'order_status' => 'cancelled',
		];
		$rows = intersoccer_roster_report_rows_apply_live_order_status([$stale_cancelled]);
		$this->assertSame('wc-completed', $rows[0]['order_status']);
		$this->assertTrue(intersoccer_roster_report_status_counts_as_history($rows[0]['order_status']));
		$this->assertTrue(intersoccer_roster_report_status_counts_as_booked($rows[0]['order_status']));
	}

	public function test_live_order_status_falls_back_to_roster_when_order_missing() {
		$GLOBALS['intersoccer_test_live_order_status_map'] = [];
		$row = [
			'order_id' => 99999,
			'order_status' => 'processing',
		];
		$rows = intersoccer_roster_report_rows_apply_live_order_status([$row]);
		$this->assertSame('processing', $rows[0]['order_status']);
	}

	public function test_live_order_status_batch_load_one_call_for_many_orders() {
		$GLOBALS['intersoccer_test_live_order_status_map'] = [
			1 => 'wc-completed',
			2 => 'wc-completed',
			3 => 'wc-pending',
		];
		$GLOBALS['intersoccer_test_live_order_status_batch_load_calls'] = 0;
		$rows = [
			['order_id' => 1, 'order_status' => 'cancelled'],
			['order_id' => 2, 'order_status' => 'cancelled'],
			['order_id' => 3, 'order_status' => 'cancelled'],
		];
		intersoccer_roster_report_rows_apply_live_order_status($rows);
		$this->assertSame(1, $GLOBALS['intersoccer_test_live_order_status_batch_load_calls']);
	}

	public function test_history_status_excludes_cancelled_refunded_failed_and_pending() {
		$this->assertFalse(intersoccer_roster_report_status_counts_as_history('cancelled'));
		$this->assertFalse(intersoccer_roster_report_status_counts_as_history('wc-refunded'));
		$this->assertFalse(intersoccer_roster_report_status_counts_as_history('failed'));
		$this->assertFalse(intersoccer_roster_report_status_counts_as_history('pending'));
		$this->assertFalse(intersoccer_roster_report_status_counts_as_history('on-hold'));
		$this->assertTrue(intersoccer_roster_report_status_counts_as_history('completed'));
		$this->assertTrue(intersoccer_roster_report_status_counts_as_history('processing'));
	}

	public function test_pending_counts_as_upcoming_rebooked_not_history() {
		$this->assertTrue(intersoccer_roster_report_status_counts_as_upcoming_rebooked('pending'));
		$this->assertFalse(intersoccer_roster_report_status_counts_as_history('pending'));
	}

	public function test_partial_refund_completed_still_counts_as_history() {
		$this->assertTrue(intersoccer_roster_report_status_counts_as_history('completed'));
		$this->assertTrue(intersoccer_roster_report_status_counts_as_booked('completed'));
	}

	public function test_customer_zero_resolves_before_keying_and_never_idx_zero_zero() {
		$GLOBALS['intersoccer_test_wc_get_order_callback'] = static function ($order_id) {
			$order_id = (int) $order_id;
			if ($order_id !== 9001) {
				return false;
			}
			return new class() {
				public function get_customer_id() {
					return 42;
				}
			};
		};

		$row = [
			'customer_id' => 0,
			'player_index' => 0,
			'order_id' => 9001,
			'player_first_name' => 'Test',
			'player_last_name' => 'Child',
		];
		$this->assertSame(42, intersoccer_roster_report_resolve_row_customer_id($row));
		$key = intersoccer_roster_report_build_player_key($row, '');
		$this->assertSame('name', $key['tier']);
		$this->assertStringStartsWith('name:42:', $key['key']);
		$this->assertNotSame('idx:0:0', $key['key']);
		unset($GLOBALS['intersoccer_test_wc_get_order_callback']);
	}

	public function test_fake_customer_proxy_after_resolution() {
		$GLOBALS['intersoccer_test_get_userdata'] = [];
		$this->assertTrue(intersoccer_roster_report_is_fake_customer(999));
		$GLOBALS['intersoccer_test_get_userdata'][5] = (object) ['ID' => 5];
		$this->assertFalse(intersoccer_roster_report_is_fake_customer(5));
		unset($GLOBALS['intersoccer_test_get_userdata']);
	}

	public function test_player_key_tiers_uuid_idx_name() {
		$row = [
			'customer_id' => 3,
			'player_index' => 2,
			'player_first_name' => 'A',
			'player_last_name' => 'B',
		];
		$uuid_key = intersoccer_roster_report_build_player_key($row, '11111111-2222-3333-4444-555555555555');
		$this->assertSame('uuid', $uuid_key['tier']);
		$this->assertStringStartsWith('uuid:', $uuid_key['key']);

		$idx_key = intersoccer_roster_report_build_player_key($row, '');
		$this->assertSame('idx', $idx_key['tier']);
		$this->assertSame('idx:3:2', $idx_key['key']);

		$row['player_index'] = 0;
		$idx_zero = intersoccer_roster_report_build_player_key($row, '');
		$this->assertSame('idx', $idx_zero['tier']);
		$this->assertSame('idx:3:0', $idx_zero['key']);
	}

	public function test_legacy_stored_customer_zero_skips_idx_tier() {
		$GLOBALS['intersoccer_test_wc_get_order_callback'] = static function ($order_id) {
			return new class() {
				public function get_customer_id() {
					return 99;
				}
			};
		};
		$row = intersoccer_roster_report_row_with_resolved_customer([
			'customer_id' => 0,
			'player_index' => 0,
			'order_id' => 9001,
			'player_first_name' => 'Legacy',
			'player_last_name' => 'Child',
		]);
		$key = intersoccer_roster_report_build_player_key($row, '');
		$this->assertSame('name', $key['tier']);
		$this->assertStringStartsWith('name:99:', $key['key']);
		$this->assertStringNotContainsString('idx:', $key['key']);
		unset($GLOBALS['intersoccer_test_wc_get_order_callback']);
	}

	public function test_cross_tier_uuid_upcoming_matches_idx_history_as_rebooked() {
		$map = new \Intersoccer_Roster_Report_Player_Identity_Map();
		$history_row = [
			'customer_id' => 12,
			'player_index' => 0,
			'player_first_name' => 'Mia',
			'player_last_name' => 'Test',
			'activity_type' => 'Camp',
		];
		$upcoming_row = [
			'customer_id' => 12,
			'player_index' => 0,
			'player_first_name' => 'Mia',
			'player_last_name' => 'Test',
			'activity_type' => 'Camp',
		];
		$uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
		$map->register_row($history_row, '');
		$map->register_row($upcoming_row, $uuid);

		$history_key = intersoccer_roster_report_build_player_key($history_row, '');
		$upcoming_key = intersoccer_roster_report_build_player_key($upcoming_row, $uuid);
		$this->assertSame('idx', $history_key['tier']);
		$this->assertSame('uuid', $upcoming_key['tier']);
		$this->assertTrue($map->same_player($history_key['key'], $upcoming_key['key']));
		$this->assertSame('uuid', $map->best_tier($history_key['key']));

		$upcoming = [
			[
				'player_key' => $upcoming_key['key'],
				'kind' => 'camp',
				'venue_slug' => 'zurich-lake',
			],
		];
		$flags = intersoccer_winback_detect_rebooked_flags($history_key['key'], 'camp', 'zurich-lake', $upcoming, $map);
		$this->assertTrue($flags['same_venue']);
	}

	public function test_aged_up_picks_next_eligible_slug() {
		$pick = intersoccer_winback_pick_target_age_slug(
			'3-5y',
			'2018-01-15',
			'2026-07-01',
			['3-5y', '5-13y']
		);
		$this->assertTrue($pick['aged_up']);
		$this->assertSame('5-13y', $pick['slug']);
	}

	public function test_unparseable_age_band_does_not_fatal() {
		$this->assertNull(intersoccer_winback_parse_age_group_bounds('Pre-K to G5'));
		$bounds = intersoccer_winback_parse_age_group_bounds('5-12');
		$this->assertNotNull($bounds);
		$this->assertSame(5, $bounds['min']);
	}

	public function test_rebooked_elsewhere_flag() {
		$upcoming = [
			['player_key' => 'uuid:abc', 'kind' => 'camp', 'venue_slug' => 'zurich-east'],
		];
		$flags = intersoccer_winback_detect_rebooked_flags('uuid:abc', 'camp', 'geneva-lake', $upcoming);
		$this->assertFalse($flags['same_venue']);
		$this->assertTrue($flags['elsewhere']);
	}

	public function test_sibling_detected_via_shared_customer_on_upcoming_rows() {
		$upcoming = [
			['customer_id' => 10, 'player_key' => 'idx:10:1', 'kind' => 'camp'],
			['customer_id' => 10, 'player_key' => 'idx:10:2', 'kind' => 'camp'],
		];
		$found = false;
		foreach ($upcoming as $row) {
			if ((int) $row['customer_id'] === 10 && $row['player_key'] !== 'idx:10:1' && $row['kind'] === 'camp') {
				$found = true;
			}
		}
		$this->assertTrue($found);
	}

	public function test_yearless_camp_terms_use_season_or_current_year_not_order_date() {
		if (!function_exists('intersoccer_parse_camp_dates_fixed')) {
			$this->markTestSkipped('intersoccer_parse_camp_dates_fixed not loaded');
		}
		$camp_terms = 'july-week-1-july-7-july-11-5-days';
		list($from_season,,) = intersoccer_parse_camp_dates_fixed($camp_terms, 'Summer 2024');
		$this->assertSame('2024-07-07', $from_season);

		list($yearless,,) = intersoccer_parse_camp_dates_fixed($camp_terms, '');
		$this->assertStringStartsWith((string) (int) date('Y') . '-', (string) $yearless);
		$this->assertNotSame('2024-07-07', $yearless);
	}

	public function test_order_date_source_when_only_order_date_available() {
		$GLOBALS['intersoccer_test_wc_get_order_callback'] = static function ($order_id) {
			return new class() {
				public function get_date_created() {
					return new class() {
						public function date($format) {
							return '2022-06-01';
						}
					};
				}
			};
		};
		$row = [
			'start_date' => null,
			'variation_id' => 0,
			'order_id' => 6001,
			'order_item_id' => 0,
			'camp_terms' => '',
			'season' => '',
			'activity_type' => 'Camp',
		];
		$bits = intersoccer_winback_resolve_row_event_date($row);
		$this->assertSame('2022-06-01', $bits['date']);
		$this->assertSame('order_date', $bits['source']);
	}

	public function test_camp_date_fallback_chain_and_unknown() {
		$vid = 88001;
		if (class_exists('\InterSoccerRRTestMeta')) {
			\InterSoccerRRTestMeta::$data = [];
		}
		update_post_meta($vid, '_camp_start_date', '2025-08-04');
		$row = [
			'start_date' => null,
			'variation_id' => $vid,
			'order_item_id' => 0,
			'camp_terms' => '',
			'season' => 'Summer Camps 2026',
			'activity_type' => 'Camp',
		];
		$bits = intersoccer_winback_resolve_row_event_date($row);
		$this->assertSame('2025-08-04', $bits['date']);
		$this->assertSame('variation_camp_start', $bits['source']);

		$unknown = intersoccer_winback_resolve_row_event_date([
			'start_date' => null,
			'variation_id' => 0,
			'order_id' => 0,
			'activity_type' => 'Camp',
		]);
		$this->assertNull($unknown['date']);
		$this->assertSame('unknown', $unknown['source']);
	}

	public function test_wpml_catalogue_dedupe_by_source_variation() {
		$rows = [
			['source_variation_id' => 100, 'variation_id' => 101, 'start_date' => '2026-11-01'],
			['source_variation_id' => 100, 'variation_id' => 102, 'start_date' => '2026-11-01'],
			['source_variation_id' => 200, 'variation_id' => 201, 'start_date' => '2026-12-01'],
		];
		$deduped = intersoccer_winback_dedupe_catalogue_variations($rows);
		$this->assertCount(2, $deduped);
	}

	public function test_en_slug_normalization_and_german_age_alias() {
		$slug = intersoccer_winback_normalize_facet_slug('5-12-jahre', 'pa_age-group', 'age_group');
		$this->assertSame('5-12y', $slug);
	}
}

}

namespace {
	if (!function_exists('get_userdata')) {
		function get_userdata($id) {
			return $GLOBALS['intersoccer_test_get_userdata'][(int) $id] ?? false;
		}
	}
}
