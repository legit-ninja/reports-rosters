<?php
/**
 * Player camp status: all players left-joined to camp roster bookings.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if ( ! function_exists( 'intersoccer_roster_field_player_first_name' ) ) {
	$__cf = dirname( __FILE__ ) . '/roster-canonical-fields.php';
	if ( file_exists( $__cf ) ) {
		require_once $__cf;
	}
}


/**
 * Camp-like activity_type values (Camps listing types + Girls Only variants).
 *
 * @return string[]
 */
function intersoccer_player_camp_status_camp_activity_types() {
	$types = function_exists('intersoccer_roster_listing_activity_types')
		? intersoccer_roster_listing_activity_types('camp')
		: ['Camp', 'Camp, Girls Only', "Camp, Girls' only", 'camp'];

	$extra = [
		'Girls Only',
		"Girls' Only",
		"Girls' only",
		'girls only',
	];

	return array_values(array_unique(array_merge($types, $extra)));
}

/**
 * Whether a roster row is a camp-like booking (not course/tournament).
 *
 * @param array<string,mixed> $row
 * @return bool
 */
function intersoccer_player_camp_status_row_is_camp(array $row) {
	$activity = trim((string) ($row['activity_type'] ?? ''));
	$activity_l = strtolower($activity);

	// Explicit course / tournament → never camp for this report.
	if (strpos($activity_l, 'course') !== false || strpos($activity_l, 'cours') !== false
		|| strpos($activity_l, 'kurs') !== false || strpos($activity_l, 'tournament') !== false) {
		return false;
	}

	// Ambiguous "Girls Only" — use listing bucket when available.
	if ($activity_l === 'girls only' || $activity_l === "girls' only") {
		if (function_exists('intersoccer_roster_girls_only_listing_bucket')) {
			return intersoccer_roster_girls_only_listing_bucket($row) === 'camp';
		}
		if (function_exists('intersoccer_roster_row_camp_facets_indicate_camp')) {
			return intersoccer_roster_row_camp_facets_indicate_camp($row);
		}
		return true;
	}

	$allowed = intersoccer_player_camp_status_camp_activity_types();
	foreach ($allowed as $type) {
		if (strcasecmp($activity, $type) === 0) {
			return true;
		}
	}

	if (function_exists('intersoccer_roster_row_camp_facets_indicate_camp')) {
		return intersoccer_roster_row_camp_facets_indicate_camp($row);
	}

	return false;
}

/**
 * Filter camp roster rows by year, season type, and girls mode.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param int|string                     $year
 * @param string                         $season_type Empty = all.
 * @param string                         $girls_mode  all|mixed|girls_only
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_camp_status_filter_roster_rows(array $rows, $year, $season_type = '', $girls_mode = 'all') {
	$year = (string) $year;
	$season_type = (string) $season_type;
	$girls_mode = in_array($girls_mode, ['all', 'mixed', 'girls_only'], true) ? $girls_mode : 'all';
	$out = [];

	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		if (!empty($row['is_placeholder'])) {
			continue;
		}
		if (!intersoccer_player_camp_status_row_is_camp($row)) {
			continue;
		}

		if ($girls_mode === 'mixed' && !empty($row['girls_only'])) {
			continue;
		}
		if ($girls_mode === 'girls_only' && empty($row['girls_only'])) {
			continue;
		}

		$season = (string) ($row['season'] ?? '');
		if ($year !== '') {
			$season_year = function_exists('intersoccer_extract_year_from_season')
				? intersoccer_extract_year_from_season($season)
				: null;
			if ($season_year === null && $season !== '' && strpos($season, $year) !== false) {
				$season_year = (int) $year;
			}
			if ($season_year === null && !empty($row['start_date'])) {
				$start = (string) $row['start_date'];
				if (preg_match('/^(\d{4})/', $start, $m)) {
					$season_year = (int) $m[1];
				}
			}
			if ((string) $season_year !== $year) {
				continue;
			}
		}

		if ($season_type !== '') {
			$extracted = function_exists('intersoccer_extract_season_type')
				? intersoccer_extract_season_type($season)
				: null;
			if ($extracted !== $season_type && stripos($season, $season_type) === false) {
				continue;
			}
		}

		$out[] = $row;
	}

	return $out;
}

/**
 * Normalize a name part for matching.
 *
 * @param string $name
 * @return string
 */
