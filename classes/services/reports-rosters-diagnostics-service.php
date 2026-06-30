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
     * Sync queue compares only completed orders by default (matches roster build scope).
     */
    private const DEFAULT_ORDER_STATUSES = [
        'wc-completed',
    ];

    /**
     * Product types that should appear in roster sync tooling.
     */
    private const ROSTER_ELIGIBLE_PRODUCT_TYPES = ['camp', 'course', 'tournament'];

    /**
     * Product types that are definitively excluded from roster sync (not inconclusive).
     */
    private const ROSTER_INELIGIBLE_PRODUCT_TYPES = ['birthday', 'gift', 'merchandise', 'fee', 'donation'];

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

        $bulk_woo_count = count($woo_map);
        $this->supplementDiagnosticsMaps($woo_map, $roster_map, $roster_rows_by_item, $filters, $bulk_woo_count);

        $all_ids = array_values(array_unique(array_merge(array_keys($woo_map), array_keys($roster_map))));
        sort($all_ids, SORT_NUMERIC);

        $reason_counts = [];
        $mismatches_all = [];

        foreach ($all_ids as $order_item_id) {
            $woo = $woo_map[$order_item_id] ?? null;
            $roster = $roster_map[$order_item_id] ?? null;
            $all_item_roster_rows = $roster_rows_by_item[$order_item_id] ?? [];
            $reasons = self::computeItemMismatchReasons($woo, $roster, $all_item_roster_rows);

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
                self::rosterItemSyncHealth($all_item_roster_rows)
            );
        }

        $filtered_mismatches = self::filterMismatchesByReason($mismatches_all, (string) $filters['reason_filter']);
        $total_mismatches = count($filtered_mismatches);
        $paged = array_slice($filtered_mismatches, $filters['offset'], $filters['limit']);

        $bucket_analysis = $this->analyzeMismatchBuckets($mismatches_all, $filters);
        $this->logFullDiscrepancyTrace($mismatches_all, $filters, $woo_map, $roster_map);
        // #region agent log
        error_log('[intersoccer-332b44] mismatch bucket analysis: ' . wp_json_encode([
            'runId' => 'post-fix-unknown-type',
            'woo_map_bulk' => $bulk_woo_count,
            'woo_map_final' => count($woo_map),
            'roster_map' => count($roster_map),
            'intersection' => count(array_intersect(array_keys($woo_map), array_keys($roster_map))),
            'year' => $filters['year'] ?? '',
            'order_statuses' => $filters['order_statuses'] ?? [],
            'summary' => $bucket_analysis['summary'] ?? [],
            'missing_in_woo_causes' => $bucket_analysis['missing_in_woo_causes'] ?? [],
            'missing_in_woo_samples' => $bucket_analysis['missing_in_woo_samples'] ?? [],
            'missing_in_rosters_items' => $bucket_analysis['missing_in_rosters_items'] ?? [],
            'missing_in_woo_meta_samples' => $bucket_analysis['missing_in_woo_meta_samples'] ?? [],
            'course_day_mismatch_samples' => $bucket_analysis['course_day_mismatch_samples'] ?? [],
        ]));
        // #endregion

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
                'bucket_analysis' => $bucket_analysis['summary'] ?? [],
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

        $bulk_woo_count = count($woo_map);
        $this->supplementDiagnosticsMaps($woo_map, $roster_map, $roster_rows_by_item, $filters, $bulk_woo_count);

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
            $reasons = self::computeItemMismatchReasons($woo, $roster, $all_item_roster_rows);
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
                if ($this->backfillMissingWooMetaFromRoster((int) $order_item_id, $roster)) {
                    $results['fixed_missing_in_woo_meta']++;
                } else {
                    $results['errors'][] = sprintf('Failed backfilling Woo meta for order_item_id=%d', $order_item_id);
                }
            }

            if (in_array('missing_in_woo', $reasons, true) && $roster !== null) {
                if (!$this->woocommerceLineItemExists((int) $order_item_id)) {
                    if ($this->quarantineRosterOrderItem((int) $order_item_id, $run_id)) {
                        $results['quarantined_missing_in_woo']++;
                    } else {
                        $results['errors'][] = sprintf('Failed quarantining orphan roster for order_item_id=%d', $order_item_id);
                    }
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

        $reasons_before = self::computeItemMismatchReasons($woo, $roster, $roster_rows);
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

        $roster_for_meta = $roster;
        foreach ($rows_pre_final as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($roster_for_meta === null || $this->rowCompletenessRank($row) > $this->rowCompletenessRank($roster_for_meta)) {
                $roster_for_meta = $row;
            }
        }

        if ($woo !== null && $roster_for_meta !== null && in_array('missing_in_woo_meta', $reasons_before, true)) {
            if ($this->backfillMissingWooMetaFromRoster($order_item_id, $roster_for_meta)) {
                $results['fixed_missing_in_woo_meta']++;
            } else {
                $results['errors'][] = sprintf('Failed backfilling Woo meta for order_item_id=%d', $order_item_id);
            }
        }

        if (in_array('missing_in_woo', $reasons_before, true) && $roster !== null) {
            if (!$this->woocommerceLineItemExists($order_item_id)) {
                if ($this->quarantineRosterOrderItem($order_item_id, $run_id)) {
                    $results['quarantined_missing_in_woo']++;
                } else {
                    $results['errors'][] = sprintf('Failed quarantining orphan roster for order_item_id=%d', $order_item_id);
                }
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
        $reasons_after = self::computeItemMismatchReasons($woo_after, $roster_after, $roster_rows_after);

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
        $woo_day = self::normalizeCourseDayComparableValue($woo['course_day_value'] ?? '');
        $roster_day = self::normalizeCourseDayComparableValue($roster['course_day'] ?? '');

        if (($woo_venue === '' && ($woo['venue_value'] ?? '') !== '') || ($roster_venue === '' && ($roster['venue'] ?? '') !== '')) {
            $reasons[] = 'unknown_placeholder_persisted';
        }
        if (($woo_day === '' && ($woo['course_day_value'] ?? '') !== '') || ($roster_day === '' && ($roster['course_day'] ?? '') !== '')) {
            $reasons[] = 'unknown_placeholder_persisted';
        }
        if ($woo_venue === '' && $roster_venue !== '') {
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
     * Default sync-queue order statuses (completed only).
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
     * Match runDiagnostics mismatch detection for a single order line item.
     *
     * @param array|null $woo
     * @param array|null $roster
     * @param array<int,array<string,mixed>> $all_roster_rows
     * @return array<int,string>
     */
    public static function computeItemMismatchReasons(?array $woo, ?array $roster, array $all_roster_rows): array {
        $sync_health = self::rosterItemSyncHealth($all_roster_rows);
        $reasons = self::classifyMismatchReasons($woo, $roster);
        return self::appendFragmentedRosterReason($reasons, $sync_health);
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
     * Normalize course-day labels across EN/FR/DE for sync comparisons.
     *
     * @param mixed $value
     * @return string English lowercase day name or empty string
     */
    public static function normalizeCourseDayComparableValue($value): string {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        $basic = self::normalizeComparableValue($raw);
        if ($basic === '') {
            return '';
        }

        static $day_map = [
            'monday' => 'monday',
            'mon' => 'monday',
            'lundi' => 'monday',
            'montag' => 'monday',
            'tuesday' => 'tuesday',
            'tue' => 'tuesday',
            'mardi' => 'tuesday',
            'dienstag' => 'tuesday',
            'wednesday' => 'wednesday',
            'wed' => 'wednesday',
            'mercredi' => 'wednesday',
            'mittwoch' => 'wednesday',
            'thursday' => 'thursday',
            'thu' => 'thursday',
            'jeudi' => 'thursday',
            'donnerstag' => 'thursday',
            'friday' => 'friday',
            'fri' => 'friday',
            'vendredi' => 'friday',
            'freitag' => 'friday',
            'saturday' => 'saturday',
            'sat' => 'saturday',
            'samedi' => 'saturday',
            'samstag' => 'saturday',
            'sunday' => 'sunday',
            'sun' => 'sunday',
            'dimanche' => 'sunday',
            'sonntag' => 'sunday',
        ];

        if (isset($day_map[$basic])) {
            return $day_map[$basic];
        }

        if (function_exists('sanitize_title')) {
            $slug = strtolower((string) sanitize_title($raw));
            if (isset($day_map[$slug])) {
                return $day_map[$slug];
            }
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
            if ($ptype !== '' && in_array($ptype, self::ROSTER_INELIGIBLE_PRODUCT_TYPES, true)) {
                return false;
            }
        }

        $atype = strtolower(trim((string) ($row['activity_type'] ?? '')));
        $product_attr = strtolower(trim((string) ($row['product_activity_type_attr'] ?? '')));
        $candidate = $atype !== '' ? $atype : $product_attr;
        if ($candidate === '') {
            $candidate = $this->resolveActivityTypeCandidateFromProduct($product_id, $variation_id);
        }
        if ($candidate !== '') {
            foreach (self::ROSTER_ELIGIBLE_PRODUCT_TYPES as $eligible) {
                if (strpos($candidate, $eligible) !== false) {
                    return true;
                }
            }
        }

        $name = strtolower(trim((string) ($row['order_item_name'] ?? '')));
        if ($name !== '') {
            foreach (self::ROSTER_ELIGIBLE_PRODUCT_TYPES as $eligible) {
                if (strpos($name, $eligible) !== false) {
                    return true;
                }
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

        $activity_type = trim((string) ($woo_row['activity_type'] ?? ''));
        if ($activity_type === '') {
            $name = strtolower(trim((string) ($woo_row['order_item_name'] ?? '')));
            if (strpos($name, 'camp') !== false) {
                $activity_type = 'Camp';
            } elseif (strpos($name, 'course') !== false) {
                $activity_type = 'Course';
            } elseif (strpos($name, 'tournament') !== false) {
                $activity_type = 'Tournament';
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
                'activity_type' => $activity_type,
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
                       player_name, player_first_name, player_last_name, first_name, last_name, product_name,
                       event_signature, is_placeholder
                FROM {$table}
                WHERE order_item_id > 0";

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $year = (int) $filters['year'];
        $allowed_statuses = is_array($filters['order_statuses'] ?? null)
            ? $filters['order_statuses']
            : self::DEFAULT_ORDER_STATUSES;
        $order_ids = [];
        foreach ($rows as $row) {
            $order_id = (int) ($row['order_id'] ?? 0);
            if ($order_id > 0) {
                $order_ids[$order_id] = $order_id;
            }
        }
        $order_meta = $this->loadOrderMetaForIds(array_values($order_ids));

        $filtered = [];

        foreach ($rows as $row) {
            if ((int) ($row['is_placeholder'] ?? 0) === 1) {
                continue;
            }
            $order_id = (int) ($row['order_id'] ?? 0);
            if ($order_id <= 0) {
                continue;
            }
            $order_status = (string) ($order_meta[$order_id]['status'] ?? '');
            if (!$this->orderStatusAllowed($order_status, $allowed_statuses)) {
                continue;
            }
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
     * @param string $status Woo order status slug (with or without wc- prefix).
     * @param array<int,string> $allowed_statuses
     */
    private function orderStatusAllowed(string $status, array $allowed_statuses): bool {
        $normalized = $this->normalizeOrderStatusSlug($status);
        if ($normalized === '') {
            return false;
        }

        foreach ($allowed_statuses as $allowed) {
            if ($this->normalizeOrderStatusSlug((string) $allowed) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $status
     */
    private function normalizeOrderStatusSlug(string $status): string {
        $status = sanitize_text_field($status);
        if ($status === '') {
            return '';
        }

        return strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
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
                'skip_duplicates' => true,
                'update_existing' => true,
                'update_past_events' => true,
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

    /**
     * Collect all mismatching order item IDs for the given diagnostics scope.
     *
     * @param array $filters
     * @return array<int,int>
     */
    public function collectMismatchOrderItemIds(array $filters): array {
        $filters = $this->sanitizeFilters($filters);
        $filters['reason_filter'] = '';

        $ids = [];
        $offset = 0;
        $page_size = self::MAX_LIMIT;
        do {
            $filters['limit'] = $page_size;
            $filters['offset'] = $offset;
            $diag = $this->runDiagnostics($filters);
            foreach ($diag['mismatches'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['order_item_id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $total = (int) ($diag['pagination']['total_unfiltered'] ?? 0);
            $offset += $page_size;
        } while ($offset < $total);

        return array_values($ids);
    }

    /**
     * Run Fix Sync on a small set of order items (e.g. newly created during reconcile).
     *
     * @param array<int,int> $order_item_ids
     * @return array<string,mixed>
     */
    public function alignOrderItemsById(array $order_item_ids): array {
        $results = [
            'candidate_count' => 0,
            'fixed' => 0,
            'partial' => 0,
            'still_mismatch' => 0,
            'errors' => 0,
        ];

        foreach ($order_item_ids as $order_item_id) {
            $order_item_id = (int) $order_item_id;
            if ($order_item_id <= 0) {
                continue;
            }
            $results['candidate_count']++;
            $fix = $this->runSafeFixForOrderItem($order_item_id);
            $status = (string) ($fix['status'] ?? '');
            if ($status === 'in_sync' || $status === 'fixed') {
                $results['fixed']++;
            } elseif ($status === 'fixed_partial') {
                $results['partial']++;
            } elseif (!empty($fix['reasons_after'])) {
                $results['still_mismatch']++;
            }
            if ($status === 'error') {
                $results['errors']++;
            }
        }

        return $results;
    }

    /**
     * Build a transient-backed queue of mismatching order items for batched alignment.
     *
     * @param array $filters
     * @return array<string,mixed>
     */
    public function startReconcileAlignmentQueue(array $filters): array {
        $filters = $this->sanitizeFilters($filters);
        $ids = $this->collectMismatchOrderItemIds($filters);
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $queue_key = 'intersoccer_reconcile_align_' . max(0, $user_id);

        $payload = [
            'ids' => array_values($ids),
            'offset' => 0,
            'year' => (string) ($filters['year'] ?? ''),
            'filters' => $filters,
            'stats' => [
                'fixed' => 0,
                'partial' => 0,
                'still_mismatch' => 0,
                'errors' => 0,
            ],
            'created_at' => time(),
        ];

        set_transient($queue_key, $payload, HOUR_IN_SECONDS);

        return [
            'queue_key' => $queue_key,
            'total' => count($ids),
            'batch_size' => 25,
        ];
    }

    /**
     * Process the next batch from a reconcile alignment queue.
     *
     * @param string $queue_key
     * @param int $batch_size
     * @return array<string,mixed>
     */
    public function processReconcileAlignmentBatch(string $queue_key, int $batch_size = 25): array {
        $started = microtime(true);
        $queue_key = sanitize_key($queue_key);
        $batch_size = max(1, min(50, (int) $batch_size));
        $queue = get_transient($queue_key);
        if (!is_array($queue) || empty($queue['ids']) || !is_array($queue['ids'])) {
            return [
                'status' => 'error',
                'message' => __('Alignment queue not found or expired.', 'intersoccer-reports-rosters'),
                'complete' => true,
            ];
        }

        $ids = array_values(array_map('intval', $queue['ids']));
        $offset = (int) ($queue['offset'] ?? 0);
        $total = count($ids);
        $batch_ids = array_slice($ids, $offset, $batch_size);
        $stats = is_array($queue['stats'] ?? null) ? $queue['stats'] : [
            'fixed' => 0,
            'partial' => 0,
            'still_mismatch' => 0,
            'errors' => 0,
        ];

        foreach ($batch_ids as $order_item_id) {
            if ($order_item_id <= 0) {
                continue;
            }
            $fix = $this->runSafeFixForOrderItem($order_item_id);
            $status = (string) ($fix['status'] ?? '');
            if ($status === 'in_sync' || $status === 'fixed') {
                $stats['fixed']++;
            } elseif ($status === 'fixed_partial') {
                $stats['partial']++;
            } elseif (!empty($fix['reasons_after'])) {
                $stats['still_mismatch']++;
            }
            if ($status === 'error') {
                $stats['errors']++;
            }
        }

        $offset += count($batch_ids);
        $complete = $offset >= $total;
        $queue['offset'] = $offset;
        $queue['stats'] = $stats;

        $mismatch_after = null;
        $reason_counts_after = null;
        if ($complete) {
            delete_transient($queue_key);
            $filters = is_array($queue['filters'] ?? null) ? $queue['filters'] : ['year' => (string) ($queue['year'] ?? date('Y'))];
            $diag_after = $this->runDiagnostics(array_merge($filters, ['limit' => 1, 'offset' => 0]));
            $mismatch_after = (int) ($diag_after['summary']['mismatch_rows'] ?? 0);
            $reason_counts_after = is_array($diag_after['reason_counts'] ?? null) ? $diag_after['reason_counts'] : [];
        } else {
            set_transient($queue_key, $queue, HOUR_IN_SECONDS);
        }

        return [
            'status' => 'ok',
            'complete' => $complete,
            'processed' => $offset,
            'total' => $total,
            'batch_count' => count($batch_ids),
            'stats' => $stats,
            'mismatch_after' => $mismatch_after,
            'reason_counts_after' => $reason_counts_after,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Run per-item Fix Sync on every mismatch in scope (same rules as Roster Sync Queue).
     *
     * @param array $filters
     * @return array<string,mixed>
     */
    public function alignMismatchOrderItemsInScope(array $filters): array {
        $started = microtime(true);
        $ids = $this->collectMismatchOrderItemIds($filters);
        $results = [
            'candidate_count' => count($ids),
            'fixed' => 0,
            'partial' => 0,
            'still_mismatch' => 0,
            'errors' => 0,
        ];

        foreach ($ids as $order_item_id) {
            $fix = $this->runSafeFixForOrderItem((int) $order_item_id);
            $status = (string) ($fix['status'] ?? '');
            if ($status === 'in_sync' || $status === 'fixed') {
                $results['fixed']++;
            } elseif ($status === 'fixed_partial') {
                $results['partial']++;
            } elseif (!empty($fix['reasons_after'])) {
                $results['still_mismatch']++;
            }
            if ($status === 'error') {
                $results['errors']++;
            }
        }

        $results['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
        return $results;
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

    /**
     * Pair Woo and roster maps so existing line items are compared even when bulk Woo fetch skipped them.
     *
     * @param array<int,array<string,mixed>> $woo_map
     * @param array<int,array<string,mixed>> $roster_map
     * @param array<int,array<int,array<string,mixed>>> $roster_rows_by_item
     * @param array<string,mixed> $filters
     */
    private function supplementDiagnosticsMaps(
        array &$woo_map,
        array &$roster_map,
        array &$roster_rows_by_item,
        array $filters,
        int $bulk_woo_count = 0
    ): void {
        $stats = [
            'bulk_woo_count' => $bulk_woo_count,
            'woo_added' => 0,
            'roster_added' => 0,
            'woo_pair_failures' => [],
        ];

        foreach (array_keys($roster_map) as $order_item_id) {
            $order_item_id = (int) $order_item_id;
            if ($order_item_id <= 0 || isset($woo_map[$order_item_id])) {
                continue;
            }
            $roster_row = $roster_map[$order_item_id] ?? null;
            $pair = $this->fetchWooRowForDiagnosticsPairing($order_item_id, $filters, is_array($roster_row) ? $roster_row : null);
            if ($pair !== null) {
                $woo_map[$order_item_id] = $this->enrichWooRowFromRoster($pair, is_array($roster_row) ? $roster_row : null);
                $stats['woo_added']++;
            } else {
                $reason = (string) ($this->classifyWooPairingFailure($order_item_id, $filters, is_array($roster_row) ? $roster_row : null)['cause'] ?? 'unknown');
                if (!isset($stats['woo_pair_failures'][$reason])) {
                    $stats['woo_pair_failures'][$reason] = 0;
                }
                $stats['woo_pair_failures'][$reason]++;
            }
        }

        foreach (array_keys($woo_map) as $order_item_id) {
            $order_item_id = (int) $order_item_id;
            if ($order_item_id <= 0 || isset($roster_map[$order_item_id])) {
                continue;
            }
            $row = $this->fetchRosterRowForDiagnosticsPairing(
                $order_item_id,
                $filters,
                $woo_map[$order_item_id] ?? null
            );
            if ($row === null) {
                continue;
            }
            if (!isset($roster_rows_by_item[$order_item_id])) {
                $roster_rows_by_item[$order_item_id] = [];
            }
            $roster_rows_by_item[$order_item_id][] = $row;
            $roster_map[$order_item_id] = $row;
            $stats['roster_added']++;
        }

        $stats['woo_final_count'] = count($woo_map);
        // #region agent log
        error_log('[intersoccer-332b44] supplement diagnostics maps: ' . wp_json_encode([
            'runId' => 'post-fix-unknown-type',
            'stats' => $stats,
        ]));
        // #endregion
    }

    /**
     * Copy roster context onto a Woo diagnostics row when Woo metadata is sparse.
     *
     * @param array<string,mixed> $woo
     * @param array<string,mixed>|null $roster_row
     * @return array<string,mixed>
     */
    private function enrichWooRowFromRoster(array $woo, ?array $roster_row): array {
        if (!is_array($roster_row)) {
            return $woo;
        }
        if (trim((string) ($woo['activity_type'] ?? '')) === '' && trim((string) ($roster_row['activity_type'] ?? '')) !== '') {
            $woo['activity_type'] = (string) $roster_row['activity_type'];
        }
        if (trim((string) ($woo['season'] ?? '')) === '' && trim((string) ($roster_row['season'] ?? '')) !== '') {
            $woo['season'] = (string) $roster_row['season'];
        }
        return $woo;
    }

    /**
     * @param int $order_item_id
     * @param array<string,mixed> $filters
     * @param array<string,mixed>|null $roster_row
     * @return array<string,mixed>|null
     */
    private function fetchWooRowForDiagnosticsPairing(int $order_item_id, array $filters, ?array $roster_row = null): ?array {
        if (!$this->woocommerceLineItemExists($order_item_id)) {
            return null;
        }

        $woo = $this->fetchWooRowByOrderItemId($order_item_id);
        if ($woo === null) {
            return null;
        }

        $order_id = (int) ($woo['order_id'] ?? 0);
        if ($order_id <= 0) {
            return null;
        }

        $allowed_statuses = is_array($filters['order_statuses'] ?? null)
            ? $filters['order_statuses']
            : self::DEFAULT_ORDER_STATUSES;
        $order_meta = $this->loadOrderMetaForIds([$order_id]);
        $order_status = (string) ($order_meta[$order_id]['status'] ?? '');
        if (!$this->orderStatusAllowed($order_status, $allowed_statuses)) {
            return null;
        }

        $order_date = (string) ($order_meta[$order_id]['date'] ?? '');
        $year = (int) ($filters['year'] ?? (int) date('Y'));
        if (is_array($roster_row) && !empty($roster_row['season'])) {
            $woo['season'] = (string) $roster_row['season'];
        }
        $year_ok = $this->wooRowMatchesYear($woo, $year, $order_date);
        if (!$year_ok && is_array($roster_row) && $this->rosterMatchesYear($roster_row, $year)) {
            $year_ok = true;
        }
        if (!$year_ok) {
            return null;
        }

        $activity = (string) ($filters['activity_type'] ?? 'All');
        if (is_array($roster_row)) {
            return $this->enrichWooRowFromRoster($woo, $roster_row);
        }

        if (!$this->matchesActivityTypeFilter($woo, $activity)) {
            return null;
        }

        return $woo;
    }

    /**
     * @param int $order_item_id
     * @param array<string,mixed> $filters
     * @param array<string,mixed>|null $roster_row
     * @return array<string,mixed>
     */
    private function classifyWooPairingFailure(int $order_item_id, array $filters, ?array $roster_row = null): array {
        if (!$this->woocommerceLineItemExists($order_item_id)) {
            return ['cause' => 'line_item_deleted', 'hypothesisId' => 'H1'];
        }

        $woo = $this->fetchWooRowByOrderItemId($order_item_id);
        if ($woo === null) {
            return ['cause' => 'line_item_unfetchable', 'hypothesisId' => 'H1'];
        }

        $order_id = (int) ($woo['order_id'] ?? 0);
        $allowed_statuses = is_array($filters['order_statuses'] ?? null)
            ? $filters['order_statuses']
            : self::DEFAULT_ORDER_STATUSES;
        $order_meta = $this->loadOrderMetaForIds([$order_id]);
        $order_status = (string) ($order_meta[$order_id]['status'] ?? '');
        if (!$this->orderStatusAllowed($order_status, $allowed_statuses)) {
            return ['cause' => 'order_status_excluded', 'order_status' => $order_status, 'hypothesisId' => 'H2'];
        }

        $order_date = (string) ($order_meta[$order_id]['date'] ?? '');
        $year = (int) ($filters['year'] ?? (int) date('Y'));
        if (is_array($roster_row) && !empty($roster_row['season'])) {
            $woo['season'] = (string) $roster_row['season'];
        }
        $year_ok = $this->wooRowMatchesYear($woo, $year, $order_date);
        if (!$year_ok && is_array($roster_row) && $this->rosterMatchesYear($roster_row, $year)) {
            $year_ok = true;
        }
        if (!$year_ok) {
            return ['cause' => 'woo_year_scope_miss', 'hypothesisId' => 'H3'];
        }

        $activity = (string) ($filters['activity_type'] ?? 'All');
        if (!$this->matchesActivityTypeFilter($woo, $activity)) {
            if (!is_array($roster_row) || !$this->matchesRosterActivityTypeFilter($roster_row, $activity)) {
                return ['cause' => 'activity_type_filter', 'hypothesisId' => 'H4'];
            }
        }

        return ['cause' => 'unknown_pair_failure', 'hypothesisId' => 'H6'];
    }

    /**
     * @param int $order_item_id
     * @param array<string,mixed> $filters
     * @param array<string,mixed>|null $woo_row
     * @return array<string,mixed>|null
     */
    private function fetchRosterRowForDiagnosticsPairing(int $order_item_id, array $filters, ?array $woo_row = null): ?array {
        $row = $this->loadRosterRowFromDatabase($order_item_id);
        if ($row === null || (int) ($row['is_placeholder'] ?? 0) === 1) {
            return null;
        }

        $order_id = (int) ($row['order_id'] ?? 0);
        if ($order_id <= 0) {
            return null;
        }

        $allowed_statuses = is_array($filters['order_statuses'] ?? null)
            ? $filters['order_statuses']
            : self::DEFAULT_ORDER_STATUSES;
        $order_meta = $this->loadOrderMetaForIds([$order_id]);
        $order_status = (string) ($order_meta[$order_id]['status'] ?? '');
        if (!$this->orderStatusAllowed($order_status, $allowed_statuses)) {
            return null;
        }

        $order_date = (string) ($order_meta[$order_id]['date'] ?? '');
        $year = (int) ($filters['year'] ?? (int) date('Y'));
        $year_ok = $this->rosterMatchesYear($row, $year);
        if (!$year_ok && is_array($woo_row)) {
            $year_ok = $this->wooRowMatchesYear($woo_row, $year, $order_date);
        }
        if (!$year_ok) {
            return null;
        }

        if (!$this->matchesRosterActivityTypeFilter($row, (string) ($filters['activity_type'] ?? 'All'))) {
            return null;
        }

        return $row;
    }

    /**
     * @param int $order_item_id
     * @return array<string,mixed>|null
     */
    private function loadRosterRowFromDatabase(int $order_item_id): ?array {
        global $wpdb;
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'intersoccer_rosters';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, order_item_id, order_id, product_id, variation_id, activity_type,
                        venue, course_day, season, canton_region, start_date, end_date,
                        player_name, player_first_name, player_last_name, first_name, last_name, product_name,
                        event_signature, is_placeholder
                 FROM {$table}
                 WHERE order_item_id = %d
                 ORDER BY id DESC
                 LIMIT 1",
                $order_item_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param int $order_item_id
     */
    private function woocommerceLineItemExists(int $order_item_id): bool {
        global $wpdb;
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return false;
        }

        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        return ((int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items_table} WHERE order_item_id = %d AND order_item_type = 'line_item'",
            $order_item_id
        ))) > 0;
    }

    /**
     * @param int $product_id
     * @param int $variation_id
     */
    private function resolveActivityTypeCandidateFromProduct(int $product_id, int $variation_id): string {
        $product_id = (int) $product_id;
        $variation_id = (int) $variation_id;
        if ($product_id <= 0) {
            return '';
        }

        if ($variation_id > 0) {
            $variation_attr = strtolower(trim((string) get_post_meta($variation_id, 'attribute_pa_activity-type', true)));
            if ($variation_attr !== '') {
                return $variation_attr;
            }
        }

        $product_attr = strtolower(trim((string) get_post_meta($product_id, 'pa_activity-type', true)));
        if ($product_attr !== '') {
            return $product_attr;
        }

        if (function_exists('intersoccer_get_product_type_safe')) {
            $ptype = strtolower((string) intersoccer_get_product_type_safe($product_id, $variation_id > 0 ? $variation_id : null));
            if ($ptype !== '') {
                return $ptype;
            }
        }

        return '';
    }

    /**
     * Backfill missing Woo venue/course-day metadata from roster values.
     *
     * @param int $order_item_id
     * @param array<string,mixed> $roster
     */
    private function backfillMissingWooMetaFromRoster(int $order_item_id, array $roster): bool {
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return false;
        }

        $changed = false;

        $venue = trim((string) ($roster['venue'] ?? ''));
        if ($venue !== '' && self::normalizeVenueComparableValue($venue) !== '') {
            $ok_a = $this->upsertOrderItemMeta($order_item_id, 'pa_intersoccer-venues', $venue);
            $ok_b = $this->upsertOrderItemMeta($order_item_id, 'attribute_pa_intersoccer-venues', $venue);
            $ok_c = $this->upsertOrderItemMeta($order_item_id, 'pa_intersoccer_venues', $venue);
            $ok_d = $this->upsertOrderItemMeta($order_item_id, 'attribute_pa_intersoccer_venues', $venue);
            $changed = $changed || $ok_a || $ok_b || $ok_c || $ok_d;
        }

        $course_day = trim((string) ($roster['course_day'] ?? ''));
        if ($course_day !== '' && self::normalizeCourseDayComparableValue($course_day) !== '') {
            $ok_a = $this->upsertOrderItemMeta($order_item_id, 'pa_course-day', $course_day);
            $ok_b = $this->upsertOrderItemMeta($order_item_id, 'attribute_pa_course-day', $course_day);
            $ok_c = $this->upsertOrderItemMeta($order_item_id, 'pa_course_day', $course_day);
            $ok_d = $this->upsertOrderItemMeta($order_item_id, 'attribute_pa_course_day', $course_day);
            $changed = $changed || $ok_a || $ok_b || $ok_c || $ok_d;
        }

        return $changed;
    }

    /**
     * Classify mismatch buckets for sync-queue accuracy investigations.
     *
     * @param array<int,array<string,mixed>> $mismatches
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function analyzeMismatchBuckets(array $mismatches, array $filters): array {
        $missing_in_woo_causes = [];
        $missing_in_woo_samples = [];
        $missing_in_rosters_items = [];
        $missing_in_woo_meta_samples = [];
        $course_day_mismatch_samples = [];
        $missing_in_woo_meta_count = 0;
        $course_day_mismatch_count = 0;

        foreach ($mismatches as $row) {
            if (!is_array($row)) {
                continue;
            }
            $order_item_id = (int) ($row['order_item_id'] ?? 0);
            $reasons = isset($row['reasons']) && is_array($row['reasons']) ? $row['reasons'] : [];
            if ($order_item_id <= 0 || $reasons === []) {
                continue;
            }

            if (in_array('missing_in_woo', $reasons, true)) {
                $classification = $this->classifyMissingInWooCause($order_item_id, $filters);
                $cause = (string) ($classification['cause'] ?? 'unknown');
                if (!isset($missing_in_woo_causes[$cause])) {
                    $missing_in_woo_causes[$cause] = 0;
                }
                $missing_in_woo_causes[$cause]++;
                if (count($missing_in_woo_samples) < 8) {
                    $missing_in_woo_samples[] = array_merge(
                        ['order_item_id' => $order_item_id, 'order_id' => (int) ($row['order_id'] ?? 0)],
                        $classification
                    );
                }
            }

            if (in_array('missing_in_rosters', $reasons, true)) {
                $missing_in_rosters_items[] = $this->classifyMissingInRostersCause($order_item_id, $filters);
            }

            if (in_array('missing_in_woo_meta', $reasons, true)) {
                $missing_in_woo_meta_count++;
                $meta_detail = $this->classifyMissingInWooMetaCause($order_item_id, $row);
                $missing_in_woo_meta_samples[] = $meta_detail;
            }

            if (in_array('course_day_mismatch', $reasons, true)) {
                $course_day_mismatch_count++;
                if (count($course_day_mismatch_samples) < 8) {
                    $course_day_mismatch_samples[] = [
                    'order_item_id' => $order_item_id,
                    'order_id' => (int) ($row['order_id'] ?? 0),
                    'woo_course_day' => (string) ($row['woo_course_day'] ?? ''),
                    'roster_course_day' => (string) ($row['roster_course_day'] ?? ''),
                    'woo_norm' => self::normalizeComparableValue($row['woo_course_day'] ?? ''),
                    'roster_norm' => self::normalizeComparableValue($row['roster_course_day'] ?? ''),
                ];
                }
            }
        }

        return [
            'summary' => [
                'missing_in_woo' => array_sum($missing_in_woo_causes),
                'missing_in_rosters' => count($missing_in_rosters_items),
                'missing_in_woo_meta' => $missing_in_woo_meta_count,
                'course_day_mismatch' => $course_day_mismatch_count,
            ],
            'missing_in_woo_causes' => $missing_in_woo_causes,
            'missing_in_woo_samples' => $missing_in_woo_samples,
            'missing_in_rosters_items' => $missing_in_rosters_items,
            'missing_in_woo_meta_items' => $missing_in_woo_meta_samples,
            'missing_in_rosters_likely_causes' => $this->summarizeLikelyCauses($missing_in_rosters_items, 'likely_cause'),
            'missing_in_woo_meta_likely_causes' => $this->summarizeLikelyCauses($missing_in_woo_meta_samples, 'likely_cause'),
            'missing_in_woo_meta_samples' => array_slice($missing_in_woo_meta_samples, 0, 8),
            'course_day_mismatch_samples' => $course_day_mismatch_samples,
        ];
    }

    /**
     * @param int $order_item_id
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function classifyMissingInWooCause(int $order_item_id, array $filters): array {
        global $wpdb;

        $order_item_id = (int) $order_item_id;
        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        $line_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$items_table} WHERE order_item_id = %d AND order_item_type = 'line_item'",
            $order_item_id
        ));
        if ($line_exists === 0) {
            return ['cause' => 'line_item_deleted', 'hypothesisId' => 'H1'];
        }

        $woo_row = $this->fetchWooRowByOrderItemId($order_item_id);
        if ($woo_row === null) {
            return ['cause' => 'line_item_unfetchable', 'hypothesisId' => 'H1'];
        }

        $order_id = (int) ($woo_row['order_id'] ?? 0);
        $order_meta = $this->loadOrderMetaForIds([$order_id]);
        $order_status = (string) ($order_meta[$order_id]['status'] ?? '');
        $allowed_statuses = is_array($filters['order_statuses'] ?? null)
            ? $filters['order_statuses']
            : self::DEFAULT_ORDER_STATUSES;
        if (!$this->orderStatusAllowed($order_status, $allowed_statuses)) {
            return [
                'cause' => 'order_status_excluded',
                'order_status' => $order_status,
                'hypothesisId' => 'H2',
            ];
        }

        $product_id = (int) ($woo_row['product_id'] ?? 0);
        $variation_id = (int) ($woo_row['variation_id'] ?? 0);
        if (!$this->isRowRosterEligible($product_id, $variation_id, $woo_row)) {
            return ['cause' => 'not_roster_eligible', 'hypothesisId' => 'H4'];
        }

        if (!$this->matchesActivityTypeFilter($woo_row, (string) ($filters['activity_type'] ?? 'All'))) {
            return ['cause' => 'activity_type_filter', 'hypothesisId' => 'H4'];
        }

        $order_date = (string) ($order_meta[$order_id]['date'] ?? '');
        if (!$this->wooRowMatchesYear($woo_row, (int) ($filters['year'] ?? (int) date('Y')), $order_date)) {
            return [
                'cause' => 'woo_year_scope_miss',
                'order_date' => $order_date,
                'season' => (string) ($woo_row['season'] ?? ''),
                'hypothesisId' => 'H3',
            ];
        }

        if (!empty($filters['exclude_buyclub'])) {
            $is_buyclub = ((float) ($woo_row['line_subtotal'] ?? 0) > 0.0) && ((float) ($woo_row['line_total'] ?? 0) === 0.0);
            if ($is_buyclub) {
                return ['cause' => 'buyclub_excluded', 'hypothesisId' => 'H4'];
            }
        }

        return ['cause' => 'unknown_scope_miss', 'hypothesisId' => 'H6'];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param string $field
     * @return array<string,int>
     */
    private function summarizeLikelyCauses(array $items, string $field): array {
        $counts = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cause = (string) ($item[$field] ?? 'unknown');
            if (!isset($counts[$cause])) {
                $counts[$cause] = 0;
            }
            $counts[$cause]++;
        }
        return $counts;
    }

    /**
     * Write one NDJSON debug trace line for sync investigations.
     *
     * @param array<string,mixed> $payload
     */
    private function writeAgentDebugLog(array $payload): void {
        // #region agent log
        $path = '/home/jeremy-lee/projects/underdog/intersoccer/players-and-events/.cursor/debug-332b44.log';
        $payload['sessionId'] = '332b44';
        $payload['timestamp'] = (int) round(microtime(true) * 1000);
        @file_put_contents($path, wp_json_encode($payload) . "\n", FILE_APPEND | LOCK_EX);
        // #endregion
    }

    /**
     * Emit a per-row trace for every mismatch so each discrepancy can be audited.
     *
     * @param array<int,array<string,mixed>> $mismatches
     * @param array<string,mixed> $filters
     * @param array<int,array<string,mixed>> $woo_map
     * @param array<int,array<string,mixed>> $roster_map
     */
    private function logFullDiscrepancyTrace(
        array $mismatches,
        array $filters,
        array $woo_map,
        array $roster_map
    ): void {
        $by_reason = [];
        foreach ($mismatches as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reasons = isset($row['reasons']) && is_array($row['reasons']) ? $row['reasons'] : [];
            foreach ($reasons as $reason) {
                if (!isset($by_reason[$reason])) {
                    $by_reason[$reason] = 0;
                }
                $by_reason[$reason]++;
            }
        }

        $this->writeAgentDebugLog([
            'runId' => 'full-discrepancy-trace',
            'location' => 'runDiagnostics',
            'message' => 'discrepancy trace summary',
            'hypothesisId' => 'TRACE',
            'data' => [
                'year' => $filters['year'] ?? '',
                'order_statuses' => $filters['order_statuses'] ?? [],
                'woo_map_count' => count($woo_map),
                'roster_map_count' => count($roster_map),
                'intersection' => count(array_intersect(array_keys($woo_map), array_keys($roster_map))),
                'only_woo' => count(array_diff(array_keys($woo_map), array_keys($roster_map))),
                'only_roster' => count(array_diff(array_keys($roster_map), array_keys($woo_map))),
                'mismatch_row_count' => count($mismatches),
                'reason_totals' => $by_reason,
            ],
        ]);

        foreach ($mismatches as $row) {
            if (!is_array($row)) {
                continue;
            }
            $order_item_id = (int) ($row['order_item_id'] ?? 0);
            $reasons = isset($row['reasons']) && is_array($row['reasons']) ? $row['reasons'] : [];
            if ($order_item_id <= 0 || $reasons === []) {
                continue;
            }

            $detail = [
                'order_item_id' => $order_item_id,
                'order_id' => (int) ($row['order_id'] ?? 0),
                'reasons' => $reasons,
                'product_name' => (string) ($row['product_name'] ?? ''),
                'activity_type' => (string) ($row['activity_type'] ?? ''),
                'participant_name' => (string) ($row['participant_name'] ?? ''),
                'woo_venue' => (string) ($row['woo_venue'] ?? ''),
                'roster_venue' => (string) ($row['roster_venue'] ?? ''),
                'woo_course_day' => (string) ($row['woo_course_day'] ?? ''),
                'roster_course_day' => (string) ($row['roster_course_day'] ?? ''),
            ];

            if (in_array('missing_in_rosters', $reasons, true)) {
                $detail['missing_in_rosters'] = $this->classifyMissingInRostersCause($order_item_id, $filters);
            }
            if (in_array('missing_in_woo_meta', $reasons, true)) {
                $detail['missing_in_woo_meta'] = $this->classifyMissingInWooMetaCause($order_item_id, $row);
            }
            if (in_array('missing_in_woo', $reasons, true)) {
                $detail['missing_in_woo'] = $this->classifyMissingInWooCause($order_item_id, $filters);
            }

            $this->writeAgentDebugLog([
                'runId' => 'full-discrepancy-trace',
                'location' => 'runDiagnostics:row',
                'message' => 'mismatch row detail',
                'hypothesisId' => 'TRACE',
                'data' => $detail,
            ]);
        }
    }

    /**
     * @param int $order_item_id
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function classifyMissingInRostersCause(int $order_item_id, array $filters): array {
        $order_item_id = (int) $order_item_id;
        $woo_row = $this->fetchWooRowByOrderItemId($order_item_id);
        $has_attendee = false;
        $attendee_name = '';
        if (function_exists('intersoccer_resolve_assigned_attendee_from_order_item')) {
            $attendee = intersoccer_resolve_assigned_attendee_from_order_item($order_item_id);
            $attendee_name = $attendee;
            $has_attendee = $attendee !== '';
        }

        $roster_count = $this->countRosterRowsForOrderItem($order_item_id);
        $order_id = (int) ($woo_row['order_id'] ?? 0);
        $product_id = (int) ($woo_row['product_id'] ?? 0);
        $variation_id = (int) ($woo_row['variation_id'] ?? 0);
        $activity_type = trim((string) ($woo_row['activity_type'] ?? ''));
        $order_item_name = trim((string) ($woo_row['order_item_name'] ?? ''));

        $order_meta = $order_id > 0 ? $this->loadOrderMetaForIds([$order_id]) : [];
        $order_status = (string) ($order_meta[$order_id]['status'] ?? '');
        $order_date = (string) ($order_meta[$order_id]['date'] ?? '');

        $customer_id = 0;
        if ($order_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $customer_id = (int) $order->get_customer_id();
            }
        }

        $roster_eligible = is_array($woo_row)
            ? $this->isRowRosterEligible($product_id, $variation_id, $woo_row)
            : false;

        $likely_cause = 'reconcile_never_created_row';
        if (!$roster_eligible) {
            $likely_cause = 'not_roster_eligible';
        } elseif ($activity_type === '' && $order_item_name === '') {
            $likely_cause = 'activity_type_missing';
        } elseif ($customer_id <= 0) {
            $likely_cause = 'guest_order_no_customer_id';
        } elseif ($has_attendee && $roster_count === 0) {
            $likely_cause = 'reconcile_player_match_or_validation_failed';
        } elseif (!$has_attendee) {
            $likely_cause = 'no_assigned_attendee';
        }

        return [
            'order_item_id' => $order_item_id,
            'order_id' => $order_id,
            'order_status' => $order_status,
            'order_date' => $order_date,
            'customer_id' => $customer_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'order_item_name' => $order_item_name,
            'woo_activity_type' => $activity_type,
            'roster_eligible' => $roster_eligible,
            'has_assigned_attendee' => $has_attendee,
            'attendee_name' => $attendee_name,
            'roster_row_count' => $roster_count,
            'likely_cause' => $likely_cause,
            'safe_fix' => 'runSafeFixForOrderItem or Reconcile',
            'hypothesisId' => 'H7',
        ];
    }

    /**
     * @param int $order_item_id
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function classifyMissingInWooMetaCause(int $order_item_id, array $row): array {
        $order_item_id = (int) $order_item_id;
        $woo_raw = (string) ($row['woo_venue'] ?? '');
        $roster_raw = (string) ($row['roster_venue'] ?? '');
        $woo_norm = self::normalizeVenueComparableValue($woo_raw);
        $roster_norm = self::normalizeVenueComparableValue($roster_raw);

        return [
            'order_item_id' => $order_item_id,
            'order_id' => (int) ($row['order_id'] ?? 0),
            'woo_venue_raw' => $woo_raw,
            'roster_venue_raw' => $roster_raw,
            'woo_venue_norm' => $woo_norm,
            'roster_venue_norm' => $roster_norm,
            'woo_course_day' => (string) ($row['woo_course_day'] ?? ''),
            'roster_course_day' => (string) ($row['roster_course_day'] ?? ''),
            'venue_meta_keys' => $this->fetchVenueMetaKeysForOrderItem($order_item_id),
            'backfillable_from_roster' => $roster_norm !== '',
            'likely_cause' => $woo_norm === '' && $roster_norm !== '' ? 'woo_venue_meta_empty' : 'venue_normalization_mismatch',
            'safe_fix' => 'backfillMissingWooMetaFromRoster',
            'hypothesisId' => 'H8',
        ];
    }

    /**
     * @param int $order_item_id
     * @return array<int,array{meta_key:string,meta_value:string}>
     */
    private function fetchVenueMetaKeysForOrderItem(int $order_item_id): array {
        global $wpdb;
        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return [];
        }

        $table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$table}
             WHERE order_item_id = %d
               AND (
                    meta_key LIKE %s
                    OR meta_key LIKE %s
                    OR meta_key LIKE %s
               )
             ORDER BY meta_id ASC",
            $order_item_id,
            '%venue%',
            '%Venue%',
            '%intersoccer%'
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'meta_key' => (string) ($row['meta_key'] ?? ''),
                'meta_value' => (string) ($row['meta_value'] ?? ''),
            ];
        }
        return $out;
    }
}

