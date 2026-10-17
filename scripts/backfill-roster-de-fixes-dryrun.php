<?php
/**
 * Dry-run sizing for Tess DE roster fixes (SELECT only — never writes).
 *
 * Usage (sql-sandbox):
 *   docker compose run --rm wpcli eval-file /path/to/backfill-roster-de-fixes-dryrun.php
 */

if (!defined('ABSPATH')) {
    echo "This script must run under wp eval-file.\n";
    return;
}

global $wpdb;
$table = $wpdb->prefix . 'intersoccer_rosters';
$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
$order_items = $wpdb->prefix . 'woocommerce_order_items';
$posts = $wpdb->posts;
$postmeta = $wpdb->postmeta;

$ro_readonly = $wpdb->get_var('SELECT @@transaction_read_only');
if ($ro_readonly === null) {
    echo "ABORT: could not read @@transaction_read_only\n";
    return;
}
$wpdb->query('SET SESSION TRANSACTION READ ONLY');
$ro_after = $wpdb->get_var('SELECT @@transaction_read_only');
if ((string) $ro_after !== '1') {
    echo "ABORT: SET SESSION TRANSACTION READ ONLY did not take effect (got " . var_export($ro_after, true) . ")\n";
    return;
}

if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
    intersoccer_set_bulk_normalize_quiet(true);
}

$echo_section = static function (string $title, array $rows) {
    echo "\n== {$title}\n";
    if ($rows === []) {
        echo "   (none)\n";
        return;
    }
    foreach ($rows as $row) {
        echo '   ' . wp_json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
};

$is_iso_date = static function ($value): bool {
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
};

$camp_item_meta_keys = [
    '_camp_start_date',
    'Camp Start Date',
    'Date de début du camp',
    'Camp-Startdatum',
];
$camp_item_end_keys = [
    '_camp_end_date',
    'Camp End Date',
    'Date de fin du camp',
    'Camp-Enddatum',
];

$girls_composite_sql = "(activity_type LIKE '%Girls Only%' OR activity_type LIKE '%Girls'' only%' OR activity_type LIKE '%Girls'' Only%'
    OR activity_type LIKE '%Filles uniquement%' OR activity_type LIKE '%filles uniquement%' OR activity_type LIKE '%filles seulement%'
    OR activity_type LIKE '%Nur Mädchen%' OR activity_type LIKE '%nur mädchen%' OR activity_type LIKE '%Nur Maedchen%')";

$resolve_variation_id = static function (int $roster_variation_id, int $order_item_id) use ($wpdb, $itemmeta) {
    $variation_id = $roster_variation_id;
    if ($order_item_id > 0) {
        $item_vid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$itemmeta} WHERE order_item_id = %d AND meta_key = '_variation_id' LIMIT 1",
            $order_item_id
        ));
        if ($item_vid > 0) {
            $variation_id = $item_vid;
        }
    }
    if ($variation_id > 0 && function_exists('intersoccer_get_default_language_variation_id')) {
        $default_vid = (int) intersoccer_get_default_language_variation_id($variation_id);
        if ($default_vid > 0) {
            $variation_id = $default_vid;
        }
    }
    return $variation_id;
};

$read_camp_variation_meta = static function (int $variation_id) use ($wpdb, $postmeta, $is_iso_date) {
    if ($variation_id <= 0) {
        return [null, null];
    }
    $start = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$postmeta} WHERE post_id = %d AND meta_key = '_camp_start_date' LIMIT 1",
        $variation_id
    ));
    $end = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$postmeta} WHERE post_id = %d AND meta_key = '_camp_end_date' LIMIT 1",
        $variation_id
    ));
    return [
        $is_iso_date($start) ? trim((string) $start) : null,
        $is_iso_date($end) ? trim((string) $end) : null,
    ];
};

