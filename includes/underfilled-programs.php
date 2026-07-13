<?php
/**
 * Underfilled programs ranking for marketing urgency.
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Resolve urgency band; prefers shared roster helper when loaded.
 *
 * @param int $count Participant count.
 * @return string
 */
function intersoccer_underfilled_resolve_count_class($count) {
    if (function_exists('intersoccer_get_count_class')) {
        return intersoccer_get_count_class($count);
    }
    $count = (int) $count;
    if ($count <= 7) {
        return 'count-critical';
    }
    if ($count <= 20) {
        return 'count-low';
    }
    if ($count <= 29) {
        return 'count-good';
    }
    return 'count-optimal';
}

/**
 * Build ranked underfilled rows from roster listing groups.
 *
 * @param array  $groups   Listing groups with total_players.
 * @param string $activity Camp or Course.
 * @param string $year     Calendar year filter (matched against season string).
 * @param string $season_type Optional season type for camps (e.g. Summer). Empty = all.
 * @return array<int,array<string,mixed>>
 */
function intersoccer_underfilled_build_rows(array $groups, $activity, $year, $season_type = '') {
    $year = (string) $year;
    $season_type = (string) $season_type;
    $rows = [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $season = (string) ($group['season'] ?? '');
        if ($year !== '' && $season !== '' && strpos($season, $year) === false) {
            continue;
        }
        if ($season_type !== '' && function_exists('intersoccer_extract_season_type')) {
            $extracted = intersoccer_extract_season_type($season);
            if ($extracted !== $season_type && stripos($season, $season_type) === false) {
                continue;
            }
        } elseif ($season_type !== '' && stripos($season, $season_type) === false) {
            continue;
        }

        $count = isset($group['total_players']) ? (int) $group['total_players'] : 0;
        $band = intersoccer_underfilled_resolve_count_class($count);

        $rows[] = [
            'activity' => $activity,
            'product_name' => (string) ($group['product_name'] ?? ''),
            'venue' => (string) ($group['venue'] ?? ''),
            'season' => $season,
            'camp_terms' => (string) ($group['camp_terms'] ?? ''),
            'course_day' => (string) ($group['course_day'] ?? ''),
            'age_group' => (string) ($group['age_group'] ?? ''),
            'total_players' => $count,
            'band' => $band,
            'group' => $group,
        ];
    }

    usort($rows, function ($a, $b) {
        $band_rank = [
            'count-critical' => 0,
            'count-low' => 1,
            'count-good' => 2,
            'count-optimal' => 3,
        ];
        $ra = $band_rank[$a['band']] ?? 9;
        $rb = $band_rank[$b['band']] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return $a['total_players'] <=> $b['total_players'];
    });

    return $rows;
}

/**
 * Human label for urgency band.
 *
 * @param string $band CSS class from intersoccer_get_count_class().
 * @return string
 */
function intersoccer_underfilled_band_label($band) {
    $map = [
        'count-critical' => __('Critical (≤7)', 'intersoccer-reports-rosters'),
        'count-low' => __('Low (≤20)', 'intersoccer-reports-rosters'),
        'count-good' => __('Good (≤29)', 'intersoccer-reports-rosters'),
        'count-optimal' => __('Optimal (30+)', 'intersoccer-reports-rosters'),
    ];
    return $map[$band] ?? $band;
}

/**
 * Flat export row matching the underfilled programs table columns.
 *
 * @param array $row Underfilled row from intersoccer_underfilled_build_rows().
 * @return array{0:string,1:string,2:string,3:string,4:string,5:string,6:int,7:string}
 */
function intersoccer_underfilled_export_row(array $row) {
    $term_day = ($row['activity'] ?? '') === 'Camp'
        ? (string) ($row['camp_terms'] ?? '')
        : (string) ($row['course_day'] ?? '');

    return [
        (string) ($row['activity'] ?? ''),
        (string) ($row['product_name'] ?? ''),
        (string) ($row['venue'] ?? ''),
        (string) ($row['season'] ?? ''),
        $term_day,
        (string) ($row['age_group'] ?? ''),
        (int) ($row['total_players'] ?? 0),
        intersoccer_underfilled_band_label($row['band'] ?? ''),
    ];
}

