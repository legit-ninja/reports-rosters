<?php
/**
 * Player course status admin menu registration.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerCourseStatusMenuTest extends TestCase {
	public function test_course_status_submenu_is_visible_under_reports_rosters(): void {
		$path = dirname(__DIR__, 2) . '/classes/Admin/menu-manager.php';
		$source = file_get_contents($path);
		$this->assertIsString($source);
		$this->assertStringContainsString("'intersoccer-player-course-status'", $source);
		$this->assertDoesNotMatchRegularExpression(
			"/add_submenu_page\(\s*null,\s*__\(\s*'Player course status'/s",
			$source
		);
		$this->assertMatchesRegularExpression(
			"/add_submenu_page\(\s*'intersoccer-reports-rosters',\s*__\(\s*'Player course status'/s",
			$source
		);
	}

	public function test_final_reports_url_helper_matches_course_grid_pattern(): void {
		require_once dirname(__DIR__, 2) . '/includes/player-course-status-admin.php';
		$url = intersoccer_player_course_status_admin_final_reports_url(2026);
		$this->assertStringContainsString('page=intersoccer-final-reports', $url);
		$this->assertStringContainsString('activity_type=Course', $url);
		$this->assertStringContainsString('year=2026', $url);
	}
}
