<?php
/**
 * Shared order item meta key aliases (FR/DE/EN) for rosters and Final Reports.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Canonical English label => roster/internal field name (same as RosterBuilder::$order_meta_field_map).
 *
 * @return array<string,string>
 */
function intersoccer_get_order_meta_field_map() {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [
        'InterSoccer Venues' => 'venue',
        'Age Group' => 'age_group',
        'Camp Terms' => 'event_type',
        'Camp Times' => 'camp_times',
        'Course Day' => 'course_day',
        'Course Times' => 'course_times',
        'Booking Type' => 'booking_type',
        'Assigned Attendee' => 'assigned_attendee',
        'Player Index' => 'player_index',
        'Days Selected' => 'selected_days',
        'Season' => 'season',
        'Canton / Region' => 'region',
        'City' => 'city',
        'Activity Type' => 'activity_type',
        'Start Date' => 'start_date',
        'End Date' => 'end_date',
        'Holidays' => 'holidays',
        'Discount' => 'discount_applied',
        'Discount Amount' => 'discount_amount',
        'Base Price' => 'base_price',
        'Remaining Sessions' => 'remaining_sessions',
        'Late Pickup Type' => 'late_pickup_type',
        'Late Pickup Days' => 'late_pickup_days',
        'Late Pickup Cost' => 'late_pickup_cost',
    ];
    return $map;
}

/**
 * Manual normalized-string aliases per canonical English meta label.
 *
 * @return array<string,array<int,string>>
 */
function intersoccer_get_order_meta_manual_aliases() {
    return [
        'InterSoccer Venues' => [
            'lieux intersoccer',
            'lieu intersoccer',
            'intersoccer-standorte',
            'sites intersoccer',
        ],
        'Age Group' => [
            'groupe dage',
            'groupe d age',
            'groupe d\'âge',
            'altersgruppe',
        ],
        'Camp Terms' => [
            'conditions de camp',
            'camp begriffe',
        ],
        'Camp Times' => [
            'horaires du camp',
            'camp zeiten',
        ],
        'Course Day' => [
            'jour de cours',
            'kurstag',
        ],
        'Course Times' => [
            'horaires du cours',
            'kurszeiten',
        ],
        'Booking Type' => [
            'type de réservation',
            'buchungstyp',
            'pa booking type',
            'attribute pa booking type',
        ],
        'Assigned Attendee' => [
            'participant assigné',
            'zugewiesener teilnehmer',
        ],
        'Days Selected' => [
            'jours sélectionnés',
            'ausgewählte tage',
            // Do not map variation attribute "Days of week" / pa_days-of-week here: that is usually Mon–Fri for
            // the product, not the customer's checkout selection (would overwrite real "Days Selected").
            // WooCommerce often stores snake_case key "selected_days" → normalized "selected days"
            'selected days',
            'days selected',
        ],
        'Season' => [
            'saison',
            'saison (programm)',
            'jahreszeit',
        ],
        'Canton / Region' => [
            'canton region',
            'canton / région',
            'kanton region',
        ],
        'City' => [
            'ville',
            'stadt',
        ],
        'Activity Type' => [
            'type d activite',
            'type d\'activite',
            'type d’activité',
            'type d\'activité',
            'aktivitätstyp',
        ],
        'Start Date' => [
            'date de début',
            'startdatum',
        ],
        'End Date' => [
            'date de fin',
            'enddatum',
        ],
        'Holidays' => [
            'vacances',
            'ferien',
        ],
        'Discount' => [
            'remise',
            'rabatt',
        ],
        'Discount Amount' => [
            'montant de la remise',
            'rabattbetrag',
        ],
        'Base Price' => [
            'prix de base',
            'grundpreis',
        ],
        'Remaining Sessions' => [
            'séances restantes',
            'verbleibende termine',
        ],
        'Late Pickup Type' => [
            'type de ramassage tardif',
            'späte abholung typ',
        ],
        'Late Pickup Days' => [
            'jours de ramassage tardif',
            'tage für späte abholung',
        ],
        'Late Pickup Cost' => [
            'coût ramassage tardif',
            'kosten späte abholung',
        ],
        'Variation ID' => [
            'id de variation',
            'varianten id',
        ],
    ];
}

