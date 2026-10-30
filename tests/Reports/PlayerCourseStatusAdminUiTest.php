<?php
/**
 * Player course status office page: status labels, pending lines, filters, pagination.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class PlayerCourseStatusAdminUiTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/player-course-status-wc-status-stub.php';
		$includes = dirname(__DIR__, 2) . '/includes';
		foreach (['medical-access.php', 'player-course-status.php', 'player-course-status-admin.php'] as $file) {
			require_once $includes . '/' . $file;
		}
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		unset($GLOBALS['intersoccer_test_current_user_caps']);
		parent::tearDown();
	}

	public function test_status_text_uses_translated_label_when_present(): void {
		$text = intersoccer_player_course_status_admin_status_text([
			'booked' => true,
			'booking_status' => 'booked',
			'booking_status_label' => 'Déjà réservé',
		]);

		$this->assertSame('Déjà réservé', $text);
	}

	public function test_empty_booking_status_label_falls_back_to_helper(): void {
		$this->assertSame('Booked', intersoccer_player_course_status_admin_status_text([
			'booked' => false,
			'booking_status' => 'booked',
			'booking_status_label' => '',
		]));
		$this->assertSame('Pending payment', intersoccer_player_course_status_admin_status_text([
			'booking_status' => 'pending_payment',
			'booking_status_label' => '',
		]));
	}

	public function test_status_text_falls_back_to_helper_for_four_statuses(): void {
		$this->assertSame('Booked', intersoccer_player_course_status_admin_status_text([
			'booking_status' => 'booked',
		]));
		$this->assertSame('Pending payment', intersoccer_player_course_status_admin_status_text([
			'booking_status' => 'pending_payment',
		]));
		$this->assertSame('On hold', intersoccer_player_course_status_admin_status_text([
			'booking_status' => 'on_hold',
		]));
		$this->assertSame('Not booked', intersoccer_player_course_status_admin_status_text([
			'booking_status' => 'not_booked',
			'booked' => false,
		]));
	}

	public function test_missing_status_uses_booked_yes_no_and_unknown_is_raw(): void {
		$this->assertSame('Yes', intersoccer_player_course_status_admin_status_text([
			'booked' => true,
		]));
		$this->assertSame('No', intersoccer_player_course_status_admin_status_text([
			'booked' => false,
		]));
		$this->assertSame('custom-status', intersoccer_player_course_status_admin_status_text([
			'booking_status' => 'custom-status',
		]));
	}

	public function test_row_escapes_status_label_once(): void {
		$html = intersoccer_player_course_status_admin_rows_html([
			[
				'player' => ['name' => 'Ada', 'tier' => 'uuid'],
				'parent' => ['id' => 15, 'email' => 'ada@example.test'],
				'booked' => true,
				'booking_status' => 'booked',
				'booking_status_label' => 'Payé <i>ok</i>',
				'dob' => '2014-05-06',
				'dob_restricted' => false,
				'age' => 11,
				'bookings_label' => '',
			],
		]);

		$this->assertStringContainsString('Payé &lt;i&gt;ok&lt;/i&gt;', $html);
		$this->assertStringNotContainsString('<i>', $html);
		$this->assertStringNotContainsString('&amp;lt;', $html);
	}

	public function test_pending_lines_show_order_status_and_are_not_deduped(): void {
		$html = intersoccer_player_course_status_admin_bookings_html([
			'bookings_label' => 'Ada <b>Camp</b>',
			'pending_bookings' => [
				['label' => 'Ada <b>Camp</b>', 'order_status' => 'pending'],
				['label' => 'Hold <script>x</script>', 'order_status' => 'on-hold'],
			],
		]);

		$this->assertSame(2, substr_count($html, 'Ada &lt;b&gt;Camp&lt;/b&gt;'));
		$this->assertStringContainsString('Pending: Ada &lt;b&gt;Camp&lt;/b&gt; (pending)', $html);
		$this->assertStringContainsString('Pending: Hold &lt;script&gt;x&lt;/script&gt; (on-hold)', $html);
		$this->assertStringNotContainsString('<b>', $html);
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringNotContainsString('&amp;lt;', $html);
	}

	public function test_pending_lines_hidden_when_absent_or_empty(): void {
		$with_label = intersoccer_player_course_status_admin_bookings_html([
			'bookings_label' => 'Sunday course',
		]);
		$empty = intersoccer_player_course_status_admin_bookings_html([
			'bookings_label' => 'Sunday course',
			'pending_bookings' => [],
		]);

		$this->assertSame('Sunday course', $with_label);
		$this->assertSame('Sunday course', $empty);
		$this->assertStringNotContainsString('Pending:', $with_label);
		$this->assertSame('', intersoccer_player_course_status_admin_pending_lines_html(null));
	}

	public function test_totals_show_pending_and_on_hold_as_of_which_without_adding(): void {
		$text = intersoccer_player_course_status_admin_totals_text([
			'players' => 10,
			'booked' => 4,
			'not_booked' => 6,
			'pending_payment' => 2,
			'on_hold' => 1,
			'unmatched_bookings' => 3,
		], 6);

		$this->assertSame(
			'Players: 10 · Booked: 4 · Not booked: 6 (of which pending payment: 2, on hold: 1) · Unmatched bookings: 3 · Showing: 6',
			$text
		);
	}

	public function test_totals_omit_of_which_when_keys_absent_and_keep_zero_when_present(): void {
		$absent = intersoccer_player_course_status_admin_totals_text([
			'players' => 10,
			'booked' => 4,
			'not_booked' => 6,
			'unmatched_bookings' => 3,
		], 6);
		$zeros = intersoccer_player_course_status_admin_totals_text([
			'players' => 10,
			'booked' => 4,
			'not_booked' => 6,
			'pending_payment' => 0,
			'on_hold' => 0,
			'unmatched_bookings' => 3,
		], 6);
		$pending_only = intersoccer_player_course_status_admin_totals_text([
			'players' => 10,
			'booked' => 4,
			'not_booked' => 6,
			'pending_payment' => 2,
			'unmatched_bookings' => 3,
		], 6);

		$this->assertSame(
			'Players: 10 · Booked: 4 · Not booked: 6 · Unmatched bookings: 3 · Showing: 6',
			$absent
		);
		$this->assertStringNotContainsString('of which', $absent);
		$this->assertSame(
			'Players: 10 · Booked: 4 · Not booked: 6 (of which pending payment: 0, on hold: 0) · Unmatched bookings: 3 · Showing: 6',
			$zeros
		);
		$this->assertSame(
			'Players: 10 · Booked: 4 · Not booked: 6 (of which pending payment: 2) · Unmatched bookings: 3 · Showing: 6',
			$pending_only
		);
	}

	public function test_dob_follows_restricted_flag_and_age_is_always_shown(): void {
		$open = intersoccer_player_course_status_admin_rows_html([
			$this->player_row([
				'dob' => '2014-05-06',
				'dob_restricted' => false,
				'age' => 0,
			]),
		]);
		$restricted = intersoccer_player_course_status_admin_rows_html([
			$this->player_row([
				'dob' => '2012-03-04',
				'dob_restricted' => true,
				'age' => 8,
			]),
		]);
		$unknown_age = intersoccer_player_course_status_admin_rows_html([
			$this->player_row([
				'dob' => '2014-05-06',
				'dob_restricted' => false,
				'age' => null,
			]),
		]);

		$this->assertStringContainsString('>2014-05-06<', $open);
		$this->assertStringContainsString('>0<', $open);
		$this->assertStringNotContainsString('Restricted', $open);
		$this->assertStringContainsString('>Restricted<', $restricted);
		$this->assertStringContainsString('>8<', $restricted);
		$this->assertStringNotContainsString('2012-03-04', $restricted);
		$this->assertStringContainsString('<td>—</td>', $unknown_age);
		$this->assertStringContainsString('2014-05-06', $unknown_age);
	}

	public function test_unmatched_table_hidden_when_empty_and_escapes_once(): void {
		$this->assertSame('', intersoccer_player_course_status_admin_unmatched_html([]));
		$this->assertSame('', intersoccer_player_course_status_admin_unmatched_html(null));

		$html = intersoccer_player_course_status_admin_unmatched_html([
			[
				'player_name' => 'Ada <script>x</script>',
				'customer_id' => 42,
				'label' => 'Course & Hall',
				'order_status' => 'processing',
			],
		]);

		$this->assertStringContainsString('Ada &lt;script&gt;x&lt;/script&gt;', $html);
		$this->assertStringContainsString('Course &amp; Hall', $html);
		$this->assertStringContainsString('>42<', $html);
		$this->assertStringContainsString('>processing<', $html);
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringNotContainsString('&amp;amp;', $html);
		$this->assertStringNotContainsString('&amp;lt;', $html);
	}

	public function test_filters_come_from_filter_options_and_status_dropdown(): void {
		$html = intersoccer_player_course_status_admin_filters_html(
			[
				'year' => 2019,
				'season_type' => 'Autumn',
				'girls_mode' => 'mixed',
				'booked' => 'not_booked',
				'booking_status' => 'on_hold',
				'search' => 'Ada <b>',
				'venue' => 'missing-hall',
				'course_day' => 'sunday',
				'age_group' => 'u8',
			],
			[
				'venues' => ['zurich' => 'Zurich <b>Hall'],
				'course_days' => ['sunday' => 'Sunday', 'monday' => 'Monday'],
				'age_groups' => ['u8' => 'Under 8 & Co'],
				'seasons' => ['Winter'],
			]
		);

		$this->assertStringContainsString('name="booking_status"', $html);
		$this->assertStringContainsString('id="pcrs-status"', $html);
		$this->assertStringContainsString('value="on_hold" selected="selected"', $html);
		$this->assertStringContainsString('>All<', $html);
		$this->assertStringContainsString('>Booked<', $html);
		$this->assertStringContainsString('>Pending payment<', $html);
		$this->assertStringContainsString('>On hold<', $html);
		$this->assertStringContainsString('>Not booked<', $html);
		$this->assertSame(1, substr_count($html, 'value="not_booked" selected="selected"'));
		$this->assertStringContainsString('Zurich &lt;b&gt;Hall', $html);
		$this->assertStringContainsString('>Winter<', $html);
		$this->assertStringContainsString('value="Autumn" selected="selected"', $html);
		$this->assertStringNotContainsString('>Summer<', $html);
		$this->assertStringNotContainsString('>Spring<', $html);
		$this->assertStringContainsString('value="missing-hall" selected="selected"', $html);
		$this->assertStringContainsString('Under 8 &amp; Co', $html);
		$this->assertStringContainsString('value="sunday" selected="selected"', $html);
		$this->assertStringContainsString('>Sunday<', $html);
		$this->assertStringContainsString('value="2019" selected="selected"', $html);
		$this->assertStringContainsString('value="Ada &lt;b&gt;"', $html);
		$this->assertStringNotContainsString('<b>', $html);
		$this->assertStringNotContainsString('&amp;amp;', $html);
		$this->assertStringNotContainsString('&amp;lt;', $html);
		$this->assertStringNotContainsString('type="number"', $html);
	}

	public function test_pagination_is_50_and_keeps_booking_status(): void {
		$rows = [];
		for ($i = 0; $i < 51; $i++) {
			$rows[] = ['player' => ['name' => 'P' . $i]];
		}
		$page1 = intersoccer_player_course_status_admin_page_slice($rows, 1);
		$page2 = intersoccer_player_course_status_admin_page_slice($rows, 9);

		$this->assertSame(50, $page1['per_page']);
		$this->assertCount(50, $page1['rows']);
		$this->assertSame(51, $page1['total_rows']);
		$this->assertSame(2, $page1['total_pages']);
		$this->assertSame('P0', $page1['rows'][0]['player']['name']);
		$this->assertCount(1, $page2['rows']);
		$this->assertSame('P50', $page2['rows'][0]['player']['name']);

		$filters = [
			'year' => 2026,
			'season_type' => 'Winter',
			'girls_mode' => 'all',
			'booked' => 'all',
			'booking_status' => 'pending_payment',
			'search' => '',
			'venue' => '',
			'course_day' => 'sunday',
			'age_group' => '',
		];
		$args = intersoccer_player_course_status_admin_query_args($filters);
		$this->assertSame('pending_payment', $args['booking_status']);
		$this->assertArrayNotHasKey('paged', $args);

		$url = intersoccer_player_course_status_admin_pagination_base($filters);
		$this->assertStringContainsString('page=intersoccer-player-course-status', $url);
		$this->assertStringContainsString('booking_status=pending_payment', $url);
		$this->assertStringContainsString('course_day=sunday', $url);
		$this->assertStringContainsString('paged=', $url);

		$export = intersoccer_player_course_status_admin_export_url($filters);
		$this->assertStringContainsString('export_excel=1', $export);
		$this->assertStringContainsString('booking_status=pending_payment', $export);
	}

	public function test_request_filters_pass_booking_status_through(): void {
		$_GET = [
			'booking_status' => 'not_booked',
			'year' => '2025',
			'venue' => 'basel',
			'booked' => 'not_booked',
		];
		$filters = intersoccer_player_course_status_request_filters();
		$this->assertSame('not_booked', $filters['booking_status']);
		$this->assertSame(2025, $filters['year']);
		$this->assertSame('basel', $filters['venue']);
		$this->assertSame('not_booked', $filters['booked']);

		$_GET['booking_status'] = 'cancelled';
		$filters = intersoccer_player_course_status_request_filters();
		$this->assertSame('', $filters['booking_status']);
	}

	public function test_warning_notice_is_shown_above_the_table_and_hidden_when_empty(): void {
		$shown = intersoccer_player_course_status_admin_warnings_html([
			['code' => 'roster_query_failed', 'message' => 'Roster query failed <b>badly</b>'],
			['code' => 'other', 'message' => 'Second warning'],
		]);
		$rows = intersoccer_player_course_status_admin_rows_html([
			$this->player_row(['player' => ['name' => 'Ada', 'tier' => 'uuid']]),
		]);
		$page = $shown . '<table class="widefat striped"><tbody>' . $rows . '</tbody></table>';

		$this->assertStringContainsString('class="notice notice-warning inline"', $shown);
		$this->assertStringContainsString('Roster query failed &lt;b&gt;badly&lt;/b&gt;', $shown);
		$this->assertStringContainsString('Second warning', $shown);
		$this->assertStringNotContainsString('<b>', $shown);
		$this->assertStringNotContainsString('&amp;lt;', $shown);
		$this->assertLessThan(strpos($page, '<table'), strpos($page, 'notice notice-warning'));

		$this->assertSame('', intersoccer_player_course_status_admin_warnings_html([]));
		$this->assertSame('', intersoccer_player_course_status_admin_warnings_html(null));
		$this->assertSame('', intersoccer_player_course_status_admin_warnings_html([
			['code' => 'roster_query_failed', 'message' => ''],
		]));
	}

	public function test_export_button_is_blocked_when_warnings_exist_and_linked_when_clear(): void {
		$filters = [
			'year' => 2026,
			'booking_status' => 'booked',
		];
		$clear = intersoccer_player_course_status_admin_export_control_html($filters, []);
		$missing = intersoccer_player_course_status_admin_export_control_html($filters, null);
		$blocked = intersoccer_player_course_status_admin_export_control_html($filters, [
			['code' => 'roster_query_failed', 'message' => 'The course roster query failed.'],
		]);

		$this->assertStringContainsString('<a class="button" href="', $clear);
		$this->assertStringContainsString('export_excel=1', $clear);
		$this->assertStringContainsString('Export to Excel', $clear);
		$this->assertStringNotContainsString('unavailable', $clear);
		$this->assertStringContainsString('export_excel=1', $missing);
		$this->assertStringNotContainsString('unavailable', $missing);

		$this->assertStringNotContainsString('<a ', $blocked);
		$this->assertStringNotContainsString('export_excel', $blocked);
		$this->assertStringNotContainsString('href=', $blocked);
		$this->assertStringContainsString('class="button disabled"', $blocked);
		$this->assertStringContainsString('Export to Excel', $blocked);
		$this->assertStringContainsString('Export is unavailable until the warning clears.', $blocked);
	}

	public function test_refresh_link_is_shown_for_manage_options_and_keeps_filters_and_nonce(): void {
		$GLOBALS['intersoccer_test_current_user_caps'] = ['manage_options' => true];
		$html = intersoccer_player_course_status_admin_refresh_html([
			'year' => 2026,
			'season_type' => 'Winter',
			'girls_mode' => 'girls_only',
			'booked' => 'not_booked',
			'booking_status' => 'on_hold',
			'search' => 'Ada',
			'venue' => 'zurich',
			'course_day' => 'sunday',
			'age_group' => 'u8',
		]);

		$this->assertStringContainsString('class="button button-secondary"', $html);
		$this->assertStringContainsString('>Refresh data<', $html);
		$this->assertStringContainsString('href="', $html);
		$this->assertStringContainsString('pcs_refresh=1', $html);
		$this->assertStringContainsString('_pcs_nonce=test_nonce_12345', $html);
		$this->assertStringContainsString('year=2026', $html);
		$this->assertStringContainsString('season_type=Winter', $html);
		$this->assertStringContainsString('girls_mode=girls_only', $html);
		$this->assertStringContainsString('booked=not_booked', $html);
		$this->assertStringContainsString('booking_status=on_hold', $html);
		$this->assertStringContainsString('search=Ada', $html);
		$this->assertStringContainsString('venue=zurich', $html);
		$this->assertStringContainsString('course_day=sunday', $html);
		$this->assertStringContainsString('age_group=u8', $html);
	}

	public function test_refresh_link_is_hidden_without_manage_options(): void {
		$GLOBALS['intersoccer_test_current_user_caps'] = ['manage_options' => false];
		$html = intersoccer_player_course_status_admin_refresh_html([
			'year' => 2026,
			'booking_status' => 'booked',
			'venue' => 'zurich',
		]);

		$this->assertSame('', $html);
	}

	public function test_order_status_uses_wc_name_when_available_and_raw_value_otherwise(): void {
		$this->assertTrue(function_exists('wc_get_order_status_name'));
		$this->assertSame('On hold <b>named</b>', intersoccer_player_course_status_admin_order_status_text('wc-on-hold'));
		$this->assertSame('pending', intersoccer_player_course_status_admin_order_status_text('pending'));
		$this->assertSame('empty-name', intersoccer_player_course_status_admin_order_status_text('empty-name'));

		$pending = intersoccer_player_course_status_admin_pending_lines_html([
			['label' => 'Sunday course', 'order_status' => 'wc-on-hold'],
			['label' => 'Monday course', 'order_status' => 'pending'],
		]);
		$unmatched = intersoccer_player_course_status_admin_unmatched_html([[
			'player_name' => 'Ada',
			'customer_id' => 9,
			'label' => 'Sunday course',
			'order_status' => 'wc-on-hold',
		]]);

		$this->assertStringContainsString('Pending: Sunday course (On hold &lt;b&gt;named&lt;/b&gt;)', $pending);
		$this->assertStringContainsString('Pending: Monday course (pending)', $pending);
		$this->assertStringContainsString('On hold &lt;b&gt;named&lt;/b&gt;', $unmatched);
		$this->assertStringNotContainsString('<b>', $pending . $unmatched);
		$this->assertStringNotContainsString('&amp;lt;', $pending . $unmatched);
		$this->assertStringNotContainsString('(wc-on-hold)', $pending);
		$this->assertStringNotContainsString('>wc-on-hold<', $unmatched);
	}

	public function test_export_status_column_stays_after_booked(): void {
		$headers = intersoccer_player_course_status_export_headers();
		$booked = array_search('Booked', $headers, true);
		$status = array_search('Status', $headers, true);

		$this->assertNotFalse($booked);
		$this->assertSame($booked + 1, $status);
		$this->assertContains('Line Type', $headers);
		$this->assertContains('Parent Email', $headers);
		$this->assertContains('Account Email', $headers);
		$this->assertNotContains('Course Bookings', $headers);
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function player_row(array $overrides): array {
		return array_merge([
			'player' => ['name' => 'Ada', 'tier' => 'uuid'],
			'parent' => ['id' => 15, 'email' => 'ada@example.test'],
			'booked' => false,
			'booking_status' => 'not_booked',
			'booking_status_label' => 'Not booked',
			'dob' => null,
			'dob_restricted' => false,
			'age' => 8,
			'bookings_label' => '',
			'pending_bookings' => [],
		], $overrides);
	}
}
