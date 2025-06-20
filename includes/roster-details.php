<?php
/**
 * Roster Details and Specific Event Pages
 *
 * Handles rendering of detailed roster views.
 *
 * @package InterSoccer Reports and Rosters
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

    error_log('InterSoccer: Base roster for variation_id ' . $variation_id . ' - activity_type: ' . $base_roster->activity_type . ', shirt_size: ' . $base_roster->shirt_size . ', shorts_size: ' . $base_roster->shorts_size);

    // For Camp activities, find all variation_ids with matching attributes except booking_type
    $related_variation_ids = [$variation_id];
    if ($base_roster->activity_type === 'Camp') {
        $related_variation_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT variation_id FROM $rosters_table
                 WHERE activity_type = 'Camp'
                 AND product_name = %s
                 AND venue = %s
                 AND camp_terms = %s
                 AND age_group = %s
                 AND variation_id != %d",
                $base_roster->product_name,
                $base_roster->venue,
                $base_roster->camp_terms,
                $base_roster->age_group,
                $variation_id
            )
        );
        $related_variation_ids[] = $variation_id; // Include the base variation_id
    }

    // Fetch all rosters for the related variation_ids
    $place_holders = implode(',', array_fill(0, count($related_variation_ids), '%d'));
    $rosters = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $rosters_table WHERE variation_id IN ($place_holders) ORDER BY player_name",
            $related_variation_ids
        )
    );

    if (!$rosters) {
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('No rosters found for variation ID(s) ', 'intersoccer-reports-rosters') . esc_html(implode(', ', $related_variation_ids)) . '.</p></div>';
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Roster Details for Variation #', 'intersoccer-reports-rosters') . esc_html(implode(', ', $related_variation_ids)) . '</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<tr>';
    echo '<th>' . esc_html__('Name') . '</th>';
    echo '<th>' . esc_html__('Surname') . '</th>';
    echo '<th>' . esc_html__('Gender') . '</th>';
    echo '<th>' . esc_html__('Phone') . '</th>';
    echo '<th>' . esc_html__('Email') . '</th>';
    echo '<th>' . esc_html__('Age') . '</th>';
    echo '<th>' . esc_html__('Medical/Dietary') . '</th>';
    if ($base_roster->activity_type === 'Camp') {
        echo '<th>' . esc_html__('Booking Type') . '</th>';
    }
    if ($base_roster->activity_type === 'Course') {
        echo '<th>' . esc_html__('Course Day') . '</th>';
    }
    if ($base_roster->activity_type === 'Girls Only') {
        echo '<th>' . esc_html__('Shirt Size') . '</th>';
        echo '<th>' . esc_html__('Shorts Size') . '</th>';
    }
    echo '</tr>';
    foreach ($rosters as $row) {
        $late_pickup_display = ($row->late_pickup === 'Yes') ? 'Yes (18:00)' : 'No';
        echo '<tr>';
        echo '<td>' . esc_html($row->first_name) . '</td>';
        echo '<td>' . esc_html($row->last_name) . '</td>';
        echo '<td>' . esc_html($row->gender) . '</td>';
        echo '<td>' . esc_html($row->parent_phone) . '</td>';
        echo '<td>' . esc_html($row->parent_email) . '</td>';
        echo '<td>' . esc_html($row->age ?? 'N/A') . '</td>';
        echo '<td>' . esc_html($row->medical_conditions ?? 'N/A') . '</td>';
        if ($base_roster->activity_type === 'Camp') {
            echo '<td>' . esc_html($row->booking_type ?? 'N/A') . '</td>';
        }
        if ($base_roster->activity_type === 'Course') {
            // Fetch Course Day from order_item_metadata if not in rosters table yet
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
    echo '</table>';
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
    echo '</div>';
}
?>
