<?php
/**
 * Shared helpers for order financial attribution and booking report discount classification.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Current item-level financial attribution schema version.
 */
function intersoccer_financial_attribution_version(): string {
    return '2';
}

/**
 * Map EN/FR/DE fee or discount labels to a canonical discount type.
 */
function intersoccer_canonical_discount_type_from_label(string $label): string {
    if ($label === '') {
        return 'other';
    }

    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower(trim($label), 'UTF-8')
        : strtolower(trim($label));

    if (strpos($normalized, 'referral credits') !== false
        || strpos($normalized, 'loyalty points') !== false
        || strpos($normalized, 'points discount') !== false
        || strpos($normalized, 'crédits de parrainage') !== false
        || strpos($normalized, 'credits de parrainage') !== false
        || strpos($normalized, 'réduction de points') !== false
        || strpos($normalized, 'reduction de points') !== false
        || strpos($normalized, 'empfehlungscredits') !== false
        || strpos($normalized, 'punkte-rabatt') !== false
        || $normalized === 'referral credits discount'
    ) {
        return 'referral_points';
    }

    if ($normalized === 'referral discount'
        || strpos($normalized, 'referral discount') !== false
        || strpos($normalized, 'réduction de parrainage') !== false
        || strpos($normalized, 'reduction de parrainage') !== false
        || $normalized === 'empfehlungsrabatt'
        || strpos($normalized, 'empfehlungsrabatt') !== false
    ) {
        return 'referral_first_order';
    }

    if (strpos($normalized, 'sibling') !== false
        || strpos($normalized, 'multi-child') !== false
        || strpos($normalized, '2nd child') !== false
        || strpos($normalized, '3rd child') !== false
        || strpos($normalized, '2nd+ child') !== false
    ) {
        return 'sibling';
    }

    if (strpos($normalized, 'same season') !== false
        || strpos($normalized, 'même saison') !== false
        || strpos($normalized, 'second course') !== false
        || (strpos($normalized, '50%') !== false && strpos($normalized, 'season') !== false)
    ) {
        return 'same_season';
    }

    if (strpos($normalized, 'coupon') !== false
        || strpos($normalized, 'promo') !== false
        || strpos($normalized, 'promotional') !== false
    ) {
        return 'coupon';
    }

    return 'other';
}

/**
 * Classify a cart/order fee name into a canonical discount type.
 */
function intersoccer_classify_discount_fee(string $fee_name): string {
    $canonical = intersoccer_canonical_discount_type_from_label($fee_name);
    if ($canonical !== 'other') {
        return $canonical;
    }

    if (function_exists('intersoccer_determine_precise_discount_type')) {
        return (string) intersoccer_determine_precise_discount_type($fee_name);
    }

    return intersoccer_determine_discount_type_from_name($fee_name);
}

/**
 * Determine discount type from discount name (legacy item meta and fee labels).
 */
function intersoccer_determine_discount_type_from_name($discount_name) {
    if (empty($discount_name)) {
        return 'other';
    }

    return intersoccer_canonical_discount_type_from_label((string) $discount_name);
}

/**
 * Map a canonical discount type to a booking report summary bucket.
 *
 * @return string sibling|same_season|coupon|referral_first_order|referral_points|other
 */
function intersoccer_map_discount_type_to_report_bucket(string $type): string {
    $type = strtolower(trim($type));

    if (in_array($type, ['sibling', 'multi-child', 'camp_sibling', 'course_multi_child', 'camp_other', 'course_other'], true)) {
        return 'sibling';
    }

    if (in_array($type, ['same_season', 'same-season', 'second_course', 'second-course', 'course_same_season'], true)) {
        return 'same_season';
    }

    if (in_array($type, ['coupon', 'promo', 'promotional'], true)) {
        return 'coupon';
    }

    if ($type === 'referral_first_order') {
        return 'referral_first_order';
    }

    if ($type === 'referral_points') {
        return 'referral_points';
    }

    return 'other';
}

/**
 * Parse a legacy HTML or plain-text discount amount from order item meta.
 */
