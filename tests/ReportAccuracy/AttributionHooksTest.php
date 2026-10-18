<?php
/**
 * Repro (failing test) S10d: Store API (block / express) checkout orders are never attributed at checkout.
 *
 * includes/oop-adapter.php hooks attribution only on woocommerce_checkout_order_processed (classic checkout).
 * Store API orders fire woocommerce_store_api_checkout_order_processed instead, so they stay unattributed
 * until the Booking Report attributes them lazily on view (S19).
 *
 * The adapter cannot be required in isolation (it boots the plugin singleton), so this test reads the
 * add_action() registrations from the plugin sources.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Tests\TestCase;

class AttributionHooksTest extends TestCase {

    /** @return array<string,string[]> hook => callbacks registered anywhere in includes/ and classes/ */
    private static function registeredActions(): array {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/includes/*.php') ?: [],
            glob($root . '/classes/*/*.php') ?: [],
            glob($root . '/classes/*/*/*.php') ?: [],
            [$root . '/intersoccer-reports-rosters.php']
        );
        $out = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all("/add_action\\(\\s*'([^']+)'\\s*,\\s*(?:'([^']+)'|\\[[^\\]]*'([^']+)'\\s*\\])/", $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $out[$hit[1]][] = $hit[2] !== '' ? $hit[2] : ($hit[3] ?? '');
                }
            }
        }
        return $out;
    }

    public function test_S10d_store_api_checkout_triggers_financial_attribution(): void {
        $actions = self::registeredActions();

        $this->assertContains('intersoccer_oop_attribute_order_financials', $actions['woocommerce_checkout_order_processed'] ?? [],
            'precondition: classic checkout hook is registered');

        $store_api = $actions['woocommerce_store_api_checkout_order_processed'] ?? [];
        $this->assertContains('intersoccer_oop_attribute_order_financials', $store_api,
            'S10d: expected intersoccer_oop_attribute_order_financials on woocommerce_store_api_checkout_order_processed; registered: '
            . ($store_api ? implode(', ', $store_api) : 'none'));
    }
}