/**
 * Resolve underfilled programs filter args from the current request.
 *
 * @return array{year:int,activity:string,season_type:string,urgency_only:bool}
 */
function intersoccer_underfilled_request_filters() {
    $year = isset($_GET['year']) ? absint($_GET['year']) : (int) date('Y');
    $activity_filter = isset($_GET['activity']) ? sanitize_text_field(wp_unslash($_GET['activity'])) : 'both';
    if (!in_array($activity_filter, ['both', 'camp', 'course'], true)) {
        $activity_filter = 'both';
    }
    $season_type = isset($_GET['season_type']) ? sanitize_text_field(wp_unslash($_GET['season_type'])) : 'Summer';
    $has_filters = isset($_GET['year']) || isset($_GET['activity']) || isset($_GET['season_type']);
    $urgency_only = $has_filters ? !empty($_GET['urgency_only']) : true;

    return [
        'year' => $year,
        'activity' => $activity_filter,
        'season_type' => $season_type,
        'urgency_only' => $urgency_only,
    ];
}

/**
 * Collect ranked underfilled rows for the given filters.
 *
 * @param int    $year
 * @param string $activity_filter both|camp|course
 * @param string $season_type
 * @param bool   $urgency_only
 * @return array<int,array<string,mixed>>
 */
function intersoccer_underfilled_collect_rows($year, $activity_filter, $season_type, $urgency_only) {
    $rows = [];
    if (function_exists('intersoccer_oop_get_roster_listing_service')) {
        $service = intersoccer_oop_get_roster_listing_service();
        if ($activity_filter === 'both' || $activity_filter === 'camp') {
            $camp_data = $service->getCampListings(['status' => ''], [], false, true);
            $camp_rows = intersoccer_underfilled_build_rows(
                $camp_data['display_groups'] ?? [],
                'Camp',
                (string) $year,
                $season_type
            );
            $rows = array_merge($rows, $camp_rows);
        }
        if ($activity_filter === 'both' || $activity_filter === 'course') {
            $course_data = $service->getCourseListings(['status' => ''], [], false, true);
            $course_rows = intersoccer_underfilled_build_rows(
                $course_data['display_groups'] ?? [],
                'Course',
                (string) $year,
                '' // courses: year only
            );
            $rows = array_merge($rows, $course_rows);
        }
        usort($rows, function ($a, $b) {
            $band_rank = [
                'count-critical' => 0,
                'count-low' => 1,
                'count-good' => 2,
                'count-optimal' => 3,
            ];
            $ra = $band_rank[$a['band']] ?? 9;
            $rb = $band_rank[$b['band']] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return $a['total_players'] <=> $b['total_players'];
        });
    }

    if ($urgency_only) {
        $rows = array_values(array_filter($rows, function ($row) {
            return in_array($row['band'], ['count-critical', 'count-low'], true);
        }));
    }

    return $rows;
}

/**
 * Stream underfilled programs as an Excel .xlsx download and exit.
 *
 * Must run before admin-header.php output (admin_init / load-{$page}), or HTML
 * will be prepended to the binary and Excel will show garbage.
 *
 * @param array  $rows Ranked underfilled rows.
 * @param int    $year Calendar year used in the filename.
 * @return void
 */
