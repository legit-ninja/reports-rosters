<?php
/**
 * Financial Report Service
 *
 * Generates booking/financial report datasets for the admin UI.
 *
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.1.0
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Campaign\FacetNormalizer;

defined('ABSPATH') or die('Restricted access');

class FinancialReportService {

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Database
     */
    private $database;

    /**
     * @var \wpdb
     */
    private $wpdb;

    /**
     * @var ProductTypeClassifierService|null
     */
    private $productTypeClassifier;

    /**
     * @var array<int,array{items:array<int,array{discount_total:float,breakdown:array<int,array<string,mixed>>,refund:float}>}|null>
     */
    private $inMemoryAttributionCache = [];

    /**
     * Constructor.
     */
    public function __construct(Logger $logger = null, Database $database = null, ProductTypeClassifierService $productTypeClassifier = null) {
        $this->logger = $logger ?: new Logger();
        $this->database = $database ?: new Database($this->logger);
        $this->wpdb = $this->database->get_wpdb();
        $this->productTypeClassifier = $productTypeClassifier;
    }

    /**
     * Get or create ProductTypeClassifierService instance.
     */
    private function getProductTypeClassifier(): ProductTypeClassifierService {
        if ($this->productTypeClassifier === null) {
            $this->productTypeClassifier = new ProductTypeClassifierService(new FacetNormalizer());
        }
        return $this->productTypeClassifier;
    }

    /**
     * Get booking/financial report data.
     *
     * Options (all optional; omitted values keep the post_date booking-report filter):
     * - date_basis: `paid_or_created` selects orders by paid timestamp, or by created
     *   date when no paid timestamp is stored. `paid_only` selects by paid timestamp
     *   and does not fall back to the created date. Bounds are paid_start_unix
     *   (inclusive), paid_end_unix (exclusive), created_start_local, and created_end_local.
     * - order_statuses: used only when date_basis is `paid_only`, `paid_any`, or
     *   `created_only`. `created_only` selects by created_start_local (inclusive)
     *   and created_end_local (exclusive) and does not require a paid date.
     *   `include_line_meta` on that basis also reads line tax. `paid_any` selects
     *   paid orders of any date and does not apply a created-date or paid-date
     *   window. Every other basis keeps the booking-report status list. The
     *   default call, with no date_basis, still filters post_date and the
     *   booking-report statuses.
     *
     * Net, discount, and refund math is unchanged. The paid basis only changes which
     * rows are read and adds raw amount fields used by the daily sales email.
     *
     * @param array<string,mixed> $options
     */
    public function getFinancialBookingReport(string $start_date = '', string $end_date = '', string $year = '', string $region = '', array $options = []): array {
        if (!function_exists('intersoccer_booking_report_date_bounds')) {
            require_once dirname(__DIR__, 2) . '/includes/financial-attribution-helpers.php';
        }
        $this->logger->info('FinancialReportService: Generating financial report', [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'year' => $year,
            'region' => $region,
        ]);

        $date_basis = isset($options['date_basis']) ? (string) $options['date_basis'] : 'post_date';
        $include_line_meta = $date_basis !== 'post_date' && !empty($options['include_line_meta']);

        [$date_sql, $date_params] = intersoccer_booking_report_post_date_filter_sql(
            (string) $start_date,
            (string) $end_date,
            $year,
            false
        );
        $date_where = '';
        if ($date_sql !== '') {
            $date_where = $date_params === []
                ? $date_sql
                : $this->wpdb->prepare($date_sql, ...$date_params);
        }

        $select_extra = '';
        $join_extra = '';
        $status_in = "('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')";
        if ($date_basis === 'paid_only' || $date_basis === 'paid_or_created') {
            $paid_start = isset($options['paid_start_unix']) ? (int) $options['paid_start_unix'] : 0;
            $paid_end = isset($options['paid_end_unix']) ? (int) $options['paid_end_unix'] : 0;
            $created_start = isset($options['created_start_local']) ? (string) $options['created_start_local'] : '';
            $created_end = isset($options['created_end_local']) ? (string) $options['created_end_local'] : '';
            $select_extra = ",
                paid_meta.meta_value as date_paid_gmt,
                oim_tax.meta_value as line_tax";
            $join_extra = "
            LEFT JOIN {$this->wpdb->postmeta} paid_meta ON p.ID = paid_meta.post_id AND paid_meta.meta_key = '_date_paid'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_tax ON oi.order_item_id = oim_tax.order_item_id AND oim_tax.meta_key = '_line_tax'";
            if ($date_basis === 'paid_only') {
                $custom_status = $this->orderStatusInSql(isset($options['order_statuses']) ? $options['order_statuses'] : null);
                if ($custom_status !== '') {
                    $status_in = $custom_status;
                }
                if ($paid_start <= 0 || $paid_end <= $paid_start) {
                    $date_where = ' AND 1=0';
                } else {
                    $date_where = $this->wpdb->prepare(
                        " AND (paid_meta.meta_value IS NOT NULL AND paid_meta.meta_value <> '' AND paid_meta.meta_value <> '0' AND CAST(paid_meta.meta_value AS UNSIGNED) >= %d AND CAST(paid_meta.meta_value AS UNSIGNED) < %d)",
                        $paid_start,
                        $paid_end
                    );
                }
            } elseif ($paid_start <= 0 || $paid_end <= $paid_start || $created_start === '' || $created_end === '') {
                $date_where = ' AND 1=0';
            } else {
                $date_where = $this->wpdb->prepare(
                    " AND ((paid_meta.meta_value IS NOT NULL AND paid_meta.meta_value <> '' AND paid_meta.meta_value <> '0' AND CAST(paid_meta.meta_value AS UNSIGNED) >= %d AND CAST(paid_meta.meta_value AS UNSIGNED) < %d) OR ((paid_meta.meta_value IS NULL OR paid_meta.meta_value = '' OR paid_meta.meta_value = '0') AND p.post_date >= %s AND p.post_date < %s))",
                    $paid_start,
                    $paid_end,
                    $created_start,
                    $created_end
                );
            }
        } elseif ($date_basis === 'created_only') {
            $custom_status = $this->orderStatusInSql(isset($options['order_statuses']) ? $options['order_statuses'] : null);
            if ($custom_status !== '') {
                $status_in = $custom_status;
            }
            $created_start = isset($options['created_start_local']) ? (string) $options['created_start_local'] : '';
            $created_end = isset($options['created_end_local']) ? (string) $options['created_end_local'] : '';
            if ($created_start === '' || $created_end === '') {
                $date_where = ' AND 1=0';
            } else {
                $date_where = $this->wpdb->prepare(
                    ' AND p.post_date >= %s AND p.post_date < %s',
                    $created_start,
                    $created_end
                );
            }
            if (!empty($options['include_line_meta'])) {
                $select_extra = ",
                oim_tax.meta_value as line_tax";
                $join_extra = "
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_tax ON oi.order_item_id = oim_tax.order_item_id AND oim_tax.meta_key = '_line_tax'";
            }
        } elseif ($date_basis === 'paid_any') {
            $custom_status = $this->orderStatusInSql(isset($options['order_statuses']) ? $options['order_statuses'] : null);
            if ($custom_status !== '') {
                $status_in = $custom_status;
            }
            $select_extra = ",
                paid_meta.meta_value as date_paid_gmt";
            $join_extra = "
            LEFT JOIN {$this->wpdb->postmeta} paid_meta ON p.ID = paid_meta.post_id AND paid_meta.meta_key = '_date_paid'";
            $date_where = " AND (paid_meta.meta_value IS NOT NULL AND paid_meta.meta_value <> '' AND paid_meta.meta_value <> '0')";
        }

        $region_where = '';
        if (!empty($region) && $region !== 'all') {
            $this->logger->debug('FinancialReportService: Region filtering requested, but not yet implemented.', [
                'region' => $region,
            ]);
        }

        $query = "
            SELECT
                p.ID as order_id,
                p.post_date as order_date,
                p.post_status as order_status,
                oi.order_item_id,
                oi.order_item_name,
                oim_product.meta_value as product_id,
                oim_variation.meta_value as variation_id,
                oim_qty.meta_value as quantity,
                oim_total.meta_value as line_total,
                oim_subtotal.meta_value as line_subtotal{$select_extra}
            FROM {$this->wpdb->posts} p
            INNER JOIN {$this->wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_product ON oi.order_item_id = oim_product.order_item_id AND oim_product.meta_key = '_product_id'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_variation ON oi.order_item_id = oim_variation.order_item_id AND oim_variation.meta_key = '_variation_id'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_subtotal ON oi.order_item_id = oim_subtotal.order_item_id AND oim_subtotal.meta_key = '_line_subtotal'
            {$join_extra}
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN {$status_in}
            {$date_where}
            {$region_where}
            ORDER BY p.post_date DESC, p.ID DESC
        ";

        $order_rows = $this->wpdb->get_results($query);

        $data = [];
        $totals = [
            'bookings' => 0,
            'places' => 0,
            'zero_total_buyclub_bookings' => 0,
            'base_price' => 0.0,
            'discount_amount' => 0.0,
            'final_price' => 0.0,
            'reimbursement' => 0.0,
        ];

        // Initialize revenue by product type aggregation
        $productTypeClassifier = $this->getProductTypeClassifier();
        $revenueByType = [];
        foreach (ProductTypeClassifierService::getValidBuckets() as $bucket) {
            $revenueByType[$bucket] = [
                'gross' => 0.0,
                'final' => 0.0,
                'net' => 0.0,
                'count' => 0,
            ];
        }

        $canonical_product_names = function_exists('intersoccer_reports_index_canonical_booking_product_names')
            ? intersoccer_reports_index_canonical_booking_product_names((array) $order_rows)
            : [];

        $zero_total_buyclub_bookings = 0;

        foreach ((array) $order_rows as $row) {
            $order_id = (int) $row->order_id;

            if (empty($row->product_id)) {
                continue;
            }

            $line_subtotal_pre = (float) $row->line_subtotal;
            $line_total_pre = (float) $row->line_total;
            if (function_exists('intersoccer_reports_order_billing_company_is_buyclub')
                && intersoccer_reports_order_billing_company_is_buyclub($order_id)) {
                continue;
            }
            $line_is_buyclub = function_exists('intersoccer_reports_line_is_buyclub')
                && intersoccer_reports_line_is_buyclub($line_subtotal_pre, $line_total_pre, $order_id);

            $item_meta = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$this->wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id = %d",
                $row->order_item_id
            ));

            $meta_map = [];
            foreach ((array) $item_meta as $meta) {
                $meta_map[$meta->meta_key] = $meta->meta_value;
            }

            $attendee_name = $this->extractMetaValue($meta_map, [
                'Attendee Name', 'Child Name', 'Player Name'
            ]);

            $attendee_age = $this->extractMetaValue($meta_map, [
                'Attendee Age', 'Child Age'
            ]);

            $attendee_gender = $this->extractMetaValue($meta_map, [
                'Attendee Gender', 'Child Gender'
            ]);

            $parent_phone = $this->extractMetaValue($meta_map, [
                'Emergency Phone', 'Parent Phone'
            ]);

            $selected_days = $this->extractMetaValue($meta_map, [
                'Days Selected', 'Days of Week'
            ]);

            $age_group = $this->extractMetaValue($meta_map, ['pa_age-group', 'attribute_pa_age-group', 'Age Group']);
            $activity_type_display = $this->extractMetaValue($meta_map, ['pa_booking-type', 'attribute_pa_booking-type', 'Activity Type']);
            $venue = $this->extractMetaValue($meta_map, ['pa_intersoccer-venues', 'attribute_pa_intersoccer-venues', 'Sites InterSoccer', 'InterSoccer Venues']);

            // Classify product type using the classification chain
            $productType = $productTypeClassifier->classifyOrderItem($meta_map, (int) $row->product_id);

            $base_price = (float) $row->line_subtotal;
            $line_total = (float) $row->line_total;
            $item_id = (int) $row->order_item_id;
            $has_persisted_discount = isset($meta_map[OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL])
                && $meta_map[OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL] !== '';
            $computed = $has_persisted_discount ? null : $this->getInMemoryItemAttribution($order_id, $item_id);
            $discount_data = $this->resolveItemDiscountData($meta_map, $base_price, $line_total, $order_id, $item_id, $computed);
            $discount_amount = $discount_data['amount'];
            $item_discount_breakdown = $discount_data['breakdown'];

            $reimbursement = 0.0;
            $has_persisted_refund = isset($meta_map[OrderFinancialAttributionService::META_ITEM_REFUND])
                && $meta_map[OrderFinancialAttributionService::META_ITEM_REFUND] !== '';
            if ($has_persisted_refund) {
                $reimbursement = (float) $meta_map[OrderFinancialAttributionService::META_ITEM_REFUND];
            } elseif ($computed !== null && ($computed['refund'] ?? 0) > 0.01) {
                $reimbursement = (float) $computed['refund'];
            }

            // Final = line total, minus order-level fees that are not already in the line.
            $final_price = intersoccer_financial_line_final_price(
                $base_price,
                $line_total,
                $discount_amount,
                $item_discount_breakdown
            );
            // Net = Final - attributed line refund (once)
            $net_price = max(0.0, $final_price - $reimbursement);

            // Aggregate by product type
            $revenueByType[$productType]['gross'] += $base_price;
            $revenueByType[$productType]['final'] += $final_price;
            $revenueByType[$productType]['net'] += $net_price;
            $revenueByType[$productType]['count']++;

            if ($line_is_buyclub && $final_price <= 0.0) {
                $zero_total_buyclub_bookings++;
            }

            $coupons = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT order_item_name FROM {$this->wpdb->prefix}woocommerce_order_items
                 WHERE order_id = %d AND order_item_type = 'coupon'",
                $order_id
            ));

            $discounts_applied = $this->formatDiscountsApplied($item_discount_breakdown, $discount_amount, $meta_map, $coupons);
            $discount_type = $this->resolvePrimaryDiscountType($item_discount_breakdown, $meta_map, $coupons);

            $stripe_fee = $final_price > 0 ? ($final_price * 0.029) + 0.30 : 0.0;

            $line_qty = (int) ($row->quantity ?? 0);

            $data[] = [
                'line_quantity' => $line_qty,
                'ref' => $order_id . '-' . $row->order_item_id,
                'booked' => date('Y-m-d', strtotime($row->order_date)),
                'base_price' => number_format($base_price, 2),
                'discount_amount' => number_format($discount_amount, 2),
                'discounts_applied' => $discounts_applied,
                'discount_type' => $discount_type,
                'discount_breakdown' => $item_discount_breakdown,
                'reimbursement' => number_format($reimbursement, 2),
                'stripe_fee' => number_format($stripe_fee, 2),
                'final_price' => number_format($final_price, 2),
                'discount_codes' => implode(', ', $coupons),
                'class_name' => $canonical_product_names[(int) $row->product_id] ?? (
                    function_exists('intersoccer_get_english_product_name')
                        ? intersoccer_get_english_product_name((string) $row->order_item_name, (int) $row->product_id)
                        : (string) $row->order_item_name
                ),
                'venue' => $venue ?: 'N/A',
                'booker_email' => get_post_meta($order_id, '_billing_email', true) ?: 'N/A',
                'booker_phone' => get_post_meta($order_id, '_billing_phone', true) ?: 'N/A',
                'attendee_name' => $attendee_name ?: 'N/A',
                'attendee_age' => $attendee_age ?: 'N/A',
                'attendee_gender' => $attendee_gender ?: 'N/A',
                'parent_phone' => $parent_phone ?: 'N/A',
                'selected_days' => $selected_days ?: 'N/A',
                'age_group' => $age_group ?: 'N/A',
                'activity_type' => $activity_type_display ?: 'N/A',
                'product_type' => $productType,
                'order_id' => $order_id,
                'product_id' => (int) ($row->product_id ?? 0),
                'variation_id' => (int) ($row->variation_id ?? 0),
                'order_item_name' => (string) ($row->order_item_name ?? ''),
                'net_ex_vat' => $net_price,
                'base_price_raw' => $base_price,
                'discount_raw' => $discount_amount,
                'final_price_raw' => $final_price,
                'reimbursement_raw' => $reimbursement,
                'line_tax_amount' => (isset($row->line_tax) && $row->line_tax !== '' && $row->line_tax !== null) ? (float) $row->line_tax : 0.0,
                'used_created_date' => $date_basis === 'paid_or_created'
                    && (!isset($row->date_paid_gmt) || $row->date_paid_gmt === '' || $row->date_paid_gmt === null || (string) $row->date_paid_gmt === '0'),
            ];
            if ($include_line_meta) {
                $data[count($data) - 1]['line_meta'] = $meta_map;
                $data[count($data) - 1]['order_item_id'] = (int) $row->order_item_id;
            }
            if ($date_basis !== 'post_date') {
                $data[count($data) - 1]['order_date_local'] = (string) $row->order_date;
            }
            if (isset($row->date_paid_gmt) && (string) $row->date_paid_gmt !== '' && (string) $row->date_paid_gmt !== '0') {
                $data[count($data) - 1]['date_paid_gmt'] = (string) $row->date_paid_gmt;
            }

            $totals['base_price'] += $base_price;
            $totals['discount_amount'] += $discount_amount;
            $totals['final_price'] += $final_price;
            $totals['reimbursement'] += $reimbursement;
        }

        $totals['bookings'] = count($data);
        $totals['places'] = 0;
        foreach ($data as $report_row) {
            $totals['places'] += function_exists('intersoccer_reports_booking_report_row_place_count')
                ? intersoccer_reports_booking_report_row_place_count($report_row)
                : max(1, (int) ($report_row['line_quantity'] ?? 1));
        }
        $totals['zero_total_buyclub_bookings'] = $zero_total_buyclub_bookings;

        // Round revenue by type values and calculate percentages
        $totalNet = $totals['final_price'] - $totals['reimbursement'];
        foreach ($revenueByType as $bucket => $values) {
            $revenueByType[$bucket]['gross'] = round($values['gross'], 2);
            $revenueByType[$bucket]['final'] = round($values['final'], 2);
            $revenueByType[$bucket]['net'] = round($values['net'], 2);
            $revenueByType[$bucket]['net_percent'] = $totalNet > 0
                ? round(($values['net'] / $totalNet) * 100, 1)
                : 0.0;
        }

        return [
            'data' => $data,
            'totals' => $totals,
            'revenue_by_type' => $revenueByType,
        ];
    }

    /**
     * Parenthesized status list for a paid-only query. Empty when the caller
     * did not pass a usable list, so the booking-report statuses stay in place.
     *
     * @param mixed $statuses
     * @return string
     */
    private function orderStatusInSql($statuses): string {
        if (!is_array($statuses)) {
            return '';
        }
        $parts = [];
        foreach ($statuses as $status) {
            $status = (string) $status;
            if (preg_match('/^wc-[a-z0-9_-]+$/', $status) !== 1) {
                continue;
            }
            if (!in_array("'" . $status . "'", $parts, true)) {
                $parts[] = "'" . $status . "'";
            }
        }
        if ($parts === []) {
            return '';
        }
        return '(' . implode(', ', $parts) . ')';
    }

    /**
     * @return array{amount:float,breakdown:array<int,array<string,mixed>>}
     */
    private function resolveItemDiscountData(
        array $meta_map,
        float $base_price,
        float $line_total,
        int $order_id,
        int $item_id,
        ?array $computed = null
    ): array {
        if ($computed !== null && !empty($computed['breakdown'])) {
            return [
                'amount' => (float) ($computed['discount_total'] ?? 0.0),
                'breakdown' => is_array($computed['breakdown'] ?? null) ? $computed['breakdown'] : [],
            ];
        }

        $discount_amount = 0.0;
        $item_discount_breakdown = [];

        if (isset($meta_map[OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL])
            && $meta_map[OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL] !== ''
        ) {
            $discount_amount = (float) $meta_map[OrderFinancialAttributionService::META_ITEM_DISCOUNT_TOTAL];

            if (isset($meta_map[OrderFinancialAttributionService::META_ITEM_DISCOUNTS])
                && $meta_map[OrderFinancialAttributionService::META_ITEM_DISCOUNTS] !== ''
            ) {
                $raw_breakdown = $meta_map[OrderFinancialAttributionService::META_ITEM_DISCOUNTS];
                $item_discount_breakdown = is_string($raw_breakdown) ? maybe_unserialize($raw_breakdown) : $raw_breakdown;
                if (!is_array($item_discount_breakdown)) {
                    $item_discount_breakdown = [];
                }
            }
        } elseif (isset($meta_map['Discount Amount']) && $meta_map['Discount Amount'] !== '') {
            $discount_amount = intersoccer_parse_legacy_discount_amount($meta_map['Discount Amount']);

            if (isset($meta_map['Discount']) && $meta_map['Discount'] !== '') {
                $discount_name = trim((string) $meta_map['Discount']);
                $item_discount_breakdown = [[
                    'name' => $discount_name,
                    'type' => intersoccer_determine_discount_type_from_name($discount_name),
                    'amount' => $discount_amount,
                ]];
            }
        } else {
            $discount_amount = max(0.0, $base_price - $line_total);
            if ($discount_amount > 0.01) {
                $this->logger->debug('FinancialReportService: Using fallback discount calculation', [
                    'discount_amount' => $discount_amount,
                ]);
            }
        }

        if ($item_discount_breakdown !== []) {
            $reconciled = intersoccer_reconcile_stale_persisted_item_discount($discount_amount, $item_discount_breakdown);
            $discount_amount = $reconciled['amount'];
            $item_discount_breakdown = $reconciled['breakdown'];
        }

        return [
            'amount' => $discount_amount,
            'breakdown' => $item_discount_breakdown,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $item_discount_breakdown
     * @param array<int,string>              $coupons
     */
    private function formatDiscountsApplied(array $item_discount_breakdown, float $discount_amount, array $meta_map, array $coupons): string {
        $discounts_applied = [];

        if (!empty($item_discount_breakdown)) {
            foreach ($item_discount_breakdown as $disc) {
                $disc_array = is_array($disc) ? $disc : (array) $disc;
                if (empty($disc_array['name'])) {
                    continue;
                }

                $discount_name = trim((string) $disc_array['name']);
                if (isset($disc_array['amount']) && (float) $disc_array['amount'] > 0) {
                    $discount_name .= ' (' . number_format((float) $disc_array['amount'], 2) . ' CHF)';
                }
                $discounts_applied[] = $discount_name;
            }
        } elseif ($discount_amount > 0.01 && isset($meta_map['Discount']) && $meta_map['Discount'] !== '') {
            $discount_name = trim((string) $meta_map['Discount']);
            $discount_name .= ' (' . number_format($discount_amount, 2) . ' CHF)';
            $discounts_applied[] = $discount_name;
        }

        foreach ($coupons as $coupon_code) {
            if (!empty($coupon_code) && trim($coupon_code) !== '') {
                $discounts_applied[] = 'Coupon: ' . trim($coupon_code);
            }
        }

        return !empty($discounts_applied) ? implode('; ', $discounts_applied) : 'None';
    }

    /**
     * @param array<int,array<string,mixed>> $item_discount_breakdown
     * @param array<int,string>              $coupons
     */
    private function resolvePrimaryDiscountType(array $item_discount_breakdown, array $meta_map, array $coupons): string {
        if (!empty($item_discount_breakdown)) {
            foreach ($item_discount_breakdown as $disc) {
                if (is_array($disc) && !empty($disc['type'])) {
                    return (string) $disc['type'];
                }
            }
        }

        if (!empty($meta_map['Discount'])) {
            return intersoccer_determine_discount_type_from_name((string) $meta_map['Discount']);
        }

        if (!empty($coupons)) {
            return 'coupon';
        }

        return 'other';
    }

    /**
     * Helper to extract the first available meta value from a list of keys.
     */
    private function extractMetaValue(array $meta_map, array $keys): string {
        foreach ($keys as $key) {
            if (!empty($meta_map[$key])) {
                return (string) $meta_map[$key];
            }
        }

        return '';
    }

    /**
     * @return array{discount_total:float,breakdown:array<int,array<string,mixed>>,refund:float}|null
     */
    private function getInMemoryItemAttribution(int $order_id, int $item_id): ?array {
        if (!function_exists('intersoccer_oop_get_order_financial_attribution_service') || !function_exists('wc_get_order')) {
            return null;
        }

        if (!array_key_exists($order_id, $this->inMemoryAttributionCache)) {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_meta(OrderFinancialAttributionService::META_ORDER_ATTRIBUTION_AT, true)) {
                $this->inMemoryAttributionCache[$order_id] = null;
            } else {
                try {
                    $service = intersoccer_oop_get_order_financial_attribution_service();
                    $discounts = $service->computeItemDiscountsForOrder($order);
                    $refunds = $service->computeItemRefundsForOrder($order);
                    $items = [];
                    foreach ($discounts as $disc_item_id => $payload) {
                        $items[(int) $disc_item_id] = [
                            'discount_total' => (float) $payload['discount_total'],
                            'breakdown' => $payload['breakdown'],
                            'refund' => (float) ($refunds[(int) $disc_item_id] ?? 0.0),
                        ];
                    }
                    foreach ($refunds as $refund_item_id => $amount) {
                        $refund_item_id = (int) $refund_item_id;
                        if (!isset($items[$refund_item_id])) {
                            $items[$refund_item_id] = [
                                'discount_total' => 0.0,
                                'breakdown' => [],
                                'refund' => (float) $amount,
                            ];
                        }
                    }
                    $this->inMemoryAttributionCache[$order_id] = ['items' => $items];
                } catch (\Throwable $e) {
                    $this->logger->error('FinancialReportService: In-memory attribution failed', [
                        'order_id' => $order_id,
                        'message' => $e->getMessage(),
                    ]);
                    $this->inMemoryAttributionCache[$order_id] = null;
                }
            }
        }

        $cache = $this->inMemoryAttributionCache[$order_id];
        if ($cache === null) {
            return null;
        }

        $item = $cache['items'][$item_id] ?? null;
        if ($item === null) {
            return null;
        }

        if (empty($item['breakdown'])) {
            if (($item['refund'] ?? 0) > 0.01) {
                return $item;
            }

            return null;
        }

        return $item;
    }
}
