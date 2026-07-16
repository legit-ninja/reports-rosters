<?php
/**
 * Bridge to product-variations attribute registry when available.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_reports_attr_order_meta_label')) {
    /**
     * Resolve canonical order-meta label for an attribute slug.
     *
     * @param string $slug Attribute slug without pa_ prefix.
     * @return string
     */
    function intersoccer_reports_attr_order_meta_label($slug) {
        if (function_exists('intersoccer_attr_order_meta_label')) {
            return intersoccer_attr_order_meta_label($slug);
        }

        static $fallback = [
            'intersoccer-venues' => 'Sites InterSoccer',
            'age-group' => 'Age Group',
            'camp-terms' => 'Camp Terms',
            'course-day' => 'Course Day',
            'course-times' => 'Course Times',
            'camp-times' => 'Camp Times',
            'activity-type' => 'Activity Type',
            'program-season' => 'Season',
            'booking-type' => 'Booking Type',
            'canton-region' => 'Canton / Region',
            'city' => 'City',
            'girls-only' => 'Girls Only',
        ];

        return $fallback[$slug] ?? '';
    }
}

if (!function_exists('intersoccer_reports_attr_legacy_order_meta_labels')) {
    /**
     * Legacy order-meta label aliases for an attribute slug.
     *
     * Delegates to PV's attribute registry when available, with a static
     * fallback covering the most common FR/DE variants.
     *
     * @param string $slug Attribute slug without pa_ prefix.
     * @return array<int,string>
     */
    function intersoccer_reports_attr_legacy_order_meta_labels($slug) {
        if (function_exists('intersoccer_attr_definition')) {
            $def = intersoccer_attr_definition($slug);
            if ($def !== null && isset($def['legacy_order_meta_labels'])) {
                return (array) $def['legacy_order_meta_labels'];
            }
        }

        static $fallback = [
            'activity-type' => ["Type d'activité", "Type d'activite", 'Aktivitätstyp'],
            'intersoccer-venues' => ['InterSoccer Venues', 'Lieux InterSoccer', 'Lieu InterSoccer', 'InterSoccer-Standorte'],
            'program-season' => ['Saison', 'Saison (Programm)', 'Jahreszeit'],
            'age-group' => ["Groupe d'âge", 'Groupe dage', 'Altersgruppe'],
            'canton-region' => ['Canton / Région', 'Canton Region', 'Kanton Region'],
            'city' => ['Ville', 'Stadt'],
            'booking-type' => ['Type de réservation', 'Buchungstyp'],
            'camp-terms' => ['Conditions du camp', 'Conditions de camp', 'Camp Begriffe'],
            'course-day' => ['Jour de cours', 'Kurstag'],
            'course-times' => ['Horaires du cours', 'Kurszeiten'],
            'camp-times' => ['Horaires du camp', 'Camp Zeiten'],
            'girls-only' => ['Filles uniquement', 'Nur Mädchen', 'Nur Madchen'],
        ];

        return $fallback[$slug] ?? [];
    }
}

if (!function_exists('intersoccer_reports_attr_canonical_label_to_slug_map')) {
    /**
     * Map canonical order-meta labels back to attribute slugs.
     *
     * @return array<string,string> order_meta_label => slug
     */
    function intersoccer_reports_attr_canonical_label_to_slug_map() {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        if (function_exists('intersoccer_attr_registry')) {
            $map = [];
            foreach (intersoccer_attr_registry() as $slug => $def) {
                $map[(string) $def['order_meta_label']] = $slug;
            }
            return $map;
        }

        $map = [
            'Activity Type' => 'activity-type',
            'Sites InterSoccer' => 'intersoccer-venues',
            'Season' => 'program-season',
            'Year' => 'program-year',
            'Age Group' => 'age-group',
            'Canton / Region' => 'canton-region',
            'City' => 'city',
            'Booking Type' => 'booking-type',
            'Days of Week' => 'days-of-week',
            'Camp Terms' => 'camp-terms',
            'Course Day' => 'course-day',
            'Course Times' => 'course-times',
            'Camp Times' => 'camp-times',
            'Girls Only' => 'girls-only',
            'Date' => 'date',
            'Tournament Day' => 'tournament-day',
            'Tournament Time' => 'tournament-time',
            'Note' => 'note',
        ];
        return $map;
    }
}
