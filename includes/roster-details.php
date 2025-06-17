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

    $rosters = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $rosters_table WHERE variation_id = %d ORDER BY player_name", $variation_id)
    );

    if (!$rosters) {
        echo '<div class="wrap"><h1>' . esc_html__('Roster Details', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('No rosters found for variation ID ', 'intersoccer-reports-rosters') . esc_html($variation_id) . '.</p></div>';
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Roster Details for Variation #', 'intersoccer-reports-rosters') . esc_html($variation_id) . '</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<tr><th>' . esc_html__('Name') . '</th><th>' . esc_html__('Surname') . '</th><th>' . esc_html__('Gender') . '</th><th>' . esc_html__('Phone') . '</th><th>' . esc_html__('Email') . '</th><th>' . esc_html__('Age') . '</th><th>' . esc_html__('Medical/Dietary') . '</th></tr>';
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
        echo '</tr>';
    }
    echo '</table>';
    // Display late pickup summary if applicable
    $late_pickup_count = count(array_filter(array_column((array)$rosters, 'late_pickup'), function($v) { return $v === 'Yes'; }));
    if ($late_pickup_count > 0) {
        echo '<p><strong>' . esc_html__('Late Pickup (18:00)') . ':</strong> ' . esc_html($late_pickup_count) . ' attendee(s)</p>';
    }
    echo '</div>';
}
?>
