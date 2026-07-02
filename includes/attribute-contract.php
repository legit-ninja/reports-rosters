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
