<?php
/**
 * Campaign Excel workbook (PhpSpreadsheet).
 *
 * @package InterSoccer\ReportsRosters\Campaign\Export
 */

namespace InterSoccer\ReportsRosters\Campaign\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

defined('ABSPATH') or die('Restricted access');

class ExcelExporter {

	/**
	 * @param array<string,mixed> $payload
	 * @return string Binary xlsx
	 */
	public function build(array $payload) {
		$ss = new Spreadsheet();

		// Sheet 1: Children (allowlisted)
		$children = $ss->getActiveSheet();
		$children->setTitle('Children');
		$rows = (array) ($payload['child_rows'] ?? []);
		$headers = ExportAllowlist::allowed_keys();
		$col = 1;
		foreach ($headers as $h) {
			$children->setCellValueByColumnAndRow($col++, 1, $h);
		}
		$r = 2;
		foreach ($rows as $row) {
			$row = ExportAllowlist::filter_row((array) $row);
			$col = 1;
			foreach ($headers as $h) {
				$children->setCellValueByColumnAndRow($col++, $r, $row[$h] ?? '');
			}
			$r++;
		}
		$last_data_row = max(2, $r - 1);

		// Sheet 2: Summary with formulas where practical
		$summary = $ss->createSheet();
		$summary->setTitle('Summary');
		$headline = (array) ($payload['headline'] ?? []);
		$summary->setCellValue('A1', 'Metric');
		$summary->setCellValue('B1', 'Campaign');
		$summary->setCellValue('C1', 'Baseline');
		$summary->setCellValue('D1', 'Change %');
		$summary->setCellValue('A2', 'Orders');
		$summary->setCellValue('B2', (int) ($headline['orders'] ?? 0));
		$summary->setCellValue('C2', (int) ($headline['baseline']['orders'] ?? 0));
		$summary->setCellValue('D2', '=IF(C2=0,"",ROUND(100*(B2-C2)/C2,0))');
		$summary->setCellValue('A3', 'Line-item bookings');
		$summary->setCellValue('B3', (int) ($headline['line_item_bookings'] ?? 0));
		$summary->setCellValue('C3', (int) ($headline['baseline']['line_item_bookings'] ?? 0));
		$summary->setCellValue('D3', '=IF(C3=0,"",ROUND(100*(B3-C3)/C3,0))');
		$summary->setCellValue('A4', 'Revenue (order totals)');
		$summary->setCellValue('B4', (float) ($headline['revenue_order_totals'] ?? 0));
		$summary->setCellValue('C4', (float) ($headline['baseline']['revenue_order_totals'] ?? 0));
		$summary->setCellValue('D4', '=IF(C4=0,"",ROUND(100*(B4-C4)/C4,0))');
		$summary->setCellValue('A5', 'Revenue (line totals)');
		$summary->setCellValue('B5', (float) ($headline['revenue_line_totals'] ?? 0));
		$summary->setCellValue('A6', 'Avg order value');
		$summary->setCellValue('B6', '=IF(B2=0,0,ROUND(B4/B2,2))');
		$summary->setCellValue('C6', (float) ($headline['baseline']['avg_order_value'] ?? 0));
		$summary->setCellValue('A7', 'Child row count (formula)');
		$summary->setCellValue('B7', '=COUNTA(Children!A2:A' . $last_data_row . ')');
		$summary->setCellValue('A9', 'Revenue basis');
		$summary->setCellValue('B9', 'order totals (default)');

		// Sheet 3: Venues
		$venues = $ss->createSheet();
		$venues->setTitle('Venues');
		$venues->setCellValue('A1', 'Venue');
		$venues->setCellValue('B1', 'Bookings');
		$venues->setCellValue('C1', 'Revenue (line)');
		$vr = 2;
		foreach ((array) ($payload['venues'] ?? []) as $name => $row) {
			$venues->setCellValue('A' . $vr, (string) $name);
			$venues->setCellValue('B' . $vr, (int) ($row['bookings'] ?? 0));
			$venues->setCellValue('C' . $vr, (float) ($row['revenue'] ?? 0));
			$vr++;
		}

		// Sheet 4: Data notes
		$notes = $ss->createSheet();
		$notes->setTitle('Data notes');
		$notes->setCellValue('A1', 'Topic');
		$notes->setCellValue('B1', 'Detail');
		$nr = 2;
		foreach ((array) ($payload['data_notes'] ?? []) as $note) {
			$notes->setCellValueExplicit('A' . $nr, (string) ($note['topic'] ?? ''), DataType::TYPE_STRING);
			$notes->setCellValueExplicit('B' . $nr, (string) ($note['detail'] ?? ''), DataType::TYPE_STRING);
			$nr++;
		}
		$notes->setCellValue('A' . $nr, 'Attribution limitation');
		$notes->setCellValue('B' . $nr, (string) ($payload['attribution_limitation'] ?? ''));

		$ss->setActiveSheetIndex(0);
		$tmp = wp_tempnam('campaign-xlsx');
		$writer = new Xlsx($ss);
		$writer->save($tmp);
		$binary = file_get_contents($tmp);
		@unlink($tmp);
		return $binary === false ? '' : $binary;
	}
}
