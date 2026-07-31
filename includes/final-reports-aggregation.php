<?php
/**
 * Pure Final Numbers aggregation helpers (testable without WooCommerce SQL).
 *
 * @package InterSoccer_Reports_Rosters
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('intersoccer_reports_resolve_program_year')) {
    /**
     * Resolve program year for a Final Numbers / report entry.
     *
     * Priority: explicit program_year / Year meta → digits in season → event/start date year.
     *
     * @param array<string,mixed> $entry Roster or report row.
     * @return int|null
     */
    function intersoccer_reports_resolve_program_year(array $entry) {
        foreach (['program_year', 'Year', 'pa_program-year'] as $key) {
            if (!isset($entry[$key]) || $entry[$key] === '' || $entry[$key] === null) {
                continue;
            }
            $raw = trim((string) $entry[$key]);
            if ($raw === '') {
                continue;
            }
            if (preg_match('/\b(20\d{2})\b/', $raw, $m)) {
                return (int) $m[1];
            }
            if (preg_match('/^(19|20)\d{2}$/', $raw)) {
                return (int) $raw;
            }
        }

        $season = '';
        if (!empty($entry['season'])) {
            $season = (string) $entry['season'];
        } elseif (!empty($entry['roster_season'])) {
            $season = (string) $entry['roster_season'];
        }
        if ($season !== '' && function_exists('intersoccer_extract_year_from_season')) {
            $from_season = intersoccer_extract_year_from_season($season);
            if ($from_season !== null) {
                return (int) $from_season;
            }
        }

        foreach (['event_start_date', 'start_date'] as $date_key) {
            if (empty($entry[$date_key])) {
                continue;
            }
            $date = trim((string) $entry[$date_key]);
            if ($date === '' || $date === '1970-01-01' || $date === '0000-00-00' || $date === 'N/A') {
                continue;
            }
            $ts = strtotime($date);
            if ($ts !== false) {
                return (int) date('Y', $ts);
            }
        }

        return null;
    }
}

if (!function_exists('intersoccer_reports_roster_matches_close_year')) {
    /**
     * Whether a roster row should be closed for the requested calendar year.
     *
     * @param array<string,mixed> $row
     * @param int                 $year Required year (must be >= 2000).
     * @return bool
     */
    function intersoccer_reports_roster_matches_close_year(array $row, $year) {
        $year = (int) $year;
        if ($year < 2000) {
            return false;
        }
        $resolved = function_exists('intersoccer_reports_resolve_program_year')
            ? intersoccer_reports_resolve_program_year($row)
            : null;
        return $resolved !== null && (int) $resolved === $year;
    }
}

if (!function_exists('intersoccer_reports_order_status_allowed_for_mode')) {
    /**
     * Whether a WooCommerce order status is counted for Final Numbers mode.
     *
     * @param string $status Order status (with or without wc- prefix).
     * @param string $mode   'final' or 'live'.
     * @return bool
     */
    function intersoccer_reports_order_status_allowed_for_mode($status, $mode = 'final') {
        $status = (string) $status;
        if ($status === '') {
            return false;
        }
        $prefixed = (strpos($status, 'wc-') === 0) ? $status : ('wc-' . $status);
        $allowed = function_exists('intersoccer_reports_final_report_order_statuses')
            ? intersoccer_reports_final_report_order_statuses($mode === 'live' ? 'live' : 'final')
            : ($mode === 'live' ? ['wc-completed', 'wc-processing'] : ['wc-completed']);
        return in_array($prefixed, $allowed, true) || in_array($status, $allowed, true);
    }
}

