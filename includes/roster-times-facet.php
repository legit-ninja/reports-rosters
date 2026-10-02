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

if (!function_exists('intersoccer_roster_url_times_pin_is_stale')) {
    /**
     * True when URL times disagree with at least one pinned order-item row's times.
     * Fail open (false) when URL times empty/N/A or URL/row normalize is empty.
     *
     * @param array<int,mixed> $row_times_values Times values from roster rows for URL order_item_ids.
     * @param mixed            $url_times
     * @return bool
     */
    function intersoccer_roster_url_times_pin_is_stale($row_times_values, $url_times) {
        $url = trim((string) ($url_times ?? ''));
        if ($url === '' || strcasecmp($url, 'N/A') === 0) {
            return false;
        }

        $url_slug = intersoccer_normalize_times_slug_for_roster_facet($url);
        if ($url_slug === '') {
            return false;
        }

        foreach ((array) $row_times_values as $row_times) {
            $row_slug = intersoccer_normalize_times_slug_for_roster_facet($row_times);
            if ($row_slug === '') {
                continue;
            }
            if ($row_slug !== $url_slug) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('intersoccer_roster_stale_times_pin_notice_message')) {
    /**
     * Friendly admin copy for a post-move stale details URL pin.
     *
     * @return string
     */
    function intersoccer_roster_stale_times_pin_notice_message() {
        if (function_exists('__')) {
            return __(
                'This roster details tab may be out of date after a player was moved to a different times slot. Reopen this roster from the course listing to see the current players. You do not need to move the player again.',
                'intersoccer-reports-rosters'
            );
        }

        return 'This roster details tab may be out of date after a player was moved to a different times slot. Reopen this roster from the course listing to see the current players. You do not need to move the player again.';
    }
}

if (!function_exists('intersoccer_roster_echo_stale_times_pin_notice')) {
    /**
     * Echo a WP admin-style warning notice for a stale times pin (no redirect / no re-move).
     *
     * @return void
     */
    function intersoccer_roster_echo_stale_times_pin_notice() {
        $message = intersoccer_roster_stale_times_pin_notice_message();
        echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
    }
}