/**
 * @param string $value
 * @return string
 */
function intersoccer_order_meta_normalize_comparison_string($value) {
    if (function_exists('intersoccer_normalize_comparison_string')) {
        return intersoccer_normalize_comparison_string($value);
    }
    $normalized = strtolower(trim((string) $value));
    if (function_exists('remove_accents')) {
        $normalized = remove_accents($normalized);
    } elseif (function_exists('iconv')) {
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $normalized);
        if ($trans !== false) {
            $normalized = $trans;
        }
    }
    $normalized = str_replace(['_', '-'], ' ', $normalized);
    $normalized = preg_replace('/[^a-z0-9\/ ]+/u', '', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return trim($normalized);
}

/**
 * Build full variant map: canonical English label => list of normalized comparison strings.
 *
 * @return array<string,array<int,string>>
 */
function intersoccer_order_meta_build_variants_array() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $field_map = intersoccer_get_order_meta_field_map();
    $manual = intersoccer_get_order_meta_manual_aliases();

    $variants = [];
    foreach (array_keys($field_map) as $canonical) {
        $variants[$canonical] = [intersoccer_order_meta_normalize_comparison_string($canonical)];
    }

    foreach ($manual as $canonical => $aliases) {
        foreach ($aliases as $alias) {
            $variants[$canonical][] = intersoccer_order_meta_normalize_comparison_string($alias);
        }
    }

    $can_switch_language = function_exists('wpml_get_active_languages')
        && function_exists('wpml_get_current_language')
        && function_exists('icl_t');

    if ($can_switch_language) {
        $active_languages = wpml_get_active_languages();
        $original_language = wpml_get_current_language();
        if (!empty($active_languages)) {
            foreach (array_keys($active_languages) as $language_code) {
                do_action('wpml_switch_language', $language_code);
                foreach (array_keys($field_map) as $canonical) {
                    $translated = icl_t('intersoccer-product-variations', $canonical, $canonical);
                    if (!empty($translated)) {
                        $variants[$canonical][] = intersoccer_order_meta_normalize_comparison_string($translated);
                    }
                }
            }
            if (!empty($original_language)) {
                do_action('wpml_switch_language', $original_language);
            }
        }
    }

    foreach ($variants as &$variant_list) {
        $variant_list = array_values(array_unique(array_filter($variant_list)));
    }
    unset($variant_list);

    $cached = $variants;
    return $cached;
}

/**
 * Map a raw WooCommerce order item meta key to canonical English (matches RosterBuilder::normalizeOrderMetaKey).
 *
 * @param string $raw_key
 * @return string
 */
function intersoccer_normalize_order_item_meta_key($raw_key) {
    static $cache = [];
    if ($raw_key === '') {
        return $raw_key;
    }
    if (isset($cache[$raw_key])) {
        return $cache[$raw_key];
    }

    $comparison_value = intersoccer_order_meta_normalize_comparison_string($raw_key);
    foreach (intersoccer_order_meta_build_variants_array() as $canonical => $variants) {
        if (in_array($comparison_value, $variants, true)) {
            $cache[$raw_key] = $canonical;
            return $canonical;
        }
    }

    $cache[$raw_key] = $raw_key;
    return $raw_key;
}

/**
 * Canonical booking type labels for roster/Final Reports (aligned with RosterBuilder::normalizeBookingTypeValue).
 *
 * @param mixed $value
 * @return string
 */