if (!function_exists('intersoccer_reports_filter_entries_by_season_year')) {
    /**
     * Filter camp/course entries by calendar year and optional season type (e.g. Summer).
     *
     * @param array       $entries     Rows with optional season / event_start_date.
     * @param int|string  $year        Requested year.
     * @param string|null $season_type Optional season type (Summer, Winter, …).
     * @return array
     */
    function intersoccer_reports_filter_entries_by_season_year(array $entries, $year, $season_type = null) {
        $requested_year = intval($year);
        $season_type = $season_type !== null && $season_type !== '' ? (string) $season_type : null;
        $out = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $season = isset($entry['season']) ? (string) $entry['season'] : '';

            if ($season_type !== null) {
                $roster_season_type = $season !== '' && function_exists('intersoccer_extract_season_type')
                    ? intersoccer_extract_season_type($season)
                    : null;
                if ($roster_season_type !== $season_type
                    && ($season === '' || stripos($season, $season_type) === false)) {
                    continue;
                }
            }

            $resolved_year = function_exists('intersoccer_reports_resolve_program_year')
                ? intersoccer_reports_resolve_program_year($entry)
                : null;

            if ($resolved_year === null && $season !== '' && function_exists('intersoccer_extract_year_from_season')) {
                $resolved_year = intersoccer_extract_year_from_season($season);
            }

            if ($resolved_year !== null) {
                if ((int) $resolved_year === $requested_year) {
                    $out[] = $entry;
                }
                continue;
            }

            // Last resort: event/start date when resolver unavailable or empty entry.
            $esd = isset($entry['event_start_date']) ? trim((string) $entry['event_start_date']) : '';
            if ($esd === '' || $esd === '1970-01-01' || $esd === '0000-00-00') {
                $esd = isset($entry['start_date']) ? trim((string) $entry['start_date']) : '';
            }
            if ($esd !== '' && $esd !== '1970-01-01' && $esd !== '0000-00-00') {
                $event_year = intval(date('Y', strtotime($esd)));
                if ($event_year === $requested_year) {
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }
}

if (!function_exists('intersoccer_reports_empty_camp_metrics')) {
    /**
     * Empty Full Day / Mini metrics block for Final Camp reports.
     *
     * @return array{full_week:int,pitchside:int,buyclub:int,individual_days:array<string,int>,min_max:string,unique_records:int}
     */
    function intersoccer_reports_empty_camp_metrics() {
        return [
            'full_week' => 0,
            'pitchside' => 0,
            'buyclub' => 0,
            'individual_days' => [
                'Monday' => 0,
                'Tuesday' => 0,
                'Wednesday' => 0,
                'Thursday' => 0,
                'Friday' => 0,
            ],
            'min_max' => '0-0',
            'unique_records' => 0,
        ];
    }
}

if (!function_exists('intersoccer_reports_aggregate_camp_location_group')) {
    /**
     * Aggregate one canton|venue|camp_type group into Final Numbers metrics.
     *
     * Matches the summer camps numbers workbook columns (Pitchside omitted):
     * Full Week | BuyClub | Individual days (M–F) | Total min-max.
     * BuyClub rows count in buyclub (not full_week / days) so TOTAL / All registrations
     * can use Full Week + BuyClub without double-counting. Use Exclude BuyClub to omit
     * WooCommerce 100% coupon registrations (already paid via buyclub.ch).
     * Min attendees = Full Week + BuyClub; max = that base + the peak single-day weekday.
     *
     * @param array $group           Entries sharing date-range + location + camp type.
     * @param bool  $exclude_buyclub Omit BuyClub / partner discount rows when true.
     * @return array{full_week:int,pitchside:int,buyclub:int,individual_days:array<string,int>,min_max:string,unique_records:int}
     */
    function intersoccer_reports_aggregate_camp_location_group(array $group, $exclude_buyclub = false) {
        $full_week = 0;
        $pitchside = 0; // Reserved — pitchside tooling is out of scope (see reports-rosters-accuracy rule).
        $buyclub = 0;
        $individual_days = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0];
        $processed_count = 0;
        $counted_order_items = [];

        foreach ($group as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (function_exists('intersoccer_reports_row_should_exclude_for_buyclub_option')
                && intersoccer_reports_row_should_exclude_for_buyclub_option($entry, $exclude_buyclub)) {
                continue;
            }
            $oid = isset($entry['order_item_id']) ? (int) $entry['order_item_id'] : 0;
            if ($oid > 0 && isset($counted_order_items[$oid])) {
                continue;
            }
            if ($oid > 0) {
                $counted_order_items[$oid] = true;
            }
            $processed_count++;

            // BuyClub is a separate column (additive), not folded into Full Week / days.
            if (!empty($entry['is_buyclub'])) {
                $buyclub++;
                continue;
            }

            $slug = function_exists('intersoccer_normalize_booking_type_slug_for_reports')
                ? intersoccer_normalize_booking_type_slug_for_reports($entry['booking_type'] ?? '')
                : 'other';
            if ($slug === 'full-week') {
                $full_week++;
            } elseif ($slug === 'single-days') {
                $sd = $entry['selected_days'] ?? '';
                if (is_array($sd)) {
                    $sd = implode(', ', $sd);
                }
                $sd = (string) $sd;
                if ($sd !== '') {
                    $days = array_map('trim', explode(',', $sd));
                    foreach ($days as $day) {
                        if ($day === '') {
                            continue;
                        }
                        $canon = function_exists('intersoccer_normalize_weekday_token')
                            ? intersoccer_normalize_weekday_token($day)
                            : null;
                        if ($canon !== null && isset($individual_days[$canon])) {
                            $individual_days[$canon]++;
                        }
                    }
                }
            }
        }

        // Min = Full Week + BuyClub; max = base + peak single-day weekday count.
        $base = $full_week + $buyclub;
        $peak_singles = !empty($individual_days) ? max(array_values($individual_days)) : 0;
        $min = $base;
        $max = $base + $peak_singles;

        return [
            'full_week' => $full_week,
            'pitchside' => $pitchside,
            'buyclub' => $buyclub,
            'individual_days' => $individual_days,
            'min_max' => "$min-$max",
            'unique_records' => $processed_count,
        ];
    }
}

if (!function_exists('intersoccer_reports_resolve_camp_type_for_entry')) {
    /**
     * @param array $entry
     * @return string Full Day|Mini - Half Day
     */
    function intersoccer_reports_resolve_camp_type_for_entry(array $entry) {
        if (!empty($entry['camp_type'])) {
            return (string) $entry['camp_type'];
        }
        $age_g = $entry['age_group'] ?? '';
        if (!empty($age_g) && (stripos($age_g, '3-5y') !== false || stripos($age_g, 'half-day') !== false)) {
            return 'Mini - Half Day';
        }
        return 'Full Day';
    }
}

if (!function_exists('intersoccer_reports_resolve_facet_label')) {
    /**
     * @param string $raw
     * @param string $taxonomy
     * @param string $fallback
     * @return string
     */
    function intersoccer_reports_resolve_facet_label($raw, $taxonomy, $fallback = 'Unknown') {
        $raw = is_string($raw) ? $raw : '';
        $label = $raw !== '' ? $raw : $fallback;
        // Keep golden PHPUnit fixtures deterministic without WPML term lookups.
        if (defined('INTERSOCCER_TESTING') && INTERSOCCER_TESTING) {
            return $label !== '' ? $label : $fallback;
        }
        // Display labels: use title-case weekday / term names — NOT facet_for_grouping
        // (that helper lowercases canonical tokens for hash keys only).
        if ($taxonomy === 'pa_course-day' && function_exists('intersoccer_normalize_weekday_token') && $raw !== '') {
            $day = intersoccer_normalize_weekday_token($raw);
            if ($day) {
                return $day;
            }
        }
        if (function_exists('intersoccer_get_term_name') && $raw !== '') {
            $name = intersoccer_get_term_name($raw, $taxonomy);
            if ($name !== '' && $name !== 'N/A') {
                return $name;
            }
        }
        return $label !== '' ? $label : $fallback;
    }
}

if (!function_exists('intersoccer_reports_build_camp_report_from_entries')) {
    /**
     * Build camp Final Numbers report_data from normalized entry rows (dates already preferred).
     *
     * @param array $entries         Filtered roster-like rows.
     * @param bool  $exclude_buyclub BuyClub exclusion.
     * @param int   $year            Fallback year for undated post_date grouping.
     * @return array
     */
    function intersoccer_reports_build_camp_report_from_entries(array $entries, $exclude_buyclub = false, $year = null) {
        if ($year === null) {
            $year = (int) date('Y');
        }
        $year = intval($year);

        // Ensure camp_type / is_buyclub for registration totals + grouping.
        foreach ($entries as &$roster) {
            if (!is_array($roster)) {
                continue;
            }
            $age_group = $roster['age_group'] ?? '';
            if (empty($roster['camp_type'])) {
                $roster['camp_type'] = (!empty($age_group) && (stripos($age_group, '3-5y') !== false || stripos($age_group, 'half-day') !== false))
                    ? 'Mini - Half Day'
                    : 'Full Day';
            }
            if (!isset($roster['is_buyclub'])) {
                $line_subtotal = floatval($roster['line_subtotal'] ?? 0);
                $line_total = floatval($roster['line_total'] ?? 0);
                $roster['is_buyclub'] = $line_subtotal > 0 && $line_total === 0.0;
            }
        }
        unset($roster);

        $player_registration_totals = function_exists('intersoccer_reports_compute_camp_registration_totals')
            ? intersoccer_reports_compute_camp_registration_totals($entries, $exclude_buyclub)
            : ['full_day' => 0, 'mini' => 0, 'all' => 0];

        $report_data = [];
        $date_groups = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $parsed_from_terms_start = null;
            $parsed_from_terms_end = null;
            $esd = isset($entry['event_start_date']) ? trim((string) $entry['event_start_date']) : '';
            if ($esd === '' || $esd === '1970-01-01' || $esd === '0000-00-00') {
                $esd = isset($entry['start_date']) ? trim((string) $entry['start_date']) : '';
            }
            $ct = isset($entry['camp_terms']) ? trim((string) $entry['camp_terms']) : '';
            if ($ct !== '' && $ct !== 'N/A' && function_exists('intersoccer_reports_normalize_camp_terms_for_dates')) {
                $ct = intersoccer_reports_normalize_camp_terms_for_dates($ct);
            }
            if (($esd === '' || $esd === '1970-01-01' || $esd === '0000-00-00')) {
                $vid = isset($entry['variation_id']) ? (int) $entry['variation_id'] : 0;
                $oid = isset($entry['order_item_id']) ? (int) $entry['order_item_id'] : 0;
                if (function_exists('intersoccer_reports_resolve_camp_schedule') && ($vid > 0 || $ct !== '')) {
                    $season_for_parse = $entry['season'] ?? '';
                    list($parsed_from_terms_start, $parsed_from_terms_end) = intersoccer_reports_resolve_camp_schedule(
                        $vid,
                        $oid,
                        $ct,
                        $season_for_parse
                    );
                    if (!empty($parsed_from_terms_start) && $parsed_from_terms_start !== '1970-01-01' && $parsed_from_terms_start !== '0000-00-00') {
                        $esd = $parsed_from_terms_start;
                    }
                } elseif ($ct !== '' && $ct !== 'N/A' && function_exists('intersoccer_parse_camp_dates_fixed')) {
                    $season_for_parse = $entry['season'] ?? '';
                    list($parsed_from_terms_start, $parsed_from_terms_end, $_ignored_ev) = intersoccer_parse_camp_dates_fixed($ct, $season_for_parse);
                    if (!empty($parsed_from_terms_start) && $parsed_from_terms_start !== '1970-01-01' && $parsed_from_terms_start !== '0000-00-00') {
                        $esd = $parsed_from_terms_start;
                    }
                }
            }
            if ($esd === '' || $esd === '1970-01-01' || $esd === '0000-00-00') {
                $undated_label = isset($entry['undated_group_label']) ? trim((string) $entry['undated_group_label']) : '';
                if ($undated_label === '' && $ct !== '' && $ct !== 'N/A') {
                    $undated_label = $ct;
                }
                if ($undated_label !== '' && function_exists('intersoccer_reports_normalize_camp_terms_for_dates')) {
                    $undated_label = intersoccer_reports_normalize_camp_terms_for_dates($undated_label);
                }
                if ($undated_label !== '') {
                    $date_range_key = $undated_label;
                    $post_date = isset($entry['post_date']) ? trim((string) $entry['post_date']) : '';
                    $esd = ($post_date !== '' && strtotime($post_date))
                        ? date('Y-m-d', strtotime($post_date))
                        : ($year . '-12-31');
                    $eed = $esd;
                    if (!isset($date_groups[$date_range_key])) {
                        $date_groups[$date_range_key] = [
                            'start_date' => $esd,
                            'end_date' => $eed,
                            'entries' => [],
                        ];
                    }
                    $date_groups[$date_range_key]['entries'][] = $entry;
                    continue;
                }
                continue;
            }
            $eed = isset($entry['event_end_date']) ? trim((string) $entry['event_end_date']) : '';
            if ($eed === '' || $eed === '1970-01-01' || $eed === '0000-00-00') {
                $eed = isset($entry['end_date']) ? trim((string) $entry['end_date']) : '';
            }
            if (($eed === '' || $eed === '1970-01-01' || $eed === '0000-00-00')
                && !empty($parsed_from_terms_end) && $parsed_from_terms_end !== '1970-01-01' && $parsed_from_terms_end !== '0000-00-00') {
                $eed = $parsed_from_terms_end;
            }
            if ($eed === '' || $eed === '1970-01-01' || $eed === '0000-00-00') {
                $eed = $esd;
            }
            $esd_ts_norm = strtotime($esd);
            $eed_ts_norm = strtotime($eed);
            if ($esd_ts_norm !== false && $eed_ts_norm !== false && $eed_ts_norm < $esd_ts_norm) {
                $tmp_date = $esd;
                $esd = $eed;
                $eed = $tmp_date;
            }

            $event_start = strtotime($esd);
            $event_end = strtotime($eed);
            if ($event_start === false) {
                continue;
            }
            if ($event_end === false) {
                $event_end = $event_start;
                $eed = $esd;
            }

            // Match production: "Month Day - Month Day, {requested year}" (or single-day form).
            $start_date_obj = new DateTime($esd);
            $end_date_obj = new DateTime($eed);
            $requested_year = (string) $year;
            $start_formatted = $start_date_obj->format('F j');
            $end_formatted = $end_date_obj->format('F j') . ', ' . $requested_year;
            $date_range_key = $start_formatted . ' - ' . $end_formatted;
            if ($start_date_obj->format('Y-m-d') === $end_date_obj->format('Y-m-d')) {
                $date_range_key = $start_date_obj->format('F j') . ', ' . $requested_year;
            }

            if (!isset($date_groups[$date_range_key])) {
                $date_groups[$date_range_key] = [
                    'start_date' => $year . '-' . $start_date_obj->format('m-d'),
                    'end_date' => $year . '-' . $end_date_obj->format('m-d'),
                    'entries' => [],
                ];
            }
            $date_groups[$date_range_key]['entries'][] = $entry;
        }

        uasort($date_groups, function ($a, $b) {
            return strtotime($a['start_date']) - strtotime($b['start_date']);
        });

        foreach ($date_groups as $date_range_key => $date_group) {
            $group_entries = $date_group['entries'];
            $location_groups = [];
            foreach ($group_entries as $entry) {
                $canton = intersoccer_reports_resolve_facet_label($entry['canton'] ?? 'Unknown', 'pa_canton-region', 'Unknown');
                $venue = intersoccer_reports_resolve_facet_label($entry['venue'] ?? 'Unknown', 'pa_intersoccer-venues', 'Unknown');
                $camp_type = intersoccer_reports_resolve_camp_type_for_entry($entry);
                $is_girls = !empty($entry['girls_only'])
                    || (function_exists('intersoccer_text_indicates_girls_only')
                        && (intersoccer_text_indicates_girls_only($entry['activity_type'] ?? '')
                            || intersoccer_text_indicates_girls_only($entry['product_name'] ?? '')));
                $venue_display = $venue . ($is_girls ? ' (Girls Only)' : '');
                $key = "$canton|$venue_display|$camp_type";
                $location_groups[$key][] = $entry;
            }

            $report_data[$date_range_key] = [];
            foreach ($location_groups as $key => $group) {
                $key_parts = explode('|', $key, 3);
                $canton = $key_parts[0] ?? 'Unknown';
                $venue = $key_parts[1] ?? 'Unknown';
                $camp_type = $key_parts[2] ?? 'Full Day';
                $report_data[$date_range_key][$canton][$venue][$camp_type] = intersoccer_reports_aggregate_camp_location_group($group, $exclude_buyclub);
            }
        }

        $report_data['__player_registration_totals__'] = $player_registration_totals;
        return $report_data;
    }
}

if (!function_exists('intersoccer_reports_build_course_report_from_entries')) {
    /**
     * Build course Final Numbers report_data from normalized entry rows.
     *
     * @param array $entries
     * @param bool  $exclude_buyclub
     * @return array
     */
    function intersoccer_reports_build_course_report_from_entries(array $entries, $exclude_buyclub = false) {
        foreach ($entries as &$roster) {
            if (!is_array($roster)) {
                continue;
            }
            if (!isset($roster['is_buyclub'])) {
                $line_subtotal = floatval($roster['line_subtotal'] ?? 0);
                $line_total = floatval($roster['line_total'] ?? 0);
                $roster['is_buyclub'] = $line_subtotal > 0 && $line_total === 0.0;
            }
            $cd_row = isset($roster['course_day']) ? trim((string) $roster['course_day']) : '';
            $roster['course_day'] = ($cd_row === '') ? 'Unknown' : $cd_row;
        }
        unset($roster);

        $course_player_registration_totals = [
            'all' => function_exists('intersoccer_reports_compute_course_registration_total')
                ? intersoccer_reports_compute_course_registration_total($entries, $exclude_buyclub)
                : 0,
        ];

        $report_data = [];
        $seen_course_roster_ids = [];
        $seen_course_order_items_no_roster = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (function_exists('intersoccer_reports_row_should_exclude_for_buyclub_option')
                && intersoccer_reports_row_should_exclude_for_buyclub_option($entry, $exclude_buyclub)) {
                continue;
            }

            $rrid = isset($entry['roster_row_id']) ? (int) $entry['roster_row_id'] : 0;
            if ($rrid > 0) {
                if (isset($seen_course_roster_ids[$rrid])) {
                    continue;
                }
                $seen_course_roster_ids[$rrid] = true;
            } else {
                $coi = isset($entry['order_item_id']) ? (int) $entry['order_item_id'] : 0;
                if ($coi > 0) {
                    if (isset($seen_course_order_items_no_roster[$coi])) {
                        continue;
                    }
                    $seen_course_order_items_no_roster[$coi] = true;
                }
            }

            $entry_region = intersoccer_reports_resolve_facet_label($entry['canton'] ?? 'Unknown', 'pa_canton-region', 'Unknown');
            $venue_raw = isset($entry['venue']) ? trim((string) $entry['venue']) : '';
            $venue = intersoccer_reports_resolve_facet_label($venue_raw !== '' ? $venue_raw : 'Unknown', 'pa_intersoccer-venues', 'Unknown');
            $product_id = isset($entry['product_id']) ? (int) $entry['product_id'] : 0;
            $variation_id = isset($entry['variation_id']) ? (int) $entry['variation_id'] : 0;
            $order_item_name = isset($entry['order_item_name']) ? (string) $entry['order_item_name'] : '';
            if (defined('INTERSOCCER_TESTING') && INTERSOCCER_TESTING) {
                $course_name = $order_item_name !== '' ? $order_item_name : ('Course #' . ($variation_id > 0 ? $variation_id : $product_id));
            } elseif (function_exists('intersoccer_reports_final_course_display_name')) {
                $course_name = intersoccer_reports_final_course_display_name($product_id, $variation_id, $order_item_name);
            } elseif ($product_id && function_exists('get_the_title')) {
                $course_name = (string) get_the_title($product_id);
            } else {
                $course_name = $order_item_name !== '' ? $order_item_name : 'Unknown';
            }
            $cd_disp = isset($entry['course_day']) ? trim((string) $entry['course_day']) : '';
            $course_day = ($cd_disp === '') ? 'Unknown' : intersoccer_reports_resolve_facet_label($cd_disp, 'pa_course-day', $cd_disp);

            if (!isset($report_data[$entry_region])) {
                $report_data[$entry_region] = [];
            }
            if (!isset($report_data[$entry_region][$venue])) {
                $report_data[$entry_region][$venue] = [];
            }

            $identity_id = $variation_id > 0 ? $variation_id : $product_id;
            $row_key = $identity_id . '|' . $course_day;
            if (!isset($report_data[$entry_region][$venue][$row_key])) {
                $report_data[$entry_region][$venue][$row_key] = [
                    'course_name' => $course_name,
                    'course_day' => $course_day,
                    'times' => [],
                    'registrations' => 0,
                ];
            }

            $times_disp = isset($entry['times']) ? trim((string) $entry['times']) : '';
            if ($times_disp !== '') {
                $times_key = intersoccer_reports_resolve_facet_label($times_disp, 'pa_camp-times', $times_disp);
                if ($times_key === $times_disp) {
                    $times_key = intersoccer_reports_resolve_facet_label($times_disp, 'pa_course-times', $times_disp);
                }
                $report_data[$entry_region][$venue][$row_key]['times'][$times_key] = true;
            }

            $report_data[$entry_region][$venue][$row_key]['registrations']++;
        }

        foreach ($report_data as $region_key => $venues_data) {
            foreach ($venues_data as $venue_key => $courses_data) {
                foreach ($courses_data as $row_key => $course_metrics) {
                    $times_keys = array_keys($course_metrics['times'] ?? []);
                    sort($times_keys, SORT_NATURAL | SORT_FLAG_CASE);
                    $report_data[$region_key][$venue_key][$row_key]['times'] = !empty($times_keys) ? implode(', ', $times_keys) : '-';
                }
                uasort($report_data[$region_key][$venue_key], static function ($a, $b) {
                    $name_cmp = strcasecmp((string) ($a['course_name'] ?? ''), (string) ($b['course_name'] ?? ''));
                    if ($name_cmp !== 0) {
                        return $name_cmp;
                    }
                    return strcasecmp((string) ($a['course_day'] ?? ''), (string) ($b['course_day'] ?? ''));
                });
            }
        }

        $report_data['__player_registration_totals__'] = $course_player_registration_totals;
        return $report_data;
    }
}

