<?php
/**
 * Regression: Booking Report end date inclusive (#152).
 */

namespace {

    require_once __DIR__ . '/ReportAccuracyFixtures.php';

    if (!function_exists('wp_date')) {
        function wp_date($format, $timestamp = null, $timezone = null) {
            if ($format === 'Y-m-d' && array_key_exists('intersoccer_rr_test_wp_date_ymd', $GLOBALS)) {
                return (string) $GLOBALS['intersoccer_rr_test_wp_date_ymd'];
            }
            return date($format, $timestamp ?? time());
        }
    }

    if (!function_exists('date_i18n')) {
        function date_i18n($format, $timestamp = null) {
            return date($format, is_numeric($timestamp) ? (int) $timestamp : strtotime((string) $timestamp));
        }
    }
}

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy {

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\FinancialReportService;
use InterSoccer\ReportsRosters\Services\ProductTypeClassifierService;
use InterSoccer\ReportsRosters\Tests\TestCase;

class BookingReportEndDateInclusive152Test extends TestCase {

    /** @var FakeBookingWpdb */
    private $wpdb;

    /** @var mixed */
    private $previousGlobalWpdb;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        $this->wpdb = new FakeBookingWpdb();
        $this->previousGlobalWpdb = $GLOBALS['wpdb'] ?? null;
        unset($GLOBALS['intersoccer_rr_test_wp_date_ymd']);
    }

    protected function tearDown(): void {
        if ($this->previousGlobalWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->previousGlobalWpdb;
        }
        unset($GLOBALS['intersoccer_rr_test_wp_date_ymd']);
        parent::tearDown();
    }

    public function test_date_bounds_october_range(): void {
        $this->assertSame(
            ['2026-10-01 00:00:00', '2026-11-01 00:00:00'],
            intersoccer_booking_report_date_bounds('2026-10-01', '2026-10-31')
        );
    }

    public function test_date_bounds_year_rollover(): void {
        $this->assertSame(
            ['2026-12-31 00:00:00', '2027-01-01 00:00:00'],
            intersoccer_booking_report_date_bounds('2026-12-31', '2026-12-31')
        );
    }

    public function test_date_bounds_leap_day(): void {
        $this->assertSame(
            ['2028-02-28 00:00:00', '2028-02-29 00:00:00'],
            intersoccer_booking_report_date_bounds('2028-02-28', '2028-02-28')
        );
        $this->assertSame(
            ['2028-02-29 00:00:00', '2028-03-01 00:00:00'],
            intersoccer_booking_report_date_bounds('2028-02-29', '2028-02-29')
        );
    }

    public function test_date_bounds_strips_time_portion(): void {
        $this->assertSame(
            ['2026-05-10 00:00:00', '2026-05-12 00:00:00'],
            intersoccer_booking_report_date_bounds('2026-05-10 14:30:00', '2026-05-11 23:59:59')
        );
    }

    public function test_date_bounds_empty_and_invalid(): void {
        $this->assertSame([null, null], intersoccer_booking_report_date_bounds('', ''));
        $this->assertSame([null, '2026-01-02 00:00:00'], intersoccer_booking_report_date_bounds('not-a-date', '2026-01-01'));
        $this->assertSame(['2026-01-01 00:00:00', null], intersoccer_booking_report_date_bounds('2026-01-01', ''));
        $this->assertSame([null, '2026-02-02 00:00:00'], intersoccer_booking_report_date_bounds('', '2026-02-01'));
    }

    public function test_service_sql_uses_half_open_post_date_range(): void {
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn(new SqlCaptureBookingWpdb());
        $classifier = $this->createMock(ProductTypeClassifierService::class);
        $service = new FinancialReportService($this->createMock(Logger::class), $database, $classifier);
        SqlCaptureBookingWpdb::$booking_query = '';
        $service->getFinancialBookingReport('2026-10-01', '2026-10-31');

        $query = SqlCaptureBookingWpdb::$booking_query;
        $this->assertStringContainsString("p.post_date >= '2026-10-01 00:00:00'", $query);
        $this->assertStringContainsString("p.post_date < '2026-11-01 00:00:00'", $query);
        $this->assertStringNotContainsString('post_date <=', $query);
        $this->assertStringNotContainsString('DATE(p.post_date)', $query);
    }

    public function test_legacy_financial_fallback_sql_uses_half_open_range(): void {
        $wpdb = new SqlCaptureBookingWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        SqlCaptureBookingWpdb::$booking_query = '';
        if (!function_exists('intersoccer_get_financial_booking_report')) {
            require_once dirname(__DIR__, 2) . '/includes/reporting-discounts.php';
        }
        intersoccer_get_financial_booking_report('2026-10-01', '2026-10-31', '', '');

        $query = SqlCaptureBookingWpdb::$booking_query;
        $this->assertStringContainsString("p.post_date >= '2026-10-01 00:00:00'", $query);
        $this->assertStringContainsString("p.post_date < '2026-11-01 00:00:00'", $query);
        $this->assertStringNotContainsString('post_date <=', $query);
    }

    public function test_enhanced_booking_report_sql_uses_half_open_range(): void {
        $wpdb = new SqlCaptureBookingWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        SqlCaptureBookingWpdb::$booking_query = '';
        if (!function_exists('intersoccer_get_enhanced_booking_report')) {
            require_once dirname(__DIR__, 2) . '/includes/reporting-discounts.php';
        }
        intersoccer_get_enhanced_booking_report('2026-10-01', '2026-10-31', '', '');

        $query = SqlCaptureBookingWpdb::$booking_query;
        $this->assertStringContainsString("p.post_date >= '2026-10-01 00:00:00'", $query);
        $this->assertStringContainsString("p.post_date < '2026-11-01 00:00:00'", $query);
        $this->assertStringNotContainsString('DATE(p.post_date)', $query);
        $this->assertStringNotContainsString('post_date <=', $query);
    }

    public function test_end_date_day_orders_included_through_235959(): void {
        $start = '2026-08-01';
        $end = '2026-08-31';
        $this->seedEdgeOrders($start, $end);
        $refs = $this->reportRefs($start, $end);
        $this->assertContains('9001-90011', $refs);
        $this->assertContains('9002-90021', $refs);
        $this->assertContains('9003-90031', $refs);
        $this->assertNotContains('9004-90041', $refs);
        $this->assertContains('9005-90051', $refs);
        $this->assertNotContains('9006-90061', $refs);
    }

    public function test_adjacent_half_month_ranges_partition_full_month_without_gap_or_overlap(): void {
        $month_start = '2026-03-01';
        $month_end = '2026-03-31';
        $this->seedMonthPartitionOrders($month_start, $month_end);

        $first_half = $this->reportBookingCount('2026-03-01', '2026-03-15');
        $second_half = $this->reportBookingCount('2026-03-16', '2026-03-31');
        $full_month = $this->reportBookingCount($month_start, $month_end);

        $this->assertSame(2, $first_half, 'expected two bookings in 1–15');
        $this->assertSame(2, $second_half, 'expected two bookings in 16–31');
        $this->assertSame(4, $full_month, 'expected four bookings in full month');
        $this->assertSame($full_month, $first_half + $second_half);

        $first_refs = $this->reportRefs('2026-03-01', '2026-03-15');
        $second_refs = $this->reportRefs('2026-03-16', '2026-03-31');
        $this->assertSame([], array_values(array_intersect($first_refs, $second_refs)));

        $GLOBALS['wpdb'] = $this->wpdb;
        if (!function_exists('intersoccer_get_financial_booking_report')) {
            require_once dirname(__DIR__, 2) . '/includes/reporting-discounts.php';
        }
        $legacy_full = (int) intersoccer_get_financial_booking_report($month_start, $month_end, '', '')['totals']['bookings'];
        $this->assertSame($full_month, $legacy_full);

        if (!function_exists('intersoccer_get_enhanced_booking_report')) {
            require_once dirname(__DIR__, 2) . '/includes/reporting-discounts.php';
        }
        $enhanced_full = (int) intersoccer_get_enhanced_booking_report($month_start, $month_end, '2026', '')['totals']['bookings'];
        $this->assertSame($full_month, $enhanced_full);
    }

    public function test_fixture_honors_inclusive_post_date_lte_operator(): void {
        ReportAccuracyStore::addOrder(
            (new FakeOrder(9050, 'completed', '2026-04-10'))->addLine(
                new FakeLineItem(90501, ['subtotal' => 10.0, 'total' => 10.0])
            )
        );
        ReportAccuracyStore::addOrder(
            (new FakeOrder(9051, 'completed', '2026-04-10 00:00:01'))->addLine(
                new FakeLineItem(90511, ['subtotal' => 10.0, 'total' => 10.0])
            )
        );

        $wpdb = new FakeBookingWpdb();
        $query = "
            SELECT p.ID FROM wp_posts p
            INNER JOIN wp_woocommerce_order_items oi ON p.ID = oi.order_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed')
            AND p.post_date >= '2026-04-01' AND p.post_date <= '2026-04-10'
        ";
        $rows = $wpdb->get_results($query);
        $this->assertCount(1, $rows);
        $this->assertSame(9050, (int) $rows[0]->order_id);
    }

    public function test_legacy_financial_invalid_range_without_year_returns_empty(): void {
        ReportAccuracyStore::addOrder(
            (new FakeOrder(9070, 'completed', '2026-06-01 12:00:00'))->addLine(
                new FakeLineItem(90701, ['subtotal' => 10.0, 'total' => 10.0])
            )
        );

        $GLOBALS['wpdb'] = $this->wpdb;
        if (!function_exists('intersoccer_get_financial_booking_report')) {
            require_once dirname(__DIR__, 2) . '/includes/reporting-discounts.php';
        }
        $this->assertSame(0, (int) intersoccer_get_financial_booking_report('bad-start', 'bad-end', '', '')['totals']['bookings']);
    }

    public function test_invalid_end_date_with_year_falls_back_to_year_filter(): void {
        ReportAccuracyStore::addOrder(
            (new FakeOrder(9060, 'completed', '2026-05-01 12:00:00'))->addLine(
                new FakeLineItem(90601, ['subtotal' => 10.0, 'total' => 10.0])
            )
        );
        ReportAccuracyStore::addOrder(
            (new FakeOrder(9061, 'completed', '2027-01-02 12:00:00'))->addLine(
                new FakeLineItem(90611, ['subtotal' => 10.0, 'total' => 10.0])
            )
        );

        $this->assertSame(1, $this->reportBookingCount('2026-01-01', 'not-a-date', '2026'));
    }

    public function test_invalid_date_without_year_returns_empty(): void {
        ReportAccuracyStore::addOrder(
            (new FakeOrder(9071, 'completed', '2026-06-01 12:00:00'))->addLine(
                new FakeLineItem(90711, ['subtotal' => 10.0, 'total' => 10.0])
            )
        );

        $this->assertSame(0, $this->reportBookingCount('bad-start', '2026-06-30', ''));
    }

    public function test_valid_range_unchanged_with_invalid_year_param(): void {
        $this->seedEdgeOrders('2026-08-01', '2026-08-31');
        $this->assertSame(4, $this->reportBookingCount('2026-08-01', '2026-08-31', '1999'));
    }

    public function test_service_sql_invalid_range_without_year_uses_always_false(): void {
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn(new SqlCaptureBookingWpdb());
        $classifier = $this->createMock(ProductTypeClassifierService::class);
        $service = new FinancialReportService($this->createMock(Logger::class), $database, $classifier);
        SqlCaptureBookingWpdb::$booking_query = '';
        $service->getFinancialBookingReport('not-a-date', 'also-bad', '', '');

        $this->assertStringContainsString('AND 1=0', SqlCaptureBookingWpdb::$booking_query);
    }

    public function test_service_sql_invalid_range_with_year_uses_year_filter(): void {
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn(new SqlCaptureBookingWpdb());
        $classifier = $this->createMock(ProductTypeClassifierService::class);
        $service = new FinancialReportService($this->createMock(Logger::class), $database, $classifier);
        SqlCaptureBookingWpdb::$booking_query = '';
        $service->getFinancialBookingReport('not-a-date', '2026-01-01', '2026', '');

        $this->assertStringContainsString('YEAR(p.post_date) = 2026', SqlCaptureBookingWpdb::$booking_query);
        $this->assertStringNotContainsString('AND 1=0', SqlCaptureBookingWpdb::$booking_query);
    }

    public function test_site_today_default_uses_wp_date_local(): void {
        $GLOBALS['intersoccer_rr_test_wp_date_ymd'] = '2026-07-01';
        $this->assertSame('2026-07-01', intersoccer_booking_report_site_today_ymd());
    }

    /**
     * @return list<string>
     */
    private function reportRefs(string $from, string $to): array {
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);
        $classifier = $this->createMock(ProductTypeClassifierService::class);
        $classifier->method('classifyOrderItem')->willReturn(ProductTypeClassifierService::TYPE_CAMP);
        $service = new FinancialReportService($this->createMock(Logger::class), $database, $classifier);
        $result = $service->getFinancialBookingReport($from, $to);
        $refs = [];
        foreach ($result['data'] as $row) {
            $refs[] = $row['ref'];
        }
        sort($refs);
        return $refs;
    }

    private function reportBookingCount(string $from, string $to, string $year = ''): int {
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);
        $classifier = $this->createMock(ProductTypeClassifierService::class);
        $classifier->method('classifyOrderItem')->willReturn(ProductTypeClassifierService::TYPE_CAMP);
        $service = new FinancialReportService($this->createMock(Logger::class), $database, $classifier);
        $result = $service->getFinancialBookingReport($from, $to, $year);
        return (int) $result['totals']['bookings'];
    }

    private function seedEdgeOrders(string $start, string $end): void {
        $end_dt = intersoccer_booking_report_normalize_ymd($end);
        $start_dt = intersoccer_booking_report_normalize_ymd($start);
        $before_start = \DateTime::createFromFormat('!Y-m-d', $start_dt)->modify('-1 day')->format('Y-m-d') . ' 23:59:59';
        $after_end = \DateTime::createFromFormat('!Y-m-d', $end_dt)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

        $attributed = static function (int $id, string $date) {
            return (new FakeOrder($id, 'completed', $date))->setMeta(
                \InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService::META_ORDER_ATTRIBUTION_AT,
                $date
            );
        };

        ReportAccuracyStore::addOrder(
            $attributed(9001, $end . ' 00:00:00')->addLine(new FakeLineItem(90011, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9002, $end . ' 12:00:00')->addLine(new FakeLineItem(90021, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9003, $end . ' 23:59:59')->addLine(new FakeLineItem(90031, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9004, $after_end)->addLine(new FakeLineItem(90041, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9005, $start . ' 00:00:00')->addLine(new FakeLineItem(90051, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9006, $before_start)->addLine(new FakeLineItem(90061, ['subtotal' => 10.0, 'total' => 10.0]))
        );
    }

    private function seedMonthPartitionOrders(string $month_start, string $month_end): void {
        $attributed = static function (int $id, string $date) {
            return (new FakeOrder($id, 'completed', $date))->setMeta(
                \InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService::META_ORDER_ATTRIBUTION_AT,
                $date
            );
        };

        $before_month = \DateTime::createFromFormat('!Y-m-d', intersoccer_booking_report_normalize_ymd($month_start))
            ->modify('-1 day')->format('Y-m-d') . ' 23:59:59';
        $after_month = \DateTime::createFromFormat('!Y-m-d', intersoccer_booking_report_normalize_ymd($month_end))
            ->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

        ReportAccuracyStore::addOrder(
            $attributed(9101, $month_start . ' 08:00:00')->addLine(new FakeLineItem(91011, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9102, '2026-03-15 23:59:59')->addLine(new FakeLineItem(91021, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9103, '2026-03-16 00:00:01')->addLine(new FakeLineItem(91031, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9104, $month_end . ' 22:30:00')->addLine(new FakeLineItem(91041, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9105, $before_month)->addLine(new FakeLineItem(91051, ['subtotal' => 10.0, 'total' => 10.0]))
        );
        ReportAccuracyStore::addOrder(
            $attributed(9106, $after_month)->addLine(new FakeLineItem(91061, ['subtotal' => 10.0, 'total' => 10.0]))
        );
    }
}

class SqlCaptureBookingWpdb {
    public static $last_query = '';
    public static $booking_query = '';
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $last_error = '';

    private static function noteQuery(string $query): void {
        self::$last_query = $query;
        if (strpos($query, "post_type = 'shop_order'") !== false
            && strpos($query, 'woocommerce_order_items') !== false) {
            self::$booking_query = $query;
        }
    }

    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $arg) {
            if (is_int($arg)) {
                $query = preg_replace('/%d/', (string) $arg, $query, 1);
            } else {
                $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
            }
        }
        return $query;
    }

    public function get_results($query, $output = OBJECT) {
        self::noteQuery((string) $query);
        return [];
    }

    public function get_col($query, $x = 0) {
        self::noteQuery((string) $query);
        return [];
    }
}

}
