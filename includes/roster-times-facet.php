<?php
/**
 * Times facet helpers for roster details soft-filter and move verification.
 *
 * Kept small and ABSPATH-free so unit tests can load without WordPress.
 *
 * @package InterSoccer_Reports_Rosters
 */

if (!function_exists('intersoccer_normalize_times_slug_for_roster_facet')) {
    /**
     * Canonical times slug for roster details soft-filtering / move verification.
     * Uses signature + grouping helpers; empty means normalization failed (callers fail open or raw-compare).
     *
     * @param mixed $value
     * @return string
     */
    function intersoccer_normalize_times_slug_for_roster_facet($value) {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
            return '';
        }

        if (function_exists('intersoccer_resolve_times_slug_for_signature')) {
            $slug = trim((string) intersoccer_resolve_times_slug_for_signature($raw));
            if ($slug !== '') {
                return strtolower($slug);
            }
        }

        if (function_exists('intersoccer_roster_facet_for_grouping')) {
            foreach (['pa_course-times', 'pa_camp-times'] as $tax) {
                $facet = intersoccer_roster_facet_for_grouping($raw, $tax);
                if ($facet !== '') {
                    return $facet;
                }
            }
            $facet = intersoccer_roster_facet_for_grouping($raw, '');
            if ($facet !== '') {
                return $facet;
            }
        }

        return '';
    }
}

if (!function_exists('intersoccer_roster_times_matches_url_facet')) {
    /**
     * Keep a roster row for a details URL that pins order_item_ids + times.
     * Fail open when either side cannot be normalized (preserves Unknown/WPML consolidation).
     *
     * @param mixed $row_times
     * @param mixed $url_times
     * @return bool
     */
    function intersoccer_roster_times_matches_url_facet($row_times, $url_times) {
        $url = trim((string) ($url_times ?? ''));
        if ($url === '' || strcasecmp($url, 'N/A') === 0) {
            return true;
        }

        $url_slug = intersoccer_normalize_times_slug_for_roster_facet($url);
        if ($url_slug === '') {
            return true;
        }

        $row_slug = intersoccer_normalize_times_slug_for_roster_facet($row_times);
        if ($row_slug === '') {
            return true;
        }

        return $row_slug === $url_slug;
    }
}

if (!function_exists('intersoccer_roster_times_equal_for_move_verify')) {
    /**
     * Strict times equality for post-move roster verification (variation already checked separately).
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    function intersoccer_roster_times_equal_for_move_verify($a, $b) {
        $sa = intersoccer_normalize_times_slug_for_roster_facet($a);
        $sb = intersoccer_normalize_times_slug_for_roster_facet($b);
        if ($sa !== '' && $sb !== '') {
            return $sa === $sb;
        }

        return strcasecmp(trim((string) ($a ?? '')), trim((string) ($b ?? ''))) === 0;
    }
}
