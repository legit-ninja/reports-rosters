<?php
/**
 * Advanced functionality for InterSoccer Reports and Rosters plugin.
 */

// Handle AJAX request for saving attendance
add_action('wp_ajax_intersoccer_save_attendance', 'intersoccer_save_attendance');
function intersoccer_save_attendance() {
    check_ajax_referer('intersoccer_advanced_nonce', 'nonce');

    if (!current_user_can('coach') && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $player_name = isset($_POST['player_name']) ? sanitize_text_field($_POST['player_name']) : '';
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : date('Y-m-d');
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'Absent';
    $coach_id = get_current_user_id();

    if (!$event_id || !$player_name || !in_array($status, ['Present', 'Absent', 'Late'])) {
        wp_send_json_error(['message' => __('Invalid data provided.', 'intersoccer-reports-rosters')]);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'intersoccer_attendance';

    // Check if an entry exists for this event, player, and date
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE event_id = %d AND player_name = %s AND date = %s",
            $event_id,
            $player_name,
            $date
        )
    );

    if ($existing) {
        // Update existing entry
        $wpdb->update(
            $table_name,
            [
                'status' => $status,
                'coach_id' => $coach_id,
                'timestamp' => current_time('mysql'),
            ],
            [
                'id' => $existing->id,
            ],
            ['%s', '%d', '%s'],
            ['%d']
        );
    } else {
        // Insert new entry
        $wpdb->insert(
            $table_name,
            [
                'event_id' => $event_id,
                'player_name' => $player_name,
                'date' => $date,
                'status' => $status,
                'coach_id' => $coach_id,
                'timestamp' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
    }

    wp_send_json_success(['message' => __('Attendance updated successfully.', 'intersoccer-reports-rosters')]);
}

// Handle AJAX request for saving coach notes
add_action('wp_ajax_intersoccer_save_coach_notes', 'intersoccer_save_coach_notes');
function intersoccer_save_coach_notes() {
    check_ajax_referer('intersoccer_advanced_nonce', 'nonce');

    if (!current_user_can('coach') && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized access.', 'intersoccer-reports-rosters')]);
    }

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $player_id = isset($_POST['player_id']) ? sanitize_text_field($_POST['player_id']) : '';
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : date('Y-m-d');
    $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $incident_report = isset($_POST['incident_report']) ? sanitize_textarea_field($_POST['incident_report']) : '';
    $coach_id = get_current_user_id();

    if (!$event_id || !$notes) {
        wp_send_json_error(['message' => __('Event ID and notes are required.', 'intersoccer-reports-rosters')]);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'intersoccer_coach_notes';

    $wpdb->insert(
        $table_name,
        [
            'event_id' => $event_id,
            'player_id' => $player_id ? $player_id : null,
            'coach_id' => $coach_id,
            'date' => $date,
            'notes' => $notes,
            'incident_report' => $incident_report ? $incident_report : null,
            'timestamp' => current_time('mysql'),
        ],
        ['%d', '%s', '%d', '%s', '%s', '%s', '%s']
    );

    wp_send_json_success(['message' => __('Notes saved successfully.', 'intersoccer-reports-rosters')]);
}

// Render Advanced page
function intersoccer_render_advanced_page() {
    $current_user = wp_get_current_user();
    $is_coach = in_array('coach', (array) $current_user->roles);
    $is_admin = current_user_can('manage_options');

    if (!$is_coach && !$is_admin) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    // Determine active tab
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'attendance';

    ?>
    <div class="wrap intersoccer-reports-rosters-dashboard">
        <h1><?php _e('InterSoccer Advanced', 'intersoccer-reports-rosters'); ?></h1>

        <!-- Tabs -->
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url(add_query_arg('tab', 'attendance')); ?>" class="nav-tab <?php echo $active_tab === 'attendance' ? 'nav-tab-active' : ''; ?>"><?php _e('Attendance', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'notes')); ?>" class="nav-tab <?php echo $active_tab === 'notes' ? 'nav-tab-active' : ''; ?>"><?php _e('Coach Notes', 'intersoccer-reports-rosters'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'schedule')); ?>" class="nav-tab <?php echo $active_tab === 'schedule' ? 'nav-tab-active' : ''; ?>"><?php _e('Schedule', 'intersoccer-reports-rosters'); ?></a>
        </h2>

        <?php if ($active_tab === 'attendance'): ?>
            <?php intersoccer_render_attendance_tab($is_coach, $is_admin); ?>
        <?php elseif ($active_tab === 'notes'): ?>
            <?php intersoccer_render_notes_tab($is_coach, $is_admin); ?>
        <?php else: // Schedule ?>
            <?php intersoccer_render_schedule_tab($is_coach, $is_admin); ?>
        <?php endif; ?>
    </div>
    <?php
    error_log('InterSoccer: Rendered Advanced page, active tab: ' . $active_tab);
}

// Render Attendance tab
function intersoccer_render_attendance_tab($is_coach, $is_admin) {
    // Fetch events (using WooCommerce products for now)
    $args = [
        'type' => 'variable',
        'limit' => -1,
        'status' => 'publish',
    ];
    if ($is_coach) {
        // TODO: Filter events by coach assignment when Events CPT is available
        // For now, show all events (placeholder for CPT integration)
    }
    $products = wc_get_products($args);

    $events = [];
    foreach ($products as $product) {
        $product_id = $product->get_id();
        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;

            $events[] = [
                'id' => $variation_id,
                'name' => $product->get_name() . ' - ' . $variation->get_name(),
                'region' => wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown',
                'venue' => wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown',
                'start_date' => wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? 'Unknown',
                'end_date' => wc_get_product_terms($product_id, 'pa_end-date', ['fields' => 'names'])[0] ?? 'Unknown',
            ];
        }
    }

    // Fetch roster and attendance data if an event is selected
    $selected_event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
    $selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');
    $roster = $selected_event_id ? intersoccer_pe_get_event_roster_by_variation($selected_event_id) : [];
    $attendance_data = [];
    if ($selected_event_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'intersoccer_attendance';
        $attendance_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT player_name, status FROM $table_name WHERE event_id = %d AND date = %s",
                $selected_event_id,
                $selected_date
            ),
            ARRAY_A
        );
        $attendance_data = array_column($attendance_data, 'status', 'player_name');
    }

    ?>
    <div class="filter-form">
        <h3><?php _e('Attendance Management', 'intersoccer-reports-rosters'); ?></h3>

        <!-- Event Selection -->
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="intersoccer-advanced" />
            <input type="hidden" name="tab" value="attendance" />
            <div class="filter-section">
                <h4><?php _e('Select Event and Date', 'intersoccer-reports-rosters'); ?></h4>
                <div class="filter-row">
                    <label for="event_id"><?php _e('Event:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="event_id" id="event_id">
                        <option value=""><?php _e('Select an Event', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($events as $event): ?>
                            <option value="<?php echo esc_attr($event['id']); ?>" <?php selected($selected_event_id, $event['id']); ?>>
                                <?php echo esc_html($event['name'] . ' (' . $event['region'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="date"><?php _e('Date:', 'intersoccer-reports-rosters'); ?></label>
                    <input type="date" name="date" id="date" value="<?php echo esc_attr($selected_date); ?>" />

                    <button type="submit" class="button"><?php _e('Load Roster', 'intersoccer-reports-rosters'); ?></button>
                </div>
            </div>
        </form>

        <!-- Attendance Form (for Coaches) or Report (for Office Staff) -->
        <?php if ($selected_event_id && !empty($roster)): ?>
            <?php if ($is_coach): ?>
                <h4><?php _e('Mark Attendance for', 'intersoccer-reports-rosters'); ?> <?php echo esc_html($events[array_search($selected_event_id, array_column($events, 'id'))]['name']); ?></h4>
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Player Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Status', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roster as $player): ?>
                            <tr>
                                <td><?php echo esc_html($player['player_name']); ?></td>
                                <td>
                                    <select class="attendance-status" data-event-id="<?php echo esc_attr($selected_event_id); ?>" data-player-name="<?php echo esc_attr($player['player_name']); ?>" data-date="<?php echo esc_attr($selected_date); ?>">
                                        <option value="Present" <?php selected($attendance_data[$player['player_name']] ?? 'Absent', 'Present'); ?>><?php _e('Present', 'intersoccer-reports-rosters'); ?></option>
                                        <option value="Absent" <?php selected($attendance_data[$player['player_name']] ?? 'Absent', 'Absent'); ?>><?php _e('Absent', 'intersoccer-reports-rosters'); ?></option>
                                        <option value="Late" <?php selected($attendance_data[$player['player_name']] ?? 'Absent', 'Late'); ?>><?php _e('Late', 'intersoccer-reports-rosters'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: // Office Staff ?>
                <h4><?php _e('Attendance Report for', 'intersoccer-reports-rosters'); ?> <?php echo esc_html($events[array_search($selected_event_id, array_column($events, 'id'))]['name']); ?></h4>
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Player Name', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Status', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Coach', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Timestamp', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'intersoccer_attendance';
                        $attendance_records = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT player_name, status, coach_id, timestamp FROM $table_name WHERE event_id = %d AND date = %s",
                                $selected_event_id,
                                $selected_date
                            )
                        );
                        foreach ($attendance_records as $record):
                            $coach = get_userdata($record->coach_id);
                            ?>
                            <tr>
                                <td><?php echo esc_html($record->player_name); ?></td>
                                <td><?php echo esc_html($record->status); ?></td>
                                <td><?php echo esc_html($coach ? $coach->display_name : 'Unknown'); ?></td>
                                <td><?php echo esc_html($record->timestamp); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php elseif ($selected_event_id): ?>
            <p><?php _e('No players assigned to this event.', 'intersoccer-reports-rosters'); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

// Render Coach Notes tab
function intersoccer_render_notes_tab($is_coach, $is_admin) {
    // Fetch events (using WooCommerce products for now)
    $args = [
        'type' => 'variable',
        'limit' => -1,
        'status' => 'publish',
    ];
    if ($is_coach) {
        // TODO: Filter events by coach assignment when Events CPT is available
    }
    $products = wc_get_products($args);

    $events = [];
    foreach ($products as $product) {
        $product_id = $product->get_id();
        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;

            $events[] = [
                'id' => $variation_id,
                'name' => $product->get_name() . ' - ' . $variation->get_name(),
                'region' => wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown',
            ];
        }
    }

    $selected_event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
    $selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');
    $roster = $selected_event_id ? intersoccer_pe_get_event_roster_by_variation($selected_event_id) : [];

    ?>
    <div class="filter-form">
        <h3><?php _e('Coach Notes', 'intersoccer-reports-rosters'); ?></h3>

        <!-- Event Selection -->
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="intersoccer-advanced" />
            <input type="hidden" name="tab" value="notes" />
            <div class="filter-section">
                <h4><?php _e('Select Event and Date', 'intersoccer-reports-rosters'); ?></h4>
                <div class="filter-row">
                    <label for="event_id"><?php _e('Event:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="event_id" id="event_id">
                        <option value=""><?php _e('Select an Event', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($events as $event): ?>
                            <option value="<?php echo esc_attr($event['id']); ?>" <?php selected($selected_event_id, $event['id']); ?>>
                                <?php echo esc_html($event['name'] . ' (' . $event['region'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="date"><?php _e('Date:', 'intersoccer-reports-rosters'); ?></label>
                    <input type="date" name="date" id="date" value="<?php echo esc_attr($selected_date); ?>" />

                    <button type="submit" class="button"><?php _e('Load Event', 'intersoccer-reports-rosters'); ?></button>
                </div>
            </div>
        </form>

        <!-- Notes Form (for Coaches) or Notes Report (for Office Staff) -->
        <?php if ($selected_event_id): ?>
            <?php if ($is_coach): ?>
                <h4><?php _e('Submit Notes for', 'intersoccer-reports-rosters'); ?> <?php echo esc_html($events[array_search($selected_event_id, array_column($events, 'id'))]['name']); ?></h4>
                <form id="coach-notes-form" method="post">
                    <input type="hidden" name="event_id" value="<?php echo esc_attr($selected_event_id); ?>" />
                    <input type="hidden" name="date" value="<?php echo esc_attr($selected_date); ?>" />
                    <div class="filter-section">
                        <div class="filter-row">
                            <label for="player_id"><?php _e('Player (Optional):', 'intersoccer-reports-rosters'); ?></label>
                            <select name="player_id" id="player_id">
                                <option value=""><?php _e('General Note', 'intersoccer-reports-rosters'); ?></option>
                                <?php foreach ($roster as $player): ?>
                                    <option value="<?php echo esc_attr($player['player_name']); ?>">
                                        <?php echo esc_html($player['player_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-row">
                            <label for="notes"><?php _e('Notes:', 'intersoccer-reports-rosters'); ?></label>
                            <textarea name="notes" id="notes" rows="5" style="width: 100%;" required></textarea>
                        </div>
                        <div class="filter-row">
                            <label for="incident_report"><?php _e('Incident Report (Optional):', 'intersoccer-reports-rosters'); ?></label>
                            <textarea name="incident_report" id="incident_report" rows="5" style="width: 100%;"></textarea>
                        </div>
                        <div class="filter-row">
                            <button type="submit" class="button button-primary"><?php _e('Submit Notes', 'intersoccer-reports-rosters'); ?></button>
                        </div>
                    </div>
                </form>
            <?php else: // Office Staff ?>
                <h4><?php _e('Coach Notes for', 'intersoccer-reports-rosters'); ?> <?php echo esc_html($events[array_search($selected_event_id, array_column($events, 'id'))]['name']); ?></h4>
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th><?php _e('Date', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Coach', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Player', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Notes', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Incident Report', 'intersoccer-reports-rosters'); ?></th>
                            <th><?php _e('Timestamp', 'intersoccer-reports-rosters'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'intersoccer_coach_notes';
                        $notes = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT * FROM $table_name WHERE event_id = %d AND date = %s",
                                $selected_event_id,
                                $selected_date
                            )
                        );
                        foreach ($notes as $note):
                            $coach = get_userdata($note->coach_id);
                            ?>
                            <tr>
                                <td><?php echo esc_html($note->date); ?></td>
                                <td><?php echo esc_html($coach ? $coach->display_name : 'Unknown'); ?></td>
                                <td><?php echo esc_html($note->player_id ? $note->player_id : 'N/A'); ?></td>
                                <td><?php echo esc_html($note->notes); ?></td>
                                <td><?php echo esc_html($note->incident_report ? $note->incident_report : 'N/A'); ?></td>
                                <td><?php echo esc_html($note->timestamp); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

// Render Schedule tab
function intersoccer_render_schedule_tab($is_coach, $is_admin) {
    // Fetch events (using WooCommerce products for now)
    $args = [
        'type' => 'variable',
        'limit' => -1,
        'status' => 'publish',
    ];
    if ($is_coach) {
        // TODO: Filter events by coach assignment when Events CPT is available
    }
    $products = wc_get_products($args);

    $events = [];
    foreach ($products as $product) {
        $product_id = $product->get_id();
        $variations = $product->get_children();
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;

            $events[] = [
                'id' => $variation_id,
                'name' => $product->get_name() . ' - ' . $variation->get_name(),
                'region' => wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown',
                'venue' => wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown',
                'start_date' => wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? 'Unknown',
                'end_date' => wc_get_product_terms($product_id, 'pa_end-date', ['fields' => 'names'])[0] ?? 'Unknown',
            ];
        }
    }

    // Filter events by date range (for Office Staff)
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-1 month'));
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d', strtotime('+1 month'));

    $filtered_events = array_filter($events, function($event) use ($start_date, $end_date) {
        return ($event['start_date'] >= $start_date && $event['start_date'] <= $end_date) ||
               ($event['end_date'] >= $start_date && $event['end_date'] <= $end_date);
    });

    ?>
    <div class="filter-form">
        <h3><?php _e('Event Schedule', 'intersoccer-reports-rosters'); ?></h3>

        <!-- Date Range Filter (for Office Staff) -->
        <?php if ($is_admin): ?>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="intersoccer-advanced" />
                <input type="hidden" name="tab" value="schedule" />
                <div class="filter-section">
                    <h4><?php _e('Filter Schedule', 'intersoccer-reports-rosters'); ?></h4>
                    <div class="filter-row">
                        <label for="start_date"><?php _e('Start Date:', 'intersoccer-reports-rosters'); ?></label>
                        <input type="date" name="start_date" id="start_date" value="<?php echo esc_attr($start_date); ?>" />

                        <label for="end_date"><?php _e('End Date:', 'intersoccer-reports-rosters'); ?></label>
                        <input type="date" name="end_date" id="end_date" value="<?php echo esc_attr($end_date); ?>" />

                        <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
                    </div>
                </div>
            </form>
        <?php endif; ?>

        <!-- Schedule Table -->
        <table class="widefat fixed">
            <thead>
                <tr>
                    <th><?php _e('Event Name', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Region', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Start Date', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('End Date', 'intersoccer-reports-rosters'); ?></th>
                    <th><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filtered_events as $event): ?>
                    <tr>
                        <td><?php echo esc_html($event['name']); ?></td>
                        <td><?php echo esc_html($event['region']); ?></td>
                        <td><?php echo esc_html($event['venue']); ?></td>
                        <td><?php echo esc_html($event['start_date']); ?></td>
                        <td><?php echo esc_html($event['end_date']); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . $event['id'])); ?>" class="button"><?php _e('View Roster', 'intersoccer-reports-rosters'); ?></a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-advanced&tab=attendance&event_id=' . $event['id'] . '&date=' . $event['start_date'])); ?>" class="button"><?php _e('Take Attendance', 'intersoccer-reports-rosters'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>
