<?php
/**
 * Daily sales email: Zurich bucketing, coupon window, VAT, and test send.
 *
 * Send-to: empty tokens are skipped and invalid tokens are dropped. A mixed
 * list sends to the valid addresses only. The send stops when none remain.
 * Logs store a reason code only.
 */

namespace {
    require_once dirname(__DIR__, 2) . '/includes/daily-bf-sales-email.php';

    if (!function_exists('intersoccer_rr_bf_note_db_call')) {
        /**
         * Counts wpdb reads while the sample-send test has counting turned on.
         *
         * @return void
         */
        function intersoccer_rr_bf_note_db_call() {
            if (empty($GLOBALS['intersoccer_rr_bf_db_counting'])) {
                return;
            }
            $GLOBALS['intersoccer_rr_bf_db_calls'] = (int) ($GLOBALS['intersoccer_rr_bf_db_calls'] ?? 0) + 1;
        }
    }

    if (!function_exists('intersoccer_rr_bf_note_shop_call')) {
        /**
         * Counts selected shop reads while the sample-send test has counting turned on.
         *
         * @param string $name
         * @return void
         */
        function intersoccer_rr_bf_note_shop_call($name) {
            if (empty($GLOBALS['intersoccer_rr_bf_shop_counting'])) {
                return;
            }
            if (!isset($GLOBALS['intersoccer_rr_bf_shop_calls']) || !is_array($GLOBALS['intersoccer_rr_bf_shop_calls'])) {
                $GLOBALS['intersoccer_rr_bf_shop_calls'] = [];
            }
            $name = (string) $name;
            $GLOBALS['intersoccer_rr_bf_shop_calls'][$name] = (int) ($GLOBALS['intersoccer_rr_bf_shop_calls'][$name] ?? 0) + 1;
        }
    }

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

    if (!function_exists('wc_get_coupon_id_by_code')) {
        function wc_get_coupon_id_by_code($code) {
            if (!isset($GLOBALS['intersoccer_rr_bf_coupon_lookup_calls'])) {
                $GLOBALS['intersoccer_rr_bf_coupon_lookup_calls'] = 0;
            }
            $GLOBALS['intersoccer_rr_bf_coupon_lookup_calls']++;
            $map = (isset($GLOBALS['intersoccer_rr_bf_coupon_ids']) && is_array($GLOBALS['intersoccer_rr_bf_coupon_ids']))
                ? $GLOBALS['intersoccer_rr_bf_coupon_ids']
                : [];
            return isset($map[$code]) ? (int) $map[$code] : 0;
        }
    }

