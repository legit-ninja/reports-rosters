<?php
/**
 * Executive Word report (PhpWord when available; HTML fallback).
 *
 * @package InterSoccer\ReportsRosters\Campaign\Export
 */

namespace InterSoccer\ReportsRosters\Campaign\Export;

defined('ABSPATH') or die('Restricted access');

class DocxExporter {

	/**
	 * @param array<string,mixed> $payload
	 * @return array{mime:string,ext:string,body:string}
	 */
	public function build(array $payload) {
		if (class_exists('\PhpOffice\PhpWord\PhpWord')) {
			return $this->build_docx($payload);
		}
		return $this->build_html_doc($payload);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{mime:string,ext:string,body:string}
	 */
	private function build_docx(array $payload) {
		$phpWord = new \PhpOffice\PhpWord\PhpWord();
		$section = $phpWord->addSection();
		$name = (string) ($payload['campaign']['name'] ?? 'Campaign');
		$section->addTitle($name . ' — Campaign report', 1);
		$section->addText('Business summary. Technical detail is omitted.');
		$section->addTextBreak();

		$h = (array) ($payload['headline'] ?? []);
		$section->addTitle('Headline comparison', 2);
		$table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999']);
		$rows = [
			['Metric', 'Campaign', 'Baseline', 'Change'],
			['Orders', $h['orders'] ?? '', $h['baseline']['orders'] ?? '', ($h['pct_change']['orders'] ?? '') . '%'],
			['Line-item bookings', $h['line_item_bookings'] ?? '', $h['baseline']['line_item_bookings'] ?? '', ($h['pct_change']['line_item_bookings'] ?? '') . '%'],
			['Revenue (order totals)', $h['revenue_order_totals'] ?? '', $h['baseline']['revenue_order_totals'] ?? '', ($h['pct_change']['revenue_order_totals'] ?? '') . '%'],
			['Avg order value', $h['avg_order_value'] ?? '', $h['baseline']['avg_order_value'] ?? '', ($h['pct_change']['avg_order_value'] ?? '') . '%'],
		];
		foreach ($rows as $row) {
			$table->addRow();
			foreach ($row as $cell) {
				$table->addCell(2000)->addText((string) $cell);
			}
		}

		$section->addTextBreak();
		$section->addTitle('Attribution note', 2);
		$section->addText((string) ($payload['attribution_limitation'] ?? ''));

		$tmp = wp_tempnam('campaign-docx');
		$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
		$writer->save($tmp);
		$body = file_get_contents($tmp);
		@unlink($tmp);
		return [
			'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'ext' => 'docx',
			'body' => $body === false ? '' : $body,
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{mime:string,ext:string,body:string}
	 */
	private function build_html_doc(array $payload) {
		$h = (array) ($payload['headline'] ?? []);
		$name = htmlspecialchars((string) ($payload['campaign']['name'] ?? 'Campaign'), ENT_QUOTES, 'UTF-8');
		$html = '<html><head><meta charset="utf-8"><title>' . $name . '</title></head><body>';
		$html .= '<h1>' . $name . ' — Campaign report</h1>';
		$html .= '<p>Business summary. Technical detail is omitted.</p>';
		$html .= '<h2>Headline comparison</h2><table border="1" cellpadding="4"><tr><th>Metric</th><th>Campaign</th><th>Baseline</th><th>Change</th></tr>';
		$html .= '<tr><td>Orders</td><td>' . (int) ($h['orders'] ?? 0) . '</td><td>' . (int) ($h['baseline']['orders'] ?? 0) . '</td><td>' . esc_html((string) ($h['pct_change']['orders'] ?? '')) . '%</td></tr>';
		$html .= '<tr><td>Line-item bookings</td><td>' . (int) ($h['line_item_bookings'] ?? 0) . '</td><td>' . (int) ($h['baseline']['line_item_bookings'] ?? 0) . '</td><td>' . esc_html((string) ($h['pct_change']['line_item_bookings'] ?? '')) . '%</td></tr>';
		$html .= '<tr><td>Revenue (order totals)</td><td>' . esc_html((string) ($h['revenue_order_totals'] ?? '')) . '</td><td>' . esc_html((string) ($h['baseline']['revenue_order_totals'] ?? '')) . '</td><td>' . esc_html((string) ($h['pct_change']['revenue_order_totals'] ?? '')) . '%</td></tr>';
		$html .= '</table>';
		$html .= '<h2>Attribution note</h2><p>' . esc_html((string) ($payload['attribution_limitation'] ?? '')) . '</p>';
		$html .= '</body></html>';
		return [
			'mime' => 'application/msword',
			'ext' => 'doc',
			'body' => $html,
		];
	}
}
