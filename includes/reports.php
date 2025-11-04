<?php
/**
 * Reports page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.3.98
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Start output buffering early - but NOT for AJAX requests
if (!defined('DOING_AJAX') || !DOING_AJAX) {
    ob_start();
}
require_once dirname(__FILE__) . '/reporting-discounts.php';
require_once dirname(__FILE__) . '/reports-ui.php';
require_once dirname(__FILE__) . '/reports-data.php';
require_once dirname(__FILE__) . '/reports-export.php';
require_once dirname(__FILE__) . '/reports-ajax.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Enqueue jQuery UI Datepicker and AJAX for auto-apply filters.
 */
function intersoccer_enqueue_datepicker() {
    if (isset($_GET['page']) && $_GET['page'] === 'intersoccer-reports') {
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        wp_enqueue_script('intersoccer-reports', plugin_dir_url(__FILE__) . '../js/reports.js', ['jquery'], '1.3.99', true);
        wp_localize_script('intersoccer-reports', 'intersoccer_reports_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('intersoccer_reports_filter'),
        ]);
        
        // Enhanced inline script with better UX
        wp_add_inline_script('intersoccer-reports', '
            jQuery(document).ready(function($) {
                console.log("InterSoccer: Reports JavaScript loaded and document ready");
                
                // Enhanced datepicker with better options
                $("#start_date, #end_date").datepicker({
                    dateFormat: "yy-mm-dd",
                    changeMonth: true,
                    changeYear: true,
                    yearRange: "2020:+2",
                    maxDate: "+1y",
                    onSelect: function() {
                        validateDateRange();
                        intersoccerUpdateReport();
                    }
                });
                
                // Auto-filter on input changes with debouncing
                $("#region, #year").on("change", function() {
                    intersoccerUpdateReport();
                });
                
                // Debounced column checkbox changes
                $("input[name=\'columns[]\']").on("change", function() {
                    clearTimeout(updateTimeout);
                    updateTimeout = setTimeout(intersoccerUpdateReport, 300);
                });
                
                // Date range validation
                function validateDateRange() {
                    var startDate = $("#start_date").val();
                    var endDate = $("#end_date").val();
                    var errorMsg = $("#date-error-message");
                    
                    if (startDate && endDate) {
                        if (new Date(startDate) > new Date(endDate)) {
                            errorMsg.show().text("Start date must be before end date");
                            $("#export-booking-report").prop("disabled", true);
                            return false;
                        }
                    }
                    errorMsg.hide();
                    $("#export-booking-report").prop("disabled", false);
                    return true;
                }
                
                // Quick date range buttons
                $(".quick-date-range").on("click", function(e) {
                    e.preventDefault();
                    var range = $(this).data("range");
                    var today = new Date();
                    var startDate, endDate;
                    
                    switch(range) {
                        case "today":
                            startDate = endDate = today;
                            break;
                        case "week":
                            startDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 7);
                            endDate = today;
                            break;
                        case "month":
                            startDate = new Date(today.getFullYear(), today.getMonth() - 1, today.getDate());
                            endDate = today;
                            break;
                        case "quarter":
                            startDate = new Date(today.getFullYear(), today.getMonth() - 3, today.getDate());
                            endDate = today;
                            break;
                        case "year":
                            startDate = new Date(today.getFullYear() - 1, today.getMonth(), today.getDate());
                            endDate = today;
                            break;
                        case "clear":
                            $("#start_date, #end_date").val("");
                            intersoccerUpdateReport();
                            return;
                    }
                    
                    $("#start_date").val(startDate.toISOString().split("T")[0]);
                    $("#end_date").val(endDate.toISOString().split("T")[0]);
                    validateDateRange();
                    intersoccerUpdateReport();
                });
                
                // Enhanced update function with loading states
                function intersoccerUpdateReport() {
                    console.log("InterSoccer: intersoccerUpdateReport called");
                    if (!validateDateRange()) return;
                    
                    var $loadingIndicator = $("#loading-indicator");
                    var $tableContainer = $("#intersoccer-report-table");
                    var $totalsContainer = $("#intersoccer-report-totals");
                    
                    // Show loading state
                    $loadingIndicator.show();
                    $tableContainer.addClass("loading");
                    $("#export-booking-report").prop("disabled", true).text("Loading...");
                    
                    var formData = {
                        action: "intersoccer_filter_report",
                        nonce: intersoccer_reports_ajax.nonce,
                        start_date: $("#start_date").val(),
                        end_date: $("#end_date").val(),
                        year: $("#year").val(),
                        region: $("#region").val(),
                        columns: $("input[name=\'columns[]\']:checked").map(function() { 
                            return this.value; 
                        }).get()
                    };
                    
                    console.log("InterSoccer: Sending AJAX request with data:", formData);
                    
                    $.ajax({
                        url: intersoccer_reports_ajax.ajaxurl,
                        type: "POST",
                        data: formData,
                        timeout: 30000,
                        success: function(response) {
                            console.log("InterSoccer: AJAX success response:", response);
                            if (response.success) {
                                $tableContainer.html(response.data.table).removeClass("loading");
                                $totalsContainer.html(response.data.totals);
                                
                                // Update record count
                                var recordCount = $(response.data.table).find("tbody tr").length;
                                $("#record-count").text(recordCount + " records found");
                                
                                // Store current filters for export
                                window.intersoccerCurrentFilters = formData;
                            } else {
                                $tableContainer.html("<p class=\"error\">Error: " + (response.data.message || "Unknown error") + "</p>");
                                console.error("Filter error:", response.data.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX error:", error, xhr.responseText);
                            $tableContainer.html("<p class=\"error\">Connection error. Please try again.</p>");
                            console.error("AJAX error:", error);
                        },
                        complete: function() {
                            $loadingIndicator.hide();
                            $("#export-booking-report").prop("disabled", false).text("Export to Excel");
                        }
                    });
                }
                
                // Enhanced export with current filters
                $("#export-booking-report").on("click", function(e) {
                    e.preventDefault();
                    
                    if (!validateDateRange()) {
                        alert("Please fix date range errors before exporting.");
                        return;
                    }
                    
                    var $exportBtn = $(this);
                    $exportBtn.prop("disabled", true).text("Exporting...");
                    
                    // Use stored filters or current form state
                    var exportData = window.intersoccerCurrentFilters || {
                        start_date: $("#start_date").val(),
                        end_date: $("#end_date").val(),
                        year: $("#year").val(),
                        region: $("#region").val(),
                        columns: $("input[name=\'columns[]\']:checked").map(function() { 
                            return this.value; 
                        }).get()
                    };
                    
                    exportData.action = "intersoccer_export_booking_report";
                    exportData.nonce = intersoccer_reports_ajax.nonce;
                    
                    $.ajax({
                        url: intersoccer_reports_ajax.ajaxurl,
                        type: "POST",
                        data: exportData,
                        timeout: 60000,
                        success: function(response) {
                            if (response.success) {
                                // Create and trigger download
                                var binary = atob(response.data.content);
                                var array = new Uint8Array(binary.length);
                                for (var i = 0; i < binary.length; i++) {
                                    array[i] = binary.charCodeAt(i);
                                }
                                var blob = new Blob([array], {
                                    type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                });
                                var link = document.createElement("a");
                                link.href = window.URL.createObjectURL(blob);
                                link.download = response.data.filename;
                                document.body.appendChild(link);
                                link.click();
                                document.body.removeChild(link);
                                
                                // Show success message
                                showNotification("Export completed successfully!", "success");
                            } else {
                                showNotification("Export failed: " + (response.data.message || "Unknown error"), "error");
                                console.error("Export error:", response.data.message);
                            }
                        },
                        error: function(xhr, status, error) {
                            if (status === "timeout") {
                                showNotification("Export timeout. Please try with a smaller date range.", "error");
                            } else {
                                showNotification("Export failed: Connection error", "error");
                            }
                            console.error("AJAX export error:", error);
                        },
                        complete: function() {
                            $exportBtn.prop("disabled", false).text("Export to Excel");
                        }
                    });
                });
                
                // Notification system
                function showNotification(message, type) {
                    var $notification = $("<div class=\"notice notice-" + type + " is-dismissible\"><p>" + message + "</p></div>");
                    $(".wrap h1").after($notification);
                    setTimeout(function() {
                        $notification.fadeOut();
                    }, 5000);
                }
                
                // Initialize with current data
                // Set default dates if fields are empty on page load
                if (!$("#start_date").val() && !$("#end_date").val()) {
                    var today = new Date();
                    var thirtyDaysAgo = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 30);
                    $("#start_date").val(thirtyDaysAgo.toISOString().split("T")[0]);
                    $("#end_date").val(today.toISOString().split("T")[0]);
                }
                console.log("InterSoccer: About to call initial intersoccerUpdateReport");
                intersoccerUpdateReport();
            });
        ');
        
        // Add custom CSS for better UX
        wp_add_inline_style('jquery-ui-css', '
            .intersoccer-filters {
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                margin: 20px 0;
            }
            .filter-row {
                display: flex;
                gap: 15px;
                align-items: center;
                margin-bottom: 15px;
                flex-wrap: wrap;
            }
            .filter-group {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            .filter-group label {
                font-weight: 600;
                font-size: 13px;
            }
            .quick-dates {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            .quick-date-range {
                padding: 4px 8px;
                font-size: 12px;
                border-radius: 3px;
                border: 1px solid #ccc;
                background: #fff;
                cursor: pointer;
                text-decoration: none;
            }
            .quick-date-range:hover {
                background: #f0f0f0;
            }
            .columns-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 10px;
                margin-top: 10px;
            }
            .column-checkbox {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 5px;
                background: #fff;
                border-radius: 3px;
                border: 1px solid #e0e0e0;
            }
            #loading-indicator {
                display: none;
                color: #0073aa;
                font-weight: 600;
            }
            .loading {
                opacity: 0.6;
                pointer-events: none;
            }
            #date-error-message {
                color: #d63638;
                font-size: 13px;
                margin-top: 5px;
                display: none;
            }
            .stats-bar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin: 15px 0;
                padding: 10px;
                background: #fff;
                border-left: 4px solid #0073aa;
            }
            #record-count {
                font-weight: 600;
                color: #0073aa;
            }
            .intersoccer-reports-rosters-reports-tab h1 {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .intersoccer-reports-rosters-reports-tab p {
                color: #666;
                font-style: italic;
                margin-bottom: 20px;
            }
        ');
    }
}
add_action('admin_enqueue_scripts', 'intersoccer_enqueue_datepicker');

