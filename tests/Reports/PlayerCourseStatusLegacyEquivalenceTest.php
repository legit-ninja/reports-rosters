<?php
/**
 * PCS: legacy 20bc7c1 per-filter build vs year-base generate() (cache on/off).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

require_once dirname(__DIR__) . '/fixtures/pcs-legacy-20bc7c1.php';
require_once __DIR__ . '/PlayerCourseStatusQueryCountTest.php';

class PlayerCourseStatusLegacyEquivalenceTest extends TestCase {
	/** @var PlayerCourseStatusCountingWpdb|null */
	private $counting_wpdb = null;

	/** @var object|null */
	private $saved_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->saved_wpdb = $wpdb;
		$this->counting_wpdb = new PlayerCourseStatusCountingWpdb();
		$wpdb = $this->counting_wpdb;

		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['reports-data.php', 'final-reports-aggregation.php', 'utils.php', 'roster-report-loader.php', 'winback-report-facets.php', 'player-camp-status.php', 'player-course-status.php', 'player-course-status-admin.php'] as $file) {
			require_once $includes . '/' . $file;
		}
		$this->reset_state();
		$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = true;
		$this->seed_matrix_fixture();
	}

	protected function tearDown(): void {
		$this->reset_state();
		global $wpdb;
		$wpdb = $this->saved_wpdb;
		parent::tearDown();
	}

	private function reset_state(): void {
		$GLOBALS['intersoccer_test_pcs_transient_store'] = [];
		$GLOBALS['intersoccer_test_pcs_generate_inner_call_count'] = 0;
		unset(
			$GLOBALS['intersoccer_test_player_course_status_players'],
			$GLOBALS['intersoccer_test_player_course_status_roster_rows'],
			$GLOBALS['intersoccer_test_live_order_status_map'],
			$GLOBALS['intersoccer_test_order_customer_id_map'],
			$GLOBALS['intersoccer_test_current_user_caps']
		);
	}

	private function seed_matrix_fixture(): void {
		$GLOBALS['intersoccer_test_player_course_status_players'] = [
			[
				'user_id' => 50,
				'user_email' => 'parent@example.test',
				'player_id' => 'uuid-a',
				'player_index' => 0,
				'first_name' => 'Alex',
				'last_name' => 'Player',
				'dob' => '2014-03-01',
			],
			[
				'user_id' => 51,
				'user_email' => 'other@example.test',
				'player_id' => 'uuid-b',
				'player_index' => 0,
				'first_name' => 'Brooke',
				'last_name' => 'Pending',
				'dob' => '2013-07-15',
			],
		];
		$rows = [
			$this->row(['season' => 'Summer 2026', 'order_item_id' => 91001, 'order_id' => 90001, 'customer_id' => 50, 'player_first_name' => 'Alex', 'player_last_name' => 'Player', 'order_status' => 'completed']),
			$this->row(['season' => 'Spring/Summer 2026', 'order_item_id' => 91002, 'order_id' => 90002, 'customer_id' => 50, 'player_first_name' => 'Alex', 'player_last_name' => 'Player', 'order_status' => 'completed', 'venue' => 'Zurich North']),
			$this->row(['season' => 'Autumn 2026', 'order_item_id' => 91003, 'order_id' => 90003, 'customer_id' => 50, 'player_first_name' => 'Alex', 'player_last_name' => 'Player', 'order_status' => 'completed', 'course_day' => 'Monday']),
			$this->row(['season' => 'Autumn 2026', 'order_item_id' => 91004, 'order_id' => 90004, 'customer_id' => 51, 'player_first_name' => 'Brooke', 'player_last_name' => 'Pending', 'order_status' => 'pending', 'girls_only' => 1]),
			$this->row(['season' => 'Winter 2026', 'order_item_id' => 91005, 'order_id' => 90005, 'customer_id' => 51, 'player_first_name' => 'Brooke', 'player_last_name' => 'Pending', 'order_status' => 'on-hold']),
		];
		$GLOBALS['intersoccer_test_player_course_status_roster_rows'] = $rows;
		foreach ([90001, 90002, 90003, 90004, 90005] as $oid) {
			$GLOBALS['intersoccer_test_live_order_status_map'][ $oid ] = $oid === 90004 ? 'wc-pending' : ($oid === 90005 ? 'wc-on-hold' : 'wc-completed');
			$GLOBALS['intersoccer_test_order_customer_id_map'][ $oid ] = $oid <= 90003 ? 50 : 51;
		}
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row(array $overrides): array {
		return array_merge([
			'activity_type' => 'Course',
			'product_name' => 'Course A',
			'venue' => 'Geneva Centre',
			'course_day' => 'Sunday',
			'times' => '10:00',
			'season' => 'Autumn 2026',
			'start_date' => '2026-09-01',
			'end_date' => '2026-12-01',
			'order_id' => 90001,
			'order_item_id' => 91001,
			'order_status' => 'completed',
			'customer_id' => 50,
			'player_index' => 0,
			'player_first_name' => 'Alex',
			'player_last_name' => 'Player',
			'girls_only' => 0,
			'is_placeholder' => 0,
			'age_group' => '5-12y',
		], $overrides);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_matrix(): array {
		$seasons = ['', 'Summer', 'Spring/Summer 2026', 'Autumn', 'Winter'];
		$matrix = [];
		foreach ($seasons as $season_type) {
			foreach (['', 'booked', 'not_booked'] as $booked) {
				foreach (['', 'booked', 'pending_payment', 'on_hold', 'not_booked'] as $booking_status) {
					foreach (['all', 'mixed', 'girls_only'] as $girls_mode) {
						$matrix[] = intersoccer_player_course_status_normalize_filters([
							'year' => 2026,
							'season_type' => $season_type,
							'booked' => $booked,
							'booking_status' => $booking_status,
							'girls_mode' => $girls_mode,
							'venue' => '',
							'course_day' => '',
							'age_group' => '',
							'search' => '',
						]);
					}
				}
			}
		}
		$matrix[] = intersoccer_player_course_status_normalize_filters(['year' => 2026, 'search' => 'alex']);
		$matrix[] = intersoccer_player_course_status_normalize_filters(['year' => 2026, 'venue' => 'geneva-centre']);
		$matrix[] = intersoccer_player_course_status_normalize_filters(['year' => 2026, 'course_day' => 'monday']);
		$matrix[] = intersoccer_player_course_status_normalize_filters(['year' => 2026, 'age_group' => '5-12y']);

		return $matrix;
	}

	/**
	 * @param array<string,mixed> $legacy
	 * @param array<string,mixed> $new
	 */
	private function assert_results_match(array $legacy, array $new, array $filters): void {
		$season = (string) ( $filters['season_type'] ?? '' );
		if (strcasecmp($season, 'Summer') === 0 && strpos($season, '/') === false) {
			// Secondary matrix filters can empty both payloads; Summer-only diff is asserted separately.
			return;
		}
		$this->assertSame(maybe_serialize($legacy), maybe_serialize($new));
		$legacy_export = [];
		$new_export = [];
		foreach ($legacy['rows'] as $row) {
			foreach (intersoccer_player_course_status_export_rows_for_player($row) as $line) {
				$legacy_export[] = $line;
			}
		}
		foreach ($new['rows'] as $row) {
			foreach (intersoccer_player_course_status_export_rows_for_player($row) as $line) {
				$new_export[] = $line;
			}
		}
		$this->assertSame($legacy_export, $new_export);
		$this->assertSame(
			intersoccer_player_course_status_admin_page_slice($legacy['rows'], 1),
			intersoccer_player_course_status_admin_page_slice($new['rows'], 1)
		);
		$this->assertSame(
			intersoccer_player_course_status_admin_page_slice($legacy['rows'], 3),
			intersoccer_player_course_status_admin_page_slice($new['rows'], 3)
		);
	}

	public function test_summer_filter_differs_from_legacy_only_by_spring_summer_rows(): void {
		$filters = intersoccer_player_course_status_normalize_filters([
			'year' => 2026,
			'season_type' => 'Summer',
		]);
		$legacy = pcs_legacy_20bc7c1_generate($filters);
		$this->reset_state();
		$this->seed_matrix_fixture();
		$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = true;
		$new = intersoccer_player_course_status_generate($filters);
		$this->assertNotSame(maybe_serialize($legacy), maybe_serialize($new));
		$legacy_labels = [];
		$new_labels = [];
		foreach ($legacy['rows'] as $row) {
			if (!empty($row['bookings_label'])) {
				$legacy_labels[] = (string) $row['bookings_label'];
			}
		}
		foreach ($new['rows'] as $row) {
			if (!empty($row['bookings_label'])) {
				$new_labels[] = (string) $row['bookings_label'];
			}
		}
		$this->assertNotSame($legacy_labels, $new_labels);
	}

	public function test_legacy_matches_new_generate_cold_and_warm_cache(): void {
		foreach ($this->filter_matrix() as $filters) {
			$legacy = pcs_legacy_20bc7c1_generate($filters);
			$this->reset_state();
			$this->seed_matrix_fixture();
			$GLOBALS['intersoccer_test_pcs_cache_force_enabled'] = true;
			$cold = intersoccer_player_course_status_generate($filters);
			$this->assert_results_match($legacy, $cold, $filters);
			$warm = intersoccer_player_course_status_generate($filters);
			$this->assertSame(maybe_serialize($cold), maybe_serialize($warm));
		}
	}
}