if (!function_exists('intersoccer_reports_parse_min_max_max')) {
	/**
	 * Max side of a Final Camp "min-max" headcount string (heatmap / urgency score).
	 *
	 * @param string $min_max e.g. "12-18".
	 * @return int Max value, or 0 if unparseable.
	 */
	function intersoccer_reports_parse_min_max_max($min_max) {
		$min_max = trim((string) $min_max);
		if ($min_max === '' || strpos($min_max, '-') === false) {
			return 0;
		}
		$parts = explode('-', $min_max, 2);
		if (count($parts) < 2 || !is_numeric(trim($parts[1]))) {
			return 0;
		}
		return (int) trim($parts[1]);
	}
}

if (!function_exists('intersoccer_reports_urgency_band')) {
	/**
	 * Urgency CSS class for a headcount (same thresholds as roster count badges).
	 *
	 * @param int $count Participant / peak headcount.
	 * @return string count-critical|count-low|count-good|count-optimal
	 */
	function intersoccer_reports_urgency_band($count) {
		if (function_exists('intersoccer_get_count_class')) {
			return intersoccer_get_count_class($count);
		}
		$count = (int) $count;
		if ($count <= 7) {
			return 'count-critical';
		}
		if ($count <= 20) {
			return 'count-low';
		}
		if ($count <= 29) {
			return 'count-good';
		}
		return 'count-optimal';
	}
}

