<?php
/**
 * Admin: scoped roster reconcile after program-season term renames.
 *
 * Reports and Rosters → Season rename repair. Same scope as the WP-CLI
 * `reconcile-orders --products=<ids>` run, with the product list chosen
 * automatically: every roster product whose current program-season term has
 * no year in its name ("Winter Courses", "Spring/Summer Courses").
 *
 * - Preview: season/signature dry run, zero writes, no order re-sync.
 * - Apply: re-syncs the orders that have roster rows on those products
 *   (backfills missing rows), then updates season + event_signature on those
 *   products' rows only. Never deletes rows, never truncates, never runs the
 *   global "Rebuild Event Signatures".
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!defined('INTERSOCCER_RR_SEASON_RENAME_PAGE')) {
    define('INTERSOCCER_RR_SEASON_RENAME_PAGE', 'intersoccer-season-rename-repair');
}
if (!defined('INTERSOCCER_RR_SEASON_RENAME_NONCE')) {
    define('INTERSOCCER_RR_SEASON_RENAME_NONCE', 'intersoccer_rr_season_rename_reconcile');
}

add_action('admin_menu', 'intersoccer_rr_register_season_rename_page', 30);

if (!function_exists('intersoccer_rr_register_season_rename_page')) {
    function intersoccer_rr_register_season_rename_page() {
        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Season rename repair', 'intersoccer-reports-rosters'),
            __('Season rename repair', 'intersoccer-reports-rosters'),
            'manage_woocommerce',
            INTERSOCCER_RR_SEASON_RENAME_PAGE,
            'intersoccer_rr_render_season_rename_page'
        );
    }
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

if (!function_exists('intersoccer_rr_render_season_rename_page')) {
    function intersoccer_rr_render_season_rename_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'intersoccer-reports-rosters'));
        }

        $notice = '';
        $run = null;

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_POST['intersoccer_rr_season_rename_action'])
        ) {
            check_admin_referer(INTERSOCCER_RR_SEASON_RENAME_NONCE);
            $action = sanitize_key(wp_unslash((string) $_POST['intersoccer_rr_season_rename_action']));
            if ($action === 'preview') {
                $run = intersoccer_rr_season_rename_reconcile(true);
                $notice = __('Preview (dry run) finished. Nothing was written.', 'intersoccer-reports-rosters');
            } elseif ($action === 'apply') {
                if (empty($_POST['intersoccer_rr_season_rename_confirm'])) {
                    $notice = __('Tick the confirmation box before Apply.', 'intersoccer-reports-rosters');
                } else {
                    $run = intersoccer_rr_season_rename_reconcile(false);
                    $notice = __('Apply finished.', 'intersoccer-reports-rosters');
                }
            }
            if (is_array($run) && $run['error'] !== '') {
                $notice = $run['error'];
            }
        }

        $result = is_array($run) && is_array($run['result']) ? $run['result'] : [];
        $season = is_array($result['season_signature'] ?? null) ? $result['season_signature'] : [];
        $changes = is_array($season['changes'] ?? null) ? $season['changes'] : [];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Season rename repair', 'intersoccer-reports-rosters'); ?></h1>
            <p><?php esc_html_e('Merges duplicate rosters caused by renamed program seasons (for example "Winter 2027" renamed to "Winter Courses"). Only products whose current season term has no year are touched. Rows are never deleted. Run the Product Variations "Season labels" repair first.', 'intersoccer-reports-rosters'); ?></p>
            <?php if ($notice !== '') : ?>
                <div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <form method="post">
                <?php wp_nonce_field(INTERSOCCER_RR_SEASON_RENAME_NONCE); ?>
                <p>
                    <button type="submit" class="button" name="intersoccer_rr_season_rename_action" value="preview"><?php esc_html_e('Preview (dry run)', 'intersoccer-reports-rosters'); ?></button>
                </p>
                <p>
                    <label><input type="checkbox" name="intersoccer_rr_season_rename_confirm" value="1" />
                        <?php esc_html_e('I ran Preview and want to apply these changes.', 'intersoccer-reports-rosters'); ?></label>
                </p>
                <p>
                    <button type="submit" class="button button-primary" name="intersoccer_rr_season_rename_action" value="apply"><?php esc_html_e('Apply', 'intersoccer-reports-rosters'); ?></button>
                </p>
            </form>
            <?php if (is_array($run) && $run['product_ids'] !== []) : ?>
                <h2><?php esc_html_e('Result', 'intersoccer-reports-rosters'); ?></h2>
                <p><?php
                    echo esc_html(sprintf(
                        /* translators: 1: mode, 2: products, 3: orders re-synced, 4: rows needing merge, 5: rows changed, 6: rows unchanged, 7: peak memory MB */
                        __('Mode: %1$s | Products: %2$d | Order lines re-synced: %3$d | Rows needing rename merge: %4$d | Rows changed: %5$d | Rows unchanged: %6$d | Peak memory: %7$s MB', 'intersoccer-reports-rosters'),
                        !empty($run['options']['dry_run']) ? 'preview' : 'apply',
                        count($run['product_ids']),
                        (int) ($result['synced'] ?? 0),
                        (int) ($season['needing_rename_merge'] ?? 0),
                        (int) ($season['updated'] ?? 0),
                        (int) ($season['unchanged'] ?? 0),
                        (string) ($run['peak_memory_mb'] ?? '?')
                    ));
                ?></p>
                <table class="widefat striped" style="max-width:640px">
                    <thead><tr>
                        <th><?php esc_html_e('Product ID', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Roster groups before', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Roster groups after', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Rows', 'intersoccer-reports-rosters'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (intersoccer_rr_season_rename_group_summary($season) as $g) : ?>
                        <tr>
                            <td><?php echo (int) $g['product_id']; ?></td>
                            <td><?php echo (int) $g['groups_before']; ?></td>
                            <td><?php echo (int) $g['groups_after']; ?></td>
                            <td><?php echo (int) $g['rows']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($changes !== []) : ?>
                    <h3><?php echo esc_html(sprintf(__('Row changes (%d, first 200 shown)', 'intersoccer-reports-rosters'), count($changes))); ?></h3>
                    <table class="widefat striped">
                        <thead><tr>
                            <th><?php esc_html_e('Row', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Order', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Product', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Season before', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Season after', 'intersoccer-reports-rosters'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach (array_slice($changes, 0, 200) as $c) : ?>
                            <tr>
                                <td><?php echo (int) ($c['id'] ?? 0); ?></td>
                                <td><?php echo (int) ($c['order_id'] ?? 0); ?></td>
                                <td><?php echo (int) ($c['product_id'] ?? 0); ?></td>
                                <td><?php echo esc_html((string) ($c['old_season'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($c['new_season'] ?? '')); ?></td>
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
