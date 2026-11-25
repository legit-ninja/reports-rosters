<?php
/**
 * Reports and Rosters → Advanced tools.
 *
 * Every repair write is a POST on this page: Preview, typed APPLY, GLOBAL
 * for shop-wide scope, a JSON backup, and a conflict-aware rollback.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

require_once __DIR__ . '/repair-contract.php';

$intersoccer_rr_season_rename_admin = __DIR__ . '/season-rename-reconcile-admin.php';
if (is_readable($intersoccer_rr_season_rename_admin)) {
    require_once $intersoccer_rr_season_rename_admin;
}

if (!function_exists('intersoccer_rr_advanced_tools_definitions')) {
    /**
     * @return array<string,array<string,mixed>>
     */
    function intersoccer_rr_advanced_tools_definitions(): array {
        return [
            'upgrade_database' => [
                'label' => __('Upgrade Database', 'intersoccer-reports-rosters'),
                'description' => __('Adds missing roster columns and records the schema version. Shop-wide. Rollback restores the recorded schema version option, not structural changes that were already applied.', 'intersoccer-reports-rosters'),
                'fields' => [],
            ],
            'process_orders' => [
                'label' => __('Process Orders', 'intersoccer-reports-rosters'),
                'description' => __('Fills missing roster rows for processing and on-hold orders and may mark those orders completed. This path is shop-wide.', 'intersoccer-reports-rosters'),
                'fields' => [],
            ],
            'reconcile_rosters' => [
                'label' => __('Reconcile Rosters', 'intersoccer-reports-rosters'),
                'description' => __('Syncs roster rows from orders in the date range or id list. An empty range is shop-wide and requires GLOBAL. This path does not delete roster rows. Use Season rename repair when the work is a renamed season.', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'date_from', 'label' => __('Order date from', 'intersoccer-reports-rosters'), 'type' => 'date'],
                    ['name' => 'date_to', 'label' => __('Order date to', 'intersoccer-reports-rosters'), 'type' => 'date'],
                    ['name' => 'order_ids', 'label' => __('Order IDs', 'intersoccer-reports-rosters'), 'type' => 'text'],
                    ['name' => 'product_ids', 'label' => __('Product IDs', 'intersoccer-reports-rosters'), 'type' => 'text'],
                ],
            ],
            'normalize_language' => [
                'label' => __('Normalize Roster Language (EN)', 'intersoccer-reports-rosters'),
                'description' => __('Rewrites event columns and linked order-line event metadata to English. Player fields are not changed. Shop-wide. The backup stores event columns for up to 2000 roster rows.', 'intersoccer-reports-rosters'),
                'fields' => [],
            ],
            'sync_placeholders' => [
                'label' => __('Sync All Placeholders', 'intersoccer-reports-rosters'),
                'description' => __('Creates or updates placeholder roster rows for published variations. Shop-wide.', 'intersoccer-reports-rosters'),
                'fields' => [],
            ],
            'financial_backfill' => [
                'label' => __('Financial Attribution Backfill', 'intersoccer-reports-rosters'),
                'description' => __('Allocates order-level discounts and refunds onto booking lines. Leave the start date empty only for a shop-wide run (GLOBAL).', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'start_date', 'label' => __('Start date', 'intersoccer-reports-rosters'), 'type' => 'date'],
                    ['name' => 'batch_size', 'label' => __('Batch size', 'intersoccer-reports-rosters'), 'type' => 'number'],
                ],
            ],
            'fix_issues' => [
                'label' => __('Fix Issues (Safe)', 'intersoccer-reports-rosters'),
                'description' => __('Inserts missing roster rows and backfills missing course-day metadata for the year or order you enter. Leave both empty only for a shop-wide run (GLOBAL).', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'year', 'label' => __('Year', 'intersoccer-reports-rosters'), 'type' => 'text'],
                    ['name' => 'order_id', 'label' => __('Order ID', 'intersoccer-reports-rosters'), 'type' => 'number'],
                ],
            ],
            'rebuild_selected' => [
                'label' => __('Rebuild selected signatures', 'intersoccer-reports-rosters'),
                'description' => __('Recomputes event_signature for the roster row IDs you list. A shop-wide signature rebuild is not available.', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'roster_ids', 'label' => __('Roster row IDs', 'intersoccer-reports-rosters'), 'type' => 'text'],
                ],
            ],
            'merge_signatures' => [
                'label' => __('Merge signatures', 'intersoccer-reports-rosters'),
                'description' => __('Rewrites event_signature from the source value to the target value. Scoped to those two signatures.', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'source_signature', 'label' => __('Source signature', 'intersoccer-reports-rosters'), 'type' => 'text'],
                    ['name' => 'target_signature', 'label' => __('Target signature', 'intersoccer-reports-rosters'), 'type' => 'text'],
                ],
            ],
            'repair_day_presence' => [
                'label' => __('Repair Day Presence', 'intersoccer-reports-rosters'),
                'description' => __('Rewrites day_presence for one event signature. Opened from a roster, the signature is filled in. The write still happens on this page.', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'event_signature', 'label' => __('Event signature', 'intersoccer-reports-rosters'), 'type' => 'text'],
                ],
            ],
        ];
    }
}

if (!function_exists('intersoccer_rr_advanced_tools_scope_from_post')) {
    function intersoccer_rr_advanced_tools_scope_from_post(string $tool): array {
        $definitions = intersoccer_rr_advanced_tools_definitions();
        $fields = $definitions[$tool]['fields'] ?? [];
        $scope = ['tool' => $tool];
        foreach ($fields as $field) {
            $name = (string) $field['name'];
            $raw = isset($_POST[$name]) ? wp_unslash($_POST[$name]) : '';
            $raw = is_array($raw) ? '' : (string) $raw;
            if (($field['type'] ?? '') === 'date') {
                $scope[$name] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
            } elseif (($field['type'] ?? '') === 'number') {
                $scope[$name] = (string) max(0, (int) $raw);
            } else {
                $scope[$name] = sanitize_text_field($raw);
            }
        }
        return $scope;
    }
}

