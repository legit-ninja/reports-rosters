<?php
/**
 * Birthdays calendar admin page.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Build a privacy-safe display name for calendar entries.
 */
function intersoccer_birthdays_display_name(array $row): string {
    $first_name = trim((string) ($row['first_name'] ?? ''));
    $last_name = trim((string) ($row['last_name'] ?? ''));
    $player_name = trim((string) ($row['player_name'] ?? ''));

    if ($first_name !== '') {
        $last_initial = $last_name !== '' ? strtoupper(substr($last_name, 0, 1)) . '.' : '';
        return trim($first_name . ' ' . $last_initial);
    }

    if ($player_name !== '') {
        $parts = preg_split('/\s+/', $player_name);
        if (is_array($parts) && !empty($parts)) {
            $fallback_first = trim((string) ($parts[0] ?? ''));
            $fallback_last = trim((string) ($parts[count($parts) - 1] ?? ''));
            if ($fallback_first !== '') {
                $fallback_initial = $fallback_last !== '' && strcasecmp($fallback_first, $fallback_last) !== 0
                    ? strtoupper(substr($fallback_last, 0, 1)) . '.'
                    : '';
                return trim($fallback_first . ' ' . $fallback_initial);
            }
        }
    }

    return __('Unknown player', 'intersoccer-reports-rosters');
}

/**
 * Query and dedupe birthdays from roster data, enriched with birthday-party flag and user ID.
 *
 * @return array<int,array<string,mixed>>
 */
function intersoccer_birthdays_get_entries(): array {
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // MySQL strict mode rejects comparing DATE columns against empty string.
    $where = "WHERE dob IS NOT NULL AND dob <> '0000-00-00'";
    $query_args = [];

    $current_user = wp_get_current_user();
    $is_coach = in_array('coach', (array) $current_user->roles, true);
    $coach_accessible_venues = [];

    if ($is_coach) {
        if (!class_exists('InterSoccer_Admin_Coach_Assignments')) {
            require_once WP_PLUGIN_DIR . '/customer-referral-system/includes/class-admin-coach-assignments.php';
        }
        $coach_accessible_venues = InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues($current_user->ID);

        if (!empty($coach_accessible_venues)) {
            $placeholders = implode(',', array_fill(0, count($coach_accessible_venues), '%s'));
            $where .= " AND venue IN ($placeholders)";
            $query_args = array_values($coach_accessible_venues);
        } else {
            return [];
        }
    }

    $sql = "SELECT first_name, last_name, player_name, dob, venue, age_group, parent_email
            FROM {$rosters_table}
            {$where}";

    if (!empty($query_args)) {
        $sql = $wpdb->prepare($sql, $query_args);
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $deduped = [];
    foreach ($rows as $row) {
        $dob_raw = isset($row['dob']) ? (string) $row['dob'] : '';
        $timestamp = strtotime($dob_raw);
        if (!$timestamp) {
            continue;
        }

        $display_name = intersoccer_birthdays_display_name($row);
        $dedupe_key = strtolower($display_name . '|' . gmdate('m-d', $timestamp));
        if (isset($deduped[$dedupe_key])) {
            continue;
        }

        $deduped[$dedupe_key] = [
            'display_name' => $display_name,
            'dob'          => gmdate('Y-m-d', $timestamp),
            'month'        => (int) gmdate('n', $timestamp),
            'day'          => (int) gmdate('j', $timestamp),
            'venue'        => (string) ($row['venue'] ?? ''),
            'age_group'    => (string) ($row['age_group'] ?? ''),
            'parent_email' => (string) ($row['parent_email'] ?? ''),
            'first_name'   => trim((string) ($row['first_name'] ?? '')),
            'last_name'    => trim((string) ($row['last_name'] ?? '')),
        ];
    }

    $entries = array_values($deduped);

    // Check which players have a Birthday Party booking this year.
    $current_year = (int) gmdate('Y');

    if ($is_coach && !empty($coach_accessible_venues)) {
        $placeholders = implode(',', array_fill(0, count($coach_accessible_venues), '%s'));
        $bp_sql = $wpdb->prepare(
            "SELECT DISTINCT first_name, last_name FROM {$rosters_table}
             WHERE activity_type = 'Birthday Party' AND YEAR(start_date) = %d
             AND venue IN ($placeholders)",
            array_merge([$current_year], array_values($coach_accessible_venues))
        );
    } else {
        $bp_sql = $wpdb->prepare(
            "SELECT DISTINCT first_name, last_name FROM {$rosters_table}
             WHERE activity_type = 'Birthday Party' AND YEAR(start_date) = %d",
            $current_year
        );
    }

    $bp_rows = $wpdb->get_results($bp_sql, ARRAY_A);
    $birthday_party_lookup = [];
    foreach ((array) $bp_rows as $bp_row) {
        $key = strtolower(trim((string) ($bp_row['first_name'] ?? '')) . '|' . trim((string) ($bp_row['last_name'] ?? '')));
        $birthday_party_lookup[$key] = true;
    }

    // Batch-lookup WordPress user IDs by parent billing email.
    $emails = array_unique(array_filter(array_column($entries, 'parent_email')));
    $user_id_map = [];
    if (!empty($emails)) {
        $placeholders = implode(',', array_fill(0, count($emails), '%s'));
        $user_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT ID, user_email FROM {$wpdb->users} WHERE user_email IN ($placeholders)", $emails),
            ARRAY_A
        );
        foreach ((array) $user_rows as $ur) {
            $user_id_map[strtolower((string) $ur['user_email'])] = (int) $ur['ID'];
        }
    }

    // Enrich entries with birthday-party flag and user ID.
    foreach ($entries as &$entry) {
        $first = $entry['first_name'];
        $last  = $entry['last_name'];
        if ($first !== '' || $last !== '') {
            $name_key = strtolower($first . '|' . $last);
            $entry['has_birthday_party'] = isset($birthday_party_lookup[$name_key]);
        } else {
            $entry['has_birthday_party'] = false;
        }
        $entry['user_id'] = $user_id_map[strtolower($entry['parent_email'])] ?? 0;
    }
    unset($entry);

    return $entries;
}

