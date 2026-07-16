<?php
/**
 * Order meta field map and SQL key candidate tests.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;

class OrderMetaFieldMapTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (file_exists(dirname(__DIR__, 2) . '/includes/order-meta-keys.php')) {
            require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        }
    }

    public function test_field_map_includes_attendee_and_player_keys() {
        if (!function_exists('intersoccer_get_order_meta_field_map')) {
            $this->markTestSkipped('order-meta-keys not loaded');
        }

        $map = intersoccer_get_order_meta_field_map();
        $this->assertSame('attendee_dob', $map['Attendee DOB']);
        $this->assertSame('attendee_gender', $map['Attendee Gender']);
        $this->assertSame('medical_conditions', $map['Medical Conditions']);
        $this->assertSame('player_index', $map['assigned_player']);
        $this->assertSame('player_id', $map['assigned_player_id']);
    }

    public function test_get_order_item_meta_field_value_prefers_attendee_gender() {
        if (!function_exists('intersoccer_get_order_item_meta_field_value')) {
            $this->markTestSkipped('meta field helper not loaded');
        }

        $meta = [
            'Player Gender' => 'Male',
            'Attendee Gender' => 'Female',
        ];
        $this->assertSame('Female', intersoccer_get_order_item_meta_field_value($meta, 'attendee_gender', 'N/A'));
    }

    public function test_get_order_item_meta_field_value_falls_back_to_player_gender() {
        if (!function_exists('intersoccer_get_order_item_meta_field_value')) {
            $this->markTestSkipped('meta field helper not loaded');
        }

        $meta = ['Player Gender' => 'Male'];
        $this->assertSame('Male', intersoccer_get_order_item_meta_field_value($meta, 'attendee_gender', 'N/A'));
    }

    public function test_normalize_attendee_gender_aliases() {
        if (!function_exists('intersoccer_normalize_order_item_meta_key')) {
            $this->markTestSkipped('normalize helper not loaded');
        }

        $this->assertSame('Attendee Gender', intersoccer_normalize_order_item_meta_key('gender'));
        $this->assertSame('Attendee Gender', intersoccer_normalize_order_item_meta_key('Genre du participant'));
    }

    public function test_sql_meta_key_candidates_for_reports() {
        if (!function_exists('intersoccer_reports_sql_meta_key_candidates')) {
            $this->markTestSkipped('sql meta helper not loaded');
        }

        $this->assertSame(['Days Selected', 'Days of Week'], intersoccer_reports_sql_meta_key_candidates('selected_days'));
        $this->assertSame(['Attendee Gender', 'gender', 'Player Gender'], intersoccer_reports_sql_meta_key_candidates('attendee_gender'));
        $this->assertContains('Discount', intersoccer_reports_sql_meta_key_candidates('discount_applied'));
    }

    public function test_manual_aliases_derived_from_registry() {
        if (!function_exists('intersoccer_get_order_meta_manual_aliases')
            || !function_exists('intersoccer_reports_attr_legacy_order_meta_labels')
            || !function_exists('intersoccer_reports_attr_canonical_label_to_slug_map')) {
            $this->markTestSkipped('alias functions not loaded');
        }

        $aliases = intersoccer_get_order_meta_manual_aliases();
        $label_to_slug = intersoccer_reports_attr_canonical_label_to_slug_map();

        foreach ($aliases as $canonical => $alias_list) {
            if (!isset($label_to_slug[$canonical])) {
                continue;
            }
            $slug = $label_to_slug[$canonical];
            $registry_labels = intersoccer_reports_attr_legacy_order_meta_labels($slug);
            $this->assertNotEmpty(
                $registry_labels,
                "Registry should have legacy labels for $canonical (slug: $slug)"
            );
        }
    }

    public function test_no_alias_drift_between_registry_and_manual_map() {
        if (!function_exists('intersoccer_get_order_meta_manual_aliases_from_registry')
            || !function_exists('intersoccer_get_order_meta_manual_aliases')) {
            $this->markTestSkipped('alias functions not loaded');
        }

        $registry_aliases = intersoccer_get_order_meta_manual_aliases_from_registry();
        $all_aliases = intersoccer_get_order_meta_manual_aliases();

        foreach ($registry_aliases as $canonical => $derived) {
            $this->assertArrayHasKey(
                $canonical,
                $all_aliases,
                "Registry canonical '$canonical' must appear in combined aliases"
            );
            foreach ($derived as $alias) {
                $this->assertContains(
                    $alias,
                    $all_aliases[$canonical],
                    "Registry-derived alias '$alias' for '$canonical' must be in combined aliases"
                );
            }
        }
    }

    public function test_french_venue_alias_resolves_to_canonical() {
        if (!function_exists('intersoccer_normalize_order_item_meta_key')) {
            $this->markTestSkipped('normalize helper not loaded');
        }

        $this->assertSame(
            'Sites InterSoccer',
            intersoccer_normalize_order_item_meta_key('Lieux InterSoccer')
        );
    }

    public function test_german_booking_type_alias_resolves() {
        if (!function_exists('intersoccer_normalize_order_item_meta_key')) {
            $this->markTestSkipped('normalize helper not loaded');
        }

        $this->assertSame(
            'Booking Type',
            intersoccer_normalize_order_item_meta_key('Buchungstyp')
        );
    }
}
