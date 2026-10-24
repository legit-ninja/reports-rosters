<?php
/**
 * Player course status office page.
 *
 * Presentation for intersoccer_render_player_course_status_page().
 * Filter values are passed through to the engine. This file does not
 * re-filter rows and does not change the Excel export.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_player_course_status_admin_status_label')) {
	/**
	 * Label for a booking_status slug when the row has no translated label.
	 *
	 * Known slugs map to Booked, Pending payment, On hold, and Not booked.
	 * Any other slug is returned unchanged.
	 *
	 * @param string $status
	 * @return string
	 */
	function intersoccer_player_course_status_admin_status_label($status) {
		switch ((string) $status) {
			case 'booked':
				return __('Booked', 'intersoccer-reports-rosters');
			case 'pending_payment':
				return __('Pending payment', 'intersoccer-reports-rosters');
			case 'on_hold':
				return __('On hold', 'intersoccer-reports-rosters');
			case 'not_booked':
				return __('Not booked', 'intersoccer-reports-rosters');
			default:
				return (string) $status;
		}
	}
}

if (!function_exists('intersoccer_player_course_status_admin_status_text')) {
	/**
	 * Status cell text. Prefer the engine's translated booking_status_label.
	 *
	 * @param array<string,mixed> $row
	 * @return string
	 */
	function intersoccer_player_course_status_admin_status_text(array $row) {
		if (array_key_exists('booking_status_label', $row) && $row['booking_status_label'] !== null) {
			return (string) $row['booking_status_label'];
		}
		if (!array_key_exists('booking_status', $row) || $row['booking_status'] === null || $row['booking_status'] === '') {
			return !empty($row['booked'])
				? __('Yes', 'intersoccer-reports-rosters')
				: __('No', 'intersoccer-reports-rosters');
		}

		return intersoccer_player_course_status_admin_status_label((string) $row['booking_status']);
	}
}

if (!function_exists('intersoccer_player_course_status_admin_pending_lines_html')) {
	/**
	 * Pending booking lines, already escaped once.
	 *
	 * pending_bookings are not in bookings[] or unmatched_bookings, so each
	 * line is shown as given. Nothing is removed by comparing labels.
	 *
	 * @param mixed $pending
	 * @return string
	 */
	function intersoccer_player_course_status_admin_pending_lines_html($pending) {
		if (!is_array($pending) || $pending === []) {
			return '';
		}

		$lines = [];
		foreach ($pending as $booking) {
			if (!is_array($booking)) {
				continue;
			}
			$label = (string) ($booking['label'] ?? '');
			$order_status = (string) ($booking['order_status'] ?? '');
			if ($order_status !== '') {
				$text = sprintf(
					/* translators: 1: booking label, 2: order status */
					__('Pending: %1$s (%2$s)', 'intersoccer-reports-rosters'),
					$label,
					$order_status
				);
			} else {
				$text = sprintf(
					/* translators: %s: booking label */
					__('Pending: %s', 'intersoccer-reports-rosters'),
					$label
				);
			}
			$lines[] = esc_html($text);
		}

		return implode('<br />', $lines);
	}
}

if (!function_exists('intersoccer_player_course_status_admin_bookings_html')) {
	/**
	 * Course bookings cell: bookings_label plus pending lines. Escaped once.
	 *
	 * @param array<string,mixed> $row
	 * @return string
	 */
	function intersoccer_player_course_status_admin_bookings_html(array $row) {
		$parts = [];
		$label = (string) ($row['bookings_label'] ?? '');
		if ($label !== '') {
			$parts[] = esc_html($label);
		}
		if (array_key_exists('pending_bookings', $row)) {
			$pending = intersoccer_player_course_status_admin_pending_lines_html($row['pending_bookings']);
			if ($pending !== '') {
				$parts[] = $pending;
			}
		}
		if ($parts === []) {
			return esc_html('—');
		}

		return implode('<br />', $parts);
	}
}

