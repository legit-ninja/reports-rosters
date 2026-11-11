<?php
/**
 * Daily sales email: Zurich bucketing, coupon window, VAT, and test send.
 *
 * Send-to fail-safe: the whole list is rejected when it is empty or any token
 * is invalid (bad format, semicolon, or internal whitespace). Valid addresses
 * in a mixed list are not mailed. Logs store a reason code only.
 */

namespace {
    require_once dirname(__DIR__, 2) . '/includes/daily-bf-sales-email.php';

    if (!function_exists('is_email')) {
        function is_email($email) {
            $email = trim((string) $email);
            if ($email === '' || preg_match('/\s/', $email)) {
                return false;
            }
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
        }
    }

    if (!function_exists('wp_mail')) {
        function wp_mail($to, $subject, $message, $headers = '', $attachments = []) {
            if (!isset($GLOBALS['intersoccer_rr_bf_wp_mail_log']) || !is_array($GLOBALS['intersoccer_rr_bf_wp_mail_log'])) {
                $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
            }
            $GLOBALS['intersoccer_rr_bf_wp_mail_log'][] = [
                'to' => $to,
                'subject' => (string) $subject,
                'message' => (string) $message,
                'headers' => $headers,
            ];
            if (!empty($GLOBALS['intersoccer_rr_bf_wp_mail_fail'])) {
                $error = isset($GLOBALS['intersoccer_rr_bf_wp_mail_error'])
                    ? (string) $GLOBALS['intersoccer_rr_bf_wp_mail_error']
                    : 'Mail failed';
                if (function_exists('do_action')) {
                    do_action('wp_mail_failed', new WP_Error('wp_mail_failed', $error));
                }
                return false;
            }
            return true;
        }
    }
}

namespace InterSoccer\ReportsRosters\Services {

    /**
     * Bootstrap records wp_schedule_event and reads wp_next_scheduled from separate
     * globals, and its wp_clear_scheduled_hook stub returns without recording the hook.
     * Unqualified calls in this namespace use these fallbacks so a scheduled event
     * stays visible to the next check, and a clear is visible to tests.
     */
    if (!function_exists(__NAMESPACE__ . '\\wp_schedule_event')) {
        function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false) {
            $result = \wp_schedule_event($timestamp, $recurrence, $hook, $args, $wp_error);
            if ($result) {
                if (!isset($GLOBALS['intersoccer_test_wp_next_scheduled']) || !is_array($GLOBALS['intersoccer_test_wp_next_scheduled'])) {
                    $GLOBALS['intersoccer_test_wp_next_scheduled'] = [];
                }
                $key = (string) $hook . '|' . \maybe_serialize($args);
                $GLOBALS['intersoccer_test_wp_next_scheduled'][$key] = (int) $timestamp;
            }
            return $result;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_clear_scheduled_hook')) {
        function wp_clear_scheduled_hook($hook, $args = [], $wp_error = false) {
            if (!isset($GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log']) || !is_array($GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log'])) {
                $GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log'] = [];
            }
            $GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log'][] = [
                'hook' => (string) $hook,
                'args' => $args,
            ];
            if (isset($GLOBALS['intersoccer_test_wp_next_scheduled']) && is_array($GLOBALS['intersoccer_test_wp_next_scheduled'])) {
                $key = (string) $hook . '|' . \maybe_serialize($args);
                unset($GLOBALS['intersoccer_test_wp_next_scheduled'][$key]);
            }
            return \wp_clear_scheduled_hook($hook, $args, $wp_error);
        }
    }
}

namespace InterSoccer\ReportsRosters\Tests\Services {

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\DailyBfSalesEmail;
use InterSoccer\ReportsRosters\Services\FinancialReportService;
use InterSoccer\ReportsRosters\Services\ProductTypeClassifierService;
use InterSoccer\ReportsRosters\Tests\TestCase;

class DailyBfSalesEmailCapturingLogger extends Logger {
    public $entries = [];

    public function __construct() {
    }

