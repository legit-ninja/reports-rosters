<?php
/**
 * Reports page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.3.79
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Start output buffering early
ob_start();

require_once dirname(__DIR__) . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Render the Reports page with tabs.
 */
function intersoccer_render_reports_page() {
    if (!current_user_can('manage_options')) {
        ob_end_clean();
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
    $tabs = [
        'general' => __('General Reports', 'intersoccer-reports-rosters'),
        'summer-camps' => __('Summer Camps Report', 'intersoccer-reports-rosters'),
        'booking' => __('Booking Report', 'intersoccer-reports-rosters'),
    ];
    ?>
    <div class="wrap intersoccer-reports-rosters-reports">
        <h1><?php _e('InterSoccer Reports', 'intersoccer-reports-rosters'); ?></h1>
        <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab => $label): ?>
                <a href="?page=intersoccer-reports&tab=<?php echo esc_attr($tab); ?>" class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="tab-content">
            <?php
            switch ($active_tab) {
                case 'booking':
                    intersoccer_render_booking_report_tab();
                    break;
                case 'summer-camps':
                    intersoccer_render_summer_camps_report_tab();
                    break;
                case 'general':
                default:
                    echo '<p>' . __('General reports content goes here.', 'intersoccer-reports-rosters') . '</p>';
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Enqueue jQuery UI Datepicker for date-range picker.
 */
function intersoccer_enqueue_datepicker() {
    if (isset($_GET['page']) && $_GET['page'] === 'intersoccer-reports' && isset($_GET['tab']) && $_GET['tab'] === 'booking') {
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        wp_add_inline_script('jquery-ui-datepicker', '
            jQuery(document).ready(function($) {
                $("#start_date, #end_date").datepicker({
                    dateFormat: "yy-mm-dd",
                    changeMonth: true,
                    changeYear: true
                });
            });
        ');
    }
}
add_action('admin_enqueue_scripts', 'intersoccer_enqueue_datepicker');

/**
 * Render the Booking Report tab content.
 */
function intersoccer_render_booking_report_tab() {
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    $year = isset($_GET['year']) ? sanitize_text_field($_GET['year']) : date('Y');
    $region = isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '';
    $visible_columns = isset($_GET['columns']) ? array_map('sanitize_text_field', (array)$_GET['columns']) : ['ref', 'booked', 'base_price', 'discount_amount', 'final_price', 'discount_codes', 'class_name', 'start_date', 'venue', 'booker_email', 'attendee_name'];
    $report_data = intersoccer_get_booking_report($start_date, $end_date, $year, $region);

    if (isset($_GET['action']) && $_GET['action'] === 'export' && check_admin_referer('export_booking_nonce')) {
        intersoccer_export_booking_excel($report_data['data'], $start_date, $end_date, $year, $region, $visible_columns);
    }

    // Define all possible columns
    $all_columns = [
        'ref' => __('Ref', 'intersoccer-reports-rosters'),
        'booked' => __('Booked', 'intersoccer-reports-rosters'),
        'base_price' => __('Base Price', 'intersoccer-reports-rosters'),
        'discount_amount' => __('Discount Amount', 'intersoccer-reports-rosters'),
        'final_price' => __('Final Price', 'intersoccer-reports-rosters'),
        'discount_codes' => __('Discount Codes', 'intersoccer-reports-rosters'),
        'class_name' => __('Class Name', 'intersoccer-reports-rosters'),
        'start_date' => __('Start Date', 'intersoccer-reports-rosters'),
        'venue' => __('Venue', 'intersoccer-reports-rosters'),
        'booker_email' => __('Booker Email', 'intersoccer-reports-rosters'),
        'attendee_name' => __('Attendee Name', 'intersoccer-reports-rosters'),
    ];

    // Calculate totals
    $total_bookings = count($report_data['data']);
    $total_base_price = array_sum(array_column($report_data['data'], 'base_price'));
    $total_discount_amount = array_sum(array_column($report_data['data'], 'discount_amount'));
    $total_final_price = array_sum(array_column($report_data['data'], 'final_price'));
    ?>
    <div class="wrap intersoccer-reports-rosters-reports-tab">
        <h2><?php echo esc_html(sprintf(__('Booking Report %s', 'intersoccer-reports-rosters'), $start_date && $end_date ? "$start_date to $end_date" : $year)); ?></h2>
        <div class="report-totals" style="margin-bottom: 20px;">
            <h3><?php _e('Summary', 'intersoccer-reports-rosters'); ?></h3>
            <p><strong><?php _e('Total Bookings:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html($total_bookings); ?></p>
            <p><strong><?php _e('Total Base Price:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html(number_format($total_base_price, 2)); ?> CHF</p>
            <p><strong><?php _e('Total Discount Amount:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html(number_format($total_discount_amount, 2)); ?> CHF</p>
            <p><strong><?php _e('Total Final Price:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html(number_format($total_final_price, 2)); ?> CHF</p>
        </div>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="intersoccer-reports" />
            <input type="hidden" name="tab" value="booking" />
            <label for="start_date"><?php _e('Start Date:', 'intersoccer-reports-rosters'); ?></label>
            <input type="text" name="start_date" id="start_date" value="<?php echo esc_attr($start_date); ?>" placeholder="YYYY-MM-DD" />
            <label for="end_date"><?php _e('End Date:', 'intersoccer-reports-rosters'); ?></label>
            <input type="text" name="end_date" id="end_date" value="<?php echo esc_attr($end_date); ?>" placeholder="YYYY-MM-DD" />
            <label for="year"><?php _e('Year:', 'intersoccer-reports-rosters'); ?></label>
            <input type="number" name="year" id="year" value="<?php echo esc_attr($year); ?>" />
            <label for="region"><?php _e('Region:', 'intersoccer-reports-rosters'); ?></label>
            <select name="region" id="region">
                <option value=""><?php _e('All Regions', 'intersoccer-reports-rosters'); ?></option>
                <?php
                $products = wc_get_products(['type' => 'variable', 'limit' => 1]);
                $regions = !empty($products) ? wc_get_product_terms($products[0]->get_id(), 'pa_canton-region', ['fields' => 'names']) : [];
                foreach ($regions as $r) {
                    echo '<option value="' . esc_attr($r) . '"' . selected($region, $r, false) . '>' . esc_html($r) . '</option>';
                }
                ?>
            </select>
            <div style="margin-top: 10px;">
                <h4><?php _e('Select Columns:', 'intersoccer-reports-rosters'); ?></h4>
                <?php foreach ($all_columns as $key => $label): ?>
                    <label style="margin-right: 15px;">
                        <input type="checkbox" name="columns[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $visible_columns)); ?> />
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
        </form>
        <div class="export-section" style="margin-top: 10px;">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=intersoccer-reports&tab=booking&start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date) . "&year=" . urlencode($year) . "&region=" . urlencode($region) . "&" . http_build_query(['columns' => $visible_columns]) . "&action=export"), 'export_booking_nonce')); ?>" class="button button-primary"><?php _e('Export to Excel', 'intersoccer-reports-rosters'); ?></a>
        </div>
        <?php if (empty($report_data['data'])): ?>
            <p><?php _e('No data available for the selected filters.', 'intersoccer-reports-rosters'); ?></p>
        <?php else: ?>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <?php foreach ($all_columns as $key => $label): ?>
                            <?php if (in_array($key, $visible_columns)): ?>
                                <th><?php echo esc_html($label); ?></th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data['data'] as $row): ?>
                        <tr>
                            <?php foreach ($all_columns as $key => $label): ?>
                                <?php if (in_array($key, $visible_columns)): ?>
                                    <td><?php echo esc_html($row[$key]); ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Generate Booking Report data from WooCommerce tables.
 *
 * @param string $start_date Start date filter (YYYY-MM-DD).
 * @param string $end_date End date filter (YYYY-MM-DD).
 * @param string $year Year filter (default: current year).
 * @param string $region Region filter.
 * @return array Structured report data with totals.
 */
function intersoccer_get_booking_report($start_date = '', $end_date = '', $year = null, $region = '') {
    global $wpdb;
    $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
    $posts_table = $wpdb->posts;
    $postmeta_table = $wpdb->postmeta;
    $term_taxonomy_table = $wpdb->prefix . 'term_taxonomy';
    $terms_table = $wpdb->prefix . 'terms';

    if (!$year) {
        $year = date('Y');
    }

    // Fetch order items, coupons, and base price
    $query = "
        SELECT 
            p.ID AS ref,
            p.post_date AS booked,
            om_price.meta_value AS line_subtotal,
            om_line_total.meta_value AS line_total,
            om_variation_id.meta_value AS variation_id,
            pm_price.meta_value AS base_price,
            oi.order_item_id,
            oi.order_item_name AS class_name,
            om_start_date.meta_value AS start_date,
            t.name AS venue,
            pm_billing_email.meta_value AS booker_email,
            om_combo.meta_value AS combo_discount,
            om_combo_amount.meta_value AS combo_discount_amount,
            om_activity_type.meta_value AS activity_type,
            pm_customer.meta_value AS customer_user,
            om_assigned_attendee.meta_value AS assigned_attendee,
            GROUP_CONCAT(DISTINCT coupon.order_item_name) AS coupon_codes
        FROM $posts_table p
        JOIN $order_items_table oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
        LEFT JOIN $order_items_table coupon ON p.ID = coupon.order_id AND coupon.order_item_type = 'coupon'
        LEFT JOIN $order_itemmeta_table om_price ON oi.order_item_id = om_price.order_item_id AND om_price.meta_key = '_line_subtotal'
        LEFT JOIN $order_itemmeta_table om_line_total ON oi.order_item_id = om_line_total.order_item_id AND om_line_total.meta_key = '_line_total'
        LEFT JOIN $order_itemmeta_table om_variation_id ON oi.order_item_id = om_variation_id.order_item_id AND om_variation_id.meta_key = '_variation_id'
        LEFT JOIN $postmeta_table pm_price ON om_variation_id.meta_value = pm_price.post_id AND pm_price.meta_key = '_price'
        LEFT JOIN $order_itemmeta_table om_start_date ON oi.order_item_id = om_start_date.order_item_id AND om_start_date.meta_key = 'event_start_date'
        LEFT JOIN $order_itemmeta_table om_venue ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
        LEFT JOIN $term_taxonomy_table tt ON om_venue.meta_value = tt.term_taxonomy_id AND tt.taxonomy = 'pa_intersoccer-venues'
        LEFT JOIN $terms_table t ON tt.term_id = t.term_id
        LEFT JOIN $order_itemmeta_table om_activity_type ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
        LEFT JOIN $order_itemmeta_table om_combo ON oi.order_item_id = om_combo.order_item_id AND om_combo.meta_key = 'Discount Applied'
        LEFT JOIN $order_itemmeta_table om_combo_amount ON oi.order_item_id = om_combo_amount.order_item_id AND om_combo_amount.meta_key = 'combo_discount_amount'
        LEFT JOIN $order_itemmeta_table om_region ON oi.order_item_id = om_region.order_item_id AND om_region.meta_key = 'Canton / Region'
        LEFT JOIN $postmeta_table pm_billing_email ON p.ID = pm_billing_email.post_id AND pm_billing_email.meta_key = '_billing_email'
        LEFT JOIN $postmeta_table pm_customer ON p.ID = pm_customer.post_id AND pm_customer.meta_key = '_customer_user'
        LEFT JOIN $order_itemmeta_table om_assigned_attendee ON oi.order_item_id = om_assigned_attendee.order_item_id AND om_assigned_attendee.meta_key = 'Assigned Attendee'
        WHERE p.post_type = 'shop_order'
    ";

    $params = [];
    if ($start_date && $end_date && strtotime($start_date) && strtotime($end_date)) {
        $query .= " AND p.post_date BETWEEN %s AND %s";
        $params[] = $start_date . ' 00:00:00';
        $params[] = $end_date . ' 23:59:59';
    } else {
        $query .= " AND YEAR(p.post_date) = %d";
        $params[] = $year;
    }
    if ($region) {
        $query .= " AND om_region.meta_value = %s";
        $params[] = $region;
    }
    $query .= " GROUP BY oi.order_item_id";

    $query = $wpdb->prepare($query, $params);
    error_log("InterSoccer: Booking Report Query: $query");
    $results = $wpdb->get_results($query, ARRAY_A);

    if (empty($results)) {
        error_log("InterSoccer: No booking data found for year $year, region $region, start_date $start_date, end_date $end_date");
        return ['data' => [], 'totals' => ['bookings' => 0, 'base_price' => 0, 'discount_amount' => 0, 'final_price' => 0]];
    }

    // Calculate total order discount from coupons
    $order_totals = [];
    foreach ($results as $row) {
        $order_id = $row['ref'];
        if (!isset($order_totals[$order_id])) {
            $order_total = floatval(get_post_meta($order_id, '_order_total', true) ?? 0);
            $order_discount = floatval(get_post_meta($order_id, '_cart_discount', true) ?? 0);
            $order_totals[$order_id] = [
                'total' => $order_total,
                'discount' => $order_discount,
                'items' => []
            ];
        }
        $order_totals[$order_id]['items'][] = [
            'order_item_id' => $row['order_item_id'],
            'subtotal' => floatval($row['base_price'] ?? $row['line_subtotal'] ?? 0)
        ];
    }

    $report_data = [];
    foreach ($results as $row) {
        // Fetch attendee details from intersoccer_players using Assigned Attendee
        $attendee_name = !empty($row['assigned_attendee']) ? $row['assigned_attendee'] : 'Unknown';
        $user_id = $row['customer_user'] ?? 0;
        if ($user_id && !empty($row['assigned_attendee'])) {
            $players = get_user_meta($user_id, 'intersoccer_players', true);
            if (is_array($players)) {
                foreach ($players as $player) {
                    $player_full_name = ($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '');
                    if (trim($player_full_name) === trim($row['assigned_attendee'])) {
                        $attendee_name = $player_full_name;
                        break;
                    }
                }
                if ($attendee_name === 'Unknown') {
                    error_log("InterSoccer: No matching player found for Order ID {$row['ref']}, Assigned Attendee {$row['assigned_attendee']}, user_id {$user_id}");
                }
            } else {
                error_log("InterSoccer: No intersoccer_players data for user_id {$user_id} in Order ID {$row['ref']}");
            }
        } else {
            error_log("InterSoccer: Missing customer_user or Assigned Attendee for Order ID {$row['ref']}");
        }

        // Calculate prorated coupon discount based on base_price
        $order_id = $row['ref'];
        $coupon_discount = 0;
        if (isset($order_totals[$order_id]) && $order_totals[$order_id]['discount'] > 0) {
            $total_subtotal = array_sum(array_column($order_totals[$order_id]['items'], 'subtotal'));
            if ($total_subtotal > 0) {
                $item_subtotal = floatval($row['base_price'] ?? $row['line_subtotal'] ?? 0);
                $coupon_discount = min($item_subtotal, ($item_subtotal / $total_subtotal) * $order_totals[$order_id]['discount']);
            }
        }

        // Calculate custom discount from Discount Applied
        $base_price = floatval($row['base_price'] ?? $row['line_subtotal'] ?? 0);
        $line_total = floatval($row['line_total'] ?? $base_price);
        $combo_discount_amount = floatval($row['combo_discount_amount'] ?? 0);

        // Derive combo discount amount from Discount Applied percentage
        if (!empty($row['combo_discount']) && !$combo_discount_amount) {
            if (preg_match('/(\d+)%/', $row['combo_discount'], $matches)) {
                $discount_percentage = floatval($matches[1]);
                $combo_discount_amount = $base_price * ($discount_percentage / 100);
            }
        }

        // Total discount amount
        $discount_amount = $combo_discount_amount + $coupon_discount;
        $final_price = max(0, $base_price - $discount_amount); // Prevent negative final price
        $discount_amount = number_format($discount_amount, 2);
        $final_price = number_format($final_price, 2);

        // Format start_date
        $start_date = !empty($row['start_date']) && strtotime($row['start_date']) ? date_i18n('d/m/Y', strtotime($row['start_date'])) : '';

        // Combine discount codes
        $discount_codes = [];
        if (!empty($row['coupon_codes'])) {
            $discount_codes[] = $row['coupon_codes'] . ' (order)';
        }
        if (!empty($row['combo_discount'])) {
            $discount_codes[] = $row['combo_discount'] . ' (item)';
        }
        $discount_codes_str = !empty($discount_codes) ? implode(', ', $discount_codes) : 'None';

        // Log price and discount details
        error_log("InterSoccer: Order ID {$row['ref']}, Order Item ID {$row['order_item_id']}, Base Price: {$base_price}, Line Total: {$line_total}, Coupon Discount: {$coupon_discount}, Combo Discount Amount: {$combo_discount_amount}, Discount Amount: {$discount_amount}, Final Price: {$final_price}, Discount Codes: {$discount_codes_str}, Assigned Attendee: {$attendee_name}, Venue: {$row['venue']}, Start Date: {$start_date}");

        // Ensure data array matches headers
        $report_data[] = [
            'ref' => $row['ref'],
            'booked' => $row['booked'] ? date_i18n('d/m/Y H:i', strtotime($row['booked'])) : '',
            'base_price' => number_format($base_price, 2),
            'discount_amount' => $discount_amount,
            'final_price' => $final_price,
            'discount_codes' => $discount_codes_str,
            'class_name' => $row['class_name'] ?? '',
            'start_date' => $start_date,
            'venue' => $row['venue'] ?? 'Unknown',
            'booker_email' => $row['booker_email'] ?? '',
            'attendee_name' => $attendee_name,
        ];
    }

    $totals = [
        'bookings' => count($report_data),
        'base_price' => array_sum(array_column($report_data, 'base_price')),
        'discount_amount' => array_sum(array_column($report_data, 'discount_amount')),
        'final_price' => array_sum(array_column($report_data, 'final_price')),
    ];

    error_log("InterSoccer: Report Data Fields: " . print_r(array_keys($report_data[0]), true));
    error_log("InterSoccer: Booking Report retrieved " . count($report_data) . " rows for year $year, region $region, start_date $start_date, end_date $end_date");
    return ['data' => $report_data, 'totals' => $totals];
}

/**
 * Export Booking Report to Excel.
 *
 * @param array $report_data The report data to export.
 * @param string $start_date Start date filter.
 * @param string $end_date End date filter.
 * @param string $year Year filter.
 * @param string $region Region filter.
 * @param array $visible_columns Columns to include in export.
 */
function intersoccer_export_booking_excel($report_data, $start_date, $end_date, $year, $region, $visible_columns) {
    // Clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    try {
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            error_log('InterSoccer: PhpSpreadsheet class not found in ' . __FILE__ . ' for booking export');
            ob_end_clean();
            wp_die(__('PhpSpreadsheet missing.', 'intersoccer-reports-rosters'));
        }

        // Increase memory limit for large exports
        ini_set('memory_limit', '256M');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Booking_Report_' . ($start_date && $end_date ? "$start_date_to_$end_date" : $year));

        // Define all possible columns
        $all_columns = [
            'ref' => __('Ref', 'intersoccer-reports-rosters'),
            'booked' => __('Booked', 'intersoccer-reports-rosters'),
            'base_price' => __('Base Price', 'intersoccer-reports-rosters'),
            'discount_amount' => __('Discount Amount', 'intersoccer-reports-rosters'),
            'final_price' => __('Final Price', 'intersoccer-reports-rosters'),
            'discount_codes' => __('Discount Codes', 'intersoccer-reports-rosters'),
            'class_name' => __('Class Name', 'intersoccer-reports-rosters'),
            'start_date' => __('Start Date', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'booker_email' => __('Booker Email', 'intersoccer-reports-rosters'),
            'attendee_name' => __('Attendee Name', 'intersoccer-reports-rosters'),
        ];

        // Headers
        $headers = array_intersect_key($all_columns, array_flip($visible_columns));
        $sheet->fromArray(array_values($headers), null, 'A1');

        // Data
        $row_number = 2;
        foreach ($report_data as $row) {
            $row_data = [];
            foreach ($visible_columns as $key) {
                $row_data[] = $row[$key];
            }
            $sheet->fromArray($row_data, null, "A$row_number");
            $row_number++;
        }

        // Styling
        $sheet->getStyle('A1:' . chr(64 + count($visible_columns)) . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . chr(64 + count($visible_columns)) . ($row_number - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Set columns to text to prevent formatting issues
        $column_map = array_keys($headers);
        foreach ($column_map as $index => $key) {
            if (in_array($key, ['booker_email', 'attendee_name', 'start_date'])) {
                $sheet->getStyle(chr(65 + $index) . '2:' . chr(65 + $index) . ($row_number - 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
        }

        // Auto-size columns
        foreach (range('A', chr(64 + count($visible_columns))) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = "booking_report_" . ($start_date && $end_date ? "$start_date_to_$end_date" : $year) . ($region ? "_$region" : '') . '_' . date('Y-m-d_H-i-s') . '.xlsx';
        error_log('InterSoccer: Sending headers for booking report export');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Expires: 0');
        header('Pragma: public');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        intersoccer_log_audit('export_booking_excel', "Exported booking report for year $year, region $region, start_date $start_date, end_date $end_date, columns " . implode(',', $visible_columns));
        ob_end_flush();
    } catch (Exception $e) {
        error_log('InterSoccer: Booking report export error: ' . $e->getMessage() . ' on line ' . $e->getLine());
        ob_end_clean();
        wp_die(__('Export failed. Check server logs for details.', 'intersoccer-reports-rosters'));
    }
    exit;
}

/**
 * Render the Summer Camps Report tab content.
 */
function intersoccer_render_summer_camps_report_tab() {
    $year = isset($_GET['year']) ? sanitize_text_field($_GET['year']) : date('Y');
    $report_data = intersoccer_get_summer_camps_report($year);

    if (isset($_GET['action']) && $_GET['action'] === 'export' && check_admin_referer('export_summer_camps_nonce')) {
        intersoccer_export_summer_camps_csv($report_data, $year);
    }
    ?>
    <div class="wrap intersoccer-reports-rosters-reports-tab">
        <h2><?php echo esc_html("Summer Camps Numbers $year"); ?></h2>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="intersoccer-reports" />
            <input type="hidden" name="tab" value="summer-camps" />
            <label for="year"><?php _e('Year:', 'intersoccer-reports-rosters'); ?></label>
            <input type="number" name="year" id="year" value="<?php echo esc_attr($year); ?>" />
            <button type="submit" class="button"><?php _e('Filter', 'intersoccer-reports-rosters'); ?></button>
        </form>
        <div class="export-section">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url("admin.php?page=intersoccer-reports&tab=summer-camps&year=" . urlencode($year) . "&action=export"), 'export_summer_camps_nonce')); ?>" class="button button-primary"><?php _e('Export to CSV', 'intersoccer-reports-rosters'); ?></a>
        </div>
        <?php if (empty($report_data)): ?>
            <p><?php _e('No data available for the selected year.', 'intersoccer-reports-rosters'); ?></p>
        <?php else: ?>
            <table class="widefat fixed">
                <thead>
                    <tr>
                        <th rowspan="2">Week</th>
                        <th rowspan="2">Canton</th>
                        <th rowspan="2">Venue</th>
                        <th colspan="8">Full Day Camps</th>
                        <th colspan="8">Mini - Half Day Camps</th>
                    </tr>
                    <tr>
                        <th>Full Week</th><th>Individual Days - M</th><th>T</th><th>W</th><th>T</th><th>F</th><th>Total Min-Max</th><th></th>
                        <th>Full Week</th><th>Individual Days - M</th><th>T</th><th>W</th><th>T</th><th>F</th><th>Total Min-Max</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report_data as $week => $regions): ?>
                        <?php foreach ($regions as $region => $venues): ?>
                            <?php foreach ($venues as $venue => $camp_types): ?>
                                <tr>
                                    <?php if (!isset($current_week) || $current_week !== $week): ?>
                                        <td rowspan="<?php echo count($regions) * count($venues); ?>" style="background-color: #f0f0f0; font-weight: bold;"><?php echo esc_html($week); ?></td>
                                        <?php $current_week = $week; ?>
                                    <?php endif; ?>
                                    <td><?php echo esc_html($region); ?></td>
                                    <td><?php echo esc_html($venue); ?></td>
                                    <?php
                                    $full_day = $camp_types['Full Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                                    $mini = $camp_types['Mini - Half Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                                    ?>
                                    <td><?php echo esc_html($full_day['full_week']); ?></td>
                                    <?php foreach ($full_day['individual_days'] as $count): ?>
                                        <td><?php echo esc_html($count); ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo esc_html($full_day['min_max']); ?></td>
                                    <td></td>
                                    <td><?php echo esc_html($mini['full_week']); ?></td>
                                    <?php foreach ($mini['individual_days'] as $count): ?>
                                        <td><?php echo esc_html($count); ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo esc_html($mini['min_max']); ?></td>
                                    <td></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php unset($current_week); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Generate Summer Camps Report data from WooCommerce tables.
 *
 * @param string $year The year to filter the report (default: current year).
 * @return array Structured report data.
 */
function intersoccer_get_summer_camps_report($year = null) {
    global $wpdb;
    $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
    $posts_table = $wpdb->posts;
    $term_taxonomy_table = $wpdb->prefix . 'term_taxonomy';
    $terms_table = $wpdb->prefix . 'terms';

    if (!$year) {
        $year = date('Y');
    }

    // Define weeks for Summer based on camp_terms format
    $weeks = [
        'Week 1: June 24 - June 28' => 'week-1-june-24-28',
        'Week 2: June 30 - July 4' => 'week-2-june-30-july-4',
        'Week 3: July 8 - July 12' => 'week-3-july-8-12',
        'Week 4: July 15 - July 19' => 'week-4-july-15-19',
        'Week 5: July 22 - July 26' => 'week-5-july-22-26',
        'Week 6: July 29 - August 2' => 'week-6-july-29-august-2',
        'Week 7: August 5 - August 9' => 'week-7-august-5-9',
        'Week 8: August 12 - August 16' => 'week-8-august-12-16',
        'Week 9: August 19 - August 23' => 'week-9-august-19-23',
        'Week 10: August 26 - August 30' => 'week-10-august-26-30',
    ];

    // Fetch orders for camps
    $query = $wpdb->prepare(
        "SELECT 
            oi.order_item_id,
            om_region.meta_value AS region,
            t.name AS venue,
            om_camp_terms.meta_value AS camp_terms,
            om_booking_type.meta_value AS booking_type,
            om_selected_days.meta_value AS selected_days,
            om_age_group.meta_value AS age_group
         FROM $posts_table p
         JOIN $order_items_table oi ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
         LEFT JOIN $order_itemmeta_table om_region ON oi.order_item_id = om_region.order_item_id AND om_region.meta_key = 'Canton / Region'
         LEFT JOIN $order_itemmeta_table om_venue ON oi.order_item_id = om_venue.order_item_id AND om_venue.meta_key = 'pa_intersoccer-venues'
         LEFT JOIN $term_taxonomy_table tt ON om_venue.meta_value = tt.term_taxonomy_id AND tt.taxonomy = 'pa_intersoccer-venues'
         LEFT JOIN $terms_table t ON tt.term_id = t.term_id
         LEFT JOIN $order_itemmeta_table om_camp_terms ON oi.order_item_id = om_camp_terms.order_item_id AND om_camp_terms.meta_key = 'camp_terms'
         LEFT JOIN $order_itemmeta_table om_booking_type ON oi.order_item_id = om_booking_type.order_item_id AND om_booking_type.meta_key = 'booking_type'
         LEFT JOIN $order_itemmeta_table om_selected_days ON oi.order_item_id = om_selected_days.order_item_id AND om_selected_days.meta_key = 'selected_days'
         LEFT JOIN $order_itemmeta_table om_age_group ON oi.order_item_id = om_age_group.order_item_id AND om_age_group.meta_key = 'age_group'
         LEFT JOIN $order_itemmeta_table om_activity_type ON oi.order_item_id = om_activity_type.order_item_id AND om_activity_type.meta_key = 'Activity Type'
         WHERE p.post_type = 'shop_order'
         AND om_activity_type.meta_value = 'Camp'
         AND YEAR(p.post_date) = %d",
        $year
    );
    error_log("InterSoccer: Summer Camps Report Query: $query");
    $rosters = $wpdb->get_results($query, ARRAY_A);
    if (empty($rosters)) {
        error_log("InterSoccer: No rosters found for year $year");
        return [];
    }

    // Enrich roster data with camp type (Full Day vs. Mini - Half Day)
    foreach ($rosters as &$roster) {
        $roster['camp_type'] = (stripos($roster['age_group'], '3-5y') !== false || stripos($roster['age_group'], 'half-day') !== false) ? 'Mini - Half Day' : 'Full Day';
    }
    unset($roster);

    // Group rosters by week, region, venue, and camp type using camp_terms
    $report_data = [];
    foreach ($weeks as $week_name => $week_pattern) {
        $week_entries = array_filter($rosters, function($r) use ($week_pattern) {
            return preg_match("/\b$week_pattern\b/i", $r['camp_terms']) === 1;
        });
        if (empty($week_entries)) {
            continue;
        }

        $week_groups = [];
        foreach ($week_entries as $entry) {
            $region = $entry['region'] ?? 'Unknown';
            $venue = $entry['venue'] ?? 'Unknown';
            $camp_type = $entry['camp_type'];
            $key = "$region|$venue|$camp_type";
            $week_groups[$key][] = $entry;
        }

        $report_data[$week_name] = [];
        foreach ($week_groups as $key => $group) {
            list($region, $venue, $camp_type) = explode('|', $key);

            $full_week = 0;
            $individual_days = ['Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0];
            foreach ($group as $entry) {
                if (strtolower($entry['booking_type']) === 'full-week') {
                    $full_week++;
                } elseif (strtolower($entry['booking_type']) === 'single-days' && !empty($entry['selected_days'])) {
                    $days = array_map('trim', explode(',', $entry['selected_days']));
                    foreach ($days as $day) {
                        if (isset($individual_days[$day])) {
                            $individual_days[$day]++;
                        }
                    }
                }
            }

            $daily_counts = [];
            foreach ($individual_days as $day => $count) {
                $daily_counts[$day] = $full_week + $count;
            }
            $min = !empty($daily_counts) ? min($daily_counts) : 0;
            $max = !empty($daily_counts) ? max($daily_counts) : 0;

            $report_data[$week_name][$region][$venue][$camp_type] = [
                'full_week' => $full_week,
                'individual_days' => $individual_days,
                'min_max' => "$min-$max",
            ];
        }
    }

    return $report_data;
}

/**
 * Export Summer Camps Report to CSV.
 *
 * @param array $report_data The report data to export.
 * @param string $year The year of the report.
 */
function intersoccer_export_summer_camps_csv($report_data, $year) {
    // Clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    try {
        $filename = "summer_camps_numbers_$year_" . date('Y-m-d_H-i-s') . '.csv';
        error_log('InterSoccer: Sending headers for summer camps report export');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Expires: 0');
        header('Pragma: public');

        $output = fopen('php://output', 'w');

        // Headers
        fputcsv($output, ['', 'SUMMER CAMPS NUMBERS ' . $year]);
        fputcsv($output, ['', '', 'Full Day Camps', '', '', '', '', '', '', 'Mini - Half Day Camps']);
        fputcsv($output, ['', '', 'Full Week', 'Individual Days', '', '', '', '', 'Total Min-Max', 'Full Week', 'Individual Days', '', '', '', '', 'Total Min-Max']);
        fputcsv($output, ['', 'Week', 'Canton', 'Venue', 'M', 'T', 'W', 'T', 'F', '', 'M', 'T', 'W', 'T', 'F', '']);

        // Data
        for ($week_number = 1; $week_number <= 10; $week_number++) {
            $week_name = "Week $week_number";
            $regions = $report_data[$week_name] ?? [];
            if (empty($regions)) {
                continue;
            }
            foreach ($regions as $region => $venues) {
                foreach ($venues as $venue => $camp_types) {
                    $full_day = $camp_types['Full Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                    $mini = $camp_types['Mini - Half Day'] ?? ['full_week' => 0, 'individual_days' => array_fill_keys(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 0), 'min_max' => '0-0'];
                    fputcsv($output, [
                        '',
                        $week_name,
                        $region,
                        $venue,
                        $full_day['full_week'],
                        $full_day['individual_days']['Monday'],
                        $full_day['individual_days']['Tuesday'],
                        $full_day['individual_days']['Wednesday'],
                        $full_day['individual_days']['Thursday'],
                        $full_day['individual_days']['Friday'],
                        $full_day['min_max'],
                        $mini['full_week'],
                        $mini['individual_days']['Monday'],
                        $mini['individual_days']['Tuesday'],
                        $mini['individual_days']['Wednesday'],
                        $mini['individual_days']['Thursday'],
                        $mini['individual_days']['Friday'],
                        $mini['min_max'],
                    ]);
                }
            }
        }

        fclose($output);
        intersoccer_log_audit('export_summer_camps_csv', "Exported summer camps report for year $year");
        ob_end_flush();
    } catch (Exception $e) {
        error_log('InterSoccer: Summer camps report export error: ' . $e->getMessage() . ' on line ' . $e->getLine());
        ob_end_clean();
        wp_die(__('Export failed. Check server logs for details.', 'intersoccer-reports-rosters'));
    }
    exit;
}

/**
 * Log audit actions.
 *
 * @param string $action The action to log.
 * @param string $message The log message.
 */
function intersoccer_log_audit($action, $message) {
    error_log("InterSoccer Audit: [$action] $message");
}
?>