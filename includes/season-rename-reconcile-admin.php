<?php
/**
 * Admin: scoped roster reconcile after program-season term renames.
 *
 * Advanced tools → Season rename repair. Same scope as the WP-CLI
 * `reconcile-orders --products=<ids>` run, with the product list chosen
 * automatically: every roster product whose current program-season term has
 * no year in its name ("Winter Courses", "Spring/Summer Courses").
 *
 * - Preview: season/signature dry run, zero writes, no order re-sync.
 * - Apply: re-syncs the orders that have roster rows on those products
 *   (backfills missing rows), then updates season + event_signature on those
 *   products' rows only. Never deletes rows, never truncates, never runs the
 *   global "Rebuild Event Signatures".
 * - Optional one-season weekday repair: choose a named season (for example
 *   "Autumn 2026"). Preview lists rows whose course_day is a French/German
 *   weekday; ticked Apply rewrites those days to English and re-signs only
 *   those rows. Season labels, session times, and other seasons are not
 *   changed. Orders are not re-synced. Never deletes.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!defined('INTERSOCCER_RR_SEASON_RENAME_PAGE')) {
    define('INTERSOCCER_RR_SEASON_RENAME_PAGE', 'intersoccer-advanced-tools');
}
if (!defined('INTERSOCCER_RR_SEASON_RENAME_NONCE')) {
    define('INTERSOCCER_RR_SEASON_RENAME_NONCE', 'intersoccer_rr_season_rename_reconcile');
}

$intersoccer_rr_repair_contract = __DIR__ . '/repair-contract.php';
if (is_readable($intersoccer_rr_repair_contract)) {
    require_once $intersoccer_rr_repair_contract;
}

if (!function_exists('intersoccer_rr_season_rename_product_ids')) {
    /**
     * Roster product ids whose current program-season term has no year.
     *
     * @param callable|null $is_evergreen Test seam; defaults to intersoccer_product_program_season_is_evergreen.
     * @param int[]|null    $candidates   Test seam; defaults to DISTINCT product_id from the roster table.
     * @return int[]
     */
    function intersoccer_rr_season_rename_product_ids($is_evergreen = null, $candidates = null) {
        if ($candidates === null) {
            global $wpdb;
            $table = $wpdb->prefix . 'intersoccer_rosters';
            $candidates = $wpdb->get_col("SELECT DISTINCT product_id FROM {$table} WHERE product_id > 0");
        }
        if ($is_evergreen === null) {
            $is_evergreen = 'intersoccer_product_program_season_is_evergreen';
        }
        if (!is_callable($is_evergreen)) {
            return [];
        }
        $out = [];
        foreach ((array) $candidates as $pid) {
            $pid = (int) $pid;
            if ($pid > 0 && call_user_func($is_evergreen, $pid)) {
                $out[$pid] = $pid;
            }
        }
        $out = array_values($out);
        sort($out);
        return $out;
    }
}

