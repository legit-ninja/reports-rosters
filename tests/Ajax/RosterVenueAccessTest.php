<?php
/**
 * Coach venue access for single-roster export and expand-row details (#82 / #83).
 *
 * Covers the shared check reused from listing/export-all:
 * InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues
 * via intersoccer_roster_venues_allowed_for_coach / resolve helpers.
 */

use PHPUnit\Framework\TestCase;

class RosterVenueAccessTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname(__DIR__, 2) . '/includes/roster-coach-venue-access.php';
	}

	public function test_coach_without_venue_is_denied(): void {
		$this->assertFalse(
			intersoccer_roster_venues_allowed_for_coach(['Geneva Stadium'], ['Zurich North'])
		);
	}

	public function test_coach_with_venue_is_allowed(): void {
		$this->assertTrue(
			intersoccer_roster_venues_allowed_for_coach(['Zurich North'], ['Zurich North', 'Bern'])
		);
	}

	public function test_coach_with_empty_assignments_is_denied(): void {
		$this->assertFalse(
			intersoccer_roster_venues_allowed_for_coach(['Zurich North'], [])
		);
	}

	public function test_coach_with_unresolved_venues_is_denied(): void {
		$this->assertFalse(
			intersoccer_roster_venues_allowed_for_coach([], ['Zurich North'])
		);
	}

	public function test_admin_bypass_is_separate_from_coach_intersection(): void {
		// Admin path short-circuits in intersoccer_roster_current_user_can_access_venues
		// via manage_options; coach intersection itself still denies empty targets.
		$this->assertFalse(intersoccer_roster_venues_allowed_for_coach([], ['Zurich North']));
		$this->assertTrue(intersoccer_roster_venues_allowed_for_coach(['Geneva Stadium'], ['Geneva Stadium']));
	}

	public function test_resolve_venues_includes_explicit_venue_and_lookup(): void {
		global $wpdb;
		$wpdb = new class {
			public $prefix = 'wp_';
			public function prepare($query, ...$args) {
				return $query;
			}
			public function get_col($query) {
				return ['Geneva Stadium'];
			}
		};

		$resolved = intersoccer_roster_resolve_venues_from_request([
			'venue' => 'Zurich North',
			'variation_id' => 999,
		]);

		$this->assertContains('Zurich North', $resolved);
		$this->assertContains('Geneva Stadium', $resolved);
	}


	public function test_admin_manage_options_not_blocked_by_venue_check(): void {
		// Bootstrap stubs current_user_can() as true, so manage_options short-circuit applies.
		$this->assertTrue(intersoccer_roster_current_user_can_access_venues(['Geneva Stadium']));
		$this->assertTrue(intersoccer_roster_current_user_can_access_venues([]));
	}

	public function test_export_path_calls_shared_venue_check(): void {
		$src = file_get_contents(dirname(__DIR__, 2) . '/includes/roster-export.php');
		$this->assertStringContainsString('intersoccer_roster_current_user_can_access_venues', $src);
		$this->assertStringContainsString('intersoccer_roster_resolve_venues_from_request', $src);
		$this->assertStringContainsString('function intersoccer_export_roster', $src);
	}

	public function test_details_ajax_path_calls_shared_venue_check(): void {
		$src = file_get_contents(dirname(__DIR__, 2) . '/classes/Ajax/rosters-tabs-ajax-handler.php');
		$this->assertStringContainsString('intersoccer_roster_current_user_can_access_venues', $src);
		$this->assertStringContainsString('intersoccer_roster_resolve_venues_from_request', $src);
		$this->assertStringContainsString('getRosterDetails', $src);
	}

	public function test_shared_helper_uses_get_coach_accessible_venues(): void {
		$src = file_get_contents(dirname(__DIR__, 2) . '/includes/roster-coach-venue-access.php');
		$this->assertStringContainsString('get_coach_accessible_venues', $src);
		$this->assertStringContainsString('InterSoccer_Admin_Coach_Assignments', $src);
	}
}
