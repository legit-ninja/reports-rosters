<?php
/**
 * Order-line product_name storage: line name as booked; catalogue fallback when empty.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;

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
}
