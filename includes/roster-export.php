<?php
/**
 * Roster export functionality for InterSoccer Reports and Rosters plugin.
 */

// Export roster to CSV
function intersoccer_export_roster_to_csv($variation_id) {
    // Log the start of the export process
    error_log('InterSoccer: Starting CSV export for Variation ID ' . $variation_id);

    // Clear all existing output buffers to prevent any prior output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Start a new output buffer to ensure clean output
    ob_start();

    // Fetch the roster data
    $roster = intersoccer_pe_get_event_roster_by_variation($variation_id);

    if (empty($roster)) {
        error_log('InterSoccer: No roster data to export for Variation ID ' . $variation_id);
        // Output a message to the CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="roster_variation_' . $variation_id . '_' . date('Y-m-d_H-i-s') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Error', 'No roster data found for this event']);
        fclose($output);
        ob_end_flush();
        exit;
    }

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="roster_variation_' . $variation_id . '_' . date('Y-m-d_H-i-s') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Define CSV headers
    $headers = [
        'Player Name',
        'Age',
        'Gender',
        'Booking Type',
        'Selected Days',
        'Venue',
        'Region',
        'Parent Country',
        'Parent State',
        'Parent City',
    ];

    // Write headers to CSV
    fputcsv($output, $headers);

    // Write roster data to CSV
    foreach ($roster as $row) {
        $csv_row = [
            $row['player_name'],
            $row['age'],
            $row['gender'],
            $row['booking_type'],
            implode(', ', $row['selected_days']),
            $row['venue'],
            $row['region'],
            $row['parent_country'],
            $row['parent_state'],
            $row['parent_city'],
        ];
        fputcsv($output, $csv_row);
    }

    // Close the output stream
    fclose($output);

    // End output buffering and flush
    ob_end_flush();

    // Log the completion of the export
    error_log('InterSoccer: Completed CSV export for Variation ID ' . $variation_id);

    // Exit to prevent any additional output
    exit;
}
?>