/**
 * Export handler called via admin_post_intersoccer_birthdays_export.
 */
function intersoccer_birthdays_export_handler(): void {
    if (!check_admin_referer('intersoccer_birthdays_export_nonce', '_nonce_birthdays_export')) {
        wp_die(__('Security check failed.', 'intersoccer-reports-rosters'));
    }

    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    $view = isset($_POST['view']) ? sanitize_key((string) wp_unslash($_POST['view'])) : 'month';
    if (!in_array($view, ['month', 'year'], true)) {
        $view = 'month';
    }

    $current_year  = (int) gmdate('Y');
    $current_month = (int) gmdate('n');

    $year = isset($_POST['year']) ? (int) $_POST['year'] : $current_year;
    if ($year < 2000 || $year > 2100) {
        $year = $current_year;
    }

    $month = isset($_POST['month']) ? (int) $_POST['month'] : $current_month;
    if ($month < 1 || $month > 12) {
        $month = $current_month;
    }

    $entries = intersoccer_birthdays_get_entries();

    // Filter to selected month when in month view.
    if ($view === 'month') {
        $entries = array_values(array_filter($entries, function ($e) use ($month) {
            return (int) $e['month'] === $month;
        }));
    }

    // Sort by month then day.
    usort($entries, function ($a, $b) {
        if ($a['month'] !== $b['month']) {
            return $a['month'] - $b['month'];
        }
        return $a['day'] - $b['day'];
    });

    $autoloader = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($autoloader)) {
        wp_die(__('Composer autoloader not found. Cannot generate Excel file.', 'intersoccer-reports-rosters'));
    }
    require_once $autoloader;

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $title = $view === 'month'
        ? date_i18n('F Y', mktime(0, 0, 0, $month, 1, $year))
        : (string) $year;
    $sheet->setTitle(mb_substr($title, 0, 31));

    $headers = [
        __('Name', 'intersoccer-reports-rosters'),
        __('Birthday', 'intersoccer-reports-rosters'),
        __('Venue', 'intersoccer-reports-rosters'),
        __('Age Group', 'intersoccer-reports-rosters'),
        __('Birthday Party Booked This Year', 'intersoccer-reports-rosters'),
    ];

    $col = 1;
    foreach ($headers as $header) {
        $sheet->setCellValueByColumnAndRow($col, 1, $header);
        $col++;
    }

    // Style header row bold with grey background.
    $last_col     = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $header_style = $sheet->getStyle('A1:' . $last_col . '1');
    $header_style->getFont()->setBold(true);
    $header_style->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FFE0E0E0');

    $row_num = 2;
    foreach ($entries as $entry) {
        $birthday  = date_i18n('F j', mktime(0, 0, 0, (int) $entry['month'], (int) $entry['day']));
        $has_party = !empty($entry['has_birthday_party']);

        $sheet->setCellValueByColumnAndRow(1, $row_num, (string) $entry['display_name']);
        $sheet->setCellValueByColumnAndRow(2, $row_num, $birthday);
        $sheet->setCellValueByColumnAndRow(3, $row_num, (string) $entry['venue']);
        $sheet->setCellValueByColumnAndRow(4, $row_num, (string) $entry['age_group']);
        $sheet->setCellValueByColumnAndRow(5, $row_num, $has_party ? __('Yes', 'intersoccer-reports-rosters') : __('No', 'intersoccer-reports-rosters'));

        // Light green for booked, light red for not booked.
        $bg_argb = $has_party ? 'FFD4EDDA' : 'FFFDE8E8';
        $sheet->getStyle('A' . $row_num . ':E' . $row_num)
            ->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB($bg_argb);

        $row_num++;
    }

    // Auto-size columns.
    for ($i = 1; $i <= count($headers); $i++) {
        $col_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($col_letter)->setAutoSize(true);
    }

    $view_slug = $view === 'month'
        ? gmdate('Y-m', mktime(0, 0, 0, $month, 1, $year))
        : (string) $year;
    $filename = 'birthdays-' . $view_slug . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * Return HTML for a birthday name entry: colour-coded span with optional profile link.
 */
