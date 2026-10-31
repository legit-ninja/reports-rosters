<?php
/**
 * QA follow-ups for RR PR #150 (Tess sandbox @ 360413c).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerCourseStatusQa150FixesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['utils.php', 'medical-access.php', 'final-reports-aggregation.php', 'reports-data.php', 'winback-report-facets.php', 'player-camp-status.php', 'player-course-status.php'] as $file) {
			$path = $includes . '/' . $file;
			if (is_readable($path)) {
				require_once $path;
			}
		}
	}

	public function test_course_day_slug_normalizes_en_fr_de_to_english_slug(): void {
		if (!function_exists('intersoccer_player_course_status_course_day_slug')) {
			$this->markTestSkipped('PCS course day slug helper missing');
		}

		$cases = [
			'Wednesday' => 'wednesday',
			'lundi' => 'monday',
			'Mercredi' => 'wednesday',
			'dimanche' => 'sunday',
			'Montag' => 'monday',
			'dienstag' => 'tuesday',
			'samstag' => 'saturday',
		];

		foreach ($cases as $raw => $expected) {
			$this->assertSame($expected, intersoccer_player_course_status_course_day_slug($raw), 'slug for ' . $raw);
		}
	}

	public function test_course_day_filter_matches_localized_roster_values(): void {
		if (!function_exists('intersoccer_player_course_status_booking_matches_filters')) {
			$this->markTestSkipped('PCS booking filter missing');
		}

		$filters = ['course_day' => 'monday'];
		$booking = [
			'venue_slug' => '',
			'age_group_slug' => '',
			'course_day_slug' => intersoccer_player_course_status_course_day_slug('lundi'),
		];
		$this->assertTrue(intersoccer_player_course_status_booking_matches_filters($booking, $filters));

		$booking_de = $booking;
		$booking_de['course_day_slug'] = intersoccer_player_course_status_course_day_slug('Montag');
		$this->assertTrue(intersoccer_player_course_status_booking_matches_filters($booking_de, $filters));
	}

	/**
	 * @dataProvider tessGermanPcsOrderMetaDateStrings
	 */
	public function test_parse_date_unified_handles_tess_german_pcs_strings(string $input, string $expected_ymd): void {
		if (!function_exists('intersoccer_parse_date_unified')) {
			$this->markTestSkipped('intersoccer_parse_date_unified missing');
		}

		$parsed = intersoccer_parse_date_unified($input, 'pcs order meta (start)');
		$this->assertSame($expected_ymd, $parsed, $input);
	}

	/**
	 * @return list<array{0:string,1:string}>
	 */
	public static function tessGermanPcsOrderMetaDateStrings(): array {
		return [
			['März 3, 2026', '2026-03-03'],
			['März 1, 2026', '2026-03-01'],
			['März 11, 2026', '2026-03-11'],
			['März 17, 2026', '2026-03-17'],
			['März 25, 2026', '2026-03-25'],
			['Juni 2, 2026', '2026-06-02'],
			['Juni 23, 2026', '2026-06-23'],
			['Juni 27, 2026', '2026-06-27'],
			['Juni 28, 2026', '2026-06-28'],
			['Februar 28, 2026', '2026-02-28'],
			['Dezember 9, 2026', '2026-12-09'],
		];
	}

	public function test_parse_date_unified_handles_french_month_comma_format(): void {
		if (!function_exists('intersoccer_parse_date_unified')) {
			$this->markTestSkipped('intersoccer_parse_date_unified missing');
		}

		$this->assertSame('2026-03-15', intersoccer_parse_date_unified('mars 15, 2026', 'pcs order meta (end)'));
		$this->assertSame('2026-07-04', intersoccer_parse_date_unified('juillet 4, 2026', 'pcs order meta (start)'));
	}

	public function test_row_in_year_uses_season_label_when_dates_are_prior_calendar_year(): void {
		if (!function_exists('intersoccer_player_course_status_row_in_year')) {
			$this->markTestSkipped('PCS row_in_year missing');
		}

		$row = [
			'start_date' => '2025-01-12',
			'end_date' => '2025-02-20',
			'season' => 'Winter 2026',
			'order_status' => 'wc-completed',
		];

		$this->assertTrue(intersoccer_player_course_status_row_in_year($row, 2026));
	}

	public function test_report_totals_matches_generate_totals_for_fixture(): void {
		if (!function_exists('intersoccer_player_course_status_generate')
			|| !function_exists('intersoccer_player_course_status_report_totals')) {
			$this->markTestSkipped('PCS generate/report_totals missing');
		}

		add_filter('intersoccer_player_course_status_cache_enabled', '__return_false', PHP_INT_MAX);

		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 42,
				'user_email' => 'parent@example.test',
				'player_id' => 'uuid-winter',
				'player_index' => 0,
				'first_name' => 'Winter',
				'last_name' => 'CliUi',
				'dob' => '2015-01-01',
			],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			[
				'order_id' => 9001,
				'order_item_id' => 90011,
				'player_first_name' => 'Winter',
				'player_last_name' => 'CliUi',
				'product_name' => 'Course',
				'product_id' => 1,
				'variation_id' => 2,
				'course_day' => 'Montag',
				'season' => 'Winter 2026',
				'start_date' => '2025-01-10',
				'end_date' => '2025-02-10',
				'order_status' => 'wc-completed',
				'customer_id' => 42,
				'player_index' => 0,
				'activity_type' => 'Course',
			],
		];

		$filters = ['year' => 2026, 'season_type' => 'courses'];
		$from_generate = intersoccer_player_course_status_generate($filters);
		$from_helper = intersoccer_player_course_status_report_totals($filters);

		$this->assertSame($from_generate['totals'], $from_helper);
		$this->assertSame(1, $from_helper['booked']);

		unset($GLOBALS['intersoccer_test_player_course_status_roster_rows'], $GLOBALS['intersoccer_test_player_course_status_players']);
	}
}