if (!function_exists('intersoccer_player_course_status_admin_dob_text')) {
	/**
	 * DOB cell. The engine already applied the gate: dob_restricted shows Restricted.
	 *
	 * @param array<string,mixed> $row
	 * @return string
	 */
	function intersoccer_player_course_status_admin_dob_text(array $row) {
		if (!empty($row['dob_restricted'])) {
			if (function_exists('intersoccer_rr_medical_restricted_label')) {
				return intersoccer_rr_medical_restricted_label();
			}

			return __('Restricted', 'intersoccer-reports-rosters');
		}
		$dob = $row['dob'] ?? null;
		if ($dob === null || $dob === '') {
			return '—';
		}

		return (string) $dob;
	}
}

if (!function_exists('intersoccer_player_course_status_admin_age_text')) {
	/**
	 * Age cell. Age is always shown; an unknown age is an em dash.
	 *
	 * @param array<string,mixed> $row
	 * @return string
	 */
	function intersoccer_player_course_status_admin_age_text(array $row) {
		if (!array_key_exists('age', $row) || $row['age'] === null || $row['age'] === '') {
			return '—';
		}

		return (string) $row['age'];
	}
}

if (!function_exists('intersoccer_player_course_status_admin_totals_text')) {
	/**
	 * Totals line. pending_payment and on_hold are "of which" parts of not_booked.
	 *
	 * They are not added into players, booked, or not_booked. Missing keys are omitted.
	 *
	 * @param array<string,mixed> $totals
	 * @param int                  $showing
	 * @return string
	 */
	function intersoccer_player_course_status_admin_totals_text(array $totals, $showing) {
		$players = (int) ($totals['players'] ?? 0);
		$booked = (int) ($totals['booked'] ?? 0);
		$not_booked = (int) ($totals['not_booked'] ?? 0);
		$unmatched = (int) ($totals['unmatched_bookings'] ?? 0);
		$has_pending = array_key_exists('pending_payment', $totals);
		$has_hold = array_key_exists('on_hold', $totals);

		if ($has_pending && $has_hold) {
			$not_booked_text = sprintf(
				/* translators: 1: not booked count, 2: pending payment count, 3: on hold count */
				__('Not booked: %1$d (of which pending payment: %2$d, on hold: %3$d)', 'intersoccer-reports-rosters'),
				$not_booked,
				(int) $totals['pending_payment'],
				(int) $totals['on_hold']
			);
		} elseif ($has_pending) {
			$not_booked_text = sprintf(
				/* translators: 1: not booked count, 2: pending payment count */
				__('Not booked: %1$d (of which pending payment: %2$d)', 'intersoccer-reports-rosters'),
				$not_booked,
				(int) $totals['pending_payment']
			);
		} elseif ($has_hold) {
			$not_booked_text = sprintf(
				/* translators: 1: not booked count, 2: on hold count */
				__('Not booked: %1$d (of which on hold: %2$d)', 'intersoccer-reports-rosters'),
				$not_booked,
				(int) $totals['on_hold']
			);
		} else {
			$not_booked_text = sprintf(
				/* translators: %d: not booked count */
				__('Not booked: %d', 'intersoccer-reports-rosters'),
				$not_booked
			);
		}

		return sprintf(
			/* translators: 1: player count, 2: booked count, 3: not-booked phrase, 4: unmatched count, 5: showing count */
			__('Players: %1$d · Booked: %2$d · %3$s · Unmatched bookings: %4$d · Showing: %5$d', 'intersoccer-reports-rosters'),
			$players,
			$booked,
			$not_booked_text,
			$unmatched,
			(int) $showing
		);
	}
}

if (!function_exists('intersoccer_player_course_status_admin_unmatched_html')) {
	/**
	 * Read-only unmatched bookings table. Empty when there is nothing to show.
	 *
	 * @param mixed $unmatched
	 * @return string
	 */
	function intersoccer_player_course_status_admin_unmatched_html($unmatched) {
		if (!is_array($unmatched) || $unmatched === []) {
			return '';
		}

		$rows = '';
		foreach ($unmatched as $booking) {
			if (!is_array($booking)) {
				continue;
			}
			$rows .= '<tr>'
				. '<td>' . esc_html((string) ($booking['player_name'] ?? '')) . '</td>'
				. '<td>' . esc_html((string) ($booking['customer_id'] ?? '')) . '</td>'
				. '<td>' . esc_html((string) ($booking['label'] ?? '')) . '</td>'
				. '<td>' . esc_html((string) ($booking['order_status'] ?? '')) . '</td>'
				. '</tr>';
		}
		if ($rows === '') {
			return '';
		}

		return '<h2>' . esc_html__('Unmatched bookings', 'intersoccer-reports-rosters') . '</h2>'
			. '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__('Player', 'intersoccer-reports-rosters') . '</th>'
			. '<th>' . esc_html__('Customer', 'intersoccer-reports-rosters') . '</th>'
			. '<th>' . esc_html__('Booking', 'intersoccer-reports-rosters') . '</th>'
			. '<th>' . esc_html__('Order status', 'intersoccer-reports-rosters') . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}
}

