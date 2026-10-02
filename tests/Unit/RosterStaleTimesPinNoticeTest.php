<?php
/**
 * Stale URL times pin detection for roster details admin notice.
 *
 * Bookmarked source details after a times move: order_item_ids + old times → notice.
 * Matching times / empty normalize → no notice (fail open).
 *
 * @package InterSoccer_Reports_Rosters
 */

use PHPUnit\Framework\TestCase;

class RosterStaleTimesPinNoticeTest extends TestCase {

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
				return RosterStaleTimesPinNoticeTest::slugFor($key);
			}
		}

		if (!function_exists('intersoccer_roster_facet_for_grouping')) {
			function intersoccer_roster_facet_for_grouping($value, $taxonomy = '') {
				$key = trim((string) $value);
				$slug = RosterStaleTimesPinNoticeTest::slugFor($key);
				return $slug !== '' ? strtolower($slug) : '';
			}
		}

		if (!function_exists('esc_html')) {
			function esc_html($text) {
				return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
			}
		}

		require_once dirname(__DIR__, 2) . '/includes/roster-times-facet.php';
	}

	public static function slugFor(string $key): string {
		return self::$slug_map[$key] ?? '';
	}

	public function test_stale_when_pinned_row_times_disagree_with_url(): void {
		$this->assertTrue(
			intersoccer_roster_url_times_pin_is_stale(['11:15–12:45'], '09:30–11:00')
		);
	}

	public function test_not_stale_when_all_row_times_match_url(): void {
		$this->assertFalse(
			intersoccer_roster_url_times_pin_is_stale(['09:30–11:00', '09-30-11-00'], '09:30–11:00')
		);
	}

	public function test_stale_when_any_of_mixed_rows_disagree(): void {
		$this->assertTrue(
			intersoccer_roster_url_times_pin_is_stale(
				['09:30–11:00', '11:15–12:45'],
				'09:30–11:00'
			)
		);
	}

	public function test_fail_open_when_url_normalize_empty(): void {
		$this->assertFalse(
			intersoccer_roster_url_times_pin_is_stale(['11:15–12:45'], 'Unknown Local Times')
		);
		$this->assertFalse(
			intersoccer_roster_url_times_pin_is_stale(['11:15–12:45'], '')
		);
		$this->assertFalse(
			intersoccer_roster_url_times_pin_is_stale(['11:15–12:45'], 'N/A')
		);
	}

	public function test_fail_open_when_only_unnormalizable_rows(): void {
		$this->assertFalse(
			intersoccer_roster_url_times_pin_is_stale(['Unknown Local Times'], '09:30–11:00')
		);
	}

	public function test_notice_message_mentions_reopen_not_re_move(): void {
		$message = intersoccer_roster_stale_times_pin_notice_message();
		$this->assertStringContainsString('out of date', $message);
		$this->assertStringContainsString('course listing', $message);
		$this->assertStringContainsString('do not need to move', $message);
	}
}
