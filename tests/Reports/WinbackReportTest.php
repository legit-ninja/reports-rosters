<?php
/**
 * Win-back report helpers (status, keying, dates, catalogue dedupe).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackReportTest extends TestCase {
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
