<?php
/**
 * Roster Details and Specific Event Pages
 *
 * Handles rendering of detailed roster views.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.16
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
    $posts_table = $wpdb->prefix . 'posts';

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

    // Check if the request comes from the Camps or Courses sub-page
    $referer = wp_get_referer();
    $is_from_camps_page = strpos($referer, 'page=intersoccer-camps') !== false;
    $is_from_courses_page = strpos($referer, 'page=intersoccer-courses') !== false;

    error_log('InterSoccer: Roster details parameters - product_id: ' . $product_id . ', variation_id: ' . $variation_id . ', camp_terms: ' . ($camp_terms ?: 'N/A') . ', course_day: ' . ($course_day ?: 'N/A') . ', venue: ' . ($venue ?: 'N/A') . ', age_group: ' . ($age_group ?: 'N/A') . ', times: ' . ($times ?: 'N/A') . ', is_from_camps_page: ' . ($is_from_camps_page ? 'yes' : 'no') . ', is_from_courses_page: ' . ($is_from_courses_page ? 'yes' : 'no'));

    // Log table schema and available variations
    $schema = $wpdb->get_results("SHOW COLUMNS FROM $rosters_table");
    error_log('InterSoccer: wp_intersoccer_rosters schema: ' . json_encode($schema));
    $all_variations = $wpdb->get_results("SELECT DISTINCT variation_id, product_id, activity_type, age_group, camp_terms, course_day, venue FROM $rosters_table", ARRAY_A);
    error_log('InterSoccer: All available variations in wp_intersoccer_rosters: ' . json_encode($all_variations));

    // Build query based on parameters
    $query_params = [];
    $query = "SELECT r.player_name, r.first_name, r.last_name, r.gender, r.parent_phone, r.parent_email, r.age, r.medical_conditions, r.late_pickup, r.booking_type, r.course_day, r.shirt_size, r.shorts_size, r.day_presence, r.order_item_id, r.variation_id, r.age_group, r.activity_type, r.product_name, r.camp_terms, r.venue, r.times, r.product_id";
    $query .= " FROM $rosters_table r";
    
    $where_clauses = [];
    if ($product_id > 0) {
        $where_clauses[] = $wpdb->prepare("r.product_id = %d", $product_id);
        $query_params[] = $product_id;
    }

    if ($product_name) {
        $where_clauses[] = $wpdb->prepare("r.product_name = %s", $product_name);
        $query_params[] = $product_name;
        if ($event_dates && $event_dates !== 'N/A') {
            $where_clauses[] = $wpdb->prepare("r.event_dates = %s", $event_dates);
            $query_params[] = $event_dates;
        }
        if ($venue) {
            $where_clauses[] = $wpdb->prepare("r.venue = %s", $venue);
            $query_params[] = $venue;
        }
        if ($age_group) {
            $where_clauses[] = $wpdb->prepare("(r.age_group = %s OR r.age_group LIKE %s)", $age_group, '%' . $wpdb->esc_like($age_group) . '%');
            $query_params[] = $age_group;
            $query_params[] = '%' . $age_group . '%';
        }
        if ($times) {
            $where_clauses[] = $wpdb->prepare("r.times = %s", $times);
            $query_params[] = $times;
        }
    } else {
        if ($variation_id > 0) {
            $where_clauses[] = $wpdb->prepare("r.variation_id = %d", $variation_id);
            $query_params[] = $variation_id;
            if ($age_group) {
                $where_clauses[] = $wpdb->prepare("(r.age_group = %s OR r.age_group LIKE %s)", $age_group, '%' . $wpdb->esc_like($age_group) . '%');
                $query_params[] = $age_group;
                $query_params[] = '%' . $age_group . '%';
            }
        } elseif ($camp_terms || $course_day || $venue || $age_group) {
            // Use 'Camp' for Camps sub-page, 'Course' for Courses sub-page, otherwise include all
            $activity_types = $is_from_camps_page ? ['Camp'] : ($is_from_courses_page ? ['Course'] : ['Camp', 'Course', 'Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only']);
            $where_clauses[] = "r.activity_type IN ('" . implode("','", array_map('esc_sql', $activity_types)) . "')";
            if ($camp_terms) {
                $where_clauses[] = $wpdb->prepare("(r.camp_terms = %s OR r.camp_terms LIKE %s OR (r.camp_terms IS NULL AND %s = 'N/A'))", $camp_terms, '%' . $wpdb->esc_like($camp_terms) . '%', $camp_terms);
                $query_params[] = $camp_terms;
                $query_params[] = '%' . $camp_terms . '%';
                $query_params[] = $camp_terms;
            }
            if ($course_day) {
                $where_clauses[] = $wpdb->prepare("(r.course_day = %s OR r.course_day LIKE %s OR (r.course_day IS NULL AND %s = 'N/A'))", $course_day, '%' . $wpdb->esc_like($course_day) . '%', $course_day);
                $query_params[] = $course_day;
                $query_params[] = '%' . $course_day . '%';
                $query_params[] = $course_day;
            }
            if ($venue) {
                $where_clauses[] = $wpdb->prepare("(r.venue = %s OR r.venue LIKE %s OR (r.venue IS NULL AND %s = 'N/A'))", $venue, '%' . $wpdb->esc_like($venue) . '%', $venue);
                $query_params[] = $venue;
                $query_params[] = '%' . $venue . '%';
                $query_params[] = $venue;
            }
            if ($age_group) {
                $where_clauses[] = $wpdb->prepare("(r.age_group = %s OR r.age_group LIKE %s)", $age_group, '%' . $wpdb->esc_like($age_group) . '%');
                $query_params[] = $age_group;
                $query_params[] = '%' . $age_group . '%';
            }
            if ($times) {
                $where_clauses[] = $wpdb->prepare("(r.times = %s OR (r.times IS NULL AND %s = 'N/A'))", $times, $times);
                $query_params[] = $times;
                $query_params[] = $times;
            }
        }
    }

    if (!empty($where_clauses)) {
        $query .= " WHERE " . implode(' AND ', $where_clauses);
    } else {
        error_log('InterSoccer: No valid parameters provided for roster details');
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('Invalid parameters provided.', 'intersoccer-reports-rosters') . '</p></div>';
        return;
    }

    $query .= " ORDER BY r.player_name, r.variation_id, r.order_item_id";
    $rosters = $wpdb->get_results($wpdb->prepare($query, $query_params), OBJECT);

    error_log('InterSoccer: Roster details query: ' . $wpdb->last_query);
    error_log('InterSoccer: Roster details results count: ' . count($rosters));
    error_log('InterSoccer: Last SQL error: ' . $wpdb->last_error);
    if ($rosters) {
        error_log('InterSoccer: Roster data sample: ' . json_encode(array_map(function($row) {
            $day_presence = !empty($row->day_presence) ? json_decode($row->day_presence, true) : [];
            $days = !empty($day_presence) ? implode(',', array_keys(array_filter($day_presence, fn($v) => $v === 'Yes'))) : 'N/A';
            return [
                'order_item_id' => $row->order_item_id,
                'player_name' => $row->player_name,
                'booking_type' => $row->booking_type,
                'shirt_size' => $row->shirt_size,
                'shorts_size' => $row->shorts_size,
                'day_presence' => $days,
                'activity_type' => $row->activity_type,
                'variation_id' => $row->variation_id,
                'product_id' => $row->product_id
            ];
        }, $rosters))); // Log all records to detect duplicates
    }

    if (!$rosters) {
        // Fallback query to debug
        $fallback_query = "SELECT r.player_name, r.first_name, r.last_name, r.variation_id, r.age_group, r.activity_type, r.camp_terms, r.course_day, r.venue, r.booking_type, r.shirt_size, r.shorts_size, r.day_presence, r.order_item_id, r.product_id FROM $rosters_table r LIMIT 5";
        $fallback_results = $wpdb->get_results($fallback_query, OBJECT);
        error_log('InterSoccer: Fallback query: ' . $fallback_query);
        error_log('InterSoccer: Fallback results: ' . json_encode(array_map(function($row) {
            return [
                'order_item_id' => $row->order_item_id,
                'player_name' => $row->player_name,
                'variation_id' => $row->variation_id,
                'age_group' => $row->age_group,
                'activity_type' => $row->activity_type,
                'camp_terms' => $row->camp_terms,
                'course_day' => $row->course_day,
                'venue' => $row->venue,
                'booking_type' => $row->booking_type,
                'shirt_size' => $row->shirt_size,
                'shorts_size' => $row->shorts_size,
                'day_presence' => $row->day_presence,
                'product_id' => $row->product_id
            ];
        }, $fallback_results)));
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('No rosters found for the provided parameters. Try reconciling rosters.', 'intersoccer-reports-rosters') . '</p>';
        echo '<p>' . esc_html__('Debug Info: Check wp-content/debug.log for available variations and query details.', 'intersoccer-reports-rosters') . '</p></div>';
        return;
    }

    // Get base roster for event attributes
    $base_roster = $rosters[0];

    // Fetch related variation IDs for export, filtered by parent product ID
    $related_variation_ids = [$variation_id];
    if (!$variation_id && ($camp_terms || $course_day || $venue || $age_group)) {
        $variation_query = $wpdb->prepare(
            "SELECT DISTINCT r.variation_id 
             FROM $rosters_table r
             WHERE r.activity_type IN ('" . implode("','", array_map('esc_sql', $activity_types)) . "')
             AND r.product_id = %d",
            $product_id ?: $base_roster->product_id
        );
        $variation_params = [$product_id ?: $base_roster->product_id];
        if ($camp_terms) {
            $variation_query .= $wpdb->prepare(" AND (r.camp_terms = %s OR r.camp_terms LIKE %s OR (r.camp_terms IS NULL AND %s = 'N/A'))", $camp_terms, '%' . $wpdb->esc_like($camp_terms) . '%', $camp_terms);
            $variation_params[] = $camp_terms;
            $variation_params[] = '%' . $camp_terms . '%';
            $variation_params[] = $camp_terms;
        }
        if ($course_day) {
            $variation_query .= $wpdb->prepare(" AND (r.course_day = %s OR r.course_day LIKE %s OR (r.course_day IS NULL AND %s = 'N/A'))", $course_day, '%' . $wpdb->esc_like($course_day) . '%', $course_day);
            $variation_params[] = $course_day;
            $variation_params[] = '%' . $course_day . '%';
            $variation_params[] = $course_day;
        }
        if ($venue) {
            $variation_query .= $wpdb->prepare(" AND (r.venue = %s OR r.venue LIKE %s OR (r.venue IS NULL AND %s = 'N/A'))", $venue, '%' . $wpdb->esc_like($venue) . '%', $venue);
            $variation_params[] = $venue;
            $variation_params[] = '%' . $venue . '%';
            $variation_params[] = $venue;
        }
        if ($age_group) {
            $variation_query .= $wpdb->prepare(" AND (r.age_group = %s OR r.age_group LIKE %s)", $age_group, '%' . $wpdb->esc_like($age_group) . '%');
            $variation_params[] = $age_group;
            $variation_params[] = '%' . $age_group . '%';
        }
        if ($times) {
            $variation_query .= $wpdb->prepare(" AND (r.times = %s OR (r.times IS NULL AND %s = 'N/A'))", $times, $times);
            $variation_params[] = $times;
            $variation_params[] = $times;
        }
        $related_variation_ids = $wpdb->get_col($wpdb->prepare($variation_query, $variation_params));
        $related_variation_ids = array_map('intval', array_filter($related_variation_ids, 'is_numeric'));
    } elseif ($variation_id > 0) {
        $variation_query = $wpdb->prepare(
            "SELECT DISTINCT r.variation_id 
             FROM $rosters_table r
             WHERE r.activity_type IN ('Camp', 'Course', 'Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only') 
             AND r.product_id = (SELECT product_id FROM $rosters_table WHERE variation_id = %d LIMIT 1)
             AND r.venue = %s 
             AND (r.camp_terms = %s OR r.course_day = %s)",
            $variation_id,
            $base_roster->venue,
            $base_roster->camp_terms,
            $base_roster->course_day
        );
        $variation_params = [$variation_id, $base_roster->venue, $base_roster->camp_terms, $base_roster->course_day];
        if ($age_group) {
            $variation_query .= $wpdb->prepare(" AND (r.age_group = %s OR r.age_group LIKE %s)", $age_group, '%' . $wpdb->esc_like($age_group) . '%');
            $variation_params[] = $age_group;
            $variation_params[] = '%' . $age_group . '%';
        }
        if ($times) {
            $variation_query .= $wpdb->prepare(" AND (r.times = %s OR (r.times IS NULL AND %s = 'N/A'))", $times, $times);
            $variation_params[] = $times;
            $variation_params[] = $times;
        }
        $related_variation_ids = $wpdb->get_col($wpdb->prepare($variation_query, $variation_params));
        $related_variation_ids = array_map('intval', array_filter($related_variation_ids, 'is_numeric'));
    }

    error_log('InterSoccer: Related variation_ids: ' . implode(', ', $related_variation_ids));
    error_log('InterSoccer: Base roster product_id: ' . ($base_roster->product_id ?? 'N/A'));

    // Count Unknown Attendees
    $unknown_count = count(array_filter($rosters, fn($row) => $row->player_name === 'Unknown Attendee'));

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Roster Details for ', 'intersoccer-reports-rosters') . esc_html($base_roster->course_day ?: $base_roster->camp_terms) . ' - ' . esc_html($base_roster->venue) . ' (' . esc_html($age_group) . ')</h1>';
    if ($unknown_count > 0) {
        echo '<p style="color: red;">' . esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)) . '</p>';
    }
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead>';
    // First row of headers with rowspans and colspans
    echo '<tr>';
    echo '<th rowspan="2">' . esc_html__('Name') . '</th>';
    echo '<th rowspan="2">' . esc_html__('Surname') . '</th>';
    echo '<th rowspan="2">' . esc_html__('Gender') . '</th>';
    echo '<th rowspan="2">' . esc_html__('Phone') . '</th>';
    echo '<th rowspan="2">' . esc_html__('Email') . '</th>';
    echo '<th rowspan="2">' . esc_html__('Age') . '</th>';
    echo '<th rowspan="2">' . esc_html__('Medical/Dietary') . '</th>';
    $is_camp_like = ($base_roster->activity_type === 'Camp' || strpos($base_roster->activity_type, 'Girls Only') !== false || strpos($base_roster->activity_type, 'Girls\' only') !== false);
    if ($is_camp_like) {
        echo '<th rowspan="2">' . esc_html__('Booking Type') . '</th>';
        echo '<th colspan="5">' . esc_html__('Days of Week') . '</th>';
    }
    echo '<th rowspan="2">' . esc_html__('Age Group') . '</th>';
    if (strpos($base_roster->activity_type, 'Girls Only') !== false || strpos($base_roster->activity_type, 'Girls\' only') !== false) {
        echo '<th rowspan="2">' . esc_html__('Shirt Size') . '</th>';
        echo '<th rowspan="2">' . esc_html__('Shorts Size') . '</th>';
    }
    echo '</tr>';
    // Second row for day sub-headers
    echo '<tr>';
    if ($is_camp_like) {
        echo '<th>Mon</th>';
        echo '<th>Tue</th>';
        echo '<th>Wed</th>';
        echo '<th>Thu</th>';
        echo '<th>Fri</th>';
    }
    echo '</tr>';
    echo '</thead><tbody>';
    foreach ($rosters as $row) {
        $late_pickup_display = ($row->late_pickup === 'Yes') ? 'Yes (18:00)' : 'No';
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
        echo '<td>' . esc_html(intersoccer_get_term_name($row->age_group, 'pa_age-group') ?? 'N/A') . '</td>';
        if (strpos($base_roster->activity_type, 'Girls Only') !== false || strpos($base_roster->activity_type, 'Girls\' only') !== false) {
            echo '<td>' . esc_html($row->shirt_size ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($row->shorts_size ?? 'N/A') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p><strong>' . esc_html__('Late Pickup') . ':</strong> ' . esc_html($late_pickup_display) . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '" class="export-form">';
    echo '<input type="hidden" name="action" value="intersoccer_export_roster">';
    echo '<input type="hidden" name="use_fields" value="1">';
    echo '<input type="hidden" name="camp_terms" value="' . esc_attr($camp_terms) . '">';
    echo '<input type="hidden" name="course_day" value="' . esc_attr($course_day) . '">';
    echo '<input type="hidden" name="venue" value="' . esc_attr($venue) . '">';
    echo '<input type="hidden" name="age_group" value="' . esc_attr($age_group) . '">';
    echo '<input type="hidden" name="times" value="' . esc_attr($times) . '">';
    echo '<input type="hidden" name="activity_types" value="' . esc_attr(implode(',', $activity_types)) . '">';
    foreach ($related_variation_ids as $id) {
        echo '<input type="hidden" name="variation_ids[]" value="' . esc_attr($id) . '">';
    }
    echo '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')) . '">';
    echo '<input type="submit" name="export_roster" class="button button-primary" value="' . esc_attr__('Export Roster', 'intersoccer-reports-rosters') . '">';
    echo '</form>';
    echo '<p><strong>' . esc_html__('Event Details') . ':</strong></p>';
    echo '<p>' . esc_html__('Product Name: ') . esc_html($base_roster->product_name ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Venue: ') . esc_html($base_roster->venue ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Age Group: ') . esc_html($base_roster->age_group ?? 'N/A') . '</p>';
    echo '<p>' . ($base_roster->course_day ? esc_html__('Course Day: ') . esc_html($base_roster->course_day) : esc_html__('Camp Terms: ') . esc_html($base_roster->camp_terms ?? 'N/A')) . '</p>';
    $times_label = ($base_roster->activity_type === 'Course') ? __('Course Times: ', 'intersoccer-reports-rosters') : __('Camp Times: ', 'intersoccer-reports-rosters');
    echo '<p>' . esc_html($times_label) . esc_html($base_roster->times ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Variation IDs: ') . esc_html(implode(', ', $related_variation_ids) ?: 'N/A') . '</p>';
    echo '<p>' . esc_html__('Parent Product ID: ') . esc_html($base_roster->product_id ?? 'N/A') . '</p>'; // Added for debug
    echo '<p><strong>' . esc_html__('Total Players') . ':</strong> ' . esc_html(count($rosters)) . '</p>';
    echo '</div>';
}
?>