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
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $order_item_id = isset($_GET['order_item_id']) ? intval($_GET['order_item_id']) : 0;

    if ($order_item_id <= 0) {
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('Invalid order item ID.', 'intersoccer-reports-rosters') . '</p></div>';
        return;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $rosters_table WHERE order_item_id = %d", $order_item_id)
    );

    if (!$row) {
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('No roster found for order item ID ', 'intersoccer-reports-rosters') . esc_html($order_item_id) . '.</p></div>';
        return;
    }

    $day_presence = json_decode($row->day_presence, true);
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Roster Details for Order Item #', 'intersoccer-reports-rosters') . esc_html($order_item_id) . '</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<tr><th>' . esc_html__('Field') . '</th><th>' . esc_html__('Value') . '</th></tr>';
    echo '<tr><td>' . esc_html__('Player Name') . '</td><td>' . esc_html($row->player_name) . '</td></tr>';
    echo '<tr><td>' . esc_html__('First Name') . '</td><td>' . esc_html($row->first_name) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Last Name') . '</td><td>' . esc_html($row->last_name) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Age') . '</td><td>' . esc_html($row->age ?? 'N/A') . '</td></tr>';
    echo '<tr><td>' . esc_html__('Gender') . '</td><td>' . esc_html($row->gender) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Booking Type') . '</td><td>' . esc_html($row->booking_type) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Selected Days') . '</td><td>' . esc_html(implode(', ', array_keys(array_filter($day_presence, function($v) { return $v === 'Yes'; })))) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Camp Terms') . '</td><td>' . esc_html($row->camp_terms) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Venue') . '</td><td>' . esc_html($row->venue) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Parent Phone') . '</td><td>' . esc_html($row->parent_phone) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Parent Email') . '</td><td>' . esc_html($row->parent_email) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Medical Conditions') . '</td><td>' . esc_html($row->medical_conditions) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Late Pickup') . '</td><td>' . esc_html($row->late_pickup) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Age Group') . '</td><td>' . esc_html($row->age_group) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Start Date') . '</td><td>' . esc_html($row->start_date ?? 'N/A') . '</td></tr>';
    echo '<tr><td>' . esc_html__('End Date') . '</td><td>' . esc_html($row->end_date ?? 'N/A') . '</td></tr>';
    echo '<tr><td>' . esc_html__('Event Dates') . '</td><td>' . esc_html($row->event_dates) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Product Name') . '</td><td>' . esc_html($row->product_name) . '</td></tr>';
    echo '<tr><td>' . esc_html__('Activity Type') . '</td><td>' . esc_html($row->activity_type) . '</td></tr>';
    echo '</table>';
    echo '</div>';
}
?>
