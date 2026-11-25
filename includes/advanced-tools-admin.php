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
                'description' => __('Runs pending schema migrations. Shop-wide. No rollback. Apply is refused when no migration step is pending.', 'intersoccer-reports-rosters'),
                'fields' => [],
                'no_rollback' => true,
            ],
            'reconcile_rosters' => [
                'label' => __('Reconcile Rosters', 'intersoccer-reports-rosters'),
                'description' => __('Syncs roster rows for the order IDs or product IDs you enter. A date range alone is shop-wide and requires GLOBAL. Dates filter that shop-wide run. Default does not delete roster rows. order-sync: no rollback. Use Season rename repair when the work is a renamed season.', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'date_from', 'label' => __('Order date from', 'intersoccer-reports-rosters'), 'type' => 'date'],
                    ['name' => 'date_to', 'label' => __('Order date to', 'intersoccer-reports-rosters'), 'type' => 'date'],
                    ['name' => 'order_ids', 'label' => __('Order IDs', 'intersoccer-reports-rosters'), 'type' => 'text'],
                    ['name' => 'product_ids', 'label' => __('Product IDs', 'intersoccer-reports-rosters'), 'type' => 'text'],
                ],
            ],
            'normalize_language' => [
                'label' => __('Normalize Roster Language (EN)', 'intersoccer-reports-rosters'),
                'description' => __('Rewrites event columns and linked order-line event metadata that are not already English. Player fields are not changed. Narrow the run by season or order ID range so a batch stays at or under the backup limit. Leave those fields empty only for a shop-wide run (GLOBAL). Rollback restores city, canton, and activity type along with the other event columns.', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'season', 'label' => __('Season', 'intersoccer-reports-rosters'), 'type' => 'text'],
                    ['name' => 'order_id_from', 'label' => __('Order ID from', 'intersoccer-reports-rosters'), 'type' => 'number'],
                    ['name' => 'order_id_to', 'label' => __('Order ID to', 'intersoccer-reports-rosters'), 'type' => 'number'],
                ],
            ],
            'sync_placeholders' => [
                'label' => __('Sync All Placeholders', 'intersoccer-reports-rosters'),
                'description' => __('Creates or updates placeholder roster rows for published variations. Shop-wide. No rollback. This repair cannot restore the previous placeholder rows.', 'intersoccer-reports-rosters'),
                'fields' => [],
                'no_rollback' => true,
            ],
            'financial_backfill' => [
                'label' => __('Financial Attribution Backfill', 'intersoccer-reports-rosters'),
                'description' => __('Allocates order-level discounts and refunds onto booking lines. Leave the start date empty only for a shop-wide run (GLOBAL). No rollback. This repair cannot restore the previous allocation.', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'start_date', 'label' => __('Start date', 'intersoccer-reports-rosters'), 'type' => 'date'],
                    ['name' => 'batch_size', 'label' => __('Batch size', 'intersoccer-reports-rosters'), 'type' => 'number'],
                ],
                'no_rollback' => true,
            ],
            'fix_issues' => [
                'label' => __('Fix Issues (Safe)', 'intersoccer-reports-rosters'),
                'description' => __('Preview lists the safe-fix counts from diagnostics for the year or order you enter. Leave both empty only for a shop-wide run (GLOBAL). No rollback.', 'intersoccer-reports-rosters'),
                'fields' => [
                    ['name' => 'year', 'label' => __('Year', 'intersoccer-reports-rosters'), 'type' => 'text'],
                    ['name' => 'order_id', 'label' => __('Order ID', 'intersoccer-reports-rosters'), 'type' => 'number'],
                ],
                'no_rollback' => true,
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
                'description' => __('Rewrites event_signature from the source value to the target value. Scoped to those two signatures. Apply is refused when the source has more rows than the backup limit.', 'intersoccer-reports-rosters'),
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
                $raw = trim($raw);
                if ($name === 'batch_size') {
                    $scope[$name] = $raw === '' ? '' : (string) max(0, (int) $raw);
                } elseif ($raw === '' || (int) $raw <= 0) {
                    $scope[$name] = '';
                } else {
                    $scope[$name] = (string) (int) $raw;
                }
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
            'over_cap' => !empty($result['over_cap']),
            'note' => (string) ($result['note'] ?? ''),
            'no_rollback' => !empty($result['no_rollback']),
        ];
    }
}

