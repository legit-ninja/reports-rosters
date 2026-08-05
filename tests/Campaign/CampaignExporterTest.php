<?php
/**
 * Campaign Docx/Excel exporter regression (FINAL15 + gate stub + allowlist).
 *
 * @package InterSoccer\ReportsRosters\Tests\Campaign
 */

namespace InterSoccer\ReportsRosters\Tests\Campaign;

use InterSoccer\ReportsRosters\Campaign\CampaignDefinition;
use InterSoccer\ReportsRosters\Campaign\Export\CampaignReportSections;
use InterSoccer\ReportsRosters\Campaign\Export\DocxExporter;
use InterSoccer\ReportsRosters\Campaign\Export\ExcelExporter;
use InterSoccer\ReportsRosters\Campaign\Export\ExportAllowlist;
use InterSoccer\ReportsRosters\Campaign\LineItem;
use InterSoccer\ReportsRosters\Campaign\Metrics\CampaignMetricsAggregator;
use InterSoccer\ReportsRosters\Tests\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CampaignExporterTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function final15_payload_with_child_rows() {
		$campaign = CampaignDefinition::from_array([
			'id' => 1,
			'name' => 'FINAL15',
			'start_datetime' => '2026-07-16 00:00:00',
			'end_datetime' => '2026-07-19 23:59:59',
			'coupon_codes' => ['FINAL15'],
			'baseline_mode' => 'matched_prior',
			'order_statuses' => ['wc-completed', 'wc-processing'],
		]);
		$agg = new CampaignMetricsAggregator();
		$lines = Final15FixtureFactory::campaign_lines();
		$payload = $agg->aggregate($campaign, $lines, Final15FixtureFactory::baseline_lines(), [
			'source_id' => 'orders',
			'gate' => ['ok' => true, 'errors' => [], 'warnings' => []],
			'coupon_usage_counts' => ['FINAL15' => 25],
			'prior_family_keys' => Final15FixtureFactory::prior_family_keys(),
		]);
		$payload['child_rows'] = $this->child_rows_from_lines($lines);
		return $payload;
	}

	/**
	 * @param LineItem[] $lines
	 * @return array<int,array<string,mixed>>
	 */
	private function child_rows_from_lines(array $lines) {
		$rows = [];
		foreach ($lines as $line) {
			$ts = (string) $line->get('booking_timestamp', '');
			$season = trim((string) $line->get('season', ''));
			if ($season === '') {
				$week = (string) $line->get('camp_week', '');
				if (preg_match('/^(summer|autumn|winter|easter)/i', $week, $m)) {
					$season = ucfirst(strtolower($m[1]));
				}
			}
			if ($season !== '' && preg_match('/^\d{4}/', $ts)) {
				$year = substr($ts, 0, 4);
				if (stripos($season, $year) === false) {
					$season .= ' ' . $year;
				}
			}
			$row = [
				'order_id' => (int) $line->get('order_id', 0),
				'derived_age' => $line->get('age'),
				'gender' => $line->get('gender'),
				'activity' => $line->get('activity_type'),
				'girls_only' => (int) $line->get('girls_only'),
				'product' => $line->get('product_name'),
				'booking_type' => $line->get('booking_type'),
				'venue' => $line->get('venue'),
				'region' => $line->get('region'),
				'season' => $season !== '' ? $season : 'not_recorded',
				'camp_week' => $line->get('camp_week'),
				'price_paid' => $line->get('line_total'),
				'sibling_discount' => $line->get('sibling_discount'),
				'coupon_used' => $line->get('used_campaign_coupon') ? 1 : 0,
				'coupon_codes' => implode(',', (array) $line->get('coupon_codes', [])),
				'booking_timestamp' => $ts,
			];
			$rows[] = ExportAllowlist::filter_row($row);
		}
		return $rows;
	}

	/**
	 * Readable text of a built Word file (native .docx zip or HTML fallback).
	 *
	 * @param array{mime:string,ext:string,body:string} $file
	 * @return string
	 */
	private function word_text(array $file) {
		if ($file['ext'] !== 'docx') {
			return $file['body'];
		}
		$tmp = tempnam(sys_get_temp_dir(), 'campdocx');
		file_put_contents($tmp, $file['body']);
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($tmp) === true);
		$xml = (string) $zip->getFromName('word/document.xml');
		$zip->close();
		@unlink($tmp);
		return $xml;
	}

	public function test_docx_final15_figures_and_house_copy() {
		$payload = $this->final15_payload_with_child_rows();
		$exporter = new DocxExporter();
		$file = $exporter->build($payload);
		$this->assertSame('docx', $file['ext']);
		$this->assertSame('PK', substr($file['body'], 0, 2));
		$text = $this->word_text($file);
		$this->assertStringContainsString('Booking Performance Report', $text);
		$this->assertStringContainsString('74', $text);
		$this->assertStringContainsString('85', $text);
		$this->assertStringContainsString('26,405.75', $text);
		$this->assertStringContainsString('FINAL15', $text);
		$this->assertStringContainsString('last-touch', $text);
		$this->assertStringContainsString(CampaignReportSections::BUSINESS_AGE_FOOTNOTE, $text);
		$this->assertStringContainsString('Executive summary', $text);
		$this->assertStringContainsString('By region', $text);
		$this->assertStringContainsString('Recommendations for the next campaign', $text);
		$this->assertStringContainsString(CampaignReportSections::NAVY, $text);
		$this->assertSame('first booking in our system', $payload['cohorts']['cohort_label']);
		$this->assertNotEmpty($payload['timing']['peak_day']);
	}

	public function test_excel_bookings_row_count_and_headers() {
		$payload = $this->final15_payload_with_child_rows();
		$exporter = new ExcelExporter();
		$binary = $exporter->build($payload);
		$this->assertNotSame('', $binary);
		$this->assertSame('PK', substr($binary, 0, 2));

		$tmp = tempnam(sys_get_temp_dir(), 'campxlsx');
		file_put_contents($tmp, $binary);
		$wb = IOFactory::load($tmp);
		@unlink($tmp);

		$names = $wb->getSheetNames();
		$this->assertSame('Bookings', $names[0]);
		$this->assertContains('Summary', $names);
		$this->assertContains('By Day', $names);
		$this->assertContains('Data Notes', $names);

		$bookings = $wb->getSheetByName('Bookings');
		$headers = [];
		for ($c = 1; $c <= 12; $c++) {
			$headers[] = (string) $bookings->getCellByColumnAndRow($c, 4)->getValue();
		}
		$this->assertSame(CampaignReportSections::booking_headers(), $headers);

		$row_count = 0;
		$r = 5;
		while ($bookings->getCell('B' . $r)->getValue() !== null && $bookings->getCell('B' . $r)->getValue() !== '') {
			$row_count++;
			$r++;
			if ($r > 500) {
				break;
			}
		}
		$this->assertSame(85, $row_count);
		$this->assertSame(85, (int) $payload['headline']['line_item_bookings']);

		// Forbidden keys never appear as headers.
		$header_blob = implode('|', $headers);
		foreach (['first_name', 'dob', 'email', 'medical', 'avs'] as $forbidden) {
			$this->assertStringNotContainsStringIgnoringCase($forbidden, $header_blob);
		}

		$summary = $wb->getSheetByName('Summary');
		$this->assertSame('=IF(C6=0,"",ROUND(100*(B6-C6)/C6,0))', (string) $summary->getCell('D6')->getValue());
		$this->assertStringStartsWith('=COUNTA(Bookings!B5:B', (string) $summary->getCell('B7')->getValue());

		// Recalc key formulas with zero errors.
		$calc = \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($wb);
		$calc->clearCalculationCache();
		$orders_change = $summary->getCell('D6')->getCalculatedValue();
		$bookings_live = $summary->getCell('B7')->getCalculatedValue();
		$this->assertFalse(is_string($orders_change) && strpos((string) $orders_change, '#') === 0);
		$this->assertSame(85.0, (float) $bookings_live);
	}

	public function test_gate_stub_both_exporters() {
		$payload = [
			'_export_stub' => true,
			'campaign' => ['name' => 'Blocked', 'start' => '', 'end' => ''],
			'gate' => ['ok' => false, 'errors' => ['roster_source_refused_integrity_unverified'], 'warnings' => []],
			'errors' => ['roster_source_refused_integrity_unverified'],
		];
		$docx = (new DocxExporter())->build($payload);
		$text = $this->word_text($docx);
		$this->assertStringContainsString('cannot produce', strtolower($text));
		$this->assertStringContainsString('roster_source_refused_integrity_unverified', $text);
		$this->assertStringContainsString(CampaignReportSections::BUSINESS_AGE_FOOTNOTE, $text);

		$binary = (new ExcelExporter())->build($payload);
		$tmp = tempnam(sys_get_temp_dir(), 'campstub');
		file_put_contents($tmp, $binary);
		$wb = IOFactory::load($tmp);
		@unlink($tmp);
		$ws = $wb->getActiveSheet();
		$this->assertStringContainsString('Cannot produce', (string) $ws->getCell('A1')->getValue());
		$this->assertStringContainsString('roster_source_refused_integrity_unverified', (string) $ws->getCell('A4')->getValue());
	}

	public function test_allowlist_season_and_order_id() {
		$row = ExportAllowlist::filter_row([
			'order_id' => 99,
			'season' => 'Summer 2026',
			'derived_age' => 6,
			'first_name' => 'Secret',
			'dob' => '2019-01-01',
			'avs_number' => '756',
		]);
		$this->assertArrayHasKey('order_id', $row);
		$this->assertArrayHasKey('season', $row);
		$this->assertArrayNotHasKey('first_name', $row);
		$this->assertArrayNotHasKey('dob', $row);
		$this->assertArrayNotHasKey('avs_number', $row);
	}
}
