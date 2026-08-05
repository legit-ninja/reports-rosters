<?php
/**
 * Campaign Excel workbook (PhpSpreadsheet) — SWISS15_bookings_2026.xlsx house layout.
 *
 * @package InterSoccer\ReportsRosters\Campaign\Export
 */

namespace InterSoccer\ReportsRosters\Campaign\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

defined('ABSPATH') or die('Restricted access');

class ExcelExporter {

	const NAVY = '1F4E5F';
	const GREY = '595959';
	const ZEBRA = 'F2F6F7';

	/**
	 * @param array<string,mixed> $payload
	 * @return string Binary xlsx (or CSV fallback when PhpSpreadsheet missing)
	 */
	public function build(array $payload) {
		if (!class_exists(Spreadsheet::class)) {
			return $this->build_csv_fallback($payload);
		}

		if (CampaignReportSections::is_gate_blocked($payload)) {
			return $this->build_gate_stub_xlsx($payload);
		}

		$ss = new Spreadsheet();
		$campaign = (array) ($payload['campaign'] ?? []);
		$name = (string) ($campaign['name'] ?? 'Campaign');
		$start = (string) ($campaign['start'] ?? '');
		$end = (string) ($campaign['end'] ?? '');
		$headline = (array) ($payload['headline'] ?? []);
		$bookings_expected = (int) ($headline['line_item_bookings'] ?? 0);

		// --- Bookings ---
		$bookings = $ss->getActiveSheet();
		$bookings->setTitle('Bookings');
		$bookings->setCellValue('A1', $name . ' — all bookings');
		$this->style_title($bookings, 'A1', 13);
		$bookings->setCellValue('A2', sprintf('Promotion %s – %s', substr($start, 0, 10), substr($end, 0, 16)));
		$this->style_grey($bookings, 'A2');

		$headers = CampaignReportSections::booking_headers();
		$col = 1;
		foreach ($headers as $h) {
			$bookings->setCellValueByColumnAndRow($col, 4, $h);
			$col++;
		}
		$this->style_header_row($bookings, 4, count($headers));

		$raw_rows = (array) ($payload['child_rows'] ?? []);
		$r = 5;
		foreach ($raw_rows as $row) {
			$row = ExportAllowlist::filter_row((array) $row);
			$display = CampaignReportSections::booking_display_row($row);
			$col = 1;
			foreach ($headers as $h) {
				$bookings->setCellValueByColumnAndRow($col, $r, $display[$h] ?? '');
				$col++;
			}
			if (($r % 2) === 0) {
				$this->fill_row($bookings, $r, count($headers), self::ZEBRA);
			}
			$this->font_row($bookings, $r, count($headers), 9);
			$r++;
		}
		$last_data = max(5, $r - 1);
		$bookings_count = max(0, $last_data - 4);
		$bookings->setAutoFilter('A4:L' . $last_data);
		$bookings->freezePane('A5');

		// --- Summary ---
		$summary = $ss->createSheet();
		$summary->setTitle('Summary');
		$summary->setCellValue('A1', $name . ' — Summary');
		$this->style_title($summary, 'A1', 14);
		$summary->setCellValue('A2', 'Counts live from the Bookings sheet where noted. Headline revenue uses order totals.');
		$this->style_grey($summary, 'A2');

		$summary->setCellValue('A4', 'Headline (verified)');
		$this->style_section($summary, 'A4');
		$summary->setCellValue('A5', 'Metric');
		$summary->setCellValue('B5', 'Campaign');
		$summary->setCellValue('C5', 'Baseline');
		$summary->setCellValue('D5', 'Change %');
		$this->style_header_row($summary, 5, 4);

		$base = (array) ($headline['baseline'] ?? []);
		$summary->setCellValue('A6', 'Orders');
		$summary->setCellValue('B6', (int) ($headline['orders'] ?? 0));
		$summary->setCellValue('C6', (int) ($base['orders'] ?? 0));
		$summary->setCellValue('D6', '=IF(C6=0,"",ROUND(100*(B6-C6)/C6,0))');

		$summary->setCellValue('A7', 'Child bookings');
		$summary->setCellValue('B7', '=COUNTA(Bookings!B5:B' . $last_data . ')');
		$summary->setCellValue('C7', (int) ($base['line_item_bookings'] ?? 0));
		$summary->setCellValue('D7', '=IF(C7=0,"",ROUND(100*(B7-C7)/C7,0))');

		$summary->setCellValue('A8', 'Revenue (CHF, order totals)');
		$summary->setCellValue('B8', (float) ($headline['revenue_order_totals'] ?? 0));
		$summary->setCellValue('C8', (float) ($base['revenue_order_totals'] ?? 0));
		$summary->setCellValue('D8', '=IF(C8=0,"",ROUND(100*(B8-C8)/C8,0))');

		$summary->setCellValue('A9', 'Average order value (CHF)');
		$summary->setCellValue('B9', '=IF(B6=0,0,ROUND(B8/B6,2))');
		$summary->setCellValue('C9', (float) ($base['avg_order_value'] ?? 0));
		$summary->setCellValue('D9', '=IF(C9=0,"",ROUND(100*(B9-C9)/C9,0))');

		$summary->setCellValue('A10', 'Bookings using a code');
		$summary->setCellValue('B10', '=COUNTIF(Bookings!L5:L' . $last_data . ',"Yes")');
		$summary->setCellValue('A11', 'Female bookings');
		$summary->setCellValue('B11', '=COUNTIF(Bookings!F5:F' . $last_data . ',"female")');

		$row = 13;
		$row = $this->summary_season_block($summary, $row, $raw_rows, $last_data, (array) ($payload['demand'] ?? []));
		$row += 1;
		$row = $this->summary_booking_type_block($summary, $row, $raw_rows, $last_data);
		$row += 1;
		$this->summary_region_block($summary, $row, $raw_rows, $last_data, (array) ($payload['regions'] ?? []));

		// --- By Day ---
		$by_day = $ss->createSheet();
		$by_day->setTitle('By Day');
		$by_day->setCellValue('A1', 'By day (promotion period)');
		$this->style_title($by_day, 'A1', 13);
		$day_headers = ['Date', 'Day', 'Orders', 'Bookings', 'Using code', 'Line revenue (CHF)'];
		foreach ($day_headers as $i => $h) {
			$by_day->setCellValueByColumnAndRow($i + 1, 3, $h);
		}
		$this->style_header_row($by_day, 3, 6);
		$dr = 4;
		$tot_o = $tot_b = $tot_c = 0;
		$tot_r = 0.0;
		foreach ((array) (($payload['timing']['by_day'] ?? [])) as $date => $drow) {
			$drow = (array) $drow;
			$o = (int) ($drow['orders'] ?? 0);
			$b = (int) ($drow['line_items'] ?? 0);
			$c = (int) ($drow['coupon_orders'] ?? 0);
			$rev = (float) ($drow['line_revenue'] ?? $drow['order_revenue'] ?? 0);
			$tot_o += $o;
			$tot_b += $b;
			$tot_c += $c;
			$tot_r += $rev;
			$by_day->setCellValue('A' . $dr, (string) $date);
			$by_day->setCellValue('B' . $dr, (string) ($drow['day_name'] ?? ''));
			$by_day->setCellValue('C' . $dr, $o);
			$by_day->setCellValue('D' . $dr, $b);
			$by_day->setCellValue('E' . $dr, $c);
			$by_day->setCellValue('F' . $dr, $rev);
			$dr++;
		}
		$by_day->setCellValue('A' . $dr, 'TOTAL');
		$by_day->setCellValue('C' . $dr, $tot_o);
		$by_day->setCellValue('D' . $dr, $tot_b);
		$by_day->setCellValue('E' . $dr, $tot_c);
		$by_day->setCellValue('F' . $dr, $tot_r);
		$by_day->getStyle('A' . $dr . ':F' . $dr)->getFont()->setBold(true)->setName('Arial')->setSize(10);

		// --- Data Notes ---
		$notes = $ss->createSheet();
		$notes->setTitle('Data Notes');
		$notes->setCellValue('A1', 'Data notes');
		$this->style_title($notes, 'A1', 13);
		$notes->setCellValue('A3', 'Topic');
		$notes->setCellValue('B3', 'Detail');
		$this->style_header_row($notes, 3, 2);
		$nr = 4;
		$note_rows = [
			['Window', sprintf('Promotion %s to %s.', $start, $end)],
			[
				'Booking count',
				sprintf(
					'%d individual child bookings across %d orders. Bookings sheet rows: %d.%s',
					$bookings_expected,
					(int) ($headline['orders'] ?? 0),
					$bookings_count,
					($bookings_count !== $bookings_expected && $bookings_expected > 0)
						? ' WARNING: sheet row count does not match headline line-item bookings.'
						: ''
				),
			],
			['Per-child price not shown', 'Agency Bookings export omits line prices to avoid multi-child order fan-out confusion.'],
			['Privacy', 'Child names, dates of birth, contact and medical details are excluded (ExportAllowlist).'],
			['Revenue basis', (string) ($campaign['revenue_basis'] ?? 'order totals')],
			['Business age', CampaignReportSections::BUSINESS_AGE_FOOTNOTE],
			['Attribution', CampaignReportSections::attribution_limitation($payload)],
		];
		foreach ((array) ($payload['data_notes'] ?? []) as $note) {
			if (is_array($note)) {
				$note_rows[] = [(string) ($note['topic'] ?? ''), (string) ($note['detail'] ?? '')];
			} else {
				$note_rows[] = ['Note', (string) $note];
			}
		}
		foreach ((array) ($payload['warnings'] ?? []) as $w) {
			$note_rows[] = ['Warning', (string) $w];
		}
		foreach ($note_rows as $pair) {
			$notes->setCellValueExplicit('A' . $nr, $pair[0], DataType::TYPE_STRING);
			$notes->setCellValueExplicit('B' . $nr, $pair[1], DataType::TYPE_STRING);
			$notes->getStyle('A' . $nr)->getFont()->setBold(true)->setName('Arial')->setSize(10);
			$notes->getStyle('B' . $nr)->getFont()->setName('Arial')->setSize(10);
			$nr++;
		}

		// --- Channels (optional) ---
		$attr = (array) ($payload['attribution']['by_source'] ?? []);
		if ($attr) {
			$ch = $ss->createSheet();
			$ch->setTitle('Channels');
			$ch->setCellValue('A1', 'Channels (last-touch)');
			$this->style_title($ch, 'A1', 13);
			$ch->setCellValue('A2', CampaignReportSections::attribution_limitation($payload));
			$this->style_grey($ch, 'A2');
			$ch->setCellValue('A4', 'Source');
			$ch->setCellValue('B4', 'Orders');
			$ch->setCellValue('C4', 'Revenue');
			$this->style_header_row($ch, 4, 3);
			$cr = 5;
			foreach ($attr as $src => $row) {
				$ch->setCellValue('A' . $cr, (string) $src);
				$ch->setCellValue('B' . $cr, (int) ($row['orders'] ?? 0));
				$ch->setCellValue('C' . $cr, (float) ($row['revenue'] ?? 0));
				$cr++;
			}
			$ch->getComment('A2')->getText()->createTextRun(CampaignReportSections::attribution_limitation($payload));
		}

		// --- Momentum (optional) ---
		$momentum = (array) ($payload['momentum'] ?? []);
		if ($momentum) {
			$ms = $ss->createSheet();
			$ms->setTitle('Momentum');
			$trough = (array) ($momentum['trough'] ?? []);
			$obs = (array) ($momentum['observation'] ?? []);
			$ms->setCellValue('A1', 'Sales momentum');
			$this->style_title($ms, 'A1', 13);
			$ms->setCellValue('A3', 'Verdict');
			$ms->setCellValue('B3', (string) ($trough['verdict'] ?? ''));
			$ms->setCellValue('A4', 'After/before ratio');
			$ms->setCellValue('B4', $trough['after_vs_before_orders_ratio'] ?? '');
			$ms->setCellValue('A5', 'After complete');
			$ms->setCellValue('B5', !empty($obs['after_complete']) ? 'yes' : 'no');
			$ms->setCellValue('A7', 'Phase');
			$ms->setCellValue('B7', 'Days');
			$ms->setCellValue('C7', 'Orders');
			$ms->setCellValue('D7', 'Orders/week');
			$ms->setCellValue('E7', 'Revenue');
			$this->style_header_row($ms, 7, 5);
			$mr = 8;
			foreach ((array) ($momentum['phases'] ?? []) as $prow) {
				$ms->setCellValue('A' . $mr, (string) ($prow['label'] ?? $prow['id'] ?? ''));
				$ms->setCellValue('B' . $mr, (int) ($prow['days'] ?? 0));
				$ms->setCellValue('C' . $mr, (int) ($prow['orders'] ?? 0));
				$ms->setCellValue('D' . $mr, (float) ($prow['orders_per_week_equiv'] ?? 0));
				$ms->setCellValue('E' . $mr, (float) ($prow['revenue_order_totals'] ?? 0));
				$mr++;
			}
		}

		$ss->setActiveSheetIndex(0);
		return $this->save_xlsx($ss);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return string
	 */
	private function build_gate_stub_xlsx(array $payload) {
		$ss = new Spreadsheet();
		$ws = $ss->getActiveSheet();
		$ws->setTitle('Data quality');
		$ws->setCellValue('A1', 'Cannot produce — data quality');
		$this->style_title($ws, 'A1', 14);
		$ws->setCellValue('A3', 'Blocked reasons');
		$r = 4;
		foreach (CampaignReportSections::blocked_reasons($payload) as $reason) {
			$ws->setCellValue('A' . $r, (string) $reason);
			$r++;
		}
		$ws->setCellValue('A' . ($r + 1), CampaignReportSections::BUSINESS_AGE_FOOTNOTE);
		return $this->save_xlsx($ss);
	}

	/**
	 * @param Spreadsheet $ss
	 * @return string
	 */
	private function save_xlsx(Spreadsheet $ss) {
		$tmp = wp_tempnam('campaign-xlsx');
		$writer = new Xlsx($ss);
		$writer->save($tmp);
		$binary = file_get_contents($tmp);
		@unlink($tmp);
		return $binary === false ? '' : $binary;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return string
	 */
	private function build_csv_fallback(array $payload) {
		if (CampaignReportSections::is_gate_blocked($payload)) {
			$lines = ["Cannot produce — data quality"];
			foreach (CampaignReportSections::blocked_reasons($payload) as $r) {
				$lines[] = $r;
			}
			return implode("\n", $lines);
		}
		$out = [];
		$out[] = '## Summary';
		$h = (array) ($payload['headline'] ?? []);
		$out[] = 'Orders,' . (int) ($h['orders'] ?? 0);
		$out[] = 'Bookings,' . (int) ($h['line_item_bookings'] ?? 0);
		$out[] = 'Revenue,' . (float) ($h['revenue_order_totals'] ?? 0);
		$out[] = '';
		$out[] = '## Bookings';
		$out[] = implode(',', CampaignReportSections::booking_headers());
		foreach ((array) ($payload['child_rows'] ?? []) as $row) {
			$row = ExportAllowlist::filter_row((array) $row);
			$display = CampaignReportSections::booking_display_row($row);
			$cells = [];
			foreach (CampaignReportSections::booking_headers() as $hdr) {
				$cells[] = '"' . str_replace('"', '""', (string) ($display[$hdr] ?? '')) . '"';
			}
			$out[] = implode(',', $cells);
		}
		$out[] = '';
		$out[] = '## Data Notes';
		$out[] = 'Privacy,"Child names, DOB, contact, medical excluded"';
		$out[] = 'Business age,"' . CampaignReportSections::BUSINESS_AGE_FOOTNOTE . '"';
		return implode("\n", $out);
	}

	/**
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
	 * @param string                                        $cell
	 * @param float                                         $size
	 * @return void
	 */
	private function style_title($sheet, $cell, $size) {
		$sheet->getStyle($cell)->getFont()
			->setName('Arial')->setBold(true)->setSize($size)->getColor()->setRGB(self::NAVY);
	}

	/**
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
	 * @param string                                        $cell
	 * @return void
	 */
	private function style_grey($sheet, $cell) {
		$sheet->getStyle($cell)->getFont()
			->setName('Arial')->setSize(9)->getColor()->setRGB(self::GREY);
	}

	/**
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
	 * @param string                                        $cell
	 * @return void
	 */
	private function style_section($sheet, $cell) {
		$sheet->getStyle($cell)->getFont()
			->setName('Arial')->setBold(true)->setSize(12)->getColor()->setRGB(self::NAVY);
	}

	/**
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
	 * @param int                                           $row
	 * @param int                                           $cols
	 * @return void
	 */
	private function style_header_row($sheet, $row, $cols) {
		for ($c = 1; $c <= $cols; $c++) {
			$coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
			$sheet->getStyle($coord)->getFont()->setName('Arial')->setBold(true)->setSize(10)->getColor()->setRGB('FFFFFF');
			$sheet->getStyle($coord)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::NAVY);
		}
	}

	/**
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
	 * @param int                                           $row
	 * @param int                                           $cols
	 * @param string                                        $rgb
	 * @return void
	 */
	private function fill_row($sheet, $row, $cols, $rgb) {
		for ($c = 1; $c <= $cols; $c++) {
			$coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
			$sheet->getStyle($coord)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
		}
	}

	/**
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
	 * @param int                                           $row
	 * @param int                                           $cols
	 * @param float                                         $size
	 * @return void
	 */
	private function font_row($sheet, $row, $cols, $size) {
		for ($c = 1; $c <= $cols; $c++) {
			$coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
			$sheet->getStyle($coord)->getFont()->setName('Arial')->setSize($size);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param string                         $key
	 * @return string[]
	 */
	private function unique_display_values(array $rows, $key) {
		$vals = [];
		foreach ($rows as $row) {
			$row = (array) $row;
			if ($key === 'season') {
				$v = CampaignReportSections::display_season((string) ($row['season'] ?? ''));
			} elseif ($key === 'booking_type') {
				$v = CampaignReportSections::display_booking_type((string) ($row['booking_type'] ?? ''));
			} else {
				$v = (string) ($row[$key] ?? '');
				if ($v === '' || $v === 'not_recorded') {
					$v = $key === 'region' ? '(not set)' : '(not recorded)';
				}
			}
			$vals[$v] = true;
		}
		return array_keys($vals);
	}

	/**
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $summary
	 * @param int                                           $row
	 * @param array                                         $raw_rows
	 * @param int                                           $last_data
	 * @param array<string,mixed>                           $demand
	 * @return int
	 */
	private function summary_season_block($summary, $row, array $raw_rows, $last_data, array $demand) {
		$values = $this->unique_display_values($raw_rows, 'season');
		$rev_by_label = [];
		foreach ((array) ($demand['by_week'] ?? []) as $key => $drow) {
			$drow = (array) $drow;
			$raw_key = (string) ($drow['key'] ?? $key);
			$label = preg_match('/^(summer|autumn|winter|easter)/i', $raw_key, $m)
				? ucfirst(strtolower($m[1]))
				: (string) ($drow['label'] ?? $key);
			$label = CampaignReportSections::display_season($label);
			if (!isset($rev_by_label[$label])) {
				$rev_by_label[$label] = 0.0;
			}
			$rev_by_label[$label] += (float) ($drow['revenue'] ?? 0);
		}

		$summary->setCellValue('A' . $row, 'By season (bookings live)');
		$this->style_section($summary, 'A' . $row);
		$row++;
		$summary->setCellValue('A' . $row, 'Season');
		$summary->setCellValue('B' . $row, 'Bookings');
		$summary->setCellValue('C' . $row, 'Revenue (CHF)');
		$this->style_header_row($summary, $row, 3);
		$row++;
		foreach ($values as $val) {
			$summary->setCellValue('A' . $row, $val);
			$crit = str_replace('"', '""', $val);
			$summary->setCellValue('B' . $row, '=COUNTIF(Bookings!I5:I' . $last_data . ',"' . $crit . '")');
			$rev = 0.0;
			foreach ($rev_by_label as $lab => $amount) {
				if ($lab === $val || stripos($val, $lab) === 0 || stripos($lab, $val) === 0) {
					$rev = $amount;
					break;
				}
			}
			$summary->setCellValue('C' . $row, $rev);
			$row++;
		}
		return $row;
	}

	/**
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $summary
	 * @param int                                           $row
	 * @param array                                         $raw_rows
	 * @param int                                           $last_data
	 * @return int
	 */
	private function summary_booking_type_block($summary, $row, array $raw_rows, $last_data) {
		$values = $this->unique_display_values($raw_rows, 'booking_type');
		$summary->setCellValue('A' . $row, 'By booking type');
		$this->style_section($summary, 'A' . $row);
		$row++;
		$summary->setCellValue('A' . $row, 'Type');
		$summary->setCellValue('B' . $row, 'Bookings');
		$this->style_header_row($summary, $row, 2);
		$row++;
		foreach ($values as $val) {
			$summary->setCellValue('A' . $row, $val);
			$crit = str_replace('"', '""', $val);
			$summary->setCellValue('B' . $row, '=COUNTIF(Bookings!H5:H' . $last_data . ',"' . $crit . '")');
			$row++;
		}
		return $row;
	}

	/**
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $summary
	 * @param int                                           $row
	 * @param array                                         $raw_rows
	 * @param int                                           $last_data
	 * @param array<string,array>                           $regions
	 * @return int
	 */
	private function summary_region_block($summary, $row, array $raw_rows, $last_data, array $regions) {
		$values = $this->unique_display_values($raw_rows, 'region');
		if (!$values && $regions) {
			foreach (array_keys($regions) as $name) {
				$values[] = ($name === '' || $name === 'not_recorded') ? '(not set)' : (string) $name;
			}
		}
		$summary->setCellValue('A' . $row, 'By region');
		$this->style_section($summary, 'A' . $row);
		$row++;
		$summary->setCellValue('A' . $row, 'Region');
		$summary->setCellValue('B' . $row, 'Bookings');
		$summary->setCellValue('C' . $row, 'Revenue (CHF)');
		$this->style_header_row($summary, $row, 3);
		$row++;
		foreach ($values as $val) {
			$summary->setCellValue('A' . $row, $val);
			$crit = str_replace('"', '""', $val);
			$summary->setCellValue('B' . $row, '=COUNTIF(Bookings!J5:J' . $last_data . ',"' . $crit . '")');
			$key = ($val === '(not set)') ? 'not_recorded' : $val;
			$summary->setCellValue('C' . $row, (float) ($regions[$key]['revenue'] ?? 0));
			$row++;
		}
		return $row;
	}
}
