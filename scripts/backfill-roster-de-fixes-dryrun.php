<?php
/**
 * Dry-run sizing for Tess DE roster fixes (SELECT only — never writes).
 *
 * Usage (sql-sandbox):
 *   docker compose run --rm wpcli eval-file /path/to/backfill-roster-de-fixes-dryrun.php
 *
 * There is intentionally no --apply mode in this script.
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

$wpdb->query('SET SESSION TRANSACTION READ ONLY');

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

$girls_composite_sql = "(activity_type LIKE '%Girls Only%' OR activity_type LIKE '%Filles uniquement%' OR activity_type LIKE '%filles uniquement%'
    OR activity_type LIKE '%Nur Mädchen%' OR activity_type LIKE '%nur mädchen%' OR activity_type LIKE '%Nur Maedchen%')";

// --- Signature drift (paged) ---
$sig_page = 500;
$sig_offset = 0;
$sig_by_type = [];
$sig_total = 0;
$sig_mismatch = 0;
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
                $sig_by_type[$atype] = ['rows' => 0, 'match' => 0, 'mismatch' => 0];
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
            if ($payload['start_date'] === '1970-01-01') {
                $payload['start_date'] = '';
            }
            $recomputed = intersoccer_event_signature_from_event_data($payload);
            $stored = (string) ($row['event_signature'] ?? '');
            if ($stored !== '' && $recomputed !== '' && hash_equals($stored, $recomputed)) {
                $sig_by_type[$atype]['match']++;
            } else {
                $sig_by_type[$atype]['mismatch']++;
                ++$sig_mismatch;
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
    ];
}
usort($sig_rows, static function ($a, $b) {
    return ($b['drift'] ?? 0) <=> ($a['drift'] ?? 0);
});
$echo_section('event_signature stored vs recomputed (by activity_type)', $sig_rows);
echo "   totals: rows={$sig_total} drift={$sig_mismatch}\n";

// --- Date recovery (resolver order: variation _camp_* then order item meta, then terms parse) ---
$date_page = 300;
$date_offset = 0;
$source_counts = [];
$disagree_item_variation = 0;
$placeholder_buckets = [];
$bad_start = 0;
$bad_end = 0;
$unrecoverable = [];

while (true) {
    $batch = $wpdb->get_results($wpdb->prepare(
        "SELECT r.id, r.order_item_id, r.variation_id, r.activity_type, r.camp_terms, r.season,
                r.start_date, r.end_date, r.is_placeholder, r.product_name
         FROM {$table} r
         WHERE (r.start_date IS NULL OR r.start_date = '' OR r.start_date = '1970-01-01'
                OR r.end_date IS NULL OR r.end_date = '' OR r.end_date = '1970-01-01')
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
        $ph = (int) ($r['is_placeholder'] ?? 0);
        $atype = strtolower(trim((string) ($r['activity_type'] ?? '')));
        if (!isset($placeholder_buckets[$ph])) {
            $placeholder_buckets[$ph] = ['rows' => 0, 'recoverable' => 0, 'unrecoverable' => 0];
        }
        $placeholder_buckets[$ph]['rows']++;

        if (strpos($atype, 'tournament') !== false) {
            continue;
        }

        $needs_start = empty($r['start_date']) || $r['start_date'] === '1970-01-01';
        $needs_end = empty($r['end_date']) || $r['end_date'] === '1970-01-01';
        if ($needs_start) {
            ++$bad_start;
        }
        if ($needs_end) {
            ++$bad_end;
        }

        $variation_id = (int) ($r['variation_id'] ?? 0);
        $order_item_id = (int) ($r['order_item_id'] ?? 0);
        $var_start = null;
        $var_end = null;
        if ($variation_id > 0) {
            $var_start = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$postmeta} WHERE post_id = %d AND meta_key = '_camp_start_date' LIMIT 1",
                $variation_id
            ));
            $var_end = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$postmeta} WHERE post_id = %d AND meta_key = '_camp_end_date' LIMIT 1",
                $variation_id
            ));
        }
        $item_start = null;
        $item_end = null;
        if ($order_item_id > 0) {
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
        }

        if ($is_iso_date($var_start) && $is_iso_date($item_start) && $var_start !== $item_start) {
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
                "SELECT meta_value FROM {$postmeta} WHERE post_id = %d AND meta_key = '_course_end_date' LIMIT 1",
                $variation_id
            ));
            if ($is_iso_date($cs)) {
                $resolved_start = $cs;
                $resolved_end = $is_iso_date($ce) ? $ce : $cs;
                $source = 'variation_course_meta';
            }
        } elseif (strpos($atype, 'camp') !== false || $atype === '') {
            if ($is_iso_date($var_start)) {
                $resolved_start = $var_start;
                $resolved_end = $is_iso_date($var_end) ? $var_end : $var_start;
                $source = 'variation_meta';
            } elseif ($is_iso_date($item_start)) {
                $resolved_start = $item_start;
                $resolved_end = $is_iso_date($item_end) ? $item_end : $item_start;
                $source = 'order_item_meta';
            } elseif (function_exists('intersoccer_reports_resolve_camp_schedule')) {
                list($rs, $re) = intersoccer_reports_resolve_camp_schedule(
                    $variation_id,
                    $order_item_id,
                    (string) ($r['camp_terms'] ?? ''),
                    (string) ($r['season'] ?? '')
                );
                if ($is_iso_date($rs)) {
                    $resolved_start = $rs;
                    $resolved_end = $is_iso_date($re) ? $re : $rs;
                    $source = 'terms_parse';
                }
            }
        }

        if (!isset($source_counts[$source])) {
            $source_counts[$source] = 0;
        }
        $source_counts[$source]++;

        if ($resolved_start) {
            $placeholder_buckets[$ph]['recoverable']++;
        } else {
            $placeholder_buckets[$ph]['unrecoverable']++;
            $season_year = 0;
            if (preg_match('/(\d{4})/', (string) ($r['season'] ?? ''), $m)) {
                $season_year = (int) $m[1];
            }
            $unrecoverable[] = [
                'id' => $id,
                'end_sort' => $r['end_date'] && $r['end_date'] !== '1970-01-01' ? $r['end_date'] : '9999-12-31',
                'start_sort' => $r['start_date'] && $r['start_date'] !== '1970-01-01' ? $r['start_date'] : '9999-12-31',
                'season_year' => $season_year,
            ];
        }
    }

    $date_offset += $date_page;
    if (count($batch) < $date_page) {
        break;
    }
}

$source_rows = [];
foreach ($source_counts as $source => $cnt) {
    $source_rows[] = ['source' => $source, 'cnt' => $cnt];
}
usort($source_rows, static function ($a, $b) {
    return ($b['cnt'] ?? 0) <=> ($a['cnt'] ?? 0);
});
$echo_section('recoverable camp/course date source (resolver order)', $source_rows);
echo "   item_vs_variation_iso_disagree={$disagree_item_variation}\n";
echo "   rows_with_bad_start={$bad_start} rows_with_bad_end={$bad_end}\n";

$ph_rows = [];
foreach ($placeholder_buckets as $ph => $b) {
    $ph_rows[] = ['is_placeholder' => $ph] + $b;
}
$echo_section('bad start/end rows by is_placeholder', $ph_rows ?: []);

usort($unrecoverable, static function ($a, $b) {
    if ($a['end_sort'] !== $b['end_sort']) {
        return strcmp($a['end_sort'], $b['end_sort']);
    }
    if ($a['start_sort'] !== $b['start_sort']) {
        return strcmp($a['start_sort'], $b['start_sort']);
    }
    return ($a['season_year'] ?? 0) <=> ($b['season_year'] ?? 0);
});
$unrecoverable_ids = array_map(static function ($row) {
    return (int) $row['id'];
}, $unrecoverable);
$echo_section('unrecoverable roster ids (upcoming-first sort)', array_map(static function ($id) {
    return ['id' => $id];
}, $unrecoverable_ids));

// --- Girls-only ---
$missing_roster = $wpdb->get_results(
    "SELECT COUNT(DISTINCT oi.order_item_id) AS cnt
     FROM {$order_items} oi
     INNER JOIN {$posts} p ON p.ID = oi.order_id AND p.post_type = 'shop_order'
       AND p.post_status IN ('wc-completed','wc-processing')
     INNER JOIN {$itemmeta} oim
       ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_intersoccer_canonical_girls_only'
     LEFT JOIN {$table} r ON r.order_item_id = oi.order_item_id AND r.is_placeholder = 0
     WHERE oim.meta_value IN ('1', 'yes') AND r.id IS NULL
       AND oi.order_item_type = 'line_item'",
    ARRAY_A
);
$echo_section('girls_only canonical yes lines with no roster row', $missing_roster ?: []);

$de_nein_fp = $wpdb->get_results(
    "SELECT COUNT(DISTINCT oi.order_item_id) AS cnt
     FROM {$order_items} oi
     INNER JOIN {$posts} p ON p.ID = oi.order_id AND p.post_type = 'shop_order'
       AND p.post_status IN ('wc-completed','wc-processing')
     INNER JOIN {$itemmeta} oim
       ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_intersoccer_canonical_girls_only'
     WHERE oi.order_item_type = 'line_item' AND LOWER(oim.meta_value) IN ('0', 'no', 'nein')",
    ARRAY_A
);
$echo_section('DE nein / canonical girls_only false (order lines)', $de_nein_fp ?: []);

$composite_to_flag = $wpdb->get_results(
    "SELECT COUNT(DISTINCT id) AS cnt
     FROM {$table}
     WHERE {$girls_composite_sql}
       AND activity_type NOT IN ('Camp', 'Course', 'Tournament')",
    ARRAY_A
);
$echo_section("rows with composite girls-only label (would become base + girls_only=1)", $composite_to_flag ?: []);

$label_girls_flag_zero = $wpdb->get_results(
    "SELECT COUNT(DISTINCT id) AS cnt
     FROM {$table}
     WHERE girls_only = 0 AND ({$girls_composite_sql} OR activity_type = 'Girls Only')",
    ARRAY_A
);
$echo_section('girls-only label text but girls_only = 0', $label_girls_flag_zero ?: []);

$disagree = $wpdb->get_results(
    "SELECT
       CASE WHEN girls_only = 1 THEN 'flag=1' ELSE 'flag=0' END AS flag_bucket,
       COUNT(DISTINCT id) AS cnt
     FROM {$table}
     WHERE (
         (girls_only = 1 AND NOT ({$girls_composite_sql}) AND activity_type NOT IN ('Girls Only'))
         OR (girls_only = 0 AND ({$girls_composite_sql} OR activity_type = 'Girls Only'))
       )
     GROUP BY flag_bucket",
    ARRAY_A
);
$echo_section('girls_only flag vs composite label disagree', $disagree ?: []);

echo "\nDRY RUN complete (no writes).\n";
