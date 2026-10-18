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

    public function test_store_api_checkout_hook_fired_twice_leaves_attribution_unchanged(): void {
        $order = (new FakeOrder(81001))
            ->addLine(new FakeLineItem(810011, ['subtotal' => 405.0, 'total' => 371.0]))
            ->addFee(new FakeFee('Réduction de crédits de parrainage', -34.0))
            ->setMeta('_intersoccer_points_redeemed', '34');
        ReportAccuracyStore::addOrder($order);

        $GLOBALS['intersoccer_test_emit_wp_hooks'] = true;
        intersoccer_test_run_wp_hooks('actions', 'woocommerce_store_api_checkout_order_processed', null, [$order]);
        $after_first = [
            'total' => ReportAccuracyStore::getItemMeta(810011, Attr::META_ITEM_DISCOUNT_TOTAL),
            'discounts' => ReportAccuracyStore::getItemMeta(810011, Attr::META_ITEM_DISCOUNTS),
            'attribution_at' => $order->get_meta(Attr::META_ORDER_ATTRIBUTION_AT),
        ];

        intersoccer_test_run_wp_hooks('actions', 'woocommerce_store_api_checkout_order_processed', null, [$order]);
        $after_second = [
            'total' => ReportAccuracyStore::getItemMeta(810011, Attr::META_ITEM_DISCOUNT_TOTAL),
            'discounts' => ReportAccuracyStore::getItemMeta(810011, Attr::META_ITEM_DISCOUNTS),
            'attribution_at' => $order->get_meta(Attr::META_ORDER_ATTRIBUTION_AT),
        ];
        unset($GLOBALS['intersoccer_test_emit_wp_hooks']);

        $this->assertSame('34', (string) $after_first['total']);
        $this->assertSame($after_first, $after_second);
    }
}
