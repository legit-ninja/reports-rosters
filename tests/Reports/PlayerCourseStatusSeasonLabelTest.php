<?php
/**
 * Courses report: canonical season label casing (PCS-scoped).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerCourseStatusSeasonLabelTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['reports-data.php', 'final-reports-aggregation.php', 'utils.php', 'winback-report-facets.php', 'player-camp-status.php', 'player-course-status.php'] as $file) {
			$path = $includes . '/' . $file;
			if (file_exists($path)) {
				require_once $path;
			}
		}
		unset(
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows']
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function base_row(array $overrides = []): array {
		return array_merge([
			'activity_type' => 'Course',
			'product_name' => 'Skills',
			'venue' => 'Geneva Centre',
			'course_day' => 'Sunday',
			'times' => '10:00',
			'season' => 'Spring/summer 2026',
			'start_date' => '2026-04-01',
			'end_date' => '2026-06-30',
			'order_id' => 7001,
			'order_item_id' => 7101,
			'order_status' => 'completed',
			'customer_id' => 50,
			'player_index' => 0,
			'player_first_name' => 'Kid',
			'player_last_name' => 'One',
			'girls_only' => 0,
			'is_placeholder' => 0,
			'age_group' => '5-12y',
		], $overrides);
	}

	public function test_canonical_season_label_title_case_after_slash(): void {
		$this->assertSame(
			'Spring/Summer 2026',
			intersoccer_player_course_status_canonical_season_label('Spring/summer  2026')
		);
		$this->assertSame(
			'Autumn 2026',
			intersoccer_player_course_status_canonical_season_label('autumn 2026')
		);
	}

	public function test_mixed_case_rows_one_season_filter_option_and_display(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 50,
				'user_email' => 'p@example.test',
				'player_id' => 'uuid-1',
				'player_index' => 0,
				'first_name' => 'Kid',
				'last_name' => 'One',
				'dob' => '2015-01-01',
			],
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = [
			$this->base_row(['order_item_id' => 7101, 'season' => 'Spring/summer 2026']),
			$this->base_row(['order_item_id' => 7102, 'season' => 'Spring/Summer 2026', 'order_id' => 7002]),
		];

		$result = intersoccer_player_course_status_generate(['year' => 2026]);
		$this->assertSame(['Spring/Summer 2026'], $result['filter_options']['seasons']);
		$this->assertCount(2, $result['rows'][0]['bookings']);
		$this->assertStringContainsString('Spring/Summer 2026', $result['rows'][0]['bookings_label']);
		$labels = $result['rows'][0]['bookings_label'];
		$this->assertSame(2, substr_count($labels, 'Spring/Summer 2026'));
	}

	public function test_season_filter_matches_case_variants(): void {
		$rows = [
			$this->base_row(['season' => 'Spring/summer 2026']),
			$this->base_row(['order_item_id' => 7103, 'order_id' => 7003, 'season' => 'Autumn 2026']),
		];
		$scoped = intersoccer_player_course_status_filter_scoped_rows($rows, 2026, 'Spring/Summer 2026', 'all');
		$this->assertCount(1, $scoped);
		$this->assertSame('Spring/summer 2026', $scoped[0]['season']);
	}

	public function test_build_booking_record_uses_canonical_season(): void {
		$booking = intersoccer_player_course_status_build_booking_record(
			$this->base_row(['season' => 'spring/SUMMER 2026'])
		);
		$this->assertSame('Spring/Summer 2026', $booking['season']);
		$this->assertStringContainsString('Spring/Summer 2026', $booking['label']);
	}
}
