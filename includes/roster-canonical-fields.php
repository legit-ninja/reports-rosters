<?php
/**
 * Canonical roster field dual-read helpers (data-model keep/drop map).
 *
 * Prefer keep columns; fall back to legacy drop columns during cleanup transition.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Read a scalar from array or object.
 *
 * @param array|object|null $row Row.
 * @param string            $key Field.
 * @return mixed|null
 */
if (!function_exists('intersoccer_roster_row_get')) {
function intersoccer_roster_row_get($row, $key) {
	if (is_array($row)) {
		return array_key_exists($key, $row) ? $row[$key] : null;
	}
	if (is_object($row)) {
		return isset($row->{$key}) ? $row->{$key} : null;
	}
	return null;
}
}

/**
 * Keep-first then drop fallback; empty string treated as missing.
 *
 * @param array|object|null $row       Row.
 * @param string            $keep_key  Canonical column.
 * @param string            $drop_key  Legacy column.
 * @return string
 */
if (!function_exists('intersoccer_roster_field_keep_first')) {
function intersoccer_roster_field_keep_first($row, $keep_key, $drop_key) {
	$keep = intersoccer_roster_row_get($row, $keep_key);
	if ($keep !== null && trim((string) $keep) !== '') {
		return is_string($keep) ? $keep : (string) $keep;
	}
	$drop = intersoccer_roster_row_get($row, $drop_key);
	if ($drop === null) {
		return '';
	}
	return is_string($drop) ? $drop : (string) $drop;
}
}

/**
 * @param array|object|null $row Roster row.
 * @return string
 */
if (!function_exists('intersoccer_roster_field_player_first_name')) {
function intersoccer_roster_field_player_first_name($row) {
	return intersoccer_roster_field_keep_first($row, 'player_first_name', 'first_name');
}
}

/**
 * @param array|object|null $row Roster row.
 * @return string
 */
if (!function_exists('intersoccer_roster_field_player_last_name')) {
function intersoccer_roster_field_player_last_name($row) {
	return intersoccer_roster_field_keep_first($row, 'player_last_name', 'last_name');
}
}

/**
 * @param array|object|null $row Roster row.
 * @return string
 */
if (!function_exists('intersoccer_roster_field_player_dob')) {
function intersoccer_roster_field_player_dob($row) {
	return intersoccer_roster_field_keep_first($row, 'player_dob', 'dob');
}
}

/**
 * @param array|object|null $row Roster row.
 * @return string
 */
if (!function_exists('intersoccer_roster_field_player_gender')) {
function intersoccer_roster_field_player_gender($row) {
	return intersoccer_roster_field_keep_first($row, 'player_gender', 'gender');
}
}

/**
 * Medical text: keep player_medical, then medical_conditions.
 *
 * @param array|object|null $row Roster row.
 * @return string
 */
if (!function_exists('intersoccer_roster_field_player_medical')) {
function intersoccer_roster_field_player_medical($row) {
	return intersoccer_roster_field_keep_first($row, 'player_medical', 'medical_conditions');
}
}

/**
 * Selected days: keep selected_days, then days_selected.
 *
 * @param array|object|null $row Roster row.
 * @return string
 */
if (!function_exists('intersoccer_roster_field_selected_days')) {
function intersoccer_roster_field_selected_days($row) {
	return intersoccer_roster_field_keep_first($row, 'selected_days', 'days_selected');
}
}

/**
 * Display name from keep names, then player_name, then drop names.
 *
 * @param array|object|null $row Roster row.
 * @return string
 */
if (!function_exists('intersoccer_roster_field_player_display_name')) {
function intersoccer_roster_field_player_display_name($row) {
	$first = intersoccer_roster_field_player_first_name($row);
	$last  = intersoccer_roster_field_player_last_name($row);
	$full  = trim($first . ' ' . $last);
	if ($full !== '') {
		return $full;
	}
	$name = intersoccer_roster_row_get($row, 'player_name');
	if ($name !== null && trim((string) $name) !== '') {
		return trim((string) $name);
	}
	return '';
}
}

/**
 * Ensure keep player/day/medical keys are set on a roster_data array before DB write.
 * Mirrors keep → drop for NOT NULL legacy columns until DROP COLUMN (transitional).
 *
 * @param array $roster_data Builder payload.
 * @return array
 */
