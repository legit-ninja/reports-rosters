<?php

namespace InterSoccer\Reports\Tests\Reports;

use PHPUnit\Framework\TestCase;

/**
 * Branch signatures must match master (80bf153) for stored roster row shapes.
 */
class EventSignatureMasterParityTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
    }

    /**
     * Replica of intersoccer_generate_event_signature() on master @ 80bf153 (parity reference).
     */
    private static function masterReferenceSignature(array $event_data): string {
        $product_id = $event_data['product_id'] ?? '';
        $slug = static function ($name, $taxonomy) {
            if (function_exists('intersoccer_get_term_slug_by_name')) {
                return intersoccer_get_term_slug_by_name($name, $taxonomy);
            }
            return $name;
        };
        $times = $event_data['times'] ?? '';
        if (function_exists('intersoccer_resolve_times_slug_for_signature')) {
            $times = intersoccer_resolve_times_slug_for_signature($times);
        }
        $normalized_components = [
            'activity_type' => $event_data['activity_type'] ?? '',
            'venue' => $slug($event_data['venue'] ?? '', 'pa_intersoccer-venues'),
            'age_group' => $slug($event_data['age_group'] ?? '', 'pa_age-group'),
            'camp_terms' => $slug($event_data['camp_terms'] ?? '', 'pa_camp-terms'),
            'course_day' => $slug($event_data['course_day'] ?? '', 'pa_course-day'),
            'times' => $times,
            'season' => $slug($event_data['season'] ?? '', 'pa_program-season'),
            'girls_only' => !empty($event_data['girls_only']) ? '1' : '0',
            'city' => $slug($event_data['city'] ?? '', 'pa_city'),
            'canton_region' => $slug($event_data['canton_region'] ?? '', 'pa_canton-region'),
            'product_id' => $product_id,
        ];
        $activity_kind = strtolower(trim((string) ($normalized_components['activity_type'] ?? '')));
        if ($activity_kind === 'camp') {
            $normalized_components['course_day'] = '';
            $normalized_components['canton_region'] = '';
        } elseif ($activity_kind === 'course') {
            $normalized_components['camp_terms'] = '';
            $normalized_components['canton_region'] = '';
        } elseif ($activity_kind === 'tournament') {
            $normalized_components['course_day'] = '';
            $normalized_components['camp_terms'] = '';
        }
        $activity_type = strtolower($normalized_components['activity_type'] ?? '');
        if ($activity_type === 'tournament' && !empty($event_data['start_date'])) {
            $normalized_components['start_date'] = $event_data['start_date'];
        }
        $signature_string = implode('|', array_map(static function ($key, $value) {
            return $key . ':' . trim(strtolower((string) $value));
        }, array_keys($normalized_components), $normalized_components));

        return md5($signature_string);
    }

    public function test_generate_event_signature_matches_master_reference_shapes(): void {
        if (!function_exists('intersoccer_generate_event_signature')) {
            $this->fail('intersoccer_generate_event_signature must be loaded');
        }

        $base = [
            'venue' => 'geneva-venue',
            'age_group' => '5-12',
            'camp_terms' => 'summer-week-1',
            'course_day' => 'monday',
            'times' => '0900-1200',
            'season' => 'Summer 2026',
            'city' => 'geneva',
            'canton_region' => 'ge',
            'product_id' => 4242,
            'girls_only' => 0,
        ];

        $shapes = [
            'EN composite girls-only camp' => [
                'activity_type' => 'Camp, Girls Only',
                'girls_only' => 0,
                'camp_terms' => 'summer-week-1',
                'course_day' => 'monday',
            ],
            'FR composite' => [
                'activity_type' => 'Camp, Filles uniquement',
                'girls_only' => 0,
                'camp_terms' => 'summer-week-1',
                'course_day' => '',
            ],
            'DE composite' => [
                'activity_type' => 'Camp, Nur Mädchen',
                'girls_only' => 1,
                'camp_terms' => 'summer-week-1',
                'course_day' => '',
            ],
            'lower-case camp girls only' => [
                'activity_type' => 'camp girls only',
                'girls_only' => 0,
                'camp_terms' => 'summer-week-1',
                'course_day' => '',
            ],
            'Girls Only alone' => [
                'activity_type' => 'Girls Only',
                'girls_only' => 1,
                'camp_terms' => 'N/A',
                'course_day' => 'N/A',
            ],
            'Course composite' => [
                'activity_type' => 'Course, Girls Only',
                'girls_only' => 0,
                'camp_terms' => '',
                'course_day' => 'wednesday',
            ],
            'Tournament with date' => [
                'activity_type' => 'Tournament',
                'girls_only' => 0,
                'camp_terms' => '',
                'course_day' => '',
                'start_date' => '2026-06-14',
            ],
            'Modern Camp flag' => [
                'activity_type' => 'Camp',
                'girls_only' => 1,
                'camp_terms' => 'summer-week-1',
                'course_day' => '',
            ],
        ];

        foreach ($shapes as $label => $overrides) {
            $payload = array_merge($base, $overrides);
            $master = self::masterReferenceSignature($payload);
            $branch = intersoccer_generate_event_signature($payload);
            $this->assertSame($master, $branch, 'Signature parity failed for shape: ' . $label);
        }
    }
}