$read_camp_item_meta = static function (int $order_item_id) use ($wpdb, $itemmeta, $camp_item_meta_keys, $camp_item_end_keys, $is_iso_date) {
    $item_start = null;
    $item_end = null;
    if ($order_item_id <= 0) {
        return [$item_start, $item_end];
    }
    foreach ($camp_item_meta_keys as $mk) {
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$itemmeta} WHERE order_item_id = %d AND meta_key = %s LIMIT 1",
            $order_item_id,
            $mk
        ));
        if ($is_iso_date($raw)) {
            $item_start = trim((string) $raw);
            break;
        }
    }
    foreach ($camp_item_end_keys as $mk) {
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$itemmeta} WHERE order_item_id = %d AND meta_key = %s LIMIT 1",
            $order_item_id,
            $mk
        ));
        if ($is_iso_date($raw)) {
            $item_end = trim((string) $raw);
            break;
        }
    }
    return [$item_start, $item_end];
};

$resolve_camp_dates_readonly = static function (
    int $variation_id,
    int $order_item_id,
    string $camp_terms,
    string $season
) use ($read_camp_variation_meta, $read_camp_item_meta, $is_iso_date) {
    list($var_start, $var_end) = $read_camp_variation_meta($variation_id);
    if ($var_start) {
        return [$var_start, $var_end ?: $var_start, 'variation_meta'];
    }
    list($item_start, $item_end) = $read_camp_item_meta($order_item_id);
    if ($item_start) {
        return [$item_start, $item_end ?: $item_start, 'order_item_meta'];
    }
    if ($camp_terms !== '' && $camp_terms !== 'N/A' && function_exists('intersoccer_parse_camp_dates_fixed')) {
        if (function_exists('intersoccer_reports_normalize_camp_terms_for_dates')) {
            $camp_terms = intersoccer_reports_normalize_camp_terms_for_dates($camp_terms);
        }
        list($parsed_start, $parsed_end) = intersoccer_parse_camp_dates_fixed($camp_terms, $season);
        if ($is_iso_date($parsed_start) && $parsed_start !== '1970-01-01') {
            return [$parsed_start, $is_iso_date($parsed_end) ? $parsed_end : $parsed_start, 'terms_parse'];
        }
    }
    return [null, null, 'none'];
};

// --- Signature drift ---
$sig_page = 500;
$sig_offset = 0;
$sig_by_type = [];
$sig_total = 0;
$sig_mismatch = 0;
$sig_empty_stored = 0;
$sig_match = 0;

if (function_exists('intersoccer_event_signature_from_event_data')) {
    while (true) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, activity_type, venue, age_group, camp_terms, course_day, times, season, girls_only,
                    city, canton_region, product_id, start_date, event_signature
             FROM {$table}
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            $sig_page,
            $sig_offset
        ), ARRAY_A);
        if (!$rows) {
            break;
        }
        foreach ($rows as $row) {
            ++$sig_total;
            $atype = (string) ($row['activity_type'] ?? '');
            if (!isset($sig_by_type[$atype])) {
                $sig_by_type[$atype] = ['rows' => 0, 'match' => 0, 'mismatch' => 0, 'empty_stored' => 0];
            }
            $sig_by_type[$atype]['rows']++;
            $payload = [
                'activity_type' => $row['activity_type'] ?? '',
                'venue' => $row['venue'] ?? '',
                'age_group' => $row['age_group'] ?? '',
                'camp_terms' => $row['camp_terms'] ?? '',
                'course_day' => $row['course_day'] ?? '',
                'times' => $row['times'] ?? '',
                'season' => $row['season'] ?? '',
                'girls_only' => (int) ($row['girls_only'] ?? 0),
                'city' => $row['city'] ?? '',
                'canton_region' => $row['canton_region'] ?? '',
                'product_id' => (int) ($row['product_id'] ?? 0),
                'start_date' => $row['start_date'] ?? '',
            ];
            $stored = (string) ($row['event_signature'] ?? '');
            if ($stored === '') {
                ++$sig_empty_stored;
                $sig_by_type[$atype]['empty_stored']++;
                continue;
            }
            $recomputed = intersoccer_event_signature_from_event_data($payload);
            if ($recomputed !== '' && hash_equals($stored, $recomputed)) {
                ++$sig_match;
                $sig_by_type[$atype]['match']++;
            } else {
                ++$sig_mismatch;
                $sig_by_type[$atype]['mismatch']++;
            }
        }
        $sig_offset += $sig_page;
        if (count($rows) < $sig_page) {
            break;
        }
    }
}
$sig_rows = [];
foreach ($sig_by_type as $atype => $counts) {
    $sig_rows[] = [
        'activity_type' => $atype,
        'rows' => $counts['rows'],
        'stored_eq_recomputed' => $counts['match'],
        'drift' => $counts['mismatch'],
        'empty_stored' => $counts['empty_stored'],
    ];
}
usort($sig_rows, static function ($a, $b) {
    return ($b['drift'] ?? 0) <=> ($a['drift'] ?? 0);
});
$echo_section('event_signature stored vs recomputed (by activity_type)', $sig_rows);
echo "   totals: rows={$sig_total} match={$sig_match} drift={$sig_mismatch} empty_stored={$sig_empty_stored}\n";

