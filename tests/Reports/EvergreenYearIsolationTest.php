<?php
/**
 * Evergreen season year isolation helpers (no full RR TestCase / vendor required).
 */

use PHPUnit\Framework\TestCase;

class EvergreenYearIsolationTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		if (!defined('ABSPATH')) {
			define('ABSPATH', dirname(__DIR__, 2) . '/');
		}
		$includes = dirname(__DIR__, 2) . '/includes';
		require_once $includes . '/final-reports-aggregation.php';
		if (!function_exists('intersoccer_extract_year_from_season')) {
			// Prefer reports-data extractors when available; otherwise local stubs below.
			$reports_data = $includes . '/reports-data.php';
			if (file_exists($reports_data)) {
				// Avoid loading full reports-data (heavy requires) — stubs are enough for this suite.
			}
		}
		if (!function_exists('intersoccer_extract_year_from_season')) {
			function intersoccer_extract_year_from_season($season) {
				if (empty($season)) {
					return null;
				}
				if (preg_match('/(\d{4})/', $season, $matches)) {
					return intval($matches[1]);
				}
				return null;
			}
		}
		if (!function_exists('intersoccer_extract_season_type')) {
			function intersoccer_extract_season_type($season) {
				foreach (['Summer', 'Winter', 'Autumn', 'Spring', 'Easter', 'Halloween'] as $type) {
					if (stripos((string) $season, $type) !== false) {
						return $type;
					}
				}
				return null;
			}
		}
	}

	public function test_evergreen_program_year_isolation() {
		$entries = [
			['order_item_id' => 1, 'season' => 'Autumn', 'program_year' => '2026', 'event_start_date' => '2026-10-05'],
			['order_item_id' => 2, 'season' => 'Autumn', 'program_year' => '2027', 'event_start_date' => '2027-10-04'],
			['order_item_id' => 3, 'season' => 'Autumn 2026', 'event_start_date' => '2026-10-12'],
			['order_item_id' => 4, 'season' => 'Autumn', 'program_year' => '2026'],
		];
		$filtered = intersoccer_reports_filter_entries_by_season_year($entries, 2026, 'Autumn');
		$this->assertCount(3, $filtered);
		$ids = array_column($filtered, 'order_item_id');
		$this->assertContains(1, $ids);
		$this->assertContains(3, $ids);
		$this->assertContains(4, $ids);
		$this->assertNotContains(2, $ids);
	}

	public function test_close_year_helper() {
		$row = ['season' => 'Autumn', 'program_year' => '2026', 'start_date' => '2026-10-05'];
		$this->assertTrue(intersoccer_reports_roster_matches_close_year($row, 2026));
		$this->assertFalse(intersoccer_reports_roster_matches_close_year($row, 2027));
		$this->assertFalse(intersoccer_reports_roster_matches_close_year($row, 0));
	}
}
