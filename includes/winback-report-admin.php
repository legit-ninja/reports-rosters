<?php
/**
 * Admin page + Excel export for the win-back report.
 *
 * The engine applies kind, venue, age group, and lookback. This file only
 * renders that result: status tabs, pagination, and the Excel link.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_winback_report_load_dob_gate')) {
	/**
	 * Ensure player camp status DOB gate helpers are loaded (export + page parity).
	 */
	function intersoccer_winback_report_load_dob_gate() {
		if (function_exists('intersoccer_player_camp_status_user_can_view_dob')) {
			return;
		}
		$path = dirname(__FILE__) . '/player-camp-status.php';
		if (file_exists($path)) {
			require_once $path;
		}
	}
}

if (!function_exists('intersoccer_winback_report_format_dob_for_export')) {
	/**
	 * @param string $dob Raw DOB (Y-m-d).
	 */
	function intersoccer_winback_report_format_dob_for_export($dob) {
		intersoccer_winback_report_load_dob_gate();
		if (function_exists('intersoccer_player_camp_status_format_dob_for_display')) {
			return intersoccer_player_camp_status_format_dob_for_display((string) $dob);
		}

		return function_exists('intersoccer_rr_medical_restricted_label')
			? intersoccer_rr_medical_restricted_label()
			: 'Restricted';
	}
}

/**
 * Scalar GET value, or null when missing or not a string.
 *
 * @param string $key Query key.
 * @return string|null
 */
function intersoccer_winback_report_get_scalar($key) {
	if (!isset($_GET[$key]) || is_array($_GET[$key])) {
		return null;
	}

	return (string) wp_unslash($_GET[$key]);
}

/**
 * Slug for a filter value. Uses WordPress sanitize_title when it is loaded.
 *
 * @param string $value
 * @return string
 */
function intersoccer_winback_report_sanitize_slug($value) {
	$value = (string) $value;
	if (function_exists('sanitize_title')) {
		return sanitize_title($value);
	}
	$value = strtolower(trim(strip_tags($value)));
	$value = preg_replace('/[^a-z0-9_-]+/', '-', $value);

	return trim((string) $value, '-');
}

/**
 * Parse win-back GET filters (single entry point for the page).
 *
 * @return array{kind:string,venue:string,age_group:string,lookback_months:int}
 */
function intersoccer_winback_report_request_filters() {
	$kind_raw = intersoccer_winback_report_get_scalar('kind');
	$kind = $kind_raw === null ? 'all' : sanitize_text_field($kind_raw);
	if (!in_array($kind, ['camp', 'course', 'all'], true)) {
		$kind = 'all';
	}
	$venue_raw = intersoccer_winback_report_get_scalar('venue');
	$venue = $venue_raw === null ? '' : intersoccer_winback_report_sanitize_slug($venue_raw);
	$age_raw = intersoccer_winback_report_get_scalar('age_group');
	$age_group = $age_raw === null ? '' : intersoccer_winback_report_sanitize_slug($age_raw);
	$lookback_raw = intersoccer_winback_report_get_scalar('lookback_months');
	$lookback_months = $lookback_raw === null ? 12 : max(1, absint($lookback_raw));

	return [
		'kind' => $kind,
		'venue' => $venue,
		'age_group' => $age_group,
		'lookback_months' => $lookback_months,
	];
}

/**
 * @param string $tab Raw tab slug.
 * @return string actionable|no_upcoming|date_unknown
 */
function intersoccer_winback_report_normalize_tab($tab) {
	$tab = (string) $tab;
	if (!in_array($tab, ['actionable', 'no_upcoming', 'date_unknown'], true)) {
		return 'actionable';
	}

	return $tab;
}

/**
 * Status tab from the request. Missing or invalid values use Actionable.
 *
 * @return string
 */
function intersoccer_winback_report_request_tab() {
	$raw = intersoccer_winback_report_get_scalar('tab');
	if ($raw === null || $raw === '') {
		return 'actionable';
	}

	return intersoccer_winback_report_normalize_tab(sanitize_text_field($raw));
}

/**
 * Page number for the table. Not an engine filter.
 *
 * @return int
 */
