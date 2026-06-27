<?php
/**
 * Validates booking report financial accuracy against WooCommerce order data.
 *
 * @package InterSoccer\ReportsRosters\Services
 */

namespace InterSoccer\ReportsRosters\Services;

defined('ABSPATH') or die('Restricted access');

class BookingReportAccuracyValidator {

    private const RECONCILIATION_TOLERANCE = 0.10;

    /** @var string */
    private $runId = 'validation';

    /**
     * @param array<int,array<string,mixed>> $raw_rows  Unformatted row metrics keyed sequentially.
     * @param array<string,mixed>            $totals
     * @param array<string,mixed>            $filters
     */
    public function validate(array $raw_rows, array $totals, array $filters = []): array {
        $this->runId = (string) ($filters['run_id'] ?? 'validation');

        if (!$this->isEnabled()) {
            return ['enabled' => false];
        }

        $summary = [
            'enabled' => true,
            'row_count' => count($raw_rows),
            'order_count' => 0,
            'anomaly_count' => 0,
            'missing_attribution_orders' => 0,
            'fallback_discount_rows' => 0,
            'refund_mismatch_orders' => 0,
            'reconciliation_mismatch_orders' => 0,
            'breakdown_mismatch' => false,
        ];

        $this->writeLog([
            'hypothesisId' => 'START',
            'location' => 'BookingReportAccuracyValidator::validate',
            'message' => 'Booking report validation started',
            'data' => [
                'filters' => $filters,
                'row_count' => count($raw_rows),
                'totals' => $totals,
            ],
        ]);
        $by_order = [];
        foreach ($raw_rows as $row) {
            $order_id = (int) ($row['order_id'] ?? 0);
            if ($order_id <= 0) {
                continue;
            }
            if (!isset($by_order[$order_id])) {
                $by_order[$order_id] = [];
            }
            $by_order[$order_id][] = $row;
        }

        $summary['order_count'] = count($by_order);

        foreach ($by_order as $order_id => $rows) {
            $this->validateOrder((int) $order_id, $rows, $summary);
        }

        $this->validateAggregateTotals($raw_rows, $totals, $summary);

        $this->writeLog([
            'hypothesisId' => 'SUMMARY',
            'location' => 'BookingReportAccuracyValidator::validate',
            'message' => 'Booking report validation completed',
            'data' => $summary,
        ]);
        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $summary
     */
    private function validateOrder(int $order_id, array $rows, array &$summary): void {
        $report_base = 0.0;
        $report_discount = 0.0;
        $report_reimbursement = 0.0;
        $report_final = 0.0;
        $fallback_rows = 0;
        $missing_attribution_rows = 0;

        foreach ($rows as $row) {
            $report_base += (float) ($row['base_price'] ?? 0);
            $report_discount += (float) ($row['discount_amount'] ?? 0);
            $report_reimbursement += (float) ($row['reimbursement'] ?? 0);
            $report_final += (float) ($row['final_price'] ?? 0);

            if (($row['discount_source'] ?? '') === 'fallback') {
                $fallback_rows++;
            }
            if (empty($row['has_attribution_meta'])) {
                $missing_attribution_rows++;
            }
        }

        if ($fallback_rows > 0) {
            $summary['fallback_discount_rows'] += $fallback_rows;
            $this->writeLog([
                'hypothesisId' => 'D',
                'location' => 'BookingReportAccuracyValidator::validateOrder',
                'message' => 'Rows using fallback discount calculation',
                'data' => [
                    'order_id' => $order_id,
                    'fallback_row_count' => $fallback_rows,
                    'line_item_count' => count($rows),
                ],
            ]);
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            $this->writeLog([
                'hypothesisId' => 'B',
                'location' => 'BookingReportAccuracyValidator::validateOrder',
                'message' => 'Could not load WC order for reconciliation',
                'data' => ['order_id' => $order_id],
            ]);
            return;
        }

        $has_order_fees = $this->orderHasDiscountFees($order);
        $has_referral_meta = $this->orderHasReferralMeta($order);
        $has_attribution_stamp = (bool) $order->get_meta(OrderFinancialAttributionService::META_ORDER_ATTRIBUTION_AT, true);

        if ($missing_attribution_rows > 0 && ($has_order_fees || $has_referral_meta)) {
            $summary['missing_attribution_orders']++;
            $summary['anomaly_count']++;
            $this->writeLog([
                'hypothesisId' => 'A',
                'location' => 'BookingReportAccuracyValidator::validateOrder',
                'message' => 'Order has fees/referral meta but report rows lack item attribution',
                'data' => [
                    'order_id' => $order_id,
                    'missing_attribution_rows' => $missing_attribution_rows,
                    'has_order_fees' => $has_order_fees,
                    'has_referral_meta' => $has_referral_meta,
                    'has_attribution_stamp' => $has_attribution_stamp,
                    'order_fee_total' => round($this->sumOrderFeeDiscounts($order), 2),
                ],
            ]);
        }

        $wc_total = (float) $order->get_total();
        $wc_tax = (float) $order->get_total_tax();
        $wc_total_ex_tax = $wc_total - $wc_tax;
        $wc_refunded = (float) $order->get_total_refunded();
        $wc_subtotal = (float) $order->get_subtotal();

        $delta_ex_tax = abs($report_final - $wc_total_ex_tax);
        if ($delta_ex_tax > self::RECONCILIATION_TOLERANCE) {
            $summary['reconciliation_mismatch_orders']++;
            $summary['anomaly_count']++;
            $this->writeLog([
                'hypothesisId' => 'B',
                'location' => 'BookingReportAccuracyValidator::validateOrder',
                'message' => 'Report net per order does not reconcile with WC total (ex tax)',
                'data' => [
                    'order_id' => $order_id,
                    'report_final_sum' => round($report_final, 2),
                    'report_base_sum' => round($report_base, 2),
                    'report_discount_sum' => round($report_discount, 2),
                    'report_reimbursement_sum' => round($report_reimbursement, 2),
                    'wc_total' => round($wc_total, 2),
                    'wc_total_ex_tax' => round($wc_total_ex_tax, 2),
                    'wc_subtotal' => round($wc_subtotal, 2),
                    'wc_refunded' => round($wc_refunded, 2),
                    'delta_ex_tax' => round($delta_ex_tax, 2),
                    'order_status' => $order->get_status(),
                ],
            ]);
        }

        $refund_delta = abs($report_reimbursement - $wc_refunded);
        if ($wc_refunded > 0.01 && $refund_delta > self::RECONCILIATION_TOLERANCE) {
            $summary['refund_mismatch_orders']++;
            $summary['anomaly_count']++;
            $this->writeLog([
                'hypothesisId' => 'C',
                'location' => 'BookingReportAccuracyValidator::validateOrder',
                'message' => 'Report reimbursement sum does not match WC total refunded',
                'data' => [
                    'order_id' => $order_id,
                    'report_reimbursement_sum' => round($report_reimbursement, 2),
                    'wc_total_refunded' => round($wc_refunded, 2),
                    'refund_delta' => round($refund_delta, 2),
                ],
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $raw_rows
     * @param array<string,mixed>            $totals
     * @param array<string,mixed>            $summary
     */
    private function validateAggregateTotals(array $raw_rows, array $totals, array &$summary): void {
        if (!function_exists('intersoccer_calculate_discount_type_breakdown')) {
            return;
        }

        $formatted_rows = [];
        foreach ($raw_rows as $row) {
            $formatted_rows[] = [
                'discount_amount' => number_format((float) ($row['discount_amount'] ?? 0), 2),
                'discount_type' => (string) ($row['discount_type'] ?? 'other'),
                'discount_breakdown' => $row['discount_breakdown'] ?? [],
            ];
        }

        $breakdown = intersoccer_calculate_discount_type_breakdown($formatted_rows);
        $breakdown_sum = array_sum($breakdown);
        $totals_discount = (float) ($totals['discount_amount'] ?? 0);
        $delta = abs($breakdown_sum - $totals_discount);

        if ($delta > self::RECONCILIATION_TOLERANCE) {
            $summary['breakdown_mismatch'] = true;
            $summary['anomaly_count']++;
            $this->writeLog([
                'hypothesisId' => 'E',
                'location' => 'BookingReportAccuracyValidator::validateAggregateTotals',
                'message' => 'Discount type breakdown sum does not match report discount total',
                'data' => [
                    'totals_discount_amount' => round($totals_discount, 2),
                    'breakdown_sum' => round($breakdown_sum, 2),
                    'breakdown' => $breakdown,
                    'delta' => round($delta, 2),
                ],
            ]);
        }
    }

    private function orderHasDiscountFees($order): bool {
        foreach ($order->get_items('fee') as $fee) {
            if ((float) $fee->get_total() < -0.01) {
                return true;
            }
        }
        return false;
    }

    private function orderHasReferralMeta($order): bool {
        $first = (float) $order->get_meta('_intersoccer_first_order_discount_amount', true);
        $points = (float) $order->get_meta('_intersoccer_points_redeemed', true);
        return $first > 0.01 || $points > 0.01;
    }

    private function sumOrderFeeDiscounts($order): float {
        $total = 0.0;
        foreach ($order->get_items('fee') as $fee) {
            $amount = (float) $fee->get_total();
            if ($amount < 0) {
                $total += abs($amount);
            }
        }
        return $total;
    }

    private function isEnabled(): bool {
        return function_exists('intersoccer_booking_report_accuracy_debug_enabled')
            && intersoccer_booking_report_accuracy_debug_enabled();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeLog(array $payload): void {
        if (function_exists('intersoccer_booking_report_accuracy_debug_log')) {
            intersoccer_booking_report_accuracy_debug_log(array_merge(['runId' => $this->runId], $payload));
        }
    }
}