if (!function_exists('intersoccer_player_course_status_admin_rows_html')) {
	/**
	 * Main table body rows. Cell text is escaped once.
	 *
	 * @param array<int,mixed> $rows
	 * @return string
	 */
	function intersoccer_player_course_status_admin_rows_html(array $rows) {
		$html = '';
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$player = is_array($row['player'] ?? null) ? $row['player'] : [];
			$parent = is_array($row['parent'] ?? null) ? $row['parent'] : [];
			$user_id = (int) ($parent['id'] ?? 0);
			$parent_html = esc_html((string) ($parent['email'] ?? ''));
			if ($user_id > 0) {
				$parent_html .= '<br /><a href="' . esc_url(admin_url('user-edit.php?user_id=' . $user_id)) . '">#'
					. esc_html((string) $user_id) . '</a>';
			}
			$booked = !empty($row['booked'])
				? esc_html__('Yes', 'intersoccer-reports-rosters')
				: esc_html__('No', 'intersoccer-reports-rosters');
			$html .= '<tr>'
				. '<td>' . esc_html((string) ($player['name'] ?? '')) . '</td>'
				. '<td>' . $parent_html . '</td>'
				. '<td>' . esc_html(intersoccer_player_course_status_admin_dob_text($row)) . '</td>'
				. '<td>' . esc_html(intersoccer_player_course_status_admin_age_text($row)) . '</td>'
				. '<td>' . $booked . '</td>'
				. '<td>' . esc_html(intersoccer_player_course_status_admin_status_text($row)) . '</td>'
				. '<td>' . intersoccer_player_course_status_admin_bookings_html($row) . '</td>'
				. '<td>' . esc_html((string) ($player['tier'] ?? '')) . '</td>'
				. '</tr>';
		}

		return $html;
	}
}

if (!function_exists('intersoccer_player_course_status_admin_page_slice')) {
	/**
	 * Page slice. The engine does not paginate.
	 *
	 * @param array<int,mixed> $rows
	 * @param int              $paged
	 * @param int              $per_page
	 * @return array{rows:array<int,mixed>,paged:int,total_pages:int,total_rows:int,per_page:int}
	 */
	function intersoccer_player_course_status_admin_page_slice(array $rows, $paged, $per_page = 50) {
		$per_page = max(1, (int) $per_page);
		$total_rows = count($rows);
		$total_pages = max(1, (int) ceil($total_rows / $per_page));
		$paged = min(max(1, (int) $paged), $total_pages);
		$offset = ($paged - 1) * $per_page;

		return [
			'rows' => array_slice($rows, $offset, $per_page),
			'paged' => $paged,
			'total_pages' => $total_pages,
			'total_rows' => $total_rows,
			'per_page' => $per_page,
		];
	}
}

if (!function_exists('intersoccer_player_course_status_admin_query_args')) {
	/**
	 * Query args shared by the filter form, pagination, and export link.
	 *
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	function intersoccer_player_course_status_admin_query_args(array $filters) {
		return [
			'page' => 'intersoccer-player-course-status',
			'year' => $filters['year'] ?? '',
			'season_type' => $filters['season_type'] ?? '',
			'girls_mode' => $filters['girls_mode'] ?? 'all',
			'booked' => $filters['booked'] ?? 'all',
			'booking_status' => $filters['booking_status'] ?? '',
			'search' => $filters['search'] ?? '',
			'venue' => $filters['venue'] ?? '',
			'course_day' => $filters['course_day'] ?? '',
			'age_group' => $filters['age_group'] ?? '',
		];
	}
}

if (!function_exists('intersoccer_player_course_status_admin_pagination_base')) {
	/**
	 * paginate_links base URL. Keeps booking_status with the other filters.
	 *
	 * @param array<string,mixed> $filters
	 * @return string
	 */
	function intersoccer_player_course_status_admin_pagination_base(array $filters) {
		$args = intersoccer_player_course_status_admin_query_args($filters);
		$args['paged'] = '%#%';

		return add_query_arg($args, admin_url('admin.php'));
	}
}