function intersoccer_winback_report_request_paged() {
	$raw = intersoccer_winback_report_get_scalar('paged');
	if ($raw === null || $raw === '') {
		return 1;
	}

	return max(1, absint($raw));
}

/**
 * Tab labels in display order.
 *
 * @return array<string,string>
 */
function intersoccer_winback_report_tab_labels() {
	return [
		'actionable' => __('Actionable', 'intersoccer-reports-rosters'),
		'no_upcoming' => __('No upcoming event', 'intersoccer-reports-rosters'),
		'date_unknown' => __('Date unknown', 'intersoccer-reports-rosters'),
	];
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,int>
 */
function intersoccer_winback_report_tab_counts(array $rows) {
	$counts = [
		'actionable' => 0,
		'no_upcoming' => 0,
		'date_unknown' => 0,
	];
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$status = (string) ($row['status'] ?? '');
		if (isset($counts[$status])) {
			$counts[$status]++;
		}
	}

	return $counts;
}

/**
 * Rows for one status tab. Does not apply venue, age, kind, or lookback.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param string                         $tab
 * @return array<int,array<string,mixed>>
 */
function intersoccer_winback_report_rows_for_tab(array $rows, $tab) {
	$tab = intersoccer_winback_report_normalize_tab($tab);
	$matched = [];
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		if ((string) ($row['status'] ?? '') === $tab) {
			$matched[] = $row;
		}
	}

	return $matched;
}

/**
 * Excel keeps every status unless the request names a tab.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function intersoccer_winback_report_export_rows(array $rows) {
	$raw = intersoccer_winback_report_get_scalar('tab');
	if ($raw === null || $raw === '') {
		return $rows;
	}

	return intersoccer_winback_report_rows_for_tab($rows, intersoccer_winback_report_normalize_tab(sanitize_text_field($raw)));
}

/**
 * @param array<int,mixed> $rows
 * @param int              $paged
 * @param int              $per_page
 * @return array{rows:array<int,mixed>,paged:int,total_pages:int,total:int}
 */
function intersoccer_winback_report_page_slice(array $rows, $paged, $per_page = 50) {
	$per_page = max(1, (int) $per_page);
	$total = count($rows);
	$total_pages = max(1, (int) ceil($total / $per_page));
	$paged = min(max(1, (int) $paged), $total_pages);
	$offset = ($paged - 1) * $per_page;

	return [
		'rows' => array_slice(array_values($rows), $offset, $per_page),
		'paged' => $paged,
		'total_pages' => $total_pages,
		'total' => $total,
	];
}

/**
 * Query args shared by the filter form, tabs, pagination, and Excel link.
 *
 * @param array<string,mixed> $filters
 * @param string              $tab
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function intersoccer_winback_report_query_args(array $filters, $tab, array $extra = []) {
	$args = [
		'page' => 'intersoccer-winback-report',
		'kind' => (string) ($filters['kind'] ?? 'all'),
		'venue' => (string) ($filters['venue'] ?? ''),
		'age_group' => (string) ($filters['age_group'] ?? ''),
		'lookback_months' => (int) ($filters['lookback_months'] ?? 12),
		'tab' => intersoccer_winback_report_normalize_tab($tab),
	];
	foreach ($extra as $key => $value) {
		$args[$key] = $value;
	}

	return $args;
}

/**
 * Excel link for every status. Same filters as the tab export, and no tab arg.
 *
 * @param array<string,mixed> $filters
 * @return array<string,mixed>
 */
function intersoccer_winback_report_export_all_query_args(array $filters) {
	$args = intersoccer_winback_report_query_args($filters, 'actionable', ['export_excel' => '1']);
	unset($args['tab']);

	return $args;
}

/**
 * Yes, or blank. Text, so the mark is readable without colour.
 *
 * @param mixed $flag
 * @return string
 */
function intersoccer_winback_report_flag_mark($flag) {
	if (empty($flag)) {
		return '';
	}

	return __('Yes', 'intersoccer-reports-rosters');
}

/**
 * @param array<string,mixed> $row
 * @return string
 */
