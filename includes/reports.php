<?php
/**
 * Reports page for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.3.98
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Start output buffering early
ob_start();
require_once dirname(__FILE__) . '/reporting-discounts.php';
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

    ?>
    <div class="wrap intersoccer-reports-rosters-reports">
        <?php intersoccer_render_booking_report_tab(); ?>
    </div>
    <?php
}

/**
 * Enqueue jQuery UI Datepicker and AJAX for auto-apply filters.
 */
function intersoccer_enqueue_datepicker() {
    if (isset($_GET['page']) && $_GET['page'] === 'intersoccer-reports') {
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        wp_enqueue_script('intersoccer-reports', plugin_dir_url(__FILE__) . 'js/reports.js', ['jquery'], '1.3.99', true);
        wp_localize_script('intersoccer-reports', 'intersoccerReports', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('intersoccer_reports_filter'),
        ]);
        
        // Enhanced inline script with better UX
        wp_add_inline_script('intersoccer-reports', '
            jQuery(document).ready(function($) {
                var updateTimeout;
                
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
                        nonce: intersoccerReports.nonce,
                        start_date: $("#start_date").val(),
                        end_date: $("#end_date").val(),
                        year: $("#year").val(),
                        region: $("#region").val(),
                        columns: $("input[name=\'columns[]\']:checked").map(function() { 
                            return this.value; 
                        }).get()
                    };
                    
                    $.ajax({
                        url: intersoccerReports.ajaxurl,
                        type: "POST",
                        data: formData,
                        timeout: 30000,
                        success: function(response) {
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
                    exportData.nonce = intersoccerReports.nonce;
                    
                    $.ajax({
                        url: intersoccerReports.ajaxurl,
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
        <h1><?php _e('📊 Booking Report Dashboard', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php _e('Filter and export detailed booking data with revenue analysis.', 'intersoccer-reports-rosters'); ?></p>
        
        <!-- Streamlined Filter Panel -->
        <div class="intersoccer-filters">
            <h3><?php _e('🔍 Filter Options', 'intersoccer-reports-rosters'); ?></h3>
            
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
                    <h4 style="margin: 0;"><?php _e('📋 Columns to Display', 'intersoccer-reports-rosters'); ?></h4>
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
                    💡 <?php _e('All columns available in Excel export', 'intersoccer-reports-rosters'); ?>
                </small>
                <button id="export-booking-report" class="button button-primary">
                    📥 <?php _e('Export to Excel', 'intersoccer-reports-rosters'); ?>
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
        // Get the EXACT same data that was displayed to the user using enhanced reporting
        $report_data = intersoccer_get_booking_report_enhanced($start_date, $end_date, $year, '');
        
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
 * Generate Booking Report data from WooCommerce tables.
 *
 * @param string $start_date Start date filter (YYYY-MM-DD).
 * @param string $end_date End date filter (YYYY-MM-DD).
 * @param string $year Year filter (default: current year).
 * @param string $region Region filter.
 * @return array Structured report data with totals.
 */
function intersoccer_get_booking_report($start_date = '', $end_date = '', $year = '', $region = '') {
    // Use the enhanced version
    return intersoccer_get_booking_report_enhanced($start_date, $end_date, $year, $region);
}

/**
 * Helper function to extract camp dates from camp terms
 * 
 * @param string $camp_terms The camp terms string
 * @param string $year The year to use for date parsing
 * @return array Start and end dates
 */
function intersoccer_parse_camp_dates_fixed($camp_terms, $season = '') {
    error_log('InterSoccer: Parsing camp dates from: ' . $camp_terms . ' with season: ' . $season);
    
    if (empty($camp_terms) || $camp_terms === 'N/A') {
        error_log('InterSoccer: Empty or N/A camp terms, returning defaults');
        return ['1970-01-01', '1970-01-01', 'N/A'];
    }
    
    // Extract year from season (e.g., "Summer 2025" -> 2025)
    preg_match('/\d{4}/', $season, $year_matches);
    $year = !empty($year_matches) ? $year_matches[0] : date('Y');
    error_log('InterSoccer: Using year: ' . $year);
    
    // Handle format: summer-week-10-august-25-29-5-days
    // This regex matches: (season)-(anything)-week-(number)-(month)-(start_day)-(end_day)-(number)-day(s)
    if (preg_match('/\w+-week-\d+-(\w+)-(\d{1,2})-(\d{1,2})-\d+-days?/', $camp_terms, $matches)) {
        $month_name = $matches[1];  // august
        $start_day = $matches[2];   // 25
        $end_day = $matches[3];     // 29
        
        error_log('InterSoccer: Parsed same-month camp terms - Month: ' . $month_name . ', Start: ' . $start_day . ', End: ' . $end_day . ', Year: ' . $year);
        
        try {
            // Create DateTime objects
            $start_date_obj = DateTime::createFromFormat('F j Y', ucfirst($month_name) . ' ' . $start_day . ' ' . $year);
            $end_date_obj = DateTime::createFromFormat('F j Y', ucfirst($month_name) . ' ' . $end_day . ' ' . $year);
            
            if ($start_date_obj && $end_date_obj) {
                $start_date = $start_date_obj->format('Y-m-d');
                $end_date = $end_date_obj->format('Y-m-d');
                $event_dates = "$start_date to $end_date";
                
                error_log('InterSoccer: Successfully parsed same-month camp dates - Start: ' . $start_date . ', End: ' . $end_date);
                return [$start_date, $end_date, $event_dates];
            } else {
                error_log('InterSoccer: DateTime creation failed for month: ' . ucfirst($month_name) . ', start: ' . $start_day . ', end: ' . $end_day . ', year: ' . $year);
                
                // Debug the DateTime creation
                if (!$start_date_obj) {
                    error_log('InterSoccer: Failed to create start date from: "' . ucfirst($month_name) . ' ' . $start_day . ' ' . $year . '"');
                }
                if (!$end_date_obj) {
                    error_log('InterSoccer: Failed to create end date from: "' . ucfirst($month_name) . ' ' . $end_day . ' ' . $year . '"');
                }
            }
        } catch (Exception $e) {
            error_log('InterSoccer: Exception parsing same-month camp dates: ' . $e->getMessage());
        }
    } else {
        error_log('InterSoccer: Regex did not match camp_terms format: ' . $camp_terms);
        
        // Try alternative regex patterns
        
        // Pattern for cross-month: summer-week-10-august-25-september-2-5-days
        if (preg_match('/\w+-week-\d+-(\w+)-(\d{1,2})-(\w+)-(\d{1,2})-\d+-days?/', $camp_terms, $matches)) {
            $start_month = $matches[1]; // august
            $start_day = $matches[2];   // 25
            $end_month = $matches[3];   // september
            $end_day = $matches[4];     // 2
            
            error_log('InterSoccer: Parsed cross-month camp terms - Start: ' . $start_month . ' ' . $start_day . ', End: ' . $end_month . ' ' . $end_day . ', Year: ' . $year);
            
            try {
                $start_date_obj = DateTime::createFromFormat('F j Y', ucfirst($start_month) . ' ' . $start_day . ' ' . $year);
                $end_date_obj = DateTime::createFromFormat('F j Y', ucfirst($end_month) . ' ' . $end_day . ' ' . $year);
                
                if ($start_date_obj && $end_date_obj) {
                    $start_date = $start_date_obj->format('Y-m-d');
                    $end_date = $end_date_obj->format('Y-m-d');
                    $event_dates = "$start_date to $end_date";
                    
                    error_log('InterSoccer: Successfully parsed cross-month camp dates - Start: ' . $start_date . ', End: ' . $end_date);
                    return [$start_date, $end_date, $event_dates];
                }
            } catch (Exception $e) {
                error_log('InterSoccer: Exception parsing cross-month camp dates: ' . $e->getMessage());
            }
        }
    }
    
    error_log('InterSoccer: Could not parse camp dates from: ' . $camp_terms . ', using defaults');
    return ['1970-01-01', '1970-01-01', 'N/A'];
}

/**
 * Helper function to validate and parse course dates
 * 
 * @param string $date_string The date string in mm/dd/yy format
 * @return string|false Formatted date (Y-m-d) or false if invalid
 */
function intersoccer_parse_course_date($date_string) {
    if (empty($date_string)) {
        return false;
    }
    
    // Handle mm/dd/yy format
    $parsed_date = DateTime::createFromFormat('m/d/y', $date_string);
    if ($parsed_date) {
        return $parsed_date->format('Y-m-d');
    }
    
    // Handle other common formats as fallback
    $timestamp = strtotime($date_string);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    error_log("InterSoccer: FIXED - Could not parse course date: $date_string");
    return false;
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
    $posts_table = $wpdb->prefix . 'posts';
    $term_taxonomy_table = $wpdb->prefix . 'term_taxonomy';
    $terms_table = $wpdb->prefix . 'terms';
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

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
         LEFT JOIN $terms_table t ON om_venue.meta_value = t.slug
         LEFT JOIN $term_taxonomy_table tt ON t.term_id = tt.term_id AND tt.taxonomy = 'pa_intersoccer-venues'
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
        $age_group = $roster['age_group'] ?? '';
        $roster['camp_type'] = (!empty($age_group) && (stripos($age_group, '3-5y') !== false || stripos($age_group, 'half-day') !== false)) ? 'Mini - Half Day' : 'Full Day';
    }
    unset($roster);

    // Group rosters by week, region, venue, and camp type using camp_terms
    $report_data = [];
    foreach ($weeks as $week_name => $week_pattern) {
        $week_entries = array_filter($rosters, function($r) use ($week_pattern) {
            return !empty($r['camp_terms']) && preg_match("/\b$week_pattern\b/i", $r['camp_terms']) === 1;
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
                if (strtolower($entry['booking_type'] ?? '') === 'full-week') {
                    $full_week++;
                } elseif (strtolower($entry['booking_type'] ?? '') === 'single-days' && !empty($entry['selected_days'])) {
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


function intersoccer_render_booking_totals($totals) {
    $net_revenue = $totals['final_price'] - $totals['reimbursement'];
    ?>
    <div class="report-totals" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h3 style="margin-top: 0; color: #0073aa;"><?php _e('📈 Financial Summary', 'intersoccer-reports-rosters'); ?></h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minspace(200px, 1fr)); gap: 15px;">
            <div class="summary-item">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;"><?php _e('Total Bookings', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 24px; font-weight: 600; color: #0073aa;"><?php echo esc_html(number_format($totals['bookings'])); ?></div>
            </div>
            <div class="summary-item">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;"><?php _e('Gross Revenue', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 24px; font-weight: 600; color: #28a745;"><?php echo wp_kses_post(wc_price($totals['base_price'])); ?></div>
            </div>
            <div class="summary-item">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;"><?php _e('Total Discounts', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 24px; font-weight: 600; color: #ffc107;"><?php echo wp_kses_post(wc_price($totals['discount_amount'])); ?></div>
            </div>
            <div class="summary-item">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;"><?php _e('Final Revenue', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 24px; font-weight: 600; color: #17a2b8;"><?php echo wp_kses_post(wc_price($totals['final_price'])); ?></div>
            </div>
            <div class="summary-item">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;"><?php _e('Reimbursements', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 24px; font-weight: 600; color: #dc3545;"><?php echo wp_kses_post(wc_price($totals['reimbursement'])); ?></div>
            </div>
            <div class="summary-item">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;"><?php _e('Stripe Fees', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 24px; font-weight: 600; color: #6c757d;"><?php echo wp_kses_post(wc_price($stripe_fee)); ?></div>
            </div>
            <div class="summary-item" style="border-left: 3px solid #0073aa; padding-left: 15px;">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;"><?php _e('Net Revenue (After Fees)', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #0073aa;"><?php echo wp_kses_post(wc_price($net_after_fees)); ?></div>
            </div>
        </div>
        
        <!-- Additional Metrics -->
        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <strong><?php _e('Average Order Value:', 'intersoccer-reports-rosters'); ?></strong> 
                    <span style="color: #0073aa; font-weight: 600;">
                        <?php echo $totals['bookings'] > 0 ? wp_kses_post(wc_price($totals['final_price'] / $totals['bookings'])) : wp_kses_post(wc_price(0)); ?>
                    </span>
                </div>
                <div>
                    <strong><?php _e('Discount Rate:', 'intersoccer-reports-rosters'); ?></strong> 
                    <span style="color: #ffc107; font-weight: 600;">
                        <?php echo $totals['base_price'] > 0 ? esc_html(number_format(($totals['discount_amount'] / $totals['base_price']) * 100, 1)) : '0.0'; ?>%
                    </span>
                </div>
                <div>
                    <strong><?php _e('Refund Rate:', 'intersoccer-reports-rosters'); ?></strong> 
                    <span style="color: #dc3545; font-weight: 600;">
                        <?php echo $totals['final_price'] > 0 ? esc_html(number_format(($totals['reimbursement'] / $totals['final_price']) * 100, 1)) : '0.0'; ?>%
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * NEW: Enhanced booking totals display with discount breakdown
 */
function intersoccer_render_enhanced_booking_totals($totals) {
    $net_revenue = $totals['final_price'] - $totals['reimbursement'];
    $stripe_fee = isset($totals['stripe_fee']) ? $totals['stripe_fee'] : 0;
    $net_after_fees = $net_revenue - $stripe_fee;
    ?>
    <div class="report-totals" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h3 style="margin-top: 0; color: #0073aa;"><?php _e('📈 Financial Summary', 'intersoccer-reports-rosters'); ?></h3>
        
        <!-- Main metrics grid - Improved for desktop readability -->
        <div style="
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 20px; 
            margin-bottom: 25px;
            /* Desktop optimization: 4 columns on larger screens */
            @media (min-width: 1200px) {
                grid-template-columns: repeat(4, 1fr);
            }
            @media (min-width: 768px) and (max-width: 1199px) {
                grid-template-columns: repeat(2, 1fr);
            }
        ">
            <div class="summary-item" style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef; transition: transform 0.2s ease;">
                <div style="font-size: 14px; color: #666; margin-bottom: 8px; font-weight: 500;"><?php _e('Total Bookings', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #0073aa; line-height: 1.2;"><?php echo esc_html(number_format($totals['bookings'])); ?></div>
            </div>
            <div class="summary-item" style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef; transition: transform 0.2s ease;">
                <div style="font-size: 14px; color: #666; margin-bottom: 8px; font-weight: 500;"><?php _e('Gross Revenue', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #28a745; line-height: 1.2;"><?php echo wp_kses_post(wc_price($totals['base_price'])); ?></div>
            </div>
            <div class="summary-item" style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef; transition: transform 0.2s ease;">
                <div style="font-size: 14px; color: #666; margin-bottom: 8px; font-weight: 500;"><?php _e('Total Discounts', 'intersoccer-reports-rosters'); ?> 
                    <span style="font-size: 12px; color: #999;">(Enhanced Tracking)</span>
                </div>
                <div style="font-size: 28px; font-weight: 700; color: #ffc107; line-height: 1.2;"><?php echo wp_kses_post(wc_price($totals['discount_amount'])); ?></div>
            </div>
            <div class="summary-item" style="background: #f8f9fa; padding:  20px; border-radius: 8px; border: 1px solid #e9ecef; transition: transform 0.2s ease;">
                <div style="font-size: 14px; color: #666; margin-bottom: 8px; font-weight: 500;"><?php _e('Final Revenue', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #17a2b8; line-height: 1.2;"><?php echo wp_kses_post(wc_price($totals['final_price'])); ?></div>
            </div>
            <div class="summary-item" style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef; transition: transform 0.2s ease;">
                <div style="font-size: 14px; color: #666; margin-bottom: 8px; font-weight: 500;"><?php _e('Reimbursements', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 24px; font-weight: 600; color: #dc3545;"><?php echo wp_kses_post(wc_price($totals['reimbursement'])); ?></div>
            </div>
            <div class="summary-item" style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef; transition: transform 0.2s ease;">
                <div style="font-size: 14px; color: #666; margin-bottom: 8px; font-weight: 500;"><?php _e('Stripe Fees', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 24px; font-weight: 600; color: #6c757d;"><?php echo wp_kses_post(wc_price($stripe_fee)); ?></div>
            </div>
            <div class="summary-item" style="
                background: linear-gradient(135deg, #0073aa 0%, #005a87 100%); 
                color: white; 
                padding: 20px; 
                border-radius: 8px; 
                border: 2px solid #0073aa; 
                grid-column: span 1;
                transition: transform 0.2s ease;
            ">
                <div style="font-size: 14px; color: rgba(255,255,255,0.9); margin-bottom: 8px; font-weight: 500;"><?php _e('Net Revenue (After Fees)', 'intersoccer-reports-rosters'); ?></div>
                <div style="font-size: 32px; font-weight: 800; color: white; line-height: 1.2; text-shadow: 0 1px 2px rgba(0,0,0,0.1);"><?php echo wp_kses_post(wc_price($net_after_fees)); ?></div>
            </div>
        </div>
        
        <!-- Enhanced metrics row - Improved spacing and layout -->
        <div style="margin-top: 25px; padding-top: 20px; border-top: 2px solid #e9ecef; background: #f8f9fa; border-radius: 6px; padding: 20px;">
            <div style="
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                align-items: center;
            ">
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <strong style="font-size: 13px; color: #495057; text-transform: uppercase; letter-spacing: 0.5px;"><?php _e('Average Order Value', 'intersoccer-reports-rosters'); ?></strong> 
                    <span style="font-size: 18px; font-weight: 700; color: #0073aa;">
                        <?php echo $totals['bookings'] > 0 ? wp_kses_post(wc_price($totals['final_price'] / $totals['bookings'])) : wp_kses_post(wc_price(0)); ?>
                    </span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <strong style="font-size: 13px; color: #495057; text-transform: uppercase; letter-spacing: 0.5px;"><?php _e('Discount Rate', 'intersoccer-reports-rosters'); ?></strong> 
                    <span style="font-size: 18px; font-weight: 700; color: #ffc107;">
                        <?php echo $totals['base_price'] > 0 ? esc_html(number_format(($totals['discount_amount'] / $totals['base_price']) * 100, 1)) : '0.0'; ?>%
                    </span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <strong style="font-size: 13px; color: #495057; text-transform: uppercase; letter-spacing: 0.5px;"><?php _e('Refund Rate', 'intersoccer-reports-rosters'); ?></strong> 
                    <span style="font-size: 18px; font-weight: 700; color: #dc3545;">
                        <?php echo $totals['final_price'] > 0 ? esc_html(number_format(($totals['reimbursement'] / $totals['final_price']) * 100, 1)) : '0.0'; ?>%
                    </span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <strong style="font-size: 13px; color: #495057; text-transform: uppercase; letter-spacing: 0.5px;"><?php _e('Stripe Fee Rate', 'intersoccer-reports-rosters'); ?></strong> 
                    <span style="font-size: 18px; font-weight: 700; color: #6c757d;">
                        <?php echo $totals['final_price'] > 0 ? esc_html(number_format(($stripe_fee / $totals['final_price']) * 100, 1)) : '0.0'; ?>%
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .summary-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        @media (max-width: 767px) {
            .report-totals .summary-item {
                padding: 15px !important;
            }
            .report-totals .summary-item div:first-child {
                font-size: 12px !important;
            }
            .report-totals .summary-item div:last-child {
                font-size: 24px !important;
            }
        }
    </style>
    <?php
}

/**
 * NEW: Calculate discount type breakdown for detailed reporting
 */
function intersoccer_calculate_discount_type_breakdown($report_data) {
    $breakdown = [
        'sibling' => 0,
        'same_season' => 0,
        'coupon' => 0,
        'other' => 0
    ];
    
    foreach ($report_data as $row) {
        $discount_codes = strtolower($row['discount_codes'] ?? '');
        $discount_amount = floatval(str_replace(',', '', $row['discount_amount'] ?? 0));
        
        if (strpos($discount_codes, 'sibling') !== false || 
            strpos($discount_codes, 'multi-child') !== false ||
            strpos($discount_codes, 'camp combo') !== false ||
            strpos($discount_codes, 'course') !== false && strpos($discount_codes, 'discount') !== false) {
            $breakdown['sibling'] += $discount_amount;
        } elseif (strpos($discount_codes, 'same season') !== false) {
            $breakdown['same_season'] += $discount_amount;
        } elseif (strpos($discount_codes, 'coupon') !== false || 
                  strpos($discount_codes, 'order') !== false) {
            $breakdown['coupon'] += $discount_amount;
        } else {
            $breakdown['other'] += $discount_amount;
        }
    }
    
    return $breakdown;
}