function intersoccer_parse_legacy_discount_amount($value): float {
    if ($value === null || $value === '') {
        return 0.0;
    }

    if (is_numeric($value)) {
        return (float) $value;
    }

    $string_value = (string) $value;
    if (preg_match('/(\d+\.?\d*)/', $string_value, $matches)) {
        return (float) $matches[1];
    }

    return (float) strip_tags($string_value);
}

/**
 * Correct persisted item discount meta that double-counted FR/DE referral/points fees (order_fee + referral_order_meta).
 *
 * Read-only at report time: adjusts the in-memory total and breakdown only.
 *
 * @param array<int,array<string,mixed>> $breakdown
 * @return array{amount:float,breakdown:array<int,array<string,mixed>>}
 */
function intersoccer_reconcile_stale_persisted_item_discount(float $amount, array $breakdown): array {
    if ($breakdown === []) {
        return ['amount' => $amount, 'breakdown' => $breakdown];
    }

    $adjusted = $breakdown;
    $removed = 0.0;

    foreach (['referral_points', 'referral_first_order'] as $canonical_type) {
        $fee_index = null;
        $meta_index = null;

        foreach ($adjusted as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $source = (string) ($entry['source'] ?? '');
            $type = (string) ($entry['type'] ?? '');
            $name = (string) ($entry['name'] ?? '');

            if ($source === 'order_fee' && intersoccer_classify_discount_fee($name) === $canonical_type) {
                $fee_index = $index;
            }
            if ($source === 'referral_order_meta' && $type === $canonical_type) {
                $meta_index = $index;
            }
        }

        if ($fee_index === null || $meta_index === null) {
            continue;
        }

        $removed += (float) ($adjusted[$meta_index]['amount'] ?? 0.0);
        unset($adjusted[$meta_index]);
        $adjusted[$fee_index]['type'] = $canonical_type;
    }

    if ($removed <= 0.0) {
        return ['amount' => $amount, 'breakdown' => $breakdown];
    }

    return [
        'amount' => max(0.0, round($amount - $removed, 2)),
        'breakdown' => array_values($adjusted),
    ];
}

/**
 * Calculate discount type breakdown from booking report data.
 */
function intersoccer_calculate_discount_type_breakdown($report_data) {
    $totals = [
        'sibling' => 0,
        'same_season' => 0,
        'coupon' => 0,
        'referral_first_order' => 0,
        'referral_points' => 0,
        'other' => 0,
    ];

    foreach ($report_data as $row) {
        $discount_amount = floatval(str_replace([',', ' CHF'], '', $row['discount_amount'] ?? '0'));

        if ($discount_amount <= 0) {
            continue;
        }

        if (isset($row['discount_breakdown']) && is_array($row['discount_breakdown']) && !empty($row['discount_breakdown'])) {
            foreach ($row['discount_breakdown'] as $disc) {
                if (!isset($disc['type']) || !isset($disc['amount'])) {
                    continue;
                }

                $bucket = intersoccer_map_discount_type_to_report_bucket((string) $disc['type']);
                $totals[$bucket] += floatval($disc['amount']);
            }
        } elseif (isset($row['discount_type']) && !empty($row['discount_type'])) {
            $bucket = intersoccer_map_discount_type_to_report_bucket((string) $row['discount_type']);
            $totals[$bucket] += $discount_amount;
        } else {
            $discount_codes = strtolower($row['discount_codes'] ?? '');

            if (strpos($discount_codes, 'sibling') !== false || strpos($discount_codes, 'multi-child') !== false) {
                $totals['sibling'] += $discount_amount;
            } elseif (strpos($discount_codes, 'same-season') !== false || strpos($discount_codes, 'second-course') !== false) {
                $totals['same_season'] += $discount_amount;
            } elseif (strpos($discount_codes, 'referral discount') !== false) {
                $totals['referral_first_order'] += $discount_amount;
            } elseif (strpos($discount_codes, 'referral credits') !== false || strpos($discount_codes, 'points') !== false) {
                $totals['referral_points'] += $discount_amount;
            } elseif (!empty($discount_codes) && $discount_codes !== 'none') {
                $totals['coupon'] += $discount_amount;
            } else {
                $totals['other'] += $discount_amount;
            }
        }
    }

    return $totals;
}
