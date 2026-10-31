<?php
/**
 * Player course status: FR/DE date fallback, year grouping, Excel export contract.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccerRRTestMeta;

class PlayerCourseStatusDateAndExportTest extends TestCase {
	/** @var array<string,mixed> */
	private $savedGet = [];

	protected function setUp(): void {
		parent::setUp();
		$this->savedGet = $_GET;
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
		$this->reset_fixtures();
	}

	protected function tearDown(): void {
		$this->reset_fixtures();
		$_GET = $this->savedGet;
		parent::tearDown();
	}

	private function reset_fixtures(): void {
		InterSoccerRRTestMeta::$data = [];
		unset(
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_item_meta'],
			$GLOBALS['intersoccer_test_line_fully_refunded_item_ids']
		);
		if (function_exists('intersoccer_roster_report_reset_order_item_meta_cache')) {
			intersoccer_roster_report_reset_order_item_meta_cache();
		}
		if (function_exists('intersoccer_player_course_status_reset_run_memos')) {
			intersoccer_player_course_status_reset_run_memos();
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function base_course_row(array $overrides = []): array {
		return array_merge([
			'activity_type' => 'Course',
			'product_name' => 'Skills',
			'venue' => 'Geneva Centre',
			'course_day' => 'Sunday',
			'times' => '10:00-11:30',
			'season' => 'Autumn 2026',
			'start_date' => '',
			'end_date' => '',
			'order_id' => 8001,
			'order_item_id' => 8101,
			'order_status' => 'completed',
			'customer_id' => 50,
			'player_index' => 0,
			'player_first_name' => 'Kid',
			'player_last_name' => 'Test',
			'girls_only' => 0,
			'is_placeholder' => 0,
			'variation_id' => 0,
		], $overrides);
	}

	public function test_parse_resolved_date_fr_and_de_month_names(): void {
		$this->assertSame(
			'2027-01-15',
			intersoccer_player_course_status_parse_resolved_date('15 janvier 2027', 'test')
		);
		$this->assertSame(
			'2027-02-01',
			intersoccer_player_course_status_parse_resolved_date('1 février 2027', 'test')
		);
		$this->assertSame(
			'2027-03-03',
			intersoccer_player_course_status_parse_resolved_date('3. März 2027', 'test')
		);
		$this->assertSame(
			'2026-12-12',
			intersoccer_player_course_status_parse_resolved_date('12. Dezember 2026', 'test')
		);
		$this->assertSame(
			'2026-08-17',
			intersoccer_player_course_status_parse_resolved_date('August 17, 2026', 'test')
		);
	}

	public function test_order_meta_localized_dates_resolve_into_year_scope(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [];
		$GLOBALS['intersoccer_test_order_item_meta'] = [
			8201 => [
				'Date de début' => '15 janvier 2027',
				'Date de fin' => '1 février 2027',
			],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row([
				'order_item_id' => 8201,
				'order_id' => 8200,
				'start_date' => '1970-01-01',
				'end_date' => '1970-01-01',
			]),
		];
		$prepared = intersoccer_player_course_status_prepare_roster_rows(
			intersoccer_player_course_status_filter_raw_course_rows($GLOBALS['intersoccer_test_player_course_status_roster_rows'])
		);
		$this->assertSame('2027-01-15', $prepared[0]['start_date']);
		$this->assertSame('2027-02-01', $prepared[0]['end_date']);
		$scoped = intersoccer_player_course_status_filter_scoped_rows($prepared, 2027, '', 'all');
		$this->assertCount(1, $scoped);
	}

	public function test_cross_year_course_appears_in_both_start_and_end_years(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [];
		$row = $this->base_course_row([
			'start_date' => '2026-09-01',
			'end_date' => '2027-01-20',
		]);
		$prepared = intersoccer_player_course_status_prepare_roster_rows([$row]);
		$this->assertCount(1, intersoccer_player_course_status_filter_scoped_rows($prepared, 2026, '', 'all'));
		$this->assertCount(1, intersoccer_player_course_status_filter_scoped_rows($prepared, 2027, '', 'all'));
	}

	public function test_program_year_only_row_in_year_without_dates(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [];
		$GLOBALS['intersoccer_test_order_item_meta'] = [
			8301 => ['Year' => '2026'],
		];
		$row = $this->base_course_row([
			'order_item_id' => 8301,
			'start_date' => '1970-01-01',
			'end_date' => '1970-01-01',
		]);
		$prepared = intersoccer_player_course_status_prepare_roster_rows([$row]);
		$this->assertCount(1, intersoccer_player_course_status_filter_scoped_rows($prepared, 2026, '', 'all'));
		$this->assertCount(0, intersoccer_player_course_status_filter_scoped_rows($prepared, 2025, '', 'all'));
	}

	public function test_no_resolved_dates_and_no_program_year_excluded_from_year(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [];
		$row = $this->base_course_row([
			'season' => 'Autumn',
			'start_date' => '1970-01-01',
			'end_date' => '1970-01-01',
		]);
		$prepared = intersoccer_player_course_status_prepare_roster_rows([$row]);
		$this->assertCount(0, intersoccer_player_course_status_filter_scoped_rows($prepared, 2026, '', 'all'));
	}

	public function test_variation_meta_fallback_when_roster_and_order_meta_empty(): void {
		InterSoccerRRTestMeta::$data[9100] = [
			'_course_start_date' => '3. März 2027',
			'_end_date' => '2027-06-01',
		];
		$row = $this->base_course_row([
			'variation_id' => 9100,
			'start_date' => '',
			'end_date' => '',
		]);
		$prepared = intersoccer_player_course_status_prepare_roster_rows([$row]);
		$this->assertSame('2027-03-03', $prepared[0]['start_date']);
		$this->assertSame('2027-06-01', $prepared[0]['end_date']);
	}

	public function test_cancelled_and_refunded_excluded_from_bookings(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 60,
				'user_email' => 'p@test',
				'player_id' => 'px',
				'player_index' => 0,
				'first_name' => 'Kid',
				'last_name' => 'Test',
				'dob' => '2014-01-01',
			],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row([
				'customer_id' => 60,
				'order_item_id' => 8401,
				'start_date' => '2026-09-01',
				'end_date' => '2026-12-01',
			]),
			$this->base_course_row([
				'customer_id' => 60,
				'order_item_id' => 8402,
				'order_status' => 'cancelled',
				'start_date' => '2026-09-01',
				'end_date' => '2026-12-01',
			]),
			$this->base_course_row([
				'customer_id' => 60,
				'order_item_id' => 8403,
				'order_status' => 'refunded',
				'start_date' => '2026-09-01',
				'end_date' => '2026-12-01',
			]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertCount(1, $result['rows'][0]['bookings']);
		$this->assertSame(8401, (int) $result['rows'][0]['bookings'][0]['order_item_id']);
	}

	public function test_export_headers_match_contract_booking_columns(): void {
		intersoccer_test_set_user_caps(['manage_options' => true]);
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		$headers = intersoccer_player_course_status_export_headers();
		$this->assertSame(
			intersoccer_player_course_status_export_booking_headers(),
			array_slice($headers, -count(intersoccer_player_course_status_export_booking_headers()))
		);
		$this->assertContains('Times', $headers);
		$this->assertNotContains('Course Bookings', $headers);
		$this->assertContains('Parent Billing Phone', $headers);
		$this->assertContains('Parent Email', $headers);
		$this->assertContains('Account Email', $headers);
	}

	public function test_normalize_times_display_unifies_french_hour_format(): void {
		$this->assertSame('1000-1130', intersoccer_player_course_status_normalize_times_display('10h00-11h30'));
		$this->assertSame('1000-1130', intersoccer_player_course_status_normalize_times_display('10:00-11:30'));
		$this->assertSame('1000-1130', intersoccer_player_course_status_normalize_times_display('1000-1130'));
		$row = $this->base_course_row(['times' => '', 'course_times' => '9h30-11h00']);
		$this->assertSame('0930-1100', intersoccer_player_course_status_resolve_times($row));
	}

	public function test_filter_options_include_pending_only_season(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [];
		$rows = [
			$this->base_course_row([
				'order_status' => 'pending',
				'season' => 'Winter 2027',
				'start_date' => '2027-01-10',
				'end_date' => '2027-03-01',
			]),
		];
		$prepared = intersoccer_player_course_status_prepare_roster_rows($rows);
		$scoped = intersoccer_player_course_status_filter_scoped_rows($prepared, 2027, '', 'all');
		$opts = intersoccer_player_course_status_build_filter_options($scoped);
		$this->assertContains('Winter', $opts['seasons']);
	}

	public function test_request_filters_default_season_type_to_courses_bundle_when_omitted(): void {
		$_GET = ['page' => 'intersoccer-player-course-status', 'year' => '2026'];
		$filters = intersoccer_player_course_status_request_filters();
		$this->assertSame('courses', $filters['season_type']);
		$_GET = ['page' => 'intersoccer-player-course-status', 'year' => '2026', 'season_type' => ''];
		$filters_all = intersoccer_player_course_status_request_filters();
		$this->assertSame('', $filters_all['season_type']);
	}

	public function test_course_day_filter_label_normalizes_fr_and_de_weekdays(): void {
		$this->assertSame('Wednesday', intersoccer_player_course_status_course_day_filter_label('wednesday', 'Mercredi'));
		$this->assertSame('Wednesday', intersoccer_player_course_status_course_day_filter_label('mercredi', ''));
		$this->assertSame('Saturday', intersoccer_player_course_status_course_day_filter_label('saturday', 'Samstag'));
	}

	public function test_season_type_courses_includes_autumn_winter_spring_excludes_summer(): void {
		$rows = [
			$this->base_course_row(['season' => 'Autumn 2026', 'order_item_id' => 8601, 'start_date' => '2026-09-01', 'end_date' => '2026-12-01']),
			$this->base_course_row(['season' => 'Winter 2026', 'order_item_id' => 8602, 'start_date' => '2026-01-10', 'end_date' => '2026-03-01']),
			$this->base_course_row(['season' => 'Spring 2026', 'order_item_id' => 8603, 'start_date' => '2026-04-01', 'end_date' => '2026-06-01']),
			$this->base_course_row(['season' => 'Summer Camps 2026', 'order_item_id' => 8604, 'start_date' => '2026-07-01', 'end_date' => '2026-08-01']),
		];
		$prepared = intersoccer_player_course_status_prepare_roster_rows($rows);
		$scoped = intersoccer_player_course_status_filter_scoped_rows($prepared, 2026, 'courses', 'all');
		$item_ids = array_map(static function ($row) {
			return (int) ($row['order_item_id'] ?? 0);
		}, $scoped);
		sort($item_ids);
		$this->assertSame([8601, 8602, 8603], $item_ids);
	}

	public function test_export_one_row_per_booking_includes_pending_line(): void {
		intersoccer_test_set_user_caps(['manage_options' => true]);
		intersoccer_player_camp_status_reset_dob_gate_cache();
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 70,
				'user_email' => 'acct@test',
				'player_id' => 'p70',
				'player_index' => 0,
				'first_name' => 'Kid',
				'last_name' => 'Two',
				'dob' => '2014-01-01',
			],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_course_row([
				'customer_id' => 70,
				'order_item_id' => 8501,
				'start_date' => '2026-09-01',
				'end_date' => '2026-12-01',
				'parent_first_name' => 'Bill',
				'parent_last_name' => 'Ing',
				'parent_email' => 'bill@test',
				'parent_phone' => '0790000000',
			]),
			$this->base_course_row([
				'customer_id' => 70,
				'order_item_id' => 8502,
				'order_status' => 'pending',
				'start_date' => '2026-10-01',
				'end_date' => '2026-12-15',
			]),
		];
		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$lines = intersoccer_player_course_status_export_rows_for_player($result['rows'][0]);
		$this->assertCount(2, $lines);
		$headers = intersoccer_player_course_status_export_headers();
		foreach ($lines as $line) {
			$this->assertCount(count($headers), $line);
		}
		$line_type_idx = array_search('Line Type', $headers, true);
		$this->assertNotFalse($line_type_idx);
		$this->assertStringContainsString('Booked', (string) $lines[0][ $line_type_idx ]);
		$this->assertStringContainsString('Pending', (string) $lines[1][ $line_type_idx ]);
		$parent_idx = array_search('Parent Email', $headers, true);
		$acct_idx = array_search('Account Email', $headers, true);
		$this->assertNotFalse($parent_idx);
		$this->assertNotFalse($acct_idx);
		$this->assertSame('acct@test', $lines[0][ $parent_idx ]);
		$this->assertSame('acct@test', $lines[0][ $acct_idx ]);
		$bill_email_idx = array_search('Parent Billing Email', $headers, true);
		$this->assertSame('bill@test', $lines[0][ $bill_email_idx ]);
	}
}
