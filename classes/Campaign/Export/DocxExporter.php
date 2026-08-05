<?php
/**
 * Executive Word report (PhpWord when available; HTML fallback).
 * House style: SWISS15_Booking_Report_2026.docx
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
		$model = CampaignReportSections::build($payload);
		$phpWord = new \PhpOffice\PhpWord\PhpWord();
		$phpWord->setDefaultFontName('Calibri');
		$phpWord->setDefaultFontSize(10);
		$phpWord->addTitleStyle(1, ['name' => 'Calibri', 'size' => 15, 'bold' => true, 'color' => CampaignReportSections::NAVY]);
		$phpWord->addTitleStyle(2, ['name' => 'Calibri', 'size' => 12, 'bold' => true, 'color' => CampaignReportSections::NAVY]);

		$section = $phpWord->addSection();

		if (!empty($model['stub'])) {
			$section->addText(
				(string) $model['title'],
				['name' => 'Calibri', 'size' => 16, 'bold' => true, 'color' => CampaignReportSections::NAVY],
				['borderBottomSize' => 12, 'borderBottomColor' => CampaignReportSections::NAVY]
			);
			$section->addText((string) $model['subtitle'], ['name' => 'Calibri', 'size' => 11, 'bold' => true]);
			$section->addTextBreak();
			$section->addTitle('Cannot produce — data quality', 1);
			$section->addText('This campaign summary did not pass the data-quality gate. No partial figures are shown.');
			foreach ((array) ($model['reasons'] ?? []) as $reason) {
				$section->addListItem((string) $reason, 0, ['name' => 'Calibri', 'size' => 10]);
			}
			$section->addTextBreak();
			$section->addText((string) ($model['business_age'] ?? CampaignReportSections::BUSINESS_AGE_FOOTNOTE), ['name' => 'Calibri', 'size' => 9, 'color' => CampaignReportSections::GREY]);
			return $this->save_docx($phpWord);
		}

		$section->addText(
			(string) $model['title'],
			['name' => 'Calibri', 'size' => 16, 'bold' => true, 'color' => CampaignReportSections::NAVY],
			['borderBottomSize' => 12, 'borderBottomColor' => CampaignReportSections::NAVY]
		);
		$section->addText((string) $model['subtitle'], ['name' => 'Calibri', 'size' => 11, 'bold' => true]);
		$section->addText((string) $model['provenance'], ['name' => 'Calibri', 'size' => 9.5, 'color' => CampaignReportSections::GREY]);
		$section->addTextBreak();

		if (!empty($model['window_note'])) {
			$section->addTitle('A note on the campaign window', 2);
			$section->addText((string) $model['window_note'], ['name' => 'Calibri', 'size' => 10]);
			$section->addTextBreak();
		}

		$section->addTitle('Executive summary', 1);
		foreach ((array) ($model['exec_summary'] ?? []) as $para) {
			$this->add_styled_para($section, $para);
		}
		$section->addTextBreak();

		$section->addTitle('Headline numbers', 1);
		$this->add_table($section, (array) ($model['headline_rows'] ?? []));
		if (!empty($model['volume_prose'])) {
			$section->addText((string) $model['volume_prose'], ['name' => 'Calibri', 'size' => 10]);
		}
		$section->addTextBreak();

		if (!empty($model['day_table']['rows'])) {
			$section->addTitle((string) $model['timing_title'], 1);
			$this->add_keyed_table($section, (array) $model['day_table']);
			foreach ((array) ($model['timing_prose'] ?? []) as $para) {
				$this->add_styled_para($section, $para);
			}
			$section->addTextBreak();
		}

		if (!empty($model['season_table']['rows'])) {
			$section->addTitle((string) $model['demand_title'], 1);
			$this->add_keyed_table($section, (array) $model['season_table']);
			foreach ((array) ($model['demand_prose'] ?? []) as $para) {
				$this->add_styled_para($section, $para);
			}
			$section->addTextBreak();
		}

		if (!empty($model['mix_table']['rows'])) {
			$section->addTitle('What was booked', 1);
			$this->add_keyed_table($section, (array) $model['mix_table']);
			if (!empty($model['mix_prose'])) {
				$section->addText((string) $model['mix_prose'], ['name' => 'Calibri', 'size' => 10]);
			}
			$section->addTextBreak();
		}

		if (!empty($model['region_table']['rows'])) {
			$section->addTitle('By region', 1);
			$this->add_keyed_table($section, (array) $model['region_table']);
			if (!empty($model['region_warning'])) {
				$section->addText((string) $model['region_warning'], ['name' => 'Calibri', 'size' => 10, 'color' => CampaignReportSections::RED]);
			} elseif (!empty($model['region_prose'])) {
				$section->addText((string) $model['region_prose'], ['name' => 'Calibri', 'size' => 10]);
			}
			$section->addTextBreak();
		}

		if (!empty($model['cohort_table']['rows'])) {
			$section->addTitle('New and returning families', 1);
			$this->add_keyed_table($section, (array) $model['cohort_table']);
			if (!empty($model['cohort_prose'])) {
				$section->addText((string) $model['cohort_prose'], ['name' => 'Calibri', 'size' => 10]);
			}
			$section->addTextBreak();
		}

		$section->addTitle('Codes and sources', 1);
		if (!empty($model['codes_prose'])) {
			$section->addText((string) $model['codes_prose'], ['name' => 'Calibri', 'size' => 10]);
		}
		if (!empty($model['attribution_prose'])) {
			$section->addText((string) $model['attribution_prose'], ['name' => 'Calibri', 'size' => 10]);
		}
		$section->addText(
			(string) ($model['attribution_limitation'] ?? ''),
			['name' => 'Calibri', 'size' => 10]
		);
		$section->addTextBreak();

		$section->addTitle('Recommendations for the next campaign', 1);
		foreach ((array) ($model['recommendations'] ?? []) as $rec) {
			$lead = (string) ($rec['lead'] ?? '');
			$rest = (string) ($rec['rest'] ?? '');
			$textrun = $section->addListItemRun(0);
			$textrun->addText($lead, ['name' => 'Calibri', 'size' => 10, 'bold' => true]);
			if ($rest !== '') {
				$textrun->addText($rest, ['name' => 'Calibri', 'size' => 10]);
			}
		}
		$section->addTextBreak();

		$section->addTitle('What this report cannot yet tell you', 1);
		foreach ((array) ($model['gaps'] ?? []) as $gap) {
			$section->addListItem((string) $gap, 0, ['name' => 'Calibri', 'size' => 10]);
		}
		$section->addTextBreak();

		$section->addText('', [], ['borderTopSize' => 6, 'borderTopColor' => CampaignReportSections::GREY]);
		foreach ((array) ($model['footnote'] ?? []) as $line) {
			$section->addText((string) $line, ['name' => 'Calibri', 'size' => 9, 'color' => CampaignReportSections::GREY]);
		}

		return $this->save_docx($phpWord);
	}

	/**
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 * @param array{text?:string,style?:string}  $para
	 * @return void
	 */
	private function add_styled_para($section, array $para) {
		$text = (string) ($para['text'] ?? '');
		if ($text === '') {
			return;
		}
		$style = (string) ($para['style'] ?? 'body');
		if ($style === 'key') {
			$section->addText($text, ['name' => 'Calibri', 'size' => 10, 'bold' => true]);
		} elseif ($style === 'caveat') {
			$section->addText($text, ['name' => 'Calibri', 'size' => 10, 'color' => CampaignReportSections::RED]);
		} elseif ($style === 'aside') {
			$section->addText($text, ['name' => 'Calibri', 'size' => 9, 'color' => CampaignReportSections::GREY]);
		} else {
			$section->addText($text, ['name' => 'Calibri', 'size' => 10]);
		}
	}

	/**
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 * @param array<int,string[]>                $rows first row = headers
	 * @return void
	 */
	private function add_table($section, array $rows) {
		if (!$rows) {
			return;
		}
		$table = $section->addTable(['borderSize' => 4, 'borderColor' => 'CCCCCC', 'cellMargin' => 60]);
		foreach ($rows as $i => $row) {
			$table->addRow();
			foreach ($row as $cell) {
				$cellEl = $table->addCell(2200, $i === 0 ? ['bgColor' => CampaignReportSections::NAVY] : []);
				$font = $i === 0
					? ['name' => 'Calibri', 'size' => 10, 'bold' => true, 'color' => 'FFFFFF']
					: ['name' => 'Calibri', 'size' => 10];
				$cellEl->addText((string) $cell, $font);
			}
		}
	}

	/**
	 * @param \PhpOffice\PhpWord\Element\Section           $section
	 * @param array{headers?:string[],rows?:array<int,string[]>} $table
	 * @return void
	 */
	private function add_keyed_table($section, array $table) {
		$headers = (array) ($table['headers'] ?? []);
		$rows = (array) ($table['rows'] ?? []);
		if (!$headers || !$rows) {
			return;
		}
		$all = array_merge([$headers], $rows);
		$this->add_table($section, $all);
	}

	/**
	 * @param \PhpOffice\PhpWord\PhpWord $phpWord
	 * @return array{mime:string,ext:string,body:string}
	 */
	private function save_docx($phpWord) {
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
		$model = CampaignReportSections::build($payload);
		$navy = CampaignReportSections::NAVY;
		$grey = CampaignReportSections::GREY;
		$red = CampaignReportSections::RED;

		$html = '<html><head><meta charset="utf-8"><title>' . esc_html((string) ($model['title'] ?? 'Campaign')) . '</title>';
		$html .= '<style>
body{font-family:Calibri,Arial,sans-serif;font-size:10pt;color:#000;max-width:800px;margin:24px}
h1{color:#' . $navy . ';font-size:15pt;margin:1.2em 0 .4em}
h2{color:#' . $navy . ';font-size:12pt;margin:1em 0 .35em}
.title{color:#' . $navy . ';font-size:16pt;font-weight:bold;border-bottom:1.5pt solid #' . $navy . ';padding-bottom:4px;margin-bottom:6px}
.subtitle{font-size:11pt;font-weight:bold;margin:4px 0}
.provenance{color:#' . $grey . ';font-size:9.5pt;margin-bottom:16px}
.key{font-weight:bold}
.caveat{color:#' . $red . '}
.aside{color:#' . $grey . ';font-size:9pt}
table{border-collapse:collapse;margin:8px 0 12px;width:100%}
th{background:#' . $navy . ';color:#fff;text-align:left;padding:4px 6px;font-size:10pt}
td{border:1px solid #ccc;padding:4px 6px;font-size:10pt}
.foot{color:#' . $grey . ';font-size:9pt;border-top:1px solid #' . $grey . ';margin-top:18px;padding-top:8px}
ul{margin:6px 0 12px 1.2em}
li strong{font-weight:bold}
</style></head><body>';

		if (!empty($model['stub'])) {
			$html .= '<div class="title">' . esc_html((string) $model['title']) . '</div>';
			$html .= '<div class="subtitle">' . esc_html((string) $model['subtitle']) . '</div>';
			$html .= '<h1>Cannot produce — data quality</h1>';
			$html .= '<p>This campaign summary did not pass the data-quality gate. No partial figures are shown.</p><ul>';
			foreach ((array) ($model['reasons'] ?? []) as $reason) {
				$html .= '<li>' . esc_html((string) $reason) . '</li>';
			}
			$html .= '</ul><p class="aside">' . esc_html((string) ($model['business_age'] ?? CampaignReportSections::BUSINESS_AGE_FOOTNOTE)) . '</p>';
			$html .= '</body></html>';
			return ['mime' => 'application/msword', 'ext' => 'doc', 'body' => $html];
		}

		$html .= '<div class="title">' . esc_html((string) $model['title']) . '</div>';
		$html .= '<div class="subtitle">' . esc_html((string) $model['subtitle']) . '</div>';
		$html .= '<div class="provenance">' . esc_html((string) $model['provenance']) . '</div>';

		if (!empty($model['window_note'])) {
			$html .= '<h2>A note on the campaign window</h2><p>' . esc_html((string) $model['window_note']) . '</p>';
		}

		$html .= '<h1>Executive summary</h1>';
		foreach ((array) ($model['exec_summary'] ?? []) as $para) {
			$html .= $this->html_para($para);
		}

		$html .= '<h1>Headline numbers</h1>';
		$html .= $this->html_table_from_rows((array) ($model['headline_rows'] ?? []));
		if (!empty($model['volume_prose'])) {
			$html .= '<p>' . esc_html((string) $model['volume_prose']) . '</p>';
		}

		if (!empty($model['day_table']['rows'])) {
			$html .= '<h1>' . esc_html((string) $model['timing_title']) . '</h1>';
			$html .= $this->html_keyed_table((array) $model['day_table']);
			foreach ((array) ($model['timing_prose'] ?? []) as $para) {
				$html .= $this->html_para($para);
			}
		}

		if (!empty($model['season_table']['rows'])) {
			$html .= '<h1>' . esc_html((string) $model['demand_title']) . '</h1>';
			$html .= $this->html_keyed_table((array) $model['season_table']);
			foreach ((array) ($model['demand_prose'] ?? []) as $para) {
				$html .= $this->html_para($para);
			}
		}

		if (!empty($model['mix_table']['rows'])) {
			$html .= '<h1>What was booked</h1>';
			$html .= $this->html_keyed_table((array) $model['mix_table']);
			if (!empty($model['mix_prose'])) {
				$html .= '<p>' . esc_html((string) $model['mix_prose']) . '</p>';
			}
		}

		if (!empty($model['region_table']['rows'])) {
			$html .= '<h1>By region</h1>';
			$html .= $this->html_keyed_table((array) $model['region_table']);
			if (!empty($model['region_warning'])) {
				$html .= '<p class="caveat">' . esc_html((string) $model['region_warning']) . '</p>';
			} elseif (!empty($model['region_prose'])) {
				$html .= '<p>' . esc_html((string) $model['region_prose']) . '</p>';
			}
		}

		if (!empty($model['cohort_table']['rows'])) {
			$html .= '<h1>New and returning families</h1>';
			$html .= $this->html_keyed_table((array) $model['cohort_table']);
			if (!empty($model['cohort_prose'])) {
				$html .= '<p>' . esc_html((string) $model['cohort_prose']) . '</p>';
			}
		}

		$html .= '<h1>Codes and sources</h1>';
		if (!empty($model['codes_prose'])) {
			$html .= '<p>' . esc_html((string) $model['codes_prose']) . '</p>';
		}
		if (!empty($model['attribution_prose'])) {
			$html .= '<p>' . esc_html((string) $model['attribution_prose']) . '</p>';
		}
		$html .= '<p>' . esc_html((string) ($model['attribution_limitation'] ?? '')) . '</p>';

		$html .= '<h1>Recommendations for the next campaign</h1><ul>';
		foreach ((array) ($model['recommendations'] ?? []) as $rec) {
			$html .= '<li><strong>' . esc_html((string) ($rec['lead'] ?? '')) . '</strong>'
				. esc_html((string) ($rec['rest'] ?? '')) . '</li>';
		}
		$html .= '</ul>';

		$html .= '<h1>What this report cannot yet tell you</h1><ul>';
		foreach ((array) ($model['gaps'] ?? []) as $gap) {
			$html .= '<li>' . esc_html((string) $gap) . '</li>';
		}
		$html .= '</ul>';

		$html .= '<div class="foot">';
		foreach ((array) ($model['footnote'] ?? []) as $line) {
			$html .= '<p>' . esc_html((string) $line) . '</p>';
		}
		$html .= '</div></body></html>';

		return [
			'mime' => 'application/msword',
			'ext' => 'doc',
			'body' => $html,
		];
	}

	/**
	 * @param array{text?:string,style?:string} $para
	 * @return string
	 */
	private function html_para(array $para) {
		$text = esc_html((string) ($para['text'] ?? ''));
		$style = (string) ($para['style'] ?? 'body');
		$class = in_array($style, ['key', 'caveat', 'aside'], true) ? $style : '';
		return '<p' . ($class !== '' ? ' class="' . $class . '"' : '') . '>' . $text . '</p>';
	}

	/**
	 * @param array<int,string[]> $rows
	 * @return string
	 */
	private function html_table_from_rows(array $rows) {
		if (!$rows) {
			return '';
		}
		$html = '<table>';
		foreach ($rows as $i => $row) {
			$html .= '<tr>';
			$tag = $i === 0 ? 'th' : 'td';
			foreach ($row as $cell) {
				$html .= '<' . $tag . '>' . esc_html((string) $cell) . '</' . $tag . '>';
			}
			$html .= '</tr>';
		}
		return $html . '</table>';
	}

	/**
	 * @param array{headers?:string[],rows?:array<int,string[]>} $table
	 * @return string
	 */
	private function html_keyed_table(array $table) {
		$headers = (array) ($table['headers'] ?? []);
		$rows = (array) ($table['rows'] ?? []);
		return $this->html_table_from_rows(array_merge([$headers], $rows));
	}
}