if (!function_exists('intersoccer_rr_season_rename_reconcile')) {
    /**
     * Run the scoped reconcile for season-renamed products.
     *
     * @param bool          $dry_run
     * @param int[]|null    $product_ids Test seam; defaults to intersoccer_rr_season_rename_product_ids().
     * @param callable|null $runner      Test seam; defaults to intersoccer_reconcile_rosters.
     * @return array{product_ids:int[],options:array,result:?array,error:string}
     */
    function intersoccer_rr_season_rename_reconcile($dry_run, $product_ids = null, $runner = null) {
        $out = ['product_ids' => [], 'options' => [], 'result' => null, 'error' => '', 'peak_memory_mb' => null];
        if ($runner === null) {
            if (!function_exists('intersoccer_rr_load_reconcile') || !intersoccer_rr_load_reconcile()) {
                $out['error'] = __('Roster reconcile could not be loaded.', 'intersoccer-reports-rosters');
                return $out;
            }
            $runner = 'intersoccer_reconcile_rosters';
        }
        if ($product_ids === null) {
            $product_ids = intersoccer_rr_season_rename_product_ids();
        }
        $product_ids = array_values(array_filter(array_map('intval', (array) $product_ids)));
        $out['product_ids'] = $product_ids;
        if ($product_ids === []) {
            $out['error'] = __('No roster products have a program-season term without a year. Nothing to do.', 'intersoccer-reports-rosters');
            return $out;
        }
        $dry_run = (bool) $dry_run;
        $options = [
            'product_ids' => $product_ids,
            'include_product_siblings' => false,
            'delete_obsolete' => false,
            'dry_run' => $dry_run,
            'skip_order_sync' => $dry_run,
        ];
        $out['options'] = $options;
        $result = call_user_func($runner, $options);
        $out['result'] = is_array($result) ? $result : null;
        $out['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        return $out;
    }
}

if (!function_exists('intersoccer_rr_season_rename_group_summary')) {
    /**
     * Per-product group (distinct event_signature) and row counts before/after.
     *
     * @param array $season season_signature result block.
     * @return array<int,array{product_id:int,groups_before:int,groups_after:int,rows:int}>
     */
    function intersoccer_rr_season_rename_group_summary(array $season) {
        $before = is_array($season['group_counts_before'] ?? null) ? $season['group_counts_before'] : [];
        $after = is_array($season['group_counts_after'] ?? null) ? $season['group_counts_after'] : [];
        $pids = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($pids);
        $rows = [];
        foreach ($pids as $pid) {
            $b = is_array($before[$pid] ?? null) ? $before[$pid] : [];
            $a = is_array($after[$pid] ?? null) ? $after[$pid] : [];
            $rows[] = [
                'product_id' => (int) $pid,
                'groups_before' => count($b),
                'groups_after' => count($a),
                'rows' => (int) array_sum($a !== [] ? $a : $b),
            ];
        }
        return $rows;
    }
}


if (!function_exists('intersoccer_rr_season_repair_labels')) {
    /**
     * Distinct roster season labels for the optional one-season weekday repair.
     *
     * @param string[]|null $rows Test seam; defaults to DISTINCT season from the roster table.
     * @return string[]
     */
    function intersoccer_rr_season_repair_labels($rows = null) {
        if ($rows === null) {
            global $wpdb;
            $table = $wpdb->prefix . 'intersoccer_rosters';
            $rows = $wpdb->get_col("SELECT DISTINCT season FROM {$table} WHERE season IS NOT NULL AND season <> '' ORDER BY season ASC");
        }
        $out = [];
        foreach ((array) $rows as $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $out[$label] = $label;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('intersoccer_rr_season_weekday_reconcile')) {
    /**
     * Opt-in weekday + re-sign pass for a single named season.
     *
     * Calls the signature reconcile directly (no order re-sync, no deletes).
     *
     * @param bool          $dry_run
     * @param string        $season
     * @param callable|null $runner Test seam; defaults to intersoccer_reconcile_roster_season_signatures.
     * @return array{product_ids:int[],options:array,result:?array,error:string,peak_memory_mb:?float}
     */
    function intersoccer_rr_season_weekday_reconcile($dry_run, $season, $runner = null) {
        $out = ['product_ids' => [], 'options' => [], 'result' => null, 'error' => '', 'peak_memory_mb' => null];
        $season = trim((string) $season);
        if ($season === '') {
            $out['error'] = __('Choose a season before running the weekday repair.', 'intersoccer-reports-rosters');
            return $out;
        }
        if ($runner === null) {
            if (!function_exists('intersoccer_rr_load_reconcile') || !intersoccer_rr_load_reconcile()) {
                $out['error'] = __('Roster reconcile could not be loaded.', 'intersoccer-reports-rosters');
                return $out;
            }
            $runner = 'intersoccer_reconcile_roster_season_signatures';
        }
        $dry_run = (bool) $dry_run;
        $options = [
            'season' => $season,
            'weekday_repair' => true,
            'include_product_siblings' => false,
            'delete_obsolete' => false,
            'dry_run' => $dry_run,
            'skip_order_sync' => true,
            'scope_all' => false,
        ];
        $out['options'] = $options;
        $season_result = call_user_func($runner, $options);
        $season_result = is_array($season_result) ? $season_result : [];
        $out['result'] = [
            'status' => 'success',
            'synced' => 0,
            'deleted' => 0,
            'season_signature' => $season_result,
        ];
        $out['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        return $out;
    }
}

if (!function_exists('intersoccer_rr_season_rename_rows_from_run')) {
    /**
     * @param array<string,mixed> $run
     * @return array<int,array<string,mixed>>
     */
    function intersoccer_rr_season_rename_rows_from_run(array $run): array {
        $result = is_array($run['result'] ?? null) ? $run['result'] : [];
        $season = is_array($result['season_signature'] ?? null) ? $result['season_signature'] : [];
        $changes = is_array($season['changes'] ?? null) ? $season['changes'] : [];
        $rows = [];
        foreach ($changes as $change) {
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
        return $rows;
    }
}

if (!function_exists('intersoccer_rr_season_rename_tool_run')) {
    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    function intersoccer_rr_season_rename_tool_run($dry_run, array $scope): array {
        $season = trim((string) ($scope['season'] ?? ''));
        if ($season !== '') {
            $run = intersoccer_rr_season_weekday_reconcile((bool) $dry_run, $season);
        } else {
            $run = intersoccer_rr_season_rename_reconcile((bool) $dry_run);
        }
        $result = is_array($run['result'] ?? null) ? $run['result'] : [];
        $season_block = is_array($result['season_signature'] ?? null) ? $result['season_signature'] : [];
        $changed = (int) ($season_block['needing_rename_merge'] ?? 0);
        $rows = intersoccer_rr_season_rename_rows_from_run($run);
        return [
            'error' => (string) ($run['error'] ?? ''),
            'summary' => sprintf(
                /* translators: 1: rows needing repair, 2: peak memory MB */
                __('Rows needing repair: %1$d. Peak memory: %2$s MB', 'intersoccer-reports-rosters'),
                $changed,
                (string) ($run['peak_memory_mb'] ?? '')
            ),
            'counts' => [
                'changed' => $changed,
                'added' => 0,
                'updated' => (int) ($season_block['updated'] ?? 0),
                'deleted' => 0,
            ],
            'rows' => $rows,
            'shop_wide' => false,
            'wrote' => !$dry_run && ($run['error'] ?? '') === '',
            'raw' => $run,
        ];
    }
}

if (!function_exists('intersoccer_rr_render_season_rename_page')) {
    function intersoccer_rr_render_season_rename_page() {
        if (!current_user_can(INTERSOCCER_RR_REPAIR_CAP)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'intersoccer-reports-rosters'));
        }

        $notice = '';
        $run = null;
        $labels = intersoccer_rr_season_repair_labels();
        $selected_season = '';
        $user_id = (int) get_current_user_id();
        $tool = 'season_rename';

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['intersoccer_rr_season_rename_action'])
        ) {
            if (!intersoccer_rr_repair_verify_nonce(INTERSOCCER_RR_SEASON_RENAME_NONCE)) {
                wp_die(esc_html__('Security check failed.', 'intersoccer-reports-rosters'));
            }
            $action = sanitize_key(wp_unslash((string) $_POST['intersoccer_rr_season_rename_action']));
            $selected_season = isset($_POST['intersoccer_rr_repair_season'])
                ? sanitize_text_field(wp_unslash((string) $_POST['intersoccer_rr_repair_season']))
                : '';
            if ($selected_season !== '' && !in_array($selected_season, $labels, true)) {
                $notice = __('That season is not in the roster table. Choose a season from the list.', 'intersoccer-reports-rosters');
                $selected_season = '';
            } else {
                $scope = ['tool' => $tool, 'season' => $selected_season];
                if ($action === 'preview') {
                    $mapped = intersoccer_rr_season_rename_tool_run(true, $scope);
                    $run = is_array($mapped['raw'] ?? null) ? $mapped['raw'] : null;
                    if (($mapped['error'] ?? '') !== '') {
                        $notice = (string) $mapped['error'];
                    } else {
                        intersoccer_rr_repair_store_preview($user_id, $tool, $scope, $mapped);
                        intersoccer_rr_repair_log([
                            'user_id' => $user_id,
                            'tool' => $tool,
                            'mode' => 'preview',
                            'scope' => $selected_season !== '' ? $selected_season : 'renamed-seasons',
                            'counts' => $mapped['counts'],
                            'backup' => '',
                        ]);
                        $notice = __('Preview (dry run) finished. Nothing was written.', 'intersoccer-reports-rosters');
                    }
                } elseif ($action === 'apply') {
                    $typed = isset($_POST['intersoccer_rr_repair_phrase'])
                        ? sanitize_text_field(wp_unslash((string) $_POST['intersoccer_rr_repair_phrase']))
                        : '';
                    $gate = intersoccer_rr_repair_gate_apply($user_id, $tool, $scope, $typed, '', '');
                    if (!$gate['ok']) {
                        $notice = (string) $gate['error'];
                    } else {
                        $again = intersoccer_rr_season_rename_tool_run(true, $scope);
                        $stored = intersoccer_rr_repair_get_preview($user_id, $tool);
                        if ($stored === null || (string) ($stored['rows_fingerprint'] ?? '') !== intersoccer_rr_repair_rows_fingerprint($again['rows'])) {
                            $notice = __('The change set no longer matches the Preview. Run Preview again.', 'intersoccer-reports-rosters');
                        } else {
                            $backup = intersoccer_rr_repair_write_backup($tool, $user_id, $again['rows']);
                            $mapped = intersoccer_rr_season_rename_tool_run(false, $scope);
                            $run = is_array($mapped['raw'] ?? null) ? $mapped['raw'] : null;
                            if (($mapped['rows'] ?? []) !== []) {
                                intersoccer_rr_repair_replace_backup_rows($backup, $mapped['rows']);
                            }
                            intersoccer_rr_repair_clear_preview($user_id, $tool);
                            intersoccer_rr_repair_log([
                                'user_id' => $user_id,
                                'tool' => $tool,
                                'mode' => 'apply',
                                'scope' => $selected_season !== '' ? $selected_season : 'renamed-seasons',
                                'counts' => $mapped['counts'],
                                'backup' => $backup,
                            ]);
                            $notice = ($mapped['error'] ?? '') !== ''
                                ? (string) $mapped['error']
                                : __('Apply finished.', 'intersoccer-reports-rosters');
                        }
                    }
                }
            }
        }

        $result = is_array($run['result'] ?? null) ? $run['result'] : [];
        $season = is_array($result['season_signature'] ?? null) ? $result['season_signature'] : [];
        $changes = is_array($season['changes'] ?? null) ? $season['changes'] : [];
        $remaining = is_array($season['remaining_splits'] ?? null) ? $season['remaining_splits'] : [];
        $weekday_mode = $selected_season !== '';
        $preview = intersoccer_rr_repair_get_preview($user_id, $tool);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Season rename repair', 'intersoccer-reports-rosters'); ?></h1>
            <p><?php esc_html_e('Merges duplicate rosters caused by renamed program seasons (for example "Winter 2027" renamed to "Winter Courses"). Only products whose current season term has no year are touched. Rows are never deleted. Run the Product Variations "Season labels" repair first.', 'intersoccer-reports-rosters'); ?></p>
            <p><?php esc_html_e('To repair French or German weekdays in one named season (for example Autumn 2026), choose that season below. That mode only rewrites those weekdays to English and re-signs those rows. It does not re-sync orders, does not change session times, and does not touch other seasons. Never use Reconcile Rosters or Rebuild Event Signatures.', 'intersoccer-reports-rosters'); ?></p>
            <?php if ($notice !== '') : ?>
                <div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($run['error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html((string) $run['error']); ?></p></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(INTERSOCCER_RR_SEASON_RENAME_NONCE)); ?>" />
                <p>
                    <label for="intersoccer_rr_repair_season"><?php esc_html_e('Season', 'intersoccer-reports-rosters'); ?></label>
                    <select name="intersoccer_rr_repair_season" id="intersoccer_rr_repair_season">
                        <option value=""><?php esc_html_e('Renamed seasons only (no year in the term)', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($labels as $label) : ?>
                            <option value="<?php echo esc_attr($label); ?>"<?php echo (string) $selected_season === (string) $label ? ' selected="selected"' : ''; ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label for="intersoccer_rr_repair_phrase"><?php esc_html_e('Type APPLY to confirm Apply', 'intersoccer-reports-rosters'); ?></label>
                    <input type="text" name="intersoccer_rr_repair_phrase" id="intersoccer_rr_repair_phrase" value="" autocomplete="off" />
                </p>
                <p>
                    <button type="submit" class="button" name="intersoccer_rr_season_rename_action" value="preview"><?php esc_html_e('Preview (dry run)', 'intersoccer-reports-rosters'); ?></button>
                    <button type="submit" class="button button-primary" name="intersoccer_rr_season_rename_action" value="apply"<?php echo $preview === null ? ' disabled="disabled"' : ''; ?>><?php esc_html_e('Apply', 'intersoccer-reports-rosters'); ?></button>
                </p>
            </form>
            <?php if ($run && empty($run['error'])) : ?>
                <h2><?php esc_html_e('Result', 'intersoccer-reports-rosters'); ?></h2>
                <p><?php
                    if ($weekday_mode) {
                        printf(
                            /* translators: 1: preview or apply, 2: season name, 3: rows examined, 4: rows needing weekday repair, 5: rows changed, 6: rows unchanged, 7: peak memory MB */
                            esc_html__('Mode: %1$s | Season: %2$s | Rows examined: %3$d | Rows needing weekday repair: %4$d | Rows changed: %5$d | Rows unchanged: %6$d | Peak memory: %7$s MB', 'intersoccer-reports-rosters'),
                            !empty($season['dry_run']) ? esc_html__('preview', 'intersoccer-reports-rosters') : esc_html__('apply', 'intersoccer-reports-rosters'),
                            esc_html($selected_season),
                            (int) ($season['examined'] ?? 0),
                            (int) ($season['needing_rename_merge'] ?? 0),
                            (int) ($season['updated'] ?? 0),
                            (int) ($season['unchanged'] ?? 0),
                            esc_html((string) ($run['peak_memory_mb'] ?? ''))
                        );
                    } else {
                        printf(
                            /* translators: 1: preview or apply, 2: product count, 3: re-synced order lines, 4: rows needing merge, 5: rows changed, 6: rows unchanged, 7: peak memory MB */
                            esc_html__('Mode: %1$s | Products: %2$d | Order lines re-synced: %3$d | Rows needing rename merge: %4$d | Rows changed: %5$d | Rows unchanged: %6$d | Peak memory: %7$s MB', 'intersoccer-reports-rosters'),
                            !empty($season['dry_run']) ? esc_html__('preview', 'intersoccer-reports-rosters') : esc_html__('apply', 'intersoccer-reports-rosters'),
                            count($run['product_ids'] ?? []),
                            (int) ($result['synced'] ?? 0),
                            (int) ($season['needing_rename_merge'] ?? 0),
                            (int) ($season['updated'] ?? 0),
                            (int) ($season['unchanged'] ?? 0),
                            esc_html((string) ($run['peak_memory_mb'] ?? ''))
                        );
                    }
                ?></p>
                <?php $groups = intersoccer_rr_season_rename_group_summary($season); ?>
                <?php if ($groups !== []) : ?>
                <h3><?php esc_html_e('Group counts by product', 'intersoccer-reports-rosters'); ?></h3>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e('Product', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Groups before', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Groups after', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Rows', 'intersoccer-reports-rosters'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($groups as $g) : ?>
                        <tr>
                            <td><?php echo (int) ($g['product_id'] ?? 0); ?></td>
                            <td><?php echo (int) ($g['groups_before'] ?? 0); ?></td>
                            <td><?php echo (int) ($g['groups_after'] ?? 0); ?></td>
                            <td><?php echo (int) ($g['rows'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <?php if ($changes !== []) : ?>
                    <h3><?php echo esc_html(sprintf(__('Row changes (%d, first 200 shown)', 'intersoccer-reports-rosters'), count($changes))); ?></h3>
                    <table class="widefat striped">
                        <thead><tr>
                            <th><?php esc_html_e('Row', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Order', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Product', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Season before', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Season after', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Day before', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Day after', 'intersoccer-reports-rosters'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach (array_slice($changes, 0, 200) as $c) : ?>
                            <tr>
                                <td><?php echo (int) ($c['id'] ?? 0); ?></td>
                                <td><?php echo (int) ($c['order_id'] ?? 0); ?></td>
                                <td><?php echo (int) ($c['product_id'] ?? 0); ?></td>
                                <td><?php echo esc_html((string) ($c['old_season'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($c['new_season'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($c['old_course_day'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($c['new_course_day'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($remaining !== []) : ?>
                    <h3><?php esc_html_e('Variations that stay split after this pass (session times or other fields, not weekdays)', 'intersoccer-reports-rosters'); ?></h3>
                    <table class="widefat striped">
                        <thead><tr>
                            <th><?php esc_html_e('Product', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Variation', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Groups', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Rows', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Times', 'intersoccer-reports-rosters'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($remaining as $split) : ?>
                            <tr>
                                <td><?php echo (int) ($split['product_id'] ?? 0); ?></td>
                                <td><?php echo (int) ($split['variation_id'] ?? 0); ?></td>
                                <td><?php echo (int) ($split['groups'] ?? 0); ?></td>
                                <td><?php echo (int) ($split['rows'] ?? 0); ?></td>
                                <td><?php echo esc_html(implode(', ', array_map('strval', (array) ($split['times'] ?? [])))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
