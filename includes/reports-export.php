<?php
/**
 * InterSoccer Reports - Export Functions
 *
 * @package InterSoccerReports
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export booking report CSV
 */
/**
 * Export final reports CSV
 */
function intersoccer_export_final_reports_csv($year, $activity_type) {
    // Include the data processing file
    require_once plugin_dir_path(__FILE__) . 'reports-data.php';

    $report_data = intersoccer_get_final_reports_data($year, $activity_type);
    $totals = intersoccer_calculate_final_reports_totals($report_data, $activity_type);

    $filename = 'final-reports-' . strtolower($activity_type) . '-' . $year . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    if ($activity_type === 'Camp') {
        // Camp CSV headers
        fputcsv($output, array(
            'Week',
            'Canton',
            'Venue',
            'Camp Type',
            'Full Week',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'BuyClub',
            'Min-Max',
            'Total'
        ));

        // Camp CSV data
        foreach ($report_data as $week_name => $cantons) {
            foreach ($cantons as $canton => $venues) {
                foreach ($venues as $venue => $camp_types) {
                    foreach ($camp_types as $camp_type => $data) {
                        fputcsv($output, array(
                            $week_name,
                            $canton,
                            $venue,
                            $camp_type,
                            $data['full_week'],
                            $data['individual_days']['Monday'],
                            $data['individual_days']['Tuesday'],
                            $data['individual_days']['Wednesday'],
                            $data['individual_days']['Thursday'],
                            $data['individual_days']['Friday'],
                            $data['buyclub'],
                            $data['min_max'],
                            $data['full_week'] + $data['buyclub'] + array_sum($data['individual_days'])
                        ));
                    }
                }
            }
        }

        // Camp totals
        fputcsv($output, array('', '', '', '', '', '', '', '', ''));
        fputcsv($output, array('TOTALS', '', '', '', '', '', '', '', ''));
        fputcsv($output, array('', '', '', 'Full Day Camps', $totals['full_day']['full_week'], $totals['full_day']['individual_days']['Monday'], $totals['full_day']['individual_days']['Tuesday'], $totals['full_day']['individual_days']['Wednesday'], $totals['full_day']['individual_days']['Thursday'], $totals['full_day']['individual_days']['Friday'], $totals['full_day']['buyclub'], '', $totals['full_day']['total']));
        fputcsv($output, array('', '', '', 'Mini - Half Day Camps', $totals['mini']['full_week'], $totals['mini']['individual_days']['Monday'], $totals['mini']['individual_days']['Tuesday'], $totals['mini']['individual_days']['Wednesday'], $totals['mini']['individual_days']['Thursday'], $totals['mini']['individual_days']['Friday'], $totals['mini']['buyclub'], '', $totals['mini']['total']));
        fputcsv($output, array('', '', '', 'All Camps', $totals['all']['full_week'], $totals['all']['individual_days']['Monday'], $totals['all']['individual_days']['Tuesday'], $totals['all']['individual_days']['Wednesday'], $totals['all']['individual_days']['Thursday'], $totals['all']['individual_days']['Friday'], $totals['all']['buyclub'], '', $totals['all']['total']));
    } else {
        // Course CSV headers
        fputcsv($output, array(
            'Region',
            'Course Name',
            'BO',
            'Pitch Side',
            'BuyClub',
            'Total',
            'Final',
            'Girls Free'
        ));

        // Course CSV data
        foreach ($report_data as $region => $courses) {
            foreach ($courses as $course_name => $data) {
                fputcsv($output, array(
                    $region,
                    $course_name,
                    $data['bo'],
                    $data['pitch_side'],
                    $data['buyclub'],
                    $data['total'],
                    $data['final'],
                    $data['girls_free']
                ));
            }
        }

        // Course totals
        fputcsv($output, array('', '', '', '', '', '', '', ''));
        fputcsv($output, array('TOTALS', '', '', '', '', '', '', ''));
        foreach ($totals['regions'] as $region => $region_total) {
            fputcsv($output, array(
                $region,
                '',
                $region_total['bo'],
                $region_total['pitch_side'],
                $region_total['buyclub'],
                $region_total['total'],
                $region_total['final'],
                $region_total['girls_free']
            ));
        }
        fputcsv($output, array(
            'TOTAL:',
            '',
            $totals['all']['bo'],
            $totals['all']['pitch_side'],
            $totals['all']['buyclub'],
            $totals['all']['total'],
            $totals['all']['final'],
            $totals['all']['girls_free']
        ));
    }

    fclose($output);
    exit;
}