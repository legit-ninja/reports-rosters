<?php
/**
 * Golden fixture: generate() + export rows unchanged vs 49fe5b4 baseline.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerCourseStatusGoldenTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/player-course-status-golden-stubs.php';
		if (function_exists('intersoccer_test_ensure_pm_filter_stub')) {
			intersoccer_test_ensure_pm_filter_stub();
		}
		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['reports-data.php', 'medical-access.php', 'final-reports-aggregation.php', 'utils.php', 'winback-report-facets.php', 'winback-report-perf.php', 'player-camp-status.php', 'player-course-status.php'] as $file) {
			require_once $includes . '/' . $file;
		}
		$this->reset_fixtures();
	}

	protected function tearDown(): void {
		$this->reset_fixtures();
		parent::tearDown();
	}

	private function reset_fixtures(): void {
		unset(
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_order_created_date_map'],
			$GLOBALS['intersoccer_test_orders_with_refunds'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'],
			$GLOBALS['intersoccer_test_order_item_meta'],
			$GLOBALS['intersoccer_test_fake_customer_map'],
			$GLOBALS['intersoccer_test_batch_chunk_size'],
			$GLOBALS['intersoccer_test_hpos_orders_enabled'],
			$GLOBALS['intersoccer_test_wc_get_order_callback'],
			$GLOBALS['intersoccer_rr_user_account_email_cache'],
			$GLOBALS['intersoccer_rr_user_billing_phone_cache'],
			$GLOBALS['intersoccer_test_golden_product_type_by_id']
		);
		if (function_exists('intersoccer_player_course_status_reset_run_memos')) {
			intersoccer_player_course_status_reset_run_memos();
		}
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
		if (function_exists('intersoccer_roster_report_reset_fake_customer_cache')) {
			intersoccer_roster_report_reset_fake_customer_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_line_refund_check_state')) {
			intersoccer_roster_report_reset_line_refund_check_state();
		}
		if (function_exists('intersoccer_winback_reset_order_created_date_cache')) {
			intersoccer_winback_reset_order_created_date_cache();
		}
		if (function_exists('intersoccer_winback_reset_facet_resolution_cache')) {
			intersoccer_winback_reset_facet_resolution_cache();
		}
		if (function_exists('intersoccer_roster_report_reset_idx_name_collision_state')) {
			intersoccer_roster_report_reset_idx_name_collision_state();
		}
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		unset(
			$GLOBALS['intersoccer_test_current_user_caps'],
			$GLOBALS['intersoccer_test_current_user']
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function base_row(array $overrides = []): array {
		return array_merge([
			'activity_type' => 'Course',
			'product_name' => 'Autumn Skills',
			'venue' => 'Geneva Centre',
			'course_day' => 'Sunday',
			'times' => '10:00-11:30',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-15',
			'order_id' => 55001,
			'order_item_id' => 59001,
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

	private function seed_golden_fixture(): void {
		$GLOBALS['intersoccer_test_golden_product_type_by_id'] = [
			91001 => 'course',
			91002 => 'birthday',
		];
		$GLOBALS['intersoccer_test_live_order_status_map'] = [
			55001 => 'wc-completed',
			55002 => 'wc-pending',
			55003 => 'wc-on-hold',
			55004 => 'wc-cancelled',
			55005 => 'wc-completed',
		];
		$GLOBALS['intersoccer_test_order_customer_id_map'] = [
			55001 => 10,
			55002 => 11,
			55003 => 12,
			55004 => 13,
			55005 => 0,
		];
		$GLOBALS['intersoccer_test_line_fully_refunded_item_ids'] = [59010 => true];
		$GLOBALS['intersoccer_test_order_item_meta'] = [59020 => ['Year' => '2026']];
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			['user_id' => 10, 'user_email' => 'booked@test', 'player_id' => 'uuid-booked', 'player_index' => 0, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'dob' => '2015-01-01'],
			['user_id' => 11, 'user_email' => 'pending@test', 'player_id' => 'uuid-pend', 'player_index' => 0, 'first_name' => 'Pen', 'last_name' => 'Ding', 'dob' => '2015-02-02'],
			['user_id' => 12, 'user_email' => 'hold@test', 'player_id' => 'uuid-hold', 'player_index' => 0, 'first_name' => 'On', 'last_name' => 'Hold', 'dob' => '2015-03-03'],
			['user_id' => 13, 'user_email' => 'free@test', 'player_id' => 'uuid-free', 'player_index' => 0, 'first_name' => 'Not', 'last_name' => 'Booked', 'dob' => '2015-04-04'],
			['user_id' => 14, 'user_email' => 'legacy@test', 'player_id' => 'uuid-legacy', 'player_index' => 0, 'first_name' => 'Legacy', 'last_name' => 'Names', 'dob' => '2015-05-05'],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_row(['order_item_id' => 59001, 'customer_id' => 10]),
			$this->base_row(['order_id' => 55002, 'order_item_id' => 59002, 'order_status' => 'pending', 'customer_id' => 11, 'player_first_name' => 'Pen', 'player_last_name' => 'Ding']),
			$this->base_row(['order_id' => 55003, 'order_item_id' => 59003, 'order_status' => 'on-hold', 'customer_id' => 12, 'player_first_name' => 'On', 'player_last_name' => 'Hold']),
			$this->base_row(['order_id' => 55004, 'order_item_id' => 59004, 'order_status' => 'cancelled', 'customer_id' => 13]),
			$this->base_row(['order_item_id' => 59010, 'customer_id' => 10, 'order_status' => 'completed']),
			$this->base_row(['order_item_id' => 59020, 'customer_id' => 10, 'start_date' => '', 'end_date' => '', 'season' => 'Autumn']),
			$this->base_row(['order_id' => 55005, 'order_item_id' => 59030, 'customer_id' => 0, 'player_first_name' => 'Ghost', 'player_last_name' => 'Roster']),
			$this->base_row(['activity_type' => 'Camp', 'order_item_id' => 59040, 'product_name' => 'Camp X']),
			$this->base_row([
				'order_item_id' => 59050,
				'customer_id' => 14,
				'player_first_name' => '',
				'player_last_name' => '',
				'first_name' => 'Legacy',
				'last_name' => 'Names',
			]),
			$this->base_row([
				'order_item_id' => 59060,
				'customer_id' => 10,
				'activity_type' => '',
				'product_id' => 91001,
				'product_name' => 'PV Course Only',
			]),
			$this->base_row([
				'order_item_id' => 59070,
				'customer_id' => 10,
				'activity_type' => 'Course',
				'product_id' => 91002,
				'product_name' => 'Birthday Party',
			]),
		];
		intersoccer_test_set_user_caps(['manage_options' => true]);
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
	}

	public function test_generate_and_export_match_golden_baseline(): void {
		$this->reset_fixtures();
		if (function_exists('intersoccer_test_ensure_pm_filter_stub')) {
			intersoccer_test_ensure_pm_filter_stub();
		}
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		$this->seed_golden_fixture();
		$expected = require __DIR__ . '/fixtures/player-course-status-golden-expected.php';
		$this->assertIsArray($expected);

		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$export_rows = [];
		foreach ($result['rows'] as $row) {
			foreach (intersoccer_player_course_status_export_rows_for_player($row) as $line) {
				$export_rows[] = $line;
			}
		}

		$this->assertSame($expected['result'], $result);
		$this->assertSame($expected['export_rows'], $export_rows);
	}
}
