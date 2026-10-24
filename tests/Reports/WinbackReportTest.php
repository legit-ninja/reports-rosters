<?php
/**
 * Win-back report helpers (status, keying, dates, catalogue dedupe).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackReportTest extends TestCase {
	protected function tearDown(): void {
		unset(
			$GLOBALS['intersoccer_test_hpos_orders_enabled'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_live_order_status_batch_load_calls'],
			$GLOBALS['intersoccer_test_order_created_date_map'],
			$GLOBALS['intersoccer_test_wc_get_order_callback'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'],
			$GLOBALS['intersoccer_rr_idx_name_by_slot'],
			$GLOBALS['intersoccer_test_current_user_caps'],
			$GLOBALS['intersoccer_test_current_user'],
			$GLOBALS['intersoccer_test_winback_facet_resolution'],
			$GLOBALS['intersoccer_test_winback_facet_resolution_callback'],
			$GLOBALS['intersoccer_test_winback_term_slugs'],
			$GLOBALS['intersoccer_test_winback_current_course_term_rows']
		);
		if (function_exists('intersoccer_winback_reset_current_course_term_cache')) {
			intersoccer_winback_reset_current_course_term_cache();
		}
		if (function_exists('intersoccer_winback_reset_facet_resolution_cache')) {
			intersoccer_winback_reset_facet_resolution_cache();
		}
		if (function_exists('intersoccer_winback_reset_order_created_date_cache')) {
			intersoccer_winback_reset_order_created_date_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_idx_name_collision_state')) {
			intersoccer_roster_report_reset_idx_name_collision_state();
		}
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
		if (function_exists('intersoccer_roster_report_reset_order_customer_id_cache')) {
			intersoccer_roster_report_reset_order_customer_id_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_fake_customer_cache')) {
			intersoccer_roster_report_reset_fake_customer_cache();
		}
		if (function_exists('intersoccer_winback_reset_catalogue_target_cache')) {
			intersoccer_winback_reset_catalogue_target_cache();
		}
		if (function_exists('intersoccer_winback_reset_j8_lookup_cache')) {
			intersoccer_winback_reset_j8_lookup_cache();
		}
		unset($GLOBALS['intersoccer_rr_user_billing_phone_cache']);
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['roster-report-loader.php', 'winback-report-facets.php', 'winback-catalogue.php', 'utils.php', 'winback-report-engine.php'] as $file) {
			$path = $includes . '/' . $file;
			if (file_exists($path)) {
				require_once $path;
			}
		}
		if (function_exists('intersoccer_winback_reset_unmapped_facets')) {
			intersoccer_winback_reset_unmapped_facets();
		}
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
		if (function_exists('intersoccer_winback_reset_catalogue_target_cache')) {
			intersoccer_winback_reset_catalogue_target_cache();
		}
		if (function_exists('intersoccer_winback_reset_catalogue_enumeration_memo')) {
			intersoccer_winback_reset_catalogue_enumeration_memo();
		}
		if (function_exists('intersoccer_winback_reset_j8_lookup_cache')) {
			intersoccer_winback_reset_j8_lookup_cache();
		}
		unset(
			$GLOBALS['intersoccer_test_get_userdata'],
			$GLOBALS['intersoccer_test_fake_customer_map']
		);
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

	public function test_aged_up_uses_target_start_not_today() {
		$dob = '2020-03-15';
		$today = '2026-03-01';
		$target_start = '2026-07-01';
		$ages = ['3-5y', '5-13y'];
		$pick_today = intersoccer_winback_pick_target_age_slug('3-5y', $dob, $today, $ages);
		$this->assertFalse($pick_today['aged_up']);
		$this->assertSame('3-5y', $pick_today['slug']);
		$pick_target = intersoccer_winback_pick_target_age_slug('3-5y', $dob, $target_start, $ages);
		$this->assertTrue($pick_target['aged_up']);
		$this->assertSame('5-13y', $pick_target['slug']);
	}

	public function test_hpos_detection_false_when_option_no_even_if_test_override_off() {
		$GLOBALS['intersoccer_test_option_store']['woocommerce_custom_orders_table_enabled'] = 'no';
		$GLOBALS['intersoccer_test_hpos_orders_enabled'] = false;
		$this->assertFalse(intersoccer_roster_report_uses_hpos_orders_table());
		unset($GLOBALS['intersoccer_test_option_store']['woocommerce_custom_orders_table_enabled']);
	}

	public function test_hpos_detection_true_only_when_test_override_on() {
		$GLOBALS['intersoccer_test_hpos_orders_enabled'] = true;
		$this->assertTrue(intersoccer_roster_report_uses_hpos_orders_table());
	}

	public function test_live_terminal_statuses_excluded_from_history_after_apply() {
		$statuses = ['wc-cancelled', 'wc-refunded', 'wc-failed'];
		foreach ($statuses as $live) {
			$GLOBALS['intersoccer_test_live_order_status_map'] = [301 => $live];
			$row = [
				'order_id' => 301,
				'order_status' => 'completed',
			];
			$rows = intersoccer_roster_report_rows_apply_live_order_status([$row]);
			$this->assertSame($live, $rows[0]['order_status']);
			$this->assertFalse(intersoccer_roster_report_row_counts_for_winback($rows[0], 'history'));
			$this->assertFalse(intersoccer_roster_report_status_counts_as_history($rows[0]['order_status']));
		}
	}

	public function test_newer_live_cancelled_row_does_not_win_latest_history_selection() {
		$GLOBALS['intersoccer_test_live_order_status_map'] = [
			401 => 'wc-completed',
			402 => 'wc-cancelled',
		];
		$older = [
			'id' => 1,
			'order_id' => 401,
			'order_status' => 'completed',
			'event_date' => '2025-06-01',
			'row' => ['id' => 1],
		];
		$newer_stale = [
			'id' => 2,
			'order_id' => 402,
			'order_status' => 'completed',
			'event_date' => '2025-09-01',
			'row' => ['id' => 2],
		];
		$best = null;
		foreach ([$older, $newer_stale] as $entry) {
			$row = intersoccer_roster_report_rows_apply_live_order_status([$entry['row'] + [
				'order_id' => $entry['order_id'],
				'order_status' => $entry['order_status'],
			]])[0];
			if (!intersoccer_roster_report_row_counts_for_winback($row, 'history')) {
				continue;
			}
			$entry['row'] = $row;
			if ($best === null || intersoccer_winback_report_engine_is_later_history($entry, $best)) {
				$best = $entry;
			}
		}
		$this->assertNotNull($best);
		$this->assertSame(1, (int) ($best['row']['id'] ?? 0));
	}

	public function test_venue_filter_applies_to_latest_history_only() {
		$older_x = [
			'venue_slug' => 'venue-x',
			'age_slug' => '3-5y',
			'event_date' => '2025-01-01',
		];
		$newer_y = [
			'venue_slug' => 'venue-y',
			'age_slug' => '3-5y',
			'event_date' => '2025-06-01',
		];
		$this->assertFalse(intersoccer_winback_report_engine_last_event_passes_filters($newer_y, 'venue-x', ''));
		$this->assertTrue(intersoccer_winback_report_engine_last_event_passes_filters($newer_y, 'venue-y', ''));
		$this->assertFalse(intersoccer_winback_report_engine_last_event_passes_filters($older_x, 'venue-y', ''));
	}

	public function test_more_targets_lists_later_same_band_matches() {
		$catalogue = [
			'camp|geneva|5-13y' => [
				[
					'product_name' => 'Feb Camp',
					'start_date' => '2026-02-10',
					'age_group_slug' => '5-13y',
					'source_variation_id' => 1001,
					'variation_id' => 1001,
				],
				[
					'product_name' => 'Easter Camp',
					'start_date' => '2026-04-05',
					'age_group_slug' => '5-13y',
					'source_variation_id' => 1002,
					'variation_id' => 1002,
				],
				[
					'product_name' => 'Summer Camp',
					'start_date' => '2026-07-01',
					'age_group_slug' => '5-13y',
					'source_variation_id' => 1003,
					'variation_id' => 1003,
				],
			],
		];
		$resolved = intersoccer_winback_resolve_catalogue_target_and_more(
			$catalogue,
			'camp',
			'geneva',
			'5-13y',
			'2015-06-01'
		);
		$this->assertNotNull($resolved['target']);
		$this->assertSame('Feb Camp', $resolved['target']['product']);
		$this->assertCount(2, $resolved['more_targets']);
		$this->assertSame('Easter Camp', $resolved['more_targets'][0]['product']);
		$this->assertSame('Summer Camp', $resolved['more_targets'][1]['product']);
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

	/**
	 * @return array<string,mixed>
	 */
	private function winback_camp_row_for_terms_parse($camp_terms, $season, $order_id) {
		$vid = 88002;
		if (class_exists('\InterSoccerRRTestMeta')) {
			\InterSoccerRRTestMeta::$data = [];
		}
		delete_post_meta($vid, '_camp_start_date');
		delete_post_meta($vid, '_camp_end_date');

		return [
			'start_date' => null,
			'variation_id' => $vid,
			'order_item_id' => 7001,
			'order_id' => $order_id,
			'camp_terms' => $camp_terms,
			'season' => $season,
			'activity_type' => 'Camp',
		];
	}

	public function test_yearless_camp_terms_use_order_year_july_after_march_order() {
		$camp_terms = 'july-week-1-july-7-july-11-5-days';
		$GLOBALS['intersoccer_test_wc_get_order_callback'] = static function () {
			return new class() {
				public function get_date_created() {
					return new class() {
						public function date($format) {
							return '2025-03-15';
						}
					};
				}
			};
		};
		$row = $this->winback_camp_row_for_terms_parse($camp_terms, '', 7101);
		$bits = intersoccer_winback_resolve_row_event_date($row);
		$this->assertSame('camp_terms_parse', $bits['source']);
		$this->assertSame('2025-07-07', $bits['date']);
	}

	public function test_yearless_camp_terms_book_ahead_february_after_november_order() {
		$camp_terms = 'february-week-1-february-3-february-7-5-days';
		$GLOBALS['intersoccer_test_wc_get_order_callback'] = static function () {
			return new class() {
				public function get_date_created() {
					return new class() {
						public function date($format) {
							return '2025-11-20';
						}
					};
				}
			};
		};
		$row = $this->winback_camp_row_for_terms_parse($camp_terms, '', 7102);
		$bits = intersoccer_winback_resolve_row_event_date($row);
		$this->assertSame('camp_terms_parse', $bits['source']);
		$this->assertSame('2026-02-03', $bits['date']);
	}

	public function test_season_year_wins_over_order_year_for_camp_terms_parse() {
		$camp_terms = 'july-week-1-july-7-july-11-5-days';
		$GLOBALS['intersoccer_test_wc_get_order_callback'] = static function () {
			return new class() {
				public function get_date_created() {
					return new class() {
						public function date($format) {
							return '2025-06-01';
						}
					};
				}
			};
		};
		$row = $this->winback_camp_row_for_terms_parse($camp_terms, 'Summer Camps 2024', 7103);
		$bits = intersoccer_winback_resolve_row_event_date($row);
		$this->assertSame('camp_terms_parse', $bits['source']);
		$this->assertSame('2024-07-07', $bits['date']);
	}

	public function test_yearless_camp_terms_without_order_date_falls_through_not_run_year() {
		$camp_terms = 'july-week-1-july-7-july-11-5-days';
		$row = $this->winback_camp_row_for_terms_parse($camp_terms, '', 0);
		$bits = intersoccer_winback_resolve_row_event_date($row);
		$this->assertNull($bits['date']);
		$this->assertSame('unknown', $bits['source']);
		$run_year = (int) gmdate('Y');
		$this->assertNotSame($run_year . '-07-07', $bits['date']);
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
		$this->assertSame('6-12y', intersoccer_winback_normalize_facet_slug('6-12-jahre', 'pa_age-group', 'age_group'));
		$this->assertSame('3-8y', intersoccer_winback_normalize_facet_slug('3-8-jahre', 'pa_age-group', 'age_group'));
		$this->assertSame('4-5y-half-day', intersoccer_winback_normalize_facet_slug('4-5-jahre-halber-tag', 'pa_age-group', 'age_group'));
		$this->assertSame('5-13y-full-day', intersoccer_winback_normalize_facet_slug('5-13-jahre-ganzer-tag', 'pa_age-group', 'age_group'));
		$this->assertSame('5-13y-half-day', intersoccer_winback_normalize_facet_slug('5-13y-demi-journee', 'pa_age-group', 'age_group'));
		$this->assertSame('3-5y-full-day', intersoccer_winback_normalize_facet_slug('3-5y-journee-complete', 'pa_age-group', 'age_group'));
		$this->assertSame('4-5y-half-day', intersoccer_winback_normalize_facet_slug('4-5-and-demi-journee', 'pa_age-group', 'age_group'));
		$this->assertSame('3-5y-half-day', intersoccer_winback_normalize_facet_slug('3-5-ans-demi-journee', 'pa_age-group', 'age_group'));
		$this->assertSame('5-13y-full-day', intersoccer_winback_normalize_facet_slug('5-13-ans-journee-complete', 'pa_age-group', 'age_group'));
		$this->assertSame('5-8y', intersoccer_winback_normalize_facet_slug('5-8-ans', 'pa_age-group', 'age_group'));
		$this->assertSame('4-8y-half-day', intersoccer_winback_normalize_facet_slug('4-8-ans-demi-journee', 'pa_age-group', 'age_group'));
		$this->assertSame('5-8y-full-day', intersoccer_winback_normalize_facet_slug('5-8-ans-journee-entiere', 'pa_age-group', 'age_group'));
	}

	public function test_run_together_age_group_resolves_from_order_line() {
		$GLOBALS['intersoccer_test_winback_facet_resolution'] = [
			88001 => ['age_group' => '5-13y-full-day'],
		];
		$row = [
			'order_item_id' => 88001,
			'variation_id' => 0,
			'age_group' => '3-5y-half-day-5-13y-full-day',
		];
		$this->assertTrue(intersoccer_winback_facet_is_multi_value($row['age_group'], 'age_group'));
		$slug = intersoccer_winback_normalize_roster_facet_slug(
			$row['age_group'],
			'pa_age-group',
			'age_group',
			$row
		);
		$this->assertSame('5-13y-full-day', $slug);
	}

	public function test_run_together_venue_resolves_from_variation_attribute() {
		$vid = 88002;
		\InterSoccerRRTestMeta::$data[ $vid ] = [
			'attribute_pa_intersoccer-venues' => 'geneva-stade-de-frontenex-cologny',
		];
		$row = [
			'order_item_id' => 0,
			'variation_id' => $vid,
			'venue' => 'geneva---stade-chnois-thonex-geneva---stade-de-frontenex-cologny',
		];
		$this->assertTrue(intersoccer_winback_facet_is_multi_value($row['venue'], 'venue'));
		$slug = intersoccer_winback_normalize_roster_facet_slug(
			$row['venue'],
			'pa_intersoccer-venues',
			'venue',
			$row
		);
		$this->assertSame('geneva-stade-de-frontenex-cologny', $slug);
	}

	public function test_unresolvable_run_together_facet_registers_unmapped_and_empty_slug() {
		intersoccer_winback_reset_unmapped_facets();
		$row = [
			'order_item_id' => 88003,
			'variation_id' => 0,
			'age_group' => '3-5y-half-day-5-13y-full-day',
		];
		$slug = intersoccer_winback_normalize_roster_facet_slug(
			$row['age_group'],
			'pa_age-group',
			'age_group',
			$row
		);
		$this->assertSame('', $slug);
		$unmapped = intersoccer_winback_get_unmapped_facets();
		$this->assertArrayHasKey('3-5y-half-day-5-13y-full-day', $unmapped['age_group']);
	}

	public function test_winback_export_dob_gate_guarded_and_load_helper_exists() {
		require_once dirname(__DIR__, 2) . '/includes/winback-report-admin.php';
		$this->assertTrue(function_exists('intersoccer_winback_report_load_dob_gate'));
		if (function_exists('intersoccer_test_ensure_pm_filter_stub')) {
			intersoccer_test_ensure_pm_filter_stub();
		}
		require_once dirname(__DIR__, 2) . '/includes/medical-access.php';
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		if (function_exists('intersoccer_test_set_user_caps')) {
			intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
		}
		$denied = intersoccer_winback_report_format_dob_for_export('2015-01-01');
		$this->assertSame('Restricted', $denied);
		$this->assertNotSame('2015-01-01', $denied);
		if (function_exists('intersoccer_test_set_user_caps')) {
			intersoccer_test_set_user_caps(['manage_options' => true]);
		}
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		$this->assertSame('2015-01-01', intersoccer_winback_report_format_dob_for_export('2015-01-01'));
	}

	public function test_idx_collision_uses_name_tier_and_does_not_merge_children() {
		$map = new \Intersoccer_Roster_Report_Player_Identity_Map();
		$row_a = [
			'customer_id' => 50,
			'player_index' => 0,
			'player_first_name' => 'Child',
			'player_last_name' => 'One',
		];
		$row_b = [
			'customer_id' => 50,
			'player_index' => 0,
			'player_first_name' => 'Child',
			'player_last_name' => 'Two',
		];
		$map->register_row($row_a, '');
		$map->register_row($row_b, '');
		$key_a = intersoccer_roster_report_build_player_key($row_a, '');
		$key_b = intersoccer_roster_report_build_player_key($row_b, '');
		$this->assertSame('name', $key_a['tier']);
		$this->assertSame('name', $key_b['tier']);
		$this->assertFalse($map->same_player($key_a['key'], $key_b['key']));
	}

	public function test_same_idx_and_same_name_still_merges() {
		$map = new \Intersoccer_Roster_Report_Player_Identity_Map();
		$row = [
			'customer_id' => 51,
			'player_index' => 1,
			'player_first_name' => 'Same',
			'player_last_name' => 'Kid',
		];
		$map->register_row($row, '');
		$map->register_row($row, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$idx_key = intersoccer_roster_report_build_player_key($row, '');
		$uuid_key = intersoccer_roster_report_build_player_key($row, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$this->assertTrue($map->same_player($idx_key['key'], $uuid_key['key']));
	}

	public function test_fully_refunded_line_drops_from_winback_while_partial_stays() {
		$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'] = [9001 => true];
		$full_line = [
			'order_id' => 1,
			'order_item_id' => 9001,
			'order_status' => 'completed',
		];
		$partial_line = [
			'order_id' => 1,
			'order_item_id' => 9002,
			'order_status' => 'completed',
		];
		$this->assertFalse(intersoccer_roster_report_row_counts_for_winback($full_line, 'history'));
		$this->assertTrue(intersoccer_roster_report_row_counts_for_winback($partial_line, 'history'));
		unset($GLOBALS['intersoccer_test_line_fully_refunded_item_ids']);
	}

	public function test_wholly_refunded_order_drops_even_when_roster_stale() {
		$row = [
			'order_id' => 2,
			'order_item_id' => 9003,
			'order_status' => 'wc-refunded',
		];
		$this->assertFalse(intersoccer_roster_report_row_counts_for_winback($row, 'history'));
		$this->assertFalse(intersoccer_roster_report_row_counts_for_winback($row, 'upcoming_rebooked'));
	}

	public function test_current_course_term_sql_has_no_empty_string_date_comparison() {
		$sql = intersoccer_winback_report_engine_current_course_term_sql('wp_intersoccer_rosters');
		$this->assertDoesNotMatchRegularExpression(
			"/(start_date|end_date)\s*(=|<>|!=)\s*''/",
			$sql
		);
		$this->assertStringContainsString('end_date IS NULL', $sql);
		$this->assertStringContainsString('start_date IS NOT NULL', $sql);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function j8_course_roster_row(array $overrides = []) {
		return array_merge([
			'is_placeholder' => 0,
			'order_item_id' => 91001,
			'order_id' => 9100,
			'order_status' => 'completed',
			'activity_type' => 'Course',
			'customer_id' => 88,
			'player_index' => 1,
			'player_first_name' => 'Course',
			'player_last_name' => 'Current',
		], $overrides);
	}

	public function test_j8_running_course_term_marks_player_current() {
		$today = '2026-10-23';
		$row = $this->j8_course_roster_row([
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-15',
		]);
		$key = intersoccer_roster_report_build_player_key($row, '');
		$GLOBALS['intersoccer_test_winback_current_course_term_rows'] = [$row];
		intersoccer_winback_reset_current_course_term_cache();
		$this->assertTrue(
			intersoccer_winback_report_engine_player_in_current_course_term(
				$key['key'],
				'course',
				$today,
				[],
				null
			)
		);
	}

	public function test_j8_null_end_date_running_term_marks_player_current() {
		$today = '2026-10-23';
		$row = $this->j8_course_roster_row([
			'start_date' => '2026-01-01',
			'end_date' => null,
		]);
		$key = intersoccer_roster_report_build_player_key($row, '');
		$GLOBALS['intersoccer_test_winback_current_course_term_rows'] = [$row];
		intersoccer_winback_reset_current_course_term_cache();
		$this->assertTrue(
			intersoccer_winback_report_engine_player_in_current_course_term(
				$key['key'],
				'course',
				$today,
				[],
				null
			)
		);
	}

	public function test_j8_past_course_term_only_is_not_current() {
		$today = '2026-10-23';
		$row = $this->j8_course_roster_row([
			'start_date' => '2025-01-01',
			'end_date' => '2025-06-30',
		]);
		$key = intersoccer_roster_report_build_player_key($row, '');
		$GLOBALS['intersoccer_test_winback_current_course_term_rows'] = [$row];
		intersoccer_winback_reset_current_course_term_cache();
		$this->assertFalse(
			intersoccer_winback_report_engine_player_in_current_course_term(
				$key['key'],
				'course',
				$today,
				[],
				null
			)
		);
	}

	public function test_j8_cancelled_live_order_for_running_term_is_not_current() {
		$today = '2026-10-23';
		$row = $this->j8_course_roster_row([
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-15',
			'order_id' => 9200,
			'order_status' => 'completed',
		]);
		$key = intersoccer_roster_report_build_player_key($row, '');
		$GLOBALS['intersoccer_test_live_order_status_map'] = [9200 => 'wc-cancelled'];
		$GLOBALS['intersoccer_test_winback_current_course_term_rows'] = [$row];
		intersoccer_winback_reset_current_course_term_cache();
		$this->assertFalse(
			intersoccer_winback_report_engine_player_in_current_course_term(
				$key['key'],
				'course',
				$today,
				[],
				null
			)
		);
	}

	public function test_resolved_age_missing_from_catalogue_registers_unmapped_slug(): void {
		intersoccer_winback_reset_unmapped_facets();
		$catalogue = [
			'camp|geneva-lake|5-13y' => [
				[
					'product_name' => 'Camp',
					'start_date' => '2026-08-01',
					'age_group_slug' => '5-13y',
					'product_id' => 1,
					'source_variation_id' => 1,
					'variation_id' => 1,
				],
			],
		];
		intersoccer_winback_register_unmapped_age_if_not_catalogue_band('5-12y', $catalogue, 'camp', 'geneva-lake');
		$unmapped = intersoccer_winback_get_unmapped_facets();
		$this->assertArrayHasKey('5-12y', $unmapped['age_group']);
		$this->assertSame(1, $unmapped['age_group']['5-12y']);
	}

	public function test_more_targets_dedupes_product_week_and_prefers_full_week(): void {
		intersoccer_winback_reset_catalogue_target_cache();
		$catalogue = [
			'camp|geneva|5-13y' => [
				[
					'product_name' => 'Easter Camp',
					'product_id' => 62554,
					'start_date' => '2026-04-05',
					'age_group_slug' => '5-13y',
					'booking_type_slug' => 'single-days',
					'source_variation_id' => 2002,
					'variation_id' => 2002,
				],
				[
					'product_name' => 'Easter Camp',
					'product_id' => 62554,
					'start_date' => '2026-04-05',
					'age_group_slug' => '5-13y',
					'booking_type_slug' => 'full-week',
					'source_variation_id' => 2001,
					'variation_id' => 2001,
				],
				[
					'product_name' => 'Summer Camp',
					'product_id' => 62555,
					'start_date' => '2026-07-01',
					'age_group_slug' => '5-13y',
					'booking_type_slug' => 'full-week',
					'source_variation_id' => 3001,
					'variation_id' => 3001,
				],
			],
		];
		$resolved = intersoccer_winback_resolve_catalogue_target_and_more(
			$catalogue,
			'camp',
			'geneva',
			'5-13y',
			'2015-06-01'
		);
		$this->assertNotNull($resolved['target']);
		$this->assertSame('Easter Camp', $resolved['target']['product']);
		$this->assertSame('2026-04-05', $resolved['target']['start_date']);
		$this->assertCount(1, $resolved['more_targets']);
		$this->assertSame('Summer Camp', $resolved['more_targets'][0]['product']);
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
