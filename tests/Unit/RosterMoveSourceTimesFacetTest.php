<?php
/**
 * Soft-filter / move-verify helpers for URL times vs roster row times.
 *
 * Repro fixture: order_item_id still in list, row times moved to 11:15–12:45,
 * URL times still 09:30–11:00 → excluded. Same times → included. Empty normalize → fail open.
 *
 * @package InterSoccer_Reports_Rosters
 */

use PHPUnit\Framework\TestCase;

class RosterMoveSourceTimesFacetTest extends TestCase {

	/** @var array<string,string> */
	private static $slug_map = [];

	public static function setUpBeforeClass(): void {
		self::$slug_map = [
			'09:30–11:00' => '09-30-11-00',
			'09:30-11:00' => '09-30-11-00',
			'11:15–12:45' => '11-15-12-45',
			'11:15-12:45' => '11-15-12-45',
			'09-30-11-00' => '09-30-11-00',
			'11-15-12-45' => '11-15-12-45',
		];

		if (!function_exists('intersoccer_resolve_times_slug_for_signature')) {
			function intersoccer_resolve_times_slug_for_signature($value) {
				$key = trim((string) $value);
				return RosterMoveSourceTimesFacetTest::slugFor($key);
			}
		}

		if (!function_exists('intersoccer_roster_facet_for_grouping')) {
			function intersoccer_roster_facet_for_grouping($value, $taxonomy = '') {
				$key = trim((string) $value);
				$slug = RosterMoveSourceTimesFacetTest::slugFor($key);
				return $slug !== '' ? strtolower($slug) : '';
			}
		}

		require_once dirname(__DIR__, 2) . '/includes/roster-times-facet.php';
	}

	public static function slugFor(string $key): string {
		return self::$slug_map[$key] ?? '';
	}

	public function test_url_times_excludes_moved_row(): void {
		$this->assertFalse(
			intersoccer_roster_times_matches_url_facet('11:15–12:45', '09:30–11:00')
		);
	}

	public function test_url_times_includes_same_times(): void {
		$this->assertTrue(
			intersoccer_roster_times_matches_url_facet('09:30–11:00', '09:30–11:00')
		);
		$this->assertTrue(
			intersoccer_roster_times_matches_url_facet('09-30-11-00', '09:30-11:00')
		);
	}

	public function test_fail_open_when_row_normalize_empty(): void {
		$this->assertTrue(
			intersoccer_roster_times_matches_url_facet('Unknown Local Times', '09:30–11:00')
		);
	}

	public function test_fail_open_when_url_times_empty(): void {
		$this->assertTrue(
			intersoccer_roster_times_matches_url_facet('11:15–12:45', '')
		);
		$this->assertTrue(
			intersoccer_roster_times_matches_url_facet('11:15–12:45', 'N/A')
		);
	}

	public function test_move_verify_requires_matching_times(): void {
		$this->assertTrue(
			intersoccer_roster_times_equal_for_move_verify('11:15–12:45', '11-15-12-45')
		);
		$this->assertFalse(
			intersoccer_roster_times_equal_for_move_verify('09:30–11:00', '11:15–12:45')
		);
	}

	public function test_normalize_returns_canonical_slug(): void {
		$this->assertSame('09-30-11-00', intersoccer_normalize_times_slug_for_roster_facet('09:30–11:00'));
		$this->assertSame('', intersoccer_normalize_times_slug_for_roster_facet(''));
	}
}
