<?php
/**
 * Admin: event signature drift report and merge-by-signature tools.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

use InterSoccer\ReportsRosters\Services\SignatureDriftService;

/**
 * Ensure DB tables exist (dbDelta for new admin log table on upgrades).
 */
function intersoccer_signature_drift_ensure_schema(): void {
    if (function_exists('intersoccer_oop_get_database')) {
        try {
            intersoccer_oop_get_database()->create_tables();
        } catch (\Throwable $e) {
            // Non-fatal; page will show error if queries fail.
        }
    }
}

/**
 * Render Signature drift & maintenance admin page.
 */
function intersoccer_render_signature_drift_report_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'intersoccer-reports-rosters'));
    }

    intersoccer_signature_drift_ensure_schema();

    $service = new SignatureDriftService();

    if (!empty($_GET['export']) && $_GET['export'] === 'csv' && !empty($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'intersoccer_signature_drift_csv')) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=signature-drift-' . gmdate('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'order_id', 'order_item_id', 'activity_type', 'venue', 'season', 'girls_only', 'stored_signature', 'expected_signature', 'product_name']);
        $cursor = isset($_GET['cursor']) ? (int) $_GET['cursor'] : 0;
        $season = isset($_GET['season']) ? sanitize_text_field(wp_unslash($_GET['season'])) : '';
        $activity = isset($_GET['activity_type']) ? sanitize_text_field(wp_unslash($_GET['activity_type'])) : '';
        $girls = isset($_GET['girls_only']) ? sanitize_text_field(wp_unslash($_GET['girls_only'])) : '';
        $max_rows = 20000;
        $exported = 0;
        while ($exported < $max_rows) {
            $batch = $service->scanChunk([
                'season' => $season,
                'activity_type' => $activity,
                'girls_only' => $girls,
                'int_cursor' => $cursor,
                'chunk' => 500,
                'max_drifts' => 500,
            ]);
            if ((int) ($batch['scanned'] ?? 0) === 0) {
                break;
            }
            foreach ($batch['drifts'] as $row) {
                fputcsv($out, [
                    $row['id'] ?? '',
                    $row['order_id'] ?? '',
                    $row['order_item_id'] ?? '',
                    $row['activity_type'] ?? '',
                    $row['venue'] ?? '',
                    $row['season'] ?? '',
                    $row['girls_only'] ?? '',
                    $row['event_signature'] ?? '',
                    $row['expected_signature'] ?? '',
                    $row['product_name'] ?? '',
                ]);
                $exported++;
            }
            $cursor = (int) $batch['next_cursor'];
            if (!empty($batch['exhausted'])) {
                break;
            }
        }
        fclose($out);
        exit;
    }

    $season = isset($_GET['season']) ? sanitize_text_field(wp_unslash($_GET['season'])) : '';
    $activity_type = isset($_GET['activity_type']) ? sanitize_text_field(wp_unslash($_GET['activity_type'])) : '';
    $girls_only = isset($_GET['girls_only']) ? sanitize_text_field(wp_unslash($_GET['girls_only'])) : '';
    $cursor = isset($_GET['cursor']) ? max(0, (int) $_GET['cursor']) : 0;
    $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'drift';

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $seasons = $wpdb->get_col("SELECT DISTINCT season FROM {$rosters_table} WHERE season IS NOT NULL AND season != '' ORDER BY season DESC LIMIT 100");

    $scan = ['drifts' => [], 'next_cursor' => $cursor, 'scanned' => 0, 'exhausted' => true];
    if ($view === 'drift') {
        $scan = $service->scanChunk([
            'season' => $season,
            'activity_type' => $activity_type,
            'girls_only' => $girls_only,
            'int_cursor' => $cursor,
            'chunk' => 800,
            'max_drifts' => 100,
        ]);
    }

    $hints = $view === 'splits' ? $service->getSplitGroupHints(150) : [];

    $admin_notice = get_transient('intersoccer_admin_roster_notice');
    if (is_string($admin_notice) && $admin_notice !== '') {
        delete_transient('intersoccer_admin_roster_notice');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($admin_notice) . '</p></div>';
    }

    $base_url = admin_url('admin.php?page=intersoccer-signature-drift');
    $csv_nonce = wp_create_nonce('intersoccer_signature_drift_csv');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Signature drift & roster maintenance', 'intersoccer-reports-rosters'); ?></h1>
        <p class="description">
            <?php esc_html_e('Drift compares each roster row’s stored event_signature to the value produced today by the canonical generator (same rules as signature rebuild). Split groups lists product/variation combinations that currently have more than one distinct signature.', 'intersoccer-reports-rosters'); ?>
        </p>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url(add_query_arg(['view' => 'drift', 'cursor' => 0], $base_url)); ?>" class="nav-tab <?php echo $view === 'drift' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Drift scan', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo esc_url(add_query_arg(['view' => 'splits'], $base_url)); ?>" class="nav-tab <?php echo $view === 'splits' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Split groups', 'intersoccer-reports-rosters'); ?></a>
        </h2>

        <?php if ($view === 'drift') : ?>
            <form method="get" action="" style="margin: 15px 0;">
                <input type="hidden" name="page" value="intersoccer-signature-drift">
                <input type="hidden" name="view" value="drift">
                <input type="hidden" name="cursor" value="0">
                <label><?php esc_html_e('Season', 'intersoccer-reports-rosters'); ?>
                    <select name="season">
                        <option value=""><?php esc_html_e('Any', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($seasons as $s) : ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($season, $s); ?>><?php echo esc_html($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?php esc_html_e('Activity type', 'intersoccer-reports-rosters'); ?>
                    <select name="activity_type">
                        <option value=""><?php esc_html_e('Any', 'intersoccer-reports-rosters'); ?></option>
                        <option value="Camp" <?php selected($activity_type, 'Camp'); ?>>Camp</option>
                        <option value="Course" <?php selected($activity_type, 'Course'); ?>>Course</option>
                        <option value="Tournament" <?php selected($activity_type, 'Tournament'); ?>>Tournament</option>
                    </select>
                </label>
                <label><?php esc_html_e('Girls only', 'intersoccer-reports-rosters'); ?>
                    <select name="girls_only">
                        <option value=""><?php esc_html_e('Any', 'intersoccer-reports-rosters'); ?></option>
                        <option value="1" <?php selected($girls_only, '1'); ?>><?php esc_html_e('Yes', 'intersoccer-reports-rosters'); ?></option>
                        <option value="0" <?php selected($girls_only, '0'); ?>><?php esc_html_e('No', 'intersoccer-reports-rosters'); ?></option>
                    </select>
                </label>
                <?php submit_button(__('Apply filters', 'intersoccer-reports-rosters'), 'secondary', 'submit', false); ?>
            </form>

            <p>
                <?php
                printf(
                    /* translators: 1: number of rows scanned in this batch */
                    esc_html__('This batch scanned up to 800 rows starting after ID %1$d. Drift rows shown below (max 100).', 'intersoccer-reports-rosters'),
                    (int) $cursor
                );
                ?>
            </p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['export' => 'csv', 'season' => $season, 'activity_type' => $activity_type, 'girls_only' => $girls_only, 'cursor' => 0], $base_url), 'intersoccer_signature_drift_csv', '_wpnonce')); ?>">
                    <?php esc_html_e('Export drift CSV (capped)', 'intersoccer-reports-rosters'); ?>
                </a>
                <?php if (empty($scan['exhausted']) || !empty($scan['drifts']) || $cursor > 0) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['view' => 'drift', 'season' => $season, 'activity_type' => $activity_type, 'girls_only' => $girls_only, 'cursor' => (int) $scan['next_cursor']], $base_url)); ?>">
                        <?php esc_html_e('Scan next chunk', 'intersoccer-reports-rosters'); ?>
                    </a>
                <?php endif; ?>
            </p>

            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-advanced-tools')); ?>">
                    <?php esc_html_e('Rebuild selected and merge signatures are on Advanced tools.', 'intersoccer-reports-rosters'); ?>
                </a>
            </p>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Order', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Item', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Activity', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Venue', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Stored', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Expected', 'intersoccer-reports-rosters'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($scan['drifts'])) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No drift rows in this chunk (or end of table).', 'intersoccer-reports-rosters'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($scan['drifts'] as $row) : ?>
                            <tr>
                                <td><?php echo esc_html((string) ($row['id'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['order_id'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['order_item_id'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['activity_type'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['venue'] ?? '')); ?></td>
                                <td><code><?php echo esc_html((string) ($row['event_signature'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($row['expected_signature'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($view === 'splits') : ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Product ID', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Variation ID', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Season', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Activity', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Girls only', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('# Signatures', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Signatures', 'intersoccer-reports-rosters'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($hints)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No split groups found (same product/variation/season/activity/girls_only with multiple signatures).', 'intersoccer-reports-rosters'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($hints as $h) : ?>
                            <tr>
                                <td><?php echo esc_html((string) ($h['product_id'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($h['variation_id'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($h['season'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($h['activity_type'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($h['girls_only'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($h['signature_count'] ?? '')); ?></td>
                                <td><code style="word-break:break-all;"><?php echo esc_html((string) ($h['signatures'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-advanced-tools')); ?>">
                    <?php esc_html_e('Merge signatures runs from Advanced tools.', 'intersoccer-reports-rosters'); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
    <?php
}