function intersoccer_birthdays_name_html(array $entry): string {
    $name      = esc_html((string) $entry['display_name']);
    $has_party = !empty($entry['has_birthday_party']);
    $css_class = $has_party ? 'isrr-birthdays-name--has-party' : 'isrr-birthdays-name--no-party';
    $user_id   = (int) ($entry['user_id'] ?? 0);

    if ($user_id > 0) {
        $url = get_edit_user_link($user_id);
        return '<span class="' . esc_attr($css_class) . '"><a href="' . esc_url($url) . '">' . $name . '</a></span>';
    }

    return '<span class="' . esc_attr($css_class) . '">' . $name . '</span>';
}

/**
 * Render month calendar grid.
 *
 * @param array<int,array<string,mixed>> $entries
 */
function intersoccer_render_birthdays_month_grid(array $entries, int $year, int $month): void {
    $first_day_ts  = mktime(0, 0, 0, $month, 1, $year);
    $days_in_month = (int) date('t', $first_day_ts);
    $start_weekday = (int) date('N', $first_day_ts); // 1 = Monday

    $days_index = [];
    foreach ($entries as $entry) {
        if ((int) $entry['month'] !== $month) {
            continue;
        }
        $day = (int) $entry['day'];
        if (!isset($days_index[$day])) {
            $days_index[$day] = [];
        }
        $days_index[$day][] = $entry;
    }

    $weekdays = [
        __('Mon', 'intersoccer-reports-rosters'),
        __('Tue', 'intersoccer-reports-rosters'),
        __('Wed', 'intersoccer-reports-rosters'),
        __('Thu', 'intersoccer-reports-rosters'),
        __('Fri', 'intersoccer-reports-rosters'),
        __('Sat', 'intersoccer-reports-rosters'),
        __('Sun', 'intersoccer-reports-rosters'),
    ];
    ?>
    <div class="isrr-birthdays-month-grid">
        <?php foreach ($weekdays as $weekday): ?>
            <div class="isrr-birthdays-weekday"><?php echo esc_html($weekday); ?></div>
        <?php endforeach; ?>

        <?php for ($blank = 1; $blank < $start_weekday; $blank++): ?>
            <div class="isrr-birthdays-day isrr-birthdays-day--empty"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
            <div class="isrr-birthdays-day">
                <div class="isrr-birthdays-day-number"><?php echo esc_html((string) $day); ?></div>
                <?php if (!empty($days_index[$day])): ?>
                    <ul class="isrr-birthdays-list">
                        <?php foreach ($days_index[$day] as $entry): ?>
                            <li class="isrr-birthdays-item"><?php echo intersoccer_birthdays_name_html($entry); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
    <?php
}

