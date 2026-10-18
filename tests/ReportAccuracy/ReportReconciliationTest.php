<?php
/**
 * Regression: Booking Report reconciliation invariants (synthetic fixtures).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\FinancialReportService;
use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService as Attr;
use InterSoccer\ReportsRosters\Services\ProductTypeClassifierService;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/ReportAccuracyFixtures.php';

class ReportReconciliationTest extends TestCase {

    /** @var FakeBookingWpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        $this->wpdb = new FakeBookingWpdb();
    }

    private function report(): array {
        $database = $this->createMock(Database::class);
        $database->method('get_wpdb')->willReturn($this->wpdb);
        $classifier = $this->createMock(ProductTypeClassifierService::class);
        $classifier->method('classifyOrderItem')->willReturn(ProductTypeClassifierService::TYPE_CAMP);
        return (new FinancialReportService($this->createMock(Logger::class), $database, $classifier))
            ->getFinancialBookingReport('2025-01-01', '2026-10-12');
    }

    private static function money($v): float {
        return (float) str_replace(',', '', (string) $v);
    }

    public function test_booking_line_nets_reconcile_to_report_totals_and_second_view_is_read_only(): void {
        $order = (new FakeOrder(501))
            ->addLine(new FakeLineItem(5011, ['subtotal' => 300.0, 'total' => 250.0]))
            ->addLine(new FakeLineItem(5012, ['subtotal' => 200.0, 'total' => 200.0]))
            ->addFee(new FakeFee('Referral Credits Discount', -30.0))
            ->setMeta(Attr::META_ORDER_ATTRIBUTION_AT, '2026-07-01 10:00:05');
        ReportAccuracyStore::addOrder($order);
        ReportAccuracyStore::setItemMeta(5011, Attr::META_ITEM_DISCOUNT_TOTAL, '50', false);
        ReportAccuracyStore::setItemMeta(5012, Attr::META_ITEM_DISCOUNT_TOTAL, '30', false);

        $first = $this->report();
        $line_net = 0.0;
        foreach ($first['data'] as $row) {
            $line_net += self::money($row['final_price']) - self::money($row['reimbursement']);
        }
        $footer_net = (float) $first['totals']['final_price'] - (float) $first['totals']['reimbursement'];

        $this->assertSame(round($footer_net, 2), round($line_net, 2));

        $second = $this->report();
        $this->assertSame($first['totals'], $second['totals']);
        $this->assertSame([], ReportAccuracyStore::writtenKeys());
    }
}
