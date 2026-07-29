<?php
/**
 * Live Snapshot landing page — one-click presets for Summer camps and courses.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Live Summer camp at-risk rows (Critical always; fragile 8–9 within cutoff).
 * Full Day and Mini are judged separately via Final Reports aggregation.
 *
 * @param int|null $year Calendar year.
 * @return array<int,array<string,mixed>>
 */
function intersoccer_live_snapshot_at_risk_rows($year = null) {
	$year = $year === null ? (int) date('Y') : (int) $year;
	if (!function_exists('intersoccer_get_final_reports_data') || !function_exists('intersoccer_reports_camp_at_risk_rows')) {
		return [];
	}
	$data = intersoccer_get_final_reports_data([
		'year' => $year,
		'season_type' => 'Summer',
		'mode' => 'live',
	]);
	return intersoccer_reports_camp_at_risk_rows(is_array($data) ? $data : []);
}

/**
 * Render the Live Snapshot hub with preset links into Final Numbers (live mode).
 */
function intersoccer_render_live_snapshot_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $year = (int) date('Y');
    $at_risk = intersoccer_live_snapshot_at_risk_rows($year);
    $critical_n = count(array_filter($at_risk, static function ($r) {
        return ($r['reason'] ?? '') === 'critical';
    }));
    $fragile_n = count(array_filter($at_risk, static function ($r) {
        return ($r['reason'] ?? '') === 'fragile';
    }));
    $summer_camps_url = add_query_arg(
        [
            'page' => 'intersoccer-final-camp-reports',
            'year' => $year,
            'season_type' => 'Summer',
            'live' => 1,
        ],
        admin_url('admin.php')
    );
    $courses_url = add_query_arg(
        [
            'page' => 'intersoccer-final-course-reports',
            'year' => $year,
            'live' => 1,
        ],
        admin_url('admin.php')
    );
    $urgent_camps_url = add_query_arg(
        [
            'page' => 'intersoccer-final-camp-reports',
            'year' => $year,
            'season_type' => 'Summer',
            'live' => 1,
            'urgency_only' => 1,
        ],
        admin_url('admin.php')
    );
    $urgent_courses_url = add_query_arg(
        [
            'page' => 'intersoccer-final-course-reports',
            'year' => $year,
            'live' => 1,
            'urgency_only' => 1,
        ],
        admin_url('admin.php')
    );
    ?>
    <div class="wrap intersoccer-live-snapshot">
        <h1><?php esc_html_e('Live Snapshot', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php esc_html_e('One-click live enrollment figures for the office. Live mode counts completed and processing orders (not pending/on-hold). Final Camp and Course Reports color-code headcounts (Critical ≤7 · Low ≤20 · Good ≤29 · Optimal 30+) so marketing can spot underfilled programs. Pitchside tooling is out of scope here.', 'intersoccer-reports-rosters'); ?></p>

        <div style="display:flex; flex-wrap:wrap; gap:16px; margin-top:24px;">
            <div style="flex:1; min-width:260px; max-width:420px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e('Summer Camps — Live', 'intersoccer-reports-rosters'); ?></h2>
                <p><?php echo esc_html(sprintf(
                    /* translators: %d: calendar year */
                    __('Open Final Camp Numbers for %d Summer with full-week and individual day (Mon–Fri) splits.', 'intersoccer-reports-rosters'),
                    $year
                )); ?></p>
                <p>
                    <a class="button button-primary button-hero" href="<?php echo esc_url($summer_camps_url); ?>">
                        <?php esc_html_e('Open Summer Camps Live', 'intersoccer-reports-rosters'); ?>
                    </a>
                </p>
            </div>
            <div style="flex:1; min-width:260px; max-width:420px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e('Courses — Live', 'intersoccer-reports-rosters'); ?></h2>
                <p><?php echo esc_html(sprintf(
                    /* translators: %d: calendar year */
                    __('Open Final Course Numbers for %d with live enrollment counts.', 'intersoccer-reports-rosters'),
                    $year
                )); ?></p>
                <p>
                    <a class="button button-primary button-hero" href="<?php echo esc_url($courses_url); ?>">
                        <?php esc_html_e('Open Courses Live', 'intersoccer-reports-rosters'); ?>
                    </a>
                </p>
            </div>
            <div style="flex:1; min-width:200px; max-width:280px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e('At risk (Summer)', 'intersoccer-reports-rosters'); ?></h2>
                <p style="margin:0 0 8px;">
                    <strong style="font-size:1.6em;"><?php echo esc_html(number_format_i18n($critical_n)); ?></strong>
                    <?php esc_html_e('Critical', 'intersoccer-reports-rosters'); ?>
                </p>
                <p style="margin:0;">
                    <strong style="font-size:1.6em;"><?php echo esc_html(number_format_i18n($fragile_n)); ?></strong>
                    <?php esc_html_e('Fragile (8–9 ≤14d)', 'intersoccer-reports-rosters'); ?>
                </p>
            </div>
        </div>

        <div style="margin-top:24px; padding:16px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; max-width:860px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Marketing focus — Critical + Low only', 'intersoccer-reports-rosters'); ?></h2>
            <p><?php esc_html_e('Same Live Final Numbers views, filtered to programs whose peak headcount is Critical (≤7) or Low (≤20). Camp heatmaps use the max of each Total min–max cell.', 'intersoccer-reports-rosters'); ?></p>
            <p style="display:flex; flex-wrap:wrap; gap:8px;">
                <a class="button" href="<?php echo esc_url($urgent_camps_url); ?>">
                    <?php esc_html_e('Summer Camps — Critical + Low', 'intersoccer-reports-rosters'); ?>
                </a>
                <a class="button" href="<?php echo esc_url($urgent_courses_url); ?>">
                    <?php esc_html_e('Courses — Critical + Low', 'intersoccer-reports-rosters'); ?>
                </a>
            </p>
        </div>

        <div style="margin-top:24px; padding:16px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; max-width:960px;">
            <h2 style="margin-top:0;"><?php esc_html_e('Cancel / promote queue — Critical &amp; fragile', 'intersoccer-reports-rosters'); ?></h2>
            <p><?php esc_html_e('Critical (≤7) always; fragile (8–9) only when start is within 14 days. Full Day and Mini are judged separately — never merge for cancel decisions. Not auto-cancel.', 'intersoccer-reports-rosters'); ?></p>
            <?php if (!$at_risk) : ?>
                <p><em><?php esc_html_e('No Critical or fragile Summer camp sessions in the live snapshot.', 'intersoccer-reports-rosters'); ?></em></p>
            <?php else : ?>
                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Reason', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Week', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Location', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Venue', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Session', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Min–max', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php esc_html_e('Days left', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($at_risk, 0, 40) as $row) : ?>
                            <tr>
                                <td><?php echo esc_html((string) ($row['reason'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['week'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['location'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['venue'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['session_type'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['min_max'] ?? '')); ?></td>
                                <td><?php echo esc_html($row['days_left'] === null ? '—' : (string) (int) $row['days_left']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($at_risk) > 40) : ?>
                    <p><em><?php echo esc_html(sprintf(
                        /* translators: %d: total at-risk rows */
                        __('Showing 40 of %d rows. Open Summer Camps Live for the full grid.', 'intersoccer-reports-rosters'),
                        count($at_risk)
                    )); ?></em></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
