<?php
/**
 * Synthetic fixtures for the report-accuracy repro tests (Booking Report + Final Numbers).
 *
 * All data here is synthetic. No real order ids, item ids or names.
 *
 * The bootstrap stubs wc_*_order_item_meta() as global no-ops, so attribution writes would be invisible.
 * The functions below are declared in the InterSoccer\ReportsRosters\Services namespace: the services
 * call wc_*_order_item_meta() unqualified, so PHP resolves these namespaced versions first and every
 * item-meta read/write goes through ReportAccuracyStore (which the fake wpdb also reads).
 * This file must be loaded before any service method calls those functions in the same process.
 */

namespace InterSoccer\ReportsRosters\Services {

    use InterSoccer\ReportsRosters\Tests\ReportAccuracy\ReportAccuracyStore;

    if (!function_exists(__NAMESPACE__ . '\\wc_get_order_item_meta')) {
        function wc_get_order_item_meta($item_id, $key, $single = true) {
            return ReportAccuracyStore::getItemMeta((int) $item_id, (string) $key, $single);
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wc_update_order_item_meta')) {
        function wc_update_order_item_meta($item_id, $meta_key, $meta_value, $prev_value = '') {
            ReportAccuracyStore::setItemMeta((int) $item_id, (string) $meta_key, $meta_value);
            return true;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wc_delete_order_item_meta')) {
        function wc_delete_order_item_meta($item_id, $meta_key, $meta_value = '', $delete_all = false) {
            ReportAccuracyStore::deleteItemMeta((int) $item_id, (string) $meta_key);
            return true;
        }
    }
}

namespace {

    use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService;

    require_once dirname(__DIR__, 2) . '/includes/financial-attribution-helpers.php';
    if (!function_exists('intersoccer_reports_line_is_buyclub')) {
        $report_accuracy_utils_log = ini_set('error_log', '/dev/null');
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        if ($report_accuracy_utils_log !== false) {
            ini_set('error_log', $report_accuracy_utils_log);
        }
    }

    if (!function_exists('maybe_unserialize')) {
        function maybe_unserialize($data) {
            if (is_string($data)) {
                $un = @unserialize($data);
                if ($un !== false || $data === 'b:0;') {
                    return $un;
                }
            }
            return $data;
        }
    }

    // Same contract as includes/oop-adapter.php (not loaded by the bootstrap).
    if (!function_exists('intersoccer_oop_get_order_financial_attribution_service')) {
        function intersoccer_oop_get_order_financial_attribution_service() {
            if (isset($GLOBALS['intersoccer_test_attribution_service_override'])) {
                return $GLOBALS['intersoccer_test_attribution_service_override'];
            }
            static $service = null;
            if ($service === null) {
                $service = new OrderFinancialAttributionService(new \InterSoccer\ReportsRosters\Core\Logger());
            }
            return $service;
        }
    }

    if (!class_exists('BackfillMissingAttributionWpdb', false)) {
        /**
         * Minimal wpdb stub: findMissingAttributionOrderIds() only needs get_col() after prepare().
         */
        class BackfillMissingAttributionWpdb {
            public $posts = 'wp_posts';
            public $postmeta = 'wp_postmeta';
            public $prefix = 'wp_';

            /** @var array<int,int> */
            private $order_ids;

            /** @param array<int,int> $order_ids */
            public function __construct(array $order_ids) {
                $this->order_ids = $order_ids;
            }

            public function prepare($query, ...$args) {
                return 'BACKFILL_MISSING_ATTRIBUTION';
            }

            public function get_col($query) {
                if ($query === 'BACKFILL_MISSING_ATTRIBUTION') {
                    return array_map('strval', $this->order_ids);
                }
                return [];
            }

            public function get_var($query) {
                return '0';
            }
        }
    }
}

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy {

    /**
     * In-memory item meta + write log shared by the namespaced wc_* stubs and the fake wpdb.
     */
    class ReportAccuracyStore {
        /** @var array<int,array<string,mixed>> */
        public static $item_meta = [];
        /** @var array<int,FakeOrder> */
        public static $orders = [];
        /** @var array<int,array{op:string,object:string,id:int,key:string}> */
        public static $writes = [];

        public static function reset(): void {
            self::$item_meta = [];
            self::$orders = [];
            self::$writes = [];
            \InterSoccerRRTestMeta::reset();
            $GLOBALS['intersoccer_test_wc_get_order_callback'] = static function ($id) {
                return self::$orders[(int) $id] ?? false;
            };
        }

        public static function getItemMeta(int $item_id, string $key, bool $single = true) {
            if (!array_key_exists($key, self::$item_meta[$item_id] ?? [])) {
                return $single ? '' : [];
            }
            $v = self::$item_meta[$item_id][$key];
            return $single ? $v : [$v];
        }

        public static function setItemMeta(int $item_id, string $key, $value, bool $log = true): void {
            self::$item_meta[$item_id][$key] = $value;
            if ($log) {
                self::$writes[] = ['op' => 'update', 'object' => 'item', 'id' => $item_id, 'key' => $key];
            }
        }

        public static function deleteItemMeta(int $item_id, string $key): void {
            unset(self::$item_meta[$item_id][$key]);
            self::$writes[] = ['op' => 'delete', 'object' => 'item', 'id' => $item_id, 'key' => $key];
        }

        public static function addOrder(FakeOrder $order): FakeOrder {
            self::$orders[$order->get_id()] = $order;
            foreach ($order->get_items('line_item') as $item_id => $item) {
                // Core line meta the Booking SQL joins on (seeded, not logged as writes).
                self::setItemMeta($item_id, '_product_id', (string) $item->get_product_id(), false);
                self::setItemMeta($item_id, '_variation_id', (string) $item->get_variation_id(), false);
                self::setItemMeta($item_id, '_qty', (string) $item->get_quantity(), false);
                self::setItemMeta($item_id, '_line_subtotal', (string) $item->get_subtotal(), false);
                self::setItemMeta($item_id, '_line_total', (string) $item->get_total(), false);
            }
            return $order;
        }

        /** @return array<int,string> keys written ("object:key") */
        public static function writtenKeys(): array {
            $keys = [];
            foreach (self::$writes as $w) {
                $keys[] = $w['object'] . ':' . $w['key'];
            }
            sort($keys);
            return array_values(array_unique($keys));
        }
    }

    class FakeLineItem extends \WC_Order_Item_Product {
        public function __construct(int $item_id, array $data, array $meta = []) {
            parent::__construct($item_id);
            $this->set_data($data + ['product_id' => 1000, 'variation_id' => 0, 'quantity' => 1, 'name' => 'Synthetic Camp']);
            foreach ($meta as $k => $v) {
                ReportAccuracyStore::setItemMeta($item_id, (string) $k, $v, false);
            }
        }
        public function get_meta($key, $single = true) {
            return ReportAccuracyStore::getItemMeta($this->id, (string) $key, $single);
        }
    }

    class FakeFee {
        private $name; private $total;
        public function __construct(string $name, float $total) { $this->name = $name; $this->total = $total; }
        public function get_name() { return $this->name; }
        public function get_total() { return $this->total; }
    }

    class FakeCoupon {
        private $code; private $discount;
        public function __construct(string $code, float $discount) { $this->code = $code; $this->discount = $discount; }
        public function get_code() { return $this->code; }
        public function get_name() { return $this->code; }
        public function get_discount() { return $this->discount; }
    }

    class FakeRefundLine {
        private $refunded_item_id; private $total;
        public function __construct(int $refunded_item_id, float $total) { $this->refunded_item_id = $refunded_item_id; $this->total = $total; }
        public function get_meta($key, $single = true) { return $key === '_refunded_item_id' ? $this->refunded_item_id : ''; }
        public function get_total() { return $this->total; }
    }

    class FakeOrder extends \WC_Order {
        public $status; public $date; public $total; public $total_refunded = 0.0;
        /** @var array<string,array> */
        public $typed_items = ['line_item' => [], 'fee' => [], 'coupon' => []];

        public function __construct(int $id, string $status = 'completed', string $date = '2026-07-01 10:00:00') {
            parent::__construct($id);
            $this->status = $status;
            $this->date = $date;
        }
        public function addLine(FakeLineItem $item): self { $this->typed_items['line_item'][$item->get_id()] = $item; return $this; }
        public function addFee(FakeFee $fee): self { $this->typed_items['fee'][] = $fee; return $this; }
        public function addCoupon(FakeCoupon $c): self { $this->typed_items['coupon'][] = $c; return $this; }
        public function addRefund(FakeRefund $r): self { $this->refunds[] = $r; $this->total_refunded += abs($r->get_total()); return $this; }
        public function setMeta(string $key, $value): self { $this->meta_data[$key] = $value; return $this; }

        public function get_status() { return $this->status; }
        public function get_items($types = 'line_item') {
            $out = [];
            foreach ((array) $types as $t) {
                foreach ($this->typed_items[$t] ?? [] as $k => $v) {
                    $out[$k] = $v;
                }
            }
            return $out;
        }
        public function get_total() {
            if ($this->total !== null) {
                return $this->total;
            }
            $t = 0.0;
            foreach ($this->typed_items['line_item'] as $i) { $t += (float) $i->get_total(); }
            foreach ($this->typed_items['fee'] as $f) { $t += (float) $f->get_total(); }
            return $t;
        }
        public function get_subtotal() {
            $t = 0.0;
            foreach ($this->typed_items['line_item'] as $i) {
                $t += (float) $i->get_subtotal();
            }
            return $t;
        }
        public function get_total_refunded() { return $this->total_refunded; }
        public function update_meta_data($key, $value) {
            $this->meta_data[$key] = $value;
            ReportAccuracyStore::$writes[] = ['op' => 'update', 'object' => 'order', 'id' => $this->id, 'key' => (string) $key];
        }
        public function save() {
            ReportAccuracyStore::$writes[] = ['op' => 'save', 'object' => 'order', 'id' => $this->id, 'key' => '(save)'];
            return $this->id;
        }

        public function save_meta_data() {
            ReportAccuracyStore::$writes[] = ['op' => 'save_meta', 'object' => 'order', 'id' => $this->id, 'key' => '(save_meta_data)'];
            return true;
        }

        public function get_meta($key, $single = true) {
            if (!array_key_exists($key, $this->meta_data)) {
                return $single ? '' : [];
            }
            $value = $this->meta_data[$key];
            return $single ? $value : [$value];
        }

        /** @return string[] */
        public function metaKeys(): array {
            return array_keys($this->meta_data ?? []);
        }
    }

    class FakeRefund extends \WC_Order_Refund {
        private $lines; private $amount;
        public function __construct(int $id, float $amount, array $lines = []) {
            parent::__construct($id);
            $this->amount = $amount;
            $this->lines = $lines;
        }
        public function get_items($types = 'line_item') { return $types === 'line_item' ? $this->lines : []; }
        public function get_total() { return -abs($this->amount); }
        public function get_amount() { return abs($this->amount); }
        public function update_meta_data($key, $value) { $this->meta_data[$key] = $value; }
        public function save() { return $this->id; }
    }

    /**
     * Minimal wpdb that answers the three queries FinancialReportService::getFinancialBookingReport() makes.
     */
    class FakeBookingWpdb extends \InterSoccerTestWpdb {
        public $prefix = 'wp_';
        public $posts = 'wp_posts';
        /** @var string[] */
        public $queries = [];

        public function get_results($query = null, $output = OBJECT) {
            $this->queries[] = (string) $query;
            if (strpos($query, "post_type = 'shop_order'") !== false && strpos($query, 'woocommerce_order_items oi') !== false) {
                return $this->bookingRows((string) $query);
            }
            if (preg_match('/woocommerce_order_itemmeta WHERE order_item_id = (\d+)/', (string) $query, $m)) {
                $rows = [];
                foreach (ReportAccuracyStore::$item_meta[(int) $m[1]] ?? [] as $k => $v) {
                    $rows[] = (object) ['meta_key' => $k, 'meta_value' => is_array($v) ? serialize($v) : (string) $v];
                }
                return $rows;
            }
            return [];
        }

        public function get_col($query = null, $x = 0) {
            $this->queries[] = (string) $query;
            if (preg_match("/WHERE order_id = (\\d+)\\s+AND order_item_type = 'coupon'/", (string) $query, $m)) {
                $order = ReportAccuracyStore::$orders[(int) $m[1]] ?? null;
                return $order ? array_map(static function ($c) { return $c->get_code(); }, array_values($order->get_items('coupon'))) : [];
            }
            return [];
        }

        private function bookingRows(string $query): array {
            preg_match("/post_status IN \\(([^)]*)\\)/", $query, $sm);
            $statuses = array_map(static function ($s) { return trim($s, " '\n\t"); }, explode(',', $sm[1] ?? ''));
            $from = $to = null;
            if (preg_match("/p\\.post_date >= '([^']*)' AND p\\.post_date <= '([^']*)'/", $query, $dm)) {
                $from = $dm[1];
                $to = $dm[2];
            }
            $orders = ReportAccuracyStore::$orders;
            uasort($orders, static function ($a, $b) { return strcmp($b->date, $a->date) ?: ($b->get_id() <=> $a->get_id()); });
            $rows = [];
            foreach ($orders as $order) {
                if (!in_array('wc-' . $order->status, $statuses, true)) {
                    continue;
                }
                // MySQL compares DATETIME with a 'Y-m-d' literal as midnight; string order gives the same result here.
                if ($from !== null && (strcmp($order->date, $from) < 0 || strcmp($order->date, $to) > 0)) {
                    continue;
                }
                foreach ($order->get_items('line_item') as $item_id => $item) {
                    $rows[] = (object) [
                        'order_id' => $order->get_id(),
                        'order_date' => $order->date,
                        'order_status' => 'wc-' . $order->status,
                        'order_item_id' => $item_id,
                        'order_item_name' => $item->get_name(),
                        'product_id' => ReportAccuracyStore::getItemMeta($item_id, '_product_id'),
                        'variation_id' => ReportAccuracyStore::getItemMeta($item_id, '_variation_id'),
                        'quantity' => ReportAccuracyStore::getItemMeta($item_id, '_qty'),
                        'line_total' => ReportAccuracyStore::getItemMeta($item_id, '_line_total'),
                        'line_subtotal' => ReportAccuracyStore::getItemMeta($item_id, '_line_subtotal'),
                    ];
                }
            }
            return $rows;
        }
    }
}
