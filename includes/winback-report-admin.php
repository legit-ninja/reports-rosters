<?php
/**
 * Hidden admin page + Excel export for win-back report QA.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Parse win-back GET filters (single entry point for the page).
 *
 * @return array{kind:string,venue:string,age_group:string,lookback_months:int}
 */
function intersoccer_winback_report_request_filters() {
	$kind = isset($_GET['kind']) ? sanitize_text_field(wp_unslash($_GET['kind'])) : 'all';
	if (!in_array($kind, ['camp', 'course', 'all'], true)) {
		$kind = 'all';
	}
	$venue = isset($_GET['venue']) ? sanitize_title(wp_unslash($_GET['venue'])) : '';
	$age_group = isset($_GET['age_group']) ? sanitize_title(wp_unslash($_GET['age_group'])) : '';
	$lookback_months = isset($_GET['lookback_months']) ? max(1, absint($_GET['lookback_months'])) : 12;

	return [
		'kind' => $kind,
		'venue' => $venue,
		'age_group' => $age_group,
		'lookback_months' => $lookback_months,
	];
}

/**
 * Run the win-back engine with request filters.
 *
 * @return array{rows:array<int,array<string,mixed>>,unmapped_facets:array<string,array<string,int>>}
 */
function intersoccer_winback_report_run() {
	if (!function_exists('intersoccer_winback_report_engine_generate')) {
		require_once dirname(__FILE__) . '/winback-report-engine.php';
	}

	return intersoccer_winback_report_engine_generate(intersoccer_winback_report_request_filters());
}

/**
 * @param array<int,array<string,mixed>> $rows
 */
function intersoccer_winback_report_stream_excel(array $rows) {
	$autoload = dirname(__DIR__) . '/vendor/autoload.php';
	if (file_exists($autoload)) {
		require_once $autoload;
	}
	if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
		wp_die(esc_html__('Excel export is unavailable (PhpSpreadsheet missing).', 'intersoccer-reports-rosters'));
	}

	while (ob_get_level() > 0) {
		ob_end_clean();
	}

	$headers = [
		'Player',
		'Player key',
		'Match tier',
		'Parent name',
		'Parent ID',
		'Billing email',
		'Billing phone',
		'Account email',
		'Contact differs',
		'Last product',
		'Last venue (slug)',
		'Last age group (slug)',
		'Last date',
		'Date source',
		'Target product',
		'Target venue',
		'Target age group',
		'Target start',
		'Aged up',
		'Sibling booked',
		'Rebooked elsewhere',
		'Full',
		'Status',
		'DOB',
		'Age',
		'Events in lookback',
	];

	$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
	$sheet = $spreadsheet->getActiveSheet();
	$sheet->setTitle('Win-back');
	$sheet->fromArray($headers, null, 'A1');
	$last_col = 'Z';
	$sheet->getStyle('A1:' . $last_col . '1')->getFont()->setBold(true);

	$row_index = 2;
	foreach ($rows as $row) {
		$player = $row['player'] ?? [];
		$parent = $row['parent'] ?? [];
		$contact = $row['contact'] ?? [];
		$last = $row['last_event'] ?? [];
		$target = $row['target'] ?? null;
		$line = [
			(string) ($player['name'] ?? ''),
			(string) ($player['key'] ?? ''),
			(string) ($player['tier'] ?? ''),
			(string) ($parent['name'] ?? ''),
			(int) ($parent['id'] ?? 0),
			(string) ($contact['email'] ?? ''),
			(string) ($contact['phone'] ?? ''),
			(string) ($contact['account_email'] ?? ($row['account_email'] ?? '')),
			!empty($contact['contact_differs']) ? 'Yes' : 'No',
			(string) ($last['product'] ?? ''),
			(string) ($last['venue'] ?? ''),
			(string) ($last['age_group'] ?? ''),
			(string) ($last['date'] ?? ''),
			(string) ($last['date_source'] ?? ''),
			is_array($target) ? (string) ($target['product'] ?? '') : '',
			is_array($target) ? (string) ($target['venue'] ?? '') : '',
			is_array($target) ? (string) ($target['age_group'] ?? '') : '',
			is_array($target) ? (string) ($target['start_date'] ?? '') : '',
			!empty($row['aged_up']) ? 'Yes' : 'No',
			!empty($row['sibling_booked']) ? 'Yes' : 'No',
			!empty($row['rebooked_elsewhere']) ? 'Yes' : 'No',
			!empty($row['full']) ? 'Yes' : 'No',
			(string) ($row['status'] ?? ''),
			function_exists('intersoccer_player_camp_status_format_dob_for_display')
				? intersoccer_player_camp_status_format_dob_for_display((string) ($row['dob'] ?? ''))
				: (string) ($row['dob'] ?? ''),
			$row['age'] ?? '',
			(int) ($row['events_in_lookback'] ?? 0),
		];
		$sheet->fromArray($line, null, 'A' . $row_index);
		$row_index++;
	}

	$filename = 'winback-report.xlsx';
	nocache_headers();
	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Cache-Control: max-age=0');

	$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
	$writer->save('php://output');
	exit;
}

