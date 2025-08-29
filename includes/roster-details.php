<?php
/**
 * Roster Details and Specific Event Pages
 *
 * Handles rendering of detailed roster views.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.49  // Incremented for activity type and referer fix
 * @author Jeremy Lee
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once plugin_dir_path(dirname(__FILE__)) . 'includes/roster-data.php';

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
    $camp_terms = isset($_GET['camp_terms']) ? sanitize_text_field($_GET['camp_terms']) : '';
    $course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';
    $venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $times = isset($_GET['times']) ? sanitize_text_field($_GET['times']) : '';
    $product_name = isset($_GET['product_name']) ? sanitize_text_field($_GET['product_name']) : '';
    $event_dates = isset($_GET['event_dates']) ? sanitize_text_field($_GET['event_dates']) : '';
    $from_page = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
    $girls_only = isset($_GET['girls_only']) ? (bool) $_GET['girls_only'] : false;

    // Check referer and from param
    $referer = wp_get_referer();
    $is_from_camps_page = $from_page === 'camps' || strpos($referer, 'page=intersoccer-camps') !== false;
    $is_from_courses_page = $from_page === 'courses' || strpos($referer, 'page=intersoccer-courses') !== false;
    $is_from_girls_only_page = $from_page === 'girls-only' || strpos($referer, 'page=intersoccer-girls-only') !== false || $girls_only;

    error_log('InterSoccer: Roster details parameters - girls_only: ' . ($girls_only ? 'yes' : 'no') . ', from_page: ' . ($from_page ?: 'N/A'));

    // Build query - SIMPLIFIED using girls_only boolean
    $query = "SELECT r.player_name, r.first_name, r.last_name, r.gender, r.parent_phone, r.parent_email, r.age, r.medical_conditions, r.late_pickup, r.booking_type, r.course_day, r.shirt_size, r.shorts_size, r.day_presence, r.order_item_id, r.variation_id, r.age_group, r.activity_type, r.product_name, r.camp_terms, r.venue, r.times, r.product_id, r.girls_only";
    $query .= " FROM $rosters_table r";
    
    $where_clauses = [];
    $query_params = [];

    // Girls Only filtering - UPDATED to use boolean column
    if ($is_from_girls_only_page || $girls_only) {
        $where_clauses[] = "r.girls_only = 1";
    } elseif ($is_from_camps_page) {
        $where_clauses[] = "r.activity_type = 'Camp' AND r.girls_only = 0";
    } elseif ($is_from_courses_page) {
        $where_clauses[] = "r.activity_type = 'Course' AND r.girls_only = 0";
    }

    // Other filters remain the same
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

    if ($camp_terms && $camp_terms !== 'N/A') {
        $where_clauses[] = "(r.camp_terms = %s OR r.camp_terms LIKE %s OR (r.camp_terms IS NULL AND %s = 'N/A'))";
        $query_params[] = $camp_terms;
        $query_params[] = '%' . $wpdb->esc_like($camp_terms) . '%';
        $query_params[] = $camp_terms;
    }

    if ($course_day && $course_day !== 'N/A') {
        $where_clauses[] = "(r.course_day = %s OR r.course_day LIKE %s OR (r.course_day IS NULL AND %s = 'N/A'))";
        $query_params[] = $course_day;
        $query_params[] = '%' . $wpdb->esc_like($course_day) . '%';
        $query_params[] = $course_day;
    }

    if ($venue) {
        $where_clauses[] = "(r.venue = %s OR r.venue LIKE %s OR (r.venue IS NULL AND %s = 'N/A'))";
        $query_params[] = $venue;
        $query_params[] = '%' . $wpdb->esc_like($venue) . '%';
        $query_params[] = $venue;
    }

    if ($age_group) {
        $where_clauses[] = "(r.age_group = %s OR r.age_group LIKE %s)";
        $query_params[] = $age_group;
        $query_params[] = '%' . $wpdb->esc_like($age_group) . '%';
    }

    if ($times) {
        $where_clauses[] = "(r.times = %s OR (r.times IS NULL AND %s = 'N/A'))";
        $query_params[] = $times;
        $query_params[] = $times;
    }

    if (empty($where_clauses)) {
        error_log('InterSoccer: No valid parameters provided for roster details');
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('Invalid parameters provided.', 'intersoccer-reports-rosters') . '</p></div>';
        return;
    }

    $query .= " WHERE " . implode(' AND ', $where_clauses);
    $query .= " ORDER BY r.player_name, r.variation_id, r.order_item_id";
    
    $rosters = $wpdb->get_results($wpdb->prepare($query, $query_params), OBJECT);

    error_log('InterSoccer: Roster details query: ' . $wpdb->last_query);
    error_log('InterSoccer: Roster details results count: ' . count($rosters));

    if (!$rosters) {
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('No rosters found for the provided parameters.', 'intersoccer-reports-rosters') . '</p></div>';
        return;
    }

    // Get base roster for event attributes
    $base_roster = $rosters[0];
    
    // Determine if the event is camp-like - SIMPLIFIED
    $is_camp_like = ($base_roster->activity_type === 'Camp' || !empty($base_roster->camp_terms));
    $is_girls_only = (bool) $base_roster->girls_only;

    // Count Unknown Attendees
    $unknown_count = count(array_filter($rosters, fn($row) => $row->player_name === 'Unknown Attendee'));

    // Render the page
    echo '<div class="wrap">';
    $title_suffix = $is_girls_only ? ' (Girls Only)' : '';
    echo '<h1>' . esc_html__('Roster Details for ', 'intersoccer-reports-rosters') . esc_html($base_roster->course_day ?: $base_roster->camp_terms) . ' - ' . esc_html($base_roster->venue) . ' (' . esc_html($age_group) . ')' . $title_suffix . '</h1>';
    
    if ($unknown_count > 0) {
        echo '<p style="color: red;">' . esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)) . '</p>';
    }
    
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>' . esc_html__('Name') . '</th>';
    echo '<th>' . esc_html__('Surname') . '</th>';
    echo '<th>' . esc_html__('Gender') . '</th>';
    echo '<th>' . esc_html__('Phone') . '</th>';
    echo '<th>' . esc_html__('Email') . '</th>';
    echo '<th>' . esc_html__('Age') . '</th>';
    echo '<th>' . esc_html__('Medical/Dietary') . '</th>';
    
    if ($is_camp_like) {
        echo '<th>' . esc_html__('Booking Type') . '</th>';
        echo '<th>' . esc_html__('Monday') . '</th>';
        echo '<th>' . esc_html__('Tuesday') . '</th>';
        echo '<th>' . esc_html__('Wednesday') . '</th>';
        echo '<th>' . esc_html__('Thursday') . '</th>';
        echo '<th>' . esc_html__('Friday') . '</th>';
    }
    
    echo '<th>' . esc_html__('Age Group') . '</th>';
    
    if ($is_girls_only) {
        echo '<th>' . esc_html__('Shirt Size') . '</th>';
        echo '<th>' . esc_html__('Shorts Size') . '</th>';
    }
    
    echo '</tr>';
    echo '</thead><tbody>';
    
    foreach ($rosters as $row) {
        $is_unknown = $row->player_name === 'Unknown Attendee';
        $day_presence = !empty($row->day_presence) ? json_decode($row->day_presence, true) : [];
        
        echo '<tr>';
        echo '<td' . ($is_unknown ? ' style="font-style: italic; color: red;"' : '') . '>' . esc_html($row->first_name ?? 'N/A') . '</td>';
        echo '<td' . ($is_unknown ? ' style="font-style: italic; color: red;"' : '') . '>' . esc_html($row->last_name ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->gender ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->parent_phone ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->parent_email ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->age ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->medical_conditions ?? 'N/A') . '</td>';
        
        if ($is_camp_like) {
            echo '<td>' . esc_html($row->booking_type ?? 'N/A') . '</td>';
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            foreach ($days as $day) {
                $presence = $day_presence[$day] ?? 'No';
                $style = ($presence === 'Yes') ? 'background-color: green; color: black;' : 'background-color: lightpink; color: black;';
                echo '<td style="' . esc_attr($style) . '">' . esc_html($presence) . '</td>';
            }
        }
        
        echo '<td>' . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($row->age_group, 'pa_age-group') : $row->age_group ?? 'N/A') . '</td>';
        
        if ($is_girls_only) {
            echo '<td>' . esc_html($row->shirt_size ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($row->shorts_size ?? 'N/A') . '</td>';
        }
        
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    
    // Export form - UPDATED to include girls_only parameter
    echo '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '" class="export-form">';
    echo '<input type="hidden" name="action" value="intersoccer_export_roster">';
    echo '<input type="hidden" name="use_fields" value="1">';
    echo '<input type="hidden" name="camp_terms" value="' . esc_attr($camp_terms) . '">';
    echo '<input type="hidden" name="course_day" value="' . esc_attr($course_day) . '">';
    echo '<input type="hidden" name="venue" value="' . esc_attr($venue) . '">';
    echo '<input type="hidden" name="age_group" value="' . esc_attr($age_group) . '">';
    echo '<input type="hidden" name="times" value="' . esc_attr($times) . '">';
    echo '<input type="hidden" name="girls_only" value="' . ($is_girls_only ? '1' : '0') . '">';
    echo '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')) . '">';
    echo '<input type="submit" name="export_roster" class="button button-primary" value="' . esc_attr__('Export Roster', 'intersoccer-reports-rosters') . '">';
    echo '</form>';
    
    echo '<p><strong>' . esc_html__('Event Details') . ':</strong></p>';
    echo '<p>' . esc_html__('Product Name: ') . esc_html($base_roster->product_name ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Venue: ') . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->venue, 'pa_intersoccer-venues') : $base_roster->venue ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Age Group: ') . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->age_group, 'pa_age-group') : $base_roster->age_group ?? 'N/A') . '</p>';
    echo '<p>' . ($base_roster->course_day ? esc_html__('Course Day: ') . esc_html($base_roster->course_day) : esc_html__('Camp Terms: ') . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->camp_terms, 'pa_camp-terms') : $base_roster->camp_terms ?? 'N/A')) . '</p>';
    $times_label = ($is_camp_like ? __('Camp Times: ', 'intersoccer-reports-rosters') : __('Course Times: ', 'intersoccer-reports-rosters'));
    echo '<p>' . esc_html($times_label) . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($base_roster->times, 'pa_camp-times') : $base_roster->times ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Girls Only: ') . ($is_girls_only ? 'Yes' : 'No') . '</p>';
    echo '<p><strong>' . esc_html__('Total Players') . ':</strong> ' . esc_html(count($rosters)) . '</p>';
    echo '</div>';
}
?>