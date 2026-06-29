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
     * Order statuses aligned with RosterBuilder::reconcile().
     */
    private const DEFAULT_ORDER_STATUSES = [
        'wc-completed',
        'wc-processing',
        'wc-pending',
        'wc-on-hold',
    ];

    /**
     * Product types that should appear in roster sync tooling.
     */
    private const ROSTER_ELIGIBLE_PRODUCT_TYPES = ['camp', 'course', 'tournament'];

    /**
     * Known mismatch reason keys for filtering.
     */
    private const KNOWN_MISMATCH_REASONS = [
        'missing_in_rosters',
        'missing_in_woo',
        'incomplete_player_data',
        'unknown_placeholder_persisted',
        'missing_in_woo_meta',
        'missing_in_roster_venue',
        'venue_mismatch',
        'course_day_mismatch',
        'fragmented_roster',
    ];

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
        $roster_rows_by_item = [];
        foreach ($roster_rows as $row) {
            $oid = (int) ($row['order_item_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            if (!isset($roster_rows_by_item[$oid])) {
                $roster_rows_by_item[$oid] = [];
            }
            $roster_rows_by_item[$oid][] = $row;
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
            $all_item_roster_rows = $roster_rows_by_item[$order_item_id] ?? [];
            $sync_health = self::rosterItemSyncHealth($all_item_roster_rows);
            $reasons = self::classifyMismatchReasons($woo, $roster);
            $reasons = self::appendFragmentedRosterReason($reasons, $sync_health);

            if (empty($reasons)) {
                continue;
            }

            foreach ($reasons as $reason) {
                if (!isset($reason_counts[$reason])) {
                    $reason_counts[$reason] = 0;
                }
                $reason_counts[$reason]++;
            }

            $mismatches_all[] = $this->buildMismatchRow(
                $order_item_id,
                $woo,
                $roster,
                $reasons,
                $sync_health
            );
        }

        $filtered_mismatches = self::filterMismatchesByReason($mismatches_all, (string) $filters['reason_filter']);
        $total_mismatches = count($filtered_mismatches);
        $paged = array_slice($filtered_mismatches, $filters['offset'], $filters['limit']);

        return [
            'filters' => $filters,
            'summary' => [
                'woo_rows' => count($woo_map),
                'roster_rows' => count($roster_map),
                'intersection' => count(array_intersect(array_keys($woo_map), array_keys($roster_map))),
                'only_woo' => count(array_diff(array_keys($woo_map), array_keys($roster_map))),
                'only_rosters' => count(array_diff(array_keys($roster_map), array_keys($woo_map))),
                'mismatch_rows' => count($mismatches_all),
                'filtered_mismatch_rows' => $total_mismatches,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
            'reason_counts' => $reason_counts,
            'pagination' => [
                'limit' => $filters['limit'],
                'offset' => $filters['offset'],
                'returned' => count($paged),
                'total' => $total_mismatches,
                'total_unfiltered' => count($mismatches_all),
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
        $roster_rows_by_item = [];
        foreach ($roster_rows as $row) {
            $oid = (int) ($row['order_item_id'] ?? 0);
            if ($oid > 0) {
                if (!isset($roster_rows_by_item[$oid])) {
                    $roster_rows_by_item[$oid] = [];
                }
                $roster_rows_by_item[$oid][] = $row;
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
            $all_item_roster_rows = $roster_rows_by_item[$order_item_id] ?? [];
            $sync_health = self::rosterItemSyncHealth($all_item_roster_rows);
            $reasons = self::classifyMismatchReasons($woo, $roster);
            $reasons = self::appendFragmentedRosterReason($reasons, $sync_health);
            if (empty($reasons)) {
                continue;
            }

            if (!empty($filters['reason_filter'])
                && !in_array((string) $filters['reason_filter'], $reasons, true)) {
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

        $trace = [
            'order_id' => $order_id,
            'order_item_id' => $order_item_id,
            'line_item' => $line_item,
            'item_meta' => $meta_rows,
            'roster_rows' => $roster_rows,
            'sync_health' => self::rosterItemSyncHealth(is_array($roster_rows) ? $roster_rows : []),
        ];

        return $trace;
    }

    /**
     * Run safe fixes for a single Woo line item.
     *
     * @param int $order_item_id
     * @return array
     */
    public function runSafeFixForOrderItem(int $order_item_id): array {
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return [
                'status' => 'error',
                'message' => __('Invalid order item ID.', 'intersoccer-reports-rosters'),
                'order_item_id' => $order_item_id,
            ];
        }

        $started = microtime(true);
        $run_id = 'itemfix_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);
        $trace_before = $this->traceItem(['order_item_id' => $order_item_id]);
        $woo = $this->fetchWooRowByOrderItemId($order_item_id);
        $roster_rows = isset($trace_before['roster_rows']) && is_array($trace_before['roster_rows']) ? $trace_before['roster_rows'] : [];
        $roster = null;
        foreach ($roster_rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($roster === null || $this->rowCompletenessRank($row) > $this->rowCompletenessRank($roster)) {
                $roster = $row;
            }
        }

        $reasons_before = self::classifyMismatchReasons($woo, $roster);
        $needs_name_repair = false;
        $needs_full_rebuild = in_array('missing_in_rosters', $reasons_before, true);
        foreach ($roster_rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (function_exists('intersoccer_roster_row_needs_order_resync')
                && intersoccer_roster_row_needs_order_resync($row)) {
                $needs_full_rebuild = true;
            }
            if (function_exists('intersoccer_roster_row_names_incomplete')
                && intersoccer_roster_row_names_incomplete($row)) {
                $needs_name_repair = true;
            }
        }

        $results = [
            'fixed_missing_in_rosters' => 0,
            'fixed_missing_in_woo_meta' => 0,
            'quarantined_missing_in_woo' => 0,
            'rebuilt_player_names' => 0,
            'rebuilt_from_order' => 0,
            'deleted_incomplete_rows' => 0,
            'backfilled_player_names' => 0,
            'aligned_event_signature' => '',
            'errors' => [],
        ];

        if ($needs_name_repair) {
            $results['backfilled_player_names'] += $this->backfillPlayerNamesForOrderItemRows($roster_rows);
        }

        if ($needs_full_rebuild && function_exists('intersoccer_oop_get_roster_builder')) {
            $rebuilt = $this->rebuildRosterNamesForOrderItem($order_item_id);
            if ($rebuilt) {
                $results['rebuilt_from_order'] = 1;
                $results['rebuilt_player_names']++;
            } else {
                $results['errors'][] = sprintf('Failed full roster rebuild for order_item_id=%d', $order_item_id);
            }
            $results['deleted_incomplete_rows'] = $this->deleteIncompleteRosterRowsForOrderItem($order_item_id);
        } elseif ($needs_name_repair && function_exists('intersoccer_oop_get_roster_builder')) {
            $rebuilt = $this->rebuildRosterNamesForOrderItem($order_item_id);
            if ($rebuilt) {
                $results['rebuilt_player_names']++;
            } else {
                $results['errors'][] = sprintf('Failed rebuilding roster names for order_item_id=%d', $order_item_id);
            }
        }

        $trace_mid = $this->traceItem(['order_item_id' => $order_item_id]);
        $roster_rows_mid = isset($trace_mid['roster_rows']) && is_array($trace_mid['roster_rows']) ? $trace_mid['roster_rows'] : [];
        $results['backfilled_player_names'] += $this->backfillPlayerNamesForOrderItemRows($roster_rows_mid);
        $roster_mid = null;
        foreach ($roster_rows_mid as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($roster_mid === null || $this->rowCompletenessRank($row) > $this->rowCompletenessRank($roster_mid)) {
                $roster_mid = $row;
            }
        }
        $reasons_mid = self::classifyMismatchReasons($woo, $roster_mid);

        if (in_array('missing_in_rosters', $reasons_mid, true) && $woo !== null) {
            if ($this->insertRosterPlaceholderFromWooRow($woo)) {
                $results['fixed_missing_in_rosters']++;
            } else {
                $results['errors'][] = sprintf('Failed inserting roster for order_item_id=%d', $order_item_id);
            }
        }

        if ($woo !== null && function_exists('intersoccer_oop_get_roster_builder')) {
            $rebuilt_retry = $this->rebuildRosterNamesForOrderItem($order_item_id);
            if ($rebuilt_retry) {
                $results['rebuilt_from_order'] = 1;
                if ($results['rebuilt_player_names'] < 1) {
                    $results['rebuilt_player_names'] = 1;
                }
            }
        }

        $alignment = $this->alignOrderItemRosterToConsolidatedGroup($order_item_id);
        if (is_array($alignment)) {
            $results['aligned_event_signature'] = (string) ($alignment['event_signature'] ?? '');
        }

        $trace_pre_final = $this->traceItem(['order_item_id' => $order_item_id]);
        $rows_pre_final = isset($trace_pre_final['roster_rows']) && is_array($trace_pre_final['roster_rows'])
            ? $trace_pre_final['roster_rows'] : [];
        $results['backfilled_player_names'] += $this->backfillPlayerNamesForOrderItemRows($rows_pre_final);

        if ($woo !== null && $roster !== null && in_array('missing_in_woo_meta', $reasons_before, true)) {
            $backfill_value = trim((string) ($roster['course_day'] ?? ''));
            if ($backfill_value !== '' && self::normalizeComparableValue($backfill_value) !== '') {
                $ok_a = $this->upsertOrderItemMeta($order_item_id, 'pa_course-day', $backfill_value);
                $ok_b = $this->upsertOrderItemMeta($order_item_id, 'attribute_pa_course-day', $backfill_value);
                if ($ok_a || $ok_b) {
                    $results['fixed_missing_in_woo_meta']++;
                } else {
                    $results['errors'][] = sprintf('Failed backfilling course day meta for order_item_id=%d', $order_item_id);
                }
            }
        }

        if (in_array('missing_in_woo', $reasons_before, true) && $roster !== null) {
            if ($this->quarantineRosterOrderItem($order_item_id, $run_id)) {
                $results['quarantined_missing_in_woo']++;
            } else {
                $results['errors'][] = sprintf('Failed quarantining orphan roster for order_item_id=%d', $order_item_id);
            }
        }

        $trace_after = $this->traceItem(['order_item_id' => $order_item_id]);

        $woo_after = $this->fetchWooRowByOrderItemId($order_item_id);
        $roster_rows_after = isset($trace_after['roster_rows']) && is_array($trace_after['roster_rows']) ? $trace_after['roster_rows'] : [];
        $roster_after = null;
        foreach ($roster_rows_after as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($roster_after === null || $this->rowCompletenessRank($row) > $this->rowCompletenessRank($roster_after)) {
                $roster_after = $row;
            }
        }
        $reasons_after = self::classifyMismatchReasons($woo_after, $roster_after);

        $action_count = $results['fixed_missing_in_rosters'] + $results['fixed_missing_in_woo_meta']
            + $results['quarantined_missing_in_woo'] + $results['rebuilt_player_names'] + $results['rebuilt_from_order']
            + $results['backfilled_player_names'];
        $status = 'no_action';
        if (empty($reasons_after)) {
            $status = $action_count > 0 ? 'fixed' : 'in_sync';
        } elseif ($action_count > 0) {
            $status = 'fixed_partial';
        } elseif (!empty($results['errors'])) {
            $status = 'error';
        }

        return [
            'status' => $status,
            'message' => $status === 'in_sync'
                ? __('Order item is already in sync.', 'intersoccer-reports-rosters')
                : __('Per-item sync action completed.', 'intersoccer-reports-rosters'),
            'run_id' => $run_id,
            'order_item_id' => $order_item_id,
            'reasons_before' => $reasons_before,
            'reasons_after' => $reasons_after,
            'trace_before' => $trace_before,
            'trace_after' => $trace_after,
            'fix_results' => $results,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
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

        if (function_exists('intersoccer_roster_row_names_incomplete')
            && intersoccer_roster_row_names_incomplete($roster)) {
            $reasons[] = 'incomplete_player_data';
        } elseif (function_exists('intersoccer_roster_row_needs_order_resync')
            && intersoccer_roster_row_needs_order_resync($roster)) {
            $reasons[] = 'incomplete_player_data';
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
        $requested = strtolower(trim($requested_activity));
        if ($requested === 'all' || $requested === '') {
            return true;
        }

        $product_attr = self::normalizeComparableValue($woo_row['product_activity_type_attr'] ?? '');
        $line_attr = self::normalizeComparableValue($woo_row['activity_type'] ?? '');

        if ($product_attr === '' && $line_attr === '') {
            return false;
        }

        $needle = self::activityTypeNeedle($requested);
        $candidate = $line_attr !== '' ? $line_attr : $product_attr;

        return strpos($candidate, $needle) !== false;
    }

    /**
     * Fallback scoping when Woo row is missing (orphan roster rows).
     *
     * @param array<string,mixed> $roster_row
     * @param string $requested_activity
     * @return bool
     */
    public static function isRosterActivityTypeScopedRow(array $roster_row, string $requested_activity): bool {
        $requested = strtolower(trim($requested_activity));
        if ($requested === 'all' || $requested === '') {
            return true;
        }

        $needle = self::activityTypeNeedle($requested);
        $atype = self::normalizeComparableValue($roster_row['activity_type'] ?? '');
        if ($atype === '') {
            return false;
        }
        return strpos($atype, $needle) !== false;
    }

    /**
     * Default reconcile-aligned order statuses.
     *
     * @return array<int,string>
     */
    public static function defaultOrderStatuses(): array {
        return self::DEFAULT_ORDER_STATUSES;
    }

    /**
     * Append fragmented_roster when roster rows are duplicated or need resync.
     *
     * @param array<int,string> $reasons
     * @param array<string,mixed> $sync_health
     * @return array<int,string>
     */
    public static function appendFragmentedRosterReason(array $reasons, array $sync_health): array {
        $count = (int) ($sync_health['roster_row_count'] ?? 0);
        $needs_resync = !empty($sync_health['needs_resync']);
        if ($count > 1 || ($count > 0 && $needs_resync)) {
            $reasons[] = 'fragmented_roster';
        }
        return array_values(array_unique($reasons));
    }

    /**
     * Filter mismatch rows by a single reason key.
     *
     * @param array<int,array<string,mixed>> $mismatches
     * @param string $reason_filter
     * @return array<int,array<string,mixed>>
     */
    public static function filterMismatchesByReason(array $mismatches, string $reason_filter): array {
        $reason_filter = sanitize_text_field($reason_filter);
        if ($reason_filter === '' || !in_array($reason_filter, self::KNOWN_MISMATCH_REASONS, true)) {
            return $mismatches;
        }

        return array_values(array_filter($mismatches, static function (array $row) use ($reason_filter): bool {
            $reasons = isset($row['reasons']) && is_array($row['reasons']) ? $row['reasons'] : [];
            return in_array($reason_filter, $reasons, true);
        }));
    }

    /**
     * Expose sanitized filters for tests and tooling.
     *
     * @param array $filters
     * @return array
     */
    public function getSanitizedFilters(array $filters): array {
        return $this->sanitizeFilters($filters);
    }

    /**
     * Map UI activity labels to comparable substrings.
     *
     * @param string $requested_activity
     * @return string
     */
    private static function activityTypeNeedle(string $requested_activity): string {
        $requested = strtolower(trim($requested_activity));
        if ($requested === 'camp') {
            return 'camp';
        }
        if ($requested === 'tournament') {
            return 'tournament';
        }
        return 'course';
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

        $activity_raw = isset($filters['activity_type']) ? sanitize_text_field((string) $filters['activity_type']) : 'All';
        $activity_lower = strtolower($activity_raw);
        if ($activity_lower === 'camp') {
            $activity_type = 'Camp';
        } elseif ($activity_lower === 'tournament') {
            $activity_type = 'Tournament';
        } elseif ($activity_lower === 'course') {
            $activity_type = 'Course';
        } else {
            $activity_type = 'All';
        }

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 200;
        if ($limit <= 0) {
            $limit = 200;
        }
        $limit = min($limit, self::MAX_LIMIT);

        $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;
        if ($offset < 0) {
            $offset = 0;
        }

        $reason_filter = isset($filters['reason_filter']) ? sanitize_text_field((string) $filters['reason_filter']) : '';
        if ($reason_filter !== '' && !in_array($reason_filter, self::KNOWN_MISMATCH_REASONS, true)) {
            $reason_filter = '';
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
            'order_statuses' => $this->sanitizeOrderStatuses($filters['order_statuses'] ?? null),
            'reason_filter' => $reason_filter,
        ];
    }

    /**
     * @param mixed $statuses
     * @return array<int,string>
     */
    private function sanitizeOrderStatuses($statuses): array {
        if ($statuses === null || $statuses === '') {
            return self::DEFAULT_ORDER_STATUSES;
        }

        if (is_string($statuses)) {
            $statuses = array_map('trim', explode(',', $statuses));
        }
        if (!is_array($statuses)) {
            return self::DEFAULT_ORDER_STATUSES;
        }

        $allowed = [
            'wc-completed',
            'wc-processing',
            'wc-pending',
            'wc-on-hold',
            'wc-cancelled',
            'wc-refunded',
            'wc-failed',
            'completed',
            'processing',
            'pending',
            'on-hold',
            'cancelled',
            'refunded',
            'failed',
        ];

        $out = [];
        foreach ($statuses as $status) {
            $status = sanitize_text_field((string) $status);
            if ($status === '' || !in_array($status, $allowed, true)) {
                continue;
            }
            if (strpos($status, 'wc-') !== 0) {
                $status = 'wc-' . $status;
            }
            $out[] = $status;
        }

        return $out !== [] ? array_values(array_unique($out)) : self::DEFAULT_ORDER_STATUSES;
    }

    /**
     * Fetch relevant Woo line items with normalized diagnostics fields.
     *
     * @param array $filters
     * @return array<int,array<string,mixed>>
     */
    private function fetchWooRows(array $filters): array {
        global $wpdb;

        $order_ids = $this->resolveOrderIdsForFilters($filters);
        if ($order_ids === []) {
            return [];
        }

        $rows = $this->fetchWooLineItemRowsForOrderIds($order_ids);
        if ($rows === []) {
            return [];
        }

        $order_meta = $this->loadOrderMetaForIds($order_ids);
        $out = [];

        foreach ($rows as $row) {
            $order_id = (int) ($row['order_id'] ?? 0);
            if ($order_id <= 0) {
                continue;
            }

            if (!empty($filters['order_id']) && $order_id !== (int) $filters['order_id']) {
                continue;
            }

            $product_id = (int) ($row['product_id'] ?? 0);
            $variation_id = (int) ($row['variation_id'] ?? 0);
            if (!$this->isRowRosterEligible($product_id, $variation_id, $row)) {
                continue;
            }

            if (!$this->matchesActivityTypeFilter($row, (string) $filters['activity_type'])) {
                continue;
            }

            $meta = $order_meta[$order_id] ?? ['status' => '', 'date' => ''];
            $row['order_status'] = (string) ($meta['status'] ?? '');
            $row['order_date'] = (string) ($meta['date'] ?? '');

            if (!$this->wooRowMatchesYear($row, (int) $filters['year'], $meta['date'] ?? '')) {
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
     * @param array $filters
     * @return array<int,int>
     */
    private function resolveOrderIdsForFilters(array $filters): array {
        $order_id = (int) ($filters['order_id'] ?? 0);
        if ($order_id > 0) {
            return [$order_id];
        }

        $year = (int) $filters['year'];
        $statuses = is_array($filters['order_statuses'] ?? null) ? $filters['order_statuses'] : self::DEFAULT_ORDER_STATUSES;
        $order_ids = [];

        if (function_exists('wc_get_orders')) {
            $by_date = wc_get_orders([
                'limit' => -1,
                'status' => $statuses,
                'date_created' => $year . '-01-01...' . $year . '-12-31',
                'return' => 'ids',
            ]);
            if (is_array($by_date)) {
                foreach ($by_date as $id) {
                    $order_ids[] = (int) $id;
                }
            }
        }

        $season_order_ids = $this->resolveOrderIdsBySeasonYear($year, $statuses);
        $order_ids = array_values(array_unique(array_merge($order_ids, $season_order_ids)));

        if ($order_ids === []) {
            $order_ids = $this->resolveOrderIdsFromPosts($year, $statuses);
        }

        return array_values(array_filter(array_map('intval', $order_ids), static function (int $id): bool {
            return $id > 0;
        }));
    }

    /**
     * @param int $year
     * @param array<int,string> $statuses
     * @return array<int,int>
     */
    private function resolveOrderIdsBySeasonYear(int $year, array $statuses): array {
        global $wpdb;

        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $like = '%' . $wpdb->esc_like((string) $year) . '%';

        $sql = "SELECT DISTINCT oi.order_id
                FROM {$order_items_table} oi
                INNER JOIN {$order_itemmeta_table} om
                    ON oi.order_item_id = om.order_item_id
                WHERE oi.order_item_type = 'line_item'
                  AND om.meta_key IN ('Season', 'pa_program-season')
                  AND om.meta_value LIKE %s";

        $ids = $wpdb->get_col($wpdb->prepare($sql, $like));
        if (!is_array($ids) || $ids === []) {
            return [];
        }

        if (!function_exists('wc_get_order')) {
            return array_map('intval', $ids);
        }

        $status_lookup = [];
        foreach ($statuses as $status) {
            $status_lookup[$status] = true;
            $status_lookup[str_replace('wc-', '', $status)] = true;
        }

        $filtered = [];
        foreach ($ids as $id) {
            $order = wc_get_order((int) $id);
            if (!$order) {
                continue;
            }
            $order_status = (string) $order->get_status();
            $prefixed = strpos($order_status, 'wc-') === 0 ? $order_status : 'wc-' . $order_status;
            if (isset($status_lookup[$order_status]) || isset($status_lookup[$prefixed])) {
                $filtered[] = (int) $id;
            }
        }

        return $filtered;
    }

    /**
     * Legacy posts-table fallback when wc_get_orders is unavailable.
     *
     * @param int $year
     * @param array<int,string> $statuses
     * @return array<int,int>
     */
    private function resolveOrderIdsFromPosts(int $year, array $statuses): array {
        global $wpdb;

        if ($statuses === []) {
            return [];
        }

        $posts_table = $wpdb->prefix . 'posts';
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $params = array_merge($statuses, [$year]);
        $sql = "SELECT ID FROM {$posts_table}
                WHERE post_type = 'shop_order'
                  AND post_status IN ({$placeholders})
                  AND YEAR(post_date) = %d";

        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * @param array<int,int> $order_ids
     * @return array<int,array<string,mixed>>
     */
    private function fetchWooLineItemRowsForOrderIds(array $order_ids): array {
        global $wpdb;

        $order_ids = array_values(array_filter(array_map('intval', $order_ids), static function (int $id): bool {
            return $id > 0;
        }));
        if ($order_ids === []) {
            return [];
        }

        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $all_rows = [];

        foreach (array_chunk($order_ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
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
                    FROM {$order_items_table} oi
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
                    WHERE oi.order_item_type = 'line_item'
                      AND oi.order_id IN ({$placeholders})";

            $chunk_rows = $wpdb->get_results($wpdb->prepare($sql, ...$chunk), ARRAY_A);
            if (is_array($chunk_rows)) {
                $all_rows = array_merge($all_rows, $chunk_rows);
            }
        }

        return $all_rows;
    }

    /**
     * @param array<int,int> $order_ids
     * @return array<int,array{status:string,date:string}>
     */
    private function loadOrderMetaForIds(array $order_ids): array {
        $meta = [];
        foreach ($order_ids as $order_id) {
            $order_id = (int) $order_id;
            if ($order_id <= 0) {
                continue;
            }
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $created = $order->get_date_created();
                    $meta[$order_id] = [
                        'status' => (string) $order->get_status(),
                        'date' => $created ? $created->date('Y-m-d H:i:s') : '',
                    ];
                    continue;
                }
            }
            $meta[$order_id] = ['status' => '', 'date' => ''];
        }
        return $meta;
    }

    /**
     * @param array<string,mixed> $row
     * @param int $year
     * @param string $order_date
     * @return bool
     */
    private function wooRowMatchesYear(array $row, int $year, string $order_date): bool {
        $season = (string) ($row['season'] ?? '');
        if ($season !== '' && strpos($season, (string) $year) !== false) {
            return true;
        }
        if ($order_date !== '') {
            $parsed = strtotime($order_date);
            if ($parsed !== false && (int) date('Y', $parsed) === $year) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $product_id
     * @param int $variation_id
     * @param array<string,mixed> $row
     * @return bool
     */
    private function isRowRosterEligible(int $product_id, int $variation_id, array $row): bool {
        if (function_exists('intersoccer_get_product_type_safe')) {
            $ptype = strtolower((string) intersoccer_get_product_type_safe($product_id, $variation_id ?: null));
            if ($ptype !== '' && in_array($ptype, self::ROSTER_ELIGIBLE_PRODUCT_TYPES, true)) {
                return true;
            }
            if ($ptype !== '' && !in_array($ptype, self::ROSTER_ELIGIBLE_PRODUCT_TYPES, true)) {
                return false;
            }
        }

        $atype = strtolower(trim((string) ($row['activity_type'] ?? '')));
        $product_attr = strtolower(trim((string) ($row['product_activity_type_attr'] ?? '')));
        $candidate = $atype !== '' ? $atype : $product_attr;
        if ($candidate === '') {
            return false;
        }

        foreach (self::ROSTER_ELIGIBLE_PRODUCT_TYPES as $eligible) {
            if (strpos($candidate, $eligible) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     * @param string $activity_type
     * @return bool
     */
    private function matchesActivityTypeFilter(array $row, string $activity_type): bool {
        return self::isActivityTypeScopedRow($row, $activity_type);
    }

    /**
     * @param int $order_item_id
     * @param array<string,mixed>|null $woo
     * @param array<string,mixed>|null $roster
     * @param array<int,string> $reasons
     * @param array<string,mixed> $sync_health
     * @return array<string,mixed>
     */
    private function buildMismatchRow(
        int $order_item_id,
        ?array $woo,
        ?array $roster,
        array $reasons,
        array $sync_health
    ): array {
        $order_id = (int) (($woo['order_id'] ?? 0) ?: ($roster['order_id'] ?? 0));
        $participant_name = '';
        if ($order_item_id > 0 && function_exists('intersoccer_resolve_assigned_attendee_from_order_item')) {
            $participant_name = intersoccer_resolve_assigned_attendee_from_order_item($order_item_id);
        }

        $roster_player_name = '';
        if (is_array($roster)) {
            $roster_player_name = trim((string) ($roster['player_name'] ?? ''));
            if ($roster_player_name === '') {
                $roster_player_name = trim(
                    (string) ($roster['player_first_name'] ?? $roster['first_name'] ?? '') . ' '
                    . (string) ($roster['player_last_name'] ?? $roster['last_name'] ?? '')
                );
            }
        }

        return [
            'order_item_id' => $order_item_id,
            'order_id' => $order_id,
            'product_id' => (int) (($woo['product_id'] ?? 0) ?: ($roster['product_id'] ?? 0)),
            'variation_id' => (int) (($woo['variation_id'] ?? 0) ?: ($roster['variation_id'] ?? 0)),
            'participant_name' => $participant_name,
            'product_name' => (string) (($woo['order_item_name'] ?? '') ?: ($roster['product_name'] ?? '')),
            'activity_type' => (string) (($woo['activity_type'] ?? '') ?: ($roster['activity_type'] ?? '')),
            'order_status' => (string) ($woo['order_status'] ?? ''),
            'order_date' => (string) ($woo['order_date'] ?? ''),
            'roster_player_name' => $roster_player_name,
            'roster_row_count' => (int) ($sync_health['roster_row_count'] ?? 0),
            'sync_health' => $sync_health,
            'edit_order_url' => $this->buildOrderAdminUrl($order_id),
            'woo_venue' => $woo['venue_value'] ?? '',
            'roster_venue' => $roster['venue'] ?? '',
            'woo_course_day' => $woo['course_day_value'] ?? '',
            'roster_course_day' => $roster['course_day'] ?? '',
            'reasons' => $reasons,
        ];
    }

    /**
     * @param int $order_id
     * @return string
     */
    private function buildOrderAdminUrl(int $order_id): string {
        if ($order_id <= 0) {
            return '';
        }
        if (class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore')) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
        }
        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    /**
     * Remove incomplete / diagnostics placeholder rows before a full rebuild.
     *
     * @param int $order_item_id
     * @return int Rows deleted.
     */
    private function deleteIncompleteRosterRowsForOrderItem(int $order_item_id): int {
        global $wpdb;
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return 0;
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE order_item_id = %d", $order_item_id),
            ARRAY_A
        );
        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $complete_row_ids = [];
        $incomplete_row_ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $needs_delete = function_exists('intersoccer_roster_row_needs_order_resync')
                ? intersoccer_roster_row_needs_order_resync($row)
                : (function_exists('intersoccer_roster_row_is_sync_placeholder') && intersoccer_roster_row_is_sync_placeholder($row));
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($needs_delete) {
                $incomplete_row_ids[] = $id;
            } else {
                $complete_row_ids[] = $id;
            }
        }

        if ($incomplete_row_ids === []) {
            return 0;
        }

        // Never remove the only roster row for this line item (avoids dropping event participant counts).
        if ($complete_row_ids === []) {
            return 0;
        }

        $deleted = 0;
        foreach ($incomplete_row_ids as $id) {
            if ($wpdb->delete($table, ['id' => $id], ['%d'])) {
                $deleted++;
            }
        }

        return $deleted;
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

        $event_signature = '';
        if (function_exists('intersoccer_roster_row_resolve_event_signature_for_url')) {
            $event_signature = intersoccer_roster_row_resolve_event_signature_for_url([
                'activity_type' => (string) ($woo_row['activity_type'] ?? ''),
                'venue' => (string) ($woo_row['venue_value'] ?? ''),
                'course_day' => (string) ($woo_row['course_day_value'] ?? ''),
                'product_id' => (int) ($woo_row['product_id'] ?? 0),
                'variation_id' => (int) ($woo_row['variation_id'] ?? 0),
                'product_name' => (string) ($woo_row['order_item_name'] ?? ''),
            ]);
        }

        $first_name = 'Unknown';
        $last_name = 'Unknown';
        $player_name = 'Unknown Player';
        if (function_exists('intersoccer_resolve_assigned_attendee_from_order_item')) {
            $attendee = intersoccer_resolve_assigned_attendee_from_order_item($order_item_id);
            if ($attendee !== '' && function_exists('intersoccer_roster_parse_attendee_display_name')) {
                $parsed = intersoccer_roster_parse_attendee_display_name($attendee);
                if (trim((string) ($parsed['first_name'] ?? '')) !== '') {
                    $first_name = $parsed['first_name'];
                    $last_name = $parsed['last_name'] !== '' ? $parsed['last_name'] : $first_name;
                    $player_name = $parsed['player_name'] !== '' ? $parsed['player_name'] : trim($first_name . ' ' . $last_name);
                }
            }
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'order_id' => (int) ($woo_row['order_id'] ?? 0),
                'order_item_id' => $order_item_id,
                'variation_id' => (int) ($woo_row['variation_id'] ?? 0),
                'product_id' => (int) ($woo_row['product_id'] ?? 0),
                'player_name' => $player_name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'booking_type' => 'single-day',
                'product_name' => (string) ($woo_row['order_item_name'] ?? 'Unknown Product'),
                'activity_type' => (string) ($woo_row['activity_type'] ?? ''),
                'venue' => (string) ($woo_row['venue_value'] ?? ''),
                'course_day' => (string) ($woo_row['course_day_value'] ?? ''),
                'event_signature' => $event_signature,
                'player_first_name' => $first_name,
                'player_last_name' => $last_name,
                'parent_first_name' => 'Unknown',
                'parent_last_name' => 'Unknown',
                'created_at' => current_time('mysql'),
            ],
            [
                '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
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

        $sql = "SELECT id, order_item_id, order_id, product_id, variation_id, activity_type,
                       venue, course_day, season, canton_region, start_date, end_date,
                       player_name, player_first_name, player_last_name, first_name, last_name, product_name
                FROM {$table}
                WHERE order_item_id > 0";

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $year = (int) $filters['year'];
        $filtered = [];

        foreach ($rows as $row) {
            if (!$this->matchesRosterActivityTypeFilter($row, (string) $filters['activity_type'])) {
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
     * @param string $activity_type
     * @return bool
     */
    private function matchesRosterActivityTypeFilter(array $row, string $activity_type): bool {
        return self::isRosterActivityTypeScopedRow($row, $activity_type);
    }

    /**
     * Fetch normalized Woo diagnostics row for one order item.
     *
     * @param int $order_item_id
     * @return array<string,mixed>|null
     */
    private function fetchWooRowByOrderItemId(int $order_item_id): ?array {
        global $wpdb;
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return null;
        }

        $posts_table = $wpdb->prefix . 'posts';
        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

        $sql = "SELECT
                    oi.order_item_id,
                    oi.order_id,
                    oi.order_item_name,
                    om_product_id.meta_value AS product_id,
                    om_variation_id.meta_value AS variation_id,
                    COALESCE(NULLIF(om_venue.meta_value, ''), om_venue_attr.meta_value, om_venue_us.meta_value, om_venue_us_attr.meta_value) AS venue_value,
                    COALESCE(NULLIF(om_course_day.meta_value, ''), om_course_day_attr.meta_value, om_course_day_us.meta_value, om_course_day_us_attr.meta_value) AS course_day_value,
                    COALESCE(om_activity_type.meta_value, pm_activity_type.meta_value) AS activity_type,
                    pm_activity_type.meta_value AS product_activity_type_attr
                FROM {$order_items_table} oi
                LEFT JOIN {$posts_table} p ON p.ID = oi.order_id
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
                LEFT JOIN {$wpdb->postmeta} pm_activity_type
                    ON om_product_id.meta_value = pm_activity_type.post_id AND pm_activity_type.meta_key = 'pa_activity-type'
                WHERE oi.order_item_type = 'line_item'
                  AND oi.order_item_id = %d
                LIMIT 1";

        $row = $wpdb->get_row($wpdb->prepare($sql, $order_item_id), ARRAY_A);
        return is_array($row) ? $row : null;
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
        if (function_exists('intersoccer_roster_row_names_incomplete') && !intersoccer_roster_row_names_incomplete($row)) {
            $score += 2;
        }
        return $score;
    }

    /**
     * Align a line item's roster row to the dominant event_signature used by its consolidated listing group.
     *
     * @param int $order_item_id
     * @return array<string,mixed>|null
     */
    /**
     * Load roster-details.php helpers required for consolidated group alignment.
     */
    private function ensureRosterDetailsHelpersLoaded(): void {
        if (function_exists('intersoccer_fetch_roster_sibling_candidates_for_consolidation')) {
            return;
        }
        $details_file = dirname(__DIR__, 2) . '/includes/roster-details.php';
        if (is_readable($details_file)) {
            require_once $details_file;
        }
    }

    private function alignOrderItemRosterToConsolidatedGroup(int $order_item_id): ?array {
        global $wpdb;

        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return null;
        }

        $this->ensureRosterDetailsHelpersLoaded();

        if (!function_exists('intersoccer_consolidated_roster_group_key')) {
            return null;
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_item_id = %d ORDER BY id DESC LIMIT 1",
                $order_item_id
            ),
            ARRAY_A
        );
        if (!is_array($row) || empty($row['id'])) {
            return null;
        }

        $kind = function_exists('intersoccer_roster_consolidated_kind_for_row')
            ? intersoccer_roster_consolidated_kind_for_row($row)
            : (stripos((string) ($row['activity_type'] ?? ''), 'course') !== false ? 'course' : 'camp');
        $target_key = intersoccer_consolidated_roster_group_key($row, $kind);
        $candidates = function_exists('intersoccer_fetch_roster_sibling_candidates_for_consolidation')
            ? intersoccer_fetch_roster_sibling_candidates_for_consolidation($row, $kind, 500)
            : [];

        $signature_counts = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if (intersoccer_consolidated_roster_group_key($candidate, $kind) !== $target_key) {
                continue;
            }
            $sig = trim((string) ($candidate['event_signature'] ?? ''));
            if ($sig === '' || strcasecmp($sig, 'N/A') === 0) {
                continue;
            }
            if (!isset($signature_counts[$sig])) {
                $signature_counts[$sig] = 0;
            }
            $signature_counts[$sig]++;
        }

        if ($signature_counts === []) {
            $signature_counts = $this->resolveDominantEventSignatureCountsForRow($row);
        }

        $dominant_signature = '';
        $dominant_count = 0;
        foreach ($signature_counts as $sig => $count) {
            if ($count > $dominant_count) {
                $dominant_count = $count;
                $dominant_signature = $sig;
            }
        }

        $current_signature = trim((string) ($row['event_signature'] ?? ''));
        $updates = ['is_placeholder' => 0];
        $changed_signature = false;

        if ($dominant_signature !== '' && $dominant_signature !== $current_signature) {
            $updates['event_signature'] = $dominant_signature;
            $changed_signature = true;
        }

        $needs_placeholder_clear = (int) ($row['is_placeholder'] ?? 0) === 1;
        if ($changed_signature || $needs_placeholder_clear) {
            $wpdb->update(
                $table,
                $updates,
                ['id' => (int) $row['id']],
                $changed_signature ? ['%d', '%s'] : ['%d'],
                ['%d']
            );
        }

        return [
            'roster_id' => (int) $row['id'],
            'consolidated_group_key' => $target_key,
            'previous_event_signature' => $current_signature,
            'event_signature' => $dominant_signature !== '' ? $dominant_signature : $current_signature,
            'signature_changed' => $changed_signature,
            'dominant_signature_count' => $dominant_count,
            'known_group_signatures' => array_keys($signature_counts),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,int>
     */
    private function resolveDominantEventSignatureCountsForRow(array $row): array {
        global $wpdb;

        $variation_id = (int) ($row['variation_id'] ?? 0);
        $order_item_id = (int) ($row['order_item_id'] ?? 0);
        if ($variation_id <= 0) {
            return [];
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $activity_like = stripos((string) ($row['activity_type'] ?? ''), 'camp') !== false ? 'camp' : 'course';
        $sql = "SELECT event_signature, COUNT(*) AS sig_count
                FROM {$table}
                WHERE variation_id = %d
                  AND order_item_id != %d
                  AND event_signature IS NOT NULL
                  AND event_signature != ''
                  AND event_signature != 'N/A'
                  AND (activity_type LIKE %s OR activity_type LIKE %s)";
        $params = [
            $variation_id,
            $order_item_id,
            '%' . $activity_like . '%',
            '%' . ucfirst($activity_like) . '%',
        ];

        $venue = trim((string) ($row['venue'] ?? ''));
        if ($venue !== '' && strcasecmp($venue, 'N/A') !== 0) {
            $sql .= ' AND venue = %s';
            $params[] = $venue;
        }

        $course_day = trim((string) ($row['course_day'] ?? ''));
        if ($activity_like === 'course' && $course_day !== '' && strcasecmp($course_day, 'N/A') !== 0) {
            $sql .= ' AND course_day = %s';
            $params[] = $course_day;
        }

        $sql .= ' GROUP BY event_signature ORDER BY sig_count DESC LIMIT 5';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $counts = [];
        foreach ($rows as $sig_row) {
            if (!is_array($sig_row)) {
                continue;
            }
            $sig = trim((string) ($sig_row['event_signature'] ?? ''));
            if ($sig === '') {
                continue;
            }
            $counts[$sig] = (int) ($sig_row['sig_count'] ?? 0);
        }

        return $counts;
    }

    /**
     * Rebuild roster row(s) for an order line via OOP builder (updates player names from Woo meta).
     *
     * @param int $order_item_id
     * @return bool
     */
    private function rebuildRosterNamesForOrderItem(int $order_item_id): bool {
        global $wpdb;
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0 || !function_exists('intersoccer_oop_get_roster_builder')) {
            return false;
        }

        $order_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d LIMIT 1",
            $order_item_id
        ));
        if ($order_id <= 0) {
            return false;
        }

        try {
            $builder = intersoccer_oop_get_roster_builder();
            $builder->buildRosterFromOrder($order_id, [
                'validate_data' => false,
                'skip_duplicates' => false,
                'update_existing' => true,
                'skip_age_group_validation' => true,
            ]);
            return $this->countRosterRowsForOrderItem($order_item_id) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the order line has at least one roster row with real player names.
     *
     * @param array<int,array<string,mixed>> $roster_rows
     */
    public static function rosterItemSyncHealth(array $roster_rows): array {
        $count = count($roster_rows);
        $has_incomplete = false;
        $needs_resync = false;
        foreach ($roster_rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (function_exists('intersoccer_roster_row_names_incomplete')
                && intersoccer_roster_row_names_incomplete($row)) {
                $has_incomplete = true;
            }
            if (function_exists('intersoccer_roster_row_needs_order_resync')
                && intersoccer_roster_row_needs_order_resync($row)) {
                $needs_resync = true;
            }
        }

        return [
            'roster_row_count' => $count,
            'in_sync' => $count === 1 && !$has_incomplete && !$needs_resync,
            'has_incomplete_player' => $has_incomplete,
            'needs_resync' => $needs_resync,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $roster_rows
     */
    private function backfillPlayerNamesForOrderItemRows(array $roster_rows): int {
        $updated = 0;
        foreach ($roster_rows as $row) {
            if (!is_array($row) || !function_exists('intersoccer_roster_backfill_player_name_fields')) {
                continue;
            }
            $filled = intersoccer_roster_backfill_player_name_fields($row);
            if (function_exists('intersoccer_roster_persist_player_name_fields')
                && intersoccer_roster_persist_player_name_fields($filled)) {
                $updated++;
            }
        }
        return $updated;
    }

    private function countRosterRowsForOrderItem(int $order_item_id): int {
        global $wpdb;
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return 0;
        }
        $table = $wpdb->prefix . 'intersoccer_rosters';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE order_item_id = %d",
            $order_item_id
        ));
    }
}

