<?php
/**
 * Roster move utils: NULL storage, skip-unchanged meta saves, duplicate renormalize guard, term cache.
 *
 * @package InterSoccer_Reports_Rosters
 */

namespace InterSoccer\ReportsRosters\Tests\Legacy;

use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

class MovePlayersUtilsSpeedNullTest extends TestCase {

    private const ROSTER_ID = 701;
    private const ITEM_ID = 77050;
    private const VAR_A = 77060;
    private const VAR_B = 77070;

    protected function setUp(): void {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';
        require_once dirname(__DIR__, 2) . '/includes/roster-move-reason-messages.php';
        $GLOBALS['intersoccer_test_get_term_by_call_count'] = 0;
        if (function_exists('intersoccer_reset_get_term_by_request_cache')) {
            intersoccer_reset_get_term_by_request_cache();
        }
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_wc_order_item_save_count'],
            $GLOBALS['intersoccer_test_get_term_by_call_count'],
            $GLOBALS['intersoccer_test_roster_store'],
            $GLOBALS['intersoccer_rr_roster_facet_rebuild_guard']
        );
        parent::tearDown();
    }

    public function test_empty_selected_days_preserves_stored_days_selected_when_non_empty(): void {
        $update = intersoccer_build_roster_booking_db_update(
            [
                'booking_type' => 'Full Term',
                'selected_days' => '',
            ],
            ['days_selected' => 'Monday', 'selected_days' => null]
        );
        $this->assertSame('Monday', $update['days_selected']);
        $this->assertNull($update['selected_days']);
    }

    public function test_nullable_storage_string_empty_with_non_empty_stored_returns_empty_string(): void {
        $this->assertSame('', intersoccer_roster_nullable_storage_string('', 'Monday', true));
        $this->assertNull(intersoccer_roster_nullable_storage_string('', null, true));
        $this->assertNull(intersoccer_roster_nullable_storage_string('', null, false));
    }

    public function test_build_roster_booking_db_update_clears_selected_days_but_keeps_days_selected_legacy(): void {
        $update = intersoccer_build_roster_booking_db_update(
            [
                'booking_type' => 'Full Term',
                'selected_days' => '',
            ],
            ['selected_days' => 'Monday', 'days_selected' => 'Monday']
        );
        $this->assertSame('', $update['selected_days']);
        $this->assertSame('Monday', $update['days_selected']);
    }

    public function test_facet_update_keeps_stored_camp_terms_when_normalized_empty(): void {
        $update = intersoccer_build_roster_facet_db_update(
            ['camp_terms' => '', 'activity_type' => 'Course'],
            ['camp_terms' => 'Week 1'],
            'Course EN'
        );
        $this->assertSame('Week 1', $update['camp_terms']);
    }

    public function test_renormalize_order_item_booking_meta_skips_save_when_unchanged(): void {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_save_count;

        $item_id = 88001;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'Booking Type' => 'Full Term',
            'Days Selected' => 'Monday',
        ];
        $intersoccer_test_wc_order_item_save_count = 0;

        $ok = intersoccer_renormalize_order_item_booking_meta($item_id, [
            'booking_type' => 'Full Term',
            'selected_days' => 'Monday',
            'late_pickup_days' => '',
        ]);

        $this->assertFalse($ok, '#104 booking repair returns false with no save when meta is unchanged.');
        $this->assertSame(0, $intersoccer_test_wc_order_item_save_count);
    }

    public function test_renormalize_order_item_booking_meta_keeps_late_pickup_when_target_empty(): void {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_save_count;

        $item_id = 88002;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'Booking Type' => 'Full Term',
            'Late Pickup Days' => 'Monday',
        ];
        $intersoccer_test_wc_order_item_save_count = 0;

        intersoccer_renormalize_order_item_booking_meta($item_id, [
            'booking_type' => 'Full Term',
            'selected_days' => '',
            'late_pickup_days' => '',
        ]);

        $this->assertSame(0, $intersoccer_test_wc_order_item_save_count);
        $this->assertSame('Monday', $intersoccer_test_wc_order_item_meta[$item_id]['Late Pickup Days']);
    }

    public function test_renormalize_order_item_booking_meta_saves_when_alias_key_present(): void {
        global $intersoccer_test_wc_order_item_meta, $intersoccer_test_wc_order_item_save_count;

        $item_id = 88003;
        $intersoccer_test_wc_order_item_meta[$item_id] = [
            'Booking Type' => 'Full Term',
            'Type de réservation' => 'Trimestre complet',
        ];
        $intersoccer_test_wc_order_item_save_count = 0;

        intersoccer_renormalize_order_item_booking_meta($item_id, [
            'booking_type' => 'Full Term',
            'selected_days' => '',
            'late_pickup_days' => '',
        ]);

        $this->assertSame(1, $intersoccer_test_wc_order_item_save_count);
        // #104 updates the existing FR row in place (no key added or removed).
        $this->assertSame('Full Term', $intersoccer_test_wc_order_item_meta[$item_id]['Type de réservation']);
        $this->assertSame('Full Term', $intersoccer_test_wc_order_item_meta[$item_id]['Booking Type']);
    }

    public function test_renormalize_row_from_variation_skips_second_identical_rebuild_in_request(): void {
        $this->installFacetRebuildWorld($this->facetRowForVariation(self::VAR_A));
        global $wpdb;
        $update_calls = 0;
        $wpdb->shouldReceive('update')->andReturnUsing(static function ($table, $data, $where) use (&$update_calls) {
            $update_calls++;
            $id = (int) ($where['id'] ?? 0);
            if ($id > 0 && isset($GLOBALS['intersoccer_test_roster_store'][$id])) {
                $GLOBALS['intersoccer_test_roster_store'][$id] = array_merge(
                    $GLOBALS['intersoccer_test_roster_store'][$id],
                    $data
                );
            }
            return 1;
        });

        $this->assertTrue(intersoccer_renormalize_roster_row_from_variation(self::ROSTER_ID));
        $this->assertSame(1, $update_calls);
        $this->assertTrue(intersoccer_renormalize_roster_row_from_variation(self::ROSTER_ID));
        $this->assertSame(1, $update_calls);
    }

    public function test_renormalize_row_from_variation_rebuilds_after_variation_change(): void {
        $this->installFacetRebuildWorld($this->facetRowForVariation(self::VAR_A));
        global $wpdb;
        $update_calls = 0;
        $wpdb->shouldReceive('update')->andReturnUsing(static function ($table, $data, $where) use (&$update_calls) {
            $update_calls++;
            $id = (int) ($where['id'] ?? 0);
            if ($id > 0 && isset($GLOBALS['intersoccer_test_roster_store'][$id])) {
                $GLOBALS['intersoccer_test_roster_store'][$id] = array_merge(
                    $GLOBALS['intersoccer_test_roster_store'][$id],
                    $data
                );
            }
            return 1;
        });

        $this->assertTrue(intersoccer_renormalize_roster_row_from_variation(self::ROSTER_ID));
        $this->assertSame(1, $update_calls);

        $GLOBALS['intersoccer_test_roster_store'][self::ROSTER_ID]['variation_id'] = self::VAR_B;
        $GLOBALS['intersoccer_test_roster_store'][self::ROSTER_ID]['event_signature'] = 'old-sig';

        $this->assertTrue(intersoccer_renormalize_roster_row_from_variation(self::ROSTER_ID));
        $this->assertSame(2, $update_calls);
    }

    public function test_rebuild_event_signature_skips_facet_update_when_row_already_matches_manual_update(): void {
        $this->installFacetRebuildWorld($this->facetRowForVariation(self::VAR_A));
        global $wpdb;
        $update_calls = 0;
        $wpdb->shouldReceive('update')->andReturnUsing(static function ($table, $data, $where) use (&$update_calls) {
            $update_calls++;
            $id = (int) ($where['id'] ?? 0);
            if ($id > 0 && isset($GLOBALS['intersoccer_test_roster_store'][$id])) {
                $GLOBALS['intersoccer_test_roster_store'][$id] = array_merge(
                    $GLOBALS['intersoccer_test_roster_store'][$id],
                    $data
                );
            }
            return 1;
        });

        $this->assertTrue(intersoccer_renormalize_roster_row_from_variation(self::ROSTER_ID));
        $this->assertSame(1, $update_calls);

        $this->assertTrue(intersoccer_rebuild_event_signature_for_order_item(self::ITEM_ID));
        $this->assertSame(1, $update_calls);
    }

    public function test_get_term_by_cached_avoids_repeat_get_term_by_calls(): void {
        $GLOBALS['intersoccer_test_terms_by_slug']['pa_city'] = [
            'geneva' => (object) ['term_id' => 1, 'slug' => 'geneva', 'name' => 'Geneva'],
        ];
        $GLOBALS['intersoccer_test_get_term_by_call_count'] = 0;

        $first = intersoccer_get_term_by_cached('slug', 'geneva', 'pa_city');
        $second = intersoccer_get_term_by_cached('slug', 'geneva', 'pa_city');
        $other = intersoccer_get_term_by_cached('slug', 'zurich', 'pa_city');

        $this->assertNotFalse($first);
        $this->assertSame($first, $second);
        $this->assertFalse($other);
        $this->assertSame(2, $GLOBALS['intersoccer_test_get_term_by_call_count']);
    }

    public function test_get_term_by_cached_misses_when_wpml_current_language_changes(): void {
        $GLOBALS['intersoccer_test_terms_by_slug']['pa_city'] = [
            'geneva' => (object) ['term_id' => 1, 'slug' => 'geneva', 'name' => 'Geneva'],
        ];
        $GLOBALS['intersoccer_test_get_term_by_call_count'] = 0;

        $language = 'en';
        $GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value) use (&$language) {
            if ($hook === 'wpml_current_language') {
                return $language;
            }
            return $value;
        };

        try {
            $this->assertNotFalse(intersoccer_get_term_by_cached('slug', 'geneva', 'pa_city'));
            $this->assertSame(1, $GLOBALS['intersoccer_test_get_term_by_call_count']);
            $this->assertNotFalse(intersoccer_get_term_by_cached('slug', 'geneva', 'pa_city'));
            $this->assertSame(1, $GLOBALS['intersoccer_test_get_term_by_call_count']);

            $language = 'fr';
            $this->assertNotFalse(intersoccer_get_term_by_cached('slug', 'geneva', 'pa_city'));
            $this->assertSame(2, $GLOBALS['intersoccer_test_get_term_by_call_count']);
        } finally {
            unset($GLOBALS['intersoccer_test_apply_filters_callback']);
        }
    }

    public function test_roster_move_l10n_and_js_move_flow(): void {
        $strings = intersoccer_roster_move_js_localize_strings();
        $this->assertArrayHasKey('moving_players', $strings);
        $this->assertArrayHasKey('ajax_timeout', $strings);
        $this->assertStringContainsString('Refresh the page', $strings['ajax_timeout']);

        $handler = $this->extractRosterMoveClickHandler();
        $this->assertStringContainsString("setRosterMoveApplyBusy('moving_players')", $handler);
        $this->assertStringContainsString('showMovingImmediately', $handler);
        $this->assertStringContainsString("rosterMoveL10n('ajax_timeout')", $handler);
        $this->assertStringContainsString('complete:', $handler);

        $details = file_get_contents(dirname(__DIR__, 2) . '/includes/roster-details.php');
        $this->assertStringContainsString('roster-move-apply-label', $details);
    }

    /**
     * @return array<string,mixed>
     */
    private function facetRowForVariation(int $variation_id): array {
        return [
            'id' => self::ROSTER_ID,
            'order_item_id' => self::ITEM_ID,
            'variation_id' => $variation_id,
            'product_id' => 77000,
            'activity_type' => 'Course',
            'venue' => 'Venue EN',
            'age_group' => '6-8',
            'camp_terms' => null,
            'course_day' => '',
            'times' => 'Morning',
            'season' => '2026',
            'city' => 'Geneva',
            'canton_region' => 'Geneva',
            'girls_only' => 0,
            'booking_type' => 'Full Term',
            'selected_days' => null,
            'days_selected' => null,
            'event_signature' => 'sig-' . $variation_id,
            'product_name' => 'Course EN',
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function installFacetRebuildWorld(array $row): void {
        global $wpdb, $intersoccer_test_wc_get_product_callback;

        $GLOBALS['intersoccer_test_roster_store'] = [
            self::ROSTER_ID => $row,
        ];
        $GLOBALS['intersoccer_rr_roster_facet_rebuild_guard'] = [];

        $make_variation = static function (int $vid) {
            $v = Mockery::mock(\WC_Product_Variation::class);
            $v->shouldReceive('get_id')->andReturn($vid);
            $v->shouldReceive('get_parent_id')->andReturn(77000);
            $v->shouldReceive('get_variation_attributes')->andReturn([
                'attribute_pa_intersoccer-venues' => 'venue-en',
                'attribute_pa_age-group' => '6-8',
                'attribute_pa_course-times' => 'morning',
            ]);
            $v->shouldReceive('get_name')->andReturn('Variation ' . $vid);
            return $v;
        };

        $intersoccer_test_wc_get_product_callback = static function ($id) use ($make_variation) {
            if ((int) $id === self::VAR_A || (int) $id === self::VAR_B) {
                return $make_variation((int) $id);
            }
            return null;
        };

        $wpdb = Mockery::mock($wpdb);
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($query) {
            return (string) $query;
        });
        $wpdb->shouldReceive('get_row')->andReturnUsing(static function ($sql) {
            if (!is_string($sql) || strpos($sql, 'intersoccer_rosters') === false) {
                return null;
            }
            if (strpos($sql, 'order_item_id') !== false) {
                return $GLOBALS['intersoccer_test_roster_store'][self::ROSTER_ID] ?? null;
            }
            if (strpos($sql, 'WHERE id =') !== false) {
                return $GLOBALS['intersoccer_test_roster_store'][self::ROSTER_ID] ?? null;
            }
            return null;
        });
    }

    private function extractRosterMoveClickHandler(): string {
        $path = dirname(__DIR__, 2) . '/includes/roster-details.php';
        $source = file_get_contents($path);
        $this->assertIsString($source);

        if (!preg_match("/applyBulk\\.on\\('click',\\s*function\\(\\)\\s*\\{(.+?)\\n\\s*\\}\\);\\s*\\n\\s*\\/\\/ Add keyboard shortcuts/s", $source, $matches)) {
            $this->fail('Could not extract roster move click handler from roster-details.php');
        }

        return $matches[1];
    }
}
