<?php
/**
 * Alias resolution regression tests.
 *
 * Ensures all known historical meta key variants resolve to the correct
 * canonical field, and that migration-meta.php and order-meta-keys.php
 * alias sets remain consistent.
 */

namespace InterSoccer\ReportsRosters\Tests\Integration;

use InterSoccer\ReportsRosters\Tests\TestCase;

class AliasResolutionRegressionTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        if (file_exists(dirname(__DIR__, 2) . '/includes/attribute-contract.php')) {
            require_once dirname(__DIR__, 2) . '/includes/attribute-contract.php';
        }
        if (file_exists(dirname(__DIR__, 2) . '/includes/order-meta-keys.php')) {
            require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        }
        if (file_exists(dirname(__DIR__, 2) . '/includes/migration-meta.php')) {
            require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        }
    }

    /**
     * Catalog of ~50 real-world meta key variants observed in production orders,
     * including accented, accent-stripped, and mixed-case forms.
     *
     * @return array<array{string, string}> [raw_key, expected_canonical]
     */
    public static function historicalMetaKeyProvider(): array {
        return [
            // --- Venues ---
            ['Sites InterSoccer', 'Sites InterSoccer'],
            ['InterSoccer Venues', 'Sites InterSoccer'],
            ['Lieux InterSoccer', 'Sites InterSoccer'],
            ['Lieu InterSoccer', 'Sites InterSoccer'],
            ['InterSoccer-Standorte', 'Sites InterSoccer'],
            ['lieux intersoccer', 'Sites InterSoccer'],

            // --- Age Group ---
            ['Age Group', 'Age Group'],
            ["Groupe d'âge", 'Age Group'],
            ['Groupe dage', 'Age Group'],
            ['groupe dage', 'Age Group'],
            ['Altersgruppe', 'Age Group'],

            // --- Activity Type ---
            ['Activity Type', 'Activity Type'],
            ["Type d'activité", 'Activity Type'],
            ["Type d'activite", 'Activity Type'],
            ['Aktivitätstyp', 'Activity Type'],
            ['aktivitatstyp', 'Activity Type'],

            // --- Booking Type ---
            ['Booking Type', 'Booking Type'],
            ['Type de réservation', 'Booking Type'],
            ['Buchungstyp', 'Booking Type'],
            ['buchungstyp', 'Booking Type'],

            // --- Season ---
            ['Season', 'Season'],
            ['Saison', 'Season'],
            ['Jahreszeit', 'Season'],
            ['saison', 'Season'],

            // --- Canton / Region ---
            ['Canton / Region', 'Canton / Region'],
            ['Canton / Région', 'Canton / Region'],
            ['Canton Region', 'Canton / Region'],
            ['Kanton Region', 'Canton / Region'],

            // --- City ---
            ['City', 'City'],
            ['Ville', 'City'],
            ['Stadt', 'City'],

            // --- Camp Terms ---
            ['Camp Terms', 'Camp Terms'],
            ['Conditions du camp', 'Camp Terms'],
            ['Conditions de camp', 'Camp Terms'],
            ['Camp Begriffe', 'Camp Terms'],

            // --- Course Day ---
            ['Course Day', 'Course Day'],
            ['Jour de cours', 'Course Day'],
            ['Kurstag', 'Course Day'],

            // --- Course Times ---
            ['Course Times', 'Course Times'],
            ['Horaires du cours', 'Course Times'],
            ['Kurszeiten', 'Course Times'],

            // --- Camp Times ---
            ['Camp Times', 'Camp Times'],
            ['Horaires du camp', 'Camp Times'],
            ['Camp Zeiten', 'Camp Times'],

            // --- Girls Only ---
            ['Girls Only', 'Girls Only'],
            ['Filles uniquement', 'Girls Only'],
            ['Nur Mädchen', 'Girls Only'],
            ['Nur Madchen', 'Girls Only'],

            // --- Assigned Attendee (checkout extra, not in registry) ---
            ['Assigned Attendee', 'Assigned Attendee'],
            ['Participant assigné', 'Assigned Attendee'],
            ['Zugewiesener Teilnehmer', 'Assigned Attendee'],

            // --- Days Selected (checkout extra) ---
            ['Days Selected', 'Days Selected'],
            ['days selected', 'Days Selected'],
            ['selected days', 'Days Selected'],
        ];
    }

    /**
     * @dataProvider historicalMetaKeyProvider
     */
    public function test_all_known_historical_meta_key_variants_resolve($raw_key, $expected_canonical) {
        if (!function_exists('intersoccer_normalize_order_item_meta_key')) {
            $this->markTestSkipped('normalize helper not loaded');
        }

        $resolved = intersoccer_normalize_order_item_meta_key($raw_key);
        $this->assertSame(
            $expected_canonical,
            $resolved,
            "Meta key '$raw_key' should resolve to '$expected_canonical', got '$resolved'"
        );
    }

    public function test_migration_alias_map_matches_order_meta_aliases() {
        if (!function_exists('intersoccer_migration_human_alias_map')
            || !function_exists('intersoccer_get_order_meta_manual_aliases')
            || !function_exists('intersoccer_order_meta_normalize_comparison_string')) {
            $this->markTestSkipped('required functions not loaded');
        }

        $migration = intersoccer_migration_human_alias_map();
        $manual = intersoccer_get_order_meta_manual_aliases();
        $normalize = 'intersoccer_order_meta_normalize_comparison_string';

        $internal_to_canonical = [
            'intersoccer_venues' => 'Sites InterSoccer',
            'age_group' => 'Age Group',
            'camp_terms' => 'Camp Terms',
            'course_day' => 'Course Day',
            'course_times' => 'Course Times',
            'camp_times' => 'Camp Times',
            'activity_type' => 'Activity Type',
            'season' => 'Season',
            'booking_type' => 'Booking Type',
            'canton_region' => 'Canton / Region',
            'city' => 'City',
        ];

        foreach ($internal_to_canonical as $internal => $canonical) {
            if (!isset($migration[$internal]) || !isset($manual[$canonical])) {
                continue;
            }

            $migration_normalized = array_map($normalize, $migration[$internal]);
            $manual_set = $manual[$canonical];

            foreach ($migration_normalized as $alias) {
                if ($alias === $normalize($canonical)) {
                    continue;
                }
                $this->assertContains(
                    $alias,
                    $manual_set,
                    "Migration alias '$alias' (internal: $internal) should be in manual aliases for '$canonical'"
                );
            }
        }
    }
}
