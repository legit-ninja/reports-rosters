<?php
/**
 * Roster product_name storage: English default-language catalogue title; order line only when product is gone.
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

    private function stub_wpml_en_product_map(array $product_map): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) use ($product_map) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id' && isset($args[0]) && $args[0] === 'product') {
                $id = (int) $value;
                if (isset($product_map[$id])) {
                    return $product_map[$id];
                }
                $lang = $args[2] ?? null;
                if ($lang === 'fr' && in_array($id, $product_map, true)) {
                    return array_search($id, $product_map, true);
                }
                return $id;
            }
            if ($hook === 'wpml_active_languages') {
                return ['en' => [], 'fr' => [], 'de' => []];
            }
            if ($hook === 'wpml_get_element_trid') {
                return null;
            }
            return $value;
        };
    }

    private function stub_english_catalogue_product(int $booked_id, string $booked_title, int $en_parent_id, string $en_title): void {
        $map = [$booked_id => $en_parent_id, $en_parent_id => $en_parent_id];
        if ($booked_id !== $en_parent_id) {
            $map[$en_parent_id] = $en_parent_id;
        }
        $this->stub_wpml_en_product_map($map);
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($booked_id, $booked_title, $en_parent_id, $en_title) {
            $id = (int) $id;
            if ($id === $en_parent_id) {
                return new class ($en_title) {
                    private $title;

                    public function __construct($title) {
                        $this->title = $title;
                    }

                    public function get_name() {
                        return $this->title;
                    }
                };
            }
            if ($id === $booked_id) {
                return new class ($booked_title, $booked_id) {
                    private $title;
                    private $id;

                    public function __construct($title, $id) {
                        $this->title = $title;
                        $this->id = $id;
                    }

                    public function get_name() {
                        return $this->title;
                    }

                    public function get_parent_id() {
                        return 0;
                    }

                    public function get_id() {
                        return $this->id;
                    }
                };
            }
            return false;
        };
    }

    public function test_english_order_stores_english_catalogue_not_line_name(): void {
        $this->stub_english_catalogue_product(9001, 'SYNTH Course A 2025', 9001, 'SYNTH Course A EN Catalogue');
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Course A 2025',
            'product_id' => 9001,
            'variation_id' => 0,
        ]);
        $this->assertSame('SYNTH Course A EN Catalogue', $resolved['product_name']);
        $this->assertTrue($resolved['used_english_catalogue_fallback']);
    }

    public function test_french_order_stores_same_english_name_as_en_booking(): void {
        $this->stub_english_catalogue_product(9103, 'SYNTH Cours C FR', 9003, 'SYNTH Course C EN');
        $fr = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Cours C FR',
            'product_id' => 9103,
            'variation_id' => 0,
        ]);
        $this->stub_english_catalogue_product(9003, 'SYNTH Course C 2025', 9003, 'SYNTH Course C EN');
        $en = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Course C 2025',
            'product_id' => 9003,
            'variation_id' => 0,
        ]);
        $this->assertSame('SYNTH Course C EN', $fr['product_name']);
        $this->assertSame($fr['product_name'], $en['product_name']);
    }

    public function test_deleted_product_falls_back_to_stored_name_before_order_line(): void {
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Line Only',
            'product_id' => 0,
            'variation_id' => 0,
            'stored_product_name' => 'SYNTH Stored Legacy',
        ]);
        $this->assertSame('SYNTH Stored Legacy', $resolved['product_name']);
    }

    public function test_deleted_product_uses_order_line_when_no_catalogue_or_stored_name(): void {
        $resolved = intersoccer_roster_resolve_product_name_for_storage([
            'order_line_name' => 'SYNTH Last Resort Line',
            'product_id' => 0,
            'variation_id' => 0,
        ]);
        $this->assertSame('SYNTH Last Resort Line', $resolved['product_name']);
    }

    public function test_apply_product_name_resolves_english_even_when_line_name_present(): void {
        $this->stub_english_catalogue_product(9101, 'SYNTH FR Line', 9100, 'SYNTH EN Catalogue');
        $row = intersoccer_roster_apply_product_name_for_storage([
            'order_line_name' => 'SYNTH FR Line',
            'product_id' => 9101,
            'variation_id' => 0,
        ]);
        $this->assertSame('SYNTH EN Catalogue', $row['product_name']);
    }

    public function test_apply_product_name_without_order_item_still_resolves_from_product_id(): void {
        $this->stub_english_catalogue_product(9104, 'Ignored', 9104, 'SYNTH Catalogue Only');
        $row = intersoccer_roster_apply_product_name_for_storage([
            'product_id' => 9104,
            'variation_id' => 0,
        ]);
        $this->assertSame('SYNTH Catalogue Only', $row['product_name']);
    }

    public function test_renormalize_resolve_context_uses_english_catalogue_not_line_name(): void {
        $GLOBALS['intersoccer_rr_test_api_counting_enabled'] = true;
        $GLOBALS['intersoccer_rr_test_api_call_counts'] = [];
        $this->stub_english_catalogue_product(9202, 'SYNTH Renorm Line', 9200, 'SYNTH Renorm EN');
        $context = intersoccer_roster_product_name_resolve_context('SYNTH Renorm Line', 9202, 0);
        $resolved = intersoccer_roster_resolve_product_name_for_storage($context);
        $this->assertSame('SYNTH Renorm EN', $resolved['product_name']);
        $this->assertTrue($resolved['used_english_catalogue_fallback']);
    }

    public function test_catalog_product_name_uses_english_helper_for_translated_product(): void {
        $this->stub_english_catalogue_product(9201, 'SYNTH Cours FR', 9201, 'SYNTH Course EN');
        $name = intersoccer_roster_catalog_product_name(9201, 0);
        $this->assertSame('SYNTH Course EN', $name);
    }

    public function test_consolidated_group_key_matches_fr_and_en_product_ids(): void {
        $this->stub_wpml_en_product_map([9103 => 9003, 9003 => 9003]);
        $base = [
            'venue' => 'Geneva',
            'age_group' => '5-12y',
            'times' => '1000-1130',
            'season' => 'Winter 2026',
            'course_day' => 'Monday',
            'girls_only' => 0,
            'start_date' => '2026-01-05',
        ];
        $fr = $base + ['product_id' => 9103, 'product_name' => 'Cours FR'];
        $en = $base + ['product_id' => 9003, 'product_name' => 'Course EN'];
        $this->assertSame(
            intersoccer_consolidated_roster_group_key($fr, 'course'),
            intersoccer_consolidated_roster_group_key($en, 'course')
        );
    }

    public function test_wpml_product_id_variants_includes_canonical_and_mapped_twins(): void {
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id' && isset($args[0]) && $args[0] === 'product') {
                $map = [9103 => 9003, 9003 => 9003];
                return $map[(int) $value] ?? (int) $value;
            }
            if ($hook === 'wpml_active_languages') {
                return ['en' => [], 'fr' => []];
            }
            return $value;
        };
        $variants = intersoccer_roster_wpml_product_id_variants(9103);
        $this->assertContains(9003, $variants);
        $this->assertGreaterThanOrEqual(1, count($variants));
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