$relabel_would = $wpdb->get_var(
    "SELECT COUNT(DISTINCT id) FROM {$table}
     WHERE girls_only = 1 AND activity_type = 'Girls Only'"
);
$echo_section('repair path: girls_only=1 rows kept as activity_type Girls Only (legacy would relabel to Camp)', [
    ['cnt' => (int) $relabel_would],
]);

// --- Headline bad start_date population (sizing-aligned) ---
$bad_start_total = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT id) FROM {$table}
     WHERE start_date IS NULL OR start_date = '1970-01-01'"
);
$bad_end_only = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT id) FROM {$table}
     WHERE (end_date IS NULL OR end_date = '' OR end_date = '1970-01-01')
       AND NOT (start_date IS NULL OR start_date = '1970-01-01')"
);
echo "\n== headline start_date population (NULL or 1970 only)\n";
echo "   bad_start_total={$bad_start_total} (target ~1894)\n";
echo "   bad_end_only_rows={$bad_end_only}\n";

$tournament_bad_start = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT id) FROM {$table}
     WHERE (start_date IS NULL OR start_date = '1970-01-01')
       AND LOWER(activity_type) LIKE '%tournament%'"
);
$non_tournament_bad_start = max(0, $bad_start_total - $tournament_bad_start);
echo "   tournament_bad_start_skipped={$tournament_bad_start}\n";
echo "   non_tournament_bad_start_denominator={$non_tournament_bad_start}\n";

$date_page = 300;
$date_offset = 0;
$source_counts = [];
$sizing_camp_meta_recoverable = 0;
$disagree_item_variation = 0;
$unrecoverable = [];
$ahead_today = 0;
$ahead_oct7 = 0;
$tournament_bucket = 0;
$today = gmdate('Y-m-d');
$as_of_oct7 = '2026-10-07';

$unrecoverable_sort_tuple = static function (array $row) use ($today) {
    $bd = $row['best_date'] ?? null;
    $undated = ($bd === null || $bd === '' || $bd === '0000-01-01');
    $season = (int) ($row['season_year'] ?? 0);
    if ($undated) {
        return [1, 1, PHP_INT_MAX, PHP_INT_MAX, -$season];
    }
    $is_past = ($bd < $today) ? 1 : 0;
    if ($is_past === 0) {
        return [0, 0, strtotime($bd), PHP_INT_MAX, -$season];
    }
    return [0, 1, PHP_INT_MAX, -strtotime($bd), -$season];
};