/**
 * Export hook (admin_init priority 1).
 */
function intersoccer_winback_report_maybe_export_excel() {
	if (empty($_GET['export_excel'])) {
		return;
	}
	$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
	if ($page !== 'intersoccer-winback-report') {
		return;
	}
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
	}
	check_admin_referer('intersoccer_winback_report_excel');

	$result = intersoccer_winback_report_run();
	intersoccer_winback_report_stream_excel($result['rows']);
}

/**
 * Hidden QA page render callback.
 */
function intersoccer_render_winback_report_page() {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
	}

	$filters = intersoccer_winback_report_request_filters();
	$result = intersoccer_winback_report_run();
	$rows = $result['rows'];
	$unmapped = $result['unmapped_facets'];

	$export_url = wp_nonce_url(
		add_query_arg(
			array_merge(
				['page' => 'intersoccer-winback-report', 'export_excel' => '1'],
				$filters
			),
			admin_url('admin.php')
		),
		'intersoccer_winback_report_excel'
	);

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__('Win-back report (QA)', 'intersoccer-reports-rosters') . '</h1>';
	echo '<p>' . esc_html__('Hidden backend preview. UI polish is handled separately.', 'intersoccer-reports-rosters') . '</p>';

	echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
	echo '<input type="hidden" name="page" value="intersoccer-winback-report" />';
	echo '<table class="form-table"><tbody>';
	echo '<tr><th scope="row">' . esc_html__('Kind', 'intersoccer-reports-rosters') . '</th><td>';
	echo '<select name="kind">';
	foreach (['all' => 'All', 'camp' => 'Camp', 'course' => 'Course'] as $val => $label) {
		echo '<option value="' . esc_attr($val) . '"' . selected($filters['kind'], $val, false) . '>' . esc_html($label) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th scope="row">' . esc_html__('Venue slug', 'intersoccer-reports-rosters') . '</th><td>';
	echo '<input type="text" name="venue" value="' . esc_attr($filters['venue']) . '" class="regular-text" /></td></tr>';
	echo '<tr><th scope="row">' . esc_html__('Age group slug', 'intersoccer-reports-rosters') . '</th><td>';
	echo '<input type="text" name="age_group" value="' . esc_attr($filters['age_group']) . '" class="regular-text" /></td></tr>';
	echo '<tr><th scope="row">' . esc_html__('Lookback months', 'intersoccer-reports-rosters') . '</th><td>';
	echo '<input type="number" min="1" name="lookback_months" value="' . esc_attr((string) $filters['lookback_months']) . '" /></td></tr>';
	echo '</tbody></table>';
	submit_button(__('Run report', 'intersoccer-reports-rosters'));
	echo '</form>';

	echo '<p><a class="button button-secondary" href="' . esc_url($export_url) . '">' . esc_html__('Download Excel', 'intersoccer-reports-rosters') . '</a></p>';
	echo '<p>' . esc_html(sprintf(/* translators: %d row count */ __('Showing %d rows.', 'intersoccer-reports-rosters'), count($rows))) . '</p>';

	if (!empty($unmapped['venue']) || !empty($unmapped['age_group'])) {
		echo '<h2>' . esc_html__('Unmapped facet values', 'intersoccer-reports-rosters') . '</h2><ul>';
		foreach (['venue', 'age_group'] as $kind) {
			foreach (($unmapped[$kind] ?? []) as $raw => $count) {
				echo '<li><code>' . esc_html($kind) . '</code>: ' . esc_html($raw) . ' (' . (int) $count . ')</li>';
			}
		}
		echo '</ul>';
	}

	echo '<table class="widefat striped"><thead><tr>';
	$cols = ['Player', 'Status', 'Last date', 'Target start', 'Tier', 'Aged up', 'Sibling', 'Elsewhere', 'Full'];
	foreach ($cols as $col) {
		echo '<th>' . esc_html($col) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ($rows as $row) {
		$player = $row['player'] ?? [];
		$last = $row['last_event'] ?? [];
		$target = $row['target'] ?? null;
		echo '<tr>';
		echo '<td>' . esc_html((string) ($player['name'] ?? '')) . '</td>';
		echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
		echo '<td>' . esc_html((string) ($last['date'] ?? '')) . ' <small>(' . esc_html((string) ($last['date_source'] ?? '')) . ')</small></td>';
		echo '<td>' . esc_html(is_array($target) ? (string) ($target['start_date'] ?? '') : '') . '</td>';
		echo '<td>' . esc_html((string) ($player['tier'] ?? '')) . '</td>';
		echo '<td>' . (!empty($row['aged_up']) ? 'Y' : '-') . '</td>';
		echo '<td>' . (!empty($row['sibling_booked']) ? 'Y' : '-') . '</td>';
		echo '<td>' . (!empty($row['rebooked_elsewhere']) ? 'Y' : '-') . '</td>';
		echo '<td>' . (!empty($row['full']) ? 'Y' : '-') . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
}
