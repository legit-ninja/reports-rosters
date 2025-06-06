<?php
/**
 * Export functionality for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Export a single roster to CSV
function intersoccer_export_roster_to_csv($variation_id) {
    if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
        wp_die(__('You do not have sufficient permissions to export this roster.', 'intersoccer-reports-rosters'));
    }

    if (ob_get_length()) {
        ob_end_clean();
    }

    $variation = wc_get_product($variation_id);
    if (!$variation || $variation->get_type() !== 'variation') {
        wp_die(__('Invalid variation ID.', 'intersoccer-reports-rosters'));
    }

    $product_id = $variation->get_parent_id();
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_die(__('Invalid parent product ID.', 'intersoccer-reports-rosters'));
    }

    $roster = intersoccer_pe_get_event_roster_by_variation($variation_id);

    $event_name = $product->get_name();
    $region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
    $venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
    $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
    $season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? 'N/A';
    $city = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names'])[0] ?? 'Unknown';
    $is_camp = in_array($booking_type, ['Full Week', 'single-days']);
    $camp_term = '';
    if ($is_camp) {
        $camp_term = wc_get_product_terms($product_id, 'pa_week', ['fields' => 'names'])[0] ?? '';
        if (!$camp_term) {
            $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? '';
            if ($start_date) {
                $date = DateTime::createFromFormat('d/m/Y', $start_date);
                if ($date) {
                    $camp_term = 'Week ' . $date->format('W');
                }
            }
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="intersoccer_roster_variation_' . $variation_id . '_' . date('Y-m-d_H-i-s') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    $headers = [
        __('Event Name', 'intersoccer-reports-rosters'),
        __('Region', 'intersoccer-reports-rosters'),
        __('Venue', 'intersoccer-reports-rosters'),
        __('Booking Type', 'intersoccer-reports-rosters'),
        __('Season', 'intersoccer-reports-rosters'),
        __('City', 'intersoccer-reports-rosters'),
        __('Camp Term', 'intersoccer-reports-rosters'),
        __('First Name', 'intersoccer-reports-rosters'),
        __('Last Name', 'intersoccer-reports-rosters'),
        __('Gender', 'intersoccer-reports-rosters'),
        __('Parent Phone', 'intersoccer-reports-rosters'),
        __('Parent Email', 'intersoccer-reports-rosters'),
        __('Medical/Dietary', 'intersoccer-reports-rosters'),
        __('Late Pick-Up (18h)', 'intersoccer-reports-rosters'),
        __('Selected Days/Discount Info', 'intersoccer-reports-rosters'),
    ];
    fputcsv($output, $headers);

    foreach ($roster as $player) {
        $row = [
            $event_name,
            $region,
            $venue,
            $booking_type,
            $season,
            $city,
            $is_camp ? $camp_term : '',
            $player['first_name'],
            $player['last_name'],
            $player['gender'],
            $player['parent_phone'],
            $player['parent_email'],
            $player['medical_conditions'],
            $player['late_pickup'] === '18h' ? __('Yes', 'intersoccer-reports-rosters') : __('No', 'intersoccer-reports-rosters'),
            $is_camp ? implode(', ', $player['selected_days']) : $player['discount_info'],
        ];
        fputcsv($output, $row);
    }

    if (!empty($roster)) {
        fputcsv($output, [__('Total Players:', 'intersoccer-reports-rosters') . ' ' . count($roster)]);
    } else {
        fputcsv($output, [__('No players found for this event.', 'intersoccer-reports-rosters')]);
    }

    fclose($output);
    intersoccer_log_audit('export_roster_csv', "Exported CSV roster for Variation ID $variation_id");
    exit;
}

// Export a single roster to PDF
function intersoccer_export_roster_to_pdf($variation_id) {
    if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
        wp_die(__('You do not have sufficient permissions to export this roster.', 'intersoccer-reports-rosters'));
    }

    if (ob_get_length()) {
        ob_end_clean();
    }

    $variation = wc_get_product($variation_id);
    if (!$variation || $variation->get_type() !== 'variation') {
        wp_die(__('Invalid variation ID.', 'intersoccer-reports-rosters'));
    }

    $product_id = $variation->get_parent_id();
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_die(__('Invalid parent product ID.', 'intersoccer-reports-rosters'));
    }

    $roster = intersoccer_pe_get_event_roster_by_variation($variation_id);

    $event_name = $product->get_name();
    $region = wc_get_product_terms($product_id, 'pa_canton-region', ['fields' => 'names'])[0] ?? 'Unknown';
    $venue = wc_get_product_terms($product_id, 'pa_intersoccer-venues', ['fields' => 'names'])[0] ?? 'Unknown';
    $booking_type = wc_get_product_terms($product_id, 'pa_booking-type', ['fields' => 'names'])[0] ?? 'Unknown';
    $season = wc_get_product_terms($product_id, 'pa_season', ['fields' => 'names'])[0] ?? 'N/A';
    $city = wc_get_product_terms($product_id, 'pa_city', ['fields' => 'names'])[0] ?? 'Unknown';
    $is_camp = in_array($booking_type, ['Full Week', 'single-days']);
    $camp_term = '';
    if ($is_camp) {
        $camp_term = wc_get_product_terms($product_id, 'pa_week', ['fields' => 'names'])[0] ?? '';
        if (!$camp_term) {
            $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? '';
            if ($start_date) {
                $date = DateTime::createFromFormat('d/m/Y', $start_date);
                if ($date) {
                    $camp_term = 'Week ' . $date->format('W');
                }
            }
        }
    }

    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF();
            const eventName = <?php echo json_encode($event_name); ?>;
            const rosterData = <?php echo json_encode(array_map(function($player) use ($is_camp) {
                return [
                    $player['first_name'],
                    $player['last_name'],
                    $player['gender'],
                    $player['parent_phone'],
                    $player['parent_email'],
                    $player['medical_conditions'],
                    $player['late_pickup'] === '18h' ? '<?php _e('Yes', 'intersoccer-reports-rosters'); ?>' : '<?php _e('No', 'intersoccer-reports-rosters'); ?>',
                    $is_camp ? implode(', ', $player['selected_days']) : $player['discount_info'],
                ];
            }, $roster)); ?>;

            doc.text('<?php _e('Roster:', 'intersoccer-reports-rosters'); ?> ' + eventName, 10, 6);
            doc.autoTable({
                startY: 15,
                head: [[
                    '<?php _e('First Name', 'intersoccer-reports-rosters'); ?>',
                    '<?php _e('Last Name', 'intersoccer-reports-rosters'); ?>',
                    '<?php _e('Gender', 'intersoccer-reports-rosters'); ?>',
                    '<?php _e('Phone', 'intersoccer-reports-rosters'); ?>',
                    '<?php _e('Email', 'intersoccer-reports-rosters'); ?>',
                    '<?php _e('Medical/Dietary', 'intersoccer-reports-rosters'); ?>',
                    '<?php _e('Late Pick-Up (18h)', 'intersoccer-reports-rosters'); ?>',
                    '<?php echo $is_camp ? __('Selected Days', 'intersoccer-reports-rosters') : __('Discount Info', 'intersoccer-reports-rosters'); ?>',
                ]],
                body: rosterData,
                styles: { fontSize: 8, cellPadding: 2 },
                columnStyles: { 
                    0: { cellWidth: 20 }, 
                    1: { cellWidth: 20 }, 
                    4: { cellWidth: 30 }, 
                    5: { cellWidth: 30 }, 
                },
                didDrawPage: function (data) {
                    doc.text('<?php _e('Region:', 'intersoccer-reports-rosters'); ?> <?php echo esc_js($region); ?>', 10, doc.internal.pageSize.height - 10);
                    doc.text('<?php _e('Venue:', 'intersoccer-reports-rosters'); ?> <?php echo esc_js($venue); ?>', 50, doc.internal.pageSize.height - 10);
                    <?php if ($is_camp && $camp_term): ?>
                        doc.text('<?php _e('Camp Term:', 'intersoccer-reports-rosters'); ?> <?php echo esc_js($camp_term); ?>', 90, doc.internal.pageSize.height - 10);
                    <?php endif; ?>
                    doc.text('Page ' + doc.internal.getNumberOfPages(), 180, doc.internal.pageSize.height - 10);
                },
            });

            doc.save(eventName.replace(/[^a-zA-Z0-9]/g, '-') + '_roster_' + new Date().toISOString().slice(0, 10) + '.pdf');
            window.location.href = '<?php echo json_encode(esc_url(remove_query_arg(['action', '_wpnonce']))); ?>';
        });
    </script>
    <?php
    intersoccer_log_audit('export_roster_pdf', "Exported PDF roster for Variation ID $variation_id");
    exit;
}

// Export all rosters to CSV
function intersoccer_export_all_rosters_to_csv($camps, $courses, $export_type = 'all') {
    if (!current_user_can('coach') && !current_user_can('event_organizer') && !current_user_can('shop_manager') && !current_user_can('administrator')) {
        wp_die(__('You do not have sufficient permissions to export rosters.', 'intersoccer-reports-rosters'));
    }

    if (ob_get_length()) {
        ob_end_clean();
    }

    $filename_suffix = 'master_rosters';
    if ($export_type === 'camps') {
        $filename_suffix = 'camp_rosters';
    } elseif ($export_type === 'courses') {
        $filename_suffix = 'course_rosters';
    }
    $filename = "intersoccer_{$filename_suffix}_" . date('Y-m-d_H-i-s') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    $headers = [
        __('Event Name', 'intersoccer-reports-rosters'),
        __('Region', 'intersoccer-reports-rosters'),
        __('Venue', 'intersoccer-reports-rosters'),
        __('Booking Type', 'intersoccer-reports-rosters'),
        __('Season', 'intersoccer-reports-rosters'),
        __('City', 'intersoccer-reports-rosters'),
        __('Camp Term', 'intersoccer-reports-rosters'),
        __('First Name', 'intersoccer-reports-rosters'),
        __('Last Name', 'intersoccer-reports-rosters'),
        __('Gender', 'intersoccer-reports-rosters'),
        __('Parent Phone', 'intersoccer-reports-rosters'),
        __('Parent Email', 'intersoccer-reports-rosters'),
        __('Medical/Dietary', 'intersoccer-reports-rosters'),
        __('Late Pick-Up (18h)', 'intersoccer-reports-rosters'),
        __('Selected Days/Discount Info', 'intersoccer-reports-rosters'),
    ];
    fputcsv($output, $headers);

    $write_rosters = function($variations, $output) use ($headers) {
        foreach ($variations as $variation) {
            $variation_id = $variation['variation_id'];
            $roster = intersoccer_pe_get_event_roster_by_variation($variation_id);

            $product_id = wc_get_product($variation_id)->get_parent_id();
            $is_camp = in_array($variation['booking_type'], ['Full Week', 'single-days']);
            $camp_term = '';
            if ($is_camp) {
                $camp_term = wc_get_product_terms($product_id, 'pa_week', ['fields' => 'names'])[0] ?? '';
                if (!$camp_term) {
                    $start_date = wc_get_product_terms($product_id, 'pa_start-date', ['fields' => 'names'])[0] ?? '';
                    if ($start_date) {
                        $date = DateTime::createFromFormat('d/m/Y', $start_date);
                        if ($date) {
                            $camp_term = 'Week ' . $date->format('W');
                        }
                    }
                }
            }

            $event_row = [
                $variation['product_name'],
                $variation['region'],
                $variation['venue'],
                $variation['booking_type'],
                $variation['season'],
                $variation['city'],
                $is_camp ? $camp_term : '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
            ];
            fputcsv($output, $event_row);

            foreach ($roster as $player) {
                $attendee_row = [
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $player['first_name'],
                    $player['last_name'],
                    $player['gender'],
                    $player['parent_phone'],
                    $player['parent_email'],
                    $player['medical_conditions'],
                    $player['late_pickup'] === '18h' ? __('Yes', 'intersoccer-reports-rosters') : __('No', 'intersoccer-reports-rosters'),
                    $is_camp ? implode(', ', $player['selected_days']) : $player['discount_info'],
                ];
                fputcsv($output, $attendee_row);
            }

            fputcsv($output, [__('Total Players:', 'intersoccer-reports-rosters') . ' ' . count($roster)]);
            fputcsv($output, array_fill(0, count($headers), ''));
        }
    };

    $has_data = false;
    if ($export_type === 'all' || $export_type === 'camps') {
        if (!empty($camps)) {
            $write_rosters($camps, $output);
            $has_data = true;
        }
    }
    if ($export_type === 'all' || $export_type === 'courses') {
        if (!empty($courses)) {
            $write_rosters($courses, $output);
            $has_data = true;
        }
    }

    if (!$has_data) {
        fputcsv($output, [__('No rosters found matching the selected filters for this export type.', 'intersoccer-reports-rosters')]);
    }

    fclose($output);
    intersoccer_log_audit('export_all_rosters_csv', "Exported $export_type rosters to CSV");
    exit;
}
?>