while (true) {
    $batch = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, r.order_item_id, r.variation_id, r.activity_type, r.camp_terms, r.season,
                r.start_date, r.end_date, r.is_placeholder
         FROM {$table} r
         WHERE r.start_date IS NULL OR r.start_date = '1970-01-01'
         ORDER BY r.id ASC
         LIMIT %d OFFSET %d",
        $date_page,
        $date_offset
    ), ARRAY_A);
    if (!$batch) {
        break;
    }

    foreach ($batch as $r) {
        $id = (int) $r['id'];
        $atype = strtolower(trim((string) ($r['activity_type'] ?? '')));
        if (strpos($atype, 'tournament') !== false) {
            ++$tournament_bucket;
            if (!isset($source_counts['tournament_skipped'])) {
                $source_counts['tournament_skipped'] = 0;
            }
            $source_counts['tournament_skipped']++;
            continue;
        }

        $order_item_id = (int) ($r['order_item_id'] ?? 0);
        $variation_id = $resolve_variation_id((int) ($r['variation_id'] ?? 0), $order_item_id);
        list($var_start, $var_end) = $read_camp_variation_meta($variation_id);
        list($item_start, $item_end) = $read_camp_item_meta($order_item_id);
        if ($var_start && $item_start && $var_start !== $item_start) {
            ++$disagree_item_variation;
        }

        $resolved_start = null;
        $resolved_end = null;
        $source = 'none';

        if (strpos($atype, 'birthday') !== false) {
            $source = 'birthday_no_source';
        } elseif (strpos($atype, 'course') !== false && $variation_id > 0) {
            $cs = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$postmeta} WHERE post_id = %d AND meta_key = '_course_start_date' LIMIT 1",
                $variation_id
            ));
            $ce = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$postmeta} WHERE post_id = %d AND meta_key = '_end_date' LIMIT 1",
                $variation_id
            ));
            if ($is_iso_date($cs)) {
                $resolved_start = $cs;
                $resolved_end = $is_iso_date($ce) ? $ce : $cs;
                $source = 'variation_course_meta';
            }
        } elseif (strpos($atype, 'camp') !== false || $atype === '' || strpos($atype, 'girls') !== false) {
            list($resolved_start, $resolved_end, $source) = $resolve_camp_dates_readonly(
                $variation_id,
                $order_item_id,
                (string) ($r['camp_terms'] ?? ''),
                (string) ($r['season'] ?? '')
            );
            if (in_array($source, ['variation_meta', 'order_item_meta'], true)) {
                ++$sizing_camp_meta_recoverable;
            }
        }

        if (!isset($source_counts[$source])) {
            $source_counts[$source] = 0;
        }
        $source_counts[$source]++;

        $best_date = null;
        if ($is_iso_date($resolved_end)) {
            $best_date = $resolved_end;
        } elseif ($is_iso_date($resolved_start)) {
            $best_date = $resolved_start;
        } elseif (function_exists('intersoccer_parse_camp_dates_fixed')) {
            list($ps, $pe) = intersoccer_parse_camp_dates_fixed((string) ($r['camp_terms'] ?? ''), (string) ($r['season'] ?? ''));
            if ($is_iso_date($pe)) {
                $best_date = $pe;
            } elseif ($is_iso_date($ps)) {
                $best_date = $ps;
            }
        }
        $season_year = 0;
        if (preg_match('/(\d{4})/', (string) ($r['season'] ?? ''), $m)) {
            $season_year = (int) $m[1];
        }

        if ($resolved_start) {
            $prop_end = $resolved_end ?: $resolved_start;
            if ($prop_end >= $today) {
                ++$ahead_today;
            }
            if ($prop_end >= $as_of_oct7) {
                ++$ahead_oct7;
            }
        } else {
            $unrecoverable[] = [
                'id' => $id,
                'best_date' => $best_date,
                'season_year' => $season_year,
            ];
        }
    }

    $date_offset += $date_page;
    if (count($batch) < $date_page) {
        break;
    }
}

