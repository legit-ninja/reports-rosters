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
    $revenue_by_type = isset($report_data['revenue_by_type']) ? $report_data['revenue_by_type'] : null;

    ob_start();
    ?>
    <div id="intersoccer-report-totals" class="report-totals" style="margin-bottom: 20px;">
        <?php intersoccer_render_enhanced_booking_totals($report_data['totals'], $report_data['data'], $revenue_by_type); ?>
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
 * @param array      $totals         Aggregated totals.
 * @param array|null $report_data    Optional row data for discount-type breakdown.
 * @param array|null $revenue_by_type Deprecated - no longer rendered here (moved to own tab).
 */
function intersoccer_render_enhanced_booking_totals($totals, $report_data = null, $revenue_by_type = null) {
    if (empty($totals)) {
        echo '<p>No totals available.</p>';
        return;
    }
    
    $net_revenue = $totals['final_price'] - $totals['reimbursement'];
    $avg_order_value = $totals['bookings'] > 0 ? $totals['final_price'] / $totals['bookings'] : 0;
    $discount_type_totals = [];

    if (is_array($report_data) && !empty($report_data) && function_exists('intersoccer_calculate_discount_type_breakdown')) {
        $discount_type_totals = intersoccer_calculate_discount_type_breakdown($report_data);
    }
    
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
            <?php if (!empty($discount_type_totals)) : ?>
                <div class="summary-item">
                    <strong>Sibling Discounts:</strong> CHF <?php echo number_format($discount_type_totals['sibling'], 2); ?>
                </div>
                <div class="summary-item">
                    <strong>Same Season Discounts:</strong> CHF <?php echo number_format($discount_type_totals['same_season'], 2); ?>
                </div>
                <div class="summary-item">
                    <strong>Coupon Discounts:</strong> CHF <?php echo number_format($discount_type_totals['coupon'], 2); ?>
                </div>
                <div class="summary-item">
                    <strong>Referral Code Discounts:</strong> CHF <?php echo number_format($discount_type_totals['referral_first_order'], 2); ?>
                </div>
                <div class="summary-item">
                    <strong>Points Redemption:</strong> CHF <?php echo number_format($discount_type_totals['referral_points'], 2); ?>
                </div>
                <div class="summary-item">
                    <strong>Other Discounts:</strong> CHF <?php echo number_format($discount_type_totals['other'], 2); ?>
                </div>
            <?php endif; ?>
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

/**
 * Handle AJAX filter request for Revenue by Product Type tab.
 */
function intersoccer_filter_revenue_by_type_callback() {
    check_ajax_referer('intersoccer_reports_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have sufficient permissions.', 'intersoccer-reports-rosters')]);
        return;
    }

    $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
    $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : date('Y');

    $report_data = intersoccer_get_financial_booking_report($start_date, $end_date, $year, '');
    $revenue_by_type = isset($report_data['revenue_by_type']) ? $report_data['revenue_by_type'] : [];
    $totals = isset($report_data['totals']) ? $report_data['totals'] : [];

    ob_start();
    intersoccer_render_revenue_by_type_table($revenue_by_type, $totals);
    $html = ob_get_clean();

    $record_count = isset($totals['bookings']) ? (int) $totals['bookings'] : 0;

    wp_send_json_success([
        'html' => $html,
        'record_count' => $record_count,
    ]);
}
add_action('wp_ajax_intersoccer_filter_revenue_by_type', 'intersoccer_filter_revenue_by_type_callback');

/**
 * Render the Revenue by Product Type table for AJAX response.
 *
 * @param array $revenue_by_type Revenue breakdown by product type.
 * @param array $totals          Overall totals.
 */
