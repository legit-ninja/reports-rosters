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

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have sufficient permissions to access this report.', 'intersoccer-reports-rosters')]);
        return;
    }

    $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
    $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : date('Y');
    $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';

    $visible_columns = isset($_POST['columns']) ? array_map('sanitize_text_field', (array)$_POST['columns']) : [
        'ref', 'booked', 'base_price', 'discount_amount', 'discounts_applied', 'stripe_fee', 'final_price',
        'class_name', 'venue', 'booker_email', 'booker_phone'
    ];

    // Use the simplified financial reporting function
    $report_data = intersoccer_get_financial_booking_report($start_date, $end_date, $year, $region);

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
                            'discounts_applied' => __('Discounts Applied', 'intersoccer-reports-rosters'),
                            'stripe_fee' => __('Stripe Fee', 'intersoccer-reports-rosters'),
                            'final_price' => __('Final Price', 'intersoccer-reports-rosters'),
                            'discount_codes' => __('Discount Codes', 'intersoccer-reports-rosters'),
                            'class_name' => __('Event', 'intersoccer-reports-rosters'),
                            'venue' => __('Venue', 'intersoccer-reports-rosters'),
                            'booker_email' => __('Email', 'intersoccer-reports-rosters'),
                            'booker_phone' => __('Customer Phone', 'intersoccer-reports-rosters'),
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
                                    // Check if key exists in row data
                                    if (isset($row[$key])) {
                                        $value = $row[$key];
                                        // Enhanced display for discount-related columns
                                        if ($key === 'discounts_applied' || $key === 'discount_codes') {
                                            // Show full text on hover for long discount strings
                                            echo '<span title="' . esc_attr($value) . '">' . esc_html($value) . '</span>';
                                        } else {
                                            echo esc_html($value);
                                        }
                                    } else {
                                        // Key doesn't exist in row data
                                        echo '—';
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
    
    // Extract totals HTML from the output
    $totals_html = '';
    if (preg_match('/<div id="intersoccer-report-totals"[^>]*>(.*?)<\/div>/s', $output, $matches)) {
        $totals_html = $matches[1];
    }
    
    // Remove totals from table output since they're handled separately
    $table_html = preg_replace('/<div id="intersoccer-report-totals"[^>]*>.*?<\/div>/s', '', $output);
    
    // Calculate record count
    $record_count = isset($report_data['data']) ? count($report_data['data']) : 0;
    wp_send_json_success([
        'table' => $table_html, 
        'totals' => $totals_html,
        'record_count' => $record_count
    ]);
}

/**
 * Render enhanced booking report totals.
 *
 * @param array $totals The totals data.
 */
function intersoccer_render_enhanced_booking_totals($totals) {
    if (empty($totals)) {
        echo '<p>No totals available.</p>';
        return;
    }
    
    $net_revenue = $totals['final_price'] - $totals['reimbursement'];
    $avg_order_value = $totals['bookings'] > 0 ? $totals['final_price'] / $totals['bookings'] : 0;
    
    ?>
    <div class="report-summary" style="background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-radius: 5px;">
        <h3 style="margin-top: 0; color: #0073aa;">Financial Summary</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <div class="summary-item">
                <strong>Total Bookings:</strong> <?php echo number_format($totals['bookings']); ?>
            </div>
            <div class="summary-item">
                <strong>Gross Revenue:</strong> CHF <?php echo number_format($totals['base_price'], 2); ?>
            </div>
            <div class="summary-item">
                <strong>Total Discounts:</strong> CHF <?php echo number_format($totals['discount_amount'], 2); ?>
            </div>
            <div class="summary-item">
                <strong>Final Revenue:</strong> CHF <?php echo number_format($totals['final_price'], 2); ?>
            </div>
            <div class="summary-item">
                <strong>Reimbursements:</strong> CHF <?php echo number_format($totals['reimbursement'], 2); ?>
            </div>
            <div class="summary-item">
                <strong>Net Revenue:</strong> CHF <?php echo number_format($net_revenue, 2); ?>
            </div>
            <div class="summary-item">
                <strong>Average Order Value:</strong> CHF <?php echo number_format($avg_order_value, 2); ?>
            </div>
        </div>
    </div>
    <?php
}


