<?php
/**
 * Reports/Rosters Diagnostics Service
 *
 * Read-only reconciliation helpers for admin diagnostics tooling.
 *
 * @package InterSoccer\ReportsRosters\Services
 */

namespace InterSoccer\ReportsRosters\Services;

defined('ABSPATH') or die('Restricted access');

class ReportsRostersDiagnosticsService {
    /**
     * Maximum mismatch rows returned in one request.
     */
    private const MAX_LIMIT = 500;

    /**
     * Run reconciliation diagnostics for a filter set.
     *
     * @param array $filters Request filters.
     * @return array
     */
    public function runDiagnostics(array $filters): array {
        $filters = $this->sanitizeFilters($filters);
        $started = microtime(true);

        $woo_rows = $this->fetchWooRows($filters);
        $roster_rows = $this->fetchRosterRows($filters);

        $woo_map = [];
        foreach ($woo_rows as $row) {
            $oid = (int) ($row['order_item_id'] ?? 0);
            if ($oid > 0) {
                $woo_map[$oid] = $row;
            }
        }

        $roster_map = [];
        foreach ($roster_rows as $row) {
            $oid = (int) ($row['order_item_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            if (!isset($roster_map[$oid])) {
                $roster_map[$oid] = $row;
                continue;
            }
            // Prefer row with non-empty venue/day for diagnostics display.
            $old_rank = $this->rowCompletenessRank($roster_map[$oid]);
            $new_rank = $this->rowCompletenessRank($row);
            if ($new_rank > $old_rank) {
                $roster_map[$oid] = $row;
            }
        }

        $all_ids = array_values(array_unique(array_merge(array_keys($woo_map), array_keys($roster_map))));
        sort($all_ids, SORT_NUMERIC);

        $reason_counts = [];
        $mismatches_all = [];

        foreach ($all_ids as $order_item_id) {
            $woo = $woo_map[$order_item_id] ?? null;
            $roster = $roster_map[$order_item_id] ?? null;
            $reasons = self::classifyMismatchReasons($woo, $roster);

            if (empty($reasons)) {
                continue;
            }

            foreach ($reasons as $reason) {
                if (!isset($reason_counts[$reason])) {
                    $reason_counts[$reason] = 0;
                }
                $reason_counts[$reason]++;
            }

            $mismatches_all[] = [
                'order_item_id' => $order_item_id,
                'order_id' => (int) (($woo['order_id'] ?? 0) ?: ($roster['order_id'] ?? 0)),
                'product_id' => (int) (($woo['product_id'] ?? 0) ?: ($roster['product_id'] ?? 0)),
                'variation_id' => (int) (($woo['variation_id'] ?? 0) ?: ($roster['variation_id'] ?? 0)),
                'woo_venue' => $woo['venue_value'] ?? '',
                'roster_venue' => $roster['venue'] ?? '',
                'woo_course_day' => $woo['course_day_value'] ?? '',
                'roster_course_day' => $roster['course_day'] ?? '',
                'reasons' => $reasons,
            ];
        }

        $total_mismatches = count($mismatches_all);
        $paged = array_slice($mismatches_all, $filters['offset'], $filters['limit']);

        return [
            'filters' => $filters,
            'summary' => [
                'woo_rows' => count($woo_map),
                'roster_rows' => count($roster_map),
                'intersection' => count(array_intersect(array_keys($woo_map), array_keys($roster_map))),
                'only_woo' => count(array_diff(array_keys($woo_map), array_keys($roster_map))),
                'only_rosters' => count(array_diff(array_keys($roster_map), array_keys($woo_map))),
                'mismatch_rows' => $total_mismatches,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
            'reason_counts' => $reason_counts,
            'pagination' => [
                'limit' => $filters['limit'],
                'offset' => $filters['offset'],
                'returned' => count($paged),
                'total' => $total_mismatches,
            ],
            'mismatches' => $paged,
        ];
    }

    /**
     * Run safe, Woo-truth fixes for diagnostics buckets.
     * Non-destructive mode: no orphan delete.
     *
     * @param array $filters
     * @return array
     */
    public function runSafeFix(array $filters): array {
        $filters = $this->sanitizeFilters($filters);
        $started = microtime(true);
        $run_id = 'safefix_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);

        $woo_rows = $this->fetchWooRows($filters);
        $roster_rows = $this->fetchRosterRows($filters);

        $woo_map = [];
        foreach ($woo_rows as $row) {
            $oid = (int) ($row['order_item_id'] ?? 0);
            if ($oid > 0) {
                $woo_map[$oid] = $row;
            }
        }

        $roster_map = [];
        foreach ($roster_rows as $row) {
            $oid = (int) ($row['order_item_id'] ?? 0);
            if ($oid > 0) {
                $roster_map[$oid] = $row;
            }
        }

        $all_ids = array_values(array_unique(array_merge(array_keys($woo_map), array_keys($roster_map))));
        sort($all_ids, SORT_NUMERIC);

        $results = [
            'run_id' => $run_id,
            'filters' => $filters,
            'fixed_missing_in_rosters' => 0,
            'fixed_missing_in_woo_meta' => 0,
            'quarantined_missing_in_woo' => 0,
            'skipped_non_activity_type' => 0,
            'errors' => [],
        ];

        foreach ($all_ids as $order_item_id) {
            $woo = $woo_map[$order_item_id] ?? null;
            $roster = $roster_map[$order_item_id] ?? null;
            $reasons = self::classifyMismatchReasons($woo, $roster);
            if (empty($reasons)) {
                continue;
            }

            if ($woo !== null && !self::isActivityTypeScopedRow($woo, (string) $filters['activity_type'])) {
                $results['skipped_non_activity_type']++;
                continue;
            }
            if ($woo === null && $roster !== null && !self::isRosterActivityTypeScopedRow($roster, (string) $filters['activity_type'])) {
                $results['skipped_non_activity_type']++;
                continue;
            }

            if (in_array('missing_in_rosters', $reasons, true) && $woo !== null) {
                if ($this->insertRosterPlaceholderFromWooRow($woo)) {
                    $results['fixed_missing_in_rosters']++;
                } else {
                    $results['errors'][] = sprintf('Failed inserting roster for order_item_id=%d', $order_item_id);
                }
            }

            if ($woo !== null && $roster !== null && in_array('missing_in_woo_meta', $reasons, true)) {
                $backfill_value = trim((string) ($roster['course_day'] ?? ''));
                if ($backfill_value !== '' && self::normalizeComparableValue($backfill_value) !== '') {
                    $ok_a = $this->upsertOrderItemMeta((int) $order_item_id, 'pa_course-day', $backfill_value);
                    $ok_b = $this->upsertOrderItemMeta((int) $order_item_id, 'attribute_pa_course-day', $backfill_value);
                    if ($ok_a || $ok_b) {
                        $results['fixed_missing_in_woo_meta']++;
                    } else {
                        $results['errors'][] = sprintf('Failed backfilling course day meta for order_item_id=%d', $order_item_id);
                    }
                }
            }

            if (in_array('missing_in_woo', $reasons, true) && $roster !== null) {
                if ($this->quarantineRosterOrderItem((int) $order_item_id, $run_id)) {
                    $results['quarantined_missing_in_woo']++;
                } else {
                    $results['errors'][] = sprintf('Failed quarantining orphan roster for order_item_id=%d', $order_item_id);
                }
            }
        }

        $results['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
        $results['message'] = __('Safe fix completed. Rerun diagnostics to verify counts.', 'intersoccer-reports-rosters');

        return $results;
    }

    /**
     * Return a deep trace payload for a specific order or order item.
     *
     * @param array $filters Request inputs.
     * @return array
     */
    public function traceItem(array $filters): array {
        global $wpdb;

        $filters = $this->sanitizeFilters($filters);
        $order_id = (int) ($filters['order_id'] ?? 0);
        $order_item_id = (int) ($filters['order_item_id'] ?? 0);

        if ($order_item_id <= 0 && $order_id <= 0) {
            return [
                'error' => __('Provide order ID or order item ID.', 'intersoccer-reports-rosters'),
            ];
        }

        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

        if ($order_item_id <= 0 && $order_id > 0) {
            $order_item_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT order_item_id FROM {$order_items_table}
                 WHERE order_id = %d AND order_item_type = 'line_item'
                 ORDER BY order_item_id ASC LIMIT 1",
                $order_id
            ));
        }
        if ($order_id <= 0 && $order_item_id > 0) {
            $order_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT order_id FROM {$order_items_table}
                 WHERE order_item_id = %d LIMIT 1",
                $order_item_id
            ));
        }