if (!function_exists('intersoccer_player_course_status_admin_export_url')) {
	/**
	 * Excel URL for the current filters. The engine owns the workbook.
	 *
	 * @param array<string,mixed> $filters
	 * @return string
	 */
	function intersoccer_player_course_status_admin_export_url(array $filters) {
		$args = intersoccer_player_course_status_admin_query_args($filters);
		$args['export_excel'] = 1;
		$url = add_query_arg($args, admin_url('admin.php'));
		if (function_exists('wp_nonce_url')) {
			return wp_nonce_url($url, 'intersoccer_player_course_status_excel');
		}

		return $url;
	}
}

if (!function_exists('intersoccer_player_course_status_admin_options_html')) {
	/**
	 * @param array<string,string> $options
	 * @param string               $current
	 * @return string
	 */
	function intersoccer_player_course_status_admin_options_html(array $options, $current) {
		$html = '';
		foreach ($options as $value => $label) {
			$value = (string) $value;
			$selected = ($value === (string) $current) ? ' selected="selected"' : '';
			$html .= '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html((string) $label) . '</option>';
		}

		return $html;
	}
}

if (!function_exists('intersoccer_player_course_status_admin_select_html')) {
	/**
	 * @param string               $name
	 * @param string               $id
	 * @param array<string,string> $options
	 * @param string               $current
	 * @return string
	 */
	function intersoccer_player_course_status_admin_select_html($name, $id, array $options, $current) {
		return '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '">'
			. intersoccer_player_course_status_admin_options_html($options, $current)
			. '</select>';
	}
}

if (!function_exists('intersoccer_player_course_status_admin_list_options')) {
	/**
	 * Option map for a list of slugs, plus the current value when it is not in the list.
	 *
	 * @param mixed  $values
	 * @param string $current
	 * @param string $all_label
	 * @return array<string,string>
	 */
	function intersoccer_player_course_status_admin_list_options($values, $current, $all_label) {
		$options = ['' => $all_label];
		if (is_array($values)) {
			foreach ($values as $value) {
				if (!is_scalar($value)) {
					continue;
				}
				$value = (string) $value;
				if ($value === '' || array_key_exists($value, $options)) {
					continue;
				}
				$options[$value] = $value;
			}
		}
		$current = (string) $current;
		if ($current !== '' && !array_key_exists($current, $options)) {
			$options[$current] = $current;
		}

		return $options;
	}
}

if (!function_exists('intersoccer_player_course_status_admin_map_options')) {
	/**
	 * Option map for slug => label, plus the current slug when it is missing.
	 *
	 * @param mixed  $map
	 * @param string $current
	 * @param string $all_label
	 * @return array<string,string>
	 */
	function intersoccer_player_course_status_admin_map_options($map, $current, $all_label) {
		$options = ['' => $all_label];
		if (is_array($map)) {
			foreach ($map as $slug => $label) {
				if (!is_scalar($slug)) {
					continue;
				}
				$slug = (string) $slug;
				if ($slug === '') {
					continue;
				}
				$options[$slug] = is_scalar($label) ? (string) $label : $slug;
			}
		}
		$current = (string) $current;
		if ($current !== '' && !array_key_exists($current, $options)) {
			$options[$current] = $current;
		}

		return $options;
	}
}

if (!function_exists('intersoccer_player_course_status_admin_year_options')) {
	/**
	 * Year dropdown: a window around the calendar year, plus the selected year.
	 *
	 * @param int $selected
	 * @return array<string,string>
	 */
	function intersoccer_player_course_status_admin_year_options($selected) {
		$current = (int) date('Y');
		$years = [];
		for ($year = $current - 2; $year <= $current + 2; $year++) {
			$years[$year] = true;
		}
		$selected = (int) $selected;
		if ($selected >= 2000) {
			$years[$selected] = true;
		}
		$keys = array_keys($years);
		sort($keys, SORT_NUMERIC);
		$options = [];
		foreach ($keys as $year) {
			$options[(string) $year] = (string) $year;
		}

		return $options;
	}
}