function intersoccer_render_revenue_by_type_table($revenue_by_type, $totals) {
    if (empty($revenue_by_type)) {
        echo '<p>' . esc_html__('No data available for the selected filters.', 'intersoccer-reports-rosters') . '</p>';
        return;
    }

    $net_revenue = isset($totals['final_price'], $totals['reimbursement'])
        ? $totals['final_price'] - $totals['reimbursement']
        : 0;
    ?>
    <div class="revenue-by-type-table-wrapper" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
        <h3 style="margin-top: 0; color: #0073aa;"><?php _e('Revenue Breakdown', 'intersoccer-reports-rosters'); ?></h3>
        <p style="margin: 0 0 15px; font-size: 12px; color: #666;">
            <?php _e('Gross = line subtotal (pre-discount). Final = Gross − discounts. Net = Final − refunds.', 'intersoccer-reports-rosters'); ?>
        </p>
        <table class="widefat fixed" style="margin-bottom: 0;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="width: 25%;"><?php _e('Product Type', 'intersoccer-reports-rosters'); ?></th>
                    <th style="text-align: right;"><?php _e('Gross (CHF)', 'intersoccer-reports-rosters'); ?></th>
                    <th style="text-align: right;"><?php _e('Final (CHF)', 'intersoccer-reports-rosters'); ?></th>
                    <th style="text-align: right;"><?php _e('Net (CHF)', 'intersoccer-reports-rosters'); ?></th>
                    <th style="text-align: right;"><?php _e('% of Net', 'intersoccer-reports-rosters'); ?></th>
                    <th style="text-align: right;"><?php _e('Bookings', 'intersoccer-reports-rosters'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($revenue_by_type as $type => $values) : ?>
                    <?php if ($values['count'] > 0 || $type !== 'Other/Unmapped') : ?>
                    <tr>
                        <td><strong><?php echo esc_html($type); ?></strong></td>
                        <td style="text-align: right;"><?php echo number_format($values['gross'], 2); ?></td>
                        <td style="text-align: right;"><?php echo number_format($values['final'], 2); ?></td>
                        <td style="text-align: right;"><?php echo number_format($values['net'], 2); ?></td>
                        <td style="text-align: right;"><?php echo number_format($values['net_percent'], 1); ?>%</td>
                        <td style="text-align: right;"><?php echo number_format($values['count']); ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8f9fa; font-weight: bold;">
                    <td><?php _e('TOTAL', 'intersoccer-reports-rosters'); ?></td>
                    <td style="text-align: right;"><?php echo number_format($totals['base_price'] ?? 0, 2); ?></td>
                    <td style="text-align: right;"><?php echo number_format($totals['final_price'] ?? 0, 2); ?></td>
                    <td style="text-align: right;"><?php echo number_format($net_revenue, 2); ?></td>
                    <td style="text-align: right;">100.0%</td>
                    <td style="text-align: right;"><?php echo number_format($totals['bookings'] ?? 0); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php
}

/**
 * Handle AJAX export request for Revenue by Product Type.
 */
function intersoccer_export_revenue_by_type_callback() {
    check_ajax_referer('intersoccer_reports_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have sufficient permissions.', 'intersoccer-reports-rosters')]);
        return;
    }

    $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
    $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : date('Y');
    $sync_to_office365 = !empty($_POST['sync_to_office365']);

    $report_data = intersoccer_get_financial_booking_report($start_date, $end_date, $year, '');

    if (empty($report_data['revenue_by_type'])) {
        wp_send_json_error(['message' => __('No data to export.', 'intersoccer-reports-rosters')]);
        return;
    }

    $result = intersoccer_build_revenue_by_type_xlsx($report_data, $start_date, $end_date, $year);

    if (!$result) {
        wp_send_json_error(['message' => __('Failed to generate Excel file.', 'intersoccer-reports-rosters')]);
        return;
    }

    $response = [
        'filename' => $result['filename'],
        'content' => $result['content'],
    ];

    if ($sync_to_office365 && function_exists('intersoccer_office365_sync_xlsx')) {
        $sync_result = intersoccer_office365_sync_xlsx($result['filename'], base64_decode($result['content']));
        $response['synced'] = $sync_result === true;
        if ($sync_result !== true) {
            $response['sync_error'] = is_string($sync_result) ? $sync_result : __('Unknown sync error', 'intersoccer-reports-rosters');
        }
    }

    wp_send_json_success($response);
}
add_action('wp_ajax_intersoccer_export_revenue_by_type', 'intersoccer_export_revenue_by_type_callback');

/**
 * Build Revenue by Product Type Excel file.
 *
 * @param array  $report_data Full booking report data with revenue_by_type.
 * @param string $start_date  Start date.
 * @param string $end_date    End date.
 * @param string $year        Year.
 * @return array{filename: string, content: string}|null
 */
