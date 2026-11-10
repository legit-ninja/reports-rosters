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
 * Normalize a booking-report filter value to a calendar Y-m-d, or null when empty/invalid.
 *
 * @param mixed $value Date string (optional time portion is ignored).
 */
function intersoccer_booking_report_normalize_ymd($value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
        $value = $matches[1];
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

/**
 * Site-local today (Y-m-d) for booking report default end dates.
 */
function intersoccer_booking_report_site_today_ymd(): string {
    if (function_exists('wp_date')) {
        return wp_date('Y-m-d');
    }
    $local = current_time('Y-m-d');
    if (is_string($local) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $local)) {
        return $local;
    }
    return date('Y-m-d');
}

/**
 * Inclusive local-date range for filtering p.post_date (stored in site local time, not UTC).
 *
 * Returns [start_inclusive, end_exclusive) as Y-m-d H:i:s bounds, or null per side when that side is empty/invalid.
 *
 * @return array{0:?string,1:?string}
 */
function intersoccer_booking_report_date_bounds($start_date, $end_date): array {
    $start_ymd = intersoccer_booking_report_normalize_ymd($start_date);
    $end_ymd = intersoccer_booking_report_normalize_ymd($end_date);

    $start_inclusive = $start_ymd !== null ? $start_ymd . ' 00:00:00' : null;

    $end_exclusive = null;
    if ($end_ymd !== null) {
        $end_day = DateTime::createFromFormat('!Y-m-d', $end_ymd);
        if ($end_day) {
            $end_day->modify('+1 day');
            $end_exclusive = $end_day->format('Y-m-d') . ' 00:00:00';
        }
    }

    return [$start_inclusive, $end_exclusive];
}

/**
 * Whether normalized start is after normalized end (legacy booking report empty-range case).
 */
function intersoccer_booking_report_start_after_end($start_date, $end_date): bool {
    $start_ymd = intersoccer_booking_report_normalize_ymd($start_date);
    $end_ymd = intersoccer_booking_report_normalize_ymd($end_date);
    if ($start_ymd === null || $end_ymd === null) {
        return false;
    }
    return strcmp($start_ymd, $end_ymd) > 0;
}

/**
 * Booking report SQL fragment for filtering p.post_date (local half-open range).
 *
 * When a date filter was requested but cannot be built, falls back to YEAR() when $year is set;
 * otherwise AND 1=0 so the query never returns unconstrained history.
 *
 * @return array{0:string,1:array<int,mixed>}
 */