    if (!class_exists('WC_Coupon')) {
        class WC_Coupon {
            /** @var int */
            private $id;

            /**
             * @param mixed $data Coupon id.
             */
            public function __construct($data = 0) {
                if (!isset($GLOBALS['intersoccer_rr_bf_wc_coupon_calls'])) {
                    $GLOBALS['intersoccer_rr_bf_wc_coupon_calls'] = 0;
                }
                $GLOBALS['intersoccer_rr_bf_wc_coupon_calls']++;
                $this->id = is_object($data) && isset($data->ID) ? (int) $data->ID : (int) $data;
            }

            /**
             * @return \DateTimeInterface|null
             */
            public function get_date_expires() {
                $map = (isset($GLOBALS['intersoccer_rr_bf_coupon_expiries']) && is_array($GLOBALS['intersoccer_rr_bf_coupon_expiries']))
                    ? $GLOBALS['intersoccer_rr_bf_coupon_expiries']
                    : [];
                if (!array_key_exists($this->id, $map)) {
                    return null;
                }
                $value = $map[$this->id];
                return ($value instanceof \DateTimeInterface) ? $value : null;
            }
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
        if (function_exists('intersoccer_rr_bf_note_db_call')) {
            \intersoccer_rr_bf_note_db_call();
        }
        $query = (string) $query;
        $this->queries[] = $query;
        if (strpos($query, "post_type = 'shop_order'") !== false) {
            if (strpos($query, '_date_paid') !== false) {
                return $this->bookingRows($query);
            }
            if (strpos($query, "'wc-failed'") !== false || strpos($query, "'wc-pending'") !== false) {
                return $this->createdStatusRows($query);
            }
            if ($this->isOnHoldCreatedQuery($query)) {
                return $this->createdStatusRows($query);
            }
            return [];
        }
        if (preg_match('/order_item_id = (\d+)/', $query, $matches)) {
            return $this->metaRows((int) $matches[1]);
        }
        return [];
    }

    public function get_col($query = null, $x = 0) {
        if (function_exists('intersoccer_rr_bf_note_db_call')) {
            \intersoccer_rr_bf_note_db_call();
        }
        $this->queries[] = (string) $query;
        return [];
    }

    private function isOnHoldCreatedQuery($query) {
        return strpos($query, "'wc-on-hold'") !== false
            && strpos($query, "'wc-completed'") === false
            && strpos($query, 'p.post_date') !== false;
    }

    private function createdStatusRows($query) {
        if (!preg_match("/p\\.post_date >= '([^']+)' AND p\\.post_date < '([^']+)'/", $query, $created)) {
            return [];
        }
        $statuses = $this->statusList($query);
        $rows = [];
        foreach ($this->orders as $order) {
            $status = isset($order['status']) ? (string) $order['status'] : 'wc-completed';
            if ($statuses !== [] && !in_array($status, $statuses, true)) {
                continue;
            }
            $postDate = (string) $order['post_date'];
            if (strcmp($postDate, $created[1]) < 0 || strcmp($postDate, $created[2]) >= 0) {
                continue;
            }
            foreach ($order['items'] as $item) {
                $rows[] = (object) [
                    'order_id' => $order['id'],
                    'order_date' => $order['post_date'],
                    'order_status' => $status,
                    'order_item_id' => $item['id'],
                    'order_item_name' => $item['name'],
                    'product_id' => (string) $item['product_id'],
                    'variation_id' => (string) $item['variation_id'],
                    'quantity' => (string) $item['qty'],
                    'line_total' => $item['total'],
                    'line_subtotal' => $item['subtotal'],
                    'line_tax' => $item['tax'],
                    'date_paid_gmt' => isset($order['date_paid']) ? (string) $order['date_paid'] : '',
                ];
            }
        }
        return $rows;
    }

    private function bookingRows($query) {
        if (strpos($query, '_date_paid') === false) {
            return [];
        }
        $paidStart = 1;
        $paidEnd = PHP_INT_MAX;
        $hasWindow = preg_match('/CAST\(paid_meta\.meta_value AS UNSIGNED\) >= (\d+) AND CAST\(paid_meta\.meta_value AS UNSIGNED\) < (\d+)/', $query, $paid);
        if ($hasWindow) {
            $paidStart = (int) $paid[1];
            $paidEnd = (int) $paid[2];
        } elseif (strpos($query, "paid_meta.meta_value <> '0'") === false) {
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
            if (!$this->orderIncluded($order, $paidStart, $paidEnd, $createdFallback, $createdStart, $createdEnd, $statuses)) {
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
        $this->resetDailyBfIsolation();
        $this->logger = new DailyBfSalesEmailCapturingLogger();
    }

    protected function tearDown(): void {
        $this->resetDailyBfIsolation();
        parent::tearDown();
    }

    /**
     * Drop state left by earlier tests: the email's static registration flag,
     * cron bookkeeping, options, and hook callbacks.
     *
     * Brain Monkey teardown is intentionally not called here. Container reset
     * and Patchwork restore forget redefinitions that later tests still use,
     * and this class does not install its own Monkey patches.
     */
    private function resetDailyBfIsolation(): void {
        $resetStatics = \Closure::bind(static function () {
            self::$hooksRegistered = false;
            self::$instance = null;
        }, null, DailyBfSalesEmail::class);
        $resetStatics();

        if (!isset($GLOBALS['intersoccer_test_wp_hooks']) || !is_array($GLOBALS['intersoccer_test_wp_hooks'])) {
            $GLOBALS['intersoccer_test_wp_hooks'] = ['actions' => [], 'filters' => []];
        }
        if (!isset($GLOBALS['intersoccer_test_wp_hooks']['actions']) || !is_array($GLOBALS['intersoccer_test_wp_hooks']['actions'])) {
            $GLOBALS['intersoccer_test_wp_hooks']['actions'] = [];
        }
        if (!isset($GLOBALS['intersoccer_test_wp_hooks']['filters']) || !is_array($GLOBALS['intersoccer_test_wp_hooks']['filters'])) {
            $GLOBALS['intersoccer_test_wp_hooks']['filters'] = [];
        }
        $actionHooks = [
            DailyBfSalesEmail::CRON_HOOK,
            'admin_menu',
            'admin_init',
            'update_option',
            'update_option_' . DailyBfSalesEmail::OPTION_SETTINGS,
        ];
        foreach ($actionHooks as $hook) {
            if (empty($GLOBALS['intersoccer_test_wp_hooks']['actions'][$hook]) || !is_array($GLOBALS['intersoccer_test_wp_hooks']['actions'][$hook])) {
                continue;
            }
            foreach ($GLOBALS['intersoccer_test_wp_hooks']['actions'][$hook] as $priority => $callbacks) {
                if (!is_array($callbacks)) {
                    continue;
                }
                $kept = [];
                foreach ($callbacks as $entry) {
                    $callback = is_array($entry) ? ($entry['callback'] ?? null) : null;
                    $owner = is_array($callback) ? ($callback[0] ?? null) : null;
                    if ($owner === DailyBfSalesEmail::class || $owner instanceof DailyBfSalesEmail) {
                        continue;
                    }
                    $kept[] = $entry;
                }
                $GLOBALS['intersoccer_test_wp_hooks']['actions'][$hook][$priority] = $kept;
            }
        }
        foreach ([
            DailyBfSalesEmail::PAID_ORDER_STATUSES_FILTER,
            DailyBfSalesEmail::COMPOSITE_SEASON_TYPE_FILTER,
            DailyBfSalesEmail::ATTENTION_ORDER_STATUSES_FILTER,
            'wpml_object_id',
        ] as $filterHook) {
            unset($GLOBALS['intersoccer_test_wp_hooks']['filters'][$filterHook]);
        }

        $GLOBALS['intersoccer_test_option_store'] = [];
        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $GLOBALS['intersoccer_rr_bf_wp_mail_fail'] = false;
        $GLOBALS['intersoccer_rr_bf_wp_mail_error'] = '';
        $GLOBALS['intersoccer_rr_bf_wc_coupon_calls'] = 0;
        $GLOBALS['intersoccer_rr_bf_coupon_lookup_calls'] = 0;
        $GLOBALS['intersoccer_rr_bf_db_calls'] = 0;
        $GLOBALS['intersoccer_rr_bf_db_counting'] = false;
        $GLOBALS['intersoccer_rr_bf_shop_calls'] = [];
        $GLOBALS['intersoccer_rr_bf_shop_counting'] = false;
        unset($GLOBALS['intersoccer_rr_test_api_counting_enabled'], $GLOBALS['intersoccer_rr_test_api_call_counts']);
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = false;
        $GLOBALS['intersoccer_test_wp_schedule_event_log'] = [];
        $GLOBALS['intersoccer_test_wp_next_scheduled'] = [];
        $GLOBALS['intersoccer_test_wp_clear_scheduled_hook_log'] = [];
        unset(
            $GLOBALS['intersoccer_test_current_user_caps'],
            $GLOBALS['intersoccer_test_current_user'],
            $GLOBALS['intersoccer_test_get_posts_callback'],
            $GLOBALS['intersoccer_test_post_titles'],
            $GLOBALS['intersoccer_test_post_parents'],
            $GLOBALS['intersoccer_rr_bf_coupon_ids'],
            $GLOBALS['intersoccer_rr_bf_coupon_expiries'],
            $GLOBALS['intersoccer_rr_bf_hpos_enabled']
        );
        \InterSoccerRRTestMeta::reset();

        global $wpdb;
        if (class_exists('InterSoccerTestWpdb', false)) {
            $wpdb = new \InterSoccerTestWpdb();
        }
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
        $this->assertStringContainsString('p.post_date', $onHoldSql);
        $this->assertStringContainsString('_line_tax', $onHoldSql);
        $this->assertStringNotContainsString('_date_paid', $onHoldSql);
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

    public function test_end_of_day_and_midnight_expiry_share_the_final_send_morning(): void {
        $start = $this->unix('2026-11-18 00:00:00');
        $endOfDay = $this->unix('2026-11-30 23:59:59');
        $midnight = $this->unix('2026-12-01 00:00:00');

        foreach ([$endOfDay, $midnight] as $expires) {
            $this->assertTrue(DailyBfSalesEmail::zurichDayOverlapsWindow('2026-11-30', $start, $expires));
            $this->assertFalse(DailyBfSalesEmail::zurichDayOverlapsWindow('2026-12-01', $start, $expires));
            $this->assertTrue(DailyBfSalesEmail::isFinalCampaignDay('2026-11-30', $start, $expires));
            $this->assertFalse(DailyBfSalesEmail::isFinalCampaignDay('2026-12-01', $start, $expires));
            $this->assertFalse(DailyBfSalesEmail::isFinalCampaignDay('2026-11-29', $start, $expires));
        }
        $this->assertSame($endOfDay, DailyBfSalesEmail::effectiveExpiryUnix($endOfDay));
        $this->assertSame($endOfDay, DailyBfSalesEmail::effectiveExpiryUnix($midnight));

        $endOfDayCoupon = ['start' => $start, 'expires' => $endOfDay];
        $midnightCoupon = ['start' => $start, 'expires' => $midnight];
        $this->assertExpiryMorning($endOfDayCoupon, '2026-12-01 08:00:00', 'sent', true, '2026-11-30');
        $this->assertExpiryMorning($endOfDayCoupon, '2026-12-02 08:00:00', 'outside_window', false, '');
        $this->assertExpiryMorning($midnightCoupon, '2026-12-01 08:00:00', 'sent', true, '2026-11-30');
        $this->assertExpiryMorning($midnightCoupon, '2026-12-02 08:00:00', 'outside_window', false, '');
    }

    public function test_woo_coupon_midnight_expiry_is_stored_as_the_previous_day(): void {
        $zone = new \DateTimeZone('Europe/Zurich');
        $endOfDay = new \DateTime('2026-11-30 23:59:59', $zone);
        $midnight = new \WC_DateTime('2026-12-01 00:00:00', $zone);
        $GLOBALS['intersoccer_rr_bf_coupon_ids'] = ['END' => 71, 'MIDNIGHT' => 72, 'META' => 73];
        $GLOBALS['intersoccer_rr_bf_coupon_expiries'] = [
            71 => $endOfDay,
            72 => $midnight,
        ];
        \InterSoccerRRTestMeta::$data[73]['date_expires'] = (string) $midnight->getTimestamp();

        $service = $this->wooService();
        $method = new \ReflectionMethod(DailyBfSalesEmail::class, 'loadCouponFromWoo');
        $method->setAccessible(true);
        $end = $method->invoke($service, 'END');
        $mid = $method->invoke($service, 'MIDNIGHT');
        $meta = $method->invoke($service, 'META');
        $this->assertSame($endOfDay->getTimestamp(), $end['expires']);
        $this->assertSame($endOfDay->getTimestamp(), $mid['expires']);
        $this->assertSame($endOfDay->getTimestamp(), $meta['expires']);

        $settings = [
            'enabled' => true,
            'recipients' => 'ops@example.com',
            'send_time' => '08:00',
            'coupon_code' => 'MIDNIGHT',
            'campaign_start' => '2026-11-18',
        ];
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = $settings;
        $this->assertSame('sent', $service->runScheduled($this->zurich('2026-12-01 08:00:00')));
        $this->assertStringContainsString('Final ', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $this->assertStringContainsString('2026-11-30', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $this->assertStringNotContainsString('2026-12-01', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);

        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $GLOBALS['intersoccer_test_option_store'] = [];
        $nextMorning = $settings;
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = $nextMorning;
        $again = $this->wooService();
        $this->assertSame('outside_window', $again->runScheduled($this->zurich('2026-12-02 08:00:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);

        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $GLOBALS['intersoccer_test_option_store'] = [
            DailyBfSalesEmail::OPTION_SETTINGS => [
                'enabled' => true,
                'recipients' => 'ops@example.com',
                'send_time' => '08:00',
                'coupon_code' => 'META',
                'campaign_start' => '2026-11-18',
            ],
        ];
        $fromMeta = $this->wooService();
        $this->assertSame('sent', $fromMeta->runScheduled($this->zurich('2026-12-01 08:00:00')));
        $this->assertStringStartsWith('Final ', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $this->assertStringContainsString('2026-11-30', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_STATE] = [];
        $this->assertSame('outside_window', $fromMeta->runScheduled($this->zurich('2026-12-02 08:00:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
    }

    public function test_campaign_start_setting_and_woo_expiry_set_the_window(): void {
        $zone = new \DateTimeZone('Europe/Zurich');
        $GLOBALS['intersoccer_rr_bf_coupon_ids'] = ['BF' => 93];
        $GLOBALS['intersoccer_rr_bf_coupon_expiries'] = [
            93 => new \WC_DateTime('2026-11-30 23:59:59', $zone),
        ];
        \InterSoccerRRTestMeta::$data[93]['date_starts'] = '2026-12-20';
        \InterSoccerRRTestMeta::$data[93]['_date_starts'] = '2026-12-20';
        \InterSoccerRRTestMeta::$data[93]['date_expires'] = (string) $this->unix('2026-12-31 23:59:59');

        $service = $this->wooService();
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = [
            'enabled' => true,
            'recipients' => 'ops@example.com',
            'send_time' => '08:00',
            'coupon_code' => 'BF',
            'campaign_start' => '2026-11-18',
        ];
        $this->logger->entries = [];

        $this->assertSame('outside_window', $service->runScheduled($this->zurich('2026-11-18 08:00:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame('sent', $service->runScheduled($this->zurich('2026-11-19 08:00:00')));
        $this->assertStringContainsString('2026-11-18', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $this->assertStringNotContainsString('Final', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
        $this->assertSame('outside_window', $service->runScheduled($this->zurich('2026-12-31 08:00:00')));
        $this->assertNoAddressesLogged();

        ob_start();
        $service->renderPage();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="bf_campaign_start"', $html);
        $this->assertStringContainsString('value="2026-11-18"', $html);
        $this->assertStringContainsString('date_expires', $html);
        $this->assertStringNotContainsString('_date_starts', $html);
        $this->assertStringNotContainsString('date_starts', $html);
        $this->assertStringNotContainsString('notice-warning', $html);
    }

    public function test_loader_start_is_ignored_and_the_setting_opens_the_window(): void {
        $service = $this->enabledService([
            'start' => $this->unix('2026-12-20 00:00:00'),
            'expires' => $this->unix('2026-11-30 23:59:59'),
        ], 'ops@example.com');
        $stored = $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS];
        $stored['campaign_start'] = '2026-11-18';
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = $stored;

        $this->assertSame('outside_window', $service->runScheduled($this->zurich('2026-11-18 08:00:00')));
        $this->assertSame('sent', $service->runScheduled($this->zurich('2026-11-19 08:00:00')));
        $this->assertStringContainsString('2026-11-18', $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject']);
    }

    public function test_missing_campaign_start_shows_a_notice_and_does_not_send(): void {
        $GLOBALS['intersoccer_rr_bf_coupon_ids'] = ['BF' => 94];
        $GLOBALS['intersoccer_rr_bf_coupon_expiries'] = [
            94 => new \WC_DateTime('2026-11-30 23:59:59', new \DateTimeZone('Europe/Zurich')),
        ];
        $service = $this->wooService();
        $saved = $service->handleAdminPost([
            'bf_save' => '1',
            '_wpnonce' => 'test',
            'bf_enabled' => '1',
            'bf_recipients' => 'ops@example.com',
            'bf_send_time' => '08:00',
            'bf_coupon_code' => 'BF',
            'bf_campaign_start' => '',
        ]);
        $this->assertTrue($saved['ok']);
        $this->assertSame('', $service->settings()['campaign_start']);

        $this->logger->entries = [];
        $this->assertSame('campaign_start_missing', $service->runScheduled($this->zurich('2026-11-29 08:00:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame([], $service->state()['sent_dates']);
        $this->assertSame('campaign_start_missing', $service->state()['last_result']);
        $this->assertNoAddressesLogged();

        unset($_GET['bf_notice'], $_GET['bf_detail']);
        ob_start();
        $service->renderPage();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Campaign start date is not set. The scheduled email will not send.', $html);
        $this->assertStringContainsString('notice-warning', $html);
        $warning = $this->htmlSection($html, 'notice-warning', '</div>');
        $this->assertStringNotContainsString('@', $warning);
        $this->assertStringNotContainsString('ops@example.com', $warning);
    }

    public function test_missing_coupon_expiry_shows_a_notice_and_does_not_send(): void {
        $GLOBALS['intersoccer_rr_bf_coupon_ids'] = ['BF' => 95];
        $service = $this->wooService();
        $GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS] = [
            'enabled' => true,
            'recipients' => 'ops@example.com',
            'send_time' => '08:00',
            'coupon_code' => 'BF',
            'campaign_start' => '2026-11-18',
        ];
        $this->logger->entries = [];
        $this->assertSame('coupon_missing_expiry', $service->runScheduled($this->zurich('2026-11-29 08:00:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame([], $service->state()['sent_dates']);
        $this->assertSame('coupon_missing_expiry', $service->state()['last_result']);
        $this->assertNoAddressesLogged();

        unset($_GET['bf_notice'], $_GET['bf_detail']);
        ob_start();
        $service->renderPage();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('The coupon has no expiry date. The scheduled email will not send.', $html);
        $this->assertStringNotContainsString('Campaign start date is not set.', $html);
        $warning = $this->htmlSection($html, 'notice-warning', '</div>');
        $this->assertStringNotContainsString('@', $warning);
    }

    public function test_invalid_campaign_start_is_rejected_and_an_empty_start_can_be_saved(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $before = $service->settings();
        $rejected = $service->handleAdminPost([
            'bf_save' => '1',
            '_wpnonce' => 'test',
            'bf_enabled' => '1',
            'bf_recipients' => 'ops@example.com',
            'bf_send_time' => '08:00',
            'bf_coupon_code' => 'BF',
            'bf_campaign_start' => '2026-02-31',
        ]);
        $this->assertFalse($rejected['ok']);
        $this->assertSame('invalid_campaign_start', $rejected['code']);
        $this->assertStringContainsString('YYYY-MM-DD', $rejected['message']);
        $this->assertSame($before, $service->settings());

        $cleared = $service->handleAdminPost([
            'bf_save' => '1',
            '_wpnonce' => 'test',
            'bf_enabled' => '1',
            'bf_recipients' => 'ops@example.com',
            'bf_send_time' => '08:00',
            'bf_coupon_code' => 'BF',
            'bf_campaign_start' => '',
        ]);
        $this->assertTrue($cleared['ok']);
        $this->assertSame('', $service->settings()['campaign_start']);
        $this->logger->entries = [];
        $this->assertSame('campaign_start_missing', $service->runScheduled($this->zurich('2026-11-29 08:00:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertNoAddressesLogged();
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
            'not-an-email, also-bad' => 'invalid_recipient',
            'ops@example.com;other@example.test' => 'invalid_recipient',
            'ops@example.com other@example.test' => 'invalid_recipient',
        ];
        foreach ($cases as $recipients => $reason) {
            $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
            $this->logger->entries = [];
            $service = $this->enabledService($this->novemberCoupon(), (string) $recipients);
            $this->assertSame($reason, $service->runScheduled($this->zurich('2026-11-29 08:00:00')), (string) $recipients);
            $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
            $this->assertSame([], $service->state()['sent_dates']);
            $this->assertNoAddressesLogged();
        }

        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $this->logger->entries = [];
        $mixed = $this->enabledService($this->novemberCoupon(), 'ops@example.com, not-an-email, other@example.test');
        $this->assertSame('sent', $mixed->runScheduled($this->zurich('2026-11-29 08:00:00')));
        $this->assertSame(
            ['ops@example.com', 'other@example.test'],
            $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['to']
        );
        $this->assertArrayHasKey('2026-11-28', $mixed->state()['sent_dates']);
        $this->assertNoAddressesLogged();
    }

    public function test_mixed_recipient_save_keeps_valid_addresses_and_notices_dropped_tokens(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'keep@example.com');
        $this->logger->entries = [];
        $result = $service->handleAdminPost([
            'bf_save' => '1',
            '_wpnonce' => 'test',
            'bf_enabled' => '1',
            'bf_recipients' => "ops@example.com, not-an-email, other@example.test\n",
            'bf_send_time' => '08:00',
            'bf_coupon_code' => 'BF',
            'bf_campaign_start' => '2026-11-28',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('saved', $result['code']);
        $this->assertStringContainsString('not-an-email', $result['message']);
        $this->assertStringNotContainsString('ops@example.com', $result['message']);
        $this->assertSame('ops@example.com, other@example.test', $service->settings()['recipients']);
        $this->assertSame('2026-11-28', $service->settings()['campaign_start']);
        $this->assertNoAddressesLogged();

        $_GET['bf_notice'] = $result['code'];
        $_GET['bf_detail'] = $result['message'];
        ob_start();
        $service->renderPage();
        $html = (string) ob_get_clean();
        unset($_GET['bf_notice'], $_GET['bf_detail']);
        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('not-an-email', $html);
        $this->assertNoAddressesLogged();
    }

    public function test_mixed_recipient_list_sends_only_to_valid_addresses(): void {
        $service = $this->enabledService(
            $this->novemberCoupon(),
            'ops@example.com, not-an-email, bad;token, other@example.test'
        );
        $this->logger->entries = [];
        $this->assertSame('sent', $service->runScheduled($this->zurich('2026-11-29 08:00:00')));
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame(
            ['ops@example.com', 'other@example.test'],
            $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['to']
        );
        $this->assertSame('sent', $service->state()['last_result']);
        $this->assertNoAddressesLogged();
    }

    public function test_all_invalid_recipients_fail_on_save_and_send_without_logging_addresses(): void {
        $direct = $this->enabledService($this->novemberCoupon(), 'not-an-email, also-bad');
        $this->logger->entries = [];
        $this->assertSame('invalid_recipient', $direct->runScheduled($this->zurich('2026-11-29 08:00:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame([], $direct->state()['sent_dates']);
        $this->assertNoAddressesLogged();

        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->logger->entries = [];
        $saved = $service->handleAdminPost([
            'bf_save' => '1',
            '_wpnonce' => 'test',
            'bf_enabled' => '1',
            'bf_recipients' => 'not-an-email, also-bad',
            'bf_send_time' => '08:00',
            'bf_coupon_code' => 'BF',
            'bf_campaign_start' => '2026-11-28',
        ]);
        $this->assertFalse($saved['ok']);
        $this->assertSame('invalid_recipient', $saved['code']);
        $this->assertStringContainsString('not-an-email', $saved['message']);
        $this->assertStringContainsString('also-bad', $saved['message']);
        $this->assertSame('', $service->settings()['recipients']);
        $this->assertStringNotContainsString('Test email sent', $saved['message']);
        $this->assertNoAddressesLogged();

        $this->assertSame('empty_recipients', $service->runScheduled($this->zurich('2026-11-29 08:00:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame([], $service->state()['sent_dates']);
        $this->assertNoAddressesLogged();
    }

    public function test_empty_recipient_list_saves_a_clear_result_and_does_not_send(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->logger->entries = [];
        $saved = $service->handleAdminPost([
            'bf_save' => '1',
            '_wpnonce' => 'test',
            'bf_enabled' => '1',
            'bf_recipients' => ' , ',
            'bf_send_time' => '09:30',
            'bf_coupon_code' => 'BF',
            'bf_campaign_start' => '2026-11-28',
        ]);
        $this->assertFalse($saved['ok']);
        $this->assertSame('empty_recipients', $saved['code']);
        $this->assertStringContainsString('empty', $saved['message']);
        $this->assertSame('', $service->settings()['recipients']);
        $this->assertSame('09:30', $service->settings()['send_time']);
        $this->assertNoAddressesLogged();

        $this->assertSame('empty_recipients', $service->runScheduled($this->zurich('2026-11-29 09:30:00')));
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame('empty_recipients', $service->state()['last_result']);
        $this->assertNoAddressesLogged();
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

    public function test_region_buckets_group_french_german_and_english_labels(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $paid = (string) $this->unix('2026-11-28 10:00:00');
        $orders = [];
        $meta = [];
        $add = function ($id, $name, $itemMeta) use (&$orders, &$meta, $paid) {
            $orders[] = [
                'id' => $id,
                'post_date' => '2026-11-28 10:00:00',
                'date_paid' => $paid,
                'items' => [
                    $this->item($id * 10, $name, $id, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ];
            $itemMeta['_intersoccer_canonical_activity_type'] = 'camp';
            $meta[$id * 10] = $itemMeta;
        };
        $add(801, 'Canonical Geneva Camp', ['_intersoccer_canonical_canton' => 'geneva']);
        $add(802, 'French Label Camp', ['Canton / Région' => 'Genève']);
        $add(803, 'German Label Camp', ['Kanton / Region' => 'Genf']);
        $add(804, 'English Geneva Camp', ['Canton / Region' => 'Geneva']);
        $add(805, 'French Basel Camp', ['Canton / Region' => 'Bâle']);
        $add(806, 'English Basel Camp', ['pa_canton-region' => 'Basel']);
        $add(807, 'Product Region Camp', []);
        $add(808, 'Canonical Vaud Camp', [
            '_intersoccer_canonical_canton' => 'vaud',
            'Canton / Région' => 'Genf',
        ]);
        $this->wpdb->orders = $orders;
        $this->wpdb->itemMeta = $meta;
        \InterSoccerRRTestMeta::$data[807]['attribute_pa_canton-region'] = 'Zurich';

        $digest = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $this->assertSame(4, $this->regionByLabel($digest['daily'], 'Geneva')['quantity']);
        $this->assertSame(2, $this->regionByLabel($digest['daily'], 'Basel-Stadt')['quantity']);
        $this->assertSame(1, $this->regionByLabel($digest['daily'], 'Zurich')['quantity']);
        $this->assertSame(1, $this->regionByLabel($digest['daily'], 'Vaud')['quantity']);

        $html = $service->renderHtml($digest);
        $this->assertStringContainsString('<h3>Regions</h3>', $html);
        $this->assertStringContainsString('Geneva', $html);
        $this->assertStringContainsString('Basel-Stadt', $html);
        $this->assertStringContainsString('Zurich', $html);
        $this->assertStringNotContainsString('Genève', $html);
        $this->assertStringNotContainsString('Genf', $html);
        $this->assertStringNotContainsString('Bâle', $html);
    }

    public function test_seasons_normalize_and_easter_promotes_spring_camps_only(): void {
        $this->assertTrue(DailyBfSalesEmail::COMPOSITE_SEASON_TYPE_LABELS);
        $this->assertTrue(DailyBfSalesEmail::compositeSeasonTypeLabels());

        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $paid = (string) $this->unix('2026-11-28 10:00:00');
        $orders = [];
        $meta = [];
        $add = function ($id, $name, $type, array $itemMeta) use (&$orders, &$meta, $paid) {
            $orders[] = [
                'id' => $id,
                'post_date' => '2026-11-28 10:00:00',
                'date_paid' => $paid,
                'items' => [
                    $this->item($id * 10, $name, $id, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ];
            $itemMeta['_intersoccer_canonical_activity_type'] = $type;
            $meta[$id * 10] = $itemMeta;
        };
        $add(901, 'Monday Course', 'course', ['Saison (Programm)' => 'Hiver']);
        $add(902, 'Easter skills course', 'course', ['Jahreszeit' => 'Frühling']);
        $add(903, 'Plain Camp', 'camp', ['Season' => 'Spring']);
        $add(904, 'Lakeside Camp', 'camp', ['Saison' => 'Printemps', 'Semaine de camp' => 'Pâques']);
        $add(905, 'Holiday Camp', 'camp', ['Season' => 'Ostern']);
        $add(906, 'Skills Course', 'course', ['attribute_pa_program-season' => 'Paques']);
        $this->wpdb->orders = $orders;
        $this->wpdb->itemMeta = $meta;

        $digest = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $daily = $digest['daily'];
        $this->assertSame(1, $this->seasonTypeByLabel($daily, 'Winter Courses')['quantity']);
        $this->assertSame(1, $this->seasonTypeByLabel($daily, 'Spring Courses')['quantity']);
        $this->assertSame(1, $this->seasonTypeByLabel($daily, 'Spring Camps')['quantity']);
        $this->assertSame(2, $this->seasonTypeByLabel($daily, 'Easter Camps')['quantity']);
        $this->assertSame(1, $this->seasonTypeByLabel($daily, 'Easter Courses')['quantity']);
        $this->assertSame(1, $this->seasonByLabel($daily, 'Winter')['quantity']);
        $this->assertSame(2, $this->seasonByLabel($daily, 'Spring')['quantity']);
        $this->assertSame(3, $this->seasonByLabel($daily, 'Easter')['quantity']);

        $html = $service->renderHtml($digest);
        $this->assertStringContainsString('<h3>Regions</h3>', $html);
        $this->assertStringContainsString('<h3>Season and type</h3>', $html);
        $this->assertStringContainsString('<h3>Products</h3>', $html);
        $this->assertStringContainsString('Winter Courses', $html);
        $this->assertStringContainsString('Easter Camps', $html);
        $this->assertStringContainsString('Spring Courses', $html);
        $this->assertStringNotContainsString('<h3>Seasons</h3>', $html);
        $this->assertStringNotContainsString('<th>Product type</th>', $html);

        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        $filter = DailyBfSalesEmail::COMPOSITE_SEASON_TYPE_FILTER;
        add_filter($filter, static function () {
            return false;
        });
        try {
            $this->assertFalse(DailyBfSalesEmail::compositeSeasonTypeLabels());
            $split = $service->renderHtml($digest);
            $this->assertStringContainsString('<h3>Seasons</h3>', $split);
            $this->assertStringContainsString('<th>Product type</th>', $split);
            $this->assertStringContainsString('<h3>Regions</h3>', $split);
            $this->assertStringNotContainsString('Winter Courses', $split);
            $this->assertStringNotContainsString('Easter Camps', $split);
            $this->assertStringNotContainsString('<h3>Season and type</h3>', $split);
            $this->assertStringContainsString('Easter', $split);
            $this->assertStringContainsString('Spring', $split);
        } finally {
            unset($GLOBALS['intersoccer_test_wp_hooks']['filters'][$filter]);
            $GLOBALS['intersoccer_test_emit_wp_hooks'] = false;
        }
    }

    public function test_failed_and_pending_orders_list_order_number_date_and_link_only(): void {
        $this->assertSame(['wc-failed', 'wc-pending'], DailyBfSalesEmail::ATTENTION_ORDER_STATUSES);
        $this->assertSame(['wc-failed', 'wc-pending'], DailyBfSalesEmail::attentionOrderStatuses());

        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->wpdb->orders = [
            [
                'id' => 711,
                'status' => 'wc-failed',
                'post_date' => '2026-11-28 10:30:00',
                'date_paid' => '',
                'items' => [
                    $this->item(7111, 'Failed Camp', 711, 0, 1, '10.00', '10.00', '0.77'),
                    $this->item(7112, 'Failed Camp Extra', 711, 0, 1, '5.00', '5.00', '0.39'),
                ],
            ],
            [
                'id' => 716,
                'status' => 'wc-failed',
                'post_date' => '2026-11-29 09:15:00',
                'date_paid' => '',
                'items' => [
                    $this->item(7161, 'Older Failed Camp', 716, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ],
            [
                'id' => 712,
                'status' => 'wc-pending',
                'post_date' => '2026-11-30 16:05:00',
                'date_paid' => '',
                'items' => [
                    $this->item(7121, 'Pending Course', 712, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ],
            [
                'id' => 713,
                'status' => 'wc-cancelled',
                'post_date' => '2026-11-29 11:00:00',
                'date_paid' => '',
                'items' => [
                    $this->item(7131, 'Cancelled Camp', 713, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ],
            [
                'id' => 714,
                'status' => 'wc-on-hold',
                'post_date' => '2026-11-30 12:00:00',
                'date_paid' => '',
                'items' => [
                    $this->item(7141, 'Held Created Camp', 714, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ],
            [
                'id' => 715,
                'status' => 'wc-failed',
                'post_date' => '2026-11-27 23:00:00',
                'date_paid' => '',
                'items' => [
                    $this->item(7151, 'Earlier Failed Camp', 715, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ],
            [
                'id' => 717,
                'status' => 'wc-pending',
                'post_date' => '2026-12-01 08:00:00',
                'date_paid' => '',
                'items' => [
                    $this->item(7171, 'Later Pending Course', 717, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ],
        ];
        $this->wpdb->itemMeta = [
            7111 => [
                '_intersoccer_canonical_activity_type' => 'camp',
                'Attendee Name' => 'Secret Child',
                'Parent Phone' => '079 555 12 12',
                'Emergency Phone' => '+41 79 123 45 67',
            ],
            7112 => [
                'Attendee Name' => 'Secret Child',
            ],
            7121 => [
                '_intersoccer_canonical_activity_type' => 'course',
                'Attendee Name' => 'Pending Child',
            ],
        ];
        \InterSoccerRRTestMeta::$data[711]['_billing_email'] = 'booker@example.com';
        \InterSoccerRRTestMeta::$data[711]['_billing_phone'] = '+41 79 123 45 67';
        \InterSoccerRRTestMeta::$data[711]['_billing_first_name'] = 'Secret Booker';
        \InterSoccerRRTestMeta::$data[712]['_billing_email'] = 'pending@example.test';

        $digest = $service->buildDigest($this->zurich('2026-12-01 08:00:00'));
        $numbers = [];
        foreach ($digest['attention_orders'] as $order) {
            $this->assertSame(['order_number', 'created_at', 'admin_url'], array_keys($order));
            $numbers[] = $order['order_number'];
        }
        $this->assertSame(['712', '716', '711'], $numbers);
        $this->assertSame('2026-11-30 16:05', $digest['attention_orders'][0]['created_at']);
        $this->assertSame('2026-11-29 09:15', $digest['attention_orders'][1]['created_at']);
        $this->assertSame('2026-11-28 10:30', $digest['attention_orders'][2]['created_at']);
        $this->assertStringContainsString('post.php?post=712&action=edit', $digest['attention_orders'][0]['admin_url']);
        $this->assertStringNotContainsString('wc-orders&action=edit', $digest['attention_orders'][0]['admin_url']);

        $html = $service->renderHtml($digest);
        $this->assertStringContainsString('<h3>Failed and pending orders</h3>', $html);
        $this->assertStringContainsString('>711<', $html);
        $this->assertStringContainsString('2026-11-28 10:30', $html);
        $this->assertStringContainsString('post.php?post=711&action=edit', $html);
        $this->assertStringContainsString('>716<', $html);
        $this->assertStringContainsString('2026-11-29 09:15', $html);
        $this->assertStringContainsString('>712<', $html);
        $this->assertStringContainsString('2026-11-30 16:05', $html);
        $this->assertTrue(strpos($html, '>712<') < strpos($html, '>716<'));
        $this->assertTrue(strpos($html, '>716<') < strpos($html, '>711<'));
        $this->assertStringNotContainsString('>713<', $html);
        $this->assertStringNotContainsString('>714<', $html);
        $this->assertStringNotContainsString('>715<', $html);
        $this->assertStringNotContainsString('>717<', $html);
        $this->assertStringNotContainsString('Cancelled Camp', $html);
        $this->assertStringNotContainsString('Held Created Camp', $html);
        $this->assertStringNotContainsString('Earlier Failed Camp', $html);
        $this->assertStringNotContainsString('Secret Child', $html);
        $this->assertStringNotContainsString('Secret Booker', $html);
        $this->assertStringNotContainsString('Pending Child', $html);
        $this->assertStringNotContainsString('booker@example.com', $html);
        $this->assertStringNotContainsString('pending@example.test', $html);
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringNotContainsString('+41 79 123 45 67', $html);
        $this->assertStringNotContainsString('079 555 12 12', $html);
        $this->assertSame(0, preg_match('/\+\d[\d\s().-]{6,}/', $html));
        $this->assertSame(0, preg_match('/\b0\d{2}[\s.-]?\d{3}[\s.-]?\d{2}[\s.-]?\d{2}\b/', $html));
        $this->assertSame(1, substr_count($html, 'post.php?post=711&action=edit'));

        $attentionSql = '';
        foreach ($this->wpdb->queries as $query) {
            if (strpos($query, "'wc-failed'") !== false) {
                $attentionSql = $query;
                break;
            }
        }
        $this->assertNotSame('', $attentionSql);
        $this->assertStringContainsString("'wc-pending'", $attentionSql);
        $this->assertStringContainsString('p.post_date', $attentionSql);
        $this->assertStringContainsString("p.post_date >= '2026-11-28 00:00:00'", $attentionSql);
        $this->assertStringContainsString("p.post_date < '2026-12-01 00:00:00'", $attentionSql);
        $this->assertStringNotContainsString('_date_paid', $attentionSql);
        $this->assertStringNotContainsString("'wc-cancelled'", $attentionSql);
        $this->assertStringNotContainsString("'wc-on-hold'", $attentionSql);

        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        $filter = DailyBfSalesEmail::ATTENTION_ORDER_STATUSES_FILTER;
        add_filter($filter, static function () {
            return ['wc-failed'];
        });
        try {
            $narrow = $service->buildDigest($this->zurich('2026-12-01 08:00:00'));
            $narrowNumbers = [];
            foreach ($narrow['attention_orders'] as $order) {
                $narrowNumbers[] = $order['order_number'];
            }
            $this->assertSame(['716', '711'], $narrowNumbers);
        } finally {
            unset($GLOBALS['intersoccer_test_wp_hooks']['filters'][$filter]);
            $GLOBALS['intersoccer_test_emit_wp_hooks'] = false;
        }
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

    public function test_sample_send_does_not_read_shop_data(): void {
        $this->installDisabledSampleSettings();
        DailyBfSalesEmail::register();
        DailyBfSalesEmail::ensureScheduled();
        $this->assertSame([], $this->salesCronEvents());

        $service = $this->wooService();
        $this->wpdb->orders[] = [
            'id' => 900,
            'status' => 'wc-completed',
            'post_date' => '2026-11-10 10:00:00',
            'date_paid' => '1',
            'items' => [
                $this->item(9001, 'Should Not Load', 1, 1, 1, '1.00', '1.00', '0.00'),
            ],
        ];
        $beforeState = $service->state();
        $beforeOptions = $GLOBALS['intersoccer_test_option_store'];
        $beforeQueries = $this->wpdb->queries;

        $this->beginShopReadCounting();
        $result = $service->handleAdminPost([
            'bf_sample_send' => '1',
            '_wpnonce' => 'test',
            'bf_test_address' => 'ops@example.com',
        ]);
        $api = (isset($GLOBALS['intersoccer_rr_test_api_call_counts']) && is_array($GLOBALS['intersoccer_rr_test_api_call_counts']))
            ? $GLOBALS['intersoccer_rr_test_api_call_counts']
            : [];
        $shop = (isset($GLOBALS['intersoccer_rr_bf_shop_calls']) && is_array($GLOBALS['intersoccer_rr_bf_shop_calls']))
            ? $GLOBALS['intersoccer_rr_bf_shop_calls']
            : [];
        $dbCalls = (int) ($GLOBALS['intersoccer_rr_bf_db_calls'] ?? 0);
        $couponCalls = (int) ($GLOBALS['intersoccer_rr_bf_wc_coupon_calls'] ?? 0);
        $couponLookups = (int) ($GLOBALS['intersoccer_rr_bf_coupon_lookup_calls'] ?? 0);
        $this->endShopReadCounting();

        $this->assertTrue($result['ok']);
        $this->assertSame('sample_sent', $result['code']);
        $this->assertSame(0, (int) ($api['wc_get_orders'] ?? 0));
        $this->assertSame(0, (int) ($api['wc_get_order'] ?? 0));
        $this->assertSame(0, (int) ($api['wc_get_product'] ?? 0));
        $this->assertSame(0, (int) ($api['get_post_meta'] ?? 0));
        $this->assertSame(0, (int) ($shop['get_posts'] ?? 0));
        $this->assertSame(0, (int) ($shop['get_option'] ?? 0));
        $this->assertSame(0, $couponCalls);
        $this->assertSame(0, $couponLookups);
        $this->assertSame(0, $dbCalls);
        $this->assertSame($beforeQueries, $this->wpdb->queries);
        $this->assertSame($beforeOptions, $GLOBALS['intersoccer_test_option_store']);
        $this->assertSame($beforeState, $service->state());
        $this->assertSame([], $this->salesCronEvents());
        $this->assertFalse(wp_next_scheduled(DailyBfSalesEmail::CRON_HOOK));

        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $this->assertSame('ops@example.com', $mail['to']);
        $this->assertStringNotContainsString('list@example.test', (string) json_encode($mail['to']));
        $this->assertStringNotContainsString('Should Not Load', $mail['message']);
        $this->assertStringNotContainsString('page=intersoccer-reports', $mail['message']);
        $this->assertStringStartsWith('[SAMPLE] ', $mail['subject']);
    }

    public function test_sample_body_is_the_shared_renderer_plus_banner(): void {
        $service = $this->wooService();
        $result = $service->sendSample('ops@example.com');
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $digest = $this->invokeSampleDigest($service);
        $rendered = $service->renderHtml($digest);

        $this->assertStringNotContainsString(DailyBfSalesEmail::SAMPLE_BANNER, $rendered);
        $this->assertSame('<p>' . DailyBfSalesEmail::SAMPLE_BANNER . '</p>' . $rendered, $mail['message']);
        $this->assertStringContainsString('Sample data, not real sales.', $mail['message']);
        $this->assertSame('[SAMPLE] Final Daily sales report 2026-11-10', $mail['subject']);
        $this->assertStringStartsWith('[SAMPLE] ', $mail['subject']);
        $this->assertStringContainsString('Yesterday (2026-11-10, Europe/Zurich)', $mail['message']);
        $this->assertStringContainsString('<h3>Season and type</h3>', $mail['message']);
        $this->assertStringContainsString('Easter Camps', $mail['message']);
        $this->assertStringContainsString('Winter Camps', $mail['message']);
        $this->assertStringContainsString('Spring Courses', $mail['message']);
        $this->assertStringContainsString('Summer Tournaments', $mail['message']);
        foreach ([
            'Sample Camp A',
            'Sample Camp B',
            'Sample Camp C',
            'Sample Camp D',
            'Sample Course A',
            'Sample Course B',
            'Sample Course C',
            'Sample Venue B',
            'Sample Birthday A',
            'Sample Tournament A',
        ] as $label) {
            $this->assertStringContainsString($label, $mail['message'], $label);
        }
        $this->assertCount(10, $digest['daily']['products']);
        $this->assertStringContainsString('Sample Region A', $mail['message']);
        $this->assertStringContainsString('Sample Region B', $mail['message']);
        $this->assertStringContainsString('Sample Region C', $mail['message']);
        $this->assertStringContainsString('>900101<', $mail['message']);
        $this->assertStringContainsString('>900102<', $mail['message']);
        $this->assertStringContainsString('>900103<', $mail['message']);
        $this->assertStringContainsString('https://example.com/sample-order/900101', $mail['message']);
        $this->assertStringContainsString('https://example.com/sample-order/900102', $mail['message']);
        $this->assertStringContainsString('https://example.test/sample-order/900103', $mail['message']);
        $this->assertStringContainsString('https://example.com/sample-booking-report/900001', $mail['message']);
        $this->assertStringContainsString('Sample Camp E', $mail['message']);
        $this->assertStringContainsString('Sample Course D', $mail['message']);
        $this->assertStringContainsString('Sample Tournament B', $mail['message']);
        $this->assertStringContainsString('>Sample Camp F<', $mail['message']);
        $this->assertStringContainsString('>0<', $mail['message']);
        $this->assertStringContainsString('On hold yesterday: 2 orders, 45.00 CHF inc-VAT.', $mail['message']);
        $this->assertStringContainsString('On hold in the campaign: 3 orders, 90.50 CHF inc-VAT.', $mail['message']);
        $this->assertStringContainsString('Orders: 4', $mail['message']);
        $this->assertStringContainsString('Orders: 9', $mail['message']);
        $this->assertStringContainsString('<h2>Running total since campaign start</h2>', $mail['message']);
        $this->assertStringContainsString('2026-11-01 through 2026-11-10', $mail['message']);
        $this->assertStringContainsString(DailyBfSalesEmail::FINAL_CAMPAIGN_LINE, $mail['message']);
        $this->assertStringContainsString('<h3>Failed and pending orders</h3>', $mail['message']);
        $this->assertStringContainsString('<h3>Low bookings</h3>', $mail['message']);
        $this->assertStringContainsString('<h3>Zero bookings</h3>', $mail['message']);
        $this->assertStringContainsString('<h3>Products</h3>', $mail['message']);
        $this->assertStringContainsString('<h3>Regions</h3>', $mail['message']);
        $this->assertTrue($digest['campaign_available']);
        $this->assertTrue($digest['campaign_started']);
        $this->assertTrue($digest['final_campaign']);
    }

    public function test_test_send_keeps_the_test_prefix_and_omits_the_sample_banner(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'list@example.test');
        $this->seedCatalogue();
        $result = $service->sendTest('ops@example.com', $this->zurich('2026-11-29 08:00:00'));
        $this->assertTrue($result['ok']);
        $this->assertSame('test_sent', $result['code']);
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $this->assertStringStartsWith('[TEST] ', $mail['subject']);
        $this->assertStringContainsString('Daily sales report 2026-11-28', $mail['subject']);
        $this->assertStringNotContainsString('[SAMPLE]', $mail['subject']);
        $this->assertStringNotContainsString(DailyBfSalesEmail::SAMPLE_BANNER, $mail['message']);
        $this->assertSame('ops@example.com', $mail['to']);
    }

    /**
     * @dataProvider invalidTestAddressProvider
     */
    public function test_sample_send_rejects_empty_invalid_and_multiple_addresses(string $address): void {
        $service = $this->wooService();
        $before = $service->state();
        $result = $service->handleAdminPost([
            'bf_sample_send' => '1',
            '_wpnonce' => 'test',
            'bf_test_address' => $address,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('test_invalid', $result['code']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $this->assertSame($before, $service->state());
    }

    public function test_sample_send_goes_only_to_the_typed_address_when_enable_is_off(): void {
        $this->installDisabledSampleSettings();
        DailyBfSalesEmail::register();
        DailyBfSalesEmail::ensureScheduled();
        $service = $this->wooService();
        $before = $service->state();

        $result = $service->sendSample('  ops@example.com  ');

        $this->assertTrue($result['ok']);
        $this->assertSame('sample_sent', $result['code']);
        $this->assertSame('Sample email sent.', $result['message']);
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $mail = $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0];
        $this->assertSame('ops@example.com', $mail['to']);
        $this->assertStringNotContainsString('list@example.test', (string) json_encode($mail['to']));
        $this->assertStringNotContainsString('list@example.test', $mail['message']);
        $this->assertStringStartsWith('[SAMPLE] ', $mail['subject']);
        $this->assertSame($before, $service->state());
        $this->assertSame($before['sent_dates'], $service->state()['sent_dates']);
        $this->assertSame($before['last_sent_at'], $service->state()['last_sent_at']);
        $this->assertSame($before['last_result'], $service->state()['last_result']);
        $this->assertSame([], $this->salesCronEvents());
        $this->assertFalse(wp_next_scheduled(DailyBfSalesEmail::CRON_HOOK));
    }

    public function test_sample_send_mailer_failure_does_not_mark_the_day(): void {
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        $GLOBALS['intersoccer_rr_bf_wp_mail_fail'] = true;
        $GLOBALS['intersoccer_rr_bf_wp_mail_error'] = 'SMTP rejected the message for ops@example.com';
        $this->installDisabledSampleSettings();
        $service = $this->wooService();
        $before = $service->state();

        $result = $service->sendSample('ops@example.com');

        $this->assertFalse($result['ok']);
        $this->assertSame('sample_failed', $result['code']);
        $this->assertStringContainsString('SMTP rejected the message for ops@example.com', $result['message']);
        $this->assertSame($before, $service->state());
    }

    public function test_sample_send_requires_manage_options(): void {
        $GLOBALS['intersoccer_test_current_user_caps'] = ['manage_options' => false];
        $service = $this->wooService();
        $result = $service->handleAdminPost([
            'bf_sample_send' => '1',
            '_wpnonce' => 'test',
            'bf_test_address' => 'ops@example.com',
        ]);
        $this->assertSame('forbidden', $result['code']);
        $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
    }

    public function test_admin_page_offers_sample_send_beside_the_test_send(): void {
        $service = $this->makeService($this->novemberCoupon());
        ob_start();
        $service->renderPage();
        $html = (string) ob_get_clean();

        $addressAt = strpos($html, 'name="bf_test_address"');
        $this->assertNotFalse($addressAt);
        $form = substr($html, (int) $addressAt);
        $formEnd = strpos($form, '</form>');
        $this->assertNotFalse($formEnd);
        $form = substr($form, 0, (int) $formEnd);
        $this->assertStringContainsString('name="bf_test_send"', $form);
        $this->assertStringContainsString('name="bf_sample_send"', $form);
        $this->assertStringContainsString('Send sample email', $form);
        $this->assertStringContainsString('Send test email', $form);
        $this->assertStringContainsString('[SAMPLE]', $html);
        $this->assertStringContainsString('sample (fake) data', $html);
        $this->assertStringContainsString('does not use real sales', $html);
        $this->assertStringContainsString('does not use the Send to list', $html);
        $this->assertStringContainsString('does not require Enable', $html);
        $this->assertStringContainsString('does not mark the day as sent', $html);

        $_GET['bf_notice'] = 'sample_sent';
        $_GET['bf_detail'] = 'Sample email sent.';
        ob_start();
        $service->renderPage();
        $notice = (string) ob_get_clean();
        unset($_GET['bf_notice'], $_GET['bf_detail']);
        $this->assertStringContainsString('notice notice-success', $notice);
        $this->assertStringNotContainsString('notice-error', $notice);
        $this->assertStringContainsString('Sample email sent.', $notice);
    }

    public function test_settings_default_to_disabled_and_an_empty_recipient_list(): void {
        $service = $this->makeService($this->novemberCoupon());
        unset($GLOBALS['intersoccer_test_option_store'][DailyBfSalesEmail::OPTION_SETTINGS]);
        $settings = $service->settings();
        $this->assertFalse($settings['enabled']);
        $this->assertSame('', $settings['recipients']);
        $this->assertSame('08:00', $settings['send_time']);
        $this->assertSame('', $settings['coupon_code']);
        $this->assertSame('', $settings['campaign_start']);
        $this->assertSame(3, $settings['low_booking_threshold']);
        $this->assertSame(3, DailyBfSalesEmail::DEFAULT_LOW_BOOKING_THRESHOLD);
    }

    public function test_low_and_zero_bookings_use_the_threshold_and_the_2027_scope(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        ob_start();
        $service->renderPage();
        $page = (string) ob_get_clean();
        $this->assertStringContainsString('name="bf_low_booking_threshold"', $page);
        $this->assertStringContainsString('value="3"', $page);
        $this->assertSame(3, $service->settings()['low_booking_threshold']);

        $products = [8301, 8302, 8303, 8304, 8305, 8306, 8307, 8308, 8401, 8402];
        $GLOBALS['intersoccer_test_get_posts_callback'] = static function ($args) use ($products) {
            $type = isset($args['post_type']) ? (string) $args['post_type'] : '';
            if ($type === 'product_variation') {
                $parent = isset($args['post_parent']) ? (int) $args['post_parent'] : 0;
                return $parent === 8306 ? [83061] : [];
            }
            if ($type === 'product') {
                return $products;
            }
            return [];
        };
        $GLOBALS['intersoccer_test_post_titles'] = [
            8301 => 'Busy Winter Camp',
            8302 => 'Quiet Winter Camp',
            8303 => 'Empty Winter Camp',
            8304 => 'Summer Future Camp',
            8305 => 'Year 2026 Camp',
            8306 => 'Past Winter Camp',
            8307 => 'Lakeside Spring Camp',
            8308 => 'City Spring Course',
            8399 => 'Draft Winter Camp',
            8401 => 'Shared Winter Camp',
            8402 => 'Camp Hiver Traduction',
        ];
        $meta = [
            8301 => [
                '_intersoccer_product_type' => 'camp',
                'attribute_pa_program-season' => 'Winter',
                'attribute_pa_program-year' => '2027',
                '_camp_start_date' => '2027-01-04',
                'attribute_pa_canton-region' => 'Geneva',
            ],
            8302 => [
                '_intersoccer_product_type' => 'camp',
                'pa_program-season' => 'Winter',
                'pa_program-year' => '2027',
                '_camp_start_date' => '2027-01-11',
                'pa_canton-region' => 'Zurich',
            ],
            8303 => [
                '_intersoccer_product_type' => 'camp',
                'Season' => 'Winter',
                'Program Year' => '2027',
                '_camp_start_date' => '2026-11-29',
                'Canton / Region' => 'Vaud',
            ],
            8304 => [
                '_intersoccer_product_type' => 'camp',
                'Jahreszeit' => 'Sommer',
                'Jahr' => '2027',
                '_camp_start_date' => '2027-07-01',
            ],
            8305 => [
                '_intersoccer_product_type' => 'camp',
                'Saison' => 'Hiver',
                'Année' => '2026',
                '_camp_start_date' => '2027-12-01',
            ],
            8306 => [
                '_intersoccer_product_type' => 'camp',
                'attribute_pa_program-season' => 'Winter',
                'attribute_pa_program-year' => '2027',
            ],
            83061 => [
                'attribute_pa_program-year' => '2027',
                '_camp_start_date' => '2026-01-04',
            ],
            8307 => [
                '_intersoccer_product_type' => 'camp',
                'Saison' => 'Printemps',
                'Semaine de camp' => 'Pâques',
                'attribute_pa_program-year' => '2027',
                '_camp_start_date' => '2027-04-06',
                'Canton / Région' => 'Bâle',
            ],
            8308 => [
                '_intersoccer_product_type' => 'course',
                'Jahreszeit' => 'Frühling',
                'pa_program-year' => '2027',
                '_camp_start_date' => '2027-03-02',
            ],
            8399 => [
                '_intersoccer_product_type' => 'camp',
                'attribute_pa_program-season' => 'Winter',
                'attribute_pa_program-year' => '2027',
                '_camp_start_date' => '2027-02-01',
            ],
            8401 => [
                '_intersoccer_product_type' => 'camp',
                'attribute_pa_program-season' => 'Winter',
                'attribute_pa_program-year' => '2027',
                '_camp_start_date' => '2027-02-02',
                'attribute_pa_canton-region' => 'Zug',
            ],
            8402 => [
                '_intersoccer_product_type' => 'camp',
                'Saison' => 'Hiver',
                'attribute_pa_program-year' => '2027',
                '_camp_start_date' => '2027-02-02',
            ],
        ];
        foreach ($meta as $postId => $pairs) {
            foreach ($pairs as $key => $value) {
                \InterSoccerRRTestMeta::$data[$postId][$key] = $value;
            }
        }

        $paid = (string) $this->unix('2026-11-28 10:00:00');
        $this->wpdb->orders = [
            [
                'id' => 901,
                'status' => 'wc-completed',
                'post_date' => '2026-11-28 10:00:00',
                'date_paid' => $paid,
                'items' => [
                    $this->item(9011, 'Busy Winter Camp', 8301, 0, 4, '40.00', '40.00', '3.08'),
                ],
            ],
            [
                'id' => 902,
                'status' => 'wc-processing',
                'post_date' => '2026-11-28 11:00:00',
                'date_paid' => $paid,
                'items' => [
                    $this->item(9021, 'Quiet Winter Camp', 8302, 0, 3, '30.00', '30.00', '2.31'),
                ],
            ],
            [
                'id' => 903,
                'status' => 'wc-on-hold',
                'post_date' => '2026-11-28 12:00:00',
                'date_paid' => $paid,
                'items' => [
                    $this->item(9031, 'Held Empty Camp', 8303, 0, 2, '20.00', '20.00', '1.54'),
                ],
            ],
            [
                'id' => 904,
                'status' => 'wc-completed',
                'post_date' => '2026-11-28 13:00:00',
                'date_paid' => '',
                'items' => [
                    $this->item(9041, 'Unpaid Empty Camp', 8303, 0, 5, '50.00', '50.00', '3.85'),
                ],
            ],
            [
                'id' => 905,
                'status' => 'wc-completed',
                'post_date' => '2026-11-28 14:00:00',
                'date_paid' => $paid,
                'items' => [
                    $this->item(9051, 'Shared Winter Camp', 8401, 0, 2, '20.00', '20.00', '1.54'),
                ],
            ],
            [
                'id' => 906,
                'status' => 'wc-completed',
                'post_date' => '2026-11-28 15:00:00',
                'date_paid' => $paid,
                'items' => [
                    $this->item(9061, 'Shared Winter Camp', 8402, 0, 2, '20.00', '20.00', '1.54'),
                ],
            ],
        ];
        $this->wpdb->itemMeta = [
            9011 => [
                'Attendee Name' => 'Hidden Player',
                'Parent Phone' => '555 010 0199',
            ],
            9021 => ['_intersoccer_canonical_activity_type' => 'camp'],
            9031 => ['Emergency Phone' => '+1 555 010 2000'],
            9041 => ['Attendee Name' => 'Hidden Player'],
            9051 => ['_intersoccer_canonical_activity_type' => 'camp'],
            9061 => ['_intersoccer_canonical_activity_type' => 'camp'],
        ];
        \InterSoccerRRTestMeta::$data[901]['_billing_email'] = 'booker@example.com';
        \InterSoccerRRTestMeta::$data[901]['_billing_phone'] = '555 010 0199';
        \InterSoccerRRTestMeta::$data[901]['_billing_first_name'] = 'Hidden Booker';
        \InterSoccerRRTestMeta::$data[904]['_billing_email'] = 'player@example.test';

        $hook = 'wpml_object_id';
        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        add_filter($hook, static function ($id) {
            return (int) $id === 8402 ? 8401 : $id;
        });
        try {
            $now = $this->zurich('2026-11-29 08:00:00');
            $digest = $service->buildDigest($now);
            $this->assertSame(['Quiet Winter Camp'], $this->bookingLabels($digest['low_bookings']));
            $quiet = $digest['low_bookings'][0];
            $this->assertSame(['label', 'season', 'type', 'region', 'count'], array_keys($quiet));
            $this->assertSame('Winter', $quiet['season']);
            $this->assertSame('Camp', $quiet['type']);
            $this->assertSame('Zurich', $quiet['region']);
            $this->assertSame(3, $quiet['count']);
            $this->assertSame(
                ['City Spring Course', 'Empty Winter Camp', 'Lakeside Spring Camp'],
                $this->bookingLabels($digest['zero_bookings'])
            );
            $zero = [];
            foreach ($digest['zero_bookings'] as $row) {
                $this->assertSame(['label', 'season', 'type', 'region', 'count'], array_keys($row));
                $this->assertSame(0, $row['count']);
                $zero[$row['label']] = $row;
            }
            $this->assertSame('Winter', $zero['Empty Winter Camp']['season']);
            $this->assertSame('Vaud', $zero['Empty Winter Camp']['region']);
            $this->assertSame('Easter', $zero['Lakeside Spring Camp']['season']);
            $this->assertSame('Camp', $zero['Lakeside Spring Camp']['type']);
            $this->assertSame('Basel-Stadt', $zero['Lakeside Spring Camp']['region']);
            $this->assertSame('Spring', $zero['City Spring Course']['season']);
            $this->assertSame('Course', $zero['City Spring Course']['type']);

            $html = $service->renderHtml($digest);
            $lowHtml = $this->htmlSection($html, '<h3>Low bookings</h3>', '<h3>Zero bookings</h3>');
            $zeroHtml = $this->htmlSection($html, '<h3>Zero bookings</h3>', '<h2>Running total since campaign start</h2>');
            $this->assertStringContainsString('Quiet Winter Camp', $lowHtml);
            $this->assertStringContainsString('<th>Event</th><th>Season</th><th>Type</th><th>Region</th><th>Count</th>', $lowHtml);
            $this->assertStringNotContainsString('Busy Winter Camp', $lowHtml);
            $this->assertStringNotContainsString('Shared Winter Camp', $lowHtml);
            $this->assertStringContainsString('Empty Winter Camp', $zeroHtml);
            $this->assertStringContainsString('Lakeside Spring Camp', $zeroHtml);
            $this->assertStringContainsString('>Easter<', $zeroHtml);
            $this->assertStringContainsString('City Spring Course', $zeroHtml);
            foreach (['Summer Future Camp', 'Year 2026 Camp', 'Past Winter Camp', 'Draft Winter Camp', 'Camp Hiver Traduction', 'Held Empty Camp', 'Unpaid Empty Camp'] as $absent) {
                $this->assertStringNotContainsString($absent, $lowHtml . $zeroHtml);
            }
            foreach (['Hidden Booker', 'Hidden Player', 'booker@example.com', 'player@example.test', 'mailto:', '555 010 0199', '+1 555 010 2000'] as $private) {
                $this->assertStringNotContainsString($private, $html);
            }
            $this->assertDoesNotMatchRegularExpression('/\b\d{3}[-. ]\d{3}[-. ]\d{4}\b/', $html);

            $saved = $service->handleAdminPost([
                'bf_save' => '1',
                '_wpnonce' => 'test',
                'bf_recipients' => 'ops@example.com',
                'bf_send_time' => '08:00',
                'bf_coupon_code' => 'BF',
                'bf_campaign_start' => '2026-11-28',
                'bf_low_booking_threshold' => '4',
                'bf_enabled' => '1',
            ]);
            $this->assertTrue($saved['ok']);
            $this->assertSame(4, $service->settings()['low_booking_threshold']);
            $raised = $service->buildDigest($now);
            $this->assertSame(
                ['Busy Winter Camp', 'Quiet Winter Camp', 'Shared Winter Camp'],
                $this->bookingLabels($raised['low_bookings'])
            );
            $byLabel = [];
            foreach ($raised['low_bookings'] as $row) {
                $byLabel[$row['label']] = $row;
            }
            $this->assertSame(4, $byLabel['Busy Winter Camp']['count']);
            $this->assertSame('Geneva', $byLabel['Busy Winter Camp']['region']);
            $this->assertSame(4, $byLabel['Shared Winter Camp']['count']);
            $this->assertSame('Zug', $byLabel['Shared Winter Camp']['region']);
            $this->assertSame(1, count(array_filter($raised['low_bookings'], static function ($row) {
                return $row['label'] === 'Shared Winter Camp';
            })));
            $this->assertSame(
                ['City Spring Course', 'Empty Winter Camp', 'Lakeside Spring Camp'],
                $this->bookingLabels($raised['zero_bookings'])
            );

            $reset = $service->handleAdminPost([
                'bf_save' => '1',
                '_wpnonce' => 'test',
                'bf_recipients' => 'ops@example.com',
                'bf_send_time' => '08:00',
                'bf_coupon_code' => 'BF',
                'bf_campaign_start' => '2026-11-28',
                'bf_low_booking_threshold' => 'nope',
                'bf_enabled' => '1',
            ]);
            $this->assertTrue($reset['ok']);
            $this->assertSame(3, $service->settings()['low_booking_threshold']);
        } finally {
            unset($GLOBALS['intersoccer_test_wp_hooks']['filters'][$hook]);
            $GLOBALS['intersoccer_test_emit_wp_hooks'] = false;
            unset($GLOBALS['intersoccer_test_get_posts_callback'], $GLOBALS['intersoccer_test_post_titles']);
        }
    }

    public function test_bookings_before_the_campaign_window_are_not_flagged_low_or_zero(): void {
        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $products = [8501, 8502];
        $GLOBALS['intersoccer_test_get_posts_callback'] = static function ($args) use ($products) {
            $type = isset($args['post_type']) ? (string) $args['post_type'] : '';
            if ($type === 'product') {
                return $products;
            }
            return [];
        };
        $GLOBALS['intersoccer_test_post_titles'] = [
            8501 => 'Early Winter Camp',
            8502 => 'Quiet Early Camp',
        ];
        $meta = [
            8501 => [
                '_intersoccer_product_type' => 'camp',
                'attribute_pa_program-season' => 'Winter',
                'attribute_pa_program-year' => '2027',
                '_camp_start_date' => '2027-01-04',
                'attribute_pa_canton-region' => 'Geneva',
            ],
            8502 => [
                '_intersoccer_product_type' => 'camp',
                'attribute_pa_program-season' => 'Winter',
                'attribute_pa_program-year' => '2027',
                '_camp_start_date' => '2027-01-11',
                'attribute_pa_canton-region' => 'Zurich',
            ],
        ];
        foreach ($meta as $postId => $pairs) {
            foreach ($pairs as $key => $value) {
                \InterSoccerRRTestMeta::$data[$postId][$key] = $value;
            }
        }
        $this->wpdb->orders = [
            [
                'id' => 951,
                'status' => 'wc-completed',
                'post_date' => '2026-10-02 10:00:00',
                'date_paid' => (string) $this->unix('2026-10-02 10:00:00'),
                'items' => [
                    $this->item(9511, 'Early Winter Camp', 8501, 0, 4, '40.00', '40.00', '3.08'),
                ],
            ],
            [
                'id' => 952,
                'status' => 'wc-processing',
                'post_date' => '2026-10-03 11:00:00',
                'date_paid' => (string) $this->unix('2026-10-03 11:00:00'),
                'items' => [
                    $this->item(9521, 'Quiet Early Camp', 8502, 0, 1, '10.00', '10.00', '0.77'),
                ],
            ],
            [
                'id' => 953,
                'status' => 'wc-on-hold',
                'post_date' => '2026-10-04 12:00:00',
                'date_paid' => (string) $this->unix('2026-10-04 12:00:00'),
                'items' => [
                    $this->item(9531, 'Held Early Camp', 8502, 0, 9, '90.00', '90.00', '6.93'),
                ],
            ],
        ];
        try {
            $digest = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
            $low = $this->bookingLabels($digest['low_bookings']);
            $zero = $this->bookingLabels($digest['zero_bookings']);
            $this->assertNotContains('Early Winter Camp', $low);
            $this->assertNotContains('Early Winter Camp', $zero);
            $this->assertSame(['Quiet Early Camp'], $low);
            $this->assertSame(1, $digest['low_bookings'][0]['count']);
            $this->assertSame(['label', 'season', 'type', 'region', 'count'], array_keys($digest['low_bookings'][0]));
            $this->assertSame([], $zero);

            $sql = '';
            foreach ($this->wpdb->queries as $query) {
                if (strpos($query, "paid_meta.meta_value <> '0'") !== false
                    && strpos($query, 'CAST(paid_meta.meta_value AS UNSIGNED)') === false
                ) {
                    $sql = $query;
                }
            }
            $this->assertNotSame('', $sql);
            $this->assertStringContainsString("'wc-processing'", $sql);
            $this->assertStringContainsString("'wc-completed'", $sql);
            $this->assertStringNotContainsString("'wc-on-hold'", $sql);
        } finally {
            unset($GLOBALS['intersoccer_test_get_posts_callback'], $GLOBALS['intersoccer_test_post_titles']);
        }
    }

    public function test_order_admin_link_uses_post_edit_when_hpos_is_off(): void {
        $this->loadHposClassesWithUsageOff();
        $this->assertTrue(class_exists('Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore', false));
        $this->assertFalse(\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled());

        $service = $this->enabledService($this->novemberCoupon(), 'ops@example.com');
        $this->wpdb->orders = [[
            'id' => 880,
            'status' => 'wc-failed',
            'post_date' => '2026-11-28 10:00:00',
            'date_paid' => '',
            'items' => [
                $this->item(8801, 'Failed Link Camp', 880, 0, 1, '10.00', '10.00', '0.77'),
            ],
        ]];
        $digest = $service->buildDigest($this->zurich('2026-11-29 08:00:00'));
        $this->assertCount(1, $digest['attention_orders']);
        $url = (string) $digest['attention_orders'][0]['admin_url'];
        $this->assertStringContainsString('post.php?post=880&action=edit', $url);
        $this->assertStringNotContainsString('page=wc-orders', $url);
        $html = $service->renderHtml($digest);
        $this->assertStringContainsString('post.php?post=880&action=edit', $html);
        $this->assertStringNotContainsString('page=wc-orders', $html);
    }

    private function loadHposClassesWithUsageOff(): void {
        $GLOBALS['intersoccer_rr_bf_hpos_enabled'] = false;
        if (!class_exists('Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore', false)) {
            eval('namespace Automattic\WooCommerce\Internal\DataStores\Orders; class OrdersTableDataStore {}');
        }
        if (!class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil', false)) {
            eval('namespace Automattic\WooCommerce\Utilities; class OrderUtil { public static function custom_orders_table_usage_is_enabled() { return !empty($GLOBALS["intersoccer_rr_bf_hpos_enabled"]); } }');
        }
    }

    private function bookingLabels(array $rows): array {
        $labels = [];
        foreach ($rows as $row) {
            $labels[] = (string) ($row['label'] ?? '');
        }
        sort($labels);
        return $labels;
    }

    private function htmlSection(string $html, string $start, string $end): string {
        $from = strpos($html, $start);
        $to = strpos($html, $end);
        $this->assertNotFalse($from, $start);
        $this->assertNotFalse($to, $end);
        $this->assertGreaterThan($from, $to);
        return substr($html, $from, $to - $from);
    }

    private function assertExpiryMorning(array $coupon, string $nowLocal, string $expected, bool $final, string $reportDate): void {
        $GLOBALS['intersoccer_rr_bf_wp_mail_log'] = [];
        $GLOBALS['intersoccer_test_option_store'] = [];
        $service = $this->enabledService($coupon, 'ops@example.com');
        $reason = $service->runScheduled($this->zurich($nowLocal));
        $this->assertSame($expected, $reason, $nowLocal);
        if ($expected !== 'sent') {
            $this->assertSame([], $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
            $this->assertSame([], $service->state()['sent_dates']);
            return;
        }
        $this->assertCount(1, $GLOBALS['intersoccer_rr_bf_wp_mail_log']);
        $subject = (string) $GLOBALS['intersoccer_rr_bf_wp_mail_log'][0]['subject'];
        $this->assertStringContainsString($reportDate, $subject);
        if ($final) {
            $this->assertStringStartsWith('Final ', $subject);
        } else {
            $this->assertStringNotContainsString('Final', $subject);
        }
        $this->assertArrayHasKey($reportDate, $service->state()['sent_dates']);
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

    private function installDisabledSampleSettings(): void {
        $GLOBALS['intersoccer_test_option_store'] = [
            DailyBfSalesEmail::OPTION_SETTINGS => [
                'enabled' => false,
                'recipients' => 'list@example.test',
                'send_time' => '08:00',
                'coupon_code' => 'BF',
                'campaign_start' => '2026-11-01',
            ],
            DailyBfSalesEmail::OPTION_STATE => [
                'sent_dates' => ['2026-10-01' => 1],
                'last_sent_at' => '2026-10-02 08:00',
                'last_result' => 'sent',
            ],
        ];
        $GLOBALS['intersoccer_rr_bf_coupon_ids'] = ['BF' => 4242];
        $GLOBALS['intersoccer_rr_bf_coupon_expiries'] = [
            4242 => new \DateTimeImmutable('2026-12-01 00:00:00', new \DateTimeZone('UTC')),
        ];
    }

    private function beginShopReadCounting(): void {
        $GLOBALS['intersoccer_rr_test_api_counting_enabled'] = true;
        $GLOBALS['intersoccer_rr_test_api_call_counts'] = [];
        $GLOBALS['intersoccer_rr_bf_db_counting'] = true;
        $GLOBALS['intersoccer_rr_bf_db_calls'] = 0;
        $GLOBALS['intersoccer_rr_bf_shop_counting'] = true;
        $GLOBALS['intersoccer_rr_bf_shop_calls'] = [];
        $GLOBALS['intersoccer_rr_bf_wc_coupon_calls'] = 0;
        $GLOBALS['intersoccer_rr_bf_coupon_lookup_calls'] = 0;
    }

    private function endShopReadCounting(): void {
        $GLOBALS['intersoccer_rr_bf_db_counting'] = false;
        $GLOBALS['intersoccer_rr_bf_shop_counting'] = false;
        unset($GLOBALS['intersoccer_rr_test_api_counting_enabled']);
    }

    /**
     * @return array<string,mixed>
     */
    private function invokeSampleDigest(DailyBfSalesEmail $service): array {
        $method = new \ReflectionMethod(DailyBfSalesEmail::class, 'sampleDigest');
        $method->setAccessible(true);
        $digest = $method->invoke($service);
        $this->assertIsArray($digest);
        return $digest;
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
            'campaign_start' => $this->campaignStartYmd($coupon),
        ];
        return $this->makeService($coupon);
    }

    private function makeService(array $coupon): DailyBfSalesEmail {
        $this->wpdb = new DailyBfSalesEmailFixtureWpdb();
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);
        $this->reports = new FinancialReportService($this->logger, $database);
        $service = new DailyBfSalesEmail(
            $this->logger,
            $this->reports,
            function ($code) use ($coupon) {
                return $coupon;
            },
            new \DateTimeZone('Europe/Zurich')
        );
        $this->writeCampaignStart($coupon);
        return $service;
    }

    private function wooService(): DailyBfSalesEmail {
        $this->wpdb = new DailyBfSalesEmailFixtureWpdb();
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);
        $this->reports = new FinancialReportService($this->logger, $database);
        return new DailyBfSalesEmail(
            $this->logger,
            $this->reports,
            null,
            new \DateTimeZone('Europe/Zurich')
        );
    }

    private function campaignStartYmd(array $coupon): string {
        if (!array_key_exists('start', $coupon)) {
            return '';
        }
        $start = $coupon['start'];
        if ($start instanceof \DateTimeInterface) {
            return DailyBfSalesEmail::zurichYmdFromTimestamp($start->getTimestamp());
        }
        if (is_int($start) || (is_string($start) && preg_match('/^\d+$/', (string) $start) === 1)) {
            $unix = (int) $start;
            if ($unix <= 0) {
                return '';
            }
            return DailyBfSalesEmail::zurichYmdFromTimestamp($unix);
        }
        return '';
    }

    private function writeCampaignStart(array $coupon): void {
        $ymd = $this->campaignStartYmd($coupon);
        if ($ymd === '') {
            return;
        }
        $key = DailyBfSalesEmail::OPTION_SETTINGS;
        $stored = (isset($GLOBALS['intersoccer_test_option_store'][$key]) && is_array($GLOBALS['intersoccer_test_option_store'][$key]))
            ? $GLOBALS['intersoccer_test_option_store'][$key]
            : [];
        $stored['campaign_start'] = $ymd;
        $GLOBALS['intersoccer_test_option_store'][$key] = $stored;
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

    private function seasonByLabel(array $summary, string $label): array {
        return $this->rowByLabel(isset($summary['seasons']) ? $summary['seasons'] : [], $label, 'season');
    }

    private function seasonTypeByLabel(array $summary, string $label): array {
        return $this->rowByLabel(isset($summary['season_types']) ? $summary['season_types'] : [], $label, 'season and type');
    }

    private function rowByLabel(array $rows, string $label, string $kind): array {
        foreach ($rows as $row) {
            if (is_array($row) && ($row['label'] ?? '') === $label) {
                return $row;
            }
        }
        $this->fail('Missing ' . $kind . ' ' . $label);
    }

    private function regionByLabel(array $summary, string $label): array {
        $regions = isset($summary['regions']) && is_array($summary['regions']) ? $summary['regions'] : [];
        foreach ($regions as $region) {
            if (is_array($region) && ($region['label'] ?? '') === $label) {
                return $region;
            }
        }
        $this->fail('Missing region ' . $label);
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