if (!function_exists('intersoccer_rr_advanced_tools_empty_result')) {
    function intersoccer_rr_advanced_tools_empty_result(string $summary, bool $shop_wide, string $error = ''): array {
        return [
            'error' => $error,
            'summary' => $summary,
            'counts' => ['changed' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0],
            'rows' => [],
            'shop_wide' => $shop_wide,
            'wrote' => false,
        ];
    }
}

if (!function_exists('intersoccer_rr_advanced_tools_normalize_result')) {
    function intersoccer_rr_advanced_tools_normalize_result(array $result, bool $dry_run, bool $shop_wide): array {
        $counts = wp_parse_args(is_array($result['counts'] ?? null) ? $result['counts'] : [], [
            'changed' => 0,
            'added' => 0,
            'updated' => 0,
            'deleted' => 0,
        ]);
        if ((int) $counts['changed'] === 0) {
            $counts['changed'] = (int) $counts['added'] + (int) $counts['updated'] + (int) $counts['deleted'];
        }
        return [
            'error' => (string) ($result['error'] ?? ''),
            'summary' => (string) ($result['summary'] ?? ''),
            'counts' => $counts,
            'rows' => is_array($result['rows'] ?? null) ? $result['rows'] : [],
            'shop_wide' => $shop_wide || !empty($result['shop_wide']),
            'wrote' => $dry_run ? false : !empty($result['wrote']),
            'raw' => $result['raw'] ?? null,
        ];
    }
}

if (!function_exists('intersoccer_rr_advanced_tools_execute')) {
    function intersoccer_rr_advanced_tools_execute(string $tool, bool $dry_run, array $scope): array {
        $shop_wide = intersoccer_rr_scope_is_shop_wide($tool, $scope);
        if (isset($GLOBALS['intersoccer_rr_test_tool_runner']) && is_callable($GLOBALS['intersoccer_rr_test_tool_runner'])) {
            $override = call_user_func($GLOBALS['intersoccer_rr_test_tool_runner'], $tool, $dry_run, $scope);
            if (is_array($override)) {
                return intersoccer_rr_advanced_tools_normalize_result($override, $dry_run, $shop_wide);
            }
        }
        $filtered = apply_filters('intersoccer_rr_advanced_tools_execute', null, $tool, $dry_run, $scope);
        if (is_array($filtered)) {
            return intersoccer_rr_advanced_tools_normalize_result($filtered, $dry_run, $shop_wide);
        }
        switch ($tool) {
            case 'upgrade_database':
                $result = intersoccer_rr_tool_upgrade_database($dry_run);
                break;
            case 'process_orders':
                $result = intersoccer_rr_tool_process_orders($dry_run);
                break;
            case 'reconcile_rosters':
                $result = intersoccer_rr_tool_reconcile($dry_run, $scope);
                break;
            case 'normalize_language':
                $result = intersoccer_rr_tool_normalize($dry_run);
                break;
            case 'sync_placeholders':
                $result = intersoccer_rr_tool_placeholders($dry_run);
                break;
            case 'financial_backfill':
                $result = intersoccer_rr_tool_financial($dry_run, $scope);
                break;
            case 'fix_issues':
                $result = intersoccer_rr_tool_fix_issues($dry_run, $scope);
                break;
            case 'rebuild_selected':
                $result = intersoccer_rr_tool_rebuild_selected($dry_run, $scope);
                break;
            case 'merge_signatures':
                $result = intersoccer_rr_tool_merge_signatures($dry_run, $scope);
                break;
            case 'repair_day_presence':
                $result = intersoccer_rr_tool_repair_day_presence($dry_run, $scope);
                break;
            case 'season_rename':
                $result = function_exists('intersoccer_rr_season_rename_tool_run')
                    ? intersoccer_rr_season_rename_tool_run($dry_run, $scope)
                    : intersoccer_rr_advanced_tools_empty_result(__('Season rename repair is unavailable.', 'intersoccer-reports-rosters'), false, 'unavailable');
                break;
            default:
                $result = intersoccer_rr_advanced_tools_empty_result(__('Unknown repair.', 'intersoccer-reports-rosters'), false, 'unknown');
                break;
        }
        return intersoccer_rr_advanced_tools_normalize_result($result, $dry_run, $shop_wide);
    }
}