    public function log($level, $message, array $context = []) {
        $this->entries[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}

class DailyBfSalesEmailFixtureWpdb extends \InterSoccerTestWpdb {
    public $queries = [];
    public $orders = [];
    public $itemMeta = [];

    public function get_results($query = null, $output = OBJECT) {
        $query = (string) $query;
        $this->queries[] = $query;
        if (strpos($query, "post_type = 'shop_order'") !== false) {
            return $this->bookingRows($query);
        }
        if (preg_match('/order_item_id = (\d+)/', $query, $matches)) {
            return $this->metaRows((int) $matches[1]);
        }
        return [];
    }

    public function get_col($query = null, $x = 0) {
        $this->queries[] = (string) $query;
        return [];
    }

    private function bookingRows($query) {
        if (strpos($query, '_date_paid') === false) {
            return [];
        }
        if (!preg_match('/CAST\(paid_meta\.meta_value AS UNSIGNED\) >= (\d+) AND CAST\(paid_meta\.meta_value AS UNSIGNED\) < (\d+)/', $query, $paid)) {
            return [];
        }
        $createdFallback = strpos($query, 'paid_meta.meta_value IS NULL') !== false;
        $createdStart = '';
        $createdEnd = '';
        if ($createdFallback) {
            if (!preg_match("/p\\.post_date >= '([^']+)' AND p\\.post_date < '([^']+)'/", $query, $created)) {
                return [];
            }
            $createdStart = $created[1];
            $createdEnd = $created[2];
        }
        $statuses = $this->statusList($query);
        $rows = [];
        foreach ($this->orders as $order) {
            if (!$this->orderIncluded($order, (int) $paid[1], (int) $paid[2], $createdFallback, $createdStart, $createdEnd, $statuses)) {
                continue;
            }
            foreach ($order['items'] as $item) {
                $rows[] = (object) [
                    'order_id' => $order['id'],
                    'order_date' => $order['post_date'],
                    'order_status' => isset($order['status']) ? (string) $order['status'] : 'wc-completed',
                    'order_item_id' => $item['id'],
                    'order_item_name' => $item['name'],
                    'product_id' => (string) $item['product_id'],
                    'variation_id' => (string) $item['variation_id'],
                    'quantity' => (string) $item['qty'],
                    'line_total' => $item['total'],
                    'line_subtotal' => $item['subtotal'],
                    'line_tax' => $item['tax'],
                    'date_paid_gmt' => (string) $order['date_paid'],
                ];
            }
        }
        return $rows;
    }

    private function statusList($query) {
        if (!preg_match("/post_status IN \(([^)]+)\)/", $query, $matches)) {
            return [];
        }
        preg_match_all("/'(wc-[a-z0-9_-]+)'/", $matches[1], $found);
        return isset($found[1]) ? $found[1] : [];
    }

    private function orderIncluded(array $order, $paidStart, $paidEnd, $createdFallback, $createdStart, $createdEnd, array $statuses) {
        $status = isset($order['status']) ? (string) $order['status'] : 'wc-completed';
        if ($statuses !== [] && !in_array($status, $statuses, true)) {
            return false;
        }
        $raw = isset($order['date_paid']) ? (string) $order['date_paid'] : '';
        if ($raw !== '' && $raw !== '0') {
            $stamp = (int) $raw;
            return $stamp >= $paidStart && $stamp < $paidEnd;
        }
        if (!$createdFallback) {
            return false;
        }
        $postDate = (string) $order['post_date'];
        return strcmp($postDate, $createdStart) >= 0 && strcmp($postDate, $createdEnd) < 0;
    }

    private function metaRows($itemId) {
        $meta = isset($this->itemMeta[$itemId]) ? $this->itemMeta[$itemId] : [];
        $rows = [];
        foreach ($meta as $key => $value) {
            $rows[] = (object) [
                'meta_key' => $key,
                'meta_value' => $value,
            ];
        }
        return $rows;
    }
}

class DailyBfSalesEmailTest extends TestCase {
    /** @var DailyBfSalesEmailCapturingLogger */
    private $logger;

    /** @var DailyBfSalesEmailFixtureWpdb */
    private $wpdb;

    /** @var FinancialReportService */
    private $reports;

    protected function setUp(): void {
        parent::setUp();
        $this->logger = new DailyBfSalesEmailCapturingLogger();
        $GLOBALS['intersoccer_test_option_store'] = [];
        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $GLOBALS['intersoccer_rr_bf_wp_mail_fail'] = false;
        $GLOBALS['intersoccer_rr_bf_wp_mail_error'] = '';
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = false;
        $GLOBALS['intersoccer_test_wp_schedule_event_log'] = [];
        $GLOBALS['intersoccer_test_wp_next_scheduled'] = [];
        $GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log'] = [];
        \InterSoccerRRTestMeta::reset();
    }

    protected function tearDown(): void {
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = false;
        $GLOBALS['intersoccer_rr_bf_wp_mail_fail'] = false;
        $GLOBALS['intersoccer_test_wp_schedule_event_log'] = [];
        $GLOBALS['intersoccer_test_wp_next_scheduled'] = [];
        $GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log'] = [];
        unset($GLOBALS['intersoccer_test_current_user_caps'], $GLOBALS['intersoccer_test_current_user']);
        \InterSoccerRRTestMeta::reset();
        parent::tearDown();
    }

    public function test_zurich_day_bucketing_includes_dst_edges(): void {
        $utc = new \DateTimeZone('UTC');

        $this->assertSame('2026-03-28', $this->ymdFromUtc('2026-03-28 22:59:59'));
        $this->assertSame('2026-03-29', $this->ymdFromUtc('2026-03-28 23:00:00'));
        $this->assertSame('2026-03-29', $this->ymdFromUtc('2026-03-29 21:59:59'));
        $this->assertSame('2026-03-30', $this->ymdFromUtc('2026-03-29 22:00:00'));

        $this->assertSame('2026-10-24', $this->ymdFromUtc('2026-10-24 21:59:59'));
        $this->assertSame('2026-10-25', $this->ymdFromUtc('2026-10-24 22:00:00'));
        $this->assertSame('2026-10-25', $this->ymdFromUtc('2026-10-25 22:59:59'));
        $this->assertSame('2026-10-26', $this->ymdFromUtc('2026-10-25 23:00:00'));

        $this->assertSame('2026-03-29', DailyBfSalesEmail::zurichYmdFromSiteLocal('2026-03-28 23:30:00', $utc));
        $this->assertSame('2026-03-28', DailyBfSalesEmail::zurichYmdFromSiteLocal('2026-03-28 22:30:00', $utc));
        $this->assertSame('2026-10-25', DailyBfSalesEmail::zurichYmdFromSiteLocal('2026-10-24 22:00:00', $utc));
        $this->assertSame('2026-10-24', DailyBfSalesEmail::zurichYmdFromSiteLocal('2026-10-24 21:59:59', $utc));
    }

    public function test_paid_versus_created_fallback_and_footer_note(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->seedCatalogue();
        $digest = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $html = $service->renderHtml($digest);

        $this->assertSame('2026-11-28', $digest['report_date']);
        $this->assertSame(3, $digest['daily']['order_count']);
        $this->assertFalse($digest['used_created_fallback']);
        $this->assertStringContainsString(DailyBfSalesEmail::FOOTER_PAID_DATE, $html);
        $this->assertStringNotContainsString('no paid date', $html);
        $this->assertStringNotContainsString('City Birthday', $html);
        $this->assertStringContainsString('Regional Tournament', $html);
        $this->assertStringNotContainsString('Excluded Camp', $html);
        $this->assertStringNotContainsString('secret-buyer@example.com', $html);
        $this->assertStringNotContainsString('Secret Child', $html);
    }

    public function test_strict_paid_is_the_default_and_on_hold_is_separate(): void {
        $this->assertSame(['wc-processing', 'wc-completed'], DailyBfSalesEmail::PAID_ORDER_STATUSES);
        $this->assertSame(['wc-processing', 'wc-completed'], DailyBfSalesEmail::paidOrderStatuses());

        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->seedCatalogue();
        $before = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $this->wpdb->orders[] = [
            'id' => 601,
            'status' => 'wc-processing',
            'post_date' => '2026-11-28 13:00:00',
            'date_paid' => (string) $this->unix('2026-11-28 13:00:00'),
            'items' => [
                $this->item(6011, 'Processing Camp', 100, 1004, 1, '20.00', '20.00', '1.54'),
            ],
        ];
        $this->wpdb->orders[] = [
            'id' => 602,
            'status' => 'wc-on-hold',
            'post_date' => '2026-11-28 14:00:00',
            'date_paid' => (string) $this->unix('2026-11-28 14:00:00'),
            'items' => [
                $this->item(6021, 'Held Camp', 100, 1005, 1, '100.00', '100.00', '7.70'),
            ],
        ];
        $this->wpdb->orders[] = [
            'id' => 603,
            'status' => 'wc-refunded',
            'post_date' => '2026-11-28 15:00:00',
            'date_paid' => (string) $this->unix('2026-11-28 15:00:00'),
            'items' => [
                $this->item(6031, 'Refunded Status Camp', 100, 1006, 1, '80.00', '80.00', '6.16'),
            ],
        ];
        $this->wpdb->itemMeta[6011] = ['_intersoccer_canonical_activity_type' => 'camp'];
        $this->wpdb->itemMeta[6021] = ['_intersoccer_canonical_activity_type' => 'camp'];
        $this->wpdb->itemMeta[6031] = ['_intersoccer_canonical_activity_type' => 'camp'];

        $digest = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $html = $service->renderHtml($digest);
        $this->assertSame($before['daily']['order_count'] + 1, $digest['daily']['order_count']);
        $this->assertSame(
            $this->cents($before['daily']['net_inc_vat'] + 21.54),
            $this->cents($digest['daily']['net_inc_vat'])
        );
        $this->assertSame(1, $digest['daily_on_hold']['order_count']);
        $this->assertSame('107.70', $this->cents($digest['daily_on_hold']['net_inc_vat']));
        $this->assertStringContainsString('Processing Camp', $html);
        $this->assertStringContainsString('On hold yesterday: 1 orders, 107.70 CHF inc-VAT. Not included in the totals.', $html);
        $this->assertStringNotContainsString('Held Camp', $html);
        $this->assertStringNotContainsString('Refunded Status Camp', $html);
        $this->assertStringContainsString('<th>Daily qty</th><th>Daily inc-VAT</th><th>Campaign qty</th><th>Campaign inc-VAT</th>', $html);

        $paidSql = $this->bookingSql();
        $this->assertStringContainsString("'wc-processing'", $paidSql);
        $this->assertStringContainsString("'wc-completed'", $paidSql);
        $this->assertStringNotContainsString('paid_meta.meta_value IS NULL', $paidSql);
        $this->assertStringNotContainsString("'wc-on-hold'", $paidSql);
        $this->assertStringNotContainsString("'wc-refunded'", $paidSql);

        $onHoldSql = '';
        foreach ($this->wpdb->queries as $query) {
            if (strpos($query, "post_type = 'shop_order'") !== false && strpos($query, "'wc-on-hold'") !== false) {
                $onHoldSql = $query;
            }
        }
        $this->assertNotSame('', $onHoldSql);
        $this->assertStringContainsString('_date_paid', $onHoldSql);
    }

    public function test_paid_status_filter_replaces_the_default_set(): void {
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        $filter = DailyBfSalesEmail::PAID_ORDER_STATUSES_FILTER;
        add_filter($filter, static function () {
            return ['wc-completed'];
        });
        try {
            $this->assertSame(['wc-completed'], DailyBfSalesEmail::paidOrderStatuses());
            $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
            $this->seedCatalogue();
            $this->wpdb->orders[] = [
                'id' => 604,
                'status' => 'wc-processing',
                'post_date' => '2026-11-28 13:00:00',
                'date_paid' => (string) $this->unix('2026-11-28 13:00:00'),
                'items' => [
                    $this->item(6041, 'Filtered Processing Camp', 100, 1007, 1, '15.00', '15.00', '1.16'),
                ],
            ];
            $this->wpdb->itemMeta[6041] = ['_intersoccer_canonical_activity_type' => 'camp'];
            $digest = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
            $this->assertStringNotContainsString('Filtered Processing Camp', $service->renderHtml($digest));
            $this->assertStringNotContainsString("'wc-processing'", $this->bookingSql());
        } finally {
            unset($GLOBALS['intersoccer_test_wp_hooks']['filters'][$filter]);
            $GLOBALS['intersoccer_test_emit_wp_hooks'] = false;
        }
    }

    public function test_booking_report_default_path_is_unchanged(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->seedCatalogue();
        $this->reports->getFinancialBookingReport('2026-11-28', '2026-11-28');
        $sql = '';
        foreach ($this->wpdb->queries as $query) {
            if (strpos($query, "post_type = 'shop_order'") !== false && strpos($query, '_date_paid') === false) {
                $sql = $query;
            }
        }
        $this->assertNotSame('', $sql);
        $this->assertStringContainsString('p.post_date', $sql);
        $this->assertStringContainsString("'wc-completed'", $sql);
        $this->assertStringContainsString("'wc-processing'", $sql);
        $this->assertStringContainsString("'wc-on-hold'", $sql);
        $this->assertStringContainsString("'wc-refunded'", $sql);
        $this->assertStringNotContainsString('CAST(paid_meta.meta_value', $sql);
        unset($service);
    }

    public function test_footer_omits_created_fallback_when_every_order_was_paid(): void {
        $service = $this->enabledService([
            'start' => $this->unix('2026-11-29 00:00:00'),
            'expires' => $this->unix('2026-11-30 23:59:59'),
        ], 'ops@example.com');
        $this->seedCatalogue();
        $digest = $service->buildDigest($this->zurich('2026-11-30 08:00:00'));
        $html = $service->renderHtml($digest);

        $this->assertFalse($digest['used_created_fallback']);
        $this->assertStringContainsString(DailyBfSalesEmail::FOOTER_PAID_DATE, $html);
        $this->assertStringNotContainsString('no paid date', $html);
        $this->assertSame(1, $digest['daily']['order_count']);
    }

    public function test_vat_columns_include_ex_vat_and_inc_vat(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->seedCatalogue();
        $html = $service->renderHtml($service->buildDigest($this->zurich('2026-11-29 08:00:00')));

        $this->assertStringContainsString('<strong>414.95 CHF</strong> net inc-VAT', $html);
        $this->assertStringContainsString('<th>Daily qty</th><th>Daily inc-VAT</th><th>Campaign qty</th><th>Campaign inc-VAT</th>', $html);
        $this->assertStringNotContainsString('Gross ex-VAT', $html);
        $this->assertStringNotContainsString('Net ex-VAT', $html);
        $this->assertStringContainsString('323.10', $html);

        $this->wpdb->orders = [[
            'id' => 700,
            'post_date' => '2026-11-28 10:00:00',
            'date_paid' => (string) $this->unix('2026-11-28 10:00:00'),
            'items' => [[
                'id' => 7001,
                'name' => 'Refunded Camp',
                'product_id' => 70,
                'variation_id' => 701,
                'qty' => 1,
                'subtotal' => '100.00',
                'total' => '100.00',
                'tax' => '7.70',
            ]],
        ]];
        $this->wpdb->itemMeta = [
            7001 => [
                '_intersoccer_canonical_activity_type' => 'camp',
                '_intersoccer_item_refund' => '50',
            ],
        ];
        $refunded = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $camp = $this->typeByLabel($refunded['daily'], 'Camp');
        $this->assertSame('50.00', $this->cents($camp['net_ex_vat']));
        $this->assertSame('53.85', $this->cents($camp['net_inc_vat']));
        $refundedHtml = $service->renderHtml($refunded);
        $this->assertStringContainsString('<strong>53.85 CHF</strong> net inc-VAT', $refundedHtml);
        $this->assertStringContainsString('53.85', $refundedHtml);
        $this->assertStringNotContainsString('>50.00<', $refundedHtml);
    }

    public function test_coupon_window_includes_morning_after_expiry_and_skips_outside_days(): void {
        $this->assertWindow('2026-11-28 08:00:00', 'outside_window', false);
        $this->assertWindow('2026-11-29 07:59:00', 'before_send_time', false);
        $this->assertWindow('2026-11-29 08:00:00', 'sent', true);
        $this->assertWindow('2026-11-30 08:00:00', 'sent', true);
        $this->assertWindow('2026-12-01 08:00:00', 'sent', true);
        $this->assertWindow('2026-12-02 08:00:00', 'outside_window', false);

        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $service->runScheduled($this->zurich('2026-12-01 08:00:00'));
        $this->assertNotEmpty($GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertStringContainsString('2026-11-30', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $this->assertArrayHasKey('2026-11-30', $service->state()['sent_dates']);
    }

    public function test_dst_mornings_still_send_inside_the_window(): void {
        $spring = $this->enabledService([
            'start' => $this->unix('2026-03-28 00:00:00'),
            'expires' => $this->unix('2026-03-28 23:59:59'),
        ], 'ops@example.com');
        $this->assertSame('sent', $spring->runScheduled($this->zurich('2026-03-29 08:00:00')));

        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $GLOBALS['intersoccer_test_option_store'] = [];
        $autumn = $this->enabledService([
            'start' => $this->unix('2026-10-24 00:00:00'),
            'expires' => $this->unix('2026-10-24 23:59:59'),
        ], 'ops@example.com');
        $this->assertSame('sent', $autumn->runScheduled($this->zurich('2026-10-25 08:00:00')));
    }

    public function test_second_scheduled_send_in_the_same_window_is_not_sent(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->seedCatalogue();
        $now = $this->zurich('2026-11-29 08:00:00');
        $this->assertSame('sent', $service->runScheduled($now));
        $this->assertSame('already_sent', $service->runScheduled($now));
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertStringNotContainsString('[TEST]', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $this->assertSame(['2026-11-28' => $now->getTimestamp()], $service->state()['sent_dates']);
    }

    public function test_send_to_empty_invalid_and_mixed_lists_do_not_send_or_log_addresses(): void {
        $cases = [
            '' => 'empty_recipients',
            '   ' => 'empty_recipients',
            ',' => 'empty_recipients',
            'not-an-email' => 'invalid_recipient',
            'ops@example.com, not-an-email, other@example.test' => 'invalid_recipient',
            'ops@example.com;other@example.test' => 'invalid_recipient',
            'ops@example.com other@example.test' => 'invalid_recipient',
        ];
        foreach ($cases as $recipients => $reason) {
            $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
            $this->logger->entries = [];
            $service = $this->enabledService($this->novemberCoupon(), (string) $recipients);
            $this->assertSame($reason, $service->runScheduled($this->zurich('2026-11-29 08:00:00')));
            $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
            $this->assertSame([], $service->state()['sent_dates']);
            $this->assertNoAddressesLogged();
        }
    }

    public function test_valid_send_to_list_is_mailed_once_without_logging_addresses(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com, other@example.test');
        $this->assertSame('sent', $service->runScheduled($this->zurich('2026-11-29 08:00:00')));
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame(
            ['ops@example.com', 'other@example.test'],
            $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['to']
        );
        $this->assertStringNotContainsString('ops@example.com', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['message']);
        $this->assertNoAddressesLogged();
    }

    public function test_product_lines_reconcile_to_type_totals_and_the_booking_report(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->seedCatalogue();
        $digest = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $daily = $digest['daily'];

        $this->assertProductLinesMatchTypes($daily);
        $camp = $this->typeByLabel($daily, 'Camp');
        $course = $this->typeByLabel($daily, 'Course');
        $this->assertSame(3, $camp['quantity']);
        $this->assertSame('300.00', $this->cents($camp['net_ex_vat']));
        $this->assertSame('323.10', $this->cents($camp['net_inc_vat']));
        $this->assertSame('80.00', $this->cents($course['gross_ex_vat']));
        $this->assertSame('30.00', $this->cents($course['discounts']));
        $this->assertSame('50.00', $this->cents($course['net_ex_vat']));

        $alpine = $this->productByLabel($daily, 'Alpine Camp Week');
        $this->assertSame(3, $alpine['quantity']);
        $this->assertSame('300.00', $this->cents($alpine['net_ex_vat']));

        $report = $this->reports->getFinancialBookingReport(
            '2026-11-28',
            '2026-11-28',
            '',
            '',
            DailyBfSalesEmail::paidWindowOptions('2026-11-28', '2026-11-28', new \DateTimeZone('Europe/Zurich'))
        );
        foreach ($daily['types'] as $type) {
            $reported = (float) $report['revenue_by_type'][$type['key']]['net'];
            $this->assertSame($this->cents($reported), $this->cents($type['net_ex_vat']), $type['label']);
        }

        $sql = $this->bookingSql();
        $this->assertStringContainsString('_date_paid', $sql);
        $this->assertStringContainsString('wc-completed', $sql);
        $this->assertStringContainsString('wc-processing', $sql);
        $this->assertStringNotContainsString('paid_meta.meta_value IS NULL', $sql);
        $this->assertStringNotContainsString('wc-on-hold', $sql);
        $this->assertStringNotContainsString('wc-refunded', $sql);
        $this->assertFalse(stripos($sql, 'INSERT') !== false, $sql);
        $this->assertFalse(stripos($sql, 'UPDATE') !== false, $sql);
        $this->assertFalse(stripos($sql, 'DELETE') !== false, $sql);

        $html = $service->renderHtml($digest);
        $this->assertStringContainsString('page=intersoccer-reports', $html);
        $this->assertStringContainsString('start_date=2026-11-28', $html);
        $this->assertStringContainsString('end_date=2026-11-28', $html);
    }

    public function test_running_total_reconciles_to_the_booking_report_and_daily_sum(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->seedCatalogue();
        $dayOne = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $dayTwo = $service->buildDigest($this->zurich('2026-11-30 08:00:00'));

        $this->assertSame(1, $dayTwo['daily']['order_count']);
        $this->assertSame('40.00', $this->cents($dayTwo['daily']['net_ex_vat']));
        $this->assertTrue($dayTwo['campaign_started']);
        $this->assertSame(4, $dayTwo['campaign']['order_count']);
        $this->assertSame(
            $this->cents($dayOne['daily']['net_ex_vat'] + $dayTwo['daily']['net_ex_vat']),
            $this->cents($dayTwo['campaign']['net_ex_vat'])
        );
        $this->assertSame(
            $this->cents($dayOne['daily']['net_inc_vat'] + $dayTwo['daily']['net_inc_vat']),
            $this->cents($dayTwo['campaign']['net_inc_vat'])
        );
        $this->assertProductLinesMatchTypes($dayTwo['campaign']);

        $report = $this->reports->getFinancialBookingReport(
            '2026-11-28',
            '2026-11-29',
            '',
            '',
            DailyBfSalesEmail::paidWindowOptions('2026-11-28', '2026-11-29', new \DateTimeZone('Europe/Zurich'))
        );
        $reported = 0.0;
        foreach ($report['revenue_by_type'] as $bucket) {
            $reported += (float) $bucket['net'];
        }
        $this->assertSame($this->cents($reported), $this->cents($dayTwo['campaign']['net_ex_vat']));

        $html = $service->renderHtml($dayTwo);
        $this->assertStringContainsString('Running total since campaign start', $html);
        $this->assertStringContainsString('2026-11-28 through 2026-11-29', $html);
        $this->assertStringContainsString(
            'Campaign total: ' . $this->cents($dayTwo['campaign']['net_inc_vat']) . ' CHF inc-VAT.',
            $html
        );
        $this->assertStringContainsString('<th>Daily qty</th><th>Daily inc-VAT</th><th>Campaign qty</th><th>Campaign inc-VAT</th>', $html);
        $this->assertStringContainsString('43.08', $html);
        $this->assertStringContainsString('366.18', $html);
        $this->assertStringNotContainsString(DailyBfSalesEmail::FINAL_CAMPAIGN_LINE, $html);
    }

    public function test_final_morning_reports_the_expiry_day_and_whole_campaign_total(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->seedCatalogue();
        $this->seedExpiryDayOrder();

        $dayOne = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $dayTwo = $service->buildDigest($this->zurich('2026-11-30 08:00:00'));
        $final = $service->buildDigest($this->zurich('2026-12-01 08:00:00'));

        $this->assertSame('2026-11-28', $dayOne['report_date']);
        $this->assertSame('2026-11-29', $dayTwo['report_date']);
        $this->assertSame('2026-11-30', $final['report_date']);
        $this->assertFalse($dayOne['final_campaign']);
        $this->assertFalse($dayTwo['final_campaign']);
        $this->assertTrue($final['final_campaign']);
        $this->assertSame('2026-11-28', $final['campaign_start']);
        $this->assertSame('55.00', $this->cents($final['daily']['net_ex_vat']));
        $this->assertSame(1, $final['daily']['order_count']);

        $dailyEx = $dayOne['daily']['net_ex_vat'] + $dayTwo['daily']['net_ex_vat'] + $final['daily']['net_ex_vat'];
        $dailyInc = $dayOne['daily']['net_inc_vat'] + $dayTwo['daily']['net_inc_vat'] + $final['daily']['net_inc_vat'];
        $dailyOrders = $dayOne['daily']['order_count'] + $dayTwo['daily']['order_count'] + $final['daily']['order_count'];
        $this->assertSame($this->cents($dailyEx), $this->cents($final['campaign']['net_ex_vat']));
        $this->assertSame($this->cents($dailyInc), $this->cents($final['campaign']['net_inc_vat']));
        $this->assertSame($dailyOrders, $final['campaign']['order_count']);
        $this->assertGreaterThan($dayTwo['campaign']['net_ex_vat'], $final['campaign']['net_ex_vat']);

        $html = $service->renderHtml($final);
        $this->assertStringContainsString('2026-11-28 through 2026-11-30', $html);
        $this->assertStringContainsString(DailyBfSalesEmail::FINAL_CAMPAIGN_LINE, $html);
        $this->assertStringContainsString(
            'Campaign total: ' . $this->cents($final['campaign']['net_inc_vat']) . ' CHF inc-VAT.',
            $html
        );
        $this->assertStringContainsString('<th>Daily qty</th><th>Daily inc-VAT</th><th>Campaign qty</th><th>Campaign inc-VAT</th>', $html);
        $this->assertStringContainsString('Closing Camp', $html);

        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $this->assertSame('sent', $service->runScheduled($this->zurich('2026-12-01 08:00:00')));
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $this->assertSame('Final Daily sales report 2026-11-30', $mail['subject']);
        $this->assertStringNotContainsString('[TEST]', $mail['subject']);
        $this->assertStringContainsString(DailyBfSalesEmail::FINAL_CAMPAIGN_LINE, $mail['message']);
        $this->assertSame(['ops@example.com'], (array) $mail['to']);
        $this->assertArrayHasKey('2026-11-30', $service->state()['sent_dates']);
    }

    public function test_morning_before_the_final_morning_is_not_marked_final(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'list@example.test');
        $this->seedCatalogue();
        $now = $this->zurich('2026-11-30 08:00:00');
        $digest = $service->buildDigest($now);

        $this->assertSame('2026-11-29', $digest['report_date']);
        $this->assertFalse($digest['final_campaign']);
        $html = $service->renderHtml($digest);
        $this->assertStringNotContainsString(DailyBfSalesEmail::FINAL_CAMPAIGN_LINE, $html);

        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $before = $service->state();
        $test = $service->sendTest('ops@example.com', $now);
        $this->assertTrue($test['ok']);
        $this->assertSame('[TEST] Daily sales report 2026-11-29', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $this->assertStringNotContainsString('Final', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $this->assertStringNotContainsString(DailyBfSalesEmail::FINAL_CAMPAIGN_LINE, $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['message']);
        $this->assertSame('ops@example.com', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['to']);
        $this->assertSame($before, $service->state());

        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $this->assertSame('sent', $service->runScheduled($now));
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $this->assertSame('Daily sales report 2026-11-29', $mail['subject']);
        $this->assertStringNotContainsString('Final', $mail['subject']);
        $this->assertStringNotContainsString('[TEST]', $mail['subject']);
        $this->assertStringNotContainsString(DailyBfSalesEmail::FINAL_CAMPAIGN_LINE, $mail['message']);
    }

    public function test_morning_after_the_final_morning_sends_nothing(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->seedCatalogue();
        $this->seedExpiryDayOrder();

        $this->assertSame('outside_window', $service->runScheduled($this->zurich('2026-12-02 08:00:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame([], $service->state()['sent_dates']);
    }

    public function test_test_send_on_the_final_morning_includes_final_after_the_test_prefix(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'list@example.test');
        $this->seedCatalogue();
        $this->seedExpiryDayOrder();
        $before = $service->state();

        $result = $service->sendTest('ops@example.com', $this->zurich('2026-12-01 08:00:00'));
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $this->assertSame('[TEST] Final Daily sales report 2026-11-30', $mail['subject']);
        $this->assertStringStartsWith('[TEST] Final ', $mail['subject']);
        $this->assertStringContainsString(DailyBfSalesEmail::FINAL_CAMPAIGN_LINE, $mail['message']);
        $this->assertStringContainsString('2026-11-28 through 2026-11-30', $mail['message']);
        $this->assertSame('ops@example.com', $mail['to']);
        $this->assertStringNotContainsString('list@example.test', $mail['message']);
        $this->assertSame($before, $service->state());
    }

    public function test_schedule_uses_hourly_wp_cron(): void {
        $GLOBALS['intersoccer_test_wp_schedule_event_log'] = [];
        $GLOBALS['intersoccer_test_wp_next_scheduled'] = [];
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = $this->settingsOption(true);
        DailyBfSalesEmail::ensureScheduled();
        $log = $this->salesCronEvents();
        $this->assertCount(1, $log);
        $last = $log[count($log) - 1];
        $this->assertSame(DailyBfSalesEmail::CRON_HOOK, $last['hook']);
        $this->assertSame('hourly', $last['recurrence']);
    }

    public function test_register_and_ensure_scheduled_schedule_nothing_when_enable_is_off(): void {
        $GLOBALS['intersoccer_test_option_store'] = [];
        DailyBfSalesEmail::register();
        DailyBfSalesEmail::ensureScheduled();

        $this->assertSame([], $this->salesCronEvents());
        $this->assertSame([], $this->salesCronClears());
        $this->assertFalse(wp_next_scheduled(DailyBfSalesEmail::CRON_HOOK));
        $this->assertActionRegistered('update_option', 'onOptionUpdated', 3);
        $this->assertActionRegistered('update_option_' . DailyBfSalesEmail::OPTION_SETTINGS, 'onSettingsUpdated', 3);
        $this->assertActionRegistered(DailyBfSalesEmail::CRON_HOOK, 'runScheduledFromCron', 1);
        $this->assertActionRegistered('admin_menu', 'registerMenu', 1);
        $this->assertActionRegistered('admin_init', 'maybeHandlePost', 1);
    }

    public function test_turning_enable_on_schedules_the_hourly_event_once(): void {
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = $this->settingsOption(false);
        DailyBfSalesEmail::register();
        $this->assertSame([], $this->salesCronEvents());

        $enabled = $this->settingsOption(true);
        update_option(DailyBfSalesEmail::OPTION_SETTINGS, $enabled, false);
        $events = $this->salesCronEvents();
        $this->assertCount(1, $events);
        $this->assertSame(DailyBfSalesEmail::CRON_HOOK, $events[0]['hook']);
        $this->assertSame('hourly', $events[0]['recurrence']);

        update_option(DailyBfSalesEmail::OPTION_SETTINGS, $enabled, false);
        DailyBfSalesEmail::ensureScheduled();
        DailyBfSalesEmail::onSettingsUpdated(null, $enabled, DailyBfSalesEmail::OPTION_SETTINGS);
        $this->assertCount(1, $this->salesCronEvents());
        $this->assertNotFalse(wp_next_scheduled(DailyBfSalesEmail::CRON_HOOK));
    }

    public function test_turning_enable_off_unschedules_the_event(): void {
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = false;
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = $this->settingsOption(true);
        DailyBfSalesEmail::ensureScheduled();
        $this->assertCount(1, $this->salesCronEvents());

        $service = $this->makeService($this->novemberCoupon());
        $result = $service->handleAdminPost([
            'bf_save' => '1',
            '_wpnonce' => 'test',
            'bf_recipients' => 'ops@example.com',
            'bf_send_time' => '08:00',
            'bf_coupon_code' => 'BF',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('saved', $result['code']);
        $this->assertFalse($service->settings()['enabled']);
        $clears = $this->salesCronClears();
        $this->assertNotEmpty($clears);
        $this->assertSame(DailyBfSalesEmail::CRON_HOOK, $clears[0]['hook']);
        $this->assertFalse(wp_next_scheduled(DailyBfSalesEmail::CRON_HOOK));
        $this->assertCount(1, $this->salesCronEvents());
    }

    public function test_plugin_deactivation_unschedules_the_event(): void {
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = $this->settingsOption(true);
        DailyBfSalesEmail::ensureScheduled();
        $this->assertNotFalse(wp_next_scheduled(DailyBfSalesEmail::CRON_HOOK));

        $this->pluginForDeactivation()->deactivate();

        $clears = $this->salesCronClears();
        $this->assertNotEmpty($clears);
        $this->assertSame(DailyBfSalesEmail::CRON_HOOK, $clears[count($clears) - 1]['hook']);
        $this->assertFalse(wp_next_scheduled(DailyBfSalesEmail::CRON_HOOK));
    }

    public function test_test_send_works_with_enable_off_without_scheduling_the_event(): void {
        $GLOBALS['intersoccer_test_option_store'] = [
            DailyBfSalesEmail::OPTION_SETTINGS => [
                'enabled' => false,
                'recipients' => 'list@example.test',
                'send_time' => '08:00',
                'coupon_code' => 'BF',
            ],
            DailyBfSalesEmail::OPTION_STATE => [
                'sent_dates' => ['2026-10-01' => 1],
                'last_sent_at' => '2026-10-02 08:00',
                'last_result' => 'sent',
            ],
        ];
        DailyBfSalesEmail::register();
        DailyBfSalesEmail::ensureScheduled();
        $this->assertSame([], $this->salesCronEvents());

        $service = $this->makeService([
            'start' => $this->unix('2026-12-01 00:00:00'),
            'expires' => $this->unix('2026-12-31 23:59:59'),
        ]);
        $now = $this->zurich('2026-11-01 09:00:00');
        $before = $service->state();

        $this->assertSame('disabled', $service->runScheduled($now));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $result = $service->sendTest('ops@example.com', $now);

        $this->assertTrue($result['ok']);
        $this->assertSame('test_sent', $result['code']);
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $this->assertSame('ops@example.com', $mail['to']);
        $this->assertStringStartsWith('[TEST] ', $mail['subject']);
        $this->assertSame($before, $service->state());
        $this->assertSame([], $this->salesCronEvents());
        $this->assertFalse(wp_next_scheduled(DailyBfSalesEmail::CRON_HOOK));
    }

    public function test_test_send_goes_only_to_the_typed_address_with_test_subject(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'list@example.test');
        $this->seedCatalogue();
        $before = $service->state();
        $result = $service->sendTest('  ops@example.com  ', $this->zurich('2026-11-29 08:00:00'));

        $this->assertTrue($result['ok']);
        $this->assertSame('Test email sent.', $result['message']);
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $this->assertSame('ops@example.com', $mail['to']);
        $this->assertStringStartsWith('[TEST] ', $mail['subject']);
        $this->assertStringContainsString('Daily sales report 2026-11-28', $mail['subject']);
        $this->assertStringNotContainsString('list@example.test', $mail['message']);
        $this->assertStringNotContainsString('list@example.test', (string) json_encode($mail['to']));
        $this->assertSame($before, $service->state());
    }

    public function test_test_send_works_when_disabled_and_before_the_campaign_starts(): void {
        $GLOBALS['intersoccer_test_option_store'] = [
            DailyBfSalesEmail::OPTION_SETTINGS => [
                'enabled' => false,
                'recipients' => 'list@example.test',
                'send_time' => '08:00',
                'coupon_code' => 'BF',
            ],
            DailyBfSalesEmail::OPTION_STATE => [
                'sent_dates' => ['2026-10-01' => 1],
                'last_sent_at' => '2026-10-02 08:00',
                'last_result' => 'sent',
            ],
        ];
        $service = $this->makeService([
            'start' => $this->unix('2026-12-01 00:00:00'),
            'expires' => $this->unix('2026-12-31 23:59:59'),
        ]);
        $now = $this->zurich('2026-11-01 09:00:00');
        $before = $service->state();

        $this->assertSame('disabled', $service->runScheduled($now));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);

        $result = $service->sendTest('ops@example.com', $now);
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $this->assertSame('ops@example.com', $mail['to']);
        $this->assertStringStartsWith('[TEST] ', $mail['subject']);
        $this->assertStringContainsString('The campaign has not started', $mail['message']);
        $this->assertStringNotContainsString('list@example.test', $mail['message']);
        $this->assertSame($before, $service->state());
    }

    public function test_test_send_does_not_change_the_sent_marker(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'list@example.test');
        $service->runScheduled($this->zurich('2026-11-29 08:00:00'));
        $before = $service->state();
        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];

        $result = $service->sendTest('ops@example.com', $this->zurich('2026-11-29 08:00:00'));
        $this->assertTrue($result['ok']);
        $this->assertSame($before['sent_dates'], $service->state()['sent_dates']);
        $this->assertSame($before['last_sent_at'], $service->state()['last_sent_at']);
        $this->assertSame($before['last_result'], $service->state()['last_result']);
        $this->assertSame('ops@example.com', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['to']);
    }

    /**
     * @dataProvider invalidTestAddressProvider
     */
    public function test_test_send_rejects_invalid_and_multiple_addresses(string $address): void {
        $service = $this->enabledService($this->novemberCoupon(), 'list@example.test');
        $before = $service->state();
        $result = $service->handleAdminPost([
            'bf_test_send' => '1',
            '_wpnonce' => 'test',
            'bf_test_address' => $address,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('test_invalid', $result['code']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame($before, $service->state());
    }

    public function invalidTestAddressProvider(): array {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'bad format' => ['not-an-email'],
            'comma' => ['ops@example.com,other@example.com'],
            'semicolon' => ['ops@example.com;other@example.com'],
            'spaces' => ['ops@example.com other@example.com'],
        ];
    }

    public function test_test_send_mailer_failure_surfaces_the_error(): void {
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        $GLOBALS['intersoccer_rr_bf_wp_mail_fail'] = true;
        $GLOBALS['intersoccer_rr_bf_wp_mail_error'] = 'SMTP rejected the message for ops@example.com';
        $service = $this->enabledService($this->novemberCoupon(), 'list@example.test');
        $before = $service->state();
        $result = $service->handleAdminPost([
            'bf_test_send' => '1',
            '_wpnonce' => 'test',
            'bf_test_address' => 'ops@example.com',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('test_failed', $result['code']);
        $this->assertStringContainsString('SMTP rejected the message for ops@example.com', $result['message']);
        $this->assertSame($before, $service->state());
        $this->assertNoAddressesLogged();
    }

    public function test_admin_page_prefills_the_test_address(): void {
        $user = new \stdClass();
        $user->ID = 5;
        $user->user_email = 'admin@example.com';
        $GLOBALS['intersoccer_test_current_user'] = $user;
        $service = $this->makeService($this->novemberCoupon());
        ob_start();
        $service->renderPage();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Test address', $html);
        $this->assertStringContainsString('Send test email', $html);
        $this->assertStringContainsString('value="admin@example.com"', $html);
        $this->assertStringContainsString('name="bf_enabled" value="1" />', $html);
        $this->assertStringContainsString('final campaign email', $html);
        $this->assertStringContainsString('with Final in the subject', $html);
        $this->assertStringContainsString('Last sent', $html);
        $this->assertStringContainsString('Never', $html);
        $this->assertStringContainsString('Last result', $html);
        $this->assertStringContainsString('None', $html);
        $this->assertStringContainsString('value="08:00"', $html);
    }

    public function test_test_send_requires_manage_options(): void {
        $GLOBALS['intersoccer_test_current_user_caps'] = ['manage_options' => false];
        $service = $this->enabledService($this->novemberCoupon(), 'list@example.test');
        $result = $service->handleAdminPost([
            'bf_test_send' => '1',
            '_wpnonce' => 'test',
            'bf_test_address' => 'ops@example.com',
        ]);
        $this->assertSame('forbidden', $result['code']);
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
    }

    public function test_settings_default_to_disabled_and_an_empty_recipient_list(): void {
        $service = $this->makeService($this->novemberCoupon());
        $settings = $service->settings();
        $this->assertFalse($settings['enabled']);
        $this->assertSame('', $settings['recipients']);
        $this->assertSame('08:00', $settings['send_time']);
        $this->assertSame('', $settings['coupon_code']);
    }

    private function assertWindow(string $nowLocal, string $expected, bool $sends): void {
        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $GLOBALS['intersoccer_test_option_store'] = [];
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $reason = $service->runScheduled($this->zurich($nowLocal));
        $this->assertSame($expected, $reason, $nowLocal);
        if ($sends) {
            $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        } else {
            $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
            $this->assertSame([], $service->state()['sent_dates']);
        }
    }

    private function assertProductLinesMatchTypes(array $summary): void {
        $byType = [];
        foreach ($summary['products'] as $product) {
            $key = $product['type'];
            if (!isset($byType[$key])) {
                $byType[$key] = ['net_ex_vat' => 0.0, 'net_inc_vat' => 0.0, 'quantity' => 0];
            }
            $byType[$key]['net_ex_vat'] += $product['net_ex_vat'];
            $byType[$key]['net_inc_vat'] += $product['net_inc_vat'];
            $byType[$key]['quantity'] += $product['quantity'];
        }
        foreach ($summary['types'] as $type) {
            $fromProducts = isset($byType[$type['key']]) ? $byType[$type['key']] : ['net_ex_vat' => 0.0, 'net_inc_vat' => 0.0, 'quantity' => 0];
            $this->assertSame($this->cents($type['net_ex_vat']), $this->cents($fromProducts['net_ex_vat']), $type['label']);
            $this->assertSame($this->cents($type['net_inc_vat']), $this->cents($fromProducts['net_inc_vat']), $type['label']);
            $this->assertSame((int) $type['quantity'], (int) $fromProducts['quantity'], $type['label']);
        }
    }

    private function assertNoAddressesLogged(): void {
        $blob = (string) json_encode($this->logger->entries);
        $this->assertFalse(strpos($blob, '@') !== false, $blob);
        $this->assertFalse(strpos($blob, 'example.com') !== false, $blob);
        $this->assertFalse(strpos($blob, 'example.test') !== false, $blob);
    }

    private function settingsOption(bool $enabled): array {
        return [
            'enabled' => $enabled,
            'recipients' => 'ops@example.com',
            'send_time' => '08:00',
            'coupon_code' => 'BF',
        ];
    }

    private function salesCronEvents(): array {
        $log = isset($GLOBALS['intersoccer_test_wp_schedule_event_log']) && is_array($GLOBALS['intersoccer_test_wp_schedule_event_log'])
            ? $GLOBALS['intersoccer_test_wp_schedule_event_log']
            : [];
        $events = [];
        foreach ($log as $entry) {
            if (is_array($entry) && ($entry['hook'] ?? '') === DailyBfSalesEmail::CRON_HOOK) {
                $events[] = $entry;
            }
        }
        return $events;
    }

    private function salesCronClears(): array {
        $log = isset($GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log']) && is_array($GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log'])
            ? $GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log']
            : [];
        $clears = [];
        foreach ($log as $entry) {
            if (is_array($entry) && ($entry['hook'] ?? '') === DailyBfSalesEmail::CRON_HOOK) {
                $clears[] = $entry;
            }
        }
        return $clears;
    }

    private function assertActionRegistered(string $hook, string $method, int $acceptedArgs): void {
        $byPriority = $GLOBALS['intersoccer_test_wp_hooks']['actions'][$hook] ?? [];
        foreach ($byPriority as $callbacks) {
            if (!is_array($callbacks)) {
                continue;
            }
            foreach ($callbacks as $entry) {
                $callback = $entry['callback'] ?? null;
                if (!is_array($callback) || ($callback[1] ?? '') !== $method) {
                    continue;
                }
                $this->assertGreaterThanOrEqual($acceptedArgs, (int) ($entry['accepted_args'] ?? 0), $hook);
                return;
            }
        }
        $this->fail('Missing action ' . $hook . ' ' . $method);
    }

    private function pluginForDeactivation(): \InterSoccer\ReportsRosters\Core\Plugin {
        $class = new \ReflectionClass(\InterSoccer\ReportsRosters\Core\Plugin::class);
        $plugin = $class->newInstanceWithoutConstructor();
        $logger = new \InterSoccer\ReportsRosters\Core\Logger([
            'level' => 'emergency',
            'log_to_file' => false,
            'log_to_db' => false,
        ]);
        $this->setPluginProperty($plugin, 'logger', $logger);
        $this->setPluginProperty($plugin, 'database', new \InterSoccer\ReportsRosters\Core\Database($logger));
        $this->setPluginProperty($plugin, 'cache', new \InterSoccer\ReportsRosters\Services\CacheManager($logger));
        $this->setPluginProperty($plugin, 'plugin_path', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
        return $plugin;
    }

    private function setPluginProperty($plugin, string $name, $value): void {
        $property = new \ReflectionProperty($plugin, $name);
        $property->setAccessible(true);
        $property->setValue($plugin, $value);
    }

    private function enabledService(array $coupon, string $recipients): DailyBfSalesEmail {
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = [
            'enabled' => true,
            'recipients' => $recipients,
            'send_time' => '08:00',
            'coupon_code' => 'BF',
        ];
        return $this->makeService($coupon);
    }

    private function makeService(array $coupon): DailyBfSalesEmail {
        $this->wpdb = new DailyBfSalesEmailFixtureWpdb();
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);
        $this->reports = new FinancialReportService($this->logger, $database);
        return new DailyBfSalesEmail(
            $this->logger,
            $this->reports,
            function ($code) use ($coupon) {
                return $coupon;
            },
            new \DateTimeZone('Europe/Zurich')
        );
    }

    private function seedCatalogue(): void {
        $paid = function ($local) {
            return (string) $this->unix($local);
        };
        $this->wpdb->orders = [
            [
                'id' => 501,
                'post_date' => '2026-11-28 10:00:00',
                'date_paid' => $paid('2026-11-28 10:00:00'),
                'items' => [
                    $this->item(5011, 'Alpine Camp Week', 100, 1001, 2, '200.00', '200.00', '15.40'),
                    $this->item(5012, 'Lake Course', 200, 2001, 1, '80.00', '50.00', '4.05'),
                ],
            ],
            [
                'id' => 502,
                'post_date' => '2026-11-28 11:00:00',
                'date_paid' => $paid('2026-11-28 11:00:00'),
                'items' => [
                    $this->item(5021, 'Alpine Camp Week', 100, 1001, 1, '100.00', '100.00', '7.70'),
                    $this->item(5022, 'Extra Session', 300, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ],
            [
                'id' => 503,
                'post_date' => '2026-11-28 12:00:00',
                'date_paid' => '',
                'items' => [
                    $this->item(5031, 'City Birthday', 400, 4001, 1, '60.00', '60.00', '4.86'),
                ],
            ],
            [
                'id' => 504,
                'post_date' => '2026-10-01 09:00:00',
                'date_paid' => $paid('2026-11-28 15:00:00'),
                'items' => [
                    $this->item(5041, 'Regional Tournament', 500, 5001, 1, '25.00', '25.00', '2.03'),
                ],
            ],
            [
                'id' => 505,
                'post_date' => '2026-11-28 10:00:00',
                'date_paid' => $paid('2026-11-27 10:00:00'),
                'items' => [
                    $this->item(5051, 'Excluded Camp', 600, 6001, 1, '999.00', '999.00', '0.00'),
                ],
            ],
            [
                'id' => 506,
                'post_date' => '2026-11-29 09:00:00',
                'date_paid' => $paid('2026-11-29 09:00:00'),
                'items' => [
                    $this->item(5061, 'Alpine Camp Day', 100, 1002, 1, '40.00', '40.00', '3.08'),
                ],
            ],
        ];
        $this->wpdb->itemMeta = [
            5011 => [
                '_intersoccer_canonical_activity_type' => 'camp',
                'Attendee Name' => 'Secret Child',
            ],
            5012 => ['_intersoccer_canonical_activity_type' => 'course'],
            5021 => ['_intersoccer_canonical_activity_type' => 'camp'],
            5022 => ['_intersoccer_canonical_activity_type' => 'other'],
            5031 => ['_intersoccer_canonical_activity_type' => 'birthday'],
            5041 => ['_intersoccer_canonical_activity_type' => 'tournament'],
            5051 => ['_intersoccer_canonical_activity_type' => 'camp'],
            5061 => ['_intersoccer_canonical_activity_type' => 'camp'],
        ];
        \InterSoccerRRTestMeta::$data[501]['_billing_email'] = 'secret-buyer@example.com';
    }

    private function seedExpiryDayOrder(): void {
        $this->wpdb->orders[] = [
            'id' => 507,
            'post_date' => '2026-11-30 09:00:00',
            'date_paid' => (string) $this->unix('2026-11-30 09:00:00'),
            'items' => [
                $this->item(5071, 'Closing Camp', 100, 1003, 1, '55.00', '55.00', '4.24'),
            ],
        ];
        $this->wpdb->itemMeta[5071] = [
            '_intersoccer_canonical_activity_type' => 'camp',
        ];
    }

    private function item($id, $name, $productId, $variationId, $qty, $subtotal, $total, $tax): array {
        return [
            'id' => $id,
            'name' => $name,
            'product_id' => $productId,
            'variation_id' => $variationId,
            'qty' => $qty,
            'subtotal' => $subtotal,
            'total' => $total,
            'tax' => $tax,
        ];
    }

    private function novemberCoupon(): array {
        return [
            'start' => $this->unix('2026-11-28 00:00:00'),
            'expires' => $this->unix('2026-11-30 23:59:59'),
        ];
    }

    private function unix(string $local): int {
        return $this->zurich($local)->getTimestamp();
    }

    private function zurich(string $local): \DateTimeImmutable {
        return new \DateTimeImmutable($local, new \DateTimeZone('Europe/Zurich'));
    }

    private function ymdFromUtc(string $local): string {
        $stamp = (new \DateTimeImmutable($local, new \DateTimeZone('UTC')))->getTimestamp();
        return DailyBfSalesEmail::zurichYmdFromTimestamp($stamp);
    }

    private function typeByLabel(array $summary, string $label): array {
        foreach ($summary['types'] as $type) {
            if ($type['label'] === $label) {
                return $type;
            }
        }
        $this->fail('Missing product type ' . $label);
    }

    private function productByLabel(array $summary, string $label): array {
        foreach ($summary['products'] as $product) {
            if ($product['label'] === $label) {
                return $product;
            }
        }
        $this->fail('Missing product ' . $label);
    }

    private function cents(float $amount): string {
        return number_format($amount, 2, '.', '');
    }

    private function bookingSql(): string {
        foreach ($this->wpdb->queries as $query) {
            if (strpos($query, "post_type = 'shop_order'") !== false) {
                return $query;
            }
        }
        $this->fail('Booking query was not recorded');
    }
}
}