if (!function_exists('intersoccer_player_course_status_admin_booking_status_options')) {
	/**
	 * Status filter: All, or one of the four booking_status values.
	 *
	 * not_booked here is the plain label only. The booked dropdown is separate.
	 *
	 * @return array<string,string>
	 */
	function intersoccer_player_course_status_admin_booking_status_options() {
		return [
			'' => __('All', 'intersoccer-reports-rosters'),
			'booked' => intersoccer_player_course_status_admin_status_label('booked'),
			'pending_payment' => intersoccer_player_course_status_admin_status_label('pending_payment'),
			'on_hold' => intersoccer_player_course_status_admin_status_label('on_hold'),
			'not_booked' => intersoccer_player_course_status_admin_status_label('not_booked'),
		];
	}
}

if (!function_exists('intersoccer_player_course_status_admin_filters_html')) {
	/**
	 * Filter controls. Seasons, venues, days, and age groups come from filter_options.
	 *
	 * @param array<string,mixed> $filters
	 * @param array<string,mixed> $filter_options
	 * @return string
	 */
	function intersoccer_player_course_status_admin_filters_html(array $filters, array $filter_options) {
		$year = isset($filters['year']) ? (int) $filters['year'] : (int) date('Y');
		$parts = [];
		$parts[] = '<label for="pcrs-year">' . esc_html__('Year:', 'intersoccer-reports-rosters') . '</label> '
			. intersoccer_player_course_status_admin_select_html(
				'year',
				'pcrs-year',
				intersoccer_player_course_status_admin_year_options($year),
				(string) $year
			);
		$parts[] = '<label for="pcrs-season">' . esc_html__('Season:', 'intersoccer-reports-rosters') . '</label> '
			. intersoccer_player_course_status_admin_select_html(
				'season_type',
				'pcrs-season',
				intersoccer_player_course_status_admin_list_options(
					$filter_options['seasons'] ?? [],
					(string) ($filters['season_type'] ?? ''),
					__('All seasons', 'intersoccer-reports-rosters')
				),
				(string) ($filters['season_type'] ?? '')
			);
		$parts[] = '<label for="pcrs-girls">' . esc_html__('Girls mode:', 'intersoccer-reports-rosters') . '</label> '
			. intersoccer_player_course_status_admin_select_html(
				'girls_mode',
				'pcrs-girls',
				[
					'all' => __('All (mixed + girls only)', 'intersoccer-reports-rosters'),
					'mixed' => __('Mixed only', 'intersoccer-reports-rosters'),
					'girls_only' => __('Girls only', 'intersoccer-reports-rosters'),
				],
				(string) ($filters['girls_mode'] ?? 'all')
			);
		$parts[] = '<label for="pcrs-venue">' . esc_html__('Venue:', 'intersoccer-reports-rosters') . '</label> '
			. intersoccer_player_course_status_admin_select_html(
				'venue',
				'pcrs-venue',
				intersoccer_player_course_status_admin_map_options(
					$filter_options['venues'] ?? [],
					(string) ($filters['venue'] ?? ''),
					__('All venues', 'intersoccer-reports-rosters')
				),
				(string) ($filters['venue'] ?? '')
			);
		$parts[] = '<label for="pcrs-day">' . esc_html__('Course day:', 'intersoccer-reports-rosters') . '</label> '
			. intersoccer_player_course_status_admin_select_html(
				'course_day',
				'pcrs-day',
				intersoccer_player_course_status_admin_list_options(
					$filter_options['course_days'] ?? [],
					(string) ($filters['course_day'] ?? ''),
					__('All days', 'intersoccer-reports-rosters')
				),
				(string) ($filters['course_day'] ?? '')
			);
		$parts[] = '<label for="pcrs-age">' . esc_html__('Age group:', 'intersoccer-reports-rosters') . '</label> '
			. intersoccer_player_course_status_admin_select_html(
				'age_group',
				'pcrs-age',
				intersoccer_player_course_status_admin_map_options(
					$filter_options['age_groups'] ?? [],
					(string) ($filters['age_group'] ?? ''),
					__('All age groups', 'intersoccer-reports-rosters')
				),
				(string) ($filters['age_group'] ?? '')
			);
		$parts[] = '<label for="pcrs-booked">' . esc_html__('Booked:', 'intersoccer-reports-rosters') . '</label> '
			. intersoccer_player_course_status_admin_select_html(
				'booked',
				'pcrs-booked',
				[
					'all' => __('All', 'intersoccer-reports-rosters'),
					'booked' => __('Booked only', 'intersoccer-reports-rosters'),
					'not_booked' => __('Not booked only', 'intersoccer-reports-rosters'),
				],
				(string) ($filters['booked'] ?? 'all')
			);
		$parts[] = '<label for="pcrs-status">' . esc_html__('Status:', 'intersoccer-reports-rosters') . '</label> '
			. intersoccer_player_course_status_admin_select_html(
				'booking_status',
				'pcrs-status',
				intersoccer_player_course_status_admin_booking_status_options(),
				(string) ($filters['booking_status'] ?? '')
			);
		$parts[] = '<label for="pcrs-search">' . esc_html__('Search:', 'intersoccer-reports-rosters') . '</label> '
			. '<input type="text" name="search" id="pcrs-search" value="'
			. esc_attr((string) ($filters['search'] ?? ''))
			. '" placeholder="' . esc_attr__('Name or parent email', 'intersoccer-reports-rosters') . '" />';

		return implode("\n", $parts);
	}
}

