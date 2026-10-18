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
     */
    public function getFinancialBookingReport(string $start_date = '', string $end_date = '', string $year = '', string $region = ''): array {
        $this->logger->info('FinancialReportService: Generating financial report', [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'year' => $year,
            'region' => $region,
        ]);

        $date_where = '';
        if (!empty($start_date) && !empty($end_date)) {
            $date_where = $this->wpdb->prepare('AND p.post_date >= %s AND p.post_date <= %s', $start_date, $end_date);
        } elseif (!empty($year)) {
            $date_where = $this->wpdb->prepare('AND YEAR(p.post_date) = %d', $year);
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
                oim_subtotal.meta_value as line_subtotal
            FROM {$this->wpdb->posts} p
            INNER JOIN {$this->wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_product ON oi.order_item_id = oim_product.order_item_id AND oim_product.meta_key = '_product_id'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_variation ON oi.order_item_id = oim_variation.order_item_id AND oim_variation.meta_key = '_variation_id'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_subtotal ON oi.order_item_id = oim_subtotal.order_item_id AND oim_subtotal.meta_key = '_line_subtotal'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded')
            {$date_where}
            {$region_where}
            ORDER BY p.post_date DESC, p.ID DESC
        ";

        $order_rows = $this->wpdb->get_results($query);

        $data = [];
        $totals = [
            'bookings' => 0,
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

        foreach ((array) $order_rows as $row) {
            $order_id = (int) $row->order_id;

            if (empty($row->product_id)) {
                continue;
            }

            $billing_company = get_post_meta($order_id, '_billing_company', true);
            if (!empty($billing_company) && stripos($billing_company, 'buyclub') !== false) {
                continue;
            }

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

            $age_group = $this->extractMetaValue($meta_map, ['pa_age-group', 'Age Group']);
            $activity_type_display = $this->extractMetaValue($meta_map, ['pa_booking-type', 'Activity Type']);
            $venue = $this->extractMetaValue($meta_map, ['pa_intersoccer-venues', 'Sites InterSoccer', 'InterSoccer Venues']);

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

            // Final = Gross - attributed line discounts (NO refund)
            $final_price = max(0.0, $base_price - $discount_amount);
            // Net = Final - attributed line refund (once)
            $net_price = max(0.0, $final_price - $reimbursement);

            // Aggregate by product type
            $revenueByType[$productType]['gross'] += $base_price;
            $revenueByType[$productType]['final'] += $final_price;
            $revenueByType[$productType]['net'] += $net_price;
            $revenueByType[$productType]['count']++;

            $coupons = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT order_item_name FROM {$this->wpdb->prefix}woocommerce_order_items
                 WHERE order_id = %d AND order_item_type = 'coupon'",
                $order_id
            ));

            $discounts_applied = $this->formatDiscountsApplied($item_discount_breakdown, $discount_amount, $meta_map, $coupons);
            $discount_type = $this->resolvePrimaryDiscountType($item_discount_breakdown, $meta_map, $coupons);

            $stripe_fee = $final_price > 0 ? ($final_price * 0.029) + 0.30 : 0.0;

            $data[] = [
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
                'class_name' => $row->order_item_name,
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
            ];

            $totals['base_price'] += $base_price;
            $totals['discount_amount'] += $discount_amount;
            $totals['final_price'] += $final_price;
            $totals['reimbursement'] += $reimbursement;
        }

        $totals['bookings'] = count($data);

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
                    'order_id' => $order_id,
                    'item_id' => $item_id,
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
