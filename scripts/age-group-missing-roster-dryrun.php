<?php
/**
 * Read-only count of paid order line items whose variation age group cannot be parsed by the
 * pre-fix Player parser (release 2.10.15 / master y-only dynamic ranges) and have no roster row.
 *
 * Usage (WP-CLI eval-file only; do not run against shared production DB from agents):
 *   wp eval-file scripts/age-group-missing-roster-dryrun.php
 */

if (!defined('ABSPATH')) {
    echo "This script must run under wp eval-file.\n";
    return;
}

use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Utils\AgeGroupHelper;

global $wpdb;

$roster_table = $wpdb->prefix . 'intersoccer_rosters';
$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
$order_items = $wpdb->prefix . 'woocommerce_order_items';
$posts = $wpdb->posts;
$postmeta = $wpdb->postmeta;
$terms = $wpdb->terms;
$term_taxonomy = $wpdb->term_taxonomy;

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

if (function_exists('intersoccer_reports_set_readonly_mode')) {
    intersoccer_reports_set_readonly_mode(true);
}

$predefined = AgeGroupHelper::flattenPredefinedCatalog(Player::AGE_GROUPS);

$fails_master_parser = static function (string $label) use ($predefined): bool {
    $label = trim($label);
    if ($label === '') {
        return false;
    }
    return AgeGroupHelper::parseMinMaxAsOnRelease21015($label, $predefined) === null;
};

$paid_statuses = "'wc-completed','wc-processing','wc-on-hold'";

$sql = "
    SELECT
        oi.order_item_id,
        CAST(variation.meta_value AS UNSIGNED) AS variation_id,
        attr.meta_value AS age_slug,
        COALESCE(t.name, attr.meta_value) AS age_group_label,
        COALESCE(
            NULLIF(activity.meta_value, ''),
            NULLIF(product_activity.meta_value, '')
        ) AS activity_type
    FROM {$order_items} oi
    INNER JOIN {$posts} o ON o.ID = oi.order_id
        AND o.post_type = 'shop_order'
        AND o.post_status IN ({$paid_statuses})
    INNER JOIN {$itemmeta} variation
        ON variation.order_item_id = oi.order_item_id
        AND variation.meta_key = '_variation_id'
        AND variation.meta_value > 0
    INNER JOIN {$postmeta} attr
        ON attr.post_id = variation.meta_value
        AND attr.meta_key = 'attribute_pa_age-group'
        AND attr.meta_value <> ''
    LEFT JOIN {$term_taxonomy} tt
        ON tt.taxonomy = 'pa_age-group'
        AND tt.slug = attr.meta_value
    LEFT JOIN {$terms} t ON t.term_id = tt.term_id
    LEFT JOIN {$itemmeta} activity
        ON activity.order_item_id = oi.order_item_id
        AND activity.meta_key = 'Activity Type'
    LEFT JOIN {$itemmeta} product_ref
        ON product_ref.order_item_id = oi.order_item_id
        AND product_ref.meta_key = '_product_id'
    LEFT JOIN {$postmeta} product_activity
        ON product_activity.post_id = product_ref.meta_value
        AND product_activity.meta_key = '_intersoccer_activity_type'
    LEFT JOIN {$roster_table} r ON r.order_item_id = oi.order_item_id
    WHERE oi.order_item_type = 'line_item'
      AND r.id IS NULL
";

$rows = $wpdb->get_results($sql, ARRAY_A);
if ($rows === null) {
    echo "ABORT: query failed: {$wpdb->last_error}\n";
    return;
}

$by_age_group = [];
$by_activity = [];
$total = 0;

foreach ($rows as $row) {
    $label = (string) ($row['age_group_label'] ?? '');
    if (!$fails_master_parser($label)) {
        continue;
    }
    ++$total;
    $by_age_group[$label] = ($by_age_group[$label] ?? 0) + 1;
    $activity = (string) ($row['activity_type'] ?? '(unknown)');
    if (!isset($by_activity[$activity])) {
        $by_activity[$activity] = [];
    }
    $by_activity[$activity][$label] = ($by_activity[$activity][$label] ?? 0) + 1;
}

ksort($by_age_group);
ksort($by_activity);

echo "== Paid order items: age group fails master parser, no roster row ==\n";
echo 'Total: ' . $total . "\n\n";

echo "By age-group string:\n";
if ($by_age_group === []) {
    echo "  (none)\n";
} else {
    foreach ($by_age_group as $label => $count) {
        echo '  ' . $count . ' × ' . $label . "\n";
    }
}

echo "\nBy activity type (then age group):\n";
if ($by_activity === []) {
    echo "  (none)\n";
} else {
    foreach ($by_activity as $activity => $groups) {
        echo '  ' . $activity . ":\n";
        ksort($groups);
        foreach ($groups as $label => $count) {
            echo '    ' . $count . ' × ' . $label . "\n";
        }
    }
}

echo "\n(read-only session; no roster rows created)\n";
