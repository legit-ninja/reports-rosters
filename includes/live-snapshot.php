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
    $underfilled_url = add_query_arg(
        ['page' => 'intersoccer-underfilled-programs'],
        admin_url('admin.php')
    );
    ?>
    <div class="wrap intersoccer-live-snapshot">
        <h1><?php esc_html_e('Live Snapshot', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php esc_html_e('One-click live enrollment figures for the office. Live mode counts completed and processing orders (not pending/on-hold). Pitchside tooling is out of scope here.', 'intersoccer-reports-rosters'); ?></p>

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

        <p style="margin-top:24px;">
            <a class="button" href="<?php echo esc_url($underfilled_url); ?>">
                <?php esc_html_e('View underfilled programs', 'intersoccer-reports-rosters'); ?>
            </a>
        </p>
    </div>
    <?php
}