/**
 * Handle AJAX filter request for booking report.
 */
add_action('wp_ajax_intersoccer_filter_report', 'intersoccer_filter_report_callback');

/**
 * Render the Booking Report tab content.
 */
function intersoccer_render_booking_report_tab() {
    // Get current filters from URL or defaults
    $default_end_date = date('Y-m-d'); // Today
    $default_start_date = date('Y-m-d', strtotime('-30 days')); // 30 days ago

    $start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) 
        ? sanitize_text_field($_GET['start_date']) 
        : $default_start_date;
        
    $end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) 
        ? sanitize_text_field($_GET['end_date']) 
        : $default_end_date;

    // Also update the year logic to use current year as fallback only
    $year = isset($_GET['year']) ? sanitize_text_field($_GET['year']) : date('Y');
    
    // Default visible columns - focused view for finance team
    $default_columns = ['ref', 'booked', 'base_price', 'discount_amount', 'stripe_fee', 'final_price', 
                       'class_name', 'venue', 'booker_email'];
    $visible_columns = isset($_GET['columns']) ? array_map('sanitize_text_field', (array)$_GET['columns']) : $default_columns;

    // Define all possible columns
    $all_columns = [
        'ref' => __('Reference', 'intersoccer-reports-rosters'),
        'booked' => __('Booking Date', 'intersoccer-reports-rosters'),
        'base_price' => __('Base Price (CHF)', 'intersoccer-reports-rosters'),
        'discount_amount' => __('Discount (CHF)', 'intersoccer-reports-rosters'),
        'reimbursement' => __('Reimbursement (CHF)', 'intersoccer-reports-rosters'),
        'stripe_fee' => __('Stripe Fee (CHF)', 'intersoccer-reports-rosters'),
        'final_price' => __('Final Price (CHF)', 'intersoccer-reports-rosters'),
        'discount_codes' => __('Discount Codes Used', 'intersoccer-reports-rosters'),
        'class_name' => __('Event/Class Name', 'intersoccer-reports-rosters'),
        'start_date' => __('Event Start Date', 'intersoccer-reports-rosters'),
        'venue' => __('Venue', 'intersoccer-reports-rosters'),
        'booker_email' => __('Booker Email', 'intersoccer-reports-rosters'),
        'attendee_name' => __('Child/Attendee Name', 'intersoccer-reports-rosters'),
        'attendee_age' => __('Attendee Age', 'intersoccer-reports-rosters'),
        'attendee_gender' => __('Attendee Gender', 'intersoccer-reports-rosters'),
        'parent_phone' => __('Emergency Phone', 'intersoccer-reports-rosters'),
    ];
    ?>
    <div class="wrap intersoccer-reports-rosters-reports-tab">
        <h1><?php _e('Booking Report Dashboard', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php _e('Filter and export detailed booking data with revenue analysis.', 'intersoccer-reports-rosters'); ?></p>
        
        <!-- Streamlined Filter Panel -->
        <div class="intersoccer-filters">
            <h3><?php _e('Filter Options', 'intersoccer-reports-rosters'); ?></h3>
            
            <!-- Primary Date Controls -->
            <div class="filter-row">
                <div class="filter-group">
                    <label for="start_date"><?php _e('Start Date:', 'intersoccer-reports-rosters'); ?></label>
                    <input type="text" name="start_date" id="start_date" value="<?php echo esc_attr($start_date); ?>" 
                           placeholder="YYYY-MM-DD" style="width: 140px;" />
                </div>
                <div class="filter-group">
                    <label for="end_date"><?php _e('End Date:', 'intersoccer-reports-rosters'); ?></label>
                    <input type="text" name="end_date" id="end_date" value="<?php echo esc_attr($end_date); ?>" 
                           placeholder="YYYY-MM-DD" style="width: 140px;" />
                </div>
                <div class="filter-group">
                    <label for="year"><?php _e('Year (if no dates):', 'intersoccer-reports-rosters'); ?></label>
                    <input type="number" name="year" id="year" value="<?php echo esc_attr($year); ?>" 
                           min="2020" max="<?php echo date('Y') + 2; ?>" style="width: 100px;" />
                </div>
            </div>
            
            <!-- Quick Date Range Buttons -->
            <div class="filter-row">
                <strong><?php _e('Quick Ranges:', 'intersoccer-reports-rosters'); ?></strong>
                <div class="quick-dates">
                    <a href="#" class="quick-date-range button button-small" data-range="today"><?php _e('Today', 'intersoccer-reports-rosters'); ?></a>
                    <a href="#" class="quick-date-range button button-small" data-range="week"><?php _e('Last 7 Days', 'intersoccer-reports-rosters'); ?></a>
                    <a href="#" class="quick-date-range button button-small" data-range="month"><?php _e('Last 30 Days', 'intersoccer-reports-rosters'); ?></a>
                    <a href="#" class="quick-date-range button button-small" data-range="quarter"><?php _e('Last 3 Months', 'intersoccer-reports-rosters'); ?></a>
                    <a href="#" class="quick-date-range button button-small" data-range="year"><?php _e('Last Year', 'intersoccer-reports-rosters'); ?></a>
                    <a href="#" class="quick-date-range button button-small" data-range="clear"><?php _e('Clear Dates', 'intersoccer-reports-rosters'); ?></a>
                </div>
            </div>
            
            <div id="date-error-message"></div>
            
            <!-- Collapsible Column Selection -->
            <div class="filter-row" style="flex-direction: column; align-items: flex-start;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <h4 style="margin: 0;"><?php _e('Columns to Display', 'intersoccer-reports-rosters'); ?></h4>
                    <button type="button" id="toggle-columns" class="button button-small" style="font-size: 12px;">
                        <?php _e('Show/Hide Columns', 'intersoccer-reports-rosters'); ?>
                    </button>
                </div>
                <div id="columns-panel" class="columns-grid" style="display: none; margin-top: 10px;">
                    <?php foreach ($all_columns as $key => $label): ?>
                        <label class="column-checkbox">
                            <input type="checkbox" name="columns[]" value="<?php echo esc_attr($key); ?>" 
                                   <?php checked(in_array($key, $visible_columns)); ?> />
                            <span><?php echo esc_html($label); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Stats and Export Bar -->
        <div class="stats-bar">
            <div>
                <span id="loading-indicator">🔄 Loading data...</span>
                <span id="record-count">0 records found</span>
            </div>
            <div style="display: flex; align-items: center; gap: 15px;">
                <small style="color: #666; font-style: italic;">
                    <?php _e('Note: All columns available in Excel export', 'intersoccer-reports-rosters'); ?>
                </small>
                <button id="export-booking-report" class="button button-primary">
                    ↓ <?php _e('Export to Excel', 'intersoccer-reports-rosters'); ?>
                </button>
            </div>
        </div>
        
        <!-- Results Section -->
        <div id="intersoccer-report-totals"></div>
        <div id="intersoccer-report-table"></div>
    </div>
    <?php
}