if (!function_exists('intersoccer_rr_tool_id_list')) {
    /**
     * @return int[]
     */
    function intersoccer_rr_tool_id_list(string $raw): array {
        if (function_exists('intersoccer_reconcile_normalize_id_list')) {
            return intersoccer_reconcile_normalize_id_list($raw);
        }
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $id = (int) $part;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('intersoccer_rr_tool_upgrade_database')) {
    function intersoccer_rr_tool_upgrade_database(bool $dry_run): array {
        $before = (string) get_option('intersoccer_db_version', '1.0.0');
        if ($dry_run) {
            return [
                'summary' => sprintf(
                    /* translators: %s: schema version */
                    __('Schema version is %s. Apply runs the database upgrade.', 'intersoccer-reports-rosters'),
                    $before
                ),
                'counts' => ['changed' => 1, 'added' => 0, 'updated' => 1, 'deleted' => 0],
                'rows' => [['id' => 'schema_version', 'before' => ['value' => $before], 'after' => ['value' => $before]]],
                'wrote' => false,
            ];
        }
        $ok = true;
        if (function_exists('intersoccer_upgrade_database')) {
            $ok = intersoccer_upgrade_database() !== false;
        }
        $after = (string) get_option('intersoccer_db_version', $before);
        return [
            'error' => $ok ? '' : __('Database upgrade failed.', 'intersoccer-reports-rosters'),
            'summary' => sprintf(
                /* translators: 1: version before, 2: version after */
                __('Schema version %1$s → %2$s.', 'intersoccer-reports-rosters'),
                $before,
                $after
            ),
            'counts' => ['changed' => 1, 'added' => 0, 'updated' => 1, 'deleted' => 0],
            'rows' => [['id' => 'schema_version', 'before' => ['value' => $before], 'after' => ['value' => $after]]],
            'wrote' => $ok,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_process_orders')) {
    function intersoccer_rr_tool_process_orders(bool $dry_run): array {
        $count = 0;
        if (function_exists('wc_get_orders')) {
            $ids = wc_get_orders([
                'limit' => -1,
                'status' => ['processing', 'on-hold'],
                'return' => 'ids',
            ]);
            $count = is_array($ids) ? count($ids) : 0;
        }
        if ($dry_run) {
            return [
                'summary' => sprintf(
                    /* translators: %d: order count */
                    __('Preview: %d processing or on-hold orders in scope. Nothing was written.', 'intersoccer-reports-rosters'),
                    $count
                ),
                'counts' => ['changed' => $count, 'added' => $count, 'updated' => 0, 'deleted' => 0],
                'rows' => [],
                'wrote' => false,
            ];
        }
        $processed = 0;
        if (function_exists('intersoccer_process_existing_orders')) {
            $raw = intersoccer_process_existing_orders();
            $processed = (int) (is_array($raw) ? ($raw['processed'] ?? 0) : 0);
        }
        return [
            'summary' => sprintf(
                /* translators: %d: processed orders */
                __('Processed %d orders.', 'intersoccer-reports-rosters'),
                $processed
            ),
            'counts' => ['changed' => $processed, 'added' => $processed, 'updated' => 0, 'deleted' => 0],
            'rows' => [],
            'wrote' => true,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_reconcile')) {
    function intersoccer_rr_tool_reconcile(bool $dry_run, array $scope): array {
        $added = 0;
        if (function_exists('wc_get_orders')) {
            $args = [
                'limit' => -1,
                'return' => 'ids',
                'status' => ['completed', 'processing', 'on-hold', 'pending'],
            ];
            $from = (string) ($scope['date_from'] ?? '');
            $to = (string) ($scope['date_to'] ?? '');
            if ($from !== '' || $to !== '') {
                $args['date_created'] = ($from !== '' ? $from : '1970-01-01') . '...' . ($to !== '' ? $to : gmdate('Y-m-d'));
            }
            $order_ids = intersoccer_rr_tool_id_list((string) ($scope['order_ids'] ?? ''));
            if ($order_ids !== []) {
                $args['include'] = $order_ids;
            }
            $ids = wc_get_orders($args);
            $added = is_array($ids) ? count($ids) : 0;
        }
        $updated = 0;
        $rows = [];
        $deleted = 0;
        if (!function_exists('intersoccer_rr_load_reconcile') || !intersoccer_rr_load_reconcile() || !function_exists('intersoccer_reconcile_rosters')) {
            if ($dry_run) {
                return [
                    'summary' => sprintf(
                        /* translators: 1: orders in scope, 2: deletes (always 0 on this path) */
                        __('Orders in scope: %1$d. Updates from the signature pass are unavailable. Deletes: %2$d. Nothing was written.', 'intersoccer-reports-rosters'),
                        $added,
                        0
                    ),
                    'counts' => ['changed' => $added, 'added' => $added, 'updated' => 0, 'deleted' => 0],
                    'rows' => [],
                    'wrote' => false,
                ];
            }
            return intersoccer_rr_advanced_tools_empty_result(
                __('Roster reconcile could not be loaded.', 'intersoccer-reports-rosters'),
                intersoccer_rr_scope_is_shop_wide('reconcile_rosters', $scope),
                'unavailable'
            );
        }
        $raw = intersoccer_reconcile_rosters([
            'dry_run' => $dry_run,
            'delete_obsolete' => false,
            'skip_order_sync' => $dry_run,
            'date_from' => (string) ($scope['date_from'] ?? ''),
            'date_to' => (string) ($scope['date_to'] ?? ''),
            'order_ids' => (string) ($scope['order_ids'] ?? ''),
            'product_ids' => (string) ($scope['product_ids'] ?? ''),
        ]);
        $season = is_array($raw['season_signature'] ?? null) ? $raw['season_signature'] : [];
        $changes = is_array($season['changes'] ?? null) ? $season['changes'] : [];
        $updated = (int) ($season['needing_rename_merge'] ?? $season['updated'] ?? 0);
        $deleted = (int) ($raw['deleted'] ?? 0);
        foreach (array_slice($changes, 0, 200) as $change) {
            if (!is_array($change)) {
                continue;
            }
            $rows[] = [
                'id' => (int) ($change['id'] ?? 0),
                'before' => [
                    'season' => (string) ($change['old_season'] ?? ''),
                    'event_signature' => (string) ($change['old_signature'] ?? ''),
                    'course_day' => (string) ($change['old_course_day'] ?? ''),
                ],
                'after' => [
                    'season' => (string) ($change['new_season'] ?? ''),
                    'event_signature' => (string) ($change['new_signature'] ?? ''),
                    'course_day' => (string) ($change['new_course_day'] ?? ''),
                ],
            ];
        }
        if (!$dry_run) {
            $added = (int) ($raw['synced'] ?? $added);
        }
        return [
            'summary' => sprintf(
                /* translators: 1: adds or orders in scope, 2: signature updates, 3: deletes */
                __('Adds: %1$d. Updates: %2$d. Deletes: %3$d.', 'intersoccer-reports-rosters'),
                $added,
                $updated,
                $deleted
            ),
            'counts' => ['changed' => $added + $updated + $deleted, 'added' => $added, 'updated' => $updated, 'deleted' => $deleted],
            'rows' => $rows,
            'wrote' => !$dry_run,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_normalize_snapshot')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_rr_tool_normalize_snapshot(): array {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'get_results')) {
            return [];
        }
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $rows = $wpdb->get_results(
            "SELECT id, venue, season, booking_type, selected_days, course_day, camp_terms, times FROM {$table} ORDER BY id ASC LIMIT 2000",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $before = [
                'venue' => (string) ($row['venue'] ?? ''),
                'season' => (string) ($row['season'] ?? ''),
                'booking_type' => (string) ($row['booking_type'] ?? ''),
                'selected_days' => (string) ($row['selected_days'] ?? ''),
                'course_day' => (string) ($row['course_day'] ?? ''),
                'camp_terms' => (string) ($row['camp_terms'] ?? ''),
                'times' => (string) ($row['times'] ?? ''),
            ];
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'before' => $before,
                'after' => $before,
            ];
        }
        return $out;
    }
}

if (!function_exists('intersoccer_rr_tool_normalize')) {
    function intersoccer_rr_tool_normalize(bool $dry_run): array {
        global $wpdb;
        $count = 0;
        if (isset($wpdb) && method_exists($wpdb, 'get_var')) {
            $table = $wpdb->prefix . 'intersoccer_rosters';
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }
        $rows = intersoccer_rr_tool_normalize_snapshot();
        if ($dry_run) {
            return [
                'summary' => sprintf(
                    /* translators: %d: roster row count */
                    __('Preview: %d roster rows would be examined. Nothing was written.', 'intersoccer-reports-rosters'),
                    $count
                ),
                'counts' => ['changed' => $count, 'added' => 0, 'updated' => $count, 'deleted' => 0],
                'rows' => $rows,
                'wrote' => false,
            ];
        }
        $updated = 0;
        if (function_exists('intersoccer_renormalize_roster_language')) {
            $raw = intersoccer_renormalize_roster_language();
            $updated = (int) (is_array($raw) ? ($raw['updated'] ?? 0) : 0);
        }
        $after_rows = intersoccer_rr_tool_normalize_snapshot();
        $after_by_id = [];
        foreach ($after_rows as $row) {
            $after_by_id[(int) $row['id']] = $row['before'];
        }
        foreach ($rows as $index => $row) {
            $id = (int) $row['id'];
            if (isset($after_by_id[$id])) {
                $rows[$index]['after'] = $after_by_id[$id];
            }
        }
        return [
            'summary' => sprintf(
                /* translators: %d: rows updated */
                __('Normalized %d roster rows.', 'intersoccer-reports-rosters'),
                $updated
            ),
            'counts' => ['changed' => max($updated, $count), 'added' => 0, 'updated' => $updated, 'deleted' => 0],
            'rows' => $rows,
            'wrote' => true,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_placeholders')) {
    function intersoccer_rr_tool_placeholders(bool $dry_run): array {
        $count = 0;
        if (function_exists('wc_get_products')) {
            $products = wc_get_products([
                'type' => 'variable',
                'status' => 'publish',
                'limit' => -1,
                'return' => 'ids',
            ]);
            $count = is_array($products) ? count($products) : 0;
        }
        if ($dry_run) {
            return [
                'summary' => sprintf(
                    /* translators: %d: published variable products */
                    __('Preview: %d published variable products. Nothing was written.', 'intersoccer-reports-rosters'),
                    $count
                ),
                'counts' => ['changed' => $count, 'added' => $count, 'updated' => 0, 'deleted' => 0],
                'rows' => [],
                'wrote' => false,
            ];
        }
        $created = 0;
        $updated = 0;
        if (!function_exists('intersoccer_sync_all_placeholders')) {
            $file = __DIR__ . '/placeholder-rosters.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
        if (function_exists('intersoccer_sync_all_placeholders')) {
            $raw = intersoccer_sync_all_placeholders();
            $created = (int) (is_array($raw) ? ($raw['created'] ?? 0) : 0);
            $updated = (int) (is_array($raw) ? ($raw['updated'] ?? 0) : 0);
        }
        return [
            'summary' => sprintf(
                /* translators: 1: created, 2: updated */
                __('Placeholders created: %1$d. Updated: %2$d.', 'intersoccer-reports-rosters'),
                $created,
                $updated
            ),
            'counts' => ['changed' => $created + $updated, 'added' => $created, 'updated' => $updated, 'deleted' => 0],
            'rows' => [],
            'wrote' => true,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_financial')) {
    function intersoccer_rr_tool_financial(bool $dry_run, array $scope): array {
        $start = (string) ($scope['start_date'] ?? '');
        if ($start === '') {
            $start = '1970-01-01';
        }
        $batch = max(1, min(500, (int) ($scope['batch_size'] ?? 100)));
        if (!function_exists('intersoccer_oop_get_order_financial_attribution_service')) {
            return intersoccer_rr_advanced_tools_empty_result(
                __('Financial attribution is unavailable.', 'intersoccer-reports-rosters'),
                intersoccer_rr_scope_is_shop_wide('financial_backfill', $scope),
                'unavailable'
            );
        }
        $service = intersoccer_oop_get_order_financial_attribution_service();
        if ($dry_run) {
            $summary = $service->summarizeMissingAttributionInDateRange($start, gmdate('Y-m-d'));
            $would = (int) ($summary['applied_count'] ?? 0);
            return [
                'summary' => sprintf(
                    /* translators: %d: orders that would be updated */
                    __('Dry run: %d orders would be updated. Nothing was written.', 'intersoccer-reports-rosters'),
                    $would
                ),
                'counts' => ['changed' => $would, 'added' => 0, 'updated' => $would, 'deleted' => 0],
                'rows' => [],
                'wrote' => false,
            ];
        }
        $result = $service->backfillBatch($start, $batch);
        $applied = (int) ($result['applied_count'] ?? $result['migrated_count'] ?? 0);
        return [
            'summary' => sprintf(
                /* translators: %d: orders updated */
                __('Applied financial attribution to %d orders.', 'intersoccer-reports-rosters'),
                $applied
            ),
            'counts' => ['changed' => $applied, 'added' => 0, 'updated' => $applied, 'deleted' => 0],
            'rows' => [],
            'wrote' => true,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_fix_issues')) {
    function intersoccer_rr_tool_fix_issues(bool $dry_run, array $scope): array {
        $filters = [
            'year' => (string) ($scope['year'] ?? ''),
            'order_id' => (int) ($scope['order_id'] ?? 0),
            'activity_type' => 'All',
            'limit' => 200,
            'offset' => 0,
        ];
        if ($dry_run) {
            return [
                'summary' => __('Preview: safe fix will use WooCommerce as the source of truth for this scope. Nothing was written.', 'intersoccer-reports-rosters'),
                'counts' => ['changed' => 1, 'added' => 0, 'updated' => 1, 'deleted' => 0],
                'rows' => [],
                'wrote' => false,
            ];
        }
        if (!class_exists(\InterSoccer\ReportsRosters\Services\ReportsRostersDiagnosticsService::class)) {
            return intersoccer_rr_advanced_tools_empty_result(
                __('Safe fix is unavailable.', 'intersoccer-reports-rosters'),
                intersoccer_rr_scope_is_shop_wide('fix_issues', $scope),
                'unavailable'
            );
        }
        $service = new \InterSoccer\ReportsRosters\Services\ReportsRostersDiagnosticsService();
        $raw = $service->runSafeFix($filters);
        return [
            'summary' => __('Safe fix finished.', 'intersoccer-reports-rosters'),
            'counts' => ['changed' => 1, 'added' => (int) ($raw['inserted'] ?? 0), 'updated' => (int) ($raw['updated'] ?? 0), 'deleted' => 0],
            'rows' => [],
            'raw' => $raw,
            'wrote' => true,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_rebuild_selected')) {
    function intersoccer_rr_tool_rebuild_selected(bool $dry_run, array $scope): array {
        $ids = intersoccer_rr_tool_id_list((string) ($scope['roster_ids'] ?? ''));
        if ($ids === []) {
            return intersoccer_rr_advanced_tools_empty_result(
                __('Enter at least one roster row ID. A shop-wide signature rebuild is not available.', 'intersoccer-reports-rosters'),
                false,
                'scope'
            );
        }
        global $wpdb;
        $rows = [];
        if (isset($wpdb) && method_exists($wpdb, 'get_row')) {
            $table = $wpdb->prefix . 'intersoccer_rosters';
            foreach ($ids as $id) {
                $current = $wpdb->get_row($wpdb->prepare("SELECT id, event_signature FROM {$table} WHERE id = %d", $id), ARRAY_A);
                $before = is_array($current) ? (string) ($current['event_signature'] ?? '') : '';
                $rows[] = [
                    'id' => $id,
                    'before' => ['event_signature' => $before],
                    'after' => ['event_signature' => $before],
                ];
            }
        }
        if ($dry_run) {
            return [
                'summary' => sprintf(
                    /* translators: %d: roster row count */
                    __('Preview: %d selected rows. Nothing was written.', 'intersoccer-reports-rosters'),
                    count($ids)
                ),
                'counts' => ['changed' => count($ids), 'added' => 0, 'updated' => count($ids), 'deleted' => 0],
                'rows' => $rows,
                'wrote' => false,
            ];
        }
        $updated = 0;
        if (class_exists(\InterSoccer\ReportsRosters\Services\SignatureDriftService::class)) {
            $service = new \InterSoccer\ReportsRosters\Services\SignatureDriftService();
            $raw = $service->rebuildSignaturesForIds($ids);
            $updated = (int) ($raw['updated'] ?? 0);
        }
        if (isset($wpdb) && method_exists($wpdb, 'get_row')) {
            $table = $wpdb->prefix . 'intersoccer_rosters';
            foreach ($rows as $index => $row) {
                $current = $wpdb->get_row($wpdb->prepare("SELECT event_signature FROM {$table} WHERE id = %d", (int) $row['id']), ARRAY_A);
                if (is_array($current)) {
                    $rows[$index]['after'] = ['event_signature' => (string) ($current['event_signature'] ?? '')];
                }
            }
        }
        return [
            'summary' => sprintf(
                /* translators: %d: rows updated */
                __('Rebuilt signatures on %d rows.', 'intersoccer-reports-rosters'),
                $updated
            ),
            'counts' => ['changed' => $updated, 'added' => 0, 'updated' => $updated, 'deleted' => 0],
            'rows' => $rows,
            'wrote' => true,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_merge_signatures')) {
    function intersoccer_rr_tool_merge_signatures(bool $dry_run, array $scope): array {
        $source = trim((string) ($scope['source_signature'] ?? ''));
        $target = trim((string) ($scope['target_signature'] ?? ''));
        if ($source === '' || $target === '' || $source === $target) {
            return intersoccer_rr_advanced_tools_empty_result(
                __('Source and target signatures must differ and be non-empty.', 'intersoccer-reports-rosters'),
                false,
                'scope'
            );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $found = $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_signature FROM {$table} WHERE event_signature = %s LIMIT 2000",
            $source
        ), ARRAY_A);
        $found = is_array($found) ? $found : [];
        $rows = [];
        foreach ($found as $row) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'before' => ['event_signature' => $source],
                'after' => ['event_signature' => $target],
            ];
        }
        $count = count($rows);
        if ($dry_run) {
            return [
                'summary' => sprintf(
                    /* translators: %d: row count */
                    __('Preview: %d rows would move to the target signature. Nothing was written.', 'intersoccer-reports-rosters'),
                    $count
                ),
                'counts' => ['changed' => $count, 'added' => 0, 'updated' => $count, 'deleted' => 0],
                'rows' => $rows,
                'wrote' => false,
            ];
        }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET event_signature = %s WHERE event_signature = %s",
            $target,
            $source
        ));
        return [
            'summary' => sprintf(
                /* translators: %d: rows updated */
                __('Updated %d roster rows.', 'intersoccer-reports-rosters'),
                (int) $updated
            ),
            'counts' => ['changed' => (int) $updated, 'added' => 0, 'updated' => (int) $updated, 'deleted' => 0],
            'rows' => $rows,
            'wrote' => $updated !== false,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_repair_day_presence')) {
    function intersoccer_rr_tool_repair_day_presence(bool $dry_run, array $scope): array {
        $signature = trim((string) ($scope['event_signature'] ?? ''));
        if ($signature === '') {
            return intersoccer_rr_advanced_tools_empty_result(
                __('Enter an event signature.', 'intersoccer-reports-rosters'),
                false,
                'scope'
            );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $found = $wpdb->get_results($wpdb->prepare(
            "SELECT id, booking_type, selected_days, day_presence FROM {$table} WHERE event_signature = %s",
            $signature
        ), ARRAY_A);
        $found = is_array($found) ? $found : [];
        $rows = [];
        foreach ($found as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $new_presence = function_exists('intersoccer_compute_day_presence')
                ? intersoccer_compute_day_presence($row['booking_type'] ?? '', $row['selected_days'] ?? '')
                : [];
            $new_json = (string) wp_json_encode($new_presence);
            $old_json = (string) ($row['day_presence'] ?? '');
            if ($new_json === $old_json) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'before' => ['day_presence' => $old_json],
                'after' => ['day_presence' => $new_json],
            ];
        }
        if ($dry_run) {
            return [
                'summary' => sprintf(
                    /* translators: %d: rows that would change */
                    __('Preview: %d rows would change. Nothing was written.', 'intersoccer-reports-rosters'),
                    count($rows)
                ),
                'counts' => ['changed' => count($rows), 'added' => 0, 'updated' => count($rows), 'deleted' => 0],
                'rows' => $rows,
                'wrote' => false,
            ];
        }
        $updated = 0;
        foreach ($rows as $row) {
            $ok = $wpdb->update(
                $table,
                ['day_presence' => (string) ($row['after']['day_presence'] ?? '')],
                ['id' => (int) $row['id']],
                ['%s'],
                ['%d']
            );
            if ($ok !== false) {
                $updated++;
            }
        }
        return [
            'summary' => sprintf(
                /* translators: %d: rows updated */
                __('Updated day presence on %d rows.', 'intersoccer-reports-rosters'),
                $updated
            ),
            'counts' => ['changed' => $updated, 'added' => 0, 'updated' => $updated, 'deleted' => 0],
            'rows' => $rows,
            'wrote' => true,
        ];
    }
}

if (!function_exists('intersoccer_rr_advanced_tools_scope_label')) {
    function intersoccer_rr_advanced_tools_scope_label(array $scope): string {
        $parts = [];
        foreach ($scope as $key => $value) {
            if ($key === 'tool' || $value === '' || $value === '0') {
                continue;
            }
            $parts[] = $key . '=' . $value;
        }
        $label = implode(', ', $parts);
        if (strlen($label) > 180) {
            $label = substr($label, 0, 177) . '...';
        }
        return $label;
    }
}

if (!function_exists('intersoccer_rr_advanced_tools_handle_request')) {
    /**
     * @return array{type:string,message:string,result:?array}
     */
    function intersoccer_rr_advanced_tools_handle_request(): array {
        $empty = ['type' => '', 'message' => '', 'result' => null];
        $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
        if ($method !== 'POST') {
            return $empty;
        }
        $tool = isset($_POST['intersoccer_rr_repair_tool'])
            ? sanitize_key(wp_unslash((string) $_POST['intersoccer_rr_repair_tool']))
            : '';
        if ($tool === '' || $tool === 'season_rename') {
            return $empty;
        }
        intersoccer_rr_repair_require_cap();
        if (!intersoccer_rr_repair_verify_nonce()) {
            wp_die(esc_html__('Security check failed.', 'intersoccer-reports-rosters'));
        }
        $mode = isset($_POST['intersoccer_rr_repair_mode'])
            ? sanitize_key(wp_unslash((string) $_POST['intersoccer_rr_repair_mode']))
            : '';
        $user_id = (int) get_current_user_id();
        if ($mode === 'rollback' || $tool === 'rollback') {
            return intersoccer_rr_advanced_tools_handle_rollback($user_id);
        }
        $definitions = intersoccer_rr_advanced_tools_definitions();
        if (!isset($definitions[$tool])) {
            return ['type' => 'error', 'message' => __('Unknown repair.', 'intersoccer-reports-rosters'), 'result' => null];
        }
        $scope = intersoccer_rr_advanced_tools_scope_from_post($tool);
        if ($mode === 'preview') {
            $result = intersoccer_rr_advanced_tools_execute($tool, true, $scope);
            if ($result['error'] !== '' && (int) $result['counts']['changed'] === 0) {
                intersoccer_rr_repair_log([
                    'user_id' => $user_id,
                    'tool' => $tool,
                    'mode' => 'preview',
                    'scope' => intersoccer_rr_advanced_tools_scope_label($scope),
                    'counts' => $result['counts'],
                    'backup' => '',
                ]);
                return ['type' => 'error', 'message' => $result['error'], 'result' => $result];
            }
            intersoccer_rr_repair_store_preview($user_id, $tool, $scope, $result);
            intersoccer_rr_repair_log([
                'user_id' => $user_id,
                'tool' => $tool,
                'mode' => 'preview',
                'scope' => intersoccer_rr_advanced_tools_scope_label($scope),
                'counts' => $result['counts'],
                'backup' => '',
            ]);
            $GLOBALS['intersoccer_rr_repair_last_result'] = $result;
            return [
                'type' => 'info',
                'message' => __('Preview finished. Nothing was written.', 'intersoccer-reports-rosters') . ' ' . $result['summary'],
                'result' => $result,
            ];
        }
        if ($mode !== 'apply') {
            return ['type' => 'error', 'message' => __('Unknown action.', 'intersoccer-reports-rosters'), 'result' => null];
        }
        $typed_apply = isset($_POST['intersoccer_rr_repair_phrase']) ? sanitize_text_field(wp_unslash((string) $_POST['intersoccer_rr_repair_phrase'])) : '';
        $typed_global = isset($_POST['intersoccer_rr_repair_global']) ? sanitize_text_field(wp_unslash((string) $_POST['intersoccer_rr_repair_global'])) : '';
        $typed_delete = isset($_POST['intersoccer_rr_repair_delete']) ? sanitize_text_field(wp_unslash((string) $_POST['intersoccer_rr_repair_delete'])) : '';
        $gate = intersoccer_rr_repair_gate_apply($user_id, $tool, $scope, $typed_apply, $typed_global, $typed_delete);
        if (!$gate['ok']) {
            return ['type' => 'error', 'message' => $gate['error'], 'result' => null];
        }
        $again = intersoccer_rr_advanced_tools_execute($tool, true, $scope);
        $stored = intersoccer_rr_repair_get_preview($user_id, $tool);
        if ($stored === null || (string) ($stored['rows_fingerprint'] ?? '') !== intersoccer_rr_repair_rows_fingerprint($again['rows'])) {
            return [
                'type' => 'error',
                'message' => __('The change set no longer matches the Preview. Run Preview again.', 'intersoccer-reports-rosters'),
                'result' => null,
            ];
        }
        $backup = intersoccer_rr_repair_write_backup($tool, $user_id, $again['rows']);
        $applied = intersoccer_rr_advanced_tools_execute($tool, false, $scope);
        if ($applied['rows'] !== []) {
            intersoccer_rr_repair_replace_backup_rows($backup, $applied['rows']);
        }
        intersoccer_rr_repair_clear_preview($user_id, $tool);
        intersoccer_rr_repair_log([
            'user_id' => $user_id,
            'tool' => $tool,
            'mode' => 'apply',
            'scope' => intersoccer_rr_advanced_tools_scope_label($scope),
            'counts' => $applied['counts'],
            'backup' => $backup,
        ]);
        $GLOBALS['intersoccer_rr_repair_last_result'] = $applied;
        $message = $applied['error'] !== ''
            ? $applied['error']
            : __('Apply finished.', 'intersoccer-reports-rosters') . ' ' . $applied['summary'];
        return ['type' => $applied['error'] !== '' ? 'error' : 'success', 'message' => $message, 'result' => $applied];
    }
}

if (!function_exists('intersoccer_rr_advanced_tools_handle_rollback')) {
    /**
     * @return array{type:string,message:string,result:?array}
     */
    function intersoccer_rr_advanced_tools_handle_rollback(int $user_id): array {
        $typed_apply = isset($_POST['intersoccer_rr_repair_phrase']) ? sanitize_text_field(wp_unslash((string) $_POST['intersoccer_rr_repair_phrase'])) : '';
        if (trim($typed_apply) !== INTERSOCCER_RR_REPAIR_APPLY_PHRASE) {
            return ['type' => 'error', 'message' => __('Type APPLY to confirm.', 'intersoccer-reports-rosters'), 'result' => null];
        }
        $filename = isset($_POST['intersoccer_rr_repair_backup']) ? basename(sanitize_text_field(wp_unslash((string) $_POST['intersoccer_rr_repair_backup']))) : '';
        $backup = intersoccer_rr_repair_read_backup($filename);
        if ($backup === null) {
            return ['type' => 'error', 'message' => __('Backup file was not found.', 'intersoccer-reports-rosters'), 'result' => null];
        }
        $outcome = intersoccer_rr_repair_rollback_rows(
            $backup,
            'intersoccer_rr_repair_read_roster_value',
            'intersoccer_rr_repair_restore_roster_value'
        );
        intersoccer_rr_repair_log([
            'user_id' => $user_id,
            'tool' => (string) ($backup['tool'] ?? ''),
            'mode' => 'rollback',
            'scope' => $filename,
            'counts' => ['changed' => $outcome['restored'], 'added' => 0, 'updated' => $outcome['restored'], 'deleted' => 0],
            'backup' => $filename,
        ]);
        $message = sprintf(
            /* translators: 1: restored count, 2: conflict count */
            __('Rollback restored %1$d rows. Conflicts left untouched: %2$d.', 'intersoccer-reports-rosters'),
            (int) $outcome['restored'],
            count($outcome['conflicts'])
        );
        return ['type' => 'success', 'message' => $message, 'result' => null];
    }
}

if (!function_exists('intersoccer_rr_repair_read_roster_value')) {
    /**
     * @param mixed $id
     * @return array<string,string>
     */
    function intersoccer_rr_repair_read_roster_value($id): array {
        if ($id === 'schema_version') {
            return ['value' => (string) get_option('intersoccer_db_version', '')];
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT season, event_signature, course_day, day_presence, venue, booking_type, selected_days, camp_terms, times FROM {$wpdb->prefix}intersoccer_rosters WHERE id = %d",
            (int) $id
        ), ARRAY_A);
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('intersoccer_rr_repair_restore_roster_value')) {
    /**
     * @param mixed $id
     * @param mixed $before
     */
    function intersoccer_rr_repair_restore_roster_value($id, $before): void {
        if (!is_array($before)) {
            return;
        }
        if ($id === 'schema_version' && isset($before['value'])) {
            update_option('intersoccer_db_version', (string) $before['value'], false);
            return;
        }
        global $wpdb;
        $allowed = ['season', 'event_signature', 'course_day', 'day_presence', 'venue', 'booking_type', 'selected_days', 'camp_terms', 'times'];
        $data = [];
        $formats = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $before)) {
                $data[$column] = (string) $before[$column];
                $formats[] = '%s';
            }
        }
        if ($data === []) {
            return;
        }
        $wpdb->update($wpdb->prefix . 'intersoccer_rosters', $data, ['id' => (int) $id], $formats, ['%d']);
    }
}

if (!function_exists('intersoccer_rr_render_advanced_tools_page')) {
    function intersoccer_rr_render_advanced_tools_page(): void {
        intersoccer_rr_repair_require_cap();
        $notice = intersoccer_rr_advanced_tools_handle_request();
        $user_id = (int) get_current_user_id();
        $definitions = intersoccer_rr_advanced_tools_definitions();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Advanced tools', 'intersoccer-reports-rosters'); ?></h1>
            <p><?php esc_html_e('Repairs run here so everyday roster screens stay read-only. Preview writes nothing. Apply stays off until a Preview exists for that tool. Type APPLY to confirm. Shop-wide runs also require GLOBAL. A backup is stored before Apply, and rollback restores a row only while it still has the value Apply wrote.', 'intersoccer-reports-rosters'); ?></p>
            <?php if (($notice['message'] ?? '') !== '') : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type'] === 'error' ? 'error' : ($notice['type'] === 'success' ? 'success' : 'info')); ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
            <?php endif; ?>
            <?php foreach ($definitions as $tool => $definition) : ?>
                <?php intersoccer_rr_render_repair_tool_card($tool, $definition, $user_id); ?>
            <?php endforeach; ?>
            <?php
            if (function_exists('intersoccer_rr_render_season_rename_page')) {
                intersoccer_rr_render_season_rename_page();
            }
            ?>
            <h2><?php esc_html_e('Run log', 'intersoccer-reports-rosters'); ?></h2>
            <?php intersoccer_rr_render_repair_run_log(); ?>
            <h2><?php esc_html_e('Rollback', 'intersoccer-reports-rosters'); ?></h2>
            <?php intersoccer_rr_render_repair_rollback_form(); ?>
        </div>
        <?php
    }
}

if (!function_exists('intersoccer_rr_render_repair_tool_card')) {
    /**
     * @param array<string,mixed> $definition
     */
    function intersoccer_rr_render_repair_tool_card(string $tool, array $definition, int $user_id): void {
        $preview = intersoccer_rr_repair_get_preview($user_id, $tool);
        $prefill = [];
        if ($tool === 'repair_day_presence' && isset($_GET['event_signature'])) {
            $prefill['event_signature'] = sanitize_text_field(wp_unslash((string) $_GET['event_signature']));
        }
        ?>
        <h2><?php echo esc_html((string) $definition['label']); ?></h2>
        <p><?php echo esc_html((string) $definition['description']); ?></p>
        <form method="post" action="">
            <input type="hidden" name="intersoccer_rr_repair_tool" value="<?php echo esc_attr($tool); ?>" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(INTERSOCCER_RR_REPAIR_NONCE)); ?>" />
            <table class="form-table" role="presentation">
                <?php foreach ((array) ($definition['fields'] ?? []) as $field) : ?>
                    <?php
                    $name = (string) $field['name'];
                    $value = $prefill[$name] ?? '';
                    $type = (string) ($field['type'] ?? 'text');
                    ?>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($tool . '-' . $name); ?>"><?php echo esc_html((string) $field['label']); ?></label></th>
                        <td><input type="<?php echo esc_attr($type === 'number' ? 'number' : ($type === 'date' ? 'date' : 'text')); ?>" class="regular-text" id="<?php echo esc_attr($tool . '-' . $name); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" /></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($tool . '-phrase'); ?>"><?php esc_html_e('Confirm', 'intersoccer-reports-rosters'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="<?php echo esc_attr($tool . '-phrase'); ?>" name="intersoccer_rr_repair_phrase" value="" autocomplete="off" />
                        <p class="description"><?php esc_html_e('Type APPLY to confirm Apply. Shop-wide runs also need GLOBAL in the next field. Deletes, when a tool lists them, need DELETE.', 'intersoccer-reports-rosters'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($tool . '-global'); ?>"><?php esc_html_e('Shop-wide confirm', 'intersoccer-reports-rosters'); ?></label></th>
                    <td><input type="text" class="regular-text" id="<?php echo esc_attr($tool . '-global'); ?>" name="intersoccer_rr_repair_global" value="" autocomplete="off" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr($tool . '-delete'); ?>"><?php esc_html_e('Delete confirm', 'intersoccer-reports-rosters'); ?></label></th>
                    <td><input type="text" class="regular-text" id="<?php echo esc_attr($tool . '-delete'); ?>" name="intersoccer_rr_repair_delete" value="" autocomplete="off" /></td>
                </tr>
            </table>
            <p>
                <button type="submit" class="button" name="intersoccer_rr_repair_mode" value="preview"><?php esc_html_e('Preview (dry run)', 'intersoccer-reports-rosters'); ?></button>
                <button type="submit" class="button button-primary" name="intersoccer_rr_repair_mode" value="apply"<?php echo $preview === null ? ' disabled="disabled"' : ''; ?>><?php esc_html_e('Apply', 'intersoccer-reports-rosters'); ?></button>
            </p>
            <?php if ($preview !== null) : ?>
                <p class="description"><?php echo esc_html((string) ($preview['summary'] ?? '')); ?></p>
            <?php endif; ?>
        </form>
        <?php
    }
}

if (!function_exists('intersoccer_rr_render_repair_run_log')) {
    function intersoccer_rr_render_repair_run_log(): void {
        $entries = array_reverse(intersoccer_rr_repair_log_entries());
        if ($entries === []) {
            echo '<p>' . esc_html__('No repair runs yet.', 'intersoccer-reports-rosters') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('When', 'intersoccer-reports-rosters') . '</th>';
        echo '<th>' . esc_html__('User', 'intersoccer-reports-rosters') . '</th>';
        echo '<th>' . esc_html__('Tool', 'intersoccer-reports-rosters') . '</th>';
        echo '<th>' . esc_html__('Mode', 'intersoccer-reports-rosters') . '</th>';
        echo '<th>' . esc_html__('Scope', 'intersoccer-reports-rosters') . '</th>';
        echo '<th>' . esc_html__('Counts', 'intersoccer-reports-rosters') . '</th>';
        echo '<th>' . esc_html__('Backup', 'intersoccer-reports-rosters') . '</th>';
        echo '<th>' . esc_html__('Peak memory (MB)', 'intersoccer-reports-rosters') . '</th>';
        echo '</tr></thead><tbody>';
        foreach (array_slice($entries, 0, 50) as $entry) {
            $counts = is_array($entry['counts'] ?? null) ? $entry['counts'] : [];
            echo '<tr>';
            echo '<td>' . esc_html((string) ($entry['at'] ?? '')) . '</td>';
            echo '<td>' . (int) ($entry['user_id'] ?? 0) . '</td>';
            echo '<td>' . esc_html((string) ($entry['tool'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($entry['mode'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($entry['scope'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) wp_json_encode($counts)) . '</td>';
            echo '<td>' . esc_html((string) ($entry['backup'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($entry['peak_memory_mb'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

if (!function_exists('intersoccer_rr_render_repair_rollback_form')) {
    function intersoccer_rr_render_repair_rollback_form(): void {
        $index = intersoccer_rr_repair_state_get('intersoccer_rr_repair_backup_index', []);
        if (!is_array($index) || $index === []) {
            echo '<p>' . esc_html__('No backups yet.', 'intersoccer-reports-rosters') . '</p>';
            return;
        }
        $index = array_reverse($index);
        ?>
        <form method="post" action="">
            <input type="hidden" name="intersoccer_rr_repair_tool" value="rollback" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(INTERSOCCER_RR_REPAIR_NONCE)); ?>" />
            <p>
                <label for="intersoccer_rr_repair_backup"><?php esc_html_e('Backup', 'intersoccer-reports-rosters'); ?></label>
                <select name="intersoccer_rr_repair_backup" id="intersoccer_rr_repair_backup">
                    <?php foreach (array_slice($index, 0, 20) as $item) : ?>
                        <?php if (!is_array($item) || empty($item['file'])) { continue; } ?>
                        <option value="<?php echo esc_attr((string) $item['file']); ?>"><?php echo esc_html((string) $item['tool'] . ' ' . (string) $item['created_at']); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="intersoccer-rollback-phrase"><?php esc_html_e('Type APPLY to roll back', 'intersoccer-reports-rosters'); ?></label>
                <input type="text" class="regular-text" id="intersoccer-rollback-phrase" name="intersoccer_rr_repair_phrase" value="" autocomplete="off" />
            </p>
            <p><button type="submit" class="button" name="intersoccer_rr_repair_mode" value="rollback"><?php esc_html_e('Rollback', 'intersoccer-reports-rosters'); ?></button></p>
        </form>
        <?php
    }
}