function intersoccer_build_revenue_by_type_xlsx($report_data, $start_date, $end_date, $year) {
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    }

    $revenue_by_type = $report_data['revenue_by_type'] ?? [];
    $totals = $report_data['totals'] ?? [];

    if (empty($revenue_by_type)) {
        return null;
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Revenue by Product Type');

    $current_row = 1;

    // Title
    $sheet->setCellValue('A' . $current_row, 'REVENUE BY PRODUCT TYPE');
    $sheet->getStyle('A' . $current_row)->getFont()->setBold(true)->setSize(14);
    $current_row += 2;

    // Report parameters
    $date_range = '';
    if ($start_date && $end_date) {
        $date_range = $start_date . ' to ' . $end_date;
    } elseif ($year) {
        $date_range = 'Year: ' . $year;
    }
    $sheet->setCellValue('A' . $current_row, 'Date Range: ' . $date_range);
    $current_row++;
    $sheet->setCellValue('A' . $current_row, 'Generated: ' . date('Y-m-d H:i:s'));
    $current_row++;
    $sheet->setCellValue('A' . $current_row, 'Currency: CHF | BuyClub: Excluded | Commissions: Not included');
    $current_row++;
    $sheet->setCellValue('A' . $current_row, 'Net = Final - Refunds (attributed line refunds)');
    $current_row += 2;

    // Headers
    $headers = ['Product Type', 'Gross (CHF)', 'Final (CHF)', 'Net (CHF)', '% of Net', 'Bookings'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $current_row, $header);
        $col++;
    }
    $header_range = 'A' . $current_row . ':F' . $current_row;
    $sheet->getStyle($header_range)->getFont()->setBold(true);
    $sheet->getStyle($header_range)->getFill()
          ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setARGB('FFF8F9FA');
    $current_row++;

    // Data rows
    foreach ($revenue_by_type as $type => $values) {
        if ($type === 'Other/Unmapped' && $values['count'] === 0) {
            continue;
        }

        $sheet->setCellValue('A' . $current_row, $type);
        $sheet->setCellValue('B' . $current_row, $values['gross']);
        $sheet->setCellValue('C' . $current_row, $values['final']);
        $sheet->setCellValue('D' . $current_row, $values['net']);
        $sheet->setCellValue('E' . $current_row, $values['net_percent'] / 100);
        $sheet->setCellValue('F' . $current_row, $values['count']);

        $sheet->getStyle('B' . $current_row . ':D' . $current_row)->getNumberFormat()->setFormatCode('#,##0.00 "CHF"');
        $sheet->getStyle('E' . $current_row)->getNumberFormat()->setFormatCode('0.0%');

        $current_row++;
    }

    // Total row
    $net_revenue = ($totals['final_price'] ?? 0) - ($totals['reimbursement'] ?? 0);
    $sheet->setCellValue('A' . $current_row, 'TOTAL');
    $sheet->setCellValue('B' . $current_row, (float)($totals['base_price'] ?? 0));
    $sheet->setCellValue('C' . $current_row, (float)($totals['final_price'] ?? 0));
    $sheet->setCellValue('D' . $current_row, $net_revenue);
    $sheet->setCellValue('E' . $current_row, 1);
    $sheet->setCellValue('F' . $current_row, (int)($totals['bookings'] ?? 0));

    $total_range = 'A' . $current_row . ':F' . $current_row;
    $sheet->getStyle($total_range)->getFont()->setBold(true);
    $sheet->getStyle($total_range)->getFill()
          ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setARGB('FFF8F9FA');
    $sheet->getStyle('B' . $current_row . ':D' . $current_row)->getNumberFormat()->setFormatCode('#,##0.00 "CHF"');
    $sheet->getStyle('E' . $current_row)->getNumberFormat()->setFormatCode('0.0%');

    // Auto-size columns
    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Generate file
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();

    $filename = 'Revenue_By_Type_';
    if ($start_date && $end_date) {
        $filename .= str_replace('-', '', $start_date) . '_' . str_replace('-', '', $end_date);
    } else {
        $filename .= $year;
    }
    $filename .= '_' . date('Ymd_His') . '.xlsx';

    return [
        'filename' => $filename,
        'content' => base64_encode($content),
    ];
}