function intersoccer_player_camp_status_normalize_name($name) {
	$name = strtolower(trim((string) $name));
	$name = preg_replace('/\s+/', ' ', $name);
	return $name === null ? '' : $name;
}

/**
 * Build booking summary string for one roster row.
 *
 * @param array<string,mixed> $row
 * @return array{product_name:string,venue:string,week:string,season:string,start_date:string,display:string}
 */
function intersoccer_player_camp_status_booking_summary(array $row) {
	$product = trim((string) ($row['product_name'] ?? ''));
	$venue = trim((string) ($row['venue'] ?? ''));
	$week = trim((string) ($row['camp_terms'] ?? ''));
	if ($week === '' || $week === 'N/A') {
		$week = trim((string) ($row['event_dates'] ?? ''));
	}
	if ($week === 'N/A') {
		$week = '';
	}
	$season = trim((string) ($row['season'] ?? ''));
	$start = trim((string) ($row['start_date'] ?? ''));
	if ($start === '1970-01-01') {
		$start = '';
	}

	$parts = array_filter([$product, $venue, $week, $season], static function ($p) {
		return $p !== '';
	});
	$display = implode(' · ', $parts);

	return [
		'product_name' => $product,
		'venue' => $venue,
		'week' => $week,
		'season' => $season,
		'start_date' => $start,
		'display' => $display,
	];
}

/**
 * Index roster rows for left-join matching.
 *
 * Keys:
 * - idx:{customer_id}:{player_index}
 * - name:{customer_id}:{first}|{last}
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,array<int,array<string,mixed>>>
 */
function intersoccer_player_camp_status_build_roster_index(array $rows) {
	$index = [];

	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$summary = intersoccer_player_camp_status_booking_summary($row);
		$customer_id = (int) ($row['customer_id'] ?? 0);
		$player_index = isset($row['player_index']) ? (int) $row['player_index'] : null;

		if ($customer_id > 0 && $player_index !== null) {
			$key = 'idx:' . $customer_id . ':' . $player_index;
			$index[$key][] = $summary;
		}

		$first = intersoccer_player_camp_status_normalize_name( function_exists( 'intersoccer_roster_field_player_first_name' ) ? intersoccer_roster_field_player_first_name( $row ) : ( $row['first_name'] ?? ( $row['player_first_name'] ?? '' ) ) );
		$last = intersoccer_player_camp_status_normalize_name( function_exists( 'intersoccer_roster_field_player_last_name' ) ? intersoccer_roster_field_player_last_name( $row ) : ( $row['last_name'] ?? ( $row['player_last_name'] ?? '' ) ) );
		if ($customer_id > 0 && $first !== '' && $last !== '') {
			$key = 'name:' . $customer_id . ':' . $first . '|' . $last;
			$index[$key][] = $summary;
		}
	}

	return $index;
}