$unrecoverable_count = count($unrecoverable);
$recoverable_non_tournament = max(0, $non_tournament_bad_start - $unrecoverable_count);
$source_rows = [];
foreach ($source_counts as $source => $cnt) {
    $source_rows[] = ['source' => $source, 'cnt' => $cnt];
}
usort($source_rows, static function ($a, $b) {
    return ($b['cnt'] ?? 0) <=> ($a['cnt'] ?? 0);
});
$echo_section('recoverable date source (bad start rows, non-tournament)', $source_rows);
echo "   sizing_camp_meta_recoverable(item+variation ISO)={$sizing_camp_meta_recoverable} (target ~1544)\n";
echo "   recoverable_non_tournament={$recoverable_non_tournament}\n";
echo "   unrecoverable_count={$unrecoverable_count} (target ~350, denominator excludes tournaments)\n";
echo "   tournament_bucket={$tournament_bucket}\n";
echo "   item_vs_variation_iso_disagree={$disagree_item_variation}\n";
echo "   ahead_proposed_end_vs_today={$ahead_today} (target ~103)\n";
echo "   ahead_proposed_end_vs_2026-10-07={$ahead_oct7} (target ~222)\n";

usort($unrecoverable, static function ($a, $b) use ($unrecoverable_sort_tuple) {
    $ta = $unrecoverable_sort_tuple($a);
    $tb = $unrecoverable_sort_tuple($b);
    return $ta <=> $tb;
});
$echo_section('unrecoverable roster ids (upcoming-first)', array_map(static function ($row) {
    return ['id' => (int) $row['id']];
}, $unrecoverable));

// --- Girls-only ---
$nein_false_positive = $wpdb->get_results(
    "SELECT COUNT(DISTINCT oi.order_item_id) AS cnt
     FROM {$order_items} oi
     INNER JOIN {$posts} p ON p.ID = oi.order_id AND p.post_type = 'shop_order'
       AND p.post_status IN ('wc-completed','wc-processing')
     INNER JOIN {$itemmeta} oim
       ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_intersoccer_canonical_girls_only'
       AND oim.meta_value = '1'
     LEFT JOIN {$itemmeta} lbl
       ON lbl.order_item_id = oi.order_item_id AND lbl.meta_key = 'Girls Only' AND LOWER(lbl.meta_value) = 'nein'
     WHERE oi.order_item_type = 'line_item' AND lbl.meta_id IS NOT NULL",
    ARRAY_A
);
$echo_section('canonical girls_only=1 with Girls Only label nein (misread false positives)', $nein_false_positive ?: []);

$missing_roster = $wpdb->get_results(
    "SELECT COUNT(DISTINCT oi.order_item_id) AS cnt
     FROM {$order_items} oi
     INNER JOIN {$posts} p ON p.ID = oi.order_id AND p.post_type = 'shop_order'
       AND p.post_status IN ('wc-completed','wc-processing')
     INNER JOIN {$itemmeta} oim
       ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_intersoccer_canonical_girls_only'
       AND oim.meta_value = '1'
     LEFT JOIN {$itemmeta} lbl_nein
       ON lbl_nein.order_item_id = oi.order_item_id AND lbl_nein.meta_key = 'Girls Only' AND LOWER(lbl_nein.meta_value) = 'nein'
     LEFT JOIN {$table} r ON r.order_item_id = oi.order_item_id AND r.is_placeholder = 0
     WHERE oi.order_item_type = 'line_item' AND r.id IS NULL AND lbl_nein.meta_id IS NULL",
    ARRAY_A
);
$echo_section('girls_only canonical yes lines with no roster row (nein excluded)', $missing_roster ?: []);

$composite_to_flag = $wpdb->get_results(
    "SELECT COUNT(DISTINCT id) AS cnt
     FROM {$table}
     WHERE {$girls_composite_sql}
       AND activity_type NOT IN ('Camp', 'Course', 'Tournament')",
    ARRAY_A
);
$echo_section("composite girls-only label -> base + girls_only=1", $composite_to_flag ?: []);

$label_girls_flag_zero = $wpdb->get_results(
    "SELECT COUNT(DISTINCT id) AS cnt
     FROM {$table}
     WHERE girls_only = 0 AND ({$girls_composite_sql} OR activity_type = 'Girls Only')",
    ARRAY_A
);
$echo_section('girls-only label text but girls_only = 0', $label_girls_flag_zero ?: []);

if (function_exists('intersoccer_set_bulk_normalize_quiet')) {
    intersoccer_set_bulk_normalize_quiet(false);
}

echo "\nDRY RUN complete (no writes).\n";
