<?php
/**
 * RR camp schedule resolve: meta first, terms parse fallback.
 */

use PHPUnit\Framework\TestCase;

class CampScheduleResolveTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$utils = dirname(__DIR__, 2) . '/includes/utils.php';
		if (file_exists($utils)) {
			require_once $utils;
		}
		if (!function_exists('intersoccer_reports_resolve_camp_schedule')) {
			$this->markTestSkipped('intersoccer_reports_resolve_camp_schedule not loaded');
		}
	}

	public function test_resolve_prefers_variation_meta_when_pv_helper_absent() {
		$vid = 50101;
		if (class_exists('InterSoccerRRTestMeta')) {
			InterSoccerRRTestMeta::$data = [];
		}
		update_post_meta($vid, '_camp_start_date', '2026-07-13');
		update_post_meta($vid, '_camp_end_date', '2026-07-17');

		list($start, $end, $label, $source) = intersoccer_reports_resolve_camp_schedule(
			$vid,
			0,
			'summer-week-4-july-13-july-17-5-days',
			'Summer Camps 2026'
		);

		$this->assertSame('2026-07-13', $start);
		$this->assertSame('2026-07-17', $end);
		$this->assertSame('variation_meta', $source);
	}

	public function test_resolve_falls_back_to_terms_parse() {
		list($start, $end, $label, $source) = intersoccer_reports_resolve_camp_schedule(
			0,
			0,
			'summer-week-4-july-13-july-17-5-days',
			'Summer Camps 2026'
		);

		$this->assertSame('2026-07-13', $start);
		$this->assertSame('2026-07-17', $end);
		$this->assertSame('terms_parse', $source);
	}

	public function test_aggregation_uses_variation_meta_dates_without_camp_terms() {
		if (!function_exists('intersoccer_reports_build_camp_report_from_entries')) {
			require_once dirname(__DIR__, 2) . '/includes/final-reports-aggregation.php';
		}
		if (!function_exists('intersoccer_reports_build_camp_report_from_entries')) {
			$this->markTestSkipped('camp report builder missing');
		}

		$entries = [
			[
				'order_item_id' => 1,
				'age_group' => '5-13y Full Day',
				'canton' => 'Zurich',
				'venue' => 'FC Seefeld',
				'variation_id' => 0,
				'event_start_date' => '2026-07-06',
				'event_end_date' => '2026-07-10',
				'camp_terms' => '',
				'booking_type' => 'Full Week',
				'is_buyclub' => false,
				'activity_type' => 'Camp',
				'line_subtotal' => 530,
				'line_total' => 530,
			],
		];

		$report = intersoccer_reports_build_camp_report_from_entries($entries, false, 2026);
		$this->assertNotEmpty($report);
	}
}