/**
 * Left-join players to roster index.
 *
 * @param array<int,array<string,mixed>>           $players
 * @param array<string,array<int,array<string,mixed>>> $index
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_camp_status_join(array $players, array $index) {
	$joined = [];

	foreach ($players as $player) {
		if (!is_array($player)) {
			continue;
		}
		$user_id = (int) ($player['user_id'] ?? 0);
		$player_index = isset($player['player_index']) ? (int) $player['player_index'] : -1;
		$first = intersoccer_player_camp_status_normalize_name($player['first_name'] ?? '');
		$last = intersoccer_player_camp_status_normalize_name($player['last_name'] ?? '');

		$bookings = [];
		$seen = [];

		$idx_key = 'idx:' . $user_id . ':' . $player_index;
		if ($user_id > 0 && isset($index[$idx_key])) {
			foreach ($index[$idx_key] as $booking) {
				$sig = $booking['display'] ?? '';
				if ($sig !== '' && isset($seen[$sig])) {
					continue;
				}
				$seen[$sig] = true;
				$bookings[] = $booking;
			}
		}

		if (empty($bookings)) {
			$name_key = 'name:' . $user_id . ':' . $first . '|' . $last;
			if ($user_id > 0 && $first !== '' && $last !== '' && isset($index[$name_key])) {
				foreach ($index[$name_key] as $booking) {
					$sig = $booking['display'] ?? '';
					if ($sig !== '' && isset($seen[$sig])) {
						continue;
					}
					$seen[$sig] = true;
					$bookings[] = $booking;
				}
			}
		}

		$display_parts = [];
		foreach ($bookings as $booking) {
			if (!empty($booking['display'])) {
				$display_parts[] = $booking['display'];
			}
		}

		$joined[] = array_merge($player, [
			'booked' => !empty($bookings),
			'bookings' => $bookings,
			'bookings_display' => implode('; ', $display_parts),
		]);
	}

	return $joined;
}

/**
 * Filter joined rows by booked status and optional search.
 *
 * @param array<int,array<string,mixed>> $joined
 * @param string                         $booked_filter all|booked|not_booked
 * @param string                         $search
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_camp_status_filter_rows(array $joined, $booked_filter = 'all', $search = '') {
	$booked_filter = in_array($booked_filter, ['all', 'booked', 'not_booked'], true) ? $booked_filter : 'all';
	$search = intersoccer_player_camp_status_normalize_name($search);
	$out = [];

	foreach ($joined as $row) {
		if (!is_array($row)) {
			continue;
		}
		$booked = !empty($row['booked']);
		if ($booked_filter === 'booked' && !$booked) {
			continue;
		}
		if ($booked_filter === 'not_booked' && $booked) {
			continue;
		}

		if ($search !== '') {
			$hay = intersoccer_player_camp_status_normalize_name(
				($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '') . ' ' . ($row['user_email'] ?? '')
			);
			if (strpos($hay, $search) === false) {
				continue;
			}
		}

		$out[] = $row;
	}

	return $out;
}

/**
 * Compute age from DOB string (Y-m-d preferred).
 *
 * @param string $dob
 * @return int|null
 */
function intersoccer_player_camp_status_compute_age($dob) {
	$dob = trim((string) $dob);
	if ($dob === '' || $dob === '1970-01-01') {
		return null;
	}
	if (function_exists('intersoccer_calculate_player_age')) {
		$age = intersoccer_calculate_player_age($dob);
		return $age > 0 ? $age : null;
	}
	try {
		$dt = new \DateTimeImmutable($dob);
		$now = new \DateTimeImmutable('today');
		return (int) $dt->diff($now)->y;
	} catch (\Exception $e) {
		return null;
	}
}

/**
 * Enumerate all players from Player Management user meta.
 *
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_camp_status_load_players() {
	global $wpdb;

	$user_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
			'intersoccer_players'
		)
	);

	$players = [];
	foreach ((array) $user_ids as $user_id) {
		$user_id = (int) $user_id;
		if ($user_id <= 0) {
			continue;
		}

		$user = get_userdata($user_id);
		$email = $user ? (string) $user->user_email : '';

		if (function_exists('intersoccer_get_user_players')) {
			$rows = intersoccer_get_user_players($user_id);
		} else {
			$rows = maybe_unserialize(get_user_meta($user_id, 'intersoccer_players', true));
		}
		if (!is_array($rows) || empty($rows)) {
			continue;
		}

		foreach ($rows as $index => $player) {
			if (!is_array($player)) {
				continue;
			}
			$first = trim((string) ($player['first_name'] ?? ''));
			$last = trim((string) ($player['last_name'] ?? ''));
			if ($first === '' && $last === '') {
				continue;
			}
			$dob = (string) ($player['dob'] ?? '');
			$players[] = [
				'user_id' => $user_id,
				'user_email' => $email,
				'player_id' => (string) ($player['player_id'] ?? ''),
				'player_index' => is_numeric($index) ? (int) $index : $index,
				'first_name' => $first,
				'last_name' => $last,
				'dob' => $dob,
				'age' => intersoccer_player_camp_status_compute_age($dob),
			];
		}
	}

	usort($players, static function ($a, $b) {
		$cmp = strcasecmp((string) ($a['last_name'] ?? ''), (string) ($b['last_name'] ?? ''));
		if ($cmp !== 0) {
			return $cmp;
		}
		return strcasecmp((string) ($a['first_name'] ?? ''), (string) ($b['first_name'] ?? ''));
	});

	return $players;
}

/**
 * Load camp roster rows from DB (year-scoped query, further filtered in PHP).
 *
 * @param int|string $year
 * @param string     $season_type
 * @param string     $girls_mode
 * @return array<int,array<string,mixed>>
 */
