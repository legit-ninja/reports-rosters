<?php
/**
 * Final Numbers urgency heatmap helpers (formerly Underfilled programs bands).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class FinalReportsUrgencyTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		if (file_exists($includes . '/final-reports-aggregation.php')) {
			require_once $includes . '/final-reports-aggregation.php';
		}
	}

	public function test_urgency_band_thresholds() {
		if (!function_exists('intersoccer_reports_urgency_band')) {
			$this->markTestSkipped('intersoccer_reports_urgency_band not loaded');
		}
		$this->assertSame('count-critical', intersoccer_reports_urgency_band(7));
		$this->assertSame('count-low', intersoccer_reports_urgency_band(20));
		$this->assertSame('count-good', intersoccer_reports_urgency_band(29));
		$this->assertSame('count-optimal', intersoccer_reports_urgency_band(30));
	}

	public function test_parse_min_max_max() {
		if (!function_exists('intersoccer_reports_parse_min_max_max')) {
			$this->markTestSkipped('intersoccer_reports_parse_min_max_max not loaded');
		}
		$this->assertSame(15, intersoccer_reports_parse_min_max_max('2-15'));
		$this->assertSame(0, intersoccer_reports_parse_min_max_max(''));
		$this->assertSame(0, intersoccer_reports_parse_min_max_max('invalid'));
		$this->assertSame(0, intersoccer_reports_parse_min_max_max('12'));
	}

	public function test_is_urgent_band() {
		if (!function_exists('intersoccer_reports_is_urgent_band')) {
			$this->markTestSkipped('intersoccer_reports_is_urgent_band not loaded');
		}
		$this->assertTrue(intersoccer_reports_is_urgent_band('count-critical'));
		$this->assertTrue(intersoccer_reports_is_urgent_band('count-low'));
		$this->assertFalse(intersoccer_reports_is_urgent_band('count-good'));
		$this->assertFalse(intersoccer_reports_is_urgent_band('count-optimal'));
	}

	public function test_camp_excel_rows_include_urgency_from_max_of_min_max() {
		if (!function_exists('intersoccer_reports_camp_excel_data_rows')) {
			$this->markTestSkipped('intersoccer_reports_camp_excel_data_rows not loaded');
		}

		$report = [
			'6–10 Jul 2026' => [
				'Geneva' => [
					'Venue A' => [
						'Full Day' => [
							'full_week' => 2,
							'individual_days' => [
								'Monday' => 1,
								'Tuesday' => 0,
								'Wednesday' => 0,
								'Thursday' => 0,
								'Friday' => 0,
							],
							'min_max' => '2-3',
							'unique_records' => 3,
						],
						'Mini - Half Day' => [
							'full_week' => 10,
							'individual_days' => [
								'Monday' => 5,
								'Tuesday' => 5,
								'Wednesday' => 5,
								'Thursday' => 5,
								'Friday' => 5,
							],
							'min_max' => '15-15',
							'unique_records' => 15,
						],
					],
				],
			],
		];

		$rows = intersoccer_reports_camp_excel_data_rows($report);
		$this->assertCount(2, $rows);

		$full_day = null;
		$mini = null;
		foreach ($rows as $row) {
			if ($row[3] === 'Full Day') {
				$full_day = $row;
			}
			if ($row[3] === 'Mini - Half Day') {
				$mini = $row;
			}
		}
		$this->assertNotNull($full_day);
		$this->assertNotNull($mini);
		$this->assertSame('2-3', $full_day[11]);
		$this->assertSame(intersoccer_reports_urgency_band_label('count-critical'), $full_day[13]);
		$this->assertSame(intersoccer_reports_urgency_band_label('count-low'), $mini[13]);

		$urgent_only = intersoccer_reports_camp_excel_data_rows($report, true);
		$this->assertCount(2, $urgent_only);

		$report['6–10 Jul 2026']['Geneva']['Venue A']['Mini - Half Day']['min_max'] = '30-32';
		$filtered = intersoccer_reports_camp_excel_data_rows($report, true);
		$this->assertCount(1, $filtered);
		$this->assertSame('Full Day', $filtered[0][3]);
	}

	public function test_camp_venue_is_urgent_when_either_type_is_critical_or_low() {
		if (!function_exists('intersoccer_reports_camp_venue_is_urgent')) {
			$this->markTestSkipped('intersoccer_reports_camp_venue_is_urgent not loaded');
		}
		$urgent = [
			'Full Day' => ['min_max' => '30-35'],
			'Mini - Half Day' => ['min_max' => '1-4'],
		];
		$ok = [
			'Full Day' => ['min_max' => '28-29'],
			'Mini - Half Day' => ['min_max' => '30-31'],
		];
		$this->assertTrue(intersoccer_reports_camp_venue_is_urgent($urgent));
		$this->assertFalse(intersoccer_reports_camp_venue_is_urgent($ok));
	}

	public function test_urgency_band_argb_palette() {
		if (!function_exists('intersoccer_reports_urgency_band_argb')) {
			$this->markTestSkipped('intersoccer_reports_urgency_band_argb not loaded');
		}
		$this->assertSame('FFDC2626', intersoccer_reports_urgency_band_argb('count-critical'));
		$this->assertSame('FFD97706', intersoccer_reports_urgency_band_argb('count-low'));
		$this->assertSame('FF059669', intersoccer_reports_urgency_band_argb('count-good'));
		$this->assertSame('FF1D4ED8', intersoccer_reports_urgency_band_argb('count-optimal'));
	}

	public function test_at_risk_critical_always() {
		if (!function_exists('intersoccer_reports_is_at_risk_program')) {
			$this->markTestSkipped('intersoccer_reports_is_at_risk_program not loaded');
		}
		$this->assertTrue(intersoccer_reports_is_at_risk_program(7, 60));
		$this->assertTrue(intersoccer_reports_is_at_risk_program(0, null));
		$this->assertSame('critical', intersoccer_reports_at_risk_reason(5, 30));
	}

	public function test_at_risk_fragile_only_within_cutoff() {
		if (!function_exists('intersoccer_reports_is_at_risk_program')) {
			$this->markTestSkipped('intersoccer_reports_is_at_risk_program not loaded');
		}
		$this->assertTrue(intersoccer_reports_is_at_risk_program(8, 14));
		$this->assertTrue(intersoccer_reports_is_at_risk_program(9, 7));
		$this->assertFalse(intersoccer_reports_is_at_risk_program(8, 15));
		$this->assertFalse(intersoccer_reports_is_at_risk_program(9, null));
		$this->assertFalse(intersoccer_reports_is_at_risk_program(10, 5));
		$this->assertSame('fragile', intersoccer_reports_at_risk_reason(8, 10));
	}

	public function test_parse_week_start_date() {
		if (!function_exists('intersoccer_reports_parse_week_start_date')) {
			$this->markTestSkipped('intersoccer_reports_parse_week_start_date not loaded');
		}
		$this->assertSame('2026-07-06', intersoccer_reports_parse_week_start_date('6–10 Jul 2026'));
		$this->assertSame('2026-07-06', intersoccer_reports_parse_week_start_date('6-10 Jul 2026'));
		$this->assertNull(intersoccer_reports_parse_week_start_date(''));
	}

	public function test_camp_at_risk_rows_separate_full_and_mini() {
		if (!function_exists('intersoccer_reports_camp_at_risk_rows')) {
			$this->markTestSkipped('intersoccer_reports_camp_at_risk_rows not loaded');
		}
		$report = [
			'6–10 Jul 2026' => [
				'Geneva' => [
					'Venue A' => [
						'Full Day' => ['min_max' => '2-3'],
						'Mini - Half Day' => ['min_max' => '8-8'],
					],
				],
			],
		];
		// Today 2026-07-01 → 5 days left → Mini fragile qualifies; Full Day critical.
		$rows = intersoccer_reports_camp_at_risk_rows($report, '2026-07-01');
		$this->assertCount(2, $rows);
		$types = array_column($rows, 'session_type');
		$this->assertContains('Full Day', $types);
		$this->assertContains('Mini - Half Day', $types);

		// Far from start: only Critical (Full Day) remains.
		$rows_far = intersoccer_reports_camp_at_risk_rows($report, '2026-05-01');
		$this->assertCount(1, $rows_far);
		$this->assertSame('Full Day', $rows_far[0]['session_type']);
		$this->assertSame('critical', $rows_far[0]['reason']);
	}
}