if (!function_exists('intersoccer_player_course_status_admin_render_page')) {
	/**
	 * Office page. Calls the engine with the request filters and renders the result.
	 *
	 * @return void
	 */
	function intersoccer_player_course_status_admin_render_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
		}

		$filters = intersoccer_player_course_status_request_filters();
		$result = intersoccer_player_course_status_generate($filters);
		$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
		$slice = intersoccer_player_course_status_admin_page_slice($rows, (int) ($filters['paged'] ?? 1));
		$totals = is_array($result['totals'] ?? null) ? $result['totals'] : [];
		$filter_options = is_array($result['filter_options'] ?? null) ? $result['filter_options'] : [];
		$excel_url = intersoccer_player_course_status_admin_export_url($filters);
		?>
		<div class="wrap intersoccer-player-course-status">
			<h1><?php esc_html_e('Player course status', 'intersoccer-reports-rosters'); ?></h1>
			<p><?php esc_html_e('All players from Player Management, with course roster bookings for the selected filters. Pending payment and on-hold orders count as not booked.', 'intersoccer-reports-rosters'); ?></p>

			<p class="description" style="margin-bottom:16px;">
				<?php echo esc_html(intersoccer_player_course_status_admin_totals_text($totals, (int) $slice['total_rows'])); ?>
			</p>

			<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:16px;">
				<input type="hidden" name="page" value="intersoccer-player-course-status" />
				<?php echo intersoccer_player_course_status_admin_filters_html($filters, $filter_options); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>

				<button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'intersoccer-reports-rosters'); ?></button>
				<a class="button" href="<?php echo esc_url($excel_url); ?>"><?php esc_html_e('Export to Excel', 'intersoccer-reports-rosters'); ?></a>
			</form>

			<?php if ((int) $slice['total_pages'] > 1) : ?>
				<div class="tablenav top" style="margin-bottom:8px;">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links([
								'base' => intersoccer_player_course_status_admin_pagination_base($filters),
								'format' => '',
								'current' => (int) $slice['paged'],
								'total' => (int) $slice['total_pages'],
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							])
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<?php if (empty($slice['rows'])) : ?>
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
							<th><?php esc_html_e('Status', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Course bookings', 'intersoccer-reports-rosters'); ?></th>
							<th><?php esc_html_e('Match tier', 'intersoccer-reports-rosters'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php echo intersoccer_player_course_status_admin_rows_html($slice['rows']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php echo intersoccer_player_course_status_admin_unmatched_html($result['unmatched_bookings'] ?? []); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
		</div>
		<?php
	}
}