/**
 * Render year summary view.
 *
 * @param array<int,array<string,mixed>> $entries
 */
function intersoccer_render_birthdays_year_grid(array $entries): void {
    $months = [];
    for ($month = 1; $month <= 12; $month++) {
        $months[$month] = [];
    }

    foreach ($entries as $entry) {
        $month = (int) $entry['month'];
        $day   = (int) $entry['day'];
        if (!isset($months[$month][$day])) {
            $months[$month][$day] = [];
        }
        $months[$month][$day][] = $entry;
    }
    ?>
    <div class="isrr-birthdays-year-grid">
        <?php for ($month = 1; $month <= 12; $month++): ?>
            <section class="isrr-birthdays-year-card">
                <h3><?php echo esc_html(date_i18n('F', mktime(0, 0, 0, $month, 1))); ?></h3>
                <?php if (empty($months[$month])): ?>
                    <p class="isrr-birthdays-empty-month"><?php esc_html_e('No birthdays', 'intersoccer-reports-rosters'); ?></p>
                <?php else: ?>
                    <ul class="isrr-birthdays-year-list">
                        <?php foreach ($months[$month] as $day => $day_entries): ?>
                            <li>
                                <strong><?php echo esc_html((string) $day); ?></strong>
                                <?php
                                $name_parts = [];
                                foreach ($day_entries as $day_entry) {
                                    $name_parts[] = intersoccer_birthdays_name_html($day_entry);
                                }
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside name_html
                                echo '<span>' . implode(', ', $name_parts) . '</span>';
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endfor; ?>
    </div>
    <?php
}

/**
 * Render Birthdays page.
 */