if (!function_exists('intersoccer_reports_urgency_band_label')) {
	/**
	 * Human label for an urgency band.
	 *
	 * @param string $band CSS class from intersoccer_reports_urgency_band().
	 * @return string
	 */
	function intersoccer_reports_urgency_band_label($band) {
		$map = [
			'count-critical' => function_exists('__')
				? __('Critical (≤7)', 'intersoccer-reports-rosters')
				: 'Critical (≤7)',
			'count-low' => function_exists('__')
				? __('Low (≤20)', 'intersoccer-reports-rosters')
				: 'Low (≤20)',
			'count-good' => function_exists('__')
				? __('Good (≤29)', 'intersoccer-reports-rosters')
				: 'Good (≤29)',
			'count-optimal' => function_exists('__')
				? __('Optimal (30+)', 'intersoccer-reports-rosters')
				: 'Optimal (30+)',
		];
		return $map[$band] ?? (string) $band;
	}
}

if (!function_exists('intersoccer_reports_urgency_band_argb')) {
	/**
	 * Solid fill ARGB for PhpSpreadsheet urgency heat cells.
	 *
	 * @param string $band CSS class from intersoccer_reports_urgency_band().
	 * @return string 8-char ARGB without #.
	 */
	function intersoccer_reports_urgency_band_argb($band) {
		$map = [
			'count-critical' => 'FFDC2626',
			'count-low' => 'FFD97706',
			'count-good' => 'FF059669',
			'count-optimal' => 'FF1D4ED8',
		];
		return $map[$band] ?? 'FF6B7280';
	}
}

