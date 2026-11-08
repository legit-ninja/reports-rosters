<?php
/**
 * Loaded intersoccer_resolve_times_slug_for_signature from includes/utils.php.
 *
 * RosterMoveSourceTimesFacetTest and RosterStaleTimesPinNoticeTest run in
 * separate processes and install a hyphenated fallback map only when that
 * function is absent. This file loads the real resolver. Unknown labels are
 * slugified (they do not fail open). Equal and moved clock times still match
 * or exclude, but the slug text is 09301100 rather than 09-30-11-00.
 */

use PHPUnit\Framework\TestCase;

class RosterTimesRealResolverTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname(__DIR__, 2) . '/includes/utils.php';
	}

	public function test_real_resolver_compacts_clock_times_and_slugifies_unknown_labels(): void {
		$this->assertSame('09301100', intersoccer_resolve_times_slug_for_signature('09:30–11:00'));
		$this->assertNotSame('09-30-11-00', intersoccer_resolve_times_slug_for_signature('09:30–11:00'));
		$this->assertSame('11151245', intersoccer_resolve_times_slug_for_signature('11:15–12:45'));
		$this->assertSame('unknown-local-times', intersoccer_resolve_times_slug_for_signature('Unknown Local Times'));

		$this->assertTrue(intersoccer_roster_times_matches_url_facet('09:30–11:00', '09:30–11:00'));
		$this->assertFalse(intersoccer_roster_times_matches_url_facet('11:15–12:45', '09:30–11:00'));
		$this->assertFalse(intersoccer_roster_times_matches_url_facet('Unknown Local Times', '09:30–11:00'));

		$this->assertFalse(intersoccer_roster_url_times_pin_is_stale(['11:15–12:45'], ''));
		$this->assertFalse(intersoccer_roster_url_times_pin_is_stale(['11:15–12:45'], 'N/A'));
		$this->assertTrue(intersoccer_roster_url_times_pin_is_stale(['11:15–12:45'], 'Unknown Local Times'));
		$this->assertTrue(intersoccer_roster_url_times_pin_is_stale(['Unknown Local Times'], '09:30–11:00'));
	}
}
