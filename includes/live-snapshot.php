<?php
/**
 * Live Snapshot landing page — one-click presets for Summer camps and courses.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render the Live Snapshot hub with preset links into Final Numbers (live mode).
 */
function intersoccer_render_live_snapshot_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $year = (int) date('Y');
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
    </div>
    <?php
}
