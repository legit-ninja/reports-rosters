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
            $GLOBALS['intersoccer_rr_test_log_order_item_meta_writes']
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
}
