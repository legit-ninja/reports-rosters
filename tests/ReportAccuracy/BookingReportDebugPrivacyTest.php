<?php
/**
 * Regression: Booking Report debug logging must not include order or line-item identifiers.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Services\FinancialReportService;
use InterSoccer\ReportsRosters\Services\ProductTypeClassifierService;
use InterSoccer\ReportsRosters\Tests\TestCase;

class BookingReportDebugPrivacyTest extends TestCase {

    public function test_reporting_discounts_fallback_error_log_messages_exclude_order_and_item_ids(): void {
        $path = dirname(__DIR__, 2) . '/includes/reporting-discounts.php';
        $source = (string) file_get_contents($path);
        $this->assertNotEmpty($source);

        preg_match_all(
            '/error_log\("InterSoccer (?:Enhanced|Financial) Report:[^"]*fallback discount calculation[^"]*"\);/',
            $source,
            $matches
        );
        $this->assertNotEmpty($matches[0], 'Expected fallback discount error_log lines in reporting-discounts.php');

        foreach ($matches[0] as $line) {
            $this->assertStringNotContainsString('order_id', $line);
            $this->assertStringNotContainsString('order_item_id', $line);
            $this->assertDoesNotMatchRegularExpression('/Order \{\$/', $line);
            $this->assertDoesNotMatchRegularExpression('/Item \{\$/', $line);
        }
    }

    public function test_financial_report_service_fallback_debug_context_excludes_order_and_item_ids(): void {
        $captured = [];
        $logger = $this->createMock(Logger::class);
        $logger->method('debug')->willReturnCallback(static function (string $message, array $context = []) use (&$captured): void {
            $captured[] = ['message' => $message, 'context' => $context];
        });

        $service = new FinancialReportService(
            $logger,
            $this->createMock(Database::class),
            $this->createMock(ProductTypeClassifierService::class)
        );
        $method = new \ReflectionMethod(FinancialReportService::class, 'resolveItemDiscountData');
        $method->setAccessible(true);
        $method->invoke($service, [], 100.0, 50.0, 9001, 90011);

        $this->assertCount(1, $captured);
        $this->assertStringContainsString('fallback discount calculation', $captured[0]['message']);
        $this->assertArrayNotHasKey('order_id', $captured[0]['context']);
        $this->assertArrayNotHasKey('item_id', $captured[0]['context']);
        $this->assertSame(50.0, $captured[0]['context']['discount_amount']);
    }
}
