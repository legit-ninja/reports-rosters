<?php
/**
 * Roster Details and Specific Event Pages
 *
 * Handles rendering of detailed roster views.
 *
 * @package InterSoccer Reports and Rosters
 * @version 1.3.75
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
    $variation_id = isset($_GET['variation_id']) ? intval($_GET['variation_id']) : 0;

    if ($variation_id <= 0) {
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('Invalid variation ID.', 'intersoccer-reports-rosters') . '</p></div>';
        return;
    }

    // Fetch the base roster to get event attributes
    $base_roster = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $rosters_table WHERE variation_id = %d LIMIT 1", $variation_id)
    );

    if (!$base_roster) {
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('No rosters found for variation ID ', 'intersoccer-reports-rosters') . esc_html($variation_id) . '.</p></div>';
        return;
    }

    error_log('InterSoccer: Base roster for variation_id ' . $variation_id . ' - product_name: ' . ($base_roster->product_name ?? 'N/A') . ', venue: ' . ($base_roster->venue ?? 'N/A') . ', camp_terms: ' . ($base_roster->camp_terms ?? 'N/A') . ', age_group: ' . ($base_roster->age_group ?? 'N/A') . ', activity_type: ' . ($base_roster->activity_type ?? 'N/A'));

    // For Camp activities, find all variation_ids with matching attributes
    $related_variation_ids = [$variation_id];
    if ($base_roster->activity_type === 'Camp') {
        $related_variation_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT variation_id FROM $rosters_table
                 WHERE activity_type = 'Camp'
                 AND product_name = %s
                 AND venue = %s
                 AND camp_terms = %s",
                $base_roster->product_name,
                $base_roster->venue,
                $base_roster->camp_terms
            )
        );
    }

    error_log('InterSoccer: Related variation_ids for variation_id ' . $variation_id . ': ' . implode(', ', $related_variation_ids));

    // Fetch rosters for the related variation_ids, counting all rows
    $place_holders = implode(',', array_fill(0, count($related_variation_ids), '%d'));
    $rosters = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT player_name, first_name, last_name, gender, parent_phone, parent_email, age, medical_conditions, late_pickup, booking_type, course_day, shirt_size, shorts_size, order_item_id, variation_id, age_group
             FROM $rosters_table
             WHERE variation_id IN ($place_holders)
             ORDER BY player_name, variation_id, order_item_id",
            $related_variation_ids
        )
    );

    // Count Unknown Attendees
    $unknown_count = count(array_filter($rosters, fn($row) => $row->player_name === 'Unknown Attendee'));
    $duplicate_check = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT player_name, COUNT(*) as count, GROUP_CONCAT(order_item_id) as order_item_ids
             FROM $rosters_table
             WHERE variation_id IN ($place_holders)
             GROUP BY player_name
             HAVING count > 1",
            $related_variation_ids
        )
    );
    error_log('InterSoccer: Retrieved ' . count($rosters) . ' roster rows for variation_ids: ' . implode(', ', $related_variation_ids) . ', including ' . $unknown_count . ' Unknown Attendee entries');
    error_log('InterSoccer: Duplicate player_name check: ' . json_encode($duplicate_check));

    if (!$rosters) {
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('No rosters found for variation ID(s) ', 'intersoccer-reports-rosters') . esc_html(implode(', ', $related_variation_ids)) . '.</p></div>';
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Roster Details for Variation #', 'intersoccer-reports-rosters') . esc_html(implode(', ', $related_variation_ids)) . '</h1>';
    if ($unknown_count > 0) {
        echo '<p style="color: red;">' . esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)) . '</p>';
    }
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Name') . '</th>';
    echo '<th>' . esc_html__('Surname') . '</th>';
    echo '<th>' . esc_html__('Gender') . '</th>';
    echo '<th>' . esc_html__('Phone') . '</th>';
    echo '<th>' . esc_html__('Email') . '</th>';
    echo '<th>' . esc_html__('Age') . '</th>';
    echo '<th>' . esc_html__('Medical/Dietary') . '</th>';
    if ($base_roster->activity_type === 'Camp') {
        echo '<th>' . esc_html__('Booking Type') . '</th>';
        echo '<th>' . esc_html__('Age Group') . '</th>';
    }
    if ($base_roster->activity_type === 'Course') {
        echo '<th>' . esc_html__('Course Day') . '</th>';
    }
    if ($base_roster->activity_type === 'Girls Only') {
        echo '<th>' . esc_html__('Shirt Size') . '</th>';
        echo '<th>' . esc_html__('Shorts Size') . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rosters as $row) {
        $late_pickup_display = ($row->late_pickup === 'Yes') ? 'Yes (18:00)' : 'No';
        $is_unknown = $row->player_name === 'Unknown Attendee';
        echo '<tr>';
        echo '<td' . ($is_unknown ? ' style="font-style: italic; color: red;"' : '') . '>' . esc_html($row->first_name) . '</td>';
        echo '<td' . ($is_unknown ? ' style="font-style: italic; color: red;"' : '') . '>' . esc_html($row->last_name) . '</td>';
        echo '<td>' . esc_html($row->gender) . '</td>';
        echo '<td>' . esc_html($row->parent_phone) . '</td>';
        echo '<td>' . esc_html($row->parent_email) . '</td>';
        echo '<td>' . esc_html($row->age ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->medical_conditions ?? 'N/A') . '</td>';
        if ($base_roster->activity_type === 'Camp') {
            echo '<td>' . esc_html($row->booking_type ?? 'N/A') . '</td>';
            echo '<td>' . esc_html(intersoccer_get_term_name($row->age_group, 'pa_age-group')) . '</td>';
        }
        if ($base_roster->activity_type === 'Course') {
            $course_day = $row->course_day ?? 'N/A';
            if ($course_day === 'N/A') {
                $order_item_id = $row->order_item_id;
                $course_day_meta = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                         WHERE order_item_id = %d AND meta_key = 'Course Day' LIMIT 1",
                        $order_item_id
                    )
                );
                $course_day = $course_day_meta ?: 'N/A';
            }
            echo '<td>' . esc_html($course_day) . '</td>';
        }
        if ($base_roster->activity_type === 'Girls Only') {
            echo '<td>' . esc_html($row->shirt_size ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($row->shorts_size ?? 'N/A') . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p><strong>' . esc_html__('Late Pickup') . ':</strong> ' . esc_html($late_pickup_display) . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '" class="export-form">';
    echo '<input type="hidden" name="action" value="intersoccer_export_roster">';
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
    if ($base_roster->activity_type === 'Camp') {
        echo '<p>' . esc_html__('Camp Terms: ') . esc_html($base_roster->camp_terms ?? 'N/A') . '</p>';
    }
    echo '<p><strong>' . esc_html__('Total Players') . ':</strong> ' . esc_html(count($rosters)) . '</p>';
}
?>