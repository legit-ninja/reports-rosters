<?php
/**
 * Regression: OOP adapter checkout attribution (classic + Store API).
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService as Attr;
use InterSoccer\ReportsRosters\Tests\TestCase;

require_once __DIR__ . '/ReportAccuracyFixtures.php';

class AttributionOopAdapterTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        ReportAccuracyStore::reset();
        unset($GLOBALS['intersoccer_test_attribution_service_override']);
        self::ensureOopAdapterLoaded();
    }

    protected function tearDown(): void {
        unset($GLOBALS['intersoccer_test_attribution_service_override']);
        parent::tearDown();
    }

    private static function ensureOopAdapterLoaded(): void {
        if (function_exists('intersoccer_oop_attribute_order_financials')) {
            return;
        }

        if (!defined('INTERSOCCER_OOP_ACTIVE')) {
            define('INTERSOCCER_OOP_ACTIVE', true);
        }

        $plugin_file = dirname(__DIR__, 2) . '/intersoccer-reports-rosters.php';
        \InterSoccer\ReportsRosters\Core\Plugin::get_instance($plugin_file);

        require_once dirname(__DIR__, 2) . '/includes/oop-adapter.php';
    }

    public function test_attribute_order_financials_logs_numeric_order_id_when_passed_order_object(): void {
        $order = (new FakeOrder(80999))->addLine(new FakeLineItem(809991, ['subtotal' => 100.0, 'total' => 100.0]));
        ReportAccuracyStore::addOrder($order);

        $throwing = $this->createMock(Attr::class);
        $throwing->method('attributeOrderDiscounts')->willThrowException(new \RuntimeException('synthetic failure'));
        $GLOBALS['intersoccer_test_attribution_service_override'] = $throwing;

        $log_file = tempnam(sys_get_temp_dir(), 'rr_attr_log_');
        $this->assertNotFalse($log_file);
        $previous_log = ini_set('error_log', $log_file);

        try {
            intersoccer_oop_attribute_order_financials($order);
            $log = (string) file_get_contents($log_file);
            $this->assertStringContainsString('80999', $log);
            $this->assertStringContainsString('synthetic failure', $log);
        } finally {
            ini_set('error_log', (string) $previous_log);
            if (is_string($log_file) && file_exists($log_file)) {
                unlink($log_file);
            }
        }
    }
}
