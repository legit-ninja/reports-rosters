<?php
/**
 * Financial Report Service
 *
 * Generates booking/financial report datasets for the admin UI.
 *
 * @package InterSoccer\ReportsRosters\Services
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Services;

use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;

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
     * Constructor.
     */
    public function __construct(Logger $logger = null, Database $database = null) {
        $this->logger = $logger ?: new Logger();
        $this->database = $database ?: new Database($this->logger);
        $this->wpdb = $this->database->get_wpdb();
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

        // Placeholder for region filtering if needed in future
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
            INNER JOIN {$this->wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_product ON oi.order_item_id = oim_product.order_item_id AND oim_product.meta_key = '_product_id'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_variation ON oi.order_item_id = oim_variation.order_item_id AND oim_variation.meta_key = '_variation_id'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
            LEFT JOIN {$this->wpdb->prefix}woocommerce_order_itemmeta oim_subtotal ON oi.order_item_id = oim_subtotal.order_item_id AND oim_subtotal.meta_key = '_line_subtotal'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed', 'wc-processing')
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

        foreach ((array) $order_rows as $row) {
            $order_id = (int) $row->order_id;

            // Skip BuyClub orders (billing company contains buyclub)
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
            $activity_type = $this->extractMetaValue($meta_map, ['pa_booking-type', 'Activity Type']);
            $venue = $this->extractMetaValue($meta_map, ['pa_intersoccer-venues', 'InterSoccer Venues']);

            $base_price = (float) $row->line_subtotal;
            $final_price = (float) $row->line_total;
            $discount_amount = $base_price - $final_price;

            $coupons = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT order_item_name FROM {$this->wpdb->prefix}woocommerce_order_items
                 WHERE order_id = %d AND order_item_type = 'coupon'",
                $order_id
            ));

            $stripe_fee = $final_price > 0 ? ($final_price * 0.029) + 0.30 : 0.0;

            $data[] = [
                'ref' => $order_id . '-' . $row->order_item_id,
                'booked' => date('Y-m-d', strtotime($row->order_date)),
                'base_price' => number_format($base_price, 2),
                'discount_amount' => number_format($discount_amount, 2),
                'stripe_fee' => number_format($stripe_fee, 2),
                'final_price' => number_format($final_price, 2),
                'discount_codes' => implode(', ', $coupons),
                'class_name' => $row->order_item_name,
                'venue' => $venue ?: 'N/A',
                'booker_email' => get_post_meta($order_id, '_billing_email', true) ?: 'N/A',
                'attendee_name' => $attendee_name ?: 'N/A',
                'attendee_age' => $attendee_age ?: 'N/A',
                'attendee_gender' => $attendee_gender ?: 'N/A',
                'parent_phone' => $parent_phone ?: 'N/A',
                'selected_days' => $selected_days ?: 'N/A',
                'age_group' => $age_group ?: 'N/A',
                'activity_type' => $activity_type ?: 'N/A',
            ];

            $totals['bookings'] += (int) ($row->quantity ?? 0);
            $totals['base_price'] += $base_price;
            $totals['discount_amount'] += $discount_amount;
            $totals['final_price'] += $final_price;
        }

        return [
            'data' => $data,
            'totals' => $totals,
        ];
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
}