function intersoccer_normalize_booking_type_label_for_reports($value) {
    if (!is_string($value) || $value === '') {
        return (string) $value;
    }
    $normalized = intersoccer_order_meta_normalize_comparison_string($value);
    if (strpos($normalized, 'full week') !== false || strpos($normalized, 'journee complete') !== false
        || strpos($normalized, 'ganze woche') !== false) {
        return 'Full Week';
    }
    if (strpos($normalized, 'single day') !== false || strpos($normalized, 'jours selectionnes') !== false
        || strpos($normalized, 'a la journee') !== false || strpos($normalized, 'la journee') !== false
        || strpos($normalized, 'ausgewahlte tage') !== false || strpos($normalized, 'einzeltag') !== false) {
        return 'Single Day(s)';
    }
    if (strpos($normalized, 'full term') !== false || strpos($normalized, 'trimestre') !== false
        || strpos($normalized, 'voller begriff') !== false) {
        return 'Full Term';
    }
    if (in_array(trim($value), ['Full Week', 'Single Day(s)', 'Full Term'], true)) {
        return trim($value);
    }
    return trim($value);
}

/**
 * Normalize selected days to English weekday names (comma-separated).
 *
 * @param array|string|null $value
 * @return string
 */
function intersoccer_normalize_selected_days_string_for_reports($value) {
    if ($value === null || $value === '') {
        return '';
    }
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = preg_split('/[,;\/|\s]+/u', (string) $value) ?: [];
    }
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p === '') {
            continue;
        }
        $c = function_exists('intersoccer_normalize_weekday_token') ? intersoccer_normalize_weekday_token($p) : null;
        if ($c) {
            $out[] = $c;
        }
    }
    $out = array_unique($out);
    return implode(', ', $out);
}

if (!function_exists('intersoccer_roster_effective_selected_days_string')) {
    /**
     * Effective camp selected-days string for roster UI and Excel export (object row: stdClass from DB/OOP).
     *
     * When event_details lists fewer canonical weekdays than the roster column, prefer event_details (column is often
     * denormalized to all five days; checkout JSON usually matches Single Day(s) choices).
     *
     * @param object $row Roster row with optional selected_days, days_selected, event_details.
     * @return string
     */
    function intersoccer_roster_effective_selected_days_string($row) {
        $to_str = static function ($v) {
            if (is_array($v)) {
                return implode(', ', $v);
            }
            return trim((string) $v);
        };

        $canonical_day_count = static function ($s) {
            if ($s === '' || !function_exists('intersoccer_normalize_selected_days_string_for_reports')) {
                return 0;
            }
            $n = trim(intersoccer_normalize_selected_days_string_for_reports($s));
            if ($n === '') {
                return 0;
            }
            return count(array_filter(array_map('trim', explode(',', $n))));
        };

        $from_ed = '';
        if (!empty($row->event_details)) {
            $ed = $row->event_details;
            if (is_string($ed)) {
                $ed = json_decode($ed, true);
            }
            if (is_array($ed) && !empty($ed['selected_days'])) {
                $from_ed = $to_str($ed['selected_days']);
            }
        }

        $sd_col = isset($row->selected_days) ? $to_str($row->selected_days) : '';
        $ds = isset($row->days_selected) ? $to_str($row->days_selected) : '';

        $result = '';
        if ($from_ed !== '' && $sd_col !== '') {
            $ce = $canonical_day_count($from_ed);
            $cs = $canonical_day_count($sd_col);
            if ($ce > 0 && $ce < $cs) {
                $result = $from_ed;
            }
        }

        if ($result === '' && $sd_col !== '') {
            $result = $sd_col;
        }
        if ($result === '' && $ds !== '') {
            $result = $ds;
        }
        if ($result === '' && $from_ed !== '') {
            $result = $from_ed;
        }

        return $result;
    }
}

/**
 * Map canonical meta labels to Final Reports row keys (subset used for enrichment).
 *
 * @return array<string,string>
 */
function intersoccer_get_order_meta_canonical_to_final_report_row_keys() {
    return [
        'Booking Type' => 'booking_type',
        'Days Selected' => 'selected_days',
        'Camp Terms' => 'camp_terms',
        'Season' => 'season',
    ];
}

