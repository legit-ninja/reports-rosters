<?php
/**
 * Dry-run sizing for Tess DE roster fixes (SELECT only — never writes).
 *
 * Usage (sql-sandbox):
 *   docker compose run --rm wpcli eval-file /path/to/backfill-roster-de-fixes-dryrun.php
 */

if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

if (!defined('ABSPATH')) {
    echo "This script must run under wp eval-file.\n";
    return;
}

$dryrun_support = dirname(__DIR__) . '/includes/dryrun-readonly.php';
if (is_readable($dryrun_support)) {
    require_once $dryrun_support;
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
if (function_exists('intersoccer_reports_dryrun_bootstrap_readonly')) {
    intersoccer_reports_dryrun_bootstrap_readonly();
}

$dryrun_after_query = static function ($context) {
    if (function_exists('intersoccer_reports_dryrun_note_sql_error')) {
        intersoccer_reports_dryrun_note_sql_error($context);
    }
};

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

// --- Signature drift (branch vs master code + stored vs branch) ---
$sig_page = 500;
$sig_offset = 0;
$sig_by_type = [];
$sig_total = 0;
$sig_stored_mismatch = 0;
$sig_empty_stored = 0;
$sig_stored_match = 0;
$sig_branch_vs_master = 0;
$sig_stored_cause_by_type = [];
$sig_stored_mismatch_by_type = [];
$bump_stored_cause = static function ($atype, $cause) use (&$sig_stored_cause_by_type) {
    if (!isset($sig_stored_cause_by_type[$atype])) {
        $sig_stored_cause_by_type[$atype] = [];
    }
    $sig_stored_cause_by_type[$atype][$cause] = (int) ($sig_stored_cause_by_type[$atype][$cause] ?? 0) + 1;
};

if (function_exists('intersoccer_event_signature_branch_pipeline')
    && function_exists('intersoccer_event_signature_master_pipeline')) {
    while (true) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, activity_type, venue, age_group, camp_terms, course_day, times, season, girls_only,
                    city, canton_region, product_id, start_date, end_date, event_signature
             FROM {$table}
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            $sig_page,
            $sig_offset
        ), ARRAY_A);
        $dryrun_after_query('signature_page');
        if (!$rows) {
            break;
        }
        foreach ($rows as $row) {
            ++$sig_total;
            $atype = (string) ($row['activity_type'] ?? '');
            if (!isset($sig_by_type[$atype])) {
                $sig_by_type[$atype] = [
                    'rows' => 0,
                    'stored_match' => 0,
                    'stored_mismatch' => 0,
                    'empty_stored' => 0,
                    'branch_vs_master' => 0,
                ];
            }
            $sig_by_type[$atype]['rows']++;
            $normalize_storage_date = static function ($value) {
                if ($value === '1970-01-01' || $value === '0000-00-00') {
                    return null;
                }
                return $value;
            };
            $start_raw = $normalize_storage_date($row['start_date'] ?? null);
            $end_raw = $normalize_storage_date($row['end_date'] ?? null);
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
                'start_date' => $start_raw,
                'end_date' => $end_raw,
            ];
            $branch_sig = intersoccer_event_signature_branch_pipeline($payload);
            $master_sig = intersoccer_event_signature_master_pipeline($payload);
            if ($branch_sig !== '' && $master_sig !== '' && !hash_equals($branch_sig, $master_sig)) {
                ++$sig_branch_vs_master;
                $sig_by_type[$atype]['branch_vs_master']++;
            }
            $stored = (string) ($row['event_signature'] ?? '');
            if ($stored === '') {
                ++$sig_empty_stored;
                $sig_by_type[$atype]['empty_stored']++;
                continue;
            }
            if ($branch_sig !== '' && hash_equals($stored, $branch_sig)) {
                ++$sig_stored_match;
                $sig_by_type[$atype]['stored_match']++;
            } else {
                ++$sig_stored_mismatch;
                $sig_by_type[$atype]['stored_mismatch']++;
                if (!isset($sig_stored_mismatch_by_type[$atype])) {
                    $sig_stored_mismatch_by_type[$atype] = 0;
                }
                $sig_stored_mismatch_by_type[$atype]++;
                $payload_legacy_start = $payload;
                $payload_legacy_start['start_date'] = $row['start_date'] ?? '';
                $payload_legacy_start['end_date'] = $row['end_date'] ?? '';
                $legacy_sig = intersoccer_event_signature_branch_pipeline($payload_legacy_start);
                if (hash_equals($stored, $legacy_sig)) {
                    $bump_stored_cause($atype, 'start_date_legacy_sentinel');
                } else {
                    $attributed = false;
                    $candidates = ['venue', 'season', 'camp_terms', 'course_day', 'times', 'city', 'canton_region', 'product_id', 'activity_type', 'girls_only'];
                    foreach ($candidates as $field) {
                        $trial = $payload;
                        $trial[$field] = is_int($payload[$field] ?? null) ? 0 : '';
                        $try_sig = intersoccer_event_signature_branch_pipeline($trial);
                        if ($try_sig !== '' && hash_equals($stored, $try_sig)) {
                            $bump_stored_cause($atype, $field . '_normalization');
                            $attributed = true;
                            break;
                        }
                    }
                    if (!$attributed) {
                        $bump_stored_cause($atype, 'unattributed_stored_drift');
                    }
                }
            }
        }
        $sig_offset += $sig_page;
        if (count($rows) < $sig_page) {
            break;
        }
    }
}
$GLOBALS['intersoccer_dryrun_signature_branch_vs_master_drift'] = $sig_branch_vs_master;
$sig_rows = [];
foreach ($sig_by_type as $atype => $counts) {
    $sig_rows[] = [
        'activity_type' => $atype,
        'rows' => $counts['rows'],
        'stored_eq_branch' => $counts['stored_match'],
        'stored_vs_branch_drift' => $counts['stored_mismatch'],
        'branch_vs_master_drift' => $counts['branch_vs_master'],
        'empty_stored' => $counts['empty_stored'],
    ];
}
usort($sig_rows, static function ($a, $b) {
    return ($b['stored_vs_branch_drift'] ?? 0) <=> ($a['stored_vs_branch_drift'] ?? 0);
});
$echo_section('event_signature branch vs master code (gate: drift must be 0)', [
    ['branch_vs_master_drift' => $sig_branch_vs_master],
]);
$echo_section('event_signature stored vs branch recompute (informational; stale DB rows)', $sig_rows);
echo "   totals: rows={$sig_total} stored_match={$sig_stored_match} stored_drift={$sig_stored_mismatch} branch_vs_master_drift={$sig_branch_vs_master} empty_stored={$sig_empty_stored}\n";
$stored_cause_rows = [];
foreach ($sig_stored_cause_by_type as $atype => $causes) {
    foreach ($causes as $cause => $cnt) {
        $stored_cause_rows[] = [
            'activity_type' => $atype,
            'cause' => $cause,
            'cnt' => $cnt,
        ];
    }
}
usort($stored_cause_rows, static function ($a, $b) {
    $by_cnt = ($b['cnt'] ?? 0) <=> ($a['cnt'] ?? 0);
    if ($by_cnt !== 0) {
        return $by_cnt;
    }
    return strcmp((string) ($a['activity_type'] ?? ''), (string) ($b['activity_type'] ?? ''));
});
$echo_section('stored vs branch drift attribution (by activity_type and cause, no ids)', $stored_cause_rows);

