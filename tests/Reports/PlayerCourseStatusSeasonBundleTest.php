<?php
/**
 * Course-season bundle filter (`courses`) with production season-type helper loaded.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerCourseStatusSeasonBundleTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['reports-data.php', 'utils.php', 'medical-access.php', 'final-reports-aggregation.php', 'player-camp-status.php', 'player-course-status.php'] as $file) {
			$path = $includes . '/' . $file;
			if (file_exists($path)) {
				require_once $path;
			}
		}
		$this->assertTrue(function_exists('intersoccer_extract_season_type'));
	}

	public function test_extract_season_type_marks_spring_summer_as_summer_but_bundle_includes_it(): void {
		$this->assertSame('Summer', intersoccer_extract_season_type('Spring/Summer 2026'));
		$this->assertTrue(
			intersoccer_player_course_status_course_season_bundle_includes_season_label('Spring/Summer 2026')
		);
		$this->assertTrue(
			intersoccer_player_course_status_row_matches_course_season_bundle('Spring/Summer 2026')
		);
	}

	public function test_bundle_includes_autumn_winter_compound_and_unknown_labels(): void {
		$this->assertTrue(
			intersoccer_player_course_status_course_season_bundle_includes_season_label('Autumn/Winter 2026')
		);
		$this->assertTrue(
			intersoccer_player_course_status_course_season_bundle_includes_season_label('Legacy programme 2026')
		);
		$this->assertTrue(
			intersoccer_player_course_status_course_season_bundle_includes_season_label('')
		);
		$this->assertFalse(
			intersoccer_player_course_status_course_season_bundle_includes_season_label('Summer Camps 2026')
		);
	}

	public function test_bundle_label_path_agrees_with_extract_for_spring_summer(): void {
		$label = 'Spring/Summer 2026';
		$via_extract = intersoccer_extract_season_type($label);
		$this->assertSame('Summer', $via_extract);
		$this->assertTrue(intersoccer_player_course_status_course_season_bundle_includes_season_label($label));
	}

	public function test_courses_scoped_rows_include_spring_summer_empty_season_exclude_summer(): void {
		$rows = [
			$this->base_row(['season' => 'Spring/Summer 2026', 'order_item_id' => 8701, 'start_date' => '2026-04-01', 'end_date' => '2026-06-15']),
			$this->base_row(['season' => '', 'order_item_id' => 8702, 'start_date' => '2026-05-01', 'end_date' => '2026-06-01']),
			$this->base_row(['season' => 'Unknown label', 'order_item_id' => 8703, 'start_date' => '2026-05-01', 'end_date' => '2026-06-01']),
			$this->base_row(['season' => 'Summer Camps 2026', 'order_item_id' => 8704, 'start_date' => '2026-07-01', 'end_date' => '2026-08-01']),
		];
		$prepared = intersoccer_player_course_status_prepare_roster_rows($rows);
		$scoped = intersoccer_player_course_status_filter_scoped_rows($prepared, 2026, 'courses', 'all');
		$ids = array_map(static function ($row) {
			return (int) ($row['order_item_id'] ?? 0);
		}, $scoped);
		sort($ids);
		$this->assertSame([8701, 8702, 8703], $ids);
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
			'times' => '10:00-11:30',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-01',
			'order_id' => 8001,
			'order_item_id' => 8101,
			'order_status' => 'completed',
			'customer_id' => 50,
			'player_index' => 0,
			'girls_only' => 0,
			'is_placeholder' => 0,
		], $overrides);
	}
}
