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
    echo '<p><strong>' . esc_html__('Late Pickup') . ':</strong> ' . esc_html($late_pickup_display) . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-ajax.php')) . '" class="export-form">';
    echo '<input type="hidden" name="action" value="intersoccer_export_roster">';
    echo '<input type="hidden" name="variation_ids[]" value="' . esc_attr($variation_id) . '">';
    echo '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')) . '">';
    echo '<input type="submit" name="export_roster" class="button button-primary" value="' . esc_attr__('Export Roster', 'intersoccer-reports-rosters') . '">';
    echo '</form>';
    echo '<p><strong>' . esc_html__('Event Details') . ':</strong></p>';
    echo '<p>' . esc_html__('Product Name: ') . esc_html($rosters[0]->product_name ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Venue: ') . esc_html($rosters[0]->venue ?? 'N/A') . '</p>';
    echo '<p>' . esc_html__('Age Group: ') . esc_html($rosters[0]->age_group ?? 'N/A') . '</p>';
    if ($rosters[0]->activity_type === 'Camp') {
        echo '<p>' . esc_html__('Camp Terms: ') . esc_html($rosters[0]->camp_terms ?? 'N/A') . '</p>';
    }
    echo '<p><strong>' . esc_html__('Total Players') . ':</strong> ' . esc_html(count($rosters)) . '</p>';
    echo '</div>';
}
?>