function intersoccer_player_camp_status_load_camp_roster_rows($year, $season_type = '', $girls_mode = 'all') {
	global $wpdb;

	$table = $wpdb->prefix . 'intersoccer_rosters';
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
	if ($exists !== $table) {
		return [];
	}

	$columns = $wpdb->get_col("DESCRIBE {$table}", 0);
	$has_placeholder = in_array('is_placeholder', (array) $columns, true);
	$has_customer = in_array('customer_id', (array) $columns, true);
	$has_player_index = in_array('player_index', (array) $columns, true);
	$has_girls = in_array('girls_only', (array) $columns, true);

	$select = [
		'id',
		'order_id',
		'order_item_id',
		'first_name',
		'last_name',
		'player_first_name',
		'player_last_name',
		'product_name',
		'venue',
		'camp_terms',
		'event_dates',
		'season',
		'start_date',
		'activity_type',
	];
	if ($has_customer) {
		$select[] = 'customer_id';
	}
	if ($has_player_index) {
		$select[] = 'player_index';
	}
	if ($has_girls) {
		$select[] = 'girls_only';
	}
	if ($has_placeholder) {
		$select[] = 'is_placeholder';
	}

	$sql = 'SELECT ' . implode(', ', $select) . " FROM {$table} WHERE 1=1";
	$params = [];

	if ($has_placeholder) {
		$sql .= ' AND (is_placeholder = 0 OR is_placeholder IS NULL)';
	}

	$year = (string) $year;
	if ($year !== '') {
		$sql .= ' AND (season LIKE %s OR start_date LIKE %s)';
		$params[] = '%' . $wpdb->esc_like($year) . '%';
		$params[] = $wpdb->esc_like($year) . '-%';
	}

	if ($has_girls && $girls_mode === 'mixed') {
		$sql .= ' AND (girls_only = 0 OR girls_only IS NULL)';
	} elseif ($has_girls && $girls_mode === 'girls_only') {
		$sql .= ' AND girls_only = 1';
	}

	if (!empty($params)) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results($sql, ARRAY_A);
	}

	if (!is_array($rows)) {
		$rows = [];
	}

	foreach ($rows as &$row) {
		$cid = (int) ($row['customer_id'] ?? 0);
		if ($cid <= 0 && !empty($row['order_id']) && function_exists('wc_get_order')) {
			$order = wc_get_order((int) $row['order_id']);
			if ($order) {
				$row['customer_id'] = (int) $order->get_user_id();
			}
		}
		if (!isset($row['player_index'])) {
			$row['player_index'] = 0;
		}
		if (!isset($row['girls_only'])) {
			$row['girls_only'] = 0;
		}
		if (!isset($row['is_placeholder'])) {
			$row['is_placeholder'] = 0;
		}
	}
	unset($row);

	return intersoccer_player_camp_status_filter_roster_rows($rows, $year, $season_type, $girls_mode);
}

