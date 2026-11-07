<?php
/**
 * Regression: RR #190 — destination-only pa_* keys on roster move when human label exists.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersRr190DestinationPaKeysTest extends TestCase {

    use MovePlayersTestIsolationTrait;

    protected function setUp(): void {
        parent::setUp();
        $this->resetMovePlayersTestGlobals();

        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';

        $this->registerActivityAndBookingTerms();
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_terms_by_id'],
            $GLOBALS['intersoccer_test_terms_by_slug'],
            $GLOBALS['intersoccer_test_terms_by_name'],
            $GLOBALS['intersoccer_test_wpml_current_language'],
            $GLOBALS['intersoccer_test_apply_filters_callback'],
            $GLOBALS['intersoccer_test_wc_get_order_callback'],
            $GLOBALS['intersoccer_test_wc_get_order_id_by_order_item_id_callback'],
            $GLOBALS['intersoccer_rr_test_log_order_item_meta_writes'],
            $GLOBALS['intersoccer_test_wc_get_product_callback']
        );
        $this->resetMovePlayersTestGlobals();
        parent::tearDown();
    }

    /**
     * @param array<string,array<string,object>> $by_slug
     * @param array<int,object>                $by_id
     */
    private function registerTerms(array $by_slug, array $by_id): void {
        $GLOBALS['intersoccer_test_terms_by_slug'] = $by_slug;
        $GLOBALS['intersoccer_test_terms_by_id'] = $by_id;
        $by_name = [];
        foreach ($by_slug as $taxonomy => $terms) {
            foreach ($terms as $row) {
                $obj = is_object($row) ? $row : (object) $row;
                if (!empty($obj->name)) {
                    $key = function_exists('mb_strtolower') ? mb_strtolower((string) $obj->name) : strtolower((string) $obj->name);
                    $by_name[$taxonomy][$key] = $obj;
                }
            }
        }
        $GLOBALS['intersoccer_test_terms_by_name'] = $by_name;
    }

    private function registerActivityAndBookingTerms(): void {
        $this->registerTerms(
            [
                'pa_activity-type' => [
                    'course' => (object) ['term_id' => 99001, 'slug' => 'course', 'name' => 'Course'],
                ],
                'pa_age-group' => [
                    '5-8y' => (object) ['term_id' => 99002, 'slug' => '5-8y', 'name' => '5-8y'],
                ],
            ],
            [
                99001 => (object) ['term_id' => 99001, 'slug' => 'course', 'name' => 'Course'],
                99002 => (object) ['term_id' => 99002, 'slug' => '5-8y', 'name' => '5-8y'],
            ]
        );
    }

    private function makeVariationMock(): Mockery\MockInterface {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_activity-type' => 'course',
            'attribute_pa_age-group' => '5-8y',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        return $variation;
    }

    /**
     * @return array<int,string>
     */
    private function lineKeysWithActivityLabelOnly(): array {
        return [
            'Activity Type',
            'Age Group',
            'pa_age-group',
            'attribute_pa_age-group',
        ];
    }

    public function test_filter_adds_resolved_destination_only_pa_key_when_human_label_on_line(): void {
        $variation = $this->makeVariationMock();
        $existing_pa = ['pa_age-group', 'attribute_pa_age-group'];
        $line_keys = $this->lineKeysWithActivityLabelOnly();
        $slug_meta = [
            'pa_activity-type' => 'course',
            'attribute_pa_activity-type' => 'course',
            'pa_age-group' => '5-8y',
            'attribute_pa_age-group' => '5-8y',
        ];

        $filtered = intersoccer_migration_filter_slug_meta_for_move(
            $slug_meta,
            $existing_pa,
            $variation,
            ['pa_age-group' => '5-8y', 'attribute_pa_age-group' => '5-8y'],
            $line_keys
        );

        $this->assertArrayHasKey('pa_activity-type', $filtered);
        $this->assertSame('course', $filtered['pa_activity-type']);
        $this->assertArrayHasKey('attribute_pa_activity-type', $filtered);
    }

    public function test_filter_skips_unresolved_destination_only_pa_key(): void {
        $variation = $this->makeVariationMock();
        $existing_pa = ['pa_age-group', 'attribute_pa_age-group'];
        $line_keys = $this->lineKeysWithActivityLabelOnly();
        $slug_meta = [
            'pa_activity-type' => 'not-a-real-activity-slug',
            'attribute_pa_activity-type' => 'not-a-real-activity-slug',
            'pa_age-group' => '5-8y',
            'attribute_pa_age-group' => '5-8y',
        ];

        $filtered = intersoccer_migration_filter_slug_meta_for_move(
            $slug_meta,
            $existing_pa,
            $variation,
            ['pa_age-group' => '5-8y', 'attribute_pa_age-group' => '5-8y'],
            $line_keys
        );

        $this->assertArrayNotHasKey('pa_activity-type', $filtered);
        $this->assertArrayNotHasKey('attribute_pa_activity-type', $filtered);
    }

    public function test_normalize_keeps_fr_matin_and_de_ganztags_when_destination_slug_unresolved(): void {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_booking-type' => 'full-week',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $existing_pa = ['pa_booking-type', 'attribute_pa_booking-type'];
        $existing_line_pa = [
            'pa_booking-type' => 'Matin',
            'attribute_pa_booking-type' => 'Matin',
        ];
        $line_keys_fr = ['Type de réservation', 'pa_booking-type', 'attribute_pa_booking-type'];
        $slug_meta_fr = [
            'pa_booking-type' => 'full-week',
            'attribute_pa_booking-type' => 'full-week',
        ];

        $filtered_fr = intersoccer_migration_filter_slug_meta_for_move(
            $slug_meta_fr,
            $existing_pa,
            $variation,
            $existing_line_pa,
            $line_keys_fr
        );

        $this->assertSame('Matin', $filtered_fr['pa_booking-type']);
        $this->assertSame('Matin', $filtered_fr['attribute_pa_booking-type']);

        $existing_line_pa_de = [
            'pa_booking-type' => 'Ganztags',
            'attribute_pa_booking-type' => 'Ganztags',
        ];
        $line_keys_de = ['Buchungstyp', 'pa_booking-type', 'attribute_pa_booking-type'];

        $filtered_de = intersoccer_migration_filter_slug_meta_for_move(
            $slug_meta_fr,
            $existing_pa,
            $variation,
            $existing_line_pa_de,
            $line_keys_de
        );

        $this->assertSame('Ganztags', $filtered_de['pa_booking-type']);
        $this->assertSame('Ganztags', $filtered_de['attribute_pa_booking-type']);
    }

    public function test_order_language_strip_adds_resolved_and_skips_unresolved_destination_keys(): void {
        $existing_pa = ['pa_age-group', 'attribute_pa_age-group'];
        $line_keys = $this->lineKeysWithActivityLabelOnly();

        $resolved_strip = intersoccer_migration_strip_slug_meta_to_allowed_pa_keys_for_move(
            [
                'pa_activity-type' => 'course',
                'attribute_pa_activity-type' => 'course',
            ],
            $existing_pa,
            $line_keys
        );

        $this->assertArrayHasKey('pa_activity-type', $resolved_strip);
        $this->assertArrayHasKey('attribute_pa_activity-type', $resolved_strip);

        $unresolved_strip = intersoccer_migration_strip_slug_meta_to_allowed_pa_keys_for_move(
            [
                'pa_activity-type' => 'missing-activity-term',
                'attribute_pa_activity-type' => 'missing-activity-term',
            ],
            $existing_pa,
            $line_keys
        );

        $this->assertSame([], $unresolved_strip);
    }

    public function test_finalize_move_order_language_never_writes_unresolved_destination_canton_pa(): void {
        $item_id = 99190;
        $order_id = 99180;
        $GLOBALS['intersoccer_rr_test_log_order_item_meta_writes'] = [];

        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }

            return $value;
        };
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $GLOBALS['intersoccer_test_wc_get_order_id_by_order_item_id_callback'] = static function () use ($order_id) {
            return $order_id;
        };
        $GLOBALS['intersoccer_test_wc_get_order_callback'] = static function ($oid) use ($order_id) {
            if ((int) $oid !== $order_id) {
                return false;
            }
            $order = new \WC_Order($order_id);
            $order->update_meta_data('wpml_language', 'fr');

            return $order;
        };

        $existing_line_keys = [
            'Canton / Région',
            'Age Group',
            'pa_age-group',
            'attribute_pa_age-group',
        ];
        global $intersoccer_test_wc_order_item_meta;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'Canton / Région' => 'Vaud',
            'Age Group' => '5-8y',
            'pa_age-group' => '5-8y',
            'attribute_pa_age-group' => '5-8y',
            'wpml_language' => 'fr',
        ];

        $unresolved_canton_slug = 'dest-canton-unregistered';
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_activity-type' => 'course',
            'attribute_pa_age-group' => '5-8y',
            'attribute_pa_canton-region' => $unresolved_canton_slug,
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);
        $variation->shouldReceive('get_id')->andReturn(99191);

        $human_alias_map = intersoccer_migration_human_alias_map();
        $human_lookup = intersoccer_migration_build_lookup($human_alias_map);
        $slug_meta = [
            'pa_age-group' => '5-8y',
            'attribute_pa_age-group' => '5-8y',
            'pa_canton-region' => $unresolved_canton_slug,
            'attribute_pa_canton-region' => $unresolved_canton_slug,
        ];
        $human_meta = [
            'canton_region' => 'Vaud',
            'age_group' => '5-8y',
        ];

        intersoccer_migration_finalize_move_item_meta(
            $item_id,
            $human_alias_map,
            $human_lookup,
            $human_meta,
            [],
            $slug_meta,
            $existing_line_keys,
            $variation,
            [],
            null,
            true
        );

        $item = new \WC_Order_Item_Product($item_id);
        $this->assertSame('', $item->get_meta('pa_canton-region', true));
        $this->assertSame('', $item->get_meta('attribute_pa_canton-region', true));

        $writes = $GLOBALS['intersoccer_rr_test_log_order_item_meta_writes'] ?? [];
        foreach ($writes as $write) {
            if ((int) ($write['item_id'] ?? 0) !== $item_id) {
                continue;
            }
            $key = (string) ($write['key'] ?? '');
            $this->assertNotSame(
                'pa_canton-region',
                $key,
                'pa_canton-region must never be written when destination slug does not strict-resolve'
            );
            $this->assertNotSame(
                'attribute_pa_canton-region',
                $key,
                'attribute_pa_canton-region must never be written when destination slug does not strict-resolve'
            );
        }
    }

    public function test_order_line_normalize_keeps_variation_slugs_when_get_attribute_returns_labels(): void {
        $this->registerCampAndCityTerms();
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_activity-type' => 'camp',
            'attribute_pa_city' => 'zurich',
        ]);
        $variation->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            if ($name === 'pa_activity-type') {
                return 'Camp';
            }
            if ($name === 'pa_city') {
                return 'Zurich';
            }

            return '';
        });
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation(
            [
                'pa_activity-type' => 'camp',
                'attribute_pa_activity-type' => 'camp',
                'pa_city' => 'zurich',
                'attribute_pa_city' => 'zurich',
                'Attendee DOB' => '2014-01-02',
                'Attendee Gender' => 'female',
                'Medical Conditions' => 'none-recorded',
            ],
            $variation,
            [],
            true
        );

        $this->assertSame('camp', $normalized['pa_activity-type']);
        $this->assertSame('camp', $normalized['attribute_pa_activity-type']);
        $this->assertSame('zurich', $normalized['pa_city']);
        $this->assertSame('zurich', $normalized['attribute_pa_city']);
        $this->assertNotSame('Camp', $normalized['pa_activity-type']);
        $this->assertNotSame('Zurich', $normalized['pa_city']);
        $this->assertSame('2014-01-02', $normalized['Attendee DOB']);
        $this->assertSame('female', $normalized['Attendee Gender']);
        $this->assertSame('none-recorded', $normalized['Medical Conditions']);
    }

    public function test_order_line_normalize_resolves_parent_display_labels_when_variation_omits_taxonomies(): void {
        $this->registerCampAndCityTerms();
        $parent_id = 27150;
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_age-group' => '5-8y',
        ]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn($parent_id);
        $variation->shouldReceive('get_id')->andReturn(27152);

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            if ($name === 'pa_activity-type') {
                return 'Camp';
            }
            if ($name === 'pa_city') {
                return 'Zurich';
            }

            return '';
        });
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($parent, $parent_id) {
            return (int) $id === $parent_id ? $parent : false;
        };

        $existing_line_pa = [
            'pa_age-group' => '5-8y',
            'attribute_pa_age-group' => '5-8y',
            'pa_city' => 'Zurich',
            'attribute_pa_city' => 'Zurich',
        ];
        $normalized = intersoccer_migration_normalize_slug_meta_for_variation(
            [
                'pa_activity-type' => 'Camp',
                'attribute_pa_activity-type' => 'Camp',
                'pa_city' => 'Zurich',
                'attribute_pa_city' => 'Zurich',
                'pa_age-group' => '5-8y',
                'attribute_pa_age-group' => '5-8y',
            ],
            $variation,
            $existing_line_pa,
            true
        );

        $this->assertSame('camp', $normalized['pa_activity-type']);
        $this->assertSame('camp', $normalized['attribute_pa_activity-type']);
        $this->assertSame('Zurich', $normalized['pa_city']);
        $this->assertSame('Zurich', $normalized['attribute_pa_city']);
        $this->assertSame('5-8y', $normalized['pa_age-group']);
        $this->assertSame('5-8y', $normalized['attribute_pa_age-group']);
    }

    public function test_order_line_normalize_does_not_invent_slug_for_unresolved_parent_label(): void {
        $this->registerCampAndCityTerms();
        $parent_id = 27151;
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn($parent_id);

        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            if ($name === 'pa_city') {
                return 'Not A Real City';
            }

            return '';
        });
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($parent, $parent_id) {
            return (int) $id === $parent_id ? $parent : false;
        };

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation(
            [
                'pa_city' => 'Not A Real City',
                'attribute_pa_city' => 'Not A Real City',
            ],
            $variation,
            [],
            true
        );

        $this->assertSame('Not A Real City', $normalized['pa_city']);
        $this->assertNotSame('not-a-real-city', $normalized['pa_city']);

        $stripped = intersoccer_migration_strip_slug_meta_to_allowed_pa_keys_for_move(
            $normalized,
            [],
            ['City', 'Activity Type']
        );
        $this->assertArrayNotHasKey('pa_city', $stripped);
        $this->assertArrayNotHasKey('attribute_pa_city', $stripped);
    }

    public function test_order_language_facet_stores_slug_when_parent_catalog_value_is_display_label(): void {
        $this->registerCampAndCityTerms();
        $parent_id = 27153;
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn($parent_id);
        $variation->shouldReceive('get_id')->andReturn(27152);

        $parent = $this->catalogParent($parent_id, ['pa_activity-type' => 'Camp']);
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($parent, $parent_id) {
            return (int) $id === $parent_id ? $parent : false;
        };
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$ignored) {
            unset($ignored);
            if ($hook === 'wpml_default_language') {
                return 'en';
            }

            return $value;
        };

        [, $slug_meta] = intersoccer_migration_apply_order_language_pa_facet_meta_for_order_line(
            [],
            [],
            [],
            $variation,
            [],
            'fr'
        );

        $this->assertSame('camp', $slug_meta['pa_activity-type'] ?? null);
        $this->assertSame('camp', $slug_meta['attribute_pa_activity-type'] ?? null);
        $this->assertNotSame('Camp', $slug_meta['pa_activity-type'] ?? null);
    }

    public function test_order_line_normalize_keeps_order_language_slug_instead_of_english_slug(): void {
        $this->registerTerms(
            [
                'pa_city' => [
                    'geneva' => (object) ['term_id' => 99201, 'slug' => 'geneva', 'name' => 'Geneva'],
                    'geneve-ville' => (object) ['term_id' => 99202, 'slug' => 'geneve-ville', 'name' => 'Genève'],
                ],
            ],
            [
                99201 => (object) ['term_id' => 99201, 'slug' => 'geneva', 'name' => 'Geneva'],
                99202 => (object) ['term_id' => 99202, 'slug' => 'geneve-ville', 'name' => 'Genève'],
            ]
        );
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([
            'attribute_pa_city' => 'geneve-ville',
        ]);
        $variation->shouldReceive('get_attribute')->with('pa_city')->andReturn('Genève');
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(0);

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation(
            [
                'pa_city' => 'geneve-ville',
                'attribute_pa_city' => 'geneve-ville',
            ],
            $variation,
            [],
            true
        );

        $this->assertSame('geneve-ville', $normalized['pa_city']);
        $this->assertSame('geneve-ville', $normalized['attribute_pa_city']);
        $this->assertNotSame('geneva', $normalized['pa_city']);
    }

    public function test_order_language_facet_maps_display_label_to_order_language_slug(): void {
        $this->registerTerms(
            [
                'pa_city' => [
                    'zurich' => (object) ['term_id' => 99211, 'slug' => 'zurich', 'name' => 'Zurich'],
                    'zuerich' => (object) ['term_id' => 99212, 'slug' => 'zuerich', 'name' => 'Zürich'],
                ],
            ],
            [
                99211 => (object) ['term_id' => 99211, 'slug' => 'zurich', 'name' => 'Zurich'],
                99212 => (object) ['term_id' => 99212, 'slug' => 'zuerich', 'name' => 'Zürich'],
            ]
        );
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn(27154);
        $variation->shouldReceive('get_id')->andReturn(27152);
        $parent = $this->catalogParent(27154, ['pa_city' => 'Zurich']);
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($parent) {
            return (int) $id === 27154 ? $parent : false;
        };
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) {
            if ($hook === 'wpml_default_language') {
                return 'en';
            }
            if ($hook === 'wpml_object_id' && (int) $value === 99211 && ($args[2] ?? '') === 'fr') {
                return 99212;
            }

            return $value;
        };

        [, $slug_meta] = intersoccer_migration_apply_order_language_pa_facet_meta_for_order_line(
            [],
            [],
            [],
            $variation,
            [],
            'fr'
        );

        $this->assertSame('zuerich', $slug_meta['pa_city'] ?? null);
        $this->assertSame('zuerich', $slug_meta['attribute_pa_city'] ?? null);
        $this->assertNotSame('zurich', $slug_meta['pa_city'] ?? null);
        $this->assertNotSame('Zurich', $slug_meta['pa_city'] ?? null);
    }

    public function test_parent_multi_name_label_is_not_split_or_guessed(): void {
        $this->registerCampAndCityTerms();
        $parent_id = 27155;
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_variation_attributes')->andReturn([]);
        $variation->shouldReceive('get_attribute')->andReturn('');
        $variation->shouldReceive('get_parent_id')->andReturn($parent_id);
        $parent = Mockery::mock('WC_Product');
        $parent->shouldReceive('get_attribute')->andReturnUsing(static function ($name) {
            return $name === 'pa_city' ? 'Zurich, Geneva' : '';
        });
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($parent, $parent_id) {
            return (int) $id === $parent_id ? $parent : false;
        };

        $normalized = intersoccer_migration_normalize_slug_meta_for_variation(
            [
                'pa_city' => 'Zurich, Geneva',
                'attribute_pa_city' => 'Zurich, Geneva',
            ],
            $variation,
            [],
            true
        );

        $this->assertSame('Zurich, Geneva', $normalized['pa_city']);
        $this->assertSame('Zurich, Geneva', $normalized['attribute_pa_city']);
        $stripped = intersoccer_migration_strip_slug_meta_to_allowed_pa_keys_for_move(
            $normalized,
            [],
            ['City']
        );
        $this->assertArrayNotHasKey('pa_city', $stripped);
        $this->assertArrayNotHasKey('attribute_pa_city', $stripped);
    }

    /**
     * Harness WC_Product has no get_attribute(), so Mockery doubles fail method_exists().
     *
     * @param array<string,string> $attributes
     */
    private function catalogParent(int $product_id, array $attributes): \WC_Product {
        return new class($product_id, $attributes) extends \WC_Product {
            /** @var array<string,string> */
            private $catalogAttributes;

            public function __construct($product_id = 0, array $attributes = []) {
                parent::__construct($product_id);
                $this->catalogAttributes = $attributes;
            }

            public function get_attribute($name) {
                return $this->catalogAttributes[(string) $name] ?? '';
            }
        };
    }

    private function registerCampAndCityTerms(): void {
        $this->registerTerms(
            [
                'pa_activity-type' => [
                    'camp' => (object) ['term_id' => 99101, 'slug' => 'camp', 'name' => 'Camp'],
                    'course' => (object) ['term_id' => 99001, 'slug' => 'course', 'name' => 'Course'],
                ],
                'pa_city' => [
                    'zurich' => (object) ['term_id' => 99102, 'slug' => 'zurich', 'name' => 'Zurich'],
                ],
                'pa_age-group' => [
                    '5-8y' => (object) ['term_id' => 99002, 'slug' => '5-8y', 'name' => '5-8y'],
                ],
            ],
            [
                99101 => (object) ['term_id' => 99101, 'slug' => 'camp', 'name' => 'Camp'],
                99001 => (object) ['term_id' => 99001, 'slug' => 'course', 'name' => 'Course'],
                99102 => (object) ['term_id' => 99102, 'slug' => 'zurich', 'name' => 'Zurich'],
                99002 => (object) ['term_id' => 99002, 'slug' => '5-8y', 'name' => '5-8y'],
            ]
        );
    }
}