/**
 * Handle AJAX export request for booking report.
 */
function intersoccer_export_booking_report_callback() {
    check_ajax_referer('intersoccer_reports_filter', 'nonce');

    // Get and validate the same filters used in the display
    $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
    $year = isset($_POST['year']) ? absint($_POST['year']) : date('Y');
    $visible_columns = isset($_POST['columns']) ? array_map('sanitize_text_field', (array)$_POST['columns']) : [
        'ref', 'booked', 'base_price', 'discount_amount', 'reimbursement', 'stripe_fee', 'final_price', 'discount_codes',
        'class_name', 'start_date', 'venue', 'booker_email', 'attendee_name', 'attendee_age', 'attendee_gender', 'parent_phone'
    ];

    // Validate inputs
    if ($start_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        wp_send_json_error(['message' => 'Invalid start date format. Use YYYY-MM-DD.']);
    }
    if ($end_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        wp_send_json_error(['message' => 'Invalid end date format. Use YYYY-MM-DD.']);
    }
    if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
        wp_send_json_error(['message' => 'Start date must be before or equal to end date.']);
    }

    try {
        // Get the EXACT same data that was displayed to the user using simplified financial reporting
        $report_data = intersoccer_get_financial_booking_report($start_date, $end_date, $year, '');
        
        if (empty($report_data['data'])) {
            wp_send_json_error(['message' => __('No data available for export with current filters.', 'intersoccer-reports-rosters')]);
        }

        // Log export parameters for debugging
        error_log('InterSoccer ENHANCED: Exporting ' . count($report_data['data']) . ' records with enhanced discount data');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Create descriptive sheet title
        $date_range = '';
        if ($start_date && $end_date) {
            $date_range = date_i18n('M j', strtotime($start_date)) . ' - ' . date_i18n('M j Y', strtotime($end_date));
        } else {
            $date_range = $year;
        }
        $sheet_title = 'Enhanced Bookings ' . $date_range;
        $sheet->setTitle(substr($sheet_title, 0, 31)); // Excel limit
        
        // Define column headers with enhanced discount information
        $all_columns = [
            'ref' => __('Reference', 'intersoccer-reports-rosters'),
            'order_id' => __('Order ID', 'intersoccer-reports-rosters'),
            'booked' => __('Booking Date', 'intersoccer-reports-rosters'),
            'base_price' => __('Base Price (CHF)', 'intersoccer-reports-rosters'),
            'discount_amount' => __('Total Discount (CHF)', 'intersoccer-reports-rosters'),
            'reimbursement' => __('Reimbursement (CHF)', 'intersoccer-reports-rosters'),
            'stripe_fee' => __('Stripe Fee (CHF)', 'intersoccer-reports-rosters'),
            'final_price' => __('Final Price (CHF)', 'intersoccer-reports-rosters'),
            'discount_codes' => __('Discount Details', 'intersoccer-reports-rosters'),
            'class_name' => __('Event/Class Name', 'intersoccer-reports-rosters'),
            'start_date' => __('Event Start Date', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'booker_email' => __('Booker Email', 'intersoccer-reports-rosters'),
            'attendee_name' => __('Attendee Name', 'intersoccer-reports-rosters'),
            'attendee_age' => __('Age', 'intersoccer-reports-rosters'),
            'attendee_gender' => __('Gender', 'intersoccer-reports-rosters'),
            'parent_phone' => __('Emergency Phone', 'intersoccer-reports-rosters'),
        ];

        // Create header row
        $header_row = [];
        foreach ($visible_columns as $key) {
            if (isset($all_columns[$key])) {
                $header_row[] = $all_columns[$key];
            }
        }
        $sheet->fromArray($header_row, null, 'A1');

        // Style header row
        $header_range = 'A1:' . chr(64 + count($header_row)) . '1';
        $sheet->getStyle($header_range)->getFont()->setBold(true);
        $sheet->getStyle($header_range)->getFill()
              ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
              ->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle($header_range)->getFont()->getColor()->setARGB('FFFFFFFF');

        // Add data rows
        $row_index = 2;
        foreach ($report_data['data'] as $data_row) {
            $excel_row = [];
            foreach ($visible_columns as $key) {
                $value = $data_row[$key] ?? '';
                
                // Clean numeric values for Excel
                if (in_array($key, ['base_price', 'discount_amount', 'reimbursement', 'final_price'])) {
                    $excel_row[] = floatval(str_replace([',', ' CHF'], '', $value));
                } else {
                    $excel_row[] = $value;
                }
            }
            $sheet->fromArray($excel_row, null, 'A' . $row_index);
            $row_index++;
        }

        // Format currency columns
        foreach ($visible_columns as $col_index => $key) {
            if (in_array($key, ['base_price', 'discount_amount', 'reimbursement', 'final_price'])) {
                $col_letter = chr(65 + $col_index);
                $range = $col_letter . '2:' . $col_letter . ($row_index - 1);
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00 "CHF"');
            }
        }

        // ENHANCED: Add comprehensive financial summary for finance team
        $totals_start = $row_index + 2;
        
        // Title row
        $sheet->setCellValue('A' . $totals_start, '=== FINANCIAL SUMMARY ===');
        $sheet->getStyle('A' . $totals_start)->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF0073AA');
        $sheet->mergeCells('A' . $totals_start . ':D' . $totals_start);
        
        // Enhanced summary with discount breakdowns
        $net_revenue = $report_data['totals']['final_price'] - $report_data['totals']['reimbursement'];
        $avg_order_value = $report_data['totals']['bookings'] > 0 ? $report_data['totals']['final_price'] / $report_data['totals']['bookings'] : 0;
        $discount_rate = $report_data['totals']['base_price'] > 0 ? ($report_data['totals']['discount_amount'] / $report_data['totals']['base_price']) * 100 : 0;
        $refund_rate = $report_data['totals']['final_price'] > 0 ? ($report_data['totals']['reimbursement'] / $report_data['totals']['final_price']) * 100 : 0;
        
        // Calculate discount type breakdowns
        $discount_type_totals = intersoccer_calculate_discount_type_breakdown($report_data['data']);
        
        $summary_data = [
            ['Metric', 'Value', 'Notes'],
            ['Total Bookings:', $report_data['totals']['bookings'], 'Individual line items processed'],
            ['Gross Revenue:', $report_data['totals']['base_price'], 'Before any discounts applied'],
            ['Total Discounts:', $report_data['totals']['discount_amount'], 'All discount types combined'],
            ['- Sibling Discounts:', $discount_type_totals['sibling'], 'Multi-child camp/course discounts'],
            ['- Same Season Discounts:', $discount_type_totals['same_season'], '50% second course same season'],
            ['- Coupon Discounts:', $discount_type_totals['coupon'], 'Promotional codes used'],
            ['- Other Discounts:', $discount_type_totals['other'], 'Legacy and other discount types'],
            ['Final Revenue:', $report_data['totals']['final_price'], 'After all discounts applied'],
            ['Reimbursements:', $report_data['totals']['reimbursement'], 'Refunds processed'],
            ['NET REVENUE:', $net_revenue, 'Final revenue minus refunds'],
            ['', '', ''], // Spacer
            ['Average Order Value:', $avg_order_value, 'Final revenue per booking'],
            ['Discount Rate:', $discount_rate, 'Percentage of gross revenue discounted'],
            ['Refund Rate:', $refund_rate, 'Percentage of final revenue refunded'],
            ['Discount Effectiveness:', $discount_type_totals['sibling'] / max($report_data['totals']['discount_amount'], 1) * 100, 'Percentage of discounts from sibling policy']
        ];
        
        $current_row = $totals_start + 1;
        foreach ($summary_data as $index => $summary_row) {
            $sheet->setCellValue('A' . $current_row, $summary_row[0]);
            $sheet->setCellValue('B' . $current_row, $summary_row[1]);
            $sheet->setCellValue('C' . $current_row, $summary_row[2]);
            
            // Format the header row
            if ($index === 0) {
                $range = 'A' . $current_row . ':C' . $current_row;
                $sheet->getStyle($range)->getFont()->setBold(true);
                $sheet->getStyle($range)->getFill()
                      ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFE6F3FF');
            }
            // Format NET REVENUE row specially
            elseif ($summary_row[0] === 'NET REVENUE:') {
                $range = 'A' . $current_row . ':C' . $current_row;
                $sheet->getStyle($range)->getFont()->setBold(true)->setSize(12);
                $sheet->getStyle($range)->getFill()
                      ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFFFEB3B');
            }
            // Format discount category rows
            elseif (strpos($summary_row[0], '- ') === 0) {
                $sheet->getStyle('A' . $current_row)->getFont()->setItalic(true);
            }
            
            // Format currency values
            if (is_numeric($summary_row[1]) && $summary_row[1] > 0 && !strpos($summary_row[0], 'Rate') && !strpos($summary_row[0], 'Bookings') && !strpos($summary_row[0], 'Average') && !strpos($summary_row[0], 'Effectiveness')) {
                $sheet->getStyle('B' . $current_row)->getNumberFormat()->setFormatCode('#,##0.00 "CHF"');
            }
            // Format percentage values
            elseif (strpos($summary_row[0], 'Rate') !== false || strpos($summary_row[0], 'Effectiveness') !== false) {
                $sheet->getStyle('B' . $current_row)->getNumberFormat()->setFormatCode('0.0"%"');
                $sheet->setCellValue('B' . $current_row, $summary_row[1] / 100); // Convert to decimal for Excel
            }
            // Format average order value
            elseif (strpos($summary_row[0], 'Average') !== false) {
                $sheet->getStyle('B' . $current_row)->getNumberFormat()->setFormatCode('#,##0.00 "CHF"');
            }
            
            $current_row++;
        }
        
        // Add generation info at the bottom
        $info_row = $current_row + 1;
        $generation_info = 'Enhanced Report Generated: ' . date('Y-m-d H:i:s') . ' | ';
        if ($start_date && $end_date) {
            $generation_info .= "Period: {$start_date} to {$end_date} | ";
        } else {
            $generation_info .= "Year: {$year} | ";
        }
        $generation_info .= 'Records: ' . count($report_data['data']) . ' | Discount System: Enhanced';
        
        $sheet->setCellValue('A' . $info_row, $generation_info);
        $sheet->getStyle('A' . $info_row)->getFont()->setItalic(true)->setSize(10);
        $sheet->mergeCells('A' . $info_row . ':D' . $info_row);

        // Auto-size columns
        foreach (range('A', chr(64 + max(count($header_row), 4))) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Generate and send file
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        // Create descriptive filename
        $filename_parts = ['enhanced_booking_report'];
        if ($start_date && $end_date) {
            $filename_parts[] = date('Y-m-d', strtotime($start_date)) . '_to_' . date('Y-m-d', strtotime($end_date));
        } else {
            $filename_parts[] = $year;
        }
        $filename_parts[] = date('Y-m-d_H-i-s');
        $filename = implode('_', $filename_parts) . '.xlsx';

        error_log('InterSoccer ENHANCED: Export completed with enhanced discount tracking. File: ' . $filename . ', Records: ' . count($report_data['data']));
        
        wp_send_json_success([
            'content' => base64_encode($content),
            'filename' => $filename,
            'record_count' => count($report_data['data']),
            'file_size' => strlen($content),
            'enhancement' => 'Enhanced discount tracking enabled'
        ]);

    } catch (\Throwable $e) {
        error_log('InterSoccer ENHANCED: Export error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json_error([
            'message' => __('Enhanced export failed: ', 'intersoccer-reports-rosters') . $e->getMessage()
        ]);
    }
}
add_action('wp_ajax_intersoccer_export_booking_report', 'intersoccer_export_booking_report_callback');

/**
 * Calculate discount type breakdown from booking report data
 */
function intersoccer_calculate_discount_type_breakdown($report_data) {
    $totals = [
        'sibling' => 0,
        'same_season' => 0,
        'coupon' => 0,
        'other' => 0
    ];

    foreach ($report_data as $row) {
        $discount_amount = floatval(str_replace([',', ' CHF'], '', $row['discount_amount'] ?? '0'));
        $discount_codes = strtolower($row['discount_codes'] ?? '');

        // Skip if no discount
        if ($discount_amount <= 0) {
            continue;
        }

        // Categorize discount types based on discount codes
        if (strpos($discount_codes, 'sibling') !== false || strpos($discount_codes, 'multi-child') !== false) {
            $totals['sibling'] += $discount_amount;
        } elseif (strpos($discount_codes, 'same-season') !== false || strpos($discount_codes, 'second-course') !== false) {
            $totals['same_season'] += $discount_amount;
        } elseif (!empty($discount_codes) && $discount_codes !== 'none') {
            // Check if it's a coupon code (not empty and not 'none')
            $totals['coupon'] += $discount_amount;
        } else {
            // Any other discount type
            $totals['other'] += $discount_amount;
        }
    }

    return $totals;
}