/**
 * Collect joined + filtered player camp status rows.
 *
 * @param int|string $year
 * @param string     $season_type
 * @param string     $girls_mode
 * @param string     $booked_filter
 * @param string     $search
 * @return array{rows:array<int,array<string,mixed>>,total:int,booked:int,not_booked:int}
 */
function intersoccer_player_camp_status_collect($year, $season_type = '', $girls_mode = 'all', $booked_filter = 'all', $search = '') {
	$players = intersoccer_player_camp_status_load_players();
	$roster_rows = intersoccer_player_camp_status_load_camp_roster_rows($year, $season_type, $girls_mode);
	$index = intersoccer_player_camp_status_build_roster_index($roster_rows);
	$joined = intersoccer_player_camp_status_join($players, $index);

	$booked_count = 0;
	$not_booked_count = 0;
	foreach ($joined as $row) {
		if (!empty($row['booked'])) {
			$booked_count++;
		} else {
			$not_booked_count++;
		}
	}

	$filtered = intersoccer_player_camp_status_filter_rows($joined, $booked_filter, $search);

	return [
		'rows' => $filtered,
		'total' => count($joined),
		'booked' => $booked_count,
		'not_booked' => $not_booked_count,
		'filtered_total' => count($filtered),
	];
}

/**
 * Resolve filters from the current request.
 *
 * @return array{year:int,season_type:string,girls_mode:string,booked:string,search:string,paged:int}
 */
function intersoccer_player_camp_status_request_filters() {
	$year = isset($_GET['year']) ? absint($_GET['year']) : (int) date('Y');
	$season_type = isset($_GET['season_type']) ? sanitize_text_field(wp_unslash($_GET['season_type'])) : '';
	$girls_mode = isset($_GET['girls_mode']) ? sanitize_text_field(wp_unslash($_GET['girls_mode'])) : 'all';
	if (!in_array($girls_mode, ['all', 'mixed', 'girls_only'], true)) {
		$girls_mode = 'all';
	}
	$booked = isset($_GET['booked']) ? sanitize_text_field(wp_unslash($_GET['booked'])) : 'all';
	if (!in_array($booked, ['all', 'booked', 'not_booked'], true)) {
		$booked = 'all';
	}
	$search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
	$paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

	return [
		'year' => $year,
		'season_type' => $season_type,
		'girls_mode' => $girls_mode,
		'booked' => $booked,
		'search' => $search,
		'paged' => $paged,
	];
}

/**
 * Flat export row for Excel.
 *
 * @param array<string,mixed> $row
 * @return array{0:string,1:string,2:int|string,3:string,4:string|int|null,5:string,6:string}
 */
function intersoccer_player_camp_status_export_row(array $row) {
	$name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
	return [
		$name,
		(string) ($row['user_email'] ?? ''),
		(int) ($row['user_id'] ?? 0),
		(string) ($row['dob'] ?? ''),
		$row['age'] ?? '',
		!empty($row['booked']) ? 'Yes' : 'No',
		(string) ($row['bookings_display'] ?? ''),
	];
}

/**
 * Stream Excel download and exit.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param int|string                     $year
 * @return void
 */
function intersoccer_player_camp_status_stream_excel(array $rows, $year) {
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

	$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
	$sheet = $spreadsheet->getActiveSheet();
	$sheet->setTitle('Player Camp Status');

	$headers = [
		'Player Name',
		'Parent Email',
		'User ID',
		'DOB',
		'Age',
		'Booked',
		'Camp Bookings',
	];
	$sheet->fromArray($headers, null, 'A1');
	$sheet->getStyle('A1:G1')->getFont()->setBold(true);

	$row_index = 2;
	foreach ($rows as $row) {
		$sheet->fromArray(intersoccer_player_camp_status_export_row($row), null, 'A' . $row_index);
		$row_index++;
	}

	foreach (range('A', 'G') as $col) {
		$sheet->getColumnDimension($col)->setAutoSize(true);
	}

	$filename = 'player-camp-status-' . (int) $year . '.xlsx';
	nocache_headers();
	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Cache-Control: max-age=0');

	$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
	$writer->save('php://output');
	exit;
}

/**
 * Export Excel on admin_init / load-{$page} before admin-header HTML.
 *
 * @return void
 */
function intersoccer_player_camp_status_maybe_export_excel() {
	if (empty($_GET['export_excel'])) {
		return;
	}
	$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
	if ($page !== 'intersoccer-player-camp-status') {
		return;
	}

	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
	}
	check_admin_referer('intersoccer_player_camp_status_excel');

	$filters = intersoccer_player_camp_status_request_filters();
	$result = intersoccer_player_camp_status_collect(
		$filters['year'],
		$filters['season_type'],
		$filters['girls_mode'],
		$filters['booked'],
		$filters['search']
	);

	intersoccer_player_camp_status_stream_excel($result['rows'], $filters['year']);
}

/**
 * Render Player camp status admin page.
 *
 * @return void
 */
function intersoccer_render_player_camp_status_page() {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
	}

	$filters = intersoccer_player_camp_status_request_filters();
	$result = intersoccer_player_camp_status_collect(
		$filters['year'],
		$filters['season_type'],
		$filters['girls_mode'],
		$filters['booked'],
		$filters['search']
	);

	$per_page = 50;
	$total_pages = max(1, (int) ceil($result['filtered_total'] / $per_page));
	$paged = min($filters['paged'], $total_pages);
	$offset = ($paged - 1) * $per_page;
	$page_rows = array_slice($result['rows'], $offset, $per_page);

	$base_args = [
		'page' => 'intersoccer-player-camp-status',
		'year' => $filters['year'],
		'season_type' => $filters['season_type'],
		'girls_mode' => $filters['girls_mode'],
		'booked' => $filters['booked'],
		'search' => $filters['search'],
	];

	$excel_url = wp_nonce_url(
		add_query_arg(
			array_merge($base_args, ['export_excel' => 1]),
			admin_url('admin.php')
		),
		'intersoccer_player_camp_status_excel'
	);
	?>
	<div class="wrap intersoccer-player-camp-status">
		<h1><?php esc_html_e('Player camp status', 'intersoccer-reports-rosters'); ?></h1>
		<p><?php esc_html_e('All players from Player Management, with whether each has a camp roster booking for the selected year/season. Bookings use the same roster table as Camps (placeholders excluded).', 'intersoccer-reports-rosters'); ?></p>

		<p class="description" style="margin-bottom:16px;">
			<?php
			printf(
				/* translators: 1: total players, 2: booked count, 3: not booked count, 4: filtered count */
				esc_html__('All players: %1$d · Booked: %2$d · Not booked: %3$d · Showing (filtered): %4$d', 'intersoccer-reports-rosters'),
				(int) $result['total'],
				(int) $result['booked'],
				(int) $result['not_booked'],
				(int) $result['filtered_total']
			);
			?>
		</p>

		<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="intersoccer-player-camp-status" />
			<label for="pcs-year"><?php esc_html_e('Year:', 'intersoccer-reports-rosters'); ?></label>
			<input type="number" name="year" id="pcs-year" value="<?php echo esc_attr((string) $filters['year']); ?>" min="2020" max="<?php echo esc_attr((string) ((int) date('Y') + 2)); ?>" />

			<label for="pcs-season"><?php esc_html_e('Camp season:', 'intersoccer-reports-rosters'); ?></label>
			<select name="season_type" id="pcs-season">
				<option value="" <?php selected($filters['season_type'], ''); ?>><?php esc_html_e('All seasons', 'intersoccer-reports-rosters'); ?></option>
				<option value="Summer" <?php selected($filters['season_type'], 'Summer'); ?>><?php esc_html_e('Summer', 'intersoccer-reports-rosters'); ?></option>
				<option value="Winter" <?php selected($filters['season_type'], 'Winter'); ?>><?php esc_html_e('Winter', 'intersoccer-reports-rosters'); ?></option>
				<option value="Autumn" <?php selected($filters['season_type'], 'Autumn'); ?>><?php esc_html_e('Autumn', 'intersoccer-reports-rosters'); ?></option>
				<option value="Spring" <?php selected($filters['season_type'], 'Spring'); ?>><?php esc_html_e('Spring', 'intersoccer-reports-rosters'); ?></option>
			</select>

			<label for="pcs-girls"><?php esc_html_e('Girls mode:', 'intersoccer-reports-rosters'); ?></label>
			<select name="girls_mode" id="pcs-girls">
				<option value="all" <?php selected($filters['girls_mode'], 'all'); ?>><?php esc_html_e('All (mixed + girls only)', 'intersoccer-reports-rosters'); ?></option>
				<option value="mixed" <?php selected($filters['girls_mode'], 'mixed'); ?>><?php esc_html_e('Mixed only', 'intersoccer-reports-rosters'); ?></option>
				<option value="girls_only" <?php selected($filters['girls_mode'], 'girls_only'); ?>><?php esc_html_e('Girls only', 'intersoccer-reports-rosters'); ?></option>
			</select>

			<label for="pcs-booked"><?php esc_html_e('Booked:', 'intersoccer-reports-rosters'); ?></label>
			<select name="booked" id="pcs-booked">
				<option value="all" <?php selected($filters['booked'], 'all'); ?>><?php esc_html_e('All', 'intersoccer-reports-rosters'); ?></option>
				<option value="booked" <?php selected($filters['booked'], 'booked'); ?>><?php esc_html_e('Booked only', 'intersoccer-reports-rosters'); ?></option>
				<option value="not_booked" <?php selected($filters['booked'], 'not_booked'); ?>><?php esc_html_e('Not booked only', 'intersoccer-reports-rosters'); ?></option>
			</select>

			<label for="pcs-search"><?php esc_html_e('Search:', 'intersoccer-reports-rosters'); ?></label>
			<input type="text" name="search" id="pcs-search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Name or parent email', 'intersoccer-reports-rosters'); ?>" />

			<button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'intersoccer-reports-rosters'); ?></button>
			<a class="button" href="<?php echo esc_url($excel_url); ?>"><?php esc_html_e('Export to Excel', 'intersoccer-reports-rosters'); ?></a>
		</form>

		<?php if ($total_pages > 1) : ?>
			<div class="tablenav top" style="margin-bottom:8px;">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links([
							'base' => add_query_arg(array_merge($base_args, ['paged' => '%#%']), admin_url('admin.php')),
							'format' => '',
							'current' => $paged,
							'total' => $total_pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						])
					);
					?>
				</div>
			</div>
		<?php endif; ?>

		<?php if (empty($page_rows)) : ?>
			<p><?php esc_html_e('No players matched the selected filters.', 'intersoccer-reports-rosters'); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Player', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('Parent email / User', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('DOB', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('Age', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('Booked', 'intersoccer-reports-rosters'); ?></th>
						<th><?php esc_html_e('Camp bookings', 'intersoccer-reports-rosters'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($page_rows as $row) :
						$name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
						$user_edit = admin_url('user-edit.php?user_id=' . (int) $row['user_id']);
						?>
						<tr>
							<td><?php echo esc_html($name); ?></td>
							<td>
								<?php echo esc_html((string) ($row['user_email'] ?? '')); ?>
								<br />
								<a href="<?php echo esc_url($user_edit); ?>">#<?php echo esc_html((string) (int) $row['user_id']); ?></a>
							</td>
							<td><?php echo esc_html((string) ($row['dob'] ?? '')); ?></td>
							<td><?php echo esc_html($row['age'] !== null && $row['age'] !== '' ? (string) $row['age'] : '—'); ?></td>
							<td>
								<?php if (!empty($row['booked'])) : ?>
									<strong style="color:#059669;"><?php esc_html_e('Yes', 'intersoccer-reports-rosters'); ?></strong>
								<?php else : ?>
									<span style="color:#b45309;"><?php esc_html_e('No', 'intersoccer-reports-rosters'); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html((string) ($row['bookings_display'] ?? '—')); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
