<?php
/**
 * InterSoccer Reports - AJAX Functions
 *
 * @package InterSoccerReports
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle AJAX filter request for booking report.
 */
function intersoccer_filter_report_callback() {
    check_ajax_referer('intersoccer_reports_filter', 'nonce');

    $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
    $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : date('Y');
    $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';
    $visible_columns = isset($_POST['columns']) ? array_map('sanitize_text_field', (array)$_POST['columns']) : [
        'ref', 'booked', 'base_price', 'discount_amount', 'stripe_fee', 'final_price',
        'class_name', 'venue', 'booker_email'
    ];

    // Use the enhanced reporting function
    $report_data = intersoccer_get_booking_report_enhanced($start_date, $end_date, $year, $region);

    ob_start();
    ?>
    <div id="intersoccer-report-totals" class="report-totals" style="margin-bottom: 20px;">
        <?php intersoccer_render_enhanced_booking_totals($report_data['totals']); ?>
    </div>
    <div id="intersoccer-report-table">
        <?php if (empty($report_data['data'])): ?>
            <p><?php _e('No data available for the selected filters.', 'intersoccer-reports-rosters'); ?></p>
        <?php else: ?>
            <style>
                .intersoccer-reports-rosters-reports-tab table.widefat th,
                .intersoccer-reports-rosters-reports-tab table.widefat td {
                    padding: 8px 12px;
                    font-size: 13px;
                }
                .intersoccer-reports-rosters-reports-tab table.widefat th {
                    background: #f8f9fa;
                    font-weight: 600;
                    border-bottom: 2px solid #dee2e6;
                }
                .intersoccer-reports-rosters-reports-tab table.widefat tbody tr:nth-child(even) {
                    background: #f8f9fa;
                }
                .intersoccer-reports-rosters-reports-tab table.widefat tbody tr:hover {
                    background: #e9ecef;
                }
            </style>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <?php
                        $all_columns = [
                            'ref' => __('Ref', 'intersoccer-reports-rosters'),
                            'booked' => __('Booked', 'intersoccer-reports-rosters'),
                            'base_price' => __('Base Price', 'intersoccer-reports-rosters'),
                            'discount_amount' => __('Discount', 'intersoccer-reports-rosters'),
                            'stripe_fee' => __('Stripe Fee', 'intersoccer-reports-rosters'),
                            'final_price' => __('Final Price', 'intersoccer-reports-rosters'),
                            'class_name' => __('Event', 'intersoccer-reports-rosters'),
                            'venue' => __('Venue', 'intersoccer-reports-rosters'),
                            'booker_email' => __('Email', 'intersoccer-reports-rosters'),
                        ];
                        foreach ($visible_columns as $key): ?>
                            <th><?php echo esc_html($all_columns[$key]); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data['data'] as $row): ?>
                        <tr>
                            <?php foreach ($visible_columns as $key): ?>
                                <td>
                                    <?php
                                    // Enhanced display for discount codes
                                    if ($key === 'discount_codes') {
                                        echo '<span title="' . esc_attr($row[$key]) . '">' . esc_html($row[$key]) . '</span>';
                                    } else {
                                        echo esc_html($row[$key]);
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    $output = ob_get_clean();
    wp_send_json_success(['table' => $output, 'totals' => ob_get_clean()]);
}