function intersoccer_booking_report_post_date_filter_sql(
    string $start_date,
    string $end_date,
    $year = '',
    bool $allow_open_ended = false
): array {
    $year_clause = static function () use ($year): ?array {
        if ($year === '' || $year === null || $year === false) {
            return null;
        }
        $y = (int) $year;
        if ($y <= 0) {
            return null;
        }
        return [' AND YEAR(p.post_date) = %d', [$y]];
    };

    $reject_or_year = static function () use ($year_clause): array {
        $yf = $year_clause();
        if ($yf !== null) {
            return $yf;
        }
        return [' AND 1=0', []];
    };

    $has_start = $start_date !== '';
    $has_end = $end_date !== '';

    if ($has_start && $has_end) {
        if (intersoccer_booking_report_start_after_end($start_date, $end_date)) {
            $start_ymd = intersoccer_booking_report_normalize_ymd($start_date);
            $end_ymd = intersoccer_booking_report_normalize_ymd($end_date);
            if ($start_ymd === null || $end_ymd === null) {
                return $reject_or_year();
            }
            return [
                ' AND p.post_date >= %s AND p.post_date <= %s',
                [$start_ymd, $end_ymd],
            ];
        }
        [$start_bound, $end_exclusive] = intersoccer_booking_report_date_bounds($start_date, $end_date);
        if ($start_bound !== null && $end_exclusive !== null) {
            return [' AND p.post_date >= %s AND p.post_date < %s', [$start_bound, $end_exclusive]];
        }
        return $reject_or_year();
    }

    if ($allow_open_ended) {
        if ($has_start) {
            [$start_bound, ] = intersoccer_booking_report_date_bounds($start_date, '');
            if ($start_bound !== null) {
                return [' AND p.post_date >= %s', [$start_bound]];
            }
            return $reject_or_year();
        }
        if ($has_end) {
            [, $end_exclusive] = intersoccer_booking_report_date_bounds('', $end_date);
            if ($end_exclusive !== null) {
                return [' AND p.post_date < %s', [$end_exclusive]];
            }
            return $reject_or_year();
        }
    }

    $yf = $year_clause();
    if ($yf !== null) {
        return $yf;
    }

    return ['', []];
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
 * True when a breakdown entry is a product-variation sibling discount already
 * included in the WooCommerce line total.
 *
 * Order-level fees and coupons are not baked into that total, even when the
 * label classifies as a sibling discount.
 *
 * @param array<string,mixed> $entry
 */
function intersoccer_financial_discount_entry_is_baked_line_sibling(array $entry): bool {
    $source = strtolower(trim((string) ($entry['source'] ?? '')));
    if (in_array($source, [
        'order_fee',
        'woocommerce_coupon',
        'coupon_precise',
        'referral_order_meta',
        'combo_fee',
        'combo_precise',
    ], true)) {
        return false;
    }

    $type = strtolower(trim((string) ($entry['type'] ?? '')));
    if ($type === '' && isset($entry['name']) && (string) $entry['name'] !== '') {
        $type = intersoccer_determine_discount_type_from_name($entry['name']);
    }

    return intersoccer_map_discount_type_to_report_bucket($type) === 'sibling';
}

/**
 * Portion of an attributed discount that is a baked-in line sibling discount.
 *
 * @param array<int,mixed> $discount_breakdown
 */
function intersoccer_financial_baked_line_sibling_discount_amount(array $discount_breakdown): float {
    $baked = 0.0;

    foreach ($discount_breakdown as $entry) {
        if (is_object($entry)) {
            $entry = (array) $entry;
        }
        if (!is_array($entry) || !intersoccer_financial_discount_entry_is_baked_line_sibling($entry)) {
            continue;
        }

        $amount = isset($entry['amount']) ? (float) $entry['amount'] : 0.0;
        if ($amount > 0.0) {
            $baked += $amount;
        }
    }

    return $baked;
}

/**
 * Post-discount line revenue before refunds (WooCommerce line total semantics).
 *
 * When subtotal already equals line total, subtract attributed discount that is
 * not a product-variation sibling discount already included in that total.
 * Order-level fees and coupons listed in the breakdown are still subtracted.
 * With no breakdown, the full attributed amount is subtracted: a three-argument
 * call cannot tell a baked-in sibling discount from an order-level fee.
 *
 * @param array<int,array<string,mixed>> $discount_breakdown
 */
function intersoccer_financial_line_final_price(
    float $line_subtotal,
    float $line_total,
    float $attributed_discount_amount,
    array $discount_breakdown = []
): float {
    if ($attributed_discount_amount <= 0.01) {
        return max(0.0, $line_total);
    }

    if (abs($line_subtotal - $line_total) < 0.02) {
        $baked = intersoccer_financial_baked_line_sibling_discount_amount($discount_breakdown);
        if ($baked > $attributed_discount_amount) {
            $baked = $attributed_discount_amount;
        }

        return max(0.0, $line_total - ($attributed_discount_amount - $baked));
    }

    return max(0.0, $line_subtotal - $attributed_discount_amount);
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
 * **Read path only** — FinancialReportService calls this when loading persisted line breakdowns.
 * Legacy PV line meta vs order-level fees is handled at attribution write time (order-level bucket
 * matching in OrderFinancialAttributionService), not here.
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

    $changed = $removed > 0.0;
    foreach ($adjusted as $index => $entry) {
        if (!is_array($entry) || (string) ($entry['source'] ?? '') !== 'order_fee') {
            continue;
        }

        $name = (string) ($entry['name'] ?? '');
        $canonical = intersoccer_classify_discount_fee($name);
        if ($canonical !== 'referral_points' && $canonical !== 'referral_first_order') {
            continue;
        }

        foreach ($adjusted as $other) {
            if (is_array($other)
                && (string) ($other['source'] ?? '') === 'referral_order_meta'
                && (string) ($other['type'] ?? '') === $canonical
            ) {
                continue 2;
            }
        }

        if ((string) ($entry['type'] ?? '') !== $canonical) {
            $adjusted[$index]['type'] = $canonical;
            $changed = true;
        }
    }

    if (!$changed) {
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
