<?php
/**
 * Order-line product_name storage: line name as booked; catalogue fallback when empty.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterOrderLineProductNameTest extends TestCase {

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_rr_test_api_counting_enabled'],
            $GLOBALS['intersoccer_rr_test_api_call_counts'],
            $GLOBALS['intersoccer_test_wc_get_product_callback'],
            $GLOBALS['intersoccer_test_apply_filters_callback'],
            $GLOBALS['intersoccer_test_wc_order_factory_get_order_item_callback']
        );
        parent::tearDown();
    }

    protected function setUp(): void {
        parent::setUp();
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
    }

    public function test_english_order_stores_order_line_name_over_catalogue_title(): void {
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Course A 2025',
            'product_id' => 9001,
            'variation_id' => 0,
            'catalog_product_name' => 'SYNTH Course A 2026',
        ]);
        $this->assertSame('SYNTH Course A 2025', $resolved['product_name']);
        $this->assertFalse($resolved['used_english_catalogue_fallback']);
    }

    public function test_unknown_order_language_stores_line_name(): void {
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Course B 2025',
            'product_id' => 9002,
            'variation_id' => 0,
            'catalog_product_name' => 'SYNTH Course B 2026',
        ]);
        $this->assertSame('SYNTH Course B 2025', $resolved['product_name']);
    }

    public function test_french_order_stores_order_line_name_as_booked(): void {
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Cours C FR',
            'product_id' => 9003,
            'variation_id' => 0,
            'catalog_product_name' => 'SYNTH Course C EN',
        ]);
        $this->assertSame('SYNTH Cours C FR', $resolved['product_name']);
        $this->assertFalse($resolved['used_english_catalogue_fallback']);
    }

    public function test_german_order_stores_order_line_name_as_booked(): void {
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Kurs D DE',
            'product_id' => 9005,
            'variation_id' => 0,
            'catalog_product_name' => 'SYNTH Course D EN',
        ]);
        $this->assertSame('SYNTH Kurs D DE', $resolved['product_name']);
    }

    public function test_empty_line_name_falls_back_to_catalogue_title(): void {
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => '',
            'product_id' => 9004,
            'variation_id' => 0,
            'catalog_product_name' => 'SYNTH Course D Catalogue',
        ]);
        $this->assertSame('SYNTH Course D Catalogue', $resolved['product_name']);
        $this->assertTrue($resolved['used_english_catalogue_fallback']);
    }

    public function test_apply_product_name_skips_catalog_wc_get_product_when_line_name_present(): void {
        $GLOBALS['intersoccer_rr_test_api_counting_enabled'] = true;
        $GLOBALS['intersoccer_rr_test_api_call_counts'] = [];
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function () {
            throw new \RuntimeException('wc_get_product must not run when order line name is set');
        };

        $row = intersoccer_roster_apply_product_name_for_storage([
            'order_line_name' => 'SYNTH Booked Line',
            'product_id' => 9101,
            'variation_id' => 9102,
        ]);

        $this->assertSame('SYNTH Booked Line', $row['product_name']);
        $this->assertSame(0, (int) ($GLOBALS['intersoccer_rr_test_api_call_counts']['wc_get_product'] ?? 0));
    }

    public function test_apply_product_name_uses_catalog_when_line_name_empty(): void {
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function () {
            return new class {
                public function get_name() {
                    return 'SYNTH Catalogue Only';
                }
            };
        };

        $row = intersoccer_roster_apply_product_name_for_storage([
            'order_line_name' => '',
            'product_id' => 9103,
            'variation_id' => 0,
        ]);

        $this->assertSame('SYNTH Catalogue Only', $row['product_name']);
    }

    public function test_catalog_product_name_uses_english_helper_for_translated_product(): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id') {
                return $args[0] ?? $value;
            }
            return $value;
        };
        $wc_calls = 0;
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function () use (&$wc_calls) {
            $wc_calls++;
            if ($wc_calls === 1) {
                return new class {
                    public function get_name() {
                        return 'SYNTH Cours FR';
                    }

                    public function get_parent_id() {
                        return 0;
                    }

                    public function get_id() {
                        return 9201;
                    }
                };
            }
            return new class {
                public function get_name() {
                    return 'SYNTH Course EN';
                }
            };
        };

        $name = intersoccer_roster_catalog_product_name(9201, 0);
        $this->assertSame('SYNTH Course EN', $name);
        $this->assertGreaterThanOrEqual(1, $wc_calls);
    }

    public function test_order_line_name_by_item_id_reads_via_wc_order_factory(): void {
        $GLOBALS['intersoccer_test_wc_order_factory_get_order_item_callback'] = static function ($item_id) {
            if ((int) $item_id !== 93001) {
                return false;
            }
            return new class {
                public function get_name() {
                    return '  SYNTH Line From WC API  ';
                }
            };
        };

        $this->assertSame('SYNTH Line From WC API', intersoccer_roster_order_line_name_by_item_id(93001));
        $this->assertSame('', intersoccer_roster_order_line_name_by_item_id(0));
        $this->assertSame('', intersoccer_roster_order_line_name_by_item_id(93002));
    }
}