if (!function_exists('intersoccer_rr_advanced_tools_execute')) {
    function intersoccer_rr_advanced_tools_execute(string $tool, bool $dry_run, array $scope): array {
        $shop_wide = intersoccer_rr_scope_is_shop_wide($tool, $scope);
        if (isset($GLOBALS['intersoccer_rr_test_tool_runner']) && is_callable($GLOBALS['intersoccer_rr_test_tool_runner'])) {
            $override = call_user_func($GLOBALS['intersoccer_rr_test_tool_runner'], $tool, $dry_run, $scope);
            if (is_array($override)) {
                return intersoccer_rr_advanced_tools_normalize_result(
                    intersoccer_rr_advanced_tools_mark_no_rollback($tool, $override),
                    $dry_run,
                    $shop_wide
                );
            }
        }
        $filtered = apply_filters('intersoccer_rr_advanced_tools_execute', null, $tool, $dry_run, $scope);
        if (is_array($filtered)) {
            return intersoccer_rr_advanced_tools_normalize_result(
                intersoccer_rr_advanced_tools_mark_no_rollback($tool, $filtered),
                $dry_run,
                $shop_wide
            );
        }
        switch ($tool) {
            case 'upgrade_database':
                $result = intersoccer_rr_tool_upgrade_database($dry_run);
                break;
            case 'reconcile_rosters':
                $result = intersoccer_rr_tool_reconcile($dry_run, $scope);
                break;
            case 'normalize_language':
                $result = intersoccer_rr_tool_normalize($dry_run, $scope);
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
        return intersoccer_rr_advanced_tools_normalize_result(
            intersoccer_rr_advanced_tools_mark_no_rollback($tool, $result),
            $dry_run,
            $shop_wide
        );
    }
}

if (!function_exists('intersoccer_rr_advanced_tools_mark_no_rollback')) {
    /**
     * Tools that cannot restore prior rows stay out of the backup file.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    function intersoccer_rr_advanced_tools_mark_no_rollback(string $tool, array $result): array {
        $definitions = intersoccer_rr_advanced_tools_definitions();
        if (empty($definitions[$tool]['no_rollback'])) {
            return $result;
        }
        $result['no_rollback'] = true;
        $summary = (string) ($result['summary'] ?? '');
        if ($summary !== '' && stripos($summary, 'no rollback') === false) {
            $result['summary'] = trim($summary . ' ' . __('This repair has no rollback.', 'intersoccer-reports-rosters'));
        }
        return $result;
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

if (!function_exists('intersoccer_rr_tool_upgrade_pending_steps')) {
    /**
     * Pending schema steps. Empty means Apply must not write.
     *
     * @return array<int,array{version:string,label:string}>
     */
    function intersoccer_rr_tool_upgrade_pending_steps(): array {
        $current = (string) get_option('intersoccer_db_version', '1.0.0');
        $catalog = [
            '2.0.0' => __('Add event signature and placeholder columns.', 'intersoccer-reports-rosters'),
            '2.1.0' => __('Add the event completed column.', 'intersoccer-reports-rosters'),
            '2.2.0' => __('Set roster start and end date defaults to empty.', 'intersoccer-reports-rosters'),
            '2.3.0' => __('Clear legacy 1970-01-01 roster dates.', 'intersoccer-reports-rosters'),
            '2.4.0' => __('Widen event type and time columns.', 'intersoccer-reports-rosters'),
        ];
        $pending = [];
        foreach ($catalog as $version => $label) {
            if (version_compare($current, $version, '<')) {
                $pending[] = ['version' => $version, 'label' => $label];
            }
        }
        return $pending;
    }
}

if (!function_exists('intersoccer_rr_tool_upgrade_database')) {
    function intersoccer_rr_tool_upgrade_database(bool $dry_run): array {
        $pending = intersoccer_rr_tool_upgrade_pending_steps();
        $note = __('no rollback', 'intersoccer-reports-rosters');
        if ($pending === []) {
            return [
                'error' => __('No database upgrades are pending. Apply was not run.', 'intersoccer-reports-rosters'),
                'summary' => __('No pending migration steps. no rollback.', 'intersoccer-reports-rosters'),
                'counts' => ['changed' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0],
                'rows' => [],
                'wrote' => false,
                'note' => $note,
                'no_rollback' => true,
            ];
        }
        $rows = [];
        $labels = [];
        foreach ($pending as $step) {
            $labels[] = $step['version'] . ' ' . $step['label'];
            $rows[] = [
                'id' => 'migration:' . $step['version'],
                'before' => ['step' => $step['label']],
                'after' => ['step' => $step['label']],
            ];
        }
        $summary = sprintf(
            /* translators: %s: pending migration steps */
            __('Pending migration steps: %s. no rollback. Nothing was written.', 'intersoccer-reports-rosters'),
            implode('; ', $labels)
        );
        if ($dry_run) {
            return [
                'summary' => $summary,
                'counts' => ['changed' => count($pending), 'added' => 0, 'updated' => count($pending), 'deleted' => 0],
                'rows' => $rows,
                'wrote' => false,
                'note' => $note,
                'no_rollback' => true,
            ];
        }
        $ok = true;
        if (class_exists(\InterSoccer\ReportsRosters\Core\DatabaseMigrator::class)) {
            $migrator = new \InterSoccer\ReportsRosters\Core\DatabaseMigrator();
            $result = $migrator->migrate();
            $ok = in_array($result, [
                \InterSoccer\ReportsRosters\Core\DatabaseMigrator::RESULT_UPGRADED,
                \InterSoccer\ReportsRosters\Core\DatabaseMigrator::RESULT_ALREADY_CURRENT,
            ], true);
        } elseif (function_exists('intersoccer_upgrade_database')) {
            $ok = intersoccer_upgrade_database() !== false;
        }
        return [
            'error' => $ok ? '' : __('Database upgrade failed.', 'intersoccer-reports-rosters'),
            'summary' => $ok
                ? __('Database upgrade finished. no rollback.', 'intersoccer-reports-rosters')
                : __('Database upgrade failed. no rollback.', 'intersoccer-reports-rosters'),
            'counts' => ['changed' => count($pending), 'added' => 0, 'updated' => count($pending), 'deleted' => 0],
            'rows' => $rows,
            'wrote' => $ok,
            'note' => $note,
            'no_rollback' => true,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_reconcile_rows_from_changes')) {
    /**
     * @param array<int,mixed> $changes
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_rr_tool_reconcile_rows_from_changes(array $changes): array {
        $rows = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $id = (int) ($change['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $rows[] = [
                'id' => $id,
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
        return $rows;
    }
}

if (!function_exists('intersoccer_rr_tool_reconcile')) {
    function intersoccer_rr_tool_reconcile(bool $dry_run, array $scope): array {
        $note = 'order-sync: no rollback';
        $shop_wide = intersoccer_rr_scope_is_shop_wide('reconcile_rosters', $scope);
        $delete_requested = !empty($scope['delete_obsolete']) && (string) $scope['delete_obsolete'] !== '0';
        if (!function_exists('intersoccer_rr_load_reconcile') || !intersoccer_rr_load_reconcile() || !function_exists('intersoccer_reconcile_rosters')) {
            return intersoccer_rr_advanced_tools_empty_result(
                __('Roster reconcile could not be loaded.', 'intersoccer-reports-rosters'),
                $shop_wide,
                'unavailable'
            );
        }
        $raw = intersoccer_reconcile_rosters([
            'dry_run' => true,
            'delete_obsolete' => false,
            'skip_order_sync' => true,
            'date_from' => (string) ($scope['date_from'] ?? ''),
            'date_to' => (string) ($scope['date_to'] ?? ''),
            'order_ids' => (string) ($scope['order_ids'] ?? ''),
            'product_ids' => (string) ($scope['product_ids'] ?? ''),
        ]);
        $raw = is_array($raw) ? $raw : [];
        $season = is_array($raw['season_signature'] ?? null) ? $raw['season_signature'] : [];
        $changes = is_array($season['changes'] ?? null) ? $season['changes'] : [];
        $rows = intersoccer_rr_tool_reconcile_rows_from_changes($changes);
        $deleted = $delete_requested ? (int) ($raw['deleted'] ?? 0) : 0;
        if ($delete_requested && $deleted > 0 && count($rows) < $deleted) {
            return [
                'error' => __('Preview could not list every row that would be deleted. Apply was not run.', 'intersoccer-reports-rosters'),
                'summary' => __('Deletes were not fully previewed. order-sync: no rollback.', 'intersoccer-reports-rosters'),
                'counts' => ['changed' => 0, 'added' => 0, 'updated' => 0, 'deleted' => $deleted],
                'rows' => [],
                'note' => $note,
                'wrote' => false,
            ];
        }
        $change_count = count($rows) + $deleted;
        if ($change_count > (int) INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP) {
            return [
                'error' => intersoccer_rr_repair_over_cap_message($change_count),
                'summary' => intersoccer_rr_repair_over_cap_message($change_count) . ' ' . $note,
                'counts' => ['changed' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0],
                'rows' => [],
                'over_cap' => true,
                'note' => $note,
                'wrote' => false,
            ];
        }
        $summary = sprintf(
            /* translators: 1: roster rows that would change, 2: deletes */
            __('Roster rows that would change: %1$d. Deletes: %2$d. order-sync: no rollback.', 'intersoccer-reports-rosters'),
            count($rows),
            $deleted
        );
        if ($dry_run) {
            return [
                'summary' => $summary . ' ' . __('Nothing was written.', 'intersoccer-reports-rosters'),
                'counts' => [
                    'changed' => $change_count,
                    'added' => 0,
                    'updated' => count($rows),
                    'deleted' => $deleted,
                ],
                'rows' => $rows,
                'note' => $note,
                'wrote' => false,
            ];
        }
        $applied = intersoccer_reconcile_rosters([
            'dry_run' => false,
            'delete_obsolete' => $delete_requested,
            'skip_order_sync' => false,
            'date_from' => (string) ($scope['date_from'] ?? ''),
            'date_to' => (string) ($scope['date_to'] ?? ''),
            'order_ids' => (string) ($scope['order_ids'] ?? ''),
            'product_ids' => (string) ($scope['product_ids'] ?? ''),
        ]);
        $applied = is_array($applied) ? $applied : [];
        $ok = ($applied['status'] ?? '') !== 'error';
        return [
            'error' => $ok ? '' : (string) ($applied['message'] ?? __('Roster reconcile failed.', 'intersoccer-reports-rosters')),
            'summary' => sprintf(
                /* translators: 1: roster rows updated, 2: deletes */
                __('Updated %1$d roster rows. Deletes: %2$d. order-sync: no rollback.', 'intersoccer-reports-rosters'),
                count($rows),
                $deleted
            ),
            'counts' => [
                'changed' => $change_count,
                'added' => (int) ($applied['synced'] ?? 0),
                'updated' => count($rows),
                'deleted' => $deleted,
            ],
            'rows' => $rows,
            'note' => $note,
            'wrote' => $ok,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_normalize_plan')) {
    /**
     * Rows and linked order-item meta that language normalize would change.
     *
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_rr_tool_normalize_plan(array $scope = []): array {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
            return [];
        }
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $columns = 'id, order_id, order_item_id, product_id, product_name, activity_type, venue, age_group, camp_terms, course_day, times, season, girls_only, city, canton_region, start_date, booking_type, selected_days, event_signature';
        $season = trim((string) ($scope['season'] ?? ''));
        $order_from = (int) ($scope['order_id_from'] ?? 0);
        $order_to = (int) ($scope['order_id_to'] ?? 0);
        $changes = [];
        $cursor = 0;
        $page_size = 200;
        do {
            $records = $wpdb->get_results($wpdb->prepare(
                "SELECT {$columns} FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $cursor,
                $page_size
            ), ARRAY_A);
            if (!is_array($records) || $records === []) {
                break;
            }
            $last_id = $cursor;
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                $id = (int) ($record['id'] ?? 0);
                if ($id > $last_id) {
                    $last_id = $id;
                }
                if ($season !== '' && strcasecmp(trim((string) ($record['season'] ?? '')), $season) !== 0) {
                    continue;
                }
                $order_id = (int) ($record['order_id'] ?? 0);
                if ($order_from > 0 && $order_id < $order_from) {
                    continue;
                }
                if ($order_to > 0 && ($order_id <= 0 || $order_id > $order_to)) {
                    continue;
                }
                $row = intersoccer_rr_tool_normalize_row_diff($record);
                if ($row !== null) {
                    $changes[] = $row;
                }
                foreach (intersoccer_rr_tool_normalize_meta_diffs($record) as $meta) {
                    $changes[] = $meta;
                }
            }
            if ($last_id <= $cursor) {
                break;
            }
            $cursor = $last_id;
        } while (count($records) === $page_size);
        return $changes;
    }
}

if (!function_exists('intersoccer_rr_tool_normalize_row_diff')) {
    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>|null
     */
    function intersoccer_rr_tool_normalize_row_diff(array $record): ?array {
        $id = (int) ($record['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $before = [];
        $after = [];
        $course_day = (string) ($record['course_day'] ?? '');
        $new_day = function_exists('intersoccer_roster_course_day_for_storage')
            ? (string) intersoccer_roster_course_day_for_storage($course_day)
            : $course_day;
        $event_data = [
            'activity_type' => $record['activity_type'] ?? '',
            'venue' => $record['venue'] ?? '',
            'age_group' => $record['age_group'] ?? '',
            'camp_terms' => $record['camp_terms'] ?? '',
            'course_day' => $new_day !== '' ? $new_day : $course_day,
            'times' => $record['times'] ?? '',
            'season' => $record['season'] ?? '',
            'girls_only' => (int) ($record['girls_only'] ?? 0),
            'city' => $record['city'] ?? '',
            'canton_region' => $record['canton_region'] ?? '',
            'product_id' => (int) ($record['product_id'] ?? 0),
            'start_date' => $record['start_date'] ?? '',
        ];
        $update = [];
        if (function_exists('intersoccer_normalize_roster_facets_for_storage') && function_exists('intersoccer_build_roster_facet_db_update')) {
            $normalized = intersoccer_normalize_roster_facets_for_storage($event_data);
            if ($new_day !== '') {
                $normalized['course_day'] = $new_day;
            }
            $update = intersoccer_build_roster_facet_db_update($normalized, $record, (string) ($record['product_name'] ?? ''));
        } elseif ($new_day !== $course_day) {
            $update = ['course_day' => $new_day];
        }
        $columns = ['venue', 'season', 'booking_type', 'selected_days', 'course_day', 'camp_terms', 'times', 'activity_type', 'city', 'canton_region'];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $update)) {
                continue;
            }
            $old = (string) ($record[$column] ?? '');
            $new = (string) $update[$column];
            if ($new === $old) {
                continue;
            }
            $before[$column] = $old;
            $after[$column] = $new;
        }
        if ($before === []) {
            return null;
        }
        if (isset($update['event_signature'])) {
            $old_sig = (string) ($record['event_signature'] ?? '');
            $new_sig = (string) $update['event_signature'];
            if ($new_sig !== '' && $new_sig !== $old_sig) {
                $before['event_signature'] = $old_sig;
                $after['event_signature'] = $new_sig;
            }
        }
        return ['id' => $id, 'before' => $before, 'after' => $after];
    }
}

if (!function_exists('intersoccer_rr_tool_normalize_meta_diffs')) {
    /**
     * @param array<string,mixed> $record
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_rr_tool_normalize_meta_diffs(array $record): array {
        $order_item_id = (int) ($record['order_item_id'] ?? 0);
        if ($order_item_id <= 0 || !function_exists('wc_get_order_item_meta')) {
            return [];
        }
        $course_day = (string) ($record['course_day'] ?? '');
        $new_day = function_exists('intersoccer_roster_course_day_for_storage')
            ? (string) intersoccer_roster_course_day_for_storage($course_day)
            : $course_day;
        if ($new_day === '' || $new_day === $course_day) {
            return [];
        }
        $out = [];
        foreach (['Course Day', 'course_day', 'Jour du cours', 'Kurstag'] as $key) {
            $current = wc_get_order_item_meta($order_item_id, $key, true);
            if ($current === '' || $current === null || $current === false) {
                continue;
            }
            if ((string) $current === $new_day) {
                continue;
            }
            $out[] = [
                'id' => 'meta:' . $order_item_id . ':' . $key,
                'before' => ['value' => (string) $current],
                'after' => ['value' => $new_day],
            ];
        }
        return $out;
    }
}

if (!function_exists('intersoccer_rr_tool_normalize_apply_plan')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    function intersoccer_rr_tool_normalize_apply_plan(array $rows): int {
        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $written = 0;
        foreach ($rows as $row) {
            $id = $row['id'] ?? 0;
            $after = is_array($row['after'] ?? null) ? $row['after'] : [];
            if (is_string($id) && strpos($id, 'meta:') === 0) {
                $parts = explode(':', $id, 3);
                $item_id = (int) ($parts[1] ?? 0);
                $meta_key = (string) ($parts[2] ?? '');
                if ($item_id > 0 && $meta_key !== '' && function_exists('wc_update_order_item_meta')) {
                    wc_update_order_item_meta($item_id, $meta_key, (string) ($after['value'] ?? ''));
                    $written++;
                }
                continue;
            }
            $data = [];
            $formats = [];
            foreach ($after as $column => $value) {
                if (!is_string($column) || $column === '') {
                    continue;
                }
                $data[$column] = (string) $value;
                $formats[] = '%s';
            }
            if ($data === []) {
                continue;
            }
            $ok = $wpdb->update($table, $data, ['id' => (int) $id], $formats, ['%d']);
            if ($ok !== false) {
                $written++;
            }
        }
        return $written;
    }
}

if (!function_exists('intersoccer_rr_tool_normalize')) {
    function intersoccer_rr_tool_normalize(bool $dry_run, array $scope = []): array {
        $rows = intersoccer_rr_tool_normalize_plan($scope);
        $count = count($rows);
        if ($count > (int) INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP) {
            return [
                'error' => intersoccer_rr_repair_over_cap_message($count),
                'summary' => intersoccer_rr_repair_over_cap_message($count),
                'counts' => ['changed' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0],
                'rows' => [],
                'over_cap' => true,
                'wrote' => false,
            ];
        }
        if ($dry_run) {
            return [
                'summary' => sprintf(
                    /* translators: %d: rows and order-item meta values that would change */
                    __('Preview: %d roster rows or order-line values would change. Nothing was written.', 'intersoccer-reports-rosters'),
                    $count
                ),
                'counts' => ['changed' => $count, 'added' => 0, 'updated' => $count, 'deleted' => 0],
                'rows' => $rows,
                'wrote' => false,
            ];
        }
        $written = intersoccer_rr_tool_normalize_apply_plan($rows);
        return [
            'summary' => sprintf(
                /* translators: %d: rows updated */
                __('Normalized %d roster rows or order-line values.', 'intersoccer-reports-rosters'),
                $written
            ),
            'counts' => ['changed' => $written, 'added' => 0, 'updated' => $written, 'deleted' => 0],
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

if (!function_exists('intersoccer_rr_tool_financial_batch_size')) {
    /**
     * Empty or non-numeric batch size uses 100. Other values stay inside 1–500.
     *
     * @param mixed $raw
     */
    function intersoccer_rr_tool_financial_batch_size($raw): int {
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return 100;
        }
        $batch = (int) $raw;
        if ($batch < 1) {
            return 100;
        }
        return min(500, $batch);
    }
}