function intersoccer_render_birthdays_page(): void {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    if (function_exists('intersoccer_rosters_bootstrap_saved_list_filters')) {
        // Keep time navigation sticky, but do not persist view mode so Month remains the default landing view.
        intersoccer_rosters_bootstrap_saved_list_filters('birthdays', ['year', 'month'], false);
    }

    $view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : 'month';
    if (!in_array($view, ['month', 'year'], true)) {
        $view = 'month';
    }

    $current_year = (int) gmdate('Y');
    $year = isset($_GET['year']) ? (int) $_GET['year'] : $current_year;
    if ($year < 2000 || $year > 2100) {
        $year = $current_year;
    }

    $current_month = (int) gmdate('n');
    $month = isset($_GET['month']) ? (int) $_GET['month'] : $current_month;
    if ($month < 1 || $month > 12) {
        $month = $current_month;
    }

    $entries = intersoccer_birthdays_get_entries();

    $prev_year  = $year;
    $prev_month = $month;
    $next_year  = $year;
    $next_month = $month;

    if ($view === 'month') {
        $prev_month--;
        if ($prev_month < 1) {
            $prev_month = 12;
            $prev_year--;
        }

        $next_month++;
        if ($next_month > 12) {
            $next_month = 1;
            $next_year++;
        }
    } else {
        $prev_year = $year - 1;
        $next_year = $year + 1;
    }

    $base_params      = ['page' => 'intersoccer-birthdays', 'view' => $view];
    $prev_url_params  = $base_params;
    $next_url_params  = $base_params;
    $current_url_params = $base_params;

    if ($view === 'month') {
        $prev_url_params['year']    = $prev_year;
        $prev_url_params['month']   = $prev_month;
        $next_url_params['year']    = $next_year;
        $next_url_params['month']   = $next_month;
        $current_url_params['year'] = $current_year;
        $current_url_params['month'] = $current_month;
    } else {
        $prev_url_params['year']    = $prev_year;
        $next_url_params['year']    = $next_year;
        $current_url_params['year'] = $current_year;
    }

    $month_view_url = add_query_arg(
        ['page' => 'intersoccer-birthdays', 'view' => 'month', 'year' => $year, 'month' => $month],
        admin_url('admin.php')
    );
    $year_view_url = add_query_arg(
        ['page' => 'intersoccer-birthdays', 'view' => 'year', 'year' => $year],
        admin_url('admin.php')
    );
    ?>
    <div class="wrap intersoccer-rosters-page isrr-birthdays-page">
        <div class="roster-header">
            <h1>🎂 <?php esc_html_e('Birthdays Calendar', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="intersoccer_birthdays_export">
                    <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
                    <input type="hidden" name="year" value="<?php echo esc_attr((string) $year); ?>">
                    <input type="hidden" name="month" value="<?php echo esc_attr((string) $month); ?>">
                    <?php wp_nonce_field('intersoccer_birthdays_export_nonce', '_nonce_birthdays_export'); ?>
                    <button type="submit" class="button button-secondary isrr-birthdays-export-btn">
                        <span class="dashicons dashicons-download" aria-hidden="true"></span>
                        <?php esc_html_e('Export to Excel', 'intersoccer-reports-rosters'); ?>
                    </button>
                </form>
                <a href="<?php echo esc_url(add_query_arg($prev_url_params, admin_url('admin.php'))); ?>" class="button button-secondary">
                    <?php esc_html_e('Previous', 'intersoccer-reports-rosters'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg($current_url_params, admin_url('admin.php'))); ?>" class="button button-secondary">
                    <?php echo esc_html($view === 'month' ? __('Current Month', 'intersoccer-reports-rosters') : __('Current Year', 'intersoccer-reports-rosters')); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg($next_url_params, admin_url('admin.php'))); ?>" class="button button-secondary">
                    <?php esc_html_e('Next', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>

        <div class="isrr-birthdays-toolbar">
            <div class="isrr-birthdays-view-toggle">
                <a class="button <?php echo esc_attr($view === 'month' ? 'button-primary' : 'button-secondary'); ?>" href="<?php echo esc_url($month_view_url); ?>">
                    <?php esc_html_e('Month View', 'intersoccer-reports-rosters'); ?>
                </a>
                <a class="button <?php echo esc_attr($view === 'year' ? 'button-primary' : 'button-secondary'); ?>" href="<?php echo esc_url($year_view_url); ?>">
                    <?php esc_html_e('Year View', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
            <div class="isrr-birthdays-title">
                <?php if ($view === 'month'): ?>
                    <h2><?php echo esc_html(date_i18n('F Y', mktime(0, 0, 0, $month, 1, $year))); ?></h2>
                <?php else: ?>
                    <h2><?php echo esc_html((string) $year); ?></h2>
                <?php endif; ?>
            </div>
            <div class="isrr-birthdays-legend">
                <span class="isrr-birthdays-legend-item isrr-birthdays-legend-item--has-party">
                    <?php esc_html_e('Party booked', 'intersoccer-reports-rosters'); ?>
                </span>
                <span class="isrr-birthdays-legend-item isrr-birthdays-legend-item--no-party">
                    <?php esc_html_e('No party booked', 'intersoccer-reports-rosters'); ?>
                </span>
            </div>
        </div>

        <?php if (empty($entries)): ?>
            <div class="no-rosters">
                <div class="no-rosters-icon">🎂</div>
                <h3><?php esc_html_e('No birthdays found', 'intersoccer-reports-rosters'); ?></h3>
                <p><?php esc_html_e('Birthday data will appear here when players with DOB values exist in rosters.', 'intersoccer-reports-rosters'); ?></p>
            </div>
        <?php elseif ($view === 'month'): ?>
            <?php intersoccer_render_birthdays_month_grid($entries, $year, $month); ?>
        <?php else: ?>
            <?php intersoccer_render_birthdays_year_grid($entries); ?>
        <?php endif; ?>
    </div>
    <?php
}