/**
 * Fill missing Final Reports fields from WooCommerce order item meta (FR/DE keys).
 *
 * @param array<string,mixed> $row
 */
function intersoccer_reports_enrich_final_report_row_from_order_item(array &$row) {
    $item_id = isset($row['order_item_id']) ? (int) $row['order_item_id'] : 0;
    if ($item_id <= 0) {
        return;
    }

    if (!class_exists('\WC_Order_Factory')) {
        return;
    }

    $item = \WC_Order_Factory::get_order_item($item_id);
    if (!$item || !($item instanceof \WC_Order_Item_Product)) {
        return;
    }

    $key_map = intersoccer_get_order_meta_canonical_to_final_report_row_keys();
    $field_map = intersoccer_get_order_meta_field_map();

    foreach ($item->get_meta_data() as $meta) {
        $data = $meta->get_data();
        $raw_key = isset($data['key']) ? (string) $data['key'] : '';
        if ($raw_key === '') {
            continue;
        }
        $value = $data['value'] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        } else {
            $value = (string) $value;
        }

        $canonical = intersoccer_normalize_order_item_meta_key($raw_key);
        $row_key = $key_map[$canonical] ?? null;
        if ($row_key === null && isset($field_map[$canonical])) {
            $internal = $field_map[$canonical];
            if ($internal === 'event_type') {
                $row_key = 'camp_terms';
            }
        }
        if ($row_key === null) {
            continue;
        }

        $current = isset($row[$row_key]) ? trim((string) $row[$row_key]) : '';
        if ($current !== '') {
            continue;
        }
        $row[$row_key] = $value;
    }
}

/**
 * Apply English normalization to booking_type and selected_days on a Final Reports row.
 *
 * @param array<string,mixed> $row
 */
function intersoccer_normalize_final_reports_row_booking_and_days(array &$row) {
    if (isset($row['booking_type'])) {
        $row['booking_type'] = intersoccer_normalize_booking_type_label_for_reports($row['booking_type']);
    }
    if (isset($row['selected_days'])) {
        $sd = $row['selected_days'];
        if (is_array($sd)) {
            $sd = implode(', ', $sd);
        }
        $row['selected_days'] = intersoccer_normalize_selected_days_string_for_reports((string) $sd);
    }
}

/**
 * Enrich rows from WooCommerce order item meta when SQL/rosters missed localized keys, then normalize values.
 *
 * @param array<int,array<string,mixed>> $rows
 */
function intersoccer_reports_enrich_and_normalize_final_report_rows(array &$rows) {
    foreach ($rows as &$row) {
        $bt = isset($row['booking_type']) ? trim((string) $row['booking_type']) : '';
        $sd_raw = $row['selected_days'] ?? '';
        $sd = is_array($sd_raw) ? trim(implode(', ', $sd_raw)) : trim((string) $sd_raw);
        $slug = function_exists('intersoccer_normalize_booking_type_slug_for_reports')
            ? intersoccer_normalize_booking_type_slug_for_reports($bt)
            : 'other';
        $needs_enrich = ($bt === '') || ($slug === 'single-days' && $sd === '');
        if ($needs_enrich) {
            intersoccer_reports_enrich_final_report_row_from_order_item($row);
        }
        intersoccer_normalize_final_reports_row_booking_and_days($row);
    }
    unset($row);
}

/**
 * Fill empty booking_type / selected_days on a roster row from WooCommerce order line meta (FR/DE/EN keys).
 *
 * @param object|array $row Roster row (stdClass from DB or export array). Mutated in place.
 */