if (!function_exists('intersoccer_reports_is_urgent_band')) {
	/**
	 * Whether a band is Critical or Low (marketing focus).
	 *
	 * @param string $band CSS class from intersoccer_reports_urgency_band().
	 * @return bool
	 */
	function intersoccer_reports_is_urgent_band($band) {
		return in_array($band, ['count-critical', 'count-low'], true);
	}
}

if (!function_exists('intersoccer_reports_camp_metrics_urgency_band')) {
	/**
	 * Urgency band for one camp Full Day / Mini metrics block (max of min-max).
	 *
	 * @param array $data Camp metrics with min_max.
	 * @return string
	 */
	function intersoccer_reports_camp_metrics_urgency_band(array $data) {
		$max = intersoccer_reports_parse_min_max_max($data['min_max'] ?? '');
		return intersoccer_reports_urgency_band($max);
	}
}

if (!function_exists('intersoccer_reports_camp_venue_is_urgent')) {
	/**
	 * True if any camp type at a venue is Critical or Low.
	 *
	 * @param array $camp_types Map of camp type => metrics.
	 * @return bool
	 */
	function intersoccer_reports_camp_venue_is_urgent(array $camp_types) {
		foreach ($camp_types as $data) {
			if (!is_array($data)) {
				continue;
			}
			if (intersoccer_reports_is_urgent_band(intersoccer_reports_camp_metrics_urgency_band($data))) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('intersoccer_reports_course_row_is_urgent')) {
	/**
	 * True if a course row registrations count is Critical or Low.
	 *
	 * @param array $course_data Course metrics with registrations.
	 * @return bool
	 */
	function intersoccer_reports_course_row_is_urgent(array $course_data) {
		$regs = isset($course_data['registrations']) ? (int) $course_data['registrations'] : 0;
		return intersoccer_reports_is_urgent_band(intersoccer_reports_urgency_band($regs));
	}
}

if (!function_exists('intersoccer_reports_normalize_camp_metrics')) {
    /**
     * Ensure camp metrics include pitchside/buyclub keys for UI/export.
     *
     * @param array|null $data Metrics or null.
     * @return array
     */
    function intersoccer_reports_normalize_camp_metrics($data) {
        $empty = intersoccer_reports_empty_camp_metrics();
        if (!is_array($data)) {
            return $empty;
        }
        return [
            'full_week' => (int) ($data['full_week'] ?? 0),
            'pitchside' => (int) ($data['pitchside'] ?? 0),
            'buyclub' => (int) ($data['buyclub'] ?? 0),
            'individual_days' => array_merge(
                $empty['individual_days'],
                is_array($data['individual_days'] ?? null) ? $data['individual_days'] : []
            ),
            'min_max' => (string) ($data['min_max'] ?? '0-0'),
            'unique_records' => (int) ($data['unique_records'] ?? 0),
        ];
    }
}

if (!function_exists('intersoccer_reports_camp_grand_totals')) {
    /**
     * Grand TOTAL / All registrations matching the summer camps numbers workbook.
     *
     * TOTAL: sum Full Week, BuyClub; Individual days = sum of all M–F cells.
     * All registrations: Full Week + BuyClub; Individual days = that slot sum.
     * Pitchside is intentionally omitted from Final Camp Numbers.
     *
     * @param array $report_data Camp report_data (without __player_registration_totals__).
     * @param bool  $urgency_only When true, only count venues that pass urgency filter.
     * @return array
     */
    function intersoccer_reports_camp_grand_totals(array $report_data, $urgency_only = false) {
        $blank = [
            'full_week' => 0,
            'buyclub' => 0,
            'individual_day_slots' => 0,
        ];
        $out = [
            'full_day' => $blank,
            'mini' => $blank,
        ];

        foreach ($report_data as $week_name => $cantons) {
            if ($week_name === '__player_registration_totals__' || !is_array($cantons)) {
                continue;
            }
            foreach ($cantons as $canton => $venues) {
                if (!is_array($venues)) {
                    continue;
                }
                foreach ($venues as $venue => $camp_types) {
                    if (!is_array($camp_types)) {
                        continue;
                    }
                    if ($urgency_only && function_exists('intersoccer_reports_camp_venue_is_urgent')
                        && !intersoccer_reports_camp_venue_is_urgent($camp_types)) {
                        continue;
                    }
                    foreach (['Full Day' => 'full_day', 'Mini - Half Day' => 'mini'] as $type_key => $bucket) {
                        $m = intersoccer_reports_normalize_camp_metrics($camp_types[$type_key] ?? null);
                        $out[$bucket]['full_week'] += $m['full_week'];
                        $out[$bucket]['buyclub'] += $m['buyclub'];
                        $out[$bucket]['individual_day_slots'] += (int) array_sum($m['individual_days']);
                    }
                }
            }
        }

        $out['full_day']['all_registrations'] = $out['full_day']['full_week'] + $out['full_day']['buyclub'];
        $out['mini']['all_registrations'] = $out['mini']['full_week'] + $out['mini']['buyclub'];

        return $out;
    }
}

if (!function_exists('intersoccer_reports_camp_excel_data_rows')) {
	/**
	 * Flat Excel body rows matching Final Camp Reports export (without header/footer).
	 *
	 * @param array $report_data Camp report_data from aggregation.
	 * @param bool  $urgency_only When true, omit rows whose max(min-max) is Good/Optimal.
	 * @return array<int,array{0:string,1:string,2:string,3:string,4:int,5:int,6:int,7:int,8:int,9:int,10:string,11:int,12:string}>
	 */
	function intersoccer_reports_camp_excel_data_rows(array $report_data, $urgency_only = false) {
		$rows = [];
		foreach ($report_data as $week_name => $cantons) {
			if ($week_name === '__player_registration_totals__' || !is_array($cantons)) {
				continue;
			}
			foreach ($cantons as $canton => $venues) {
				if (!is_array($venues)) {
					continue;
				}
				foreach ($venues as $venue => $camp_types) {
					if (!is_array($camp_types)) {
						continue;
					}
					foreach ($camp_types as $camp_type => $data) {
						if (!is_array($data) || !isset($data['full_week'], $data['individual_days'])) {
							continue;
						}
						$band = intersoccer_reports_camp_metrics_urgency_band($data);
						if ($urgency_only && !intersoccer_reports_is_urgent_band($band)) {
							continue;
						}
						$days = $data['individual_days'];
						$m = function_exists('intersoccer_reports_normalize_camp_metrics')
							? intersoccer_reports_normalize_camp_metrics($data)
							: $data;
						$all_reg = (int) ($m['full_week'] ?? 0) + (int) ($m['buyclub'] ?? 0);
						$rows[] = [
							$week_name,
							$canton,
							$venue,
							$camp_type,
							(int) ($m['full_week'] ?? 0),
							(int) ($m['buyclub'] ?? 0),
							(int) ($days['Monday'] ?? 0),
							(int) ($days['Tuesday'] ?? 0),
							(int) ($days['Wednesday'] ?? 0),
							(int) ($days['Thursday'] ?? 0),
							(int) ($days['Friday'] ?? 0),
							(string) ($m['min_max'] ?? ''),
							$all_reg,
							intersoccer_reports_urgency_band_label($band),
						];
					}
				}
			}
		}
		return $rows;
	}
}