if (!function_exists('intersoccer_rr_tool_financial')) {
    function intersoccer_rr_tool_financial(bool $dry_run, array $scope): array {
        $start = (string) ($scope['start_date'] ?? '');
        if ($start === '') {
            $start = '1970-01-01';
        }
        $batch = intersoccer_rr_tool_financial_batch_size($scope['batch_size'] ?? '');
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

if (!function_exists('intersoccer_rr_tool_fix_issues_filters')) {
    /**
     * Filters shared by diagnostics Preview and safe-fix Apply.
     *
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    function intersoccer_rr_tool_fix_issues_filters(array $scope): array {
        $order_id = (int) ($scope['order_id'] ?? 0);
        $year = trim((string) ($scope['year'] ?? ''));
        if ($year === '0') {
            $year = '';
        }
        return [
            'year' => $year,
            'order_id' => $order_id > 0 ? $order_id : 0,
            'activity_type' => 'All',
            'limit' => (int) INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP,
            'offset' => 0,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_fix_issues_service')) {
    /**
     * @return object|null
     */
    function intersoccer_rr_tool_fix_issues_service() {
        $filtered = apply_filters('intersoccer_rr_diagnostics_service', null);
        if (is_object($filtered) && method_exists($filtered, 'runDiagnostics') && method_exists($filtered, 'runSafeFix')) {
            return $filtered;
        }
        if (!class_exists(\InterSoccer\ReportsRosters\Services\ReportsRostersDiagnosticsService::class)) {
            return null;
        }
        return new \InterSoccer\ReportsRosters\Services\ReportsRostersDiagnosticsService();
    }
}

if (!function_exists('intersoccer_rr_tool_fix_issues_counts_from_diagnostics')) {
    /**
     * @param array<string,mixed> $diagnostics
     * @return array<string,int>
     */
    function intersoccer_rr_tool_fix_issues_counts_from_diagnostics(array $diagnostics): array {
        $reasons = is_array($diagnostics['reason_counts'] ?? null) ? $diagnostics['reason_counts'] : [];
        return [
            'fixed_missing_in_rosters' => (int) ($reasons['missing_in_rosters'] ?? 0),
            'fixed_missing_in_woo_meta' => (int) ($reasons['missing_in_woo_meta'] ?? 0),
            'quarantined_missing_in_woo' => (int) ($reasons['missing_in_woo'] ?? 0),
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_fix_issues_summary')) {
    /**
     * @param array<string,int> $counts
     */
    function intersoccer_rr_tool_fix_issues_summary(array $counts, bool $dry_run): string {
        $summary = sprintf(
            /* translators: 1: roster rows inserted, 2: order-line meta backfilled, 3: roster rows quarantined */
            __('Roster rows to insert: %1$d. Order-line details to fill in: %2$d. Roster rows to set aside: %3$d. This repair has no rollback.', 'intersoccer-reports-rosters'),
            (int) ($counts['fixed_missing_in_rosters'] ?? 0),
            (int) ($counts['fixed_missing_in_woo_meta'] ?? 0),
            (int) ($counts['quarantined_missing_in_woo'] ?? 0)
        );
        if ($dry_run) {
            $summary .= ' ' . __('Nothing was written.', 'intersoccer-reports-rosters');
        }
        return $summary;
    }
}

if (!function_exists('intersoccer_rr_tool_fix_issues')) {
    function intersoccer_rr_tool_fix_issues(bool $dry_run, array $scope): array {
        $filters = intersoccer_rr_tool_fix_issues_filters($scope);
        $note = __('no rollback', 'intersoccer-reports-rosters');
        $service = intersoccer_rr_tool_fix_issues_service();
        if ($service === null) {
            return intersoccer_rr_advanced_tools_empty_result(
                __('Safe fix is unavailable.', 'intersoccer-reports-rosters'),
                intersoccer_rr_scope_is_shop_wide('fix_issues', $scope),
                'unavailable'
            );
        }
        $diagnostics = $service->runDiagnostics($filters);
        $diagnostics = is_array($diagnostics) ? $diagnostics : [];
        $projected = intersoccer_rr_tool_fix_issues_counts_from_diagnostics($diagnostics);
        $projected_total = (int) $projected['fixed_missing_in_rosters'] + (int) $projected['fixed_missing_in_woo_meta'] + (int) $projected['quarantined_missing_in_woo'];
        if ($projected_total > (int) INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP) {
            return [
                'error' => intersoccer_rr_repair_over_cap_message($projected_total),
                'summary' => intersoccer_rr_repair_over_cap_message($projected_total),
                'counts' => [
                    'changed' => 0,
                    'added' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                    'fixed_missing_in_rosters' => 0,
                    'fixed_missing_in_woo_meta' => 0,
                    'quarantined_missing_in_woo' => 0,
                ],
                'rows' => [],
                'over_cap' => true,
                'note' => $note,
                'no_rollback' => true,
                'wrote' => false,
            ];
        }
        $rows = [];
        $mismatches = is_array($diagnostics['mismatches'] ?? null) ? $diagnostics['mismatches'] : [];
        foreach ($mismatches as $mismatch) {
            if (!is_array($mismatch)) {
                continue;
            }
            $item_id = (int) ($mismatch['order_item_id'] ?? 0);
            if ($item_id <= 0) {
                continue;
            }
            $reasons = is_array($mismatch['reasons'] ?? null) ? implode(',', $mismatch['reasons']) : '';
            $rows[] = [
                'id' => 'diag:' . $item_id,
                'before' => ['reasons' => $reasons],
                'after' => ['reasons' => $reasons],
            ];
        }
        if ($dry_run) {
            return [
                'summary' => intersoccer_rr_tool_fix_issues_summary($projected, true),
                'counts' => [
                    'changed' => $projected_total,
                    'added' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                    'fixed_missing_in_rosters' => $projected['fixed_missing_in_rosters'],
                    'fixed_missing_in_woo_meta' => $projected['fixed_missing_in_woo_meta'],
                    'quarantined_missing_in_woo' => $projected['quarantined_missing_in_woo'],
                ],
                'rows' => $rows,
                'note' => $note,
                'no_rollback' => true,
                'wrote' => false,
            ];
        }
        $raw = $service->runSafeFix($filters);
        $raw = is_array($raw) ? $raw : [];
        $applied = [
            'fixed_missing_in_rosters' => (int) ($raw['fixed_missing_in_rosters'] ?? 0),
            'fixed_missing_in_woo_meta' => (int) ($raw['fixed_missing_in_woo_meta'] ?? 0),
            'quarantined_missing_in_woo' => (int) ($raw['quarantined_missing_in_woo'] ?? 0),
        ];
        $applied_total = $applied['fixed_missing_in_rosters'] + $applied['fixed_missing_in_woo_meta'] + $applied['quarantined_missing_in_woo'];
        return [
            'summary' => intersoccer_rr_tool_fix_issues_summary($applied, false),
            'counts' => [
                'changed' => $applied_total,
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
                'fixed_missing_in_rosters' => $applied['fixed_missing_in_rosters'],
                'fixed_missing_in_woo_meta' => $applied['fixed_missing_in_woo_meta'],
                'quarantined_missing_in_woo' => $applied['quarantined_missing_in_woo'],
            ],
            'rows' => $rows,
            'raw' => $raw,
            'note' => $note,
            'no_rollback' => true,
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
        $rows = intersoccer_rr_tool_rebuild_selected_plan($ids);
        $count = count($rows);
        if ($dry_run) {
            return [
                'summary' => sprintf(
                    /* translators: %d: roster rows whose signature would change */
                    __('Preview: %d roster rows would get a new event signature. Nothing was written.', 'intersoccer-reports-rosters'),
                    $count
                ),
                'counts' => ['changed' => $count, 'added' => 0, 'updated' => $count, 'deleted' => 0],
                'rows' => $rows,
                'wrote' => false,
            ];
        }
        $updated = 0;
        $write_ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $write_ids[] = $id;
            }
        }
        if ($write_ids !== [] && class_exists(\InterSoccer\ReportsRosters\Services\SignatureDriftService::class)) {
            $service = new \InterSoccer\ReportsRosters\Services\SignatureDriftService();
            $raw = $service->rebuildSignaturesForIds($write_ids);
            $updated = (int) ($raw['updated'] ?? 0);
        }
        return [
            'summary' => sprintf(
                /* translators: %d: rows updated */
                __('Rebuilt signatures on %d rows.', 'intersoccer-reports-rosters'),
                $updated
            ),
            'counts' => ['changed' => $updated, 'added' => 0, 'updated' => $updated, 'deleted' => 0],
            'rows' => $rows,
            'wrote' => $updated > 0,
        ];
    }
}

if (!function_exists('intersoccer_rr_tool_rebuild_selected_plan')) {
    /**
     * Backup rows for selected signatures. After is the expected signature.
     *
     * @param int[] $ids
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_rr_tool_rebuild_selected_plan(array $ids): array {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'get_row') || !method_exists($wpdb, 'prepare')) {
            return [];
        }
        if (!class_exists(\InterSoccer\ReportsRosters\Services\SignatureDriftService::class)) {
            return [];
        }
        $service = new \InterSoccer\ReportsRosters\Services\SignatureDriftService();
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $rows = [];
        foreach ($ids as $id) {
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT id, activity_type, venue, age_group, camp_terms, course_day, times, season, girls_only, city, canton_region, product_id, start_date, event_signature FROM {$table} WHERE id = %d",
                (int) $id
            ), ARRAY_A);
            if (!is_array($current)) {
                continue;
            }
            $before = (string) ($current['event_signature'] ?? '');
            $after = $service->expectedSignatureForRow($current);
            if ($after === '' || $after === $before) {
                continue;
            }
            $rows[] = [
                'id' => (int) $id,
                'before' => ['event_signature' => $before],
                'after' => ['event_signature' => $after],
            ];
        }
        return $rows;
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
        $cap = (int) INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_signature = %s",
            $source
        ));
        if ($count > $cap) {
            return [
                'error' => intersoccer_rr_repair_over_cap_message($count),
                'summary' => intersoccer_rr_repair_over_cap_message($count),
                'counts' => ['changed' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0],
                'rows' => [],
                'over_cap' => true,
                'wrote' => false,
            ];
        }
        $found = $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_signature FROM {$table} WHERE event_signature = %s LIMIT %d",
            $source,
            $cap
        ), ARRAY_A);
        $found = is_array($found) ? $found : [];
        if (count($found) !== $count) {
            return [
                'error' => intersoccer_rr_repair_over_cap_message(max($count, count($found))),
                'summary' => intersoccer_rr_repair_over_cap_message(max($count, count($found))),
                'counts' => ['changed' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0],
                'rows' => [],
                'over_cap' => true,
                'wrote' => false,
            ];
        }
        $rows = [];
        foreach ($found as $row) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'before' => ['event_signature' => $source],
                'after' => ['event_signature' => $target],
            ];
        }
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
            "UPDATE {$table} SET event_signature = %s WHERE event_signature = %s LIMIT %d",
            $target,
            $source,
            $cap
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
            $preview_scope = intersoccer_rr_advanced_tools_scope_label($scope);
            if (($result['note'] ?? '') !== '') {
                $preview_scope = trim($preview_scope . ' ' . $result['note']);
            }
            if (!empty($result['over_cap']) || count($result['rows']) > (int) INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP) {
                $cap_message = $result['error'] !== ''
                    ? $result['error']
                    : intersoccer_rr_repair_over_cap_message(count($result['rows']));
                intersoccer_rr_repair_log([
                    'user_id' => $user_id,
                    'tool' => $tool,
                    'mode' => 'preview',
                    'scope' => $preview_scope,
                    'counts' => $result['counts'],
                    'backup' => '',
                ]);
                return ['type' => 'error', 'message' => $cap_message, 'result' => $result];
            }
            if ($result['error'] !== '' && (int) $result['counts']['changed'] === 0) {
                intersoccer_rr_repair_log([
                    'user_id' => $user_id,
                    'tool' => $tool,
                    'mode' => 'preview',
                    'scope' => $preview_scope,
                    'counts' => $result['counts'],
                    'backup' => '',
                ]);
                return ['type' => 'error', 'message' => $result['error'], 'result' => $result];
            }
            $result['shop_wide'] = intersoccer_rr_scope_is_shop_wide($tool, $scope);
            intersoccer_rr_repair_store_preview($user_id, $tool, $scope, $result);
            intersoccer_rr_repair_log([
                'user_id' => $user_id,
                'tool' => $tool,
                'mode' => 'preview',
                'scope' => $preview_scope,
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
        if (!empty($again['over_cap']) || count($again['rows']) > (int) INTERSOCCER_RR_REPAIR_BACKUP_ROW_CAP) {
            return [
                'type' => 'error',
                'message' => $again['error'] !== ''
                    ? $again['error']
                    : intersoccer_rr_repair_over_cap_message(count($again['rows'])),
                'result' => null,
            ];
        }
        $no_rollback = !empty($again['no_rollback']) || !intersoccer_rr_repair_tool_supports_rollback($tool);
        $backup = $no_rollback ? '' : intersoccer_rr_repair_write_backup($tool, $user_id, $again['rows']);
        $applied = intersoccer_rr_advanced_tools_execute($tool, false, $scope);
        intersoccer_rr_repair_clear_preview($user_id, $tool);
        $apply_scope = intersoccer_rr_advanced_tools_scope_label($scope);
        $apply_note = (string) ($applied['note'] ?? $again['note'] ?? '');
        if ($apply_note !== '') {
            $apply_scope = trim($apply_scope . ' ' . $apply_note);
        }
        intersoccer_rr_repair_log([
            'user_id' => $user_id,
            'tool' => $tool,
            'mode' => 'apply',
            'scope' => $apply_scope,
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
        $backup_tool = (string) ($backup['tool'] ?? '');
        if (!intersoccer_rr_repair_tool_supports_rollback($backup_tool)) {
            return [
                'type' => 'error',
                'message' => __('This repair has no rollback.', 'intersoccer-reports-rosters'),
                'result' => null,
            ];
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

if (!function_exists('intersoccer_rr_repair_roster_rollback_columns')) {
    /**
     * Columns read back and restored together. Keep the SELECT and the restore allow-list on this list.
     *
     * @return string[]
     */
    function intersoccer_rr_repair_roster_rollback_columns(): array {
        return ['season', 'event_signature', 'course_day', 'day_presence', 'venue', 'booking_type', 'selected_days', 'camp_terms', 'times', 'city', 'canton_region', 'activity_type'];
    }
}

if (!function_exists('intersoccer_rr_repair_read_roster_value')) {
    /**
     * @param mixed $id
     * @return array<string,string>
     */
    function intersoccer_rr_repair_read_roster_value($id): array {
        if (is_string($id) && strpos($id, 'meta:') === 0 && function_exists('wc_get_order_item_meta')) {
            $parts = explode(':', $id, 3);
            return ['value' => (string) wc_get_order_item_meta((int) ($parts[1] ?? 0), (string) ($parts[2] ?? ''), true)];
        }
        if (!is_numeric($id)) {
            return [];
        }
        global $wpdb;
        $columns = implode(', ', intersoccer_rr_repair_roster_rollback_columns());
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$columns} FROM {$wpdb->prefix}intersoccer_rosters WHERE id = %d",
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
        if (is_string($id) && strpos($id, 'meta:') === 0 && function_exists('wc_update_order_item_meta')) {
            $parts = explode(':', $id, 3);
            $item_id = (int) ($parts[1] ?? 0);
            $meta_key = (string) ($parts[2] ?? '');
            if ($item_id > 0 && $meta_key !== '') {
                wc_update_order_item_meta($item_id, $meta_key, (string) ($before['value'] ?? ''));
            }
            return;
        }
        if (!is_numeric($id)) {
            return;
        }
        global $wpdb;
        $allowed = intersoccer_rr_repair_roster_rollback_columns();
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

if (!function_exists('intersoccer_rr_advanced_tools_products_orders_url')) {
    /**
     * Cross-link to Product Variations advanced tools when that page is registered.
     */
    function intersoccer_rr_advanced_tools_products_orders_url(): string {
        if (!function_exists('current_user_can') || !current_user_can('intersoccer_run_repairs')) {
            return '';
        }
        $slug = 'intersoccer-pv-advanced-tools';
        if (!intersoccer_rr_admin_page_is_registered($slug)) {
            return '';
        }
        return admin_url('admin.php?page=' . $slug);
    }
}

if (!function_exists('intersoccer_rr_admin_page_is_registered')) {
    function intersoccer_rr_admin_page_is_registered(string $slug): bool {
        global $submenu, $_parent_pages;
        if (isset($_parent_pages) && is_array($_parent_pages) && array_key_exists($slug, $_parent_pages)) {
            return true;
        }
        if (!isset($submenu) || !is_array($submenu)) {
            return false;
        }
        foreach ($submenu as $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item) && isset($item[2]) && (string) $item[2] === $slug) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('intersoccer_rr_repair_format_counts_for_display')) {
    /**
     * Translated count labels for the run log. Structured keys stay in the stored result.
     *
     * @param array<string,mixed> $counts
     */
    function intersoccer_rr_repair_format_counts_for_display(array $counts): string {
        $labels = [
            'changed' => __('Changed', 'intersoccer-reports-rosters'),
            'added' => __('Added', 'intersoccer-reports-rosters'),
            'updated' => __('Updated', 'intersoccer-reports-rosters'),
            'deleted' => __('Deleted', 'intersoccer-reports-rosters'),
            'fixed_missing_in_rosters' => __('Roster rows to insert', 'intersoccer-reports-rosters'),
            'fixed_missing_in_woo_meta' => __('Order-line details to fill in', 'intersoccer-reports-rosters'),
            'quarantined_missing_in_woo' => __('Roster rows to set aside', 'intersoccer-reports-rosters'),
        ];
        $parts = [];
        foreach ($counts as $key => $value) {
            if (!is_string($key) || !isset($labels[$key]) || is_array($value)) {
                continue;
            }
            $parts[] = $labels[$key] . ': ' . (int) $value;
        }
        return implode('. ', $parts);
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
            <h1><?php esc_html_e('InterSoccer Advanced tools: Rosters & reports', 'intersoccer-reports-rosters'); ?></h1>
            <p><?php esc_html_e('Repairs run here so everyday roster screens stay read-only. Preview writes nothing and expires after 30 minutes. Apply stays off until a Preview exists for that tool. Type APPLY to confirm. Shop-wide runs also require GLOBAL. A backup of every changed row is stored before Apply when the change set is within the backup limit. Rollback restores a written row from that pre-Apply snapshot. Upgrade Database, Fix Issues, Financial Attribution Backfill, and Sync All Placeholders have no rollback. Reconcile order-sync has no rollback.', 'intersoccer-reports-rosters'); ?></p>
            <?php
            $products_orders_url = intersoccer_rr_advanced_tools_products_orders_url();
            if ($products_orders_url !== '') :
                ?>
                <p><a href="<?php echo esc_url($products_orders_url); ?>"><?php esc_html_e('InterSoccer Advanced tools: Products & orders', 'intersoccer-reports-rosters'); ?></a></p>
            <?php endif; ?>
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
            echo '<td>' . esc_html(intersoccer_rr_repair_format_counts_for_display($counts)) . '</td>';
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
        $rollback_items = [];
        foreach ($index as $item) {
            if (!is_array($item) || empty($item['file'])) {
                continue;
            }
            if (!intersoccer_rr_repair_tool_supports_rollback((string) ($item['tool'] ?? ''))) {
                continue;
            }
            $rollback_items[] = $item;
        }
        if ($rollback_items === []) {
            echo '<p>' . esc_html__('No backups yet.', 'intersoccer-reports-rosters') . '</p>';
            return;
        }
        ?>
        <form method="post" action="">
            <input type="hidden" name="intersoccer_rr_repair_tool" value="rollback" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(INTERSOCCER_RR_REPAIR_NONCE)); ?>" />
            <p>
                <label for="intersoccer_rr_repair_backup"><?php esc_html_e('Backup', 'intersoccer-reports-rosters'); ?></label>
                <select name="intersoccer_rr_repair_backup" id="intersoccer_rr_repair_backup">
                    <?php foreach (array_slice($rollback_items, 0, 20) as $item) : ?>
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