        $line_item = null;
        if ($order_item_id > 0) {
            $line_item = $wpdb->get_row($wpdb->prepare(
                "SELECT order_item_id, order_id, order_item_name, order_item_type
                 FROM {$order_items_table}
                 WHERE order_item_id = %d",
                $order_item_id
            ), ARRAY_A);
        }

        $meta_rows = [];
        if ($order_item_id > 0) {
            $meta_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value
                 FROM {$order_itemmeta_table}
                 WHERE order_item_id = %d
                 ORDER BY meta_key ASC",
                $order_item_id
            ), ARRAY_A);
        }

        $roster_rows = [];
        if ($order_item_id > 0) {
            $roster_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *
                 FROM {$rosters_table}
                 WHERE order_item_id = %d
                 ORDER BY id ASC",
                $order_item_id
            ), ARRAY_A);
        }

        return [
            'order_id' => $order_id,
            'order_item_id' => $order_item_id,
            'line_item' => $line_item,
            'item_meta' => $meta_rows,
            'roster_rows' => $roster_rows,
        ];
    }

    /**
     * Generate mismatch reasons for a Woo-vs-roster pair.
     *
     * @param array|null $woo
     * @param array|null $roster
     * @return array
     */
    public static function classifyMismatchReasons(?array $woo, ?array $roster): array {
        $reasons = [];
        if (!$woo && $roster) {
            $reasons[] = 'missing_in_woo';
            return $reasons;
        }
        if ($woo && !$roster) {
            $reasons[] = 'missing_in_rosters';
            return $reasons;
        }
        if (!$woo || !$roster) {
            return $reasons;
        }

        $woo_venue = self::normalizeVenueComparableValue($woo['venue_value'] ?? '');
        $roster_venue = self::normalizeVenueComparableValue($roster['venue'] ?? '');
        $woo_day = self::normalizeComparableValue($woo['course_day_value'] ?? '');
        $roster_day = self::normalizeComparableValue($roster['course_day'] ?? '');

        if (($woo_venue === '' && ($woo['venue_value'] ?? '') !== '') || ($roster_venue === '' && ($roster['venue'] ?? '') !== '')) {
            $reasons[] = 'unknown_placeholder_persisted';
        }
        if (($woo_day === '' && ($woo['course_day_value'] ?? '') !== '') || ($roster_day === '' && ($roster['course_day'] ?? '') !== '')) {
            $reasons[] = 'unknown_placeholder_persisted';
        }
        if ($woo_venue === '' && $roster_venue === '') {
            $reasons[] = 'missing_in_woo_meta';
        } elseif ($woo_venue === '' && $roster_venue !== '') {
            $reasons[] = 'missing_in_woo_meta';
        } elseif ($woo_venue !== '' && $roster_venue === '') {
            $reasons[] = 'missing_in_roster_venue';
        } elseif ($woo_venue !== $roster_venue) {
            $reasons[] = 'venue_mismatch';
        }

        // Course day mismatches are relevant primarily for course rows.
        if ($woo_day !== '' || $roster_day !== '') {
            if ($woo_day !== $roster_day) {
                $reasons[] = 'course_day_mismatch';
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Normalize values for source comparisons.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalizeComparableValue($value): string {
        $v = trim((string) $value);
        if ($v === '') {
            return '';
        }
        $lv = strtolower($v);
        if ($lv === 'unknown' || $lv === 'n/a' || $lv === 'na' || $lv === '-') {
            return '';
        }
        return $lv;
    }

    /**
     * Return true only when line-item/product context explicitly uses activity_type.
     *
     * @param array<string,mixed> $woo_row
     * @param string $requested_activity
     * @return bool
     */
    public static function isActivityTypeScopedRow(array $woo_row, string $requested_activity): bool {
        $product_attr = self::normalizeComparableValue($woo_row['product_activity_type_attr'] ?? '');
        $line_attr = self::normalizeComparableValue($woo_row['activity_type'] ?? '');

        if ($product_attr === '' && $line_attr === '') {
            return false;
        }

        $requested = strtolower(trim($requested_activity)) === 'camp' ? 'camp' : 'course';
        $candidate = $line_attr !== '' ? $line_attr : $product_attr;

        return strpos($candidate, $requested) !== false;
    }

    /**
     * Fallback scoping when Woo row is missing (orphan roster rows).
     *
     * @param array<string,mixed> $roster_row
     * @param string $requested_activity
     * @return bool
     */
    public static function isRosterActivityTypeScopedRow(array $roster_row, string $requested_activity): bool {
        $requested = strtolower(trim($requested_activity)) === 'camp' ? 'camp' : 'course';
        $atype = self::normalizeComparableValue($roster_row['activity_type'] ?? '');
        if ($atype === '') {
            return false;
        }
        return strpos($atype, $requested) !== false;
    }

    /**
     * Normalize venue values to a comparable slug-like format.
     * Accepts slugs, labels, and mixed translated values.
     *
     * @param mixed $value
     * @return string
     */
    private static function normalizeVenueComparableValue($value): string {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        $basic = self::normalizeComparableValue($raw);
        if ($basic === '') {
            return '';
        }

        // Try taxonomy slug resolution first when available.
        if (function_exists('get_term_by')) {
            $term_by_slug = get_term_by('slug', $basic, 'pa_intersoccer-venues');
            if ($term_by_slug && !is_wp_error($term_by_slug) && isset($term_by_slug->slug)) {
                return strtolower((string) $term_by_slug->slug);
            }
            $term_by_name = get_term_by('name', $raw, 'pa_intersoccer-venues');
            if ($term_by_name && !is_wp_error($term_by_name) && isset($term_by_name->slug)) {
                return strtolower((string) $term_by_name->slug);
            }
        }

        if (function_exists('sanitize_title')) {
            return strtolower((string) sanitize_title($raw));
        }

        return $basic;
    }

    /**
     * @param array $filters
     * @return array
     */
    private function sanitizeFilters(array $filters): array {
        $year = isset($filters['year']) ? (int) $filters['year'] : (int) date('Y');
        if ($year <= 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $activity_type = isset($filters['activity_type']) ? sanitize_text_field((string) $filters['activity_type']) : 'Course';
        $activity_type = strtolower($activity_type) === 'camp' ? 'Camp' : 'Course';

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 200;
        if ($limit <= 0) {
            $limit = 200;
        }
        $limit = min($limit, self::MAX_LIMIT);

        $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;
        if ($offset < 0) {
            $offset = 0;
        }

        return [
            'year' => $year,
            'activity_type' => $activity_type,
            'season_type' => isset($filters['season_type']) ? sanitize_text_field((string) $filters['season_type']) : '',
            'region' => isset($filters['region']) ? sanitize_text_field((string) $filters['region']) : '',
            'exclude_buyclub' => !empty($filters['exclude_buyclub']),
            'limit' => $limit,
            'offset' => $offset,
            'order_id' => isset($filters['order_id']) ? (int) $filters['order_id'] : 0,
            'order_item_id' => isset($filters['order_item_id']) ? (int) $filters['order_item_id'] : 0,
        ];
    }

    /**
     * Fetch relevant Woo line items with normalized diagnostics fields.
     *
     * @param array $filters
     * @return array<int,array<string,mixed>>
     */
    private function fetchWooRows(array $filters): array {
        global $wpdb;
        $posts_table = $wpdb->prefix . 'posts';
        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

        $activity_like = strtolower($filters['activity_type']) === 'camp' ? 'camp' : 'course';
        $year = (int) $filters['year'];

        $sql = "SELECT
                    oi.order_item_id,
                    oi.order_id,
                    oi.order_item_name,
                    om_product_id.meta_value AS product_id,
                    om_variation_id.meta_value AS variation_id,
                    COALESCE(NULLIF(om_venue.meta_value, ''), om_venue_attr.meta_value, om_venue_us.meta_value, om_venue_us_attr.meta_value) AS venue_value,
                    COALESCE(NULLIF(om_course_day.meta_value, ''), om_course_day_attr.meta_value, om_course_day_us.meta_value, om_course_day_us_attr.meta_value) AS course_day_value,
                    COALESCE(om_activity_type.meta_value, pm_activity_type.meta_value) AS activity_type,
                    pm_activity_type.meta_value AS product_activity_type_attr,
                    COALESCE(om_season.meta_value, om_season_alt.meta_value, '') AS season,
                    COALESCE(om_canton.meta_value, '') AS canton,
                    om_line_subtotal.meta_value AS line_subtotal,
                    om_line_total.meta_value AS line_total
                FROM {$posts_table} p
                INNER JOIN {$order_items_table} oi
                    ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
                LEFT JOIN {$order_itemmeta_table} om_product_id
                    ON oi.order_item_id = om_product_id.order_item_id AND om_product_id.meta_key = '_product_id'
                LEFT JOIN {$order_itemmeta_table} om_variation_id
                    ON oi.order_item_id = om_variation_id.order_item_id AND om_variation_id.meta_key = '_variation_id'
                LEFT JOIN {$order_itemmeta_table} om_venue
                    ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
                LEFT JOIN {$order_itemmeta_table} om_venue_attr
                    ON oi.order_item_id = om_venue_attr.order_item_id AND om_venue_attr.meta_key = 'attribute_pa_intersoccer-venues'
                LEFT JOIN {$order_itemmeta_table} om_venue_us
                    ON oi.order_item_id = om_venue_us.order_item_id AND om_venue_us.meta_key = 'pa_intersoccer_venues'
                LEFT JOIN {$order_itemmeta_table} om_venue_us_attr
                    ON oi.order_item_id = om_venue_us_attr.order_item_id AND om_venue_us_attr.meta_key = 'attribute_pa_intersoccer_venues'
                LEFT JOIN {$order_itemmeta_table} om_course_day
                    ON oi.order_item_id = om_course_day.order_item_id AND om_course_day.meta_key = 'pa_course-day'
                LEFT JOIN {$order_itemmeta_table} om_course_day_attr
                    ON oi.order_item_id = om_course_day_attr.order_item_id AND om_course_day_attr.meta_key = 'attribute_pa_course-day'
                LEFT JOIN {$order_itemmeta_table} om_course_day_us
                    ON oi.order_item_id = om_course_day_us.order_item_id AND om_course_day_us.meta_key = 'pa_course_day'
                LEFT JOIN {$order_itemmeta_table} om_course_day_us_attr
                    ON oi.order_item_id = om_course_day_us_attr.order_item_id AND om_course_day_us_attr.meta_key = 'attribute_pa_course_day'
                LEFT JOIN {$order_itemmeta_table} om_activity_type
                    ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
                LEFT JOIN {$order_itemmeta_table} om_season
                    ON oi.order_item_id = om_season.order_item_id AND om_season.meta_key = 'Season'
                LEFT JOIN {$order_itemmeta_table} om_season_alt
                    ON oi.order_item_id = om_season_alt.order_item_id AND om_season_alt.meta_key = 'pa_program-season'
                LEFT JOIN {$order_itemmeta_table} om_canton
                    ON oi.order_item_id = om_canton.order_item_id AND om_canton.meta_key = 'Canton / Region'
                LEFT JOIN {$order_itemmeta_table} om_line_subtotal
                    ON oi.order_item_id = om_line_subtotal.order_item_id AND om_line_subtotal.meta_key = '_line_subtotal'
                LEFT JOIN {$order_itemmeta_table} om_line_total
                    ON oi.order_item_id = om_line_total.order_item_id AND om_line_total.meta_key = '_line_total'
                LEFT JOIN {$wpdb->postmeta} pm_activity_type
                    ON om_product_id.meta_value = pm_activity_type.post_id AND pm_activity_type.meta_key = 'pa_activity-type'
                WHERE p.post_type = 'shop_order'
                    AND p.post_status = 'wc-completed'
                    AND (
                        YEAR(p.post_date) = %d
                        OR COALESCE(om_season.meta_value, om_season_alt.meta_value, '') LIKE %s
                    )";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $year, '%' . $year . '%'), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $atype = strtolower(trim((string) ($row['activity_type'] ?? '')));
            if ($atype !== '' && strpos($atype, $activity_like) === false) {
                continue;
            }
            if (!empty($filters['season_type']) && function_exists('intersoccer_extract_season_type')) {
                $stype = intersoccer_extract_season_type((string) ($row['season'] ?? ''));
                if ((string) $stype !== (string) $filters['season_type']) {
                    continue;
                }
            }
            if (!empty($filters['region']) && function_exists('intersoccer_reports_region_matches_filter')) {
                if (!intersoccer_reports_region_matches_filter((string) ($row['canton'] ?? ''), (string) $filters['region'])) {
                    continue;
                }
            }
            if (!empty($filters['exclude_buyclub'])) {
                $is_buyclub = ((float) ($row['line_subtotal'] ?? 0) > 0.0) && ((float) ($row['line_total'] ?? 0) === 0.0);
                if ($is_buyclub) {
                    continue;
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Insert minimal roster row for missing line item.
     *
     * @param array<string,mixed> $woo_row
     * @return bool
     */
    private function insertRosterPlaceholderFromWooRow(array $woo_row): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $order_item_id = (int) ($woo_row['order_item_id'] ?? 0);
        if ($order_item_id <= 0) {
            return false;
        }

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE order_item_id = %d",
            $order_item_id
        ));
        if ($exists > 0) {
            return true;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'order_id' => (int) ($woo_row['order_id'] ?? 0),
                'order_item_id' => $order_item_id,
                'variation_id' => (int) ($woo_row['variation_id'] ?? 0),
                'product_id' => (int) ($woo_row['product_id'] ?? 0),
                'player_name' => 'Unknown Player',
                'first_name' => 'Unknown',
                'last_name' => 'Unknown',
                'booking_type' => 'single-day',
                'product_name' => (string) ($woo_row['order_item_name'] ?? 'Unknown Product'),
                'activity_type' => (string) ($woo_row['activity_type'] ?? ''),
                'venue' => (string) ($woo_row['venue_value'] ?? ''),
                'course_day' => (string) ($woo_row['course_day_value'] ?? ''),
                'player_first_name' => 'Unknown',
                'player_last_name' => 'Unknown',
                'parent_first_name' => 'Unknown',
                'parent_last_name' => 'Unknown',
                'created_at' => current_time('mysql'),
            ],
            [
                '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            ]
        );

        return $inserted !== false;
    }

    /**
     * Upsert Woo order item meta.
     */
    private function upsertOrderItemMeta(int $order_item_id, string $meta_key, string $meta_value): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        if ($order_item_id <= 0 || $meta_key === '') {
            return false;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT meta_id, meta_value
             FROM {$table}
             WHERE order_item_id = %d AND meta_key = %s
             ORDER BY meta_id ASC
             LIMIT 1",
            $order_item_id,
            $meta_key
        ), ARRAY_A);

        if (is_array($existing) && isset($existing['meta_id'])) {
            $current_value = trim((string) ($existing['meta_value'] ?? ''));
            if ($current_value !== '') {
                return true;
            }
            $updated = $wpdb->update(
                $table,
                ['meta_value' => $meta_value],
                ['meta_id' => (int) $existing['meta_id']],
                ['%s'],
                ['%d']
            );
            return $updated !== false;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'order_item_id' => $order_item_id,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value,
            ],
            ['%d', '%s', '%s']
        );
        return $inserted !== false;
    }

    /**
     * Quarantine orphan roster row by order item id.
     */
    private function quarantineRosterOrderItem(int $order_item_id, string $run_id): bool {
        global $wpdb;
        $source = $wpdb->prefix . 'intersoccer_rosters';
        $quarantine = $wpdb->prefix . 'rr_cleanup_orphan_quarantine';
        if ($order_item_id <= 0) {
            return false;
        }

        $this->ensureQuarantineTable();

        $already = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$quarantine} WHERE order_item_id = %d",
            $order_item_id
        ));
        if ($already > 0) {
            return true;
        }

        $sql = $wpdb->prepare(
            "INSERT INTO {$quarantine} (
                order_id, order_item_id, variation_id, player_name, first_name, last_name, age, gender,
                booking_type, selected_days, camp_terms, venue, parent_phone, parent_email, medical_conditions,
                late_pickup, late_pickup_days, day_presence, age_group, start_date, end_date, event_dates, product_name,
                activity_type, shirt_size, shorts_size, registration_timestamp, course_day, updated_at, product_id,
                player_first_name, player_last_name, player_dob, player_gender, player_medical, player_dietary,
                parent_first_name, parent_last_name, emergency_contact, term, times, days_selected, season,
                canton_region, city, avs_number, created_at, base_price, discount_amount, final_price,
                reimbursement, discount_codes, girls_only, event_signature, is_placeholder, event_completed,
                quarantined_at, run_id
             )
             SELECT
                r.order_id, r.order_item_id, r.variation_id, r.player_name, r.first_name, r.last_name, r.age, r.gender,
                r.booking_type, r.selected_days, r.camp_terms, r.venue, r.parent_phone, r.parent_email, r.medical_conditions,
                r.late_pickup, r.late_pickup_days, r.day_presence, r.age_group, r.start_date, r.end_date, r.event_dates, r.product_name,
                r.activity_type, r.shirt_size, r.shorts_size, r.registration_timestamp, r.course_day, r.updated_at, r.product_id,
                r.player_first_name, r.player_last_name, r.player_dob, r.player_gender, r.player_medical, r.player_dietary,
                r.parent_first_name, r.parent_last_name, r.emergency_contact, r.term, r.times, r.days_selected, r.season,
                r.canton_region, r.city, r.avs_number, r.created_at, r.base_price, r.discount_amount, r.final_price,
                r.reimbursement, r.discount_codes, r.girls_only, r.event_signature, r.is_placeholder, r.event_completed,
                %s AS quarantined_at, %s AS run_id
             FROM {$source} r
             WHERE r.order_item_id = %d",
            current_time('mysql'),
            $run_id,
            $order_item_id
        );
        $inserted = $wpdb->query($sql);
        return $inserted !== false && (int) $inserted > 0;
    }

    /**
     * Create quarantine table if needed.
     */
    private function ensureQuarantineTable(): void {
        global $wpdb;
        $source = $wpdb->prefix . 'intersoccer_rosters';
        $quarantine = $wpdb->prefix . 'rr_cleanup_orphan_quarantine';
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$quarantine} LIKE {$source}");
        if (!$this->tableColumnExists($quarantine, 'quarantined_at')) {
            $wpdb->query("ALTER TABLE {$quarantine} ADD COLUMN quarantined_at datetime NULL");
        }
        if (!$this->tableColumnExists($quarantine, 'run_id')) {
            $wpdb->query("ALTER TABLE {$quarantine} ADD COLUMN run_id varchar(64) NULL");
        }
        if (!$this->tableIndexExists($quarantine, 'idx_run_id')) {
            $wpdb->query("ALTER TABLE {$quarantine} ADD KEY idx_run_id (run_id)");
        }
    }

    private function tableColumnExists(string $table, string $column): bool {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND COLUMN_NAME = %s",
            $table,
            $column
        );
        return ((int) $wpdb->get_var($sql)) > 0;
    }

    private function tableIndexExists(string $table, string $index_name): bool {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = %s
               AND INDEX_NAME = %s",
            $table,
            $index_name
        );
        return ((int) $wpdb->get_var($sql)) > 0;
    }

    /**
     * Fetch roster rows filtered by activity/year.
     *
     * @param array $filters
     * @return array<int,array<string,mixed>>
     */
    private function fetchRosterRows(array $filters): array {
        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';

        $activity = strtolower($filters['activity_type']) === 'camp' ? 'camp' : 'course';

        $sql = "SELECT id, order_item_id, order_id, product_id, variation_id, activity_type,
                       venue, course_day, season, canton_region, start_date, end_date
                FROM {$table}
                WHERE order_item_id > 0";

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $year = (int) $filters['year'];
        $filtered = [];

        foreach ($rows as $row) {
            $atype = strtolower(trim((string) ($row['activity_type'] ?? '')));
            if ($atype !== '' && strpos($atype, $activity) === false) {
                continue;
            }
            if (!$this->rosterMatchesYear($row, $year)) {
                continue;
            }
            if (!empty($filters['season_type']) && function_exists('intersoccer_extract_season_type')) {
                $stype = intersoccer_extract_season_type((string) ($row['season'] ?? ''));
                if ((string) $stype !== (string) $filters['season_type']) {
                    continue;
                }
            }
            if (!empty($filters['region']) && function_exists('intersoccer_reports_region_matches_filter')) {
                if (!intersoccer_reports_region_matches_filter((string) ($row['canton_region'] ?? ''), (string) $filters['region'])) {
                    continue;
                }
            }
            $filtered[] = $row;
        }

        return $filtered;
    }

    /**
     * @param array<string,mixed> $row
     * @param int $year
     * @return bool
     */
    private function rosterMatchesYear(array $row, int $year): bool {
        $season = (string) ($row['season'] ?? '');
        if ($season !== '' && strpos($season, (string) $year) !== false) {
            return true;
        }
        $start = (string) ($row['start_date'] ?? '');
        if ($start !== '' && $start !== '0000-00-00' && $start !== '1970-01-01') {
            $parsed = strtotime($start);
            if ($parsed !== false) {
                return (int) date('Y', $parsed) === $year;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $row
     * @return int
     */
    private function rowCompletenessRank(array $row): int {
        $score = 0;
        $venue = self::normalizeComparableValue($row['venue'] ?? '');
        $day = self::normalizeComparableValue($row['course_day'] ?? '');
        if ($venue !== '') {
            $score += 1;
        }
        if ($day !== '') {
            $score += 1;
        }
        return $score;
    }
}