function intersoccer_underfilled_stream_excel(array $rows, $year) {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        wp_die(esc_html__('Excel export is unavailable (PhpSpreadsheet missing).', 'intersoccer-reports-rosters'));
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Underfilled');

    $headers = [
        'Activity',
        'Product',
        'Venue',
        'Season',
        'Term/Day',
        'Age Group',
        'Players',
        'Urgency',
    ];
    $sheet->fromArray($headers, null, 'A1');
    $sheet->getStyle('A1:H1')->getFont()->setBold(true);

    $row_index = 2;
    foreach ($rows as $row) {
        $sheet->fromArray(intersoccer_underfilled_export_row($row), null, 'A' . $row_index);
        $row_index++;
    }

    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename = 'underfilled-programs-' . (int) $year . '.xlsx';
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * Export Excel on admin_init / load-{$page} before admin-header HTML is printed.
 *
 * @return void
 */
function intersoccer_underfilled_maybe_export_excel() {
    if (empty($_GET['export_excel'])) {
        return;
    }
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    if ($page !== 'intersoccer-underfilled-programs') {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }
    check_admin_referer('intersoccer_underfilled_excel');

    $filters = intersoccer_underfilled_request_filters();
    $rows = intersoccer_underfilled_collect_rows(
        $filters['year'],
        $filters['activity'],
        $filters['season_type'],
        $filters['urgency_only']
    );

    intersoccer_underfilled_stream_excel($rows, $filters['year']);
}

/**
 * Render underfilled programs admin page.
 */
function intersoccer_render_underfilled_programs_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $filters = intersoccer_underfilled_request_filters();
    $year = $filters['year'];
    $activity_filter = $filters['activity'];
    $season_type = $filters['season_type'];
    $urgency_only = $filters['urgency_only'];
    $rows = intersoccer_underfilled_collect_rows($year, $activity_filter, $season_type, $urgency_only);

    $excel_url = wp_nonce_url(
        add_query_arg(
            [
                'page' => 'intersoccer-underfilled-programs',
                'year' => $year,
                'activity' => $activity_filter,
                'season_type' => $season_type,
                'urgency_only' => $urgency_only ? 1 : 0,
                'export_excel' => 1,
            ],
            admin_url('admin.php')
        ),
        'intersoccer_underfilled_excel'
    );

    $live_camps_url = add_query_arg(
        [
            'page' => 'intersoccer-final-camp-reports',
            'year' => $year,
            'season_type' => $season_type ?: 'Summer',
            'live' => 1,
        ],
        admin_url('admin.php')
    );
    $live_courses_url = add_query_arg(
        [
            'page' => 'intersoccer-final-course-reports',
            'year' => $year,
            'live' => 1,
        ],
        admin_url('admin.php')
    );
    ?>
    <div class="wrap intersoccer-underfilled-programs">
        <h1><?php esc_html_e('Underfilled programs', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php esc_html_e('Programs ranked weakest-first so marketing can target camps and courses that need a push. Headcounts use the same roster listing statuses as Camps/Courses pages (completed, processing, pending, on-hold). Live Snapshot Final Numbers use completed + processing only.', 'intersoccer-reports-rosters'); ?></p>

        <p class="description" style="margin-bottom:16px;">
            <strong><?php esc_html_e('Legend:', 'intersoccer-reports-rosters'); ?></strong>
            <?php esc_html_e('Critical ≤7 · Low ≤20 · Good ≤29 · Optimal 30+', 'intersoccer-reports-rosters'); ?>
        </p>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="intersoccer-underfilled-programs" />
            <label for="year"><?php esc_html_e('Year:', 'intersoccer-reports-rosters'); ?></label>
            <input type="number" name="year" id="year" value="<?php echo esc_attr((string) $year); ?>" min="2020" max="<?php echo esc_attr((string) (date('Y') + 2)); ?>" />
            <label for="activity"><?php esc_html_e('Activity:', 'intersoccer-reports-rosters'); ?></label>
            <select name="activity" id="activity">
                <option value="both" <?php selected($activity_filter, 'both'); ?>><?php esc_html_e('Camps + Courses', 'intersoccer-reports-rosters'); ?></option>
                <option value="camp" <?php selected($activity_filter, 'camp'); ?>><?php esc_html_e('Camps only', 'intersoccer-reports-rosters'); ?></option>
                <option value="course" <?php selected($activity_filter, 'course'); ?>><?php esc_html_e('Courses only', 'intersoccer-reports-rosters'); ?></option>
            </select>
            <label for="season_type"><?php esc_html_e('Camp season:', 'intersoccer-reports-rosters'); ?></label>
            <select name="season_type" id="season_type">
                <option value="Summer" <?php selected($season_type, 'Summer'); ?>><?php esc_html_e('Summer', 'intersoccer-reports-rosters'); ?></option>
                <option value="Winter" <?php selected($season_type, 'Winter'); ?>><?php esc_html_e('Winter', 'intersoccer-reports-rosters'); ?></option>
                <option value="Autumn" <?php selected($season_type, 'Autumn'); ?>><?php esc_html_e('Autumn', 'intersoccer-reports-rosters'); ?></option>
                <option value="Spring" <?php selected($season_type, 'Spring'); ?>><?php esc_html_e('Spring', 'intersoccer-reports-rosters'); ?></option>
                <option value="" <?php selected($season_type, ''); ?>><?php esc_html_e('All seasons', 'intersoccer-reports-rosters'); ?></option>
            </select>
            <label style="margin-left:8px;">
                <input type="checkbox" name="urgency_only" value="1" <?php checked($urgency_only); ?> />
                <?php esc_html_e('Critical + Low only', 'intersoccer-reports-rosters'); ?>
            </label>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'intersoccer-reports-rosters'); ?></button>
            <a class="button" href="<?php echo esc_url($excel_url); ?>"><?php esc_html_e('Export to Excel', 'intersoccer-reports-rosters'); ?></a>
            <a class="button" href="<?php echo esc_url($live_camps_url); ?>"><?php esc_html_e('Summer Camps Live', 'intersoccer-reports-rosters'); ?></a>
            <a class="button" href="<?php echo esc_url($live_courses_url); ?>"><?php esc_html_e('Courses Live', 'intersoccer-reports-rosters'); ?></a>
        </form>

        <?php if (empty($rows)): ?>
            <p><?php esc_html_e('No programs matched the selected filters.', 'intersoccer-reports-rosters'); ?></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Activity', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Product', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Venue', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Season', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Term / Day', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Age group', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Players', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Urgency', 'intersoccer-reports-rosters'); ?></th>
                        <th><?php esc_html_e('Actions', 'intersoccer-reports-rosters'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row):
                        $from = $row['activity'] === 'Camp' ? 'camps' : 'courses';
                        $view_url = function_exists('intersoccer_get_roster_listing_group_details_url')
                            ? intersoccer_get_roster_listing_group_details_url($row['group'], $from)
                            : add_query_arg(
                                [
                                    'page' => 'intersoccer-roster-details',
                                    'from' => $from,
                                    'event_signature' => $row['group']['event_signature'] ?? '',
                                ],
                                admin_url('admin.php')
                            );
                        $term_day = $row['activity'] === 'Camp' ? $row['camp_terms'] : $row['course_day'];
                        ?>
                        <tr>
                            <td><?php echo esc_html($row['activity']); ?></td>
                            <td><?php echo esc_html($row['product_name']); ?></td>
                            <td><?php echo esc_html($row['venue']); ?></td>
                            <td><?php echo esc_html($row['season']); ?></td>
                            <td><?php echo esc_html($term_day); ?></td>
                            <td><?php echo esc_html($row['age_group']); ?></td>
                            <td>
                                <span class="count-number <?php echo esc_attr($row['band']); ?>" style="display:inline-block;padding:4px 10px;border-radius:4px;color:#fff;font-weight:600;">
                                    <?php echo esc_html((string) $row['total_players']); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(intersoccer_underfilled_band_label($row['band'])); ?></td>
                            <td><a href="<?php echo esc_url($view_url); ?>"><?php esc_html_e('View roster', 'intersoccer-reports-rosters'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <style>
                .intersoccer-underfilled-programs .count-critical { background: #dc2626; }
                .intersoccer-underfilled-programs .count-low { background: #d97706; }
                .intersoccer-underfilled-programs .count-good { background: #059669; }
                .intersoccer-underfilled-programs .count-optimal { background: #1d4ed8; }
            </style>
        <?php endif; ?>
    </div>
    <?php
}