function intersoccer_roster_enrich_camp_fields_from_order_item(&$row) {
    $item_id = 0;
    if (is_object($row)) {
        $item_id = (int) ($row->order_item_id ?? 0);
    } elseif (is_array($row)) {
        $item_id = (int) ($row['order_item_id'] ?? 0);
    }
    if ($item_id <= 0 || !class_exists('\WC_Order_Factory')) {
        return;
    }

    $item = \WC_Order_Factory::get_order_item($item_id);
    if (!$item || !($item instanceof \WC_Order_Item_Product)) {
        return;
    }

    $key_map = intersoccer_get_order_meta_canonical_to_final_report_row_keys();
    $field_map = intersoccer_get_order_meta_field_map();

    $get = static function ($r, $k) {
        if (is_object($r)) {
            return isset($r->$k) ? trim((string) $r->$k) : '';
        }
        return isset($r[$k]) ? trim((string) $r[$k]) : '';
    };
    $set = static function (&$r, $k, $v) {
        if (is_object($r)) {
            $r->$k = $v;
        } else {
            $r[$k] = $v;
        }
    };

    foreach ($item->get_meta_data() as $meta) {
        $data = $meta->get_data();
        $raw_key = isset($data['key']) ? (string) $data['key'] : '';
        if ($raw_key === '') {
            continue;
        }
        $value = $data['value'] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        } else {
            $value = (string) $value;
        }

        $canonical = intersoccer_normalize_order_item_meta_key($raw_key);
        $row_key = $key_map[$canonical] ?? null;
        if ($row_key === null && isset($field_map[$canonical])) {
            $internal = $field_map[$canonical];
            if ($internal === 'selected_days') {
                $row_key = 'selected_days';
            } elseif ($internal === 'booking_type') {
                $row_key = 'booking_type';
            }
        }
        if ($row_key !== 'selected_days' && $row_key !== 'booking_type') {
            continue;
        }
        if (trim($value) === '') {
            continue;
        }
        // WooCommerce line meta is source of truth for days and booking type; roster rows can be stale.
        if ($row_key === 'selected_days') {
            $set($row, 'selected_days', $value);
            continue;
        }
        if ($row_key === 'booking_type') {
            $set($row, 'booking_type', $value);
            continue;
        }
    }

    if ($get($row, 'selected_days') === '' && function_exists('wc_get_order_item_meta')) {
        foreach (['selected_days', 'Days Selected', 'days_selected'] as $meta_key) {
            $v = wc_get_order_item_meta($item_id, $meta_key, true);
            if ($v === null || $v === '') {
                continue;
            }
            if (is_array($v)) {
                $v = implode(', ', array_map('strval', $v));
            }
            $v = trim((string) $v);
            if ($v !== '') {
                $set($row, 'selected_days', $v);
                break;
            }
        }
    }

    if ($get($row, 'booking_type') === '' && function_exists('wc_get_order_item_meta')) {
        foreach (['Booking Type', 'booking_type', 'pa_booking-type'] as $meta_key) {
            $v = wc_get_order_item_meta($item_id, $meta_key, true);
            if ($v === null || $v === '') {
                continue;
            }
            if (is_array($v)) {
                $v = implode(', ', array_map('strval', $v));
            }
            $v = trim((string) $v);
            if ($v !== '') {
                $set($row, 'booking_type', $v);
                break;
            }
        }
    }

    if ($get($row, 'selected_days') !== '' && function_exists('intersoccer_normalize_selected_days_string_for_reports')) {
        $set($row, 'selected_days', intersoccer_normalize_selected_days_string_for_reports($get($row, 'selected_days')));
    }
    if ($get($row, 'booking_type') !== '' && function_exists('intersoccer_normalize_booking_type_label_for_reports')) {
        $set($row, 'booking_type', intersoccer_normalize_booking_type_label_for_reports($get($row, 'booking_type')));
    }

    $repair_applied = false;
    if ($get($row, 'selected_days') !== '' && $get($row, 'booking_type') !== ''
        && function_exists('intersoccer_normalize_booking_type_slug_for_reports')
        && function_exists('intersoccer_normalize_selected_days_string_for_reports')
        && function_exists('wc_get_order_item_meta')) {
        $bt_s = intersoccer_normalize_booking_type_slug_for_reports($get($row, 'booking_type'));
        if ($bt_s === 'single-days') {
            $norm_sd = intersoccer_normalize_selected_days_string_for_reports($get($row, 'selected_days'));
            $cnt = $norm_sd === '' ? 0 : count(array_filter(array_map('trim', explode(',', $norm_sd))));
            if ($cnt >= 5) {
                $best_v = '';
                $best_n = 99;
                foreach (['Days Selected', 'selected_days', 'days_selected', 'Jours sélectionnés', 'Ausgewählte Tage'] as $mk) {
                    $v = wc_get_order_item_meta($item_id, $mk, true);
                    if ($v === null || $v === '') {
                        continue;
                    }
                    if (is_array($v)) {
                        $v = implode(', ', array_map('strval', $v));
                    }
                    $v = trim((string) $v);
                    if ($v === '') {
                        continue;
                    }
                    $norm_v = intersoccer_normalize_selected_days_string_for_reports($v);
                    $n = $norm_v === '' ? 0 : count(array_filter(array_map('trim', explode(',', $norm_v))));
                    if ($n > 0 && $n < $best_n) {
                        $best_n = $n;
                        $best_v = $v;
                    }
                }
                if ($best_v !== '' && $best_n > 0 && $best_n < $cnt) {
                    $set($row, 'selected_days', intersoccer_normalize_selected_days_string_for_reports($best_v));
                    $repair_applied = true;
                }
            }
        }
    }

    // Second repair: if every explicit key still yields five weekdays, scan non-excluded meta for any value that
    // normalizes to 1–4 weekdays (e.g. alternate locale key not in wc_get_order_item_meta list).
    if (!$repair_applied && $get($row, 'booking_type') !== ''
        && function_exists('intersoccer_normalize_booking_type_slug_for_reports')
        && function_exists('intersoccer_normalize_selected_days_string_for_reports')) {
        $bt_s2 = intersoccer_normalize_booking_type_slug_for_reports($get($row, 'booking_type'));
        if ($bt_s2 === 'single-days' && $get($row, 'selected_days') !== '') {
            $norm_sd2 = intersoccer_normalize_selected_days_string_for_reports($get($row, 'selected_days'));
            $cnt2 = $norm_sd2 === '' ? 0 : count(array_filter(array_map('trim', explode(',', $norm_sd2))));
            if ($cnt2 >= 5) {
                $best_v2 = '';
                $best_n2 = 99;
                $meta_key_exclude_broad = static function ($k) {
                    $k = strtolower((string) $k);
                    if ($k === '') {
                        return true;
                    }
                    if (preg_match('/late\s*pickup|ramassage|abholung|booking\s*type|buchungstyp|type\s+de\s+r/i', $k)) {
                        return true;
                    }
                    if (preg_match('/assigned|attendee|player\s*index|discount|price|cost|season|venue|camp\s*terms|course|activity|start\s*date|end\s*date|email|phone|description|content|note$/i', $k)) {
                        return true;
                    }
                    return false;
                };
                foreach ($item->get_meta_data() as $meta) {
                    $data = $meta->get_data();
                    $raw_key = (string) ($data['key'] ?? '');
                    if ($meta_key_exclude_broad($raw_key)) {
                        continue;
                    }
                    $value = $data['value'] ?? '';
                    if (is_array($value)) {
                        $value = implode(', ', array_map('strval', $value));
                    }
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }
                    $norm_v = intersoccer_normalize_selected_days_string_for_reports($value);
                    $n = $norm_v === '' ? 0 : count(array_filter(array_map('trim', explode(',', $norm_v))));
                    if ($n >= 1 && $n < 5 && $n < $best_n2) {
                        $best_n2 = $n;
                        $best_v2 = $value;
                    }
                }
                if ($best_v2 !== '' && $best_n2 > 0 && $best_n2 < $cnt2) {
                    $set($row, 'selected_days', intersoccer_normalize_selected_days_string_for_reports($best_v2));
                    $repair_applied = true;
                }
            }
        }
    }
}