if (!function_exists('intersoccer_roster_apply_canonical_write_fields')) {
function intersoccer_roster_apply_canonical_write_fields(array $roster_data) {
	$first = trim((string) ($roster_data['player_first_name'] ?? $roster_data['first_name'] ?? ''));
	$last  = trim((string) ($roster_data['player_last_name'] ?? $roster_data['last_name'] ?? ''));
	$dob   = trim((string) ($roster_data['player_dob'] ?? $roster_data['dob'] ?? ''));
	$gender = trim((string) ($roster_data['player_gender'] ?? $roster_data['gender'] ?? ''));
	$medical = (string) ($roster_data['player_medical'] ?? $roster_data['medical_conditions'] ?? '');

	$roster_data['player_first_name'] = $first !== '' ? $first : 'Unknown';
	$roster_data['player_last_name']  = $last !== '' ? $last : 'Unknown';
	if ($dob !== '') {
		$roster_data['player_dob'] = $dob;
	}
	if ($gender !== '') {
		$roster_data['player_gender'] = $gender;
	}
	$roster_data['player_medical'] = $medical;

	// Transitional mirror into drop columns (NOT NULL / widely read) — source is keep.
	$roster_data['first_name'] = $roster_data['player_first_name'];
	$roster_data['last_name']  = $roster_data['player_last_name'];
	if (!empty($roster_data['player_dob'])) {
		$roster_data['dob'] = $roster_data['player_dob'];
	}
	if (!empty($roster_data['player_gender'])) {
		$roster_data['gender'] = $roster_data['player_gender'];
	}
	$roster_data['medical_conditions'] = $roster_data['player_medical'];

	$sel = trim((string) ($roster_data['selected_days'] ?? ''));
	if ($sel === '' && !empty($roster_data['days_selected'])) {
		$ds  = $roster_data['days_selected'];
		$sel = is_array($ds) ? implode(', ', $ds) : trim((string) $ds);
	}
	$roster_data['selected_days'] = $sel;
	$roster_data['days_selected'] = $sel; // transitional mirror; prefer selected_days

	if (empty($roster_data['camp_terms']) && !empty($roster_data['term'])) {
		// Do not invent camp_terms from course_day stuffed into term — leave camp_terms if set elsewhere.
	}

	if (empty($roster_data['times'])) {
		$roster_data['times'] = $roster_data['camp_times'] ?? $roster_data['course_times'] ?? ($roster_data['times'] ?? '');
	}

	if (!empty($roster_data['player_first_name']) || !empty($roster_data['player_last_name'])) {
		$display = trim($roster_data['player_first_name'] . ' ' . $roster_data['player_last_name']);
		if ($display !== '' && (empty($roster_data['player_name']) || $roster_data['player_name'] === 'Unknown Player')) {
			$roster_data['player_name'] = $display;
		}
	}

	return $roster_data;
}
}

/**
 * Normalize a roster row for Excel/UI export: keep-first into legacy keys Excel still reads.
 *
 * @param array $row Roster row (ARRAY_A).
 * @return array
 */
if (!function_exists('intersoccer_roster_normalize_export_row')) {
function intersoccer_roster_normalize_export_row(array $row) {
	$display = intersoccer_roster_field_player_display_name($row);
	if ($display !== '') {
		$row['player_name'] = $display;
	}
	$row['first_name'] = intersoccer_roster_field_player_first_name($row);
	$row['last_name']  = intersoccer_roster_field_player_last_name($row);

	$gender = intersoccer_roster_field_player_gender($row);
	if ($gender !== '') {
		$row['gender']        = $gender;
		$row['player_gender'] = $gender;
	}

	$dob = intersoccer_roster_field_player_dob($row);
	if ($dob !== '') {
		$row['player_dob'] = $dob;
		$row['dob']        = $dob;
	}

	$sd = intersoccer_roster_field_selected_days($row);
	if ($sd !== '') {
		$row['selected_days'] = $sd;
	}

	$med = intersoccer_roster_field_player_medical($row);
	$row['medical_conditions'] = $med;
	$row['player_medical']     = $med;

	return $row;
}
}