function intersoccer_winback_report_age_text(array $row) {
	if (!array_key_exists('age', $row) || $row['age'] === null || $row['age'] === '') {
		return '—';
	}

	return (string) $row['age'];
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return bool
 */
function intersoccer_winback_report_has_events_column(array $rows) {
	foreach ($rows as $row) {
		if (is_array($row) && array_key_exists('events_in_lookback', $row)) {
			return true;
		}
	}

	return false;
}

/**
 * @param array<string,mixed> $row
 * @return string
 */
function intersoccer_winback_report_events_text(array $row) {
	if (!array_key_exists('events_in_lookback', $row)) {
		return '—';
	}

	return (string) (int) $row['events_in_lookback'];
}

/**
 * First term for a slug wins, so a shared FR/DE slug cannot replace the EN label.
 *
 * @param mixed $terms
 * @return array<string,string> slug => label
 */
function intersoccer_winback_report_term_options_from_terms($terms) {
	if (is_wp_error($terms) || !is_array($terms)) {
		return [];
	}
	$options = [];
	foreach ($terms as $term) {
		$slug = '';
		$name = '';
		if (is_object($term)) {
			$slug = (string) ($term->slug ?? '');
			$name = (string) ($term->name ?? '');
		} elseif (is_array($term)) {
			$slug = (string) ($term['slug'] ?? '');
			$name = (string) ($term['name'] ?? '');
		}
		if ($slug === '' || isset($options[$slug])) {
			continue;
		}
		$options[$slug] = $name !== '' ? $name : $slug;
	}

	return $options;
}

/**
 * Terms for a taxonomy while WPML is on the default language, when WPML is present.
 *
 * @param string $taxonomy
 * @return mixed
 */
function intersoccer_winback_report_load_taxonomy_terms($taxonomy) {
	$taxonomy = (string) $taxonomy;
	$load = static function () use ($taxonomy) {
		if (!function_exists('get_terms') || $taxonomy === '') {
			return [];
		}

		return get_terms([
			'taxonomy' => $taxonomy,
			'hide_empty' => false,
			'orderby' => 'name',
			'order' => 'ASC',
		]);
	};

	if (function_exists('intersoccer_with_wpml_default_language')) {
		return intersoccer_with_wpml_default_language($load);
	}

	$current = function_exists('wpml_get_current_language') ? (string) wpml_get_current_language() : '';
	$default = function_exists('wpml_get_default_language') ? (string) wpml_get_default_language() : '';
	$switched = $default !== '' && $current !== $default;
	if ($switched) {
		do_action('wpml_switch_language', $default);
	}
	try {
		return $load();
	} finally {
		if ($switched) {
			do_action('wpml_switch_language', $current);
		}
	}
}

/**
 * EN slug => human label. The current selection is kept even if it is not a term.
 *
 * @param string $taxonomy
 * @param string $selected
 * @return array<string,string>
 */
function intersoccer_winback_report_facet_options($taxonomy, $selected = '') {
	$options = intersoccer_winback_report_term_options_from_terms(
		intersoccer_winback_report_load_taxonomy_terms($taxonomy)
	);
	$selected = intersoccer_winback_report_sanitize_slug($selected);
	if ($selected !== '' && !isset($options[$selected])) {
		$options[$selected] = $selected;
	}
	if ($options) {
		asort($options, SORT_NATURAL | SORT_FLAG_CASE);
	}

	return $options;
}

/**
 * Escaped HTML for a last-event or target-event cell.
 *
 * @param mixed                $event
 * @param array<string,string> $venue_labels
 * @param array<string,string> $age_labels
 * @param bool                 $with_date_source
 * @return string
 */
function intersoccer_winback_report_format_event_html($event, array $venue_labels, array $age_labels, $with_date_source) {
	if (!is_array($event)) {
		return '&mdash;';
	}
	$lines = [];
	$product = trim((string) ($event['product'] ?? ''));
	if ($product !== '') {
		$lines[] = esc_html($product);
	}
	$venue = trim((string) ($event['venue'] ?? ''));
	if ($venue !== '') {
		$lines[] = esc_html($venue_labels[$venue] ?? $venue);
	}
	$age = trim((string) ($event['age_group'] ?? ''));
	if ($age !== '') {
		$lines[] = esc_html($age_labels[$age] ?? $age);
	}
	$date = $event['date'] ?? ($event['start_date'] ?? '');
	if ($date !== null && $date !== '') {
		$lines[] = esc_html((string) $date);
	}
	$html = $lines === [] ? '&mdash;' : implode('<br />', $lines);
	if ($with_date_source) {
		$source = trim((string) ($event['date_source'] ?? ''));
		if ($source !== '') {
			$html .= '<br /><span class="description" style="font-size:12px;">' . esc_html($source) . '</span>';
		}
	}

	return $html;
}

/**
 * Small escaped list of later catalogue dates for the Target event cell.
 *
 * One line per item: start date, product, venue label, age-group label.
 * Empty when more_targets is missing, not a list, or has no displayable rows.
 *
 * @param mixed                $more_targets
 * @param array<string,string> $venue_labels
 * @param array<string,string> $age_labels
 * @return string
 */
function intersoccer_winback_report_format_more_targets_html($more_targets, array $venue_labels, array $age_labels) {
	if (!is_array($more_targets) || $more_targets === []) {
		return '';
	}

	$lines = [];
	foreach ($more_targets as $item) {
		if (!is_array($item)) {
			continue;
		}
		$parts = [];
		$date = trim((string) ($item['start_date'] ?? ''));
		if ($date !== '') {
			$parts[] = esc_html($date);
		}
		$product = trim((string) ($item['product'] ?? ''));
		if ($product !== '') {
			$parts[] = esc_html($product);
		}
		$venue = trim((string) ($item['venue'] ?? ''));
		if ($venue !== '') {
			$parts[] = esc_html($venue_labels[$venue] ?? $venue);
		}
		$age = trim((string) ($item['age_group'] ?? ''));
		if ($age !== '') {
			$parts[] = esc_html($age_labels[$age] ?? $age);
		}
		if ($parts === []) {
			continue;
		}
		$lines[] = implode(', ', $parts);
	}
	if ($lines === []) {
		return '';
	}

	$html = '<br /><span class="description" style="font-size:12px;">' . esc_html__('More dates', 'intersoccer-reports-rosters') . '</span>';
	foreach ($lines as $line) {
		$html .= '<br /><span class="description" style="font-size:12px;">' . $line . '</span>';
	}

	return $html;
}

/**
 * @param array<string,mixed> $unmapped
 * @return array<int,array{facet:string,value:string,count:int}>
 */
function intersoccer_winback_report_unmapped_notice_items(array $unmapped) {
	$items = [];
	$facets = [
		'venue' => __('Venue', 'intersoccer-reports-rosters'),
		'age_group' => __('Age group', 'intersoccer-reports-rosters'),
	];
	foreach ($facets as $key => $label) {
		$bucket = $unmapped[$key] ?? [];
		if (!is_array($bucket)) {
			continue;
		}
		foreach ($bucket as $raw => $count) {
			$text = trim((string) $raw);
			if ($text === '') {
				continue;
			}
			$items[] = [
				'facet' => $label,
				'value' => $text,
				'count' => (int) $count,
			];
		}
	}

	return $items;
}

/**
 * Human label for a post status. Unknown values stay as stored and are escaped later.
 *
 * @param mixed $status
 * @return string
 */
function intersoccer_winback_report_admin_status_label($status) {
	$raw = trim((string) $status);
	$key = strtolower($raw);
	if ($key === 'draft') {
		return __('Draft', 'intersoccer-reports-rosters');
	}
	if ($key === 'pending') {
		return __('Pending', 'intersoccer-reports-rosters');
	}
	if ($key === 'private') {
		return __('Private', 'intersoccer-reports-rosters');
	}

	return $raw;
}

/**
 * EN venue name for a slug. Cached for this request. Falls back to the slug.
 *
 * @param string $slug
 * @return string
 */
function intersoccer_winback_report_admin_venue_name($slug) {
	static $cache = [];
	$slug = trim((string) $slug);
	if ($slug === '') {
		return '';
	}
	if (array_key_exists($slug, $cache)) {
		return $cache[$slug];
	}

	$label = $slug;
	if (function_exists('get_term_by')) {
		$lookup = static function () use ($slug) {
			return get_term_by('slug', $slug, 'pa_intersoccer-venues');
		};
		if (function_exists('intersoccer_with_wpml_default_language')) {
			$term = intersoccer_with_wpml_default_language($lookup);
		} else {
			$term = $lookup();
		}
		if ($term && !is_wp_error($term)) {
			$name = is_object($term) ? trim((string) ($term->name ?? '')) : '';
			if ($name !== '') {
				$label = $name;
			}
		}
	}
	$cache[$slug] = $label;

	return $label;
}

/**
 * Link text to the variation's parent edit screen when the current user may edit it.
 *
 * @param int    $variation_id
 * @param string $text
 * @return string
 */
function intersoccer_winback_report_admin_parent_edit_link($variation_id, $text) {
	$safe = esc_html($text);
	$variation_id = (int) $variation_id;
	if ($variation_id <= 0 || !function_exists('wp_get_post_parent_id') || !function_exists('get_edit_post_link')) {
		return $safe;
	}
	$parent_id = (int) wp_get_post_parent_id($variation_id);
	if ($parent_id <= 0) {
		return $safe;
	}
	$url = get_edit_post_link($parent_id);
	if (!is_string($url) || $url === '') {
		return $safe;
	}

	return '<a href="' . esc_url($url) . '">' . $safe . '</a>';
}

/**
 * Read-only table of EN variations that are off in English and on sale in FR/DE.
 *
 * Empty when the list is missing, not an array, or has no rows. Not used by Excel.
 *
 * @param mixed $items Engine `en_unpublished_variations`.
 * @return string
 */
function intersoccer_winback_report_admin_en_unpublished_html($items) {
	if (!is_array($items) || $items === []) {
		return '';
	}

	$rows = [];
	foreach ($items as $item) {
		if (!is_array($item)) {
			continue;
		}
		$en_id = (int) ($item['en_variation_id'] ?? 0);
		$reason_raw = trim((string) ($item['reason'] ?? ''));
		if ($reason_raw === 'parent') {
			$reason = __('English course is a draft', 'intersoccer-reports-rosters');
			$human = intersoccer_winback_report_admin_status_label($item['en_parent_status'] ?? '');
			$status = $human === '' ? '' : $human . ' (' . __('English course', 'intersoccer-reports-rosters') . ')';
		} elseif ($reason_raw === 'variation') {
			$reason = __('English date switched off', 'intersoccer-reports-rosters');
			$status = intersoccer_winback_report_admin_status_label($item['en_variation_status'] ?? '');
		} else {
			$reason = $reason_raw;
			$status = intersoccer_winback_report_admin_status_label($item['en_variation_status'] ?? '');
		}
		$id_html = [];
		$raw_ids = $item['translation_variation_ids'] ?? [];
		if (is_array($raw_ids)) {
			foreach ($raw_ids as $id) {
				$n = (int) $id;
				$id_html[] = intersoccer_winback_report_admin_parent_edit_link($n, (string) $n);
			}
		}
		$rows[] = [
			'date' => (string) ($item['start_date'] ?? ''),
			'product_html' => intersoccer_winback_report_admin_parent_edit_link($en_id, (string) ($item['product'] ?? '')),
			'venue' => intersoccer_winback_report_admin_venue_name((string) ($item['venue'] ?? '')),
			'kind' => (string) ($item['kind'] ?? ''),
			'reason' => $reason,
			'parent_status' => trim((string) ($item['en_parent_status'] ?? '')),
			'status' => $status,
			'ids_html' => implode(', ', $id_html),
		];
	}
	if ($rows === []) {
		return '';
	}

	$headings = [
		__('Date', 'intersoccer-reports-rosters'),
		__('Product', 'intersoccer-reports-rosters'),
		__('Venue', 'intersoccer-reports-rosters'),
		__('Kind', 'intersoccer-reports-rosters'),
		__('Reason', 'intersoccer-reports-rosters'),
		__('EN parent status', 'intersoccer-reports-rosters'),
		__('EN status', 'intersoccer-reports-rosters'),
		__('FR/DE variation IDs', 'intersoccer-reports-rosters'),
	];
	$html = '<h2>' . esc_html__('Dates switched off in English but on sale in FR/DE', 'intersoccer-reports-rosters') . '</h2>';
	$html .= '<table class="widefat striped"><thead><tr>';
	foreach ($headings as $heading) {
		$html .= '<th>' . esc_html($heading) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ($rows as $row) {
		$html .= '<tr>';
		$html .= '<td>' . esc_html($row['date']) . '</td>';
		$html .= '<td>' . $row['product_html'] . '</td>';
		$html .= '<td>' . esc_html($row['venue']) . '</td>';
		$html .= '<td>' . esc_html($row['kind']) . '</td>';
		$html .= '<td>' . esc_html($row['reason']) . '</td>';
		$html .= '<td>' . esc_html($row['parent_status']) . '</td>';
		$html .= '<td>' . esc_html($row['status']) . '</td>';
		$html .= '<td>' . $row['ids_html'] . '</td>';
		$html .= '</tr>';
	}
	$html .= '</tbody></table>';

	return $html;
}

/**
 * Run the win-back engine with request filters.
 *
 * @return array{
 *   rows:array<int,array<string,mixed>>,
 *   unmapped_facets:array<string,array<string,int>>,
 *   en_unpublished_variations:array<int,array<string,mixed>>
 * }
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
	intersoccer_winback_report_load_dob_gate();

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
		'More targets',
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
	$last_col = 'AA';
	$sheet->getStyle('A1:' . $last_col . '1')->getFont()->setBold(true);

	$row_index = 2;
	foreach ($rows as $row) {
		$player = $row['player'] ?? [];
		$parent = $row['parent'] ?? [];
		$contact = $row['contact'] ?? [];
		$last = $row['last_event'] ?? [];
		$target = $row['target'] ?? null;
		$more_targets = $row['more_targets'] ?? [];
		$more_labels = [];
		if (is_array($more_targets)) {
			foreach ($more_targets as $mt) {
				if (!is_array($mt)) {
					continue;
				}
				$label = trim((string) ( $mt['product'] ?? '' ));
				$start = (string) ( $mt['start_date'] ?? '' );
				if ($label !== '' && $start !== '') {
					$more_labels[] = $label . ' (' . $start . ')';
				} elseif ($label !== '') {
					$more_labels[] = $label;
				}
			}
		}
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
			implode(', ', $more_labels),
			!empty($row['aged_up']) ? 'Yes' : 'No',
			!empty($row['sibling_booked']) ? 'Yes' : 'No',
			!empty($row['rebooked_elsewhere']) ? 'Yes' : 'No',
			!empty($row['full']) ? 'Yes' : 'No',
			(string) ($row['status'] ?? ''),
			intersoccer_winback_report_format_dob_for_export((string) ($row['dob'] ?? '')),
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
	$page = intersoccer_winback_report_get_scalar('page');
	if ($page === null || sanitize_text_field($page) !== 'intersoccer-winback-report') {
		return;
	}
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
	}
	check_admin_referer('intersoccer_winback_report_excel');

	$result = intersoccer_winback_report_run();
	intersoccer_winback_report_stream_excel(intersoccer_winback_report_export_rows($result['rows']));
}

/**
 * Admin page render callback.
 */
function intersoccer_render_winback_report_page() {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
	}

	$filters = intersoccer_winback_report_request_filters();
	$tab = intersoccer_winback_report_request_tab();
	$result = intersoccer_winback_report_run();
	$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
	$unmapped = is_array($result['unmapped_facets'] ?? null) ? $result['unmapped_facets'] : [];
	$counts = intersoccer_winback_report_tab_counts($rows);
	$tab_rows = intersoccer_winback_report_rows_for_tab($rows, $tab);
	$page = intersoccer_winback_report_page_slice($tab_rows, intersoccer_winback_report_request_paged(), 50);
	$show_events = intersoccer_winback_report_has_events_column($rows);
	$venue_labels = intersoccer_winback_report_facet_options('pa_intersoccer-venues', $filters['venue']);
	$age_labels = intersoccer_winback_report_facet_options('pa_age-group', $filters['age_group']);
	$unmapped_items = intersoccer_winback_report_unmapped_notice_items($unmapped);
	$base_args = intersoccer_winback_report_query_args($filters, $tab);
	$excel_url = wp_nonce_url(
		add_query_arg(
			intersoccer_winback_report_query_args($filters, $tab, ['export_excel' => '1']),
			admin_url('admin.php')
		),
		'intersoccer_winback_report_excel'
	);
	$excel_all_url = wp_nonce_url(
		add_query_arg(
			intersoccer_winback_report_export_all_query_args($filters),
			admin_url('admin.php')
		),
		'intersoccer_winback_report_excel'
	);
	$kind_options = [
		'camp' => __('Camp', 'intersoccer-reports-rosters'),
		'course' => __('Course', 'intersoccer-reports-rosters'),
		'all' => __('All', 'intersoccer-reports-rosters'),
	];
	?>
	<div class="wrap intersoccer-winback-report">
		<h1><?php esc_html_e('Win-back report', 'intersoccer-reports-rosters'); ?></h1>
		<p><?php esc_html_e('Players with a camp or course in the lookback window. Filters are applied by the report engine. Tabs split the result by status.', 'intersoccer-reports-rosters'); ?></p>

		<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="intersoccer-winback-report" />
			<input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />

			<label for="wb-kind"><?php esc_html_e('Kind:', 'intersoccer-reports-rosters'); ?></label>
			<select name="kind" id="wb-kind">
				<?php foreach ($kind_options as $value => $label) : ?>
					<option value="<?php echo esc_attr($value); ?>" <?php selected($filters['kind'], $value); ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="wb-venue"><?php esc_html_e('Venue:', 'intersoccer-reports-rosters'); ?></label>
			<select name="venue" id="wb-venue">
				<option value="" <?php selected($filters['venue'], ''); ?>><?php esc_html_e('All venues', 'intersoccer-reports-rosters'); ?></option>
				<?php foreach ($venue_labels as $slug => $label) : ?>
					<option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['venue'], $slug); ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="wb-age"><?php esc_html_e('Age group:', 'intersoccer-reports-rosters'); ?></label>
			<select name="age_group" id="wb-age">
				<option value="" <?php selected($filters['age_group'], ''); ?>><?php esc_html_e('All age groups', 'intersoccer-reports-rosters'); ?></option>
				<?php foreach ($age_labels as $slug => $label) : ?>
					<option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['age_group'], $slug); ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="wb-lookback"><?php esc_html_e('Lookback months:', 'intersoccer-reports-rosters'); ?></label>
			<input type="number" min="1" step="1" name="lookback_months" id="wb-lookback" value="<?php echo esc_attr((string) $filters['lookback_months']); ?>" />

			<button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'intersoccer-reports-rosters'); ?></button>
			<a class="button" href="<?php echo esc_url($excel_url); ?>"><?php esc_html_e('Export this tab', 'intersoccer-reports-rosters'); ?></a>
			<a class="button" href="<?php echo esc_url($excel_all_url); ?>"><?php esc_html_e('Export all statuses', 'intersoccer-reports-rosters'); ?></a>
		</form>

		<?php if ($unmapped_items !== []) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e('Some venue or age-group values did not map to an English term.', 'intersoccer-reports-rosters'); ?></p>
				<ul style="list-style:disc;margin-left:1.5em;">
					<?php foreach ($unmapped_items as $item) : ?>
						<li>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: facet label, 2: raw value, 3: row count */
									__('%1$s: %2$s (%3$d)', 'intersoccer-reports-rosters'),
									$item['facet'],
									$item['value'],
									$item['count']
								)
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php
		echo intersoccer_winback_report_admin_en_unpublished_html(
			is_array($result['en_unpublished_variations'] ?? null) ? $result['en_unpublished_variations'] : null
		);
		?>

		<h2 class="nav-tab-wrapper">
			<?php foreach (intersoccer_winback_report_tab_labels() as $slug => $label) : ?>
				<a href="<?php echo esc_url(add_query_arg(intersoccer_winback_report_query_args($filters, $slug), admin_url('admin.php'))); ?>" class="nav-tab<?php echo $tab === $slug ? ' nav-tab-active' : ''; ?>">
					<?php echo esc_html($label); ?> (<?php echo esc_html((string) (int) $counts[$slug]); ?>)
				</a>
			<?php endforeach; ?>
		</h2>

		<p class="description" style="margin:12px 0;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: rows in the current tab, 2: current page, 3: page count */
					__('Showing %1$d in this tab · page %2$d of %3$d', 'intersoccer-reports-rosters'),
					(int) $page['total'],
					(int) $page['paged'],
					(int) $page['total_pages']
				)
			);
			?>
		</p>

		<?php if ($page['total_pages'] > 1) : ?>
			<div class="tablenav top" style="margin-bottom:8px;">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links([
							'base' => add_query_arg(array_merge($base_args, ['paged' => '%#%']), admin_url('admin.php')),
							'format' => '',
							'current' => $page['paged'],
							'total' => $page['total_pages'],
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						])
					);
					?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ($page['rows'] === []) : ?>
			<p><?php esc_html_e('No players in this tab.', 'intersoccer-reports-rosters'); ?></p>
		<?php else : ?>
			<div style="overflow-x:auto;">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('Player', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Age', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Parent', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Email', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Phone', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Account email', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Last event', 'intersoccer-reports-rosters'); ?></th>
							<?php if ($show_events) : ?>
								<th><?php esc_html_e('Events in lookback', 'intersoccer-reports-rosters'); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e('Target event', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Aged up', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Sibling booked', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Rebooked elsewhere', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Full', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Contact differs', 'intersoccer-reports-rosters'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($page['rows'] as $row) :
							$player = is_array($row['player'] ?? null) ? $row['player'] : [];
							$parent = is_array($row['parent'] ?? null) ? $row['parent'] : [];
							$contact = is_array($row['contact'] ?? null) ? $row['contact'] : [];
							$parent_id = (int) ($parent['id'] ?? 0);
							$parent_name = trim((string) ($parent['name'] ?? ''));
							$email = (string) ($contact['email'] ?? '');
							$phone = (string) ($contact['phone'] ?? '');
							$account_email = (string) ($contact['account_email'] ?? ($row['account_email'] ?? ''));
							$dob = intersoccer_winback_report_format_dob_for_export(isset($row['dob']) ? (string) $row['dob'] : '');
							?>
							<tr>
								<td>
									<?php echo esc_html((string) ($player['name'] ?? '')); ?>
									<br />
									<span class="description"><?php echo esc_html(sprintf(/* translators: %s: uuid, idx, or name */ __('Match tier: %s', 'intersoccer-reports-rosters'), (string) ($player['tier'] ?? ''))); ?></span>
								</td>
								<td>
									<?php echo esc_html(intersoccer_winback_report_age_text($row)); ?>
									<?php if ($dob !== '') : ?>
										<br />
										<span class="description"><?php echo esc_html($dob); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html($parent_name !== '' ? $parent_name : '—'); ?>
									<?php if ($parent_id > 0) : ?>
										<br />
										<a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $parent_id)); ?>">#<?php echo esc_html((string) $parent_id); ?></a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html($email !== '' ? $email : '—'); ?></td>
								<td><?php echo esc_html($phone !== '' ? $phone : '—'); ?></td>
								<td><?php echo esc_html($account_email !== '' ? $account_email : '—'); ?></td>
								<td><?php echo intersoccer_winback_report_format_event_html($row['last_event'] ?? null, $venue_labels, $age_labels, true); ?></td>
								<?php if ($show_events) : ?>
									<td><?php echo esc_html(intersoccer_winback_report_events_text($row)); ?></td>
								<?php endif; ?>
								<td><?php
									echo intersoccer_winback_report_format_event_html($row['target'] ?? null, $venue_labels, $age_labels, false);
									echo intersoccer_winback_report_format_more_targets_html($row['more_targets'] ?? null, $venue_labels, $age_labels);
								?></td>
								<td><?php echo esc_html(intersoccer_winback_report_flag_mark($row['aged_up'] ?? false)); ?></td>
								<td><?php echo esc_html(intersoccer_winback_report_flag_mark($row['sibling_booked'] ?? false)); ?></td>
								<td><?php echo esc_html(intersoccer_winback_report_flag_mark($row['rebooked_elsewhere'] ?? false)); ?></td>
								<td><?php echo esc_html(intersoccer_winback_report_flag_mark($row['full'] ?? false)); ?></td>
								<td><?php echo esc_html(intersoccer_winback_report_flag_mark($contact['contact_differs'] ?? false)); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
