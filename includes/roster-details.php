<?php
/**
 * Roster Details and Specific Event Pages
 *
 * Handles rendering of detailed roster views.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.50  // Incremented for activity type and referer fix
 * @author Jeremy Lee
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once plugin_dir_path(dirname(__FILE__)) . 'includes/roster-data.php';

/**
 * Generate URL for sortable column
 */
function intersoccer_get_sort_url($sort_field, $current_sort, $current_order) {
    $params = $_GET;
    $params['sort'] = $sort_field;
    $params['order'] = ($current_sort === $sort_field && $current_order === 'asc') ? 'desc' : 'asc';
    return add_query_arg($params, admin_url('admin.php?page=intersoccer-roster-details'));
}

/**
 * Get sort indicator for column header
 */
function intersoccer_get_sort_indicator($field, $current_sort, $current_order) {
    if ($current_sort !== $field) {
        return ' ⇅'; // Neutral sort indicator
    }
    return $current_order === 'asc' ? ' ↑' : ' ↓';
}

/**
 * Render the roster details page
 */
function intersoccer_render_roster_details_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Get query parameters
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    $variation_id = isset($_GET['variation_id']) ? intval($_GET['variation_id']) : 0;
    $variation_ids_str = isset($_GET['variation_ids']) ? sanitize_text_field($_GET['variation_ids']) : '';
    $variation_ids = $variation_ids_str ? array_map('intval', explode(',', $variation_ids_str)) : [];
    $event_signature = isset($_GET['event_signature']) ? sanitize_text_field($_GET['event_signature']) : '';
    $camp_terms = isset($_GET['camp_terms']) ? sanitize_text_field($_GET['camp_terms']) : '';
    $course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';
    $venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $times = isset($_GET['times']) ? sanitize_text_field($_GET['times']) : '';
    $product_name = isset($_GET['product_name']) ? sanitize_text_field($_GET['product_name']) : '';
    $event_dates = isset($_GET['event_dates']) ? sanitize_text_field($_GET['event_dates']) : '';
    $from_page = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
    $girls_only = isset($_GET['girls_only']) ? (bool) $_GET['girls_only'] : false;
    $season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $sort_by = isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'order_date';
    $sort_order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'desc';

    // Validate sort parameters
    $allowed_sort_fields = ['order_date', 'player_name', 'last_name', 'gender', 'age', 'age_group'];
    if (!in_array($sort_by, $allowed_sort_fields)) {
        $sort_by = 'order_date';
    }
    if (!in_array($sort_order, ['asc', 'desc'])) {
        $sort_order = 'asc';
    }

    // Check referer and from param
    $referer = wp_get_referer();
    $is_from_camps_page = $from_page === 'camps' || strpos($referer, 'page=intersoccer-camps') !== false;
    $is_from_courses_page = $from_page === 'courses' || strpos($referer, 'page=intersoccer-courses') !== false;
    $is_from_girls_only_page = $from_page === 'girls-only' || strpos($referer, 'page=intersoccer-girls-only') !== false || $girls_only;
    $is_from_tournaments_page = $from_page === 'tournaments' || strpos($referer, 'page=intersoccer-tournaments') !== false;

    error_log('InterSoccer: Roster details parameters - girls_only: ' . ($girls_only ? 'yes' : 'no') . ', from_page: ' . ($from_page ?: 'N/A'));

    $use_oop_rosters = defined('INTERSOCCER_OOP_ACTIVE')
        && INTERSOCCER_OOP_ACTIVE
        && function_exists('intersoccer_use_oop_for')
        && intersoccer_use_oop_for('rosters')
        && function_exists('intersoccer_oop_get_roster_details_service');

    if ($use_oop_rosters) {
        $service = intersoccer_oop_get_roster_details_service();
        $result = $service->getRosterContext(
            [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'variation_ids' => $variation_ids,
                'event_signature' => $event_signature,
                'camp_terms' => $camp_terms,
                'course_day' => $course_day,
                'venue' => $venue,
                'age_group' => $age_group,
                'times' => $times,
                'season' => $season,
                'girls_only' => $girls_only,
            ],
            [
                'is_from_camps_page' => $is_from_camps_page,
                'is_from_courses_page' => $is_from_courses_page,
                'is_from_girls_only_page' => $is_from_girls_only_page,
                'is_from_tournaments_page' => $is_from_tournaments_page,
                'sort_by' => $sort_by,
                'sort_order' => $sort_order,
            ]
        );

        if (!$result['success']) {
            echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
            echo '<p>' . esc_html($result['error']) . '</p></div>';
            return;
        }

        $rosters = $result['rosters'];
        $base_roster = $result['base_roster'];
        $available_rosters = $result['available_rosters'];
        $cross_gender_rosters = $result['cross_gender_rosters'];
        $unknown_count = $result['unknown_count'];

        error_log('InterSoccer OOP: Roster details results count: ' . count($rosters));

        if ($product_id <= 0 && !empty($base_roster->product_id)) {
            $product_id = (int) $base_roster->product_id;
        }
        if (empty($camp_terms) && !empty($base_roster->camp_terms)) {
            $camp_terms = $base_roster->camp_terms;
        }
        if (empty($course_day) && !empty($base_roster->course_day)) {
            $course_day = $base_roster->course_day;
        }
        if (empty($venue) && !empty($base_roster->venue)) {
            $venue = $base_roster->venue;
        }
        if (empty($age_group) && !empty($base_roster->age_group)) {
            $age_group = $base_roster->age_group;
        }
        if (empty($times) && !empty($base_roster->times)) {
            $times = $base_roster->times;
        }
        if (!$girls_only && !empty($base_roster->girls_only)) {
            $girls_only = (bool) $base_roster->girls_only;
        }
        if (empty($season) && !empty($base_roster->season)) {
            $season = $base_roster->season;
        }
    } else {
        $query = "SELECT r.player_name, r.first_name, r.last_name, r.gender, r.parent_phone, r.parent_email, r.age, r.medical_conditions, r.late_pickup, r.late_pickup_days, r.booking_type, r.course_day, r.shirt_size, r.shorts_size, r.day_presence, r.order_item_id, r.variation_id, r.age_group, r.activity_type, r.product_name, r.camp_terms, r.venue, r.times, r.product_id, r.girls_only, p.post_date as order_date";
        $query .= " FROM $rosters_table r";
        $query .= " JOIN {$wpdb->posts} p ON r.order_id = p.ID";

        $where_clauses = [];
        $query_params = [];

        $where_clauses[] = "p.post_status IN ('wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold')";

        if ($is_from_girls_only_page || $girls_only) {
            $where_clauses[] = "r.girls_only = 1";
        } elseif ($is_from_camps_page) {
            $where_clauses[] = "r.activity_type = 'Camp' AND r.girls_only = 0";
        } elseif ($is_from_courses_page) {
            $where_clauses[] = "r.activity_type = 'Course' AND r.girls_only = 0";
        } elseif ($is_from_tournaments_page) {
            $where_clauses[] = "r.activity_type = 'Tournament' AND r.girls_only = 0";
        }

        if ($product_id > 0) {
            $where_clauses[] = "r.product_id = %d";
            $query_params[] = $product_id;
        }

        if ($product_name) {
            $where_clauses[] = "r.product_name = %s";
            $query_params[] = $product_name;
            if ($event_dates && $event_dates !== 'N/A') {
                $where_clauses[] = "r.event_dates = %s";
                $query_params[] = $event_dates;
            }
        }

        if ($variation_id > 0) {
            $where_clauses[] = "r.variation_id = %d";
            $query_params[] = $variation_id;
        }

        if (!empty($variation_ids)) {
            $placeholders = implode(',', array_fill(0, count($variation_ids), '%d'));
            $where_clauses[] = "r.variation_id IN ($placeholders)";
            $query_params = array_merge($query_params, $variation_ids);
        }

        if ($event_signature && $event_signature !== 'N/A') {
            $where_clauses[] = "(r.event_signature = %s OR r.event_signature LIKE %s OR (r.event_signature IS NULL AND %s = 'N/A'))";
            $query_params[] = $event_signature;
            $query_params[] = '%' . $wpdb->esc_like($event_signature) . '%';
            $query_params[] = $event_signature;
        } else {
            if ($camp_terms && $camp_terms !== 'N/A') {
                $where_clauses[] = "r.camp_terms = %s";
                $query_params[] = $camp_terms;
            }

            if ($course_day && $course_day !== 'N/A') {
                $where_clauses[] = "r.course_day = %s";
                $query_params[] = $course_day;
            }

            if ($venue) {
                $where_clauses[] = "r.venue = %s";
                $query_params[] = $venue;
            }

            if ($age_group) {
                $where_clauses[] = "r.age_group = %s";
                $query_params[] = $age_group;
            }

            if ($times) {
                $where_clauses[] = "r.times = %s";
                $query_params[] = $times;
            }
        }

        if ($season) {
            $where_clauses[] = "r.season = %s";
            $query_params[] = $season;
        }

        if (empty($where_clauses)) {
            error_log('InterSoccer: No valid parameters provided for roster details');
            echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
            echo '<p>' . esc_html__('Invalid parameters provided.', 'intersoccer-reports-rosters') . '</p></div>';
            return;
        }

        $query .= " WHERE " . implode(' AND ', $where_clauses);

        $order_by_map = [
            'order_date' => 'p.post_date',
            'player_name' => 'r.first_name',
            'last_name' => 'r.last_name',
            'gender' => 'r.gender',
            'age' => 'CAST(r.age AS UNSIGNED)',
            'age_group' => 'r.age_group'
        ];

        $order_field = $order_by_map[$sort_by] ?? 'p.post_date';
        $query .= " ORDER BY {$order_field} {$sort_order}, r.first_name ASC, r.last_name ASC";

        $rosters = $wpdb->get_results($wpdb->prepare($query, $query_params), OBJECT);

        error_log('InterSoccer: Roster details query: ' . $wpdb->last_query);
        error_log('InterSoccer: Roster details results count: ' . count($rosters));

        if (!$rosters) {
            echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
            echo '<p>' . esc_html__('No rosters found for the provided parameters.', 'intersoccer-reports-rosters') . '</p></div>';
            return;
        }

        $base_roster = $rosters[0];
        $base_roster_girls_only = (int) ($base_roster->girls_only ?? 0);

        $available_rosters_query = $wpdb->prepare("
        SELECT DISTINCT 
            r.product_id,
            r.variation_id,
            r.product_name,
            r.venue,
            r.age_group,
            r.activity_type,
            r.camp_terms,
            r.course_day,
            r.times,
            r.season,
            r.girls_only,
            COUNT(DISTINCT r.order_item_id) as current_players
        FROM $rosters_table r
        JOIN {$wpdb->posts} p ON r.order_id = p.ID
        WHERE p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND r.activity_type = %s
        AND r.girls_only = %d
        AND r.variation_id != %d
        GROUP BY r.product_id, r.variation_id, r.product_name, r.venue, r.age_group, r.activity_type, r.camp_terms, r.course_day, r.times, r.season, r.girls_only
        ORDER BY r.product_name, r.venue, r.age_group
    ", $base_roster->activity_type, $base_roster_girls_only, $variation_id);

        $available_rosters = $wpdb->get_results($available_rosters_query, OBJECT);

        $cross_gender_rosters_query = $wpdb->prepare("
        SELECT DISTINCT 
            r.product_id,
            r.variation_id,
            r.product_name,
            r.venue,
            r.age_group,
            r.activity_type,
            r.camp_terms,
            r.course_day,
            r.times,
            r.season,
            r.girls_only,
            COUNT(DISTINCT r.order_item_id) as current_players
        FROM $rosters_table r
        JOIN {$wpdb->posts} p ON r.order_id = p.ID
        WHERE p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND r.activity_type = %s
        AND r.girls_only != %d
        AND r.variation_id != %d
        GROUP BY r.product_id, r.variation_id, r.product_name, r.venue, r.age_group, r.activity_type, r.camp_terms, r.course_day, r.times, r.season, r.girls_only
        ORDER BY r.product_name, r.venue, r.age_group
    ", $base_roster->activity_type, $base_roster_girls_only, $variation_id);

        $cross_gender_rosters = $wpdb->get_results($cross_gender_rosters_query, OBJECT);

        $unknown_count = count(array_filter($rosters, fn($row) => $row->player_name === 'Unknown Attendee'));
    }

    // Get base roster for event attributes
    $base_roster = $base_roster ?? $rosters[0];

    // Determine if the event is camp-like - SIMPLIFIED
    $is_camp_like = ($base_roster->activity_type === 'Camp' || (!empty($base_roster->camp_terms) && $base_roster->camp_terms !== 'N/A'));
    $is_girls_only = (bool) $base_roster->girls_only;

    // Render the page
    echo '<div class="wrap">';
    $title_suffix = $is_girls_only ? ' (Girls Only)' : '';
    // Normalize product name to English for display
    $product_name = $base_roster->product_name ?: '';
    if (!empty($product_name) && function_exists('intersoccer_get_english_product_name')) {
        $product_name = intersoccer_get_english_product_name($product_name, $base_roster->product_id ?? 0);
    }
    $event_label = $product_name ?: ($base_roster->course_day ?: ($base_roster->camp_terms ?: __('Unknown Event', 'intersoccer-reports-rosters')));
    
    // Get age group for title - use base_roster if GET parameter is empty
    $display_age_group = !empty($age_group) ? $age_group : ($base_roster->age_group ?? '');
    // Only add parentheses if age group is not empty
    $age_group_suffix = !empty($display_age_group) ? ' (' . esc_html($display_age_group) . ')' : '';
    
    echo '<h1>' . esc_html__('Roster Details for ', 'intersoccer-reports-rosters') . esc_html($event_label) . ' - ' . esc_html($base_roster->venue) . $age_group_suffix . $title_suffix . '</h1>';
    
    if ($unknown_count > 0) {
        echo '<p style="color: red;">' . esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)) . '</p>';
    }
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th style="width: 15px;"><input type="checkbox" id="selectAll"></th>'; // New: Checkbox for select all
    echo '<th style="width: 120px;"><a href="' . esc_url(intersoccer_get_sort_url('order_date', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Order Date', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('order_date', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 140px;"><a href="' . esc_url(intersoccer_get_sort_url('player_name', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Name', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('player_name', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 140px;"><a href="' . esc_url(intersoccer_get_sort_url('last_name', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Surname', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('last_name', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 80px;"><a href="' . esc_url(intersoccer_get_sort_url('gender', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Gender', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('gender', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 130px;">' . esc_html__('Phone', 'intersoccer-reports-rosters') . '</th>';
    echo '<th style="width: 200px;">' . esc_html__('Email', 'intersoccer-reports-rosters') . '</th>';
    echo '<th style="width: 50px;"><a href="' . esc_url(intersoccer_get_sort_url('age', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Age', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('age', $sort_by, $sort_order) . '</a></th>';
    echo '<th style="width: 200px;">' . esc_html__('Medical/Dietary', 'intersoccer-reports-rosters') . '</th>';
    
    if ($is_camp_like) {
        echo '<th style="width: 100px;">' . esc_html__('Late Pickup', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 120px;">' . esc_html__('Late Pickup Days', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 120px;">' . esc_html__('Booking Type', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Monday', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Tuesday', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Wednesday', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Thursday', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 70px;">' . esc_html__('Friday', 'intersoccer-reports-rosters') . '</th>';
    }
    
    echo '<th style="width: 100px;"><a href="' . esc_url(intersoccer_get_sort_url('age_group', $sort_by, $sort_order)) . '" style="color: inherit; text-decoration: none;">' . esc_html__('Age Group', 'intersoccer-reports-rosters') . intersoccer_get_sort_indicator('age_group', $sort_by, $sort_order) . '</a></th>';

    // Add pa_date column for tournaments
    if ($base_roster->activity_type === 'Tournament') {
        echo '<th style="width: 120px;">' . esc_html__('Tournament Date (pa_date)', 'intersoccer-reports-rosters') . '</th>';
    }

    if ($is_girls_only) {
        echo '<th style="width: 90px;">' . esc_html__('Shirt Size', 'intersoccer-reports-rosters') . '</th>';
        echo '<th style="width: 90px;">' . esc_html__('Shorts Size', 'intersoccer-reports-rosters') . '</th>';
    }
    
    echo '</tr>';
    echo '</thead><tbody>';
    
    foreach ($rosters as $row) {
        $is_unknown = $row->player_name === 'Unknown Attendee';
        $day_presence = !empty($row->day_presence) ? json_decode($row->day_presence, true) : [];
        
        echo '<tr data-order-item-id="' . esc_attr($row->order_item_id) . '">';
        echo '<td><input type="checkbox" class="player-select"></td>'; // New: Checkbox for selection
        echo '<td>' . esc_html($row->order_date ? date_i18n('Y-m-d H:i', strtotime($row->order_date)) : 'N/A') . '</td>';
        echo '<td' . ($is_unknown ? ' style="font-style: italic; color: red;"' : '') . '>' . esc_html($row->first_name ?? 'N/A') . '</td>';
        echo '<td' . ($is_unknown ? ' style="font-style: italic; color: red;"' : '') . '>' . esc_html($row->last_name ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->gender ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->parent_phone ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->parent_email ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->age ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->medical_conditions ?? 'N/A') . '</td>';
        
        if ($is_camp_like) {
            echo '<td>' . esc_html($row->late_pickup ?? 'No') . '</td>';
            echo '<td>' . esc_html($row->late_pickup_days ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($row->booking_type ?? 'N/A') . '</td>';
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            foreach ($days as $day) {
                $presence = $day_presence[$day] ?? 'No';
                $style = ($presence === 'Yes') ? 'background-color: green; color: black;' : 'background-color: lightpink; color: black;';
                echo '<td style="' . esc_attr($style) . '">' . esc_html($presence) . '</td>';
            }
        }
        
        echo '<td>' . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($row->age_group, 'pa_age-group') : $row->age_group ?? 'N/A') . '</td>';
        
        // Display pa_date for tournaments
        if ($base_roster->activity_type === 'Tournament') {
            $pa_date = 'N/A';
            if (!empty($row->variation_id)) {
                $variation = wc_get_product($row->variation_id);
                if ($variation) {
                    $pa_date = $variation->get_attribute('pa_date') ?: $variation->get_attribute('Date') ?: 'N/A';
                }
            }
            if ($pa_date === 'N/A' && !empty($row->product_id)) {
                $product = wc_get_product($row->product_id);
                if ($product) {
                    $pa_date = $product->get_attribute('pa_date') ?: $product->get_attribute('Date') ?: 'N/A';
                }
            }
            echo '<td>' . esc_html($pa_date) . '</td>';
        }
        
        if ($is_girls_only) {
            echo '<td>' . esc_html($row->shirt_size ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($row->shorts_size ?? 'N/A') . '</td>';
        }
        
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    // Bulk actions section - New: Added for player migration
    echo '<div class="bulk-actions">';
    echo '    <h3 style="margin: 0 0 15px 0; color: #2c3338;">Player Management</h3>';
    echo '    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">';
    echo '        <div>';
    echo '            <label for="bulkActionSelect" style="font-weight: 500; margin-right: 8px;">Action:</label>';
    echo '            <select id="bulkActionSelect">';
    echo '                <option value="">Select Action</option>';
    echo '                <option value="move">Move to Another Roster</option>';
    echo '            </select>';
    echo '        </div>';
    echo '    </div>';
    
    // Cross-Gender Override Option
    echo '    <div id="crossGenderOption" style="display: none; margin: 15px 0 10px 0; padding: 12px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">';
    echo '        <label style="cursor: pointer;">';
    echo '            <input type="checkbox" id="allowCrossGender" value="1">';
    echo '            <strong style="color: #856404;">⚠️ Allow moving between Girls Only and Regular rosters</strong>';
    echo '        </label>';
    echo '        <p style="margin: 8px 0 0 24px; font-size: 13px; color: #856404; line-height: 1.5;">';
    echo '            <strong>Use this to fix purchase mistakes.</strong> When enabled, you can move players between rosters with different gender types. ';
    echo '            The player\'s details will be preserved, but they will be assigned to a different event type.';
    echo '        </p>';
    echo '    </div>';
            
    echo '    <div id="moveOptions" style="display: none; margin-top: 10px;">';
    echo '        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">';
    echo '            <div>';
    echo '                <label for="targetRosterSelect" style="font-weight: 500; margin-right: 8px;">Destination Roster:</label>';
    echo '                <select id="targetRosterSelect" style="min-width: 400px;">';
    echo '                    <option value="">Select a destination roster...</option>';
    
    // Helper function to generate roster label
    $generate_roster_label = function($roster, $is_cross_gender = false) use ($is_girls_only) {
        // Activity icon
        $icon = $roster->activity_type === 'Course' ? '🏐' : ($roster->activity_type === 'Camp' ? '⛺' : '🎂');
        
        // Build label with enhanced information
        $roster_label = sprintf(
            '%s %s - %s (%s)',
            $icon,
            $roster->product_name,
            $roster->venue ?: 'No Venue',
            $roster->age_group ?: 'No Age Group'
        );
        
        // Add camp terms or course day
        if ($roster->camp_terms && $roster->camp_terms !== 'N/A') {
            $roster_label .= ' - ' . substr($roster->camp_terms, 0, 50);
        } elseif ($roster->course_day && $roster->course_day !== 'N/A') {
            $roster_label .= ' - ' . $roster->course_day;
        }
        
        // Add time if available
        if (!empty($roster->times)) {
            $roster_label .= ' | ' . $roster->times;
        }
        
        // Add player count
        $roster_label .= sprintf(' | 👥 %d players', intval($roster->current_players));
        
        // Add girls-only badge
        if ($roster->girls_only) {
            $roster_label .= ' | 🚺 Girls Only';
        }
        
        // Warning if different gender from source
        if ($is_girls_only !== (bool)$roster->girls_only) {
            $roster_label .= ' | ⚠️ Different Gender';
        }
        
        return $roster_label;
    };
    
    // Same-gender rosters (always shown)
    if (!empty($available_rosters)) {
        echo '                <optgroup label="' . esc_attr__('Same Gender Type', 'intersoccer-reports-rosters') . ' (' . count($available_rosters) . ')">';
        foreach ($available_rosters as $roster) {
            $roster_label = $generate_roster_label($roster, false);
            echo '                    <option value="' . esc_attr($roster->variation_id) . '" data-girls-only="' . esc_attr($roster->girls_only ? '1' : '0') . '" data-cross-gender="0">' . esc_html($roster_label) . '</option>';
        }
        echo '                </optgroup>';
    }
    
    // Cross-gender rosters (hidden by default, shown when checkbox enabled)
    if (!empty($cross_gender_rosters)) {
        echo '                <optgroup label="' . esc_attr__('⚠️ Different Gender Type (Enable checkbox above)', 'intersoccer-reports-rosters') . ' (' . count($cross_gender_rosters) . ')" id="crossGenderRosterGroup" style="display: none;">';
        foreach ($cross_gender_rosters as $roster) {
            $roster_label = $generate_roster_label($roster, true);
            echo '                    <option value="' . esc_attr($roster->variation_id) . '" data-girls-only="' . esc_attr($roster->girls_only ? '1' : '0') . '" data-cross-gender="1" class="cross-gender-option" style="display: none;">' . esc_html($roster_label) . '</option>';
        }
        echo '                </optgroup>';
    }
    
    if (empty($available_rosters) && empty($cross_gender_rosters)) {
        echo '                <option value="" disabled>No other rosters available</option>';
    }
    echo '            </select>';
    echo '        </div>';
            
    echo '        <button id="applyBulk" class="button button-primary">Apply</button>';
    echo '    </div>';
        
    echo '    <div style="margin-top: 10px; font-size: 13px; color: #666;">';
    echo '        <strong>Instructions:</strong> ';
    echo '        1) Select players using checkboxes ';
    echo '        2) Choose "Move to Another Roster" ';
    echo '        3) Select the destination roster from the dropdown (shows event name, venue, age group, and current player count) ';
    echo '        4) Click Apply';
    echo '        <br>';
    echo '        <strong>Note:</strong> This will update order items and preserve original pricing. Changes cannot be undone.';
    echo '    </div>';
    echo '</div>';
    
    // Export form - UPDATED to include girls_only parameter and AJAX handling
    echo '<div id="roster-export-notice" style="margin-top: 20px;"></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '" class="export-form" id="roster-export-form" style="margin-top: 20px;">';
    echo '<input type="hidden" name="action" value="intersoccer_export_roster">';
    echo '<input type="hidden" name="use_fields" value="1">';
    if ($event_signature) {
        echo '<input type="hidden" name="event_signature" value="' . esc_attr($event_signature) . '">';
    } elseif (!empty($variation_ids)) {
        echo '<input type="hidden" name="variation_ids" value="' . esc_attr(implode(',', $variation_ids)) . '">';
    } elseif ($variation_id > 0) {
        echo '<input type="hidden" name="variation_id" value="' . esc_attr($variation_id) . '">';
    }
    echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '">';
    echo '<input type="hidden" name="activity_types" value="' . esc_attr($base_roster->activity_type) . '">';
    echo '<input type="hidden" name="camp_terms" value="' . esc_attr($camp_terms) . '">';
    echo '<input type="hidden" name="course_day" value="' . esc_attr($course_day) . '">';
    echo '<input type="hidden" name="venue" value="' . esc_attr($venue) . '">';
    echo '<input type="hidden" name="age_group" value="' . esc_attr($age_group) . '">';
    echo '<input type="hidden" name="times" value="' . esc_attr($times) . '">';
    echo '<input type="hidden" name="girls_only" value="' . ($is_girls_only ? '1' : '0') . '">';
    echo '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')) . '">';
    echo '<input type="submit" name="export_roster" id="roster-export-button" class="button button-primary" value="' . esc_attr__('Export Roster', 'intersoccer-reports-rosters') . '">';
    echo '</form>';
    
    echo '<p><strong>' . esc_html__('Event Details', 'intersoccer-reports-rosters') . ':</strong></p>';
    echo '<p>' . esc_html__('Product Name: ', 'intersoccer-reports-rosters') . esc_html($base_roster->product_name ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Venue: ', 'intersoccer-reports-rosters') . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->venue, 'pa_intersoccer-venues') : $base_roster->venue ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Age Group: ', 'intersoccer-reports-rosters') . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->age_group, 'pa_age-group') : $base_roster->age_group ?? 'N/A') . '</p>';
    // Display day/terms based on activity type
    if ($base_roster->activity_type === 'Tournament') {
        echo '<p>' . esc_html__('Tournament Day: ', 'intersoccer-reports-rosters') . esc_html($base_roster->course_day ?? 'N/A') . '</p>';
    } elseif ($base_roster->course_day && $base_roster->course_day !== 'N/A') {
        echo '<p>' . esc_html__('Course Day: ', 'intersoccer-reports-rosters') . esc_html($base_roster->course_day) . '</p>';
    } else {
        echo '<p>' . esc_html__('Camp Terms: ', 'intersoccer-reports-rosters') . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->camp_terms, 'pa_camp-terms') : $base_roster->camp_terms ?? 'N/A') . '</p>';
    }
    
    // Display times label based on activity type
    if ($base_roster->activity_type === 'Tournament') {
        $times_label = __('Tournament Time: ', 'intersoccer-reports-rosters');
        $times_value = $base_roster->times ?? 'N/A';
    } elseif ($is_camp_like) {
        $times_label = __('Camp Times: ', 'intersoccer-reports-rosters');
        $times_value = function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->times, 'pa_camp-times') : ($base_roster->times ?? 'N/A');
    } else {
        $times_label = __('Course Times: ', 'intersoccer-reports-rosters');
        $times_value = function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->times, 'pa_course-times') : ($base_roster->times ?? 'N/A');
    }
    echo '<p>' . esc_html($times_label) . esc_html($times_value) . '</p>';
    echo '<p>' . esc_html__('Girls Only: ', 'intersoccer-reports-rosters') . ($is_girls_only ? 'Yes' : 'No') . '</p>';
    
    // Check if roster is closed
    $is_closed = !empty($base_roster->event_completed);
    if ($is_closed) {
        echo '<p><strong style="color: #d63638;">' . esc_html__('Status: ', 'intersoccer-reports-rosters') . '</strong><span style="color: #d63638;">' . esc_html__('Closed', 'intersoccer-reports-rosters') . '</span></p>';
    }
    
    echo '<p><strong>' . esc_html__('Total Players', 'intersoccer-reports-rosters') . ':</strong> ' . esc_html(count($rosters)) . '</p>';
    
    // Close Out / Reopen button
    echo '<div style="margin-top: 20px;">';
    if ($is_closed) {
        echo '<button type="button" class="reopen-roster-btn" id="roster-reopen-btn" 
                data-event-signature="' . esc_attr($base_roster->event_signature ?? '') . '"
                title="' . esc_attr__('Reopen Roster', 'intersoccer-reports-rosters') . '">';
        echo esc_html__('Reopen', 'intersoccer-reports-rosters');
        echo '</button>';
    } else {
        echo '<button type="button" class="close-roster-btn" id="roster-close-btn"
                data-event-signature="' . esc_attr($base_roster->event_signature ?? '') . '"
                title="' . esc_attr__('Close Out Roster', 'intersoccer-reports-rosters') . '">';
        echo esc_html__('Close Out', 'intersoccer-reports-rosters');
        echo '</button>';
    }
    echo '</div>';
    
    // Add CSS for icon buttons
    echo '<style>
        .close-roster-btn, .reopen-roster-btn {
            background: #dc3232;
            color: white;
            padding: 8px 10px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            transition: all 0.2s ease;
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-indent: -9999px;
            position: relative;
        }
        .close-roster-btn::before {
            content: "\\00D7";
            position: absolute;
            text-indent: 0;
            font-size: 24px;
            font-weight: bold;
            line-height: 1;
        }
        .close-roster-btn:hover {
            background: #a00;
            transform: translateY(-1px);
        }
        .reopen-roster-btn {
            background: #46b450;
        }
        .reopen-roster-btn::before {
            content: "\\21BB";
            position: absolute;
            text-indent: 0;
            font-size: 18px;
        }
        .reopen-roster-btn:hover {
            background: #2e7d32;
            transform: translateY(-1px);
        }
    </style>';
    
    echo '</div>';
    
    // JavaScript for bulk actions and close/reopen
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Close roster handler (for roster details page)
        $(document).on('click', '.close-roster-btn, #roster-close-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var eventSignature = $btn.data('event-signature');
            
            if (!confirm('<?php echo esc_js(__('Are you sure you want to close out this roster? This will mark the event as completed.', 'intersoccer-reports-rosters')); ?>')) {
                return;
            }
            
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Closing...', 'intersoccer-reports-rosters')); ?>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_close_out_roster',
                    nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                    event_signature: eventSignature
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || '<?php echo esc_js(__('Roster closed successfully.', 'intersoccer-reports-rosters')); ?>');
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Failed to close roster.', 'intersoccer-reports-rosters')); ?>');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                    $btn.prop('disabled', false);
                }
            });
        });
        
        // Reopen roster handler (for roster details page)
        $(document).on('click', '.reopen-roster-btn, #roster-reopen-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var eventSignature = $btn.data('event-signature');
            
            if (!confirm('<?php echo esc_js(__('Are you sure you want to reopen this roster?', 'intersoccer-reports-rosters')); ?>')) {
                return;
            }
            
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Reopening...', 'intersoccer-reports-rosters')); ?>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_reopen_roster',
                    nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                    event_signature: eventSignature
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || '<?php echo esc_js(__('Roster reopened successfully.', 'intersoccer-reports-rosters')); ?>');
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Failed to reopen roster.', 'intersoccer-reports-rosters')); ?>');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                    $btn.prop('disabled', false);
                }
            });
        });
        
        const selectAll = $('#selectAll');
        const playerSelects = $('.player-select');
        const bulkActionSelect = $('#bulkActionSelect');
        const moveOptions = $('#moveOptions');
        const applyBulk = $('#applyBulk');
        const targetRosterSelect = $('#targetRosterSelect');

        // Enhanced logging for debugging
        console.log('InterSoccer Migration: JavaScript initialized');
        console.log('InterSoccer Migration: Found elements - selectAll:', selectAll.length, 'playerSelects:', playerSelects.length);

        selectAll.on('change', function() {
            console.log('InterSoccer Migration: Select all toggled:', this.checked);
            playerSelects.prop('checked', this.checked);
        });

        bulkActionSelect.on('change', function() {
            const isMove = this.value === 'move';
            console.log('InterSoccer Migration: Bulk action changed to:', this.value, 'Show move options:', isMove);
            moveOptions.toggle(isMove);
            $('#crossGenderOption').toggle(isMove);
            
            // Reset target roster selection when switching away from move
            if (!isMove) {
                targetRosterSelect.val('');
                $('#allowCrossGender').prop('checked', false);
            }
        });

        // Add validation for target roster selection
        targetRosterSelect.on('change', function() {
            const value = $(this).val().trim();
            console.log('InterSoccer Migration: Target roster selected:', value);
        });
        
        // Handle cross-gender checkbox toggle
        $('#allowCrossGender').on('change', function() {
            const isChecked = $(this).is(':checked');
            console.log('InterSoccer Migration: Cross-gender checkbox toggled:', isChecked);
            
            if (isChecked) {
                // Show cross-gender options
                $('#crossGenderRosterGroup').show();
                $('.cross-gender-option').show();
                console.log('InterSoccer Migration: Showing cross-gender roster options');
            } else {
                // Hide cross-gender options and reset selection if it was a cross-gender roster
                const currentSelection = targetRosterSelect.find('option:selected');
                if (currentSelection.data('cross-gender') === 1 || currentSelection.hasClass('cross-gender-option')) {
                    targetRosterSelect.val('');
                    console.log('InterSoccer Migration: Cleared cross-gender selection');
                }
                $('#crossGenderRosterGroup').hide();
                $('.cross-gender-option').hide();
                console.log('InterSoccer Migration: Hiding cross-gender roster options');
            }
        });

        applyBulk.on('click', function() {
            console.log('InterSoccer Migration: Apply bulk action clicked');
            
            const action = bulkActionSelect.val();
            if (action !== 'move') {
                console.log('InterSoccer Migration: No move action selected');
                return;
            }

            const targetVar = targetRosterSelect.val().trim();
            if (!targetVar) {
                alert('Please select a destination roster.');
                targetRosterSelect.focus();
                return;
            }

            const selectedItems = [];
            playerSelects.each(function() {
                if ($(this).prop('checked')) {
                    const itemId = $(this).closest('tr').data('order-item-id');
                    if (itemId) {
                        selectedItems.push(itemId);
                    }
                }
            });

            console.log('InterSoccer Migration: Selected items:', selectedItems);

            if (selectedItems.length === 0) {
                alert('No players selected.');
                return;
            }

            // Enhanced confirmation with details
            const selectedOption = targetRosterSelect.find('option:selected');
            const destinationName = selectedOption.text();
            const destinationGirlsOnly = selectedOption.data('girls-only') === '1' || selectedOption.data('girls-only') === 1;
            const sourceGirlsOnly = <?php echo $is_girls_only ? 'true' : 'false'; ?>;
            const allowCrossGender = $('#allowCrossGender').is(':checked');
            const isCrossGender = sourceGirlsOnly !== destinationGirlsOnly;
            
            // Build confirmation message
            let confirmMessage = `Move ${selectedItems.length} player(s) to:\n"${destinationName}"\n\n`;
            
            // Add gender warning if applicable
            if (isCrossGender) {
                if (sourceGirlsOnly && !destinationGirlsOnly) {
                    confirmMessage += '⚠️ WARNING: Moving from Girls Only to Regular (Mixed Gender) roster\n\n';
                } else {
                    confirmMessage += '⚠️ WARNING: Moving from Regular to Girls Only roster\n\n';
                }
            }
            
            confirmMessage += 'This will:\n';
            confirmMessage += '  ✓ Update order items to new variation\n';
            confirmMessage += '  ✓ Change roster assignment\n';
            confirmMessage += '  ✓ Preserve original pricing\n';
            confirmMessage += '  ✓ Update roster database\n\n';
            confirmMessage += 'This action cannot be undone.\n\n';
            confirmMessage += 'Continue?';
            
            if (!confirm(confirmMessage)) {
                console.log('InterSoccer Migration: Migration cancelled by user');
                return;
            }

            console.log('InterSoccer Migration: Starting AJAX request with allow_cross_gender=' + allowCrossGender);

            // Enhanced AJAX call with better error handling
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_move_players',
                    nonce: '<?php echo esc_js(wp_create_nonce('intersoccer_move_nonce')); ?>',
                    target_variation_id: parseInt(targetVar),
                    order_item_ids: selectedItems,
                    allow_cross_gender: allowCrossGender ? '1' : '0'
                },
                beforeSend: function() {
                    console.log('InterSoccer Migration: AJAX request started');
                    applyBulk.prop('disabled', true).text('Moving Players...');
                    
                    // Show progress indicator
                    $('<div id="migration-progress" style="margin-top: 10px; padding: 10px; background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px;">' +
                    '<strong>Migration in Progress...</strong><br>' +
                    'Moving ' + selectedItems.length + ' player(s) to "' + destinationName + '"...' +
                    '</div>').insertAfter(applyBulk);
                },
                success: function(response) {
                    console.log('InterSoccer Migration: AJAX success:', response);
                    
                    $('#migration-progress').remove();
                    
                    if (response.success) {
                        alert('Success: ' + response.data.message);
                        
                        // Clear selections and reset form
                        playerSelects.prop('checked', false);
                        selectAll.prop('checked', false);
                        bulkActionSelect.val('');
                        targetRosterSelect.val('');
                        moveOptions.hide();
                        
                        // Reload page to show updated data
                        console.log('InterSoccer Migration: Reloading page to show changes');
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data?.message || 'Unknown error occurred'));
                        console.error('InterSoccer Migration: Server returned error:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('InterSoccer Migration: AJAX error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                    
                    $('#migration-progress').remove();
                    
                    let errorMessage = 'AJAX error occurred.';
                    
                    // Try to parse JSON error response
                    try {
                        const errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse.data && errorResponse.data.message) {
                            errorMessage = 'Error: ' + errorResponse.data.message;
                        }
                    } catch (e) {
                        // If not JSON, use status text or generic message
                        if (xhr.status === 403) {
                            errorMessage = 'Permission denied. Please refresh the page and try again.';
                        } else if (xhr.status === 404) {
                            errorMessage = 'Migration function not found. Please check plugin configuration.';
                        } else if (xhr.status >= 500) {
                            errorMessage = 'Server error occurred. Please check the error logs.';
                        }
                    }
                    
                    alert(errorMessage);
                },
                complete: function() {
                    console.log('InterSoccer Migration: AJAX request completed');
                    applyBulk.prop('disabled', false).text('Apply');
                    $('#migration-progress').remove();
                }
            });
        });

        // Add keyboard shortcuts for better UX
        $(document).on('keydown', function(e) {
            // Ctrl+A to select all (when focused on table)
            if (e.ctrlKey && e.key === 'a' && $(e.target).closest('table').length) {
                e.preventDefault();
                selectAll.prop('checked', true).trigger('change');
            }
            
            // Escape to clear selections
            if (e.key === 'Escape') {
                playerSelects.prop('checked', false);
                selectAll.prop('checked', false);
                bulkActionSelect.val('');
                moveOptions.hide();
            }
        });

        // Export form AJAX handling with notification banners
        $('#roster-export-form').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $button = $('#roster-export-button');
            var $noticeContainer = $('#roster-export-notice');
            var originalButtonText = $button.val();
            
            // Helper function to show WordPress-style notification
            function showExportNotice(message, type) {
                type = type || 'info'; // success, error, warning, info
                var noticeClass = 'notice notice-' + type + ' is-dismissible';
                var notice = $('<div class="' + noticeClass + '"><p><strong>' + message + '</strong></p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
                
                $noticeContainer.html(notice);
                
                // Auto-dismiss after 5 seconds for success, 10 seconds for errors
                var dismissDelay = (type === 'success') ? 5000 : 10000;
                setTimeout(function() {
                    notice.fadeOut(function() {
                        $(this).remove();
                    });
                }, dismissDelay);
                
                // Handle manual dismiss
                notice.find('.notice-dismiss').on('click', function() {
                    notice.fadeOut(function() {
                        $(this).remove();
                    });
                });
                
                // Scroll to notice
                $('html, body').animate({
                    scrollTop: $noticeContainer.offset().top - 50
                }, 300);
            }
            
            // Show "Exporting..." notice
            showExportNotice('<?php echo esc_js(__('Exporting roster...', 'intersoccer-reports-rosters')); ?>', 'info');
            $button.prop('disabled', true).val('<?php echo esc_js(__('Exporting...', 'intersoccer-reports-rosters')); ?>');
            
            // Submit via AJAX
            $.ajax({
                url: typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                type: 'POST',
                data: $form.serialize(),
                timeout: 120000, // 2 minutes timeout for large exports
                success: function(response) {
                    if (response.success && response.data && response.data.content && response.data.filename) {
                        // Create and trigger download
                        try {
                            var binary = atob(response.data.content);
                            var array = new Uint8Array(binary.length);
                            for (var i = 0; i < binary.length; i++) {
                                array[i] = binary.charCodeAt(i);
                            }
                            var blob = new Blob([array], {
                                type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            });
                            var link = document.createElement("a");
                            link.href = window.URL.createObjectURL(blob);
                            link.download = response.data.filename;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            window.URL.revokeObjectURL(link.href);
                            
                            // Show success notice
                            showExportNotice('<?php echo esc_js(__('Export completed successfully!', 'intersoccer-reports-rosters')); ?>', 'success');
                        } catch (err) {
                            console.error('Export download error:', err);
                            showExportNotice('<?php echo esc_js(__('Export generated but download failed. Please try again.', 'intersoccer-reports-rosters')); ?>', 'error');
                        }
                    } else {
                        var errorMsg = response.data && response.data.message 
                            ? response.data.message 
                            : '<?php echo esc_js(__('Unknown error occurred.', 'intersoccer-reports-rosters')); ?>';
                        showExportNotice('<?php echo esc_js(__('Export failed: ', 'intersoccer-reports-rosters')); ?>' + errorMsg, 'error');
                    }
                    $button.prop('disabled', false).val(originalButtonText);
                },
                error: function(xhr, status, error) {
                    var errorMsg = '<?php echo esc_js(__('Export failed: Connection error', 'intersoccer-reports-rosters')); ?>';
                    
                    if (status === 'timeout') {
                        errorMsg = '<?php echo esc_js(__('Export timeout. The roster may be too large. Please try again or contact support.', 'intersoccer-reports-rosters')); ?>';
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = '<?php echo esc_js(__('Export failed: ', 'intersoccer-reports-rosters')); ?>' + xhr.responseJSON.data.message;
                    } else if (xhr.responseText) {
                        try {
                            var errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMsg = '<?php echo esc_js(__('Export failed: ', 'intersoccer-reports-rosters')); ?>' + errorResponse.data.message;
                            }
                        } catch (e) {
                            // Not JSON, use generic error
                        }
                    }
                    
                    showExportNotice(errorMsg, 'error');
                    $button.prop('disabled', false).val(originalButtonText);
                    console.error('Export AJAX error:', status, error, xhr.responseText);
                }
            });
        });
    });
    </script>
    <?php
}
?>