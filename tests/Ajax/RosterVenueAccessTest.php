<?php
/**
 * Coach venue access for single-roster export and expand-row details (#82 / #83).
 *
 * Behavioural coverage for the query/result venue IN filter (not source-string checks):
 * - empty / NULL venue rows must not leak to coaches
 * - substring venue names (Geneva vs Geneva Champel) must not leak
 */

use PHPUnit\Framework\TestCase;

class RosterVenueAccessTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname(__DIR__, 2) . '/includes/roster-coach-venue-access.php';
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['intersoccer_test_current_user_caps'],
			$GLOBALS['intersoccer_test_current_user'],
			$GLOBALS['intersoccer_test_json_error_throws']
		);
		$_POST = [];
		parent::tearDown();
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

	public function test_admin_manage_options_not_blocked_by_venue_check(): void {
		// Bootstrap stubs current_user_can() as true, so manage_options short-circuit applies.
		$this->assertTrue(intersoccer_roster_current_user_can_access_venues(['Geneva Stadium']));
		$this->assertTrue(intersoccer_roster_current_user_can_access_venues([]));
	}

	/**
	 * order_item_ids mix: own-venue + empty-venue rows. Empty venue must not export.
	 */
	public function test_export_filter_excludes_empty_venue_rows_mixed_with_own_venue(): void {
		$rows = [
			[
				'order_item_id' => 101,
				'venue' => 'Geneva',
				'medical_conditions' => 'None',
				'parent_email' => 'own@example.com',
				'avs_number' => '756.1111.1111.11',
			],
			[
				'order_item_id' => 102,
				'venue' => '',
				'medical_conditions' => 'SECRET allergy',
				'parent_email' => 'leak@example.com',
				'avs_number' => '756.2222.2222.22',
			],
			[
				'order_item_id' => 103,
				'venue' => null,
				'medical_conditions' => 'SECRET asthma',
				'parent_email' => 'leak2@example.com',
				'avs_number' => '756.3333.3333.33',
			],
		];

		$filtered = intersoccer_roster_filter_rows_to_accessible_venues($rows, ['Geneva']);

		$this->assertCount(1, $filtered);
		$this->assertSame(101, $filtered[0]['order_item_id']);
		$this->assertSame('Geneva', $filtered[0]['venue']);
		$emails = array_column($filtered, 'parent_email');
		$this->assertNotContains('leak@example.com', $emails);
		$this->assertNotContains('leak2@example.com', $emails);
	}

	/**
	 * venue LIKE %Geneva% would also match Geneva Champel; exact IN must not.
	 */
	public function test_export_filter_excludes_substring_venue_names(): void {
		$rows = [
			[
				'order_item_id' => 201,
				'venue' => 'Geneva',
				'avs_number' => 'own',
				'medical_conditions' => 'ok',
			],
			[
				'order_item_id' => 202,
				'venue' => 'Geneva Champel',
				'avs_number' => 'LEAK',
				'medical_conditions' => 'secret',
			],
		];

		$filtered = intersoccer_roster_filter_rows_to_accessible_venues($rows, ['Geneva']);

		$this->assertCount(1, $filtered);
		$this->assertSame('Geneva', $filtered[0]['venue']);
		$this->assertSame('own', $filtered[0]['avs_number']);
	}

	public function test_details_filter_excludes_empty_and_substring_venues(): void {
		$rows = [
			['venue' => 'Geneva', 'player_name' => 'Allowed Player', 'age' => 10],
			['venue' => '', 'player_name' => 'Empty Venue Player', 'age' => 11],
			['venue' => 'Geneva Champel', 'player_name' => 'Substring Player', 'age' => 12],
		];

		$filtered = intersoccer_roster_filter_rows_to_accessible_venues($rows, ['Geneva']);

		$this->assertCount(1, $filtered);
		$this->assertSame('Allowed Player', $filtered[0]['player_name']);
	}

	public function test_sql_venue_in_clause_uses_exact_placeholders(): void {
		$where = ['variation_id = %d'];
		$params = [999];
		$ok = intersoccer_roster_append_coach_venue_in_clause($where, $params, ['Geneva', 'Bern']);

		$this->assertTrue($ok);
		$this->assertSame('venue IN (%s,%s)', $where[1]);
		$this->assertSame([999, 'Geneva', 'Bern'], $params);
	}

	public function test_sql_venue_in_clause_rejects_empty_accessible_list(): void {
		$where = [];
		$params = [];
		$ok = intersoccer_roster_append_coach_venue_in_clause($where, $params, ['', null]);
		$this->assertFalse($ok);
		$this->assertSame([], $where);
	}

	public function test_shared_helper_still_calls_get_coach_accessible_venues(): void {
		$src = file_get_contents(dirname(__DIR__, 2) . '/includes/roster-coach-venue-access.php');
		$this->assertStringContainsString('get_coach_accessible_venues', $src);
	}

	/**
	 * Shop Manager passes the menu check, then details, export, and expand-row
	 * read this scope. Null is unrestricted. An empty list would deny.
	 */
	public function test_shop_manager_capabilities_are_unrestricted_venue_scope(): void {
		$GLOBALS['intersoccer_test_current_user_caps'] = [
			'manage_woocommerce' => true,
		];
		$this->assertNull(intersoccer_roster_current_user_coach_venue_scope());

		$GLOBALS['intersoccer_test_current_user_caps'] = [
			'manage_intersoccer_rosters' => true,
		];
		$this->assertNull(intersoccer_roster_current_user_coach_venue_scope());
	}

	public function test_user_without_roster_capabilities_gets_empty_venue_scope(): void {
		$GLOBALS['intersoccer_test_current_user_caps'] = [];
		$GLOBALS['intersoccer_test_current_user'] = (object) [
			'ID' => 7,
			'roles' => ['subscriber'],
		];

		$this->assertSame([], intersoccer_roster_current_user_coach_venue_scope());
	}

	public function test_coach_without_shop_capabilities_stays_venue_limited(): void {
		$GLOBALS['intersoccer_test_current_user_caps'] = [
			'coach' => true,
		];
		$GLOBALS['intersoccer_test_current_user'] = (object) [
			'ID' => 42,
			'roles' => ['coach'],
		];

		$scope = intersoccer_roster_current_user_coach_venue_scope();
		$this->assertIsArray($scope);
		$this->assertNotNull($scope);
	}

	/**
	 * Export and expand-row were still manage_options or coach only, before venue scope.
	 * Shop managers use the same roster-page check as the menu.
	 */
	public function test_shop_manager_is_not_denied_at_export_or_expand_row_gates(): void {
		require_once dirname(__DIR__, 2) . '/includes/roster-export.php';
		require_once dirname(__DIR__, 2) . '/classes/Ajax/rosters-tabs-ajax-handler.php';
		$GLOBALS['intersoccer_test_json_error_throws'] = true;
		$_POST = [];

		foreach (['manage_woocommerce', 'manage_intersoccer_rosters'] as $cap) {
			$GLOBALS['intersoccer_test_current_user_caps'] = [$cap => true];

			try {
				intersoccer_export_roster();
				$this->fail('Export should stop before building a file.');
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString('No variation IDs', $e->getMessage());
				$this->assertStringNotContainsString('for this venue', $e->getMessage());
			}

			try {
				(new \InterSoccer\ReportsRosters\Ajax\RostersTabsAjaxHandler())->getRosterDetails();
				$this->fail('Expand-row should stop on a missing variation.');
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString('Invalid variation ID', $e->getMessage());
				$this->assertStringNotContainsString('Permission denied.', $e->getMessage());
			}
		}
	}

	public function test_user_without_roster_admin_caps_is_denied_at_export_and_expand_row(): void {
		require_once dirname(__DIR__, 2) . '/includes/roster-export.php';
		require_once dirname(__DIR__, 2) . '/classes/Ajax/rosters-tabs-ajax-handler.php';
		$GLOBALS['intersoccer_test_json_error_throws'] = true;
		$GLOBALS['intersoccer_test_current_user_caps'] = [];
		$GLOBALS['intersoccer_test_current_user'] = (object) [
			'ID' => 9,
			'roles' => ['subscriber'],
		];
		$_POST = [];

		try {
			intersoccer_export_roster();
			$this->fail('Export should deny a user with no roster caps.');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('You do not have permission to export rosters.', $e->getMessage());
			$this->assertStringNotContainsString('for this venue', $e->getMessage());
		}

		try {
			(new \InterSoccer\ReportsRosters\Ajax\RostersTabsAjaxHandler())->getRosterDetails();
			$this->fail('Expand-row should deny a user with no roster caps.');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Permission denied.', $e->getMessage());
		}
	}
}
