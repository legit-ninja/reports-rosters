<?php
/**
 * Win-back admin UI: filter and tab query handling, flag marks, pagination.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackReportAdminUiTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/includes/winback-report-admin.php';
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		unset(
			$GLOBALS['intersoccer_test_current_user_caps'],
			$GLOBALS['intersoccer_test_current_user'],
			$GLOBALS['intersoccer_medical_access_test_filters']
		);
		parent::tearDown();
	}

	public function test_filters_default_and_reject_invalid_kind_and_arrays() {
		$_GET = [
			'kind' => ['camp'],
			'venue' => 'Basel Hall!',
			'age_group' => '5-13y (Full Day)',
			'lookback_months' => '0',
			'tab' => 'date_unknown',
		];

		$filters = intersoccer_winback_report_request_filters();

		$this->assertSame('all', $filters['kind']);
		$this->assertSame('basel-hall', $filters['venue']);
		$this->assertSame('5-13y-full-day', $filters['age_group']);
		$this->assertSame(1, $filters['lookback_months']);
		$this->assertArrayNotHasKey('tab', $filters);
		$this->assertSame(
			['kind', 'venue', 'age_group', 'lookback_months'],
			array_keys($filters)
		);
	}

	public function test_filters_keep_valid_kind_and_default_lookback() {
		$_GET = ['kind' => 'course'];

		$filters = intersoccer_winback_report_request_filters();

		$this->assertSame('course', $filters['kind']);
		$this->assertSame('', $filters['venue']);
		$this->assertSame('', $filters['age_group']);
		$this->assertSame(12, $filters['lookback_months']);
	}

	public function test_tab_defaults_to_actionable_and_rejects_unknown() {
		$this->assertSame('actionable', intersoccer_winback_report_request_tab());

		$_GET['tab'] = 'not-a-tab';
		$this->assertSame('actionable', intersoccer_winback_report_request_tab());

		$_GET['tab'] = ['date_unknown'];
		$this->assertSame('actionable', intersoccer_winback_report_request_tab());

		$_GET['tab'] = 'no_upcoming';
		$this->assertSame('no_upcoming', intersoccer_winback_report_request_tab());

		$_GET['tab'] = 'date_unknown';
		$this->assertSame('date_unknown', intersoccer_winback_report_request_tab());
	}

	public function test_rows_for_tab_and_counts() {
		$rows = [
			['status' => 'actionable', 'player' => ['name' => 'A']],
			['status' => 'no_upcoming', 'player' => ['name' => 'B']],
			['status' => 'date_unknown', 'player' => ['name' => 'C']],
			['status' => 'actionable', 'player' => ['name' => 'D']],
			['status' => 'other'],
		];

		$this->assertSame(
			['actionable' => 2, 'no_upcoming' => 1, 'date_unknown' => 1],
			intersoccer_winback_report_tab_counts($rows)
		);
		$actionable = intersoccer_winback_report_rows_for_tab($rows, 'actionable');
		$this->assertCount(2, $actionable);
		$this->assertSame('A', $actionable[0]['player']['name']);
		$this->assertSame('D', $actionable[1]['player']['name']);
		$this->assertCount(2, intersoccer_winback_report_rows_for_tab($rows, 'bogus'));
	}

	public function test_export_without_tab_keeps_every_status_and_with_tab_limits() {
		$rows = [
			['status' => 'actionable'],
			['status' => 'no_upcoming'],
			['status' => 'date_unknown'],
		];

		$this->assertCount(3, intersoccer_winback_report_export_rows($rows));

		$_GET['tab'] = 'no_upcoming';
		$exported = intersoccer_winback_report_export_rows($rows);
		$this->assertCount(1, $exported);
		$this->assertSame('no_upcoming', $exported[0]['status']);
	}

	public function test_flag_mark_is_yes_or_blank() {
		$this->assertSame('Yes', intersoccer_winback_report_flag_mark(true));
		$this->assertSame('Yes', intersoccer_winback_report_flag_mark(1));
		$this->assertSame('', intersoccer_winback_report_flag_mark(false));
		$this->assertSame('', intersoccer_winback_report_flag_mark(0));
		$this->assertSame('', intersoccer_winback_report_flag_mark(null));
		$this->assertSame('', intersoccer_winback_report_flag_mark(''));
	}

	public function test_query_args_keep_filters_and_tab_for_excel() {
		$args = intersoccer_winback_report_query_args(
			[
				'kind' => 'camp',
				'venue' => 'basel-hall',
				'age_group' => '5-13y-full-day',
				'lookback_months' => 6,
			],
			'date_unknown',
			['export_excel' => '1']
		);

		$this->assertSame('intersoccer-winback-report', $args['page']);
		$this->assertSame('camp', $args['kind']);
		$this->assertSame('basel-hall', $args['venue']);
		$this->assertSame('5-13y-full-day', $args['age_group']);
		$this->assertSame(6, $args['lookback_months']);
		$this->assertSame('date_unknown', $args['tab']);
		$this->assertSame('1', $args['export_excel']);
	}

	public function test_export_all_query_args_keep_filters_and_omit_tab() {
		$filters = [
			'kind' => 'course',
			'venue' => 'basel-hall',
			'age_group' => '5-13y-full-day',
			'lookback_months' => 6,
		];
		$args = intersoccer_winback_report_export_all_query_args($filters);

		$this->assertSame('intersoccer-winback-report', $args['page']);
		$this->assertSame('course', $args['kind']);
		$this->assertSame('basel-hall', $args['venue']);
		$this->assertSame('5-13y-full-day', $args['age_group']);
		$this->assertSame(6, $args['lookback_months']);
		$this->assertSame('1', $args['export_excel']);
		$this->assertArrayNotHasKey('tab', $args);
	}

	public function test_page_slice_is_fifty_rows() {
		$rows = [];
		for ($i = 0; $i < 60; $i++) {
			$rows[] = ['n' => $i];
		}

		$first = intersoccer_winback_report_page_slice($rows, 1, 50);
		$this->assertSame(50, count($first['rows']));
		$this->assertSame(0, $first['rows'][0]['n']);
		$this->assertSame(2, $first['total_pages']);
		$this->assertSame(60, $first['total']);

		$second = intersoccer_winback_report_page_slice($rows, 9, 50);
		$this->assertSame(2, $second['paged']);
		$this->assertSame(10, count($second['rows']));
		$this->assertSame(50, $second['rows'][0]['n']);
	}

	public function test_shared_slug_keeps_first_label_and_selected_slug_survives() {
		$terms = [
			(object) ['slug' => 'zurich', 'name' => 'Zurich'],
			(object) ['slug' => 'zurich', 'name' => 'Zürich'],
			(object) ['slug' => 'basel-hall', 'name' => 'Basel Hall'],
		];
		$options = intersoccer_winback_report_term_options_from_terms($terms);
		$this->assertSame('Zurich', $options['zurich']);
		$this->assertSame('Basel Hall', $options['basel-hall']);
		$this->assertCount(2, $options);

		$with_selected = intersoccer_winback_report_facet_options('pa_intersoccer-venues', 'missing-venue');
		$this->assertArrayHasKey('missing-venue', $with_selected);
		$this->assertSame('missing-venue', $with_selected['missing-venue']);
	}

	public function test_event_html_escapes_and_shows_date_source() {
		$html = intersoccer_winback_report_format_event_html(
			[
				'product' => 'Camp <b>',
				'venue' => 'basel-hall',
				'age_group' => '5-13y-full-day',
				'date' => '2026-07-01',
				'date_source' => 'variation_camp_start',
			],
			['basel-hall' => 'Basel Hall'],
			['5-13y-full-day' => '5-13y (Full Day)'],
			true
		);

		$this->assertStringContainsString('Camp &lt;b&gt;', $html);
		$this->assertStringContainsString('Basel Hall', $html);
		$this->assertStringContainsString('5-13y (Full Day)', $html);
		$this->assertStringContainsString('2026-07-01', $html);
		$this->assertStringContainsString('variation_camp_start', $html);
		$this->assertStringContainsString('class="description"', $html);
		$this->assertStringNotContainsString('<b>', $html);

		$this->assertSame('&mdash;', intersoccer_winback_report_format_event_html(null, [], [], false));
	}

	public function test_more_dates_html_escapes_three_items_and_hides_empty_or_missing() {
		$venue_labels = [
			'basel-hall' => 'Basel Hall',
			'geneva-hall' => 'Geneva <script>',
		];
		$age_labels = [
			'5-13y-full-day' => '5-13y (Full Day)',
			'3-5y' => '3-5y & <b>',
		];
		$html = intersoccer_winback_report_format_more_targets_html(
			[
				[
					'start_date' => '2026-08-01',
					'product' => 'Easter <b>',
					'venue' => 'basel-hall',
					'age_group' => '5-13y-full-day',
				],
				[
					'start_date' => '2026-10-02<script>',
					'product' => 'Autumn Camp',
					'venue' => 'geneva-hall',
					'age_group' => '3-5y',
				],
				[
					'start_date' => '2026-12-14',
					'product' => 'Winter Camp',
					'venue' => 'unknown-venue',
					'age_group' => 'missing-age',
				],
			],
			$venue_labels,
			$age_labels
		);

		$this->assertStringContainsString('More dates', $html);
		$this->assertStringContainsString('2026-08-01, Easter &lt;b&gt;, Basel Hall, 5-13y (Full Day)', $html);
		$this->assertStringContainsString('2026-10-02&lt;script&gt;, Autumn Camp, Geneva &lt;script&gt;, 3-5y &amp; &lt;b&gt;', $html);
		$this->assertStringContainsString('2026-12-14, Winter Camp, unknown-venue, missing-age', $html);
		$this->assertStringNotContainsString('<b>', $html);
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertLessThan(strpos($html, '2026-10-02'), strpos($html, '2026-08-01'));
		$this->assertLessThan(strpos($html, '2026-12-14'), strpos($html, '2026-10-02'));

		$this->assertSame('', intersoccer_winback_report_format_more_targets_html([], $venue_labels, $age_labels));
		$this->assertSame('', intersoccer_winback_report_format_more_targets_html(null, $venue_labels, $age_labels));
	}

	public function test_unmapped_notice_lists_venue_and_age_group() {
		$items = intersoccer_winback_report_unmapped_notice_items([
			'venue' => ['zurich---fc-langnau' => 14],
			'age_group' => ['3-5y-half-day-5-13y-full-day' => 30, '' => 2],
		]);

		$this->assertCount(2, $items);
		$this->assertSame('Venue', $items[0]['facet']);
		$this->assertSame('zurich---fc-langnau', $items[0]['value']);
		$this->assertSame(14, $items[0]['count']);
		$this->assertSame('Age group', $items[1]['facet']);
		$this->assertSame(30, $items[1]['count']);
	}

	public function test_dob_stays_behind_player_camp_status_gate() {
		if (function_exists('intersoccer_test_ensure_pm_filter_stub')) {
			intersoccer_test_ensure_pm_filter_stub();
		}
		$row = ['dob' => '2015-06-01', 'age' => 11];

		intersoccer_test_set_user_caps(['coach' => true, 'read' => true]);
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		$hidden = intersoccer_winback_report_format_dob_for_export($row['dob']);
		$this->assertSame('Restricted', $hidden);
		$this->assertStringNotContainsString('2015-06-01', $hidden);
		$this->assertSame('11', intersoccer_winback_report_age_text($row));

		intersoccer_test_set_user_caps(['manage_options' => true]);
		if (function_exists('intersoccer_player_camp_status_reset_dob_gate_cache')) {
			intersoccer_player_camp_status_reset_dob_gate_cache();
		}
		$this->assertSame('2015-06-01', intersoccer_winback_report_format_dob_for_export($row['dob']));
	}

	public function test_events_column_only_when_the_key_is_present() {
		$this->assertFalse(intersoccer_winback_report_has_events_column([
			['status' => 'actionable'],
		]));
		$this->assertTrue(intersoccer_winback_report_has_events_column([
			['events_in_lookback' => 0],
		]));
		$this->assertSame('0', intersoccer_winback_report_events_text(['events_in_lookback' => 0]));
		$this->assertSame('—', intersoccer_winback_report_events_text(['status' => 'actionable']));
	}
}

}
