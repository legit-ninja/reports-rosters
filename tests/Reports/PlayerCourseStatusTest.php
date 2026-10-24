<?php
/**
 * Player course status report helpers and engine fixtures.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerCourseStatusTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		if (function_exists('intersoccer_test_ensure_pm_filter_stub')) {
			intersoccer_test_ensure_pm_filter_stub();
		}
		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['medical-access.php', 'final-reports-aggregation.php', 'utils.php', 'winback-report-facets.php', 'player-camp-status.php', 'player-course-status.php'] as $file) {
			$path = $includes . '/' . $file;
			if (file_exists($path)) {
				require_once $path;
			}
		}
		$this->reset_course_status_fixtures();
	}

	protected function tearDown(): void {
		$this->reset_course_status_fixtures();
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		unset(
			$GLOBALS['intersoccer_test_current_user_caps'],
			$GLOBALS['intersoccer_test_current_user']
		);
		parent::tearDown();
	}

	private function reset_course_status_fixtures(): void {
		unset(
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'],
			$GLOBALS['intersoccer_test_order_item_meta']
		);
		if (function_exists('intersoccer_roster_report_reset_live_order_status_memo')) {
			intersoccer_roster_report_reset_live_order_status_memo();
		}
		if (function_exists('intersoccer_roster_report_reset_order_customer_id_cache')) {
			intersoccer_roster_report_reset_order_customer_id_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		if (function_exists('intersoccer_roster_report_reset_order_item_meta_cache')) {
			intersoccer_roster_report_reset_order_item_meta_cache();
		}
		if (function_exists('intersoccer_winback_reset_facet_resolution_cache')) {
			intersoccer_winback_reset_facet_resolution_cache();
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function base_course_row(array $overrides = []): array {
		return array_merge([
			'activity_type' => 'Course',
			'product_name' => 'Autumn Skills',
			'venue' => 'Geneva Centre',
			'course_day' => 'Sunday',
			'times' => '10:00-11:30',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-15',
			'order_id' => 5001,
			'order_item_id' => 9001,
			'order_status' => 'completed',
			'customer_id' => 10,
			'player_index' => 0,
			'player_first_name' => 'Ada',
			'player_last_name' => 'Lovelace',
			'girls_only' => 0,
			'is_placeholder' => 0,
			'age_group' => '5-12y',
		], $overrides);
	}

	public function test_course_rows_included_camp_rows_excluded(): void {
		$rows = [
			$this->base_course_row(),
			$this->base_course_row([
				'activity_type' => 'Camp',
				'product_name' => 'Summer Camp',
				'order_item_id' => 9002,
			]),
		];
		$scoped = intersoccer_player_course_status_filter_scoped_rows($rows, 2026, '', 'all');
		$this->assertCount(1, $scoped);
		$this->assertSame('Autumn Skills', $scoped[0]['product_name']);
	}

	public function test_booking_label_and_times_fallback(): void {
		$row = $this->base_course_row(['times' => '', 'course_times' => '14:00-15:00']);
		$label = intersoccer_player_course_status_booking_label($row);
		$this->assertStringContainsString('14:00-15:00', $label);
		$this->assertStringContainsString('Sunday', $label);
	}

	public function test_cancelled_live_status_excludes_booking(): void {
		$GLOBALS['intersoccer_test_live_order_status_map'] = [5001 => 'wc-cancelled'];
		$row = intersoccer_player_course_status_prepare_roster_rows([$this->base_course_row()])[0];
		$this->assertFalse(intersoccer_player_course_status_row_is_active_booking($row));
	}

	public function test_pending_and_on_hold_count_as_active_booking(): void {
		$this->assertTrue(intersoccer_player_course_status_row_is_active_booking(
			$this->base_course_row(['order_status' => 'pending'])
		));
		$this->assertTrue(intersoccer_player_course_status_row_is_active_booking(
			$this->base_course_row(['order_status' => 'on-hold'])
		));
	}

	public function test_fully_refunded_line_excluded_partial_counts(): void {
		$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'] = [9001 => true];
		$row = intersoccer_player_course_status_prepare_roster_rows([$this->base_course_row()])[0];
		$this->assertFalse(intersoccer_player_course_status_row_is_active_booking($row));

		unset($GLOBALS['intersoccer_test_line_fully_refunded_item_ids']);
		$row2 = intersoccer_player_course_status_prepare_roster_rows([$this->base_course_row()])[0];
		$this->assertTrue(intersoccer_player_course_status_row_is_active_booking($row2));
	}

	public function test_year_rule_spanning_end_date_and_program_year_meta(): void {
		$autumn = $this->base_course_row([
			'start_date' => '2026-09-01',
			'end_date' => '2027-01-15',
		]);
		$this->assertTrue(intersoccer_player_course_status_row_in_year($autumn, 2026));
		$this->assertTrue(intersoccer_player_course_status_row_in_year($autumn, 2027));

		$GLOBALS['intersoccer_test_order_item_meta'] = [
			9001 => ['Year' => '2026'],
		];
		$undated = $this->base_course_row([
			'start_date' => '',
			'end_date' => '',
			'season' => 'Autumn',
		]);
		$prepared = intersoccer_player_course_status_prepare_roster_rows([$undated])[0];
		$this->assertTrue(intersoccer_player_course_status_row_in_year($prepared, 2026));
		$this->assertFalse(intersoccer_player_course_status_row_in_year($prepared, 2025));
	}

	public function test_legacy_customer_zero_matches_by_name_not_index_zero(): void {
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [5001 => 42];
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 42,
				'user_email' => 'parent@example.test',
				'player_id' => 'uuid-child-a',
				'player_index' => 0,
				'first_name' => 'Ada',
				'last_name' => 'Lovelace',
				'dob' => '2015-01-01',
			],
			[
				'user_id' => 42,
				'user_email' => 'parent@example.test',
				'player_id' => 'uuid-child-b',
				'player_index' => 1,
				'first_name' => 'Alan',
				'last_name' => 'Turing',
				'dob' => '2016-02-02',
			],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row([
				'customer_id' => 0,
				'player_index' => 0,
				'player_first_name' => 'Alan',
				'player_last_name' => 'Turing',
				'order_item_id' => 9100,
			]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$by_name = [];
		foreach ($result['rows'] as $row) {
			$by_name[ $row['player']['first_name'] ] = $row;
		}
		$this->assertFalse($by_name['Ada']['booked']);
		$this->assertTrue($by_name['Alan']['booked']);
		$this->assertSame('name', $by_name['Alan']['player']['tier']);
	}

	public function test_venue_filter_and_booked_not_booked_semantics(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 1,
				'user_email' => 'a@test',
				'player_id' => 'p1',
				'player_index' => 0,
				'first_name' => 'A',
				'last_name' => 'One',
				'dob' => '2015-01-01',
			],
			[
				'user_id' => 2,
				'user_email' => 'b@test',
				'player_id' => 'p2',
				'player_index' => 0,
				'first_name' => 'B',
				'last_name' => 'Two',
				'dob' => '2015-01-01',
			],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row([
				'customer_id' => 1,
				'player_index' => 0,
				'venue' => 'Geneva Centre',
				'order_item_id' => 9201,
			]),
		];
		$slug = intersoccer_player_course_status_resolve_venue_facet($GLOBALS['intersoccer_test_player_course_status_roster_rows'][0])['slug'];
		$result = intersoccer_player_course_status_generate([
			'year' => 2026,
			'venue' => $slug,
			'booked' => 'not_booked',
		]);
		$this->assertCount(1, $result['rows']);
		$this->assertSame('B', $result['rows'][0]['player']['first_name']);
	}

	public function test_age_group_alias_resolves_to_en_slug(): void {
		$row = $this->base_course_row(['age_group' => '5-12-jahre']);
		$facet = intersoccer_player_course_status_resolve_age_group_facet($row);
		$this->assertSame('5-12y', $facet['slug']);
	}

	public function test_unmatched_bookings_listed(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row(['customer_id' => 99, 'player_index' => 0]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertCount(1, $result['unmatched_bookings']);
		$this->assertSame(99, $result['unmatched_bookings'][0]['customer_id']);
	}

	public function test_dob_gated_in_engine_and_export(): void {
		intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [];
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 5,
				'user_email' => 'p@test',
				'player_id' => 'px',
				'player_index' => 0,
				'first_name' => 'Kid',
				'last_name' => 'Test',
				'dob' => '2014-05-05',
			],
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertTrue($result['rows'][0]['dob_restricted']);
		$this->assertNull($result['rows'][0]['dob']);
		$this->assertNotNull($result['rows'][0]['age']);
		$this->assertGreaterThan(0, $result['rows'][0]['age']);
		$headers = intersoccer_player_course_status_export_headers();
		$this->assertNotContains('DOB', $headers);

		intersoccer_test_set_user_caps(['manage_options' => true]);
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		$result2 = intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertFalse($result2['rows'][0]['dob_restricted']);
		$this->assertSame('2014-05-05', $result2['rows'][0]['dob']);
		$this->assertContains('DOB', intersoccer_player_course_status_export_headers());
	}

	public function test_export_row_count_matches_engine_shape(): void {
		intersoccer_test_set_user_caps(['manage_options' => true]);
		intersoccer_player_camp_status_reset_dob_gate_cache();
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 7,
				'user_email' => 'x@test',
				'player_id' => 'px',
				'player_index' => 0,
				'first_name' => 'Kid',
				'last_name' => 'Test',
				'dob' => '2014-05-05',
			],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row(['customer_id' => 7, 'player_index' => 0]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$headers = intersoccer_player_course_status_export_headers();
		$export = intersoccer_player_course_status_export_row($result['rows'][0]);
		$this->assertCount(count($headers), $export);
		$booked_index = array_search('Booked', $headers, true);
		$this->assertNotFalse($booked_index);
		$this->assertSame('Yes', $export[ $booked_index ]);
	}
}
