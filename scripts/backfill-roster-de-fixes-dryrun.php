<?php
/**
 * Dry-run sizing for Tess DE roster fixes (SELECT only — never writes).
 *
 * Usage (sql-sandbox):
 *   docker compose run --rm wpcli eval-file /path/to/backfill-roster-de-fixes-dryrun.php
 *
 * There is intentionally no --apply mode in this script; reviewed apply would be a separate change.
 */

if (!defined('ABSPATH')) {
    echo "This script must run under wp eval-file.\n";
    return;
}

global $wpdb;
$table = $wpdb->prefix . 'intersoccer_rosters';

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

// 1970 start_date rows by activity_type (language proxy: none on roster — group activity_type only).
$epoch_rows = $wpdb->get_results(
    "SELECT activity_type, COUNT(*) AS cnt
     FROM {$table}
     WHERE start_date = '1970-01-01' AND is_placeholder = 0
     GROUP BY activity_type
     ORDER BY cnt DESC",
    ARRAY_A
);
$echo_section('start_date = 1970-01-01 (counts by activity_type)', $epoch_rows ?: []);
$epoch_sample = $wpdb->get_col(
    "SELECT id FROM {$table}
     WHERE start_date = '1970-01-01' AND is_placeholder = 0
     ORDER BY id DESC LIMIT 15"
);
echo "   sample ids: " . implode(', ', array_map('intval', $epoch_sample ?: [])) . "\n";

// booking_type other that would resolve from order item meta (ganze-woche / semaine-complete / full-week slugs).
$other_bt = $wpdb->get_results(
    "SELECT r.activity_type, COUNT(*) AS cnt
     FROM {$table} r
     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m
       ON m.order_item_id = r.order_item_id
      AND m.meta_key IN ('Booking Type', 'buchungstyp', 'type de réservation', 'pa_booking-type', '_intersoccer_canonical_booking_type')
     WHERE r.booking_type = 'other' AND r.is_placeholder = 0
       AND (
         LOWER(m.meta_value) LIKE '%ganze-woche%'
         OR LOWER(m.meta_value) LIKE '%semaine-complete%'
         OR LOWER(m.meta_value) LIKE '%semaine compl%'
         OR LOWER(m.meta_value) LIKE '%full-week%'
         OR LOWER(m.meta_value) LIKE '%full week%'
       )
     GROUP BY r.activity_type
     ORDER BY cnt DESC",
    ARRAY_A
);
$echo_section("booking_type 'other' with resolvable full-week/single-day meta", $other_bt ?: []);
$other_sample = $wpdb->get_col(
    "SELECT DISTINCT r.id
     FROM {$table} r
     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m
       ON m.order_item_id = r.order_item_id
      AND m.meta_key IN ('Booking Type', 'buchungstyp', 'type de réservation')
     WHERE r.booking_type = 'other' AND r.is_placeholder = 0
       AND LOWER(m.meta_value) LIKE '%ganze-woche%'
     ORDER BY r.id DESC LIMIT 15"
);
echo "   sample ids: " . implode(', ', array_map('intval', $other_sample ?: [])) . "\n";

// Girls-only orders without roster rows (orders with canonical girls_only on a line item, no roster for that item).
$missing_roster = $wpdb->get_results(
    "SELECT oim.meta_value AS girls_flag, COUNT(DISTINCT oi.order_item_id) AS cnt
     FROM {$wpdb->prefix}woocommerce_order_items oi
     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
       ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_intersoccer_canonical_girls_only'
     LEFT JOIN {$table} r ON r.order_item_id = oi.order_item_id AND r.is_placeholder = 0
     WHERE oim.meta_value IN ('1', 'yes') AND r.id IS NULL
     GROUP BY oim.meta_value",
    ARRAY_A
);
$echo_section('girls_only order lines with no roster row', $missing_roster ?: []);

// Flag vs activity_type text disagreement.
$disagree = $wpdb->get_results(
    "SELECT
       CASE WHEN girls_only = 1 THEN 'flag=1' ELSE 'flag=0' END AS flag_bucket,
       CASE
         WHEN activity_type LIKE '%Girls%' OR activity_type LIKE '%girls%' THEN 'text_girls'
         ELSE 'text_plain'
       END AS text_bucket,
       COUNT(*) AS cnt
     FROM {$table}
     WHERE is_placeholder = 0
       AND (
         (girls_only = 1 AND (activity_type NOT LIKE '%Girls%' AND activity_type NOT LIKE '%girls%' AND activity_type NOT IN ('Girls Only')))
         OR (girls_only = 0 AND (activity_type LIKE '%Girls%' OR activity_type LIKE '%girls%' OR activity_type = 'Girls Only'))
       )
     GROUP BY flag_bucket, text_bucket
     ORDER BY cnt DESC",
    ARRAY_A
);
$echo_section('girls_only flag vs activity_type text disagree', $disagree ?: []);
$disagree_sample = $wpdb->get_col(
    "SELECT id FROM {$table}
     WHERE is_placeholder = 0
       AND (
         (girls_only = 1 AND activity_type NOT LIKE '%Girls%' AND activity_type NOT LIKE '%girls%' AND activity_type NOT IN ('Girls Only'))
         OR (girls_only = 0 AND (activity_type LIKE '%Girls%' OR activity_type LIKE '%girls%' OR activity_type = 'Girls Only'))
       )
     ORDER BY id DESC LIMIT 15"
);
echo "   sample ids: " . implode(', ', array_map('intval', $disagree_sample ?: [])) . "\n";

echo "\nDRY RUN complete (no writes).\n";