$relabel_would = $wpdb->get_var(
    "SELECT COUNT(DISTINCT id) FROM {$table}
     WHERE girls_only = 1 AND activity_type = 'Girls Only'"
);
$dryrun_after_query('girls_only_relabel_info');
echo "\n== repair path (informational)\n";
echo "   girls_only=1 with activity_type Girls Only (expected after no-relabel repair): " . (int) $relabel_would . "\n";
echo "   legacy relabel-to-Camp bucket removed (repair no longer relabels).\n";

// --- Headline bad start_date population (sizing-aligned) ---
$bad_start_sql = intersoccer_reports_sql_roster_date_is_bad('start_date');
$bad_end_sql = intersoccer_reports_sql_roster_date_is_bad('end_date');
$bad_start_total = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT id) FROM {$table} WHERE {$bad_start_sql}"
);
$dryrun_after_query('bad_start_total');
$bad_end_only = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT id) FROM {$table}
     WHERE {$bad_end_sql}
       AND NOT ({$bad_start_sql})"
);
$dryrun_after_query('bad_end_only');
echo "\n== headline start_date population (NULL or 1970 only)\n";
echo "   bad_start_total={$bad_start_total} (target ~1894)\n";
echo "   bad_end_only_rows={$bad_end_only}\n";

$tournament_bad_start = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT id) FROM {$table}
     WHERE ({$bad_start_sql})
       AND LOWER(activity_type) LIKE '%tournament%'"
);
$dryrun_after_query('tournament_bad_start');
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
         WHERE " . intersoccer_reports_sql_roster_date_is_bad('r.start_date') . "
         ORDER BY r.id ASC
         LIMIT %d OFFSET %d",
        $date_page,
        $date_offset
    ), ARRAY_A);
    $dryrun_after_query('bad_start_batch');
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

if (function_exists('intersoccer_reports_note_readonly_db_error_if_any')) {
    intersoccer_reports_note_readonly_db_error_if_any();
}

$eval = function_exists('intersoccer_reports_dryrun_evaluate_result')
    ? intersoccer_reports_dryrun_evaluate_result()
    : ['valid' => true, 'reasons' => []];

echo "\n== dry-run result summary\n";
if (!($eval['valid'] ?? false)) {
    echo "RESULT: INVALID\n";
    foreach ($eval['reasons'] as $reason) {
        echo "ABORT: {$reason}\n";
    }
    $blocked = $GLOBALS['intersoccer_dryrun_blocked_queries'] ?? null;
    if (is_array($blocked)) {
        $type_rows = [];
        foreach ($blocked['by_type'] ?? [] as $type => $cnt) {
            $type_rows[] = ['type' => $type, 'cnt' => $cnt];
        }
        $table_rows = [];
        foreach ($blocked['by_table'] ?? [] as $tbl => $cnt) {
            $table_rows[] = ['table' => $tbl, 'cnt' => $cnt];
        }
        $echo_section('blocked write queries by statement type', $type_rows);
        $echo_section('blocked write queries by table', $table_rows);
        if (!empty($blocked['samples'])) {
            echo "   first_blocked_samples (masked):\n";
            foreach ($blocked['samples'] as $sample) {
                echo '   ' . $sample . "\n";
            }
        }
    }
    if (function_exists('intersoccer_reports_set_readonly_mode')) {
        intersoccer_reports_set_readonly_mode(false);
    }
    return;
}

echo "RESULT: OK\n";
echo "   signature_branch_vs_master_drift=0 (required)\n";
echo "   stored_vs_branch_drift={$sig_stored_mismatch} (informational only)\n";

if (function_exists('intersoccer_reports_set_readonly_mode')) {
    intersoccer_reports_set_readonly_mode(false);
}

echo "DRY RUN complete (no writes).\n";
