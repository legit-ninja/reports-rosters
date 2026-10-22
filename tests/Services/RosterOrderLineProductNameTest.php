<?php
/**
 * Order-line product_name storage with WPML order-language guard.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class RosterOrderLineProductNameTest extends TestCase {

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
            'order_wpml_language' => 'en',
            'product_id' => 9001,
            'variation_id' => 0,
            'catalog_product_name' => 'SYNTH Course A 2026',
        ]);
        $this->assertSame('SYNTH Course A 2025', $resolved['product_name']);
        $this->assertFalse($resolved['used_english_catalogue_fallback']);
    }

    public function test_unknown_order_language_treated_as_english_uses_line_name(): void {
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Course B 2025',
            'order_wpml_language' => '',
            'product_id' => 9002,
            'variation_id' => 0,
            'catalog_product_name' => 'SYNTH Course B 2026',
        ]);
        $this->assertSame('SYNTH Course B 2025', $resolved['product_name']);
    }

    public function test_french_order_uses_english_catalogue_not_french_line_name(): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$rest) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id') {
                return 9003;
            }
            return $value;
        };
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) {
            if ((int) $id === 9003) {
                return new class {
                    public function get_name() {
                        return 'SYNTH Course C EN';
                    }
                    public function get_parent_id() {
                        return 0;
                    }
                };
            }
            return false;
        };

        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Cours C FR',
            'order_wpml_language' => 'fr',
            'product_id' => 9003,
            'variation_id' => 0,
            'catalog_product_name' => 'SYNTH Cours C FR',
        ]);

        $this->assertSame('SYNTH Course C EN', $resolved['product_name']);
        $this->assertTrue($resolved['used_english_catalogue_fallback']);
        unset($GLOBALS['intersoccer_test_apply_filters_callback'], $GLOBALS['intersoccer_test_wc_get_product_callback']);
    }

    public function test_empty_line_name_falls_back_to_catalogue_for_english_order(): void {
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => '',
            'order_wpml_language' => 'en',
            'product_id' => 9004,
            'variation_id' => 0,
            'catalog_product_name' => 'SYNTH Course D Catalogue',
        ]);
        $this->assertSame('SYNTH Course D Catalogue', $resolved['product_name']);
    }
}
