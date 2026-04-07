<?php
/**
 * Rosters pages for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.7
 * @author Jeremy Lee
 */

/**
 * Helper: Get placeholder filter for WHERE clause
 */
function intersoccer_roster_placeholder_where() {
    global $wpdb;
    static $filter = null;
    
    if ($filter === null) {
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        $columns = $wpdb->get_col("DESCRIBE $rosters_table", 0);
        $has_column = in_array('is_placeholder', $columns);
        $filter = $has_column ? " AND (is_placeholder = 0 OR is_placeholder IS NULL)" : "";
    }
    
    return $filter;
}

/**
 * Helper: Get closed roster filter for WHERE clause
 * @param string|bool $status 'closed' to show only closed, 'all' to show all, false/default to exclude closed
 * @return string SQL WHERE clause fragment
 */
function intersoccer_roster_closed_where($status = false) {
    global $wpdb;
    static $has_column = null;
    
    if ($has_column === null) {
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        try {
            $columns = $wpdb->get_col("DESCRIBE $rosters_table", 0);
            $has_column = is_array($columns) && in_array('event_completed', $columns);
        } catch (Exception $e) {
            // If DESCRIBE fails, assume column doesn't exist
            error_log('InterSoccer: Error checking for event_completed column: ' . $e->getMessage());
            $has_column = false;
        }
    }
    
    if (!$has_column) {
        return "";
    }
    
    // If showing all or closed only, don't filter by closed status
    if ($status === 'all' || $status === 'closed') {
        // For 'closed', we'll filter in the query itself
        return "";
    }
    
    // Otherwise, exclude closed rosters by default
    return " AND (event_completed = 0 OR event_completed IS NULL)";
}

/**
 * Add improved inline CSS for better layout
 */
add_action('admin_head', function () {
    echo '<style>
        /* Conditional formatting for participant counts */
        .count-number.count-critical {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            animation: pulse-red 2s infinite;
        }

        .count-number.count-low {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .count-number.count-good {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .count-number.count-optimal {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        }

        @keyframes pulse-red {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        /* Add tooltip for critical counts */
        .count-critical::after {
            content: "Event may be canceled";
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .count-critical:hover::after {
            opacity: 1;
        }

        .player-count {
            position: relative;
        }
        .intersoccer-rosters-page {
            all: initial;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.5;
            color: #111827;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            margin: 0 -20px 0 -22px;  /* Push outside admin area like roster-details */
            position: relative;
            box-sizing: border-box;
        }
        
        .intersoccer-rosters-page * {
            box-sizing: border-box;
        }
        
        /* Hide WordPress admin notices within our custom pages */
        .intersoccer-rosters-page .notice,
        .intersoccer-rosters-page .updated,
        .intersoccer-rosters-page .error,
        .intersoccer-rosters-page .update-nag {
            display: none !important;
        }
        
        /* Ensure our content container has proper spacing */
        .intersoccer-rosters-page .wrap {
            margin: 0;
            padding: 32px;
            max-width: none;
        }
        
        .roster-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #0073aa;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .roster-header h1 {
            margin: 0;
            color:rgb(0, 0, 0);
            font-size: 28px;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        /* Improved filter styling */
        .roster-filters {
            background: #f8f9fa;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            border: 1px solid #e1e4e8;
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 180px;
            flex: 1;
            max-width: 250px;
        }
        
        .filter-group label {
            font-weight: 600;
            margin-bottom: 6px;
            color: #2c3338;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            background: white;
            font-size: 14px;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        
        .filter-group select:focus {
            border-color: #0073aa;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 115, 170, 0.1);
        }
        
        .filter-group select:hover {
            border-color: #0073aa;
        }
        
        /* Sports roster layout improvements */
        .sports-rosters {
            margin-top: 30px;
        }
        
        .roster-week, .roster-season {
            margin-bottom: 35px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid #e1e4e8;
        }
        
        .week-header, .season-header {
            background: linear-gradient(135deg, #0073aa 0%, #005a87 100%);
            color: white;
            padding: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .week-title, .season-title {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
        }

        .season-title {
            color: #ffffff;
        }
        
        .week-date {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 300;
            margin-left: 10px;
        }
        
        .week-stats, .season-stats {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            background: rgba(255,255,255,0.15);
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
        }
        
        /* Grid layouts */
        .camps-grid, .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            padding: 25px;
            background: #fafbfc;
        }
        
        .camp-card, .course-card {
            background: white;
            border: 1px solid #e1e4e8;
            border-radius: 10px;
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .camp-card:hover, .course-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-color: #0073aa;
        }
        
        .camp-header, .course-header {
            padding: 18px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e1e4e8;
        }
        
        .camp-venue, .course-venue {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: #2c3338;
        }
        
        .camp-type {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #0073aa;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .camp-details, .course-details {
            padding: 20px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .detail-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .detail-label {
            font-weight: 600;
            color: #5f6368;
            font-size: 13px;
        }
        
        .detail-value {
            color: #2c3338;
            font-weight: 500;
            text-align: right;
            max-width: 60%;
            word-wrap: break-word;
        }
        
        .camp-footer, .course-footer {
            padding: 15px 20px;
            background: #f8f9fa;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #e1e4e8;
        }
        
        .player-count {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .count-number {
            background: #0073aa;
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
            min-width: 35px;
            text-align: center;
        }
        
        .count-label {
            color: #5f6368;
            font-size: 13px;
            font-weight: 500;
        }
        
        .button-roster-view {
            background: #0073aa;
            color: white;
            padding: 10px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.2s ease;
            border: none;
        }
        
        .button-roster-view:hover {
            background: #005a87;
            color: white;
            text-decoration: none;
            transform: translateY(-1px);
            margin-right: 8px;
        }
        
        .close-roster-btn, .reopen-roster-btn {
            background: #dc3232;
            color: white;
            padding: 8px 10px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            transition: all 0.2s ease;
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-indent: -9999px;
            position: relative;
        }
        
        .close-roster-btn::before {
            content: "\\00D7";
            position: absolute;
            text-indent: 0;
            font-size: 24px;
            font-weight: bold;
            line-height: 1;
        }
        
        .close-roster-btn:hover {
            background: #a00;
            transform: translateY(-1px);
        }
        
        .reopen-roster-btn {
            background: #46b450;
        }
        
        .reopen-roster-btn::before {
            content: "\\21BB";
            position: absolute;
            text-indent: 0;
            font-size: 18px;
        }
        
        .reopen-roster-btn:hover {
            background: #2e7d32;
            transform: translateY(-1px);
        }
        
        .camp-actions, .course-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Bulk actions */
        .bulk-actions-bar {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            gap: 15px;
            border: 1px solid #ddd;
        }
        
        .bulk-actions-bar.active {
            display: flex;
        }
        
        .bulk-actions-bar .selected-count {
            font-weight: 600;
            color: #0073aa;
        }
        
        .roster-checkbox {
            margin-right: 8px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .camp-card, .course-card {
            position: relative;
        }
        
        .camp-card .roster-checkbox,
        .course-card .roster-checkbox {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }
        
        /* No rosters state */
        .no-rosters {
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: 10px;
            border: 2px dashed #ddd;
            margin: 30px 0;
        }
        
        .no-rosters-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .no-rosters h3 {
            color: #5f6368;
            margin-bottom: 10px;
            font-size: 20px;
        }
        
        .no-rosters p {
            color: #8a8a8a;
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
        }
        
        /* Day groups for courses */
        .day-group {
            margin-bottom: 30px;
        }
        
        .day-group h3 {
            background: #f1f3f4;
            margin: 0;
            padding: 15px 25px;
            font-size: 18px;
            color: #2c3338;
            border-left: 4px solid #0073aa;
        }
        
        /* Export buttons styling */
        .export-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .export-buttons form {
            display: inline-block;
        }
        
        .export-buttons .button-primary {
            background: #0073aa;
            border-color: #0073aa;
            padding: 8px 16px;
            font-weight: 500;
        }
        
        .export-buttons .button-primary:hover {
            background: #005a87;
            border-color: #005a87;
        }
        
        /* Responsive design */
        @media (max-width: 1200px) {
            .camps-grid, .courses-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 15px;
                padding: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .roster-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-form {
                flex-direction: column;
                gap: 15px;
            }
            
            .filter-group {
                max-width: 100%;
                min-width: 100%;
            }
            
            .camps-grid, .courses-grid {
                grid-template-columns: 1fr;
                padding: 15px;
            }
            
            .week-header, .season-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .week-stats, .season-stats {
                gap: 15px;
            }
            
            .export-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .export-buttons form {
                width: 100%;
            }
            
            .export-buttons .button-primary {
                width: 100%;
                text-align: center;
            }
        }
    </style>';
});

/**
 * Inline admin JS for camps/courses roster cards (close, reopen, bulk, season close).
 */
function intersoccer_roster_listing_page_scripts() {
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        function intersoccerSignaturesFromData($el) {
            var raw = $el.attr('data-event-signatures');
            var out = [];
            if (raw) {
                raw.split(',').forEach(function(s) {
                    s = $.trim(s);
                    if (s) { out.push(s); }
                });
            }
            if (!out.length) {
                var one = $el.data('event-signature');
                if (one) { out.push(one); }
            }
            return out;
        }
        // Close roster handler
        $(document).on('click', '.close-roster-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var sigs = intersoccerSignaturesFromData($btn);
            if (!sigs.length) {
                alert('<?php echo esc_js(__('Error: Missing event signature', 'intersoccer-reports-rosters')); ?>');
                return;
            }
            if (!confirm('<?php echo esc_js(__('Are you sure you want to close out this roster? This will mark the event as completed.', 'intersoccer-reports-rosters')); ?>')) {
                return;
            }
            $btn.prop('disabled', true);
            function onCloseSuccess() {
                $btn.removeClass('close-roster-btn').addClass('reopen-roster-btn')
                    .attr('title', '<?php echo esc_js(__('Reopen Roster', 'intersoccer-reports-rosters')); ?>')
                    .prop('disabled', false);
                location.reload();
            }
            if (sigs.length === 1) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_close_out_roster',
                        nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                        event_signature: sigs[0]
                    },
                    success: function(response) {
                        if (response.success) { onCloseSuccess(); }
                        else {
                            alert(response.data.message || '<?php echo esc_js(__('Failed to close roster.', 'intersoccer-reports-rosters')); ?>');
                            $btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                        $btn.prop('disabled', false);
                    }
                });
            } else {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_bulk_close_rosters',
                        nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                        event_signatures: sigs
                    },
                    success: function(response) {
                        if (response.success) { onCloseSuccess(); }
                        else {
                            alert(response.data.message || '<?php echo esc_js(__('Failed to close roster.', 'intersoccer-reports-rosters')); ?>');
                            $btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                        $btn.prop('disabled', false);
                    }
                });
            }
        });
        // Reopen roster handler
        $(document).on('click', '.reopen-roster-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var sigs = intersoccerSignaturesFromData($btn);
            if (!sigs.length) {
                alert('<?php echo esc_js(__('Error: Missing event signature', 'intersoccer-reports-rosters')); ?>');
                return;
            }
            if (!confirm('<?php echo esc_js(__('Are you sure you want to reopen this roster?', 'intersoccer-reports-rosters')); ?>')) {
                return;
            }
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Reopening...', 'intersoccer-reports-rosters')); ?>');
            function onReopenSuccess() {
                $btn.removeClass('reopen-roster-btn').addClass('close-roster-btn')
                    .text('<?php echo esc_js(__('Close Out', 'intersoccer-reports-rosters')); ?>')
                    .prop('disabled', false);
                location.reload();
            }
            if (sigs.length === 1) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_reopen_roster',
                        nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                        event_signature: sigs[0]
                    },
                    success: function(response) {
                        if (response.success) { onReopenSuccess(); }
                        else {
                            alert(response.data.message || '<?php echo esc_js(__('Failed to reopen roster.', 'intersoccer-reports-rosters')); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Reopen', 'intersoccer-reports-rosters')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Reopen', 'intersoccer-reports-rosters')); ?>');
                    }
                });
            } else {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_bulk_reopen_rosters',
                        nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                        event_signatures: sigs
                    },
                    success: function(response) {
                        if (response.success) { onReopenSuccess(); }
                        else {
                            alert(response.data.message || '<?php echo esc_js(__('Failed to reopen roster.', 'intersoccer-reports-rosters')); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Reopen', 'intersoccer-reports-rosters')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Reopen', 'intersoccer-reports-rosters')); ?>');
                    }
                });
            }
        });
        // Bulk actions
        var $bulkBar = $('#bulk-actions-bar');
        var $checkboxes = $('.roster-checkbox');
        var $selectedCount = $('#selected-count');
        var $bulkCloseBtn = $('#bulk-close-btn');
        var $bulkReopenBtn = $('#bulk-reopen-btn');
        var $clearSelectionBtn = $('#clear-selection-btn');
        function updateBulkBar() {
            var selected = $checkboxes.filter(':checked');
            var count = selected.length;
            if (count > 0) {
                $bulkBar.addClass('active');
                $selectedCount.text(count + ' <?php echo esc_js(__('selected', 'intersoccer-reports-rosters')); ?>');
                var hasClosed = selected.filter(function() {
                    return $(this).data('is-closed') == '1';
                }).length > 0;
                var hasOpen = selected.filter(function() {
                    return $(this).data('is-closed') == '0';
                }).length > 0;
                $bulkCloseBtn.prop('disabled', !hasOpen);
                $bulkReopenBtn.prop('disabled', !hasClosed);
            } else {
                $bulkBar.removeClass('active');
            }
        }
        $checkboxes.on('change', updateBulkBar);
        $clearSelectionBtn.on('click', function() {
            $checkboxes.prop('checked', false);
            updateBulkBar();
        });
        $bulkCloseBtn.on('click', function() {
            var selected = $checkboxes.filter(':checked').filter(function() {
                return $(this).data('is-closed') == '0';
            });
            if (selected.length === 0) {
                alert('<?php echo esc_js(__('Please select at least one open roster to close.', 'intersoccer-reports-rosters')); ?>');
                return;
            }
            if (!confirm('<?php echo esc_js(__('Are you sure you want to close', 'intersoccer-reports-rosters')); ?> ' + selected.length + ' <?php echo esc_js(__('roster(s)?', 'intersoccer-reports-rosters')); ?>')) {
                return;
            }
            var eventSignatures = [];
            selected.each(function() {
                intersoccerSignaturesFromData($(this)).forEach(function(s) {
                    if (eventSignatures.indexOf(s) === -1) { eventSignatures.push(s); }
                });
            });
            $bulkCloseBtn.prop('disabled', true).text('<?php echo esc_js(__('Closing...', 'intersoccer-reports-rosters')); ?>');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_bulk_close_rosters',
                    nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                    event_signatures: eventSignatures
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || '<?php echo esc_js(__('Rosters closed successfully.', 'intersoccer-reports-rosters')); ?>');
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Failed to close rosters.', 'intersoccer-reports-rosters')); ?>');
                        $bulkCloseBtn.prop('disabled', false).text('<?php echo esc_js(__('Close Selected Rosters', 'intersoccer-reports-rosters')); ?>');
                    }
                },
                error: function() {
                    alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                    $bulkCloseBtn.prop('disabled', false).text('<?php echo esc_js(__('Close Selected Rosters', 'intersoccer-reports-rosters')); ?>');
                }
            });
        });
        $bulkReopenBtn.on('click', function() {
            var selected = $checkboxes.filter(':checked').filter(function() {
                return $(this).data('is-closed') == '1';
            });
            if (selected.length === 0) {
                alert('<?php echo esc_js(__('Please select at least one closed roster to reopen.', 'intersoccer-reports-rosters')); ?>');
                return;
            }
            if (!confirm('<?php echo esc_js(__('Are you sure you want to reopen', 'intersoccer-reports-rosters')); ?> ' + selected.length + ' <?php echo esc_js(__('roster(s)?', 'intersoccer-reports-rosters')); ?>')) {
                return;
            }
            var eventSignatures = [];
            selected.each(function() {
                intersoccerSignaturesFromData($(this)).forEach(function(s) {
                    if (eventSignatures.indexOf(s) === -1) { eventSignatures.push(s); }
                });
            });
            $bulkReopenBtn.prop('disabled', true).text('<?php echo esc_js(__('Reopening...', 'intersoccer-reports-rosters')); ?>');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_bulk_reopen_rosters',
                    nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                    event_signatures: eventSignatures
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || '<?php echo esc_js(__('Rosters reopened successfully.', 'intersoccer-reports-rosters')); ?>');
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Failed to reopen rosters.', 'intersoccer-reports-rosters')); ?>');
                        $bulkReopenBtn.prop('disabled', false).text('<?php echo esc_js(__('Reopen Selected Rosters', 'intersoccer-reports-rosters')); ?>');
                    }
                },
                error: function() {
                    alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                    $bulkReopenBtn.prop('disabled', false).text('<?php echo esc_js(__('Reopen Selected Rosters', 'intersoccer-reports-rosters')); ?>');
                }
            });
        });
        $(document).on('click', '.close-season-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var season = $btn.data('season');
            var page = $btn.data('page') || 'camps';
            if (!confirm('<?php echo esc_js(__('Are you sure you want to close ALL active rosters in this season? This action cannot be undone.', 'intersoccer-reports-rosters')); ?>')) {
                return;
            }
            $btn.prop('disabled', true).text('<?php echo esc_js(__('Closing...', 'intersoccer-reports-rosters')); ?>');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_close_season_rosters',
                    nonce: '<?php echo wp_create_nonce('intersoccer_reports_rosters_nonce'); ?>',
                    season: season,
                    page: page
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || '<?php echo esc_js(__('All rosters in season closed successfully.', 'intersoccer-reports-rosters')); ?>');
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('Failed to close rosters in season.', 'intersoccer-reports-rosters')); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Close All in Season', 'intersoccer-reports-rosters')); ?>');
                    }
                },
                error: function() {
                    alert('<?php echo esc_js(__('An error occurred. Please try again.', 'intersoccer-reports-rosters')); ?>');
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Close All in Season', 'intersoccer-reports-rosters')); ?>');
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * Whether admin camps/courses roster lists use consolidated (all languages) grouping.
 * Defaults to true; consolidated=0 opts into per-event_signature (WPML split) view.
 *
 * @return bool
 */
function intersoccer_roster_admin_consolidated_view_from_request() {
    if (!isset($_GET['consolidated'])) {
        return true;
    }
    $raw = sanitize_text_field(wp_unslash($_GET['consolidated']));
    if ($raw === '' || $raw === '0' || strtolower($raw) === 'false') {
        return false;
    }
    return true;
}

/**
 * Updated Camps page with improved formatting, no booking_type split, and City display
 */
function intersoccer_render_camps_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    // Check if user is a coach and filter venues accordingly
    $current_user = wp_get_current_user();
    $is_coach = in_array('coach', $current_user->roles);
    $coach_accessible_venues = [];

    if ($is_coach) {
        // Include the coach assignments class
        if (!class_exists('InterSoccer_Admin_Coach_Assignments')) {
            require_once WP_PLUGIN_DIR . '/customer-referral-system/includes/class-admin-coach-assignments.php';
        }
        $coach_accessible_venues = InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues($current_user->ID);
    }

    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $selected_camp_terms = isset($_GET['camp_terms']) ? sanitize_text_field($_GET['camp_terms']) : '';
    $selected_age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $selected_city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $show_closed = ($status_filter === 'closed' || $status_filter === 'all') ? $status_filter : false;
    $consolidated_view = intersoccer_roster_admin_consolidated_view_from_request();

    $service = intersoccer_oop_get_roster_listing_service();
    $result = $service->getCampListings(
        [
            'season' => $selected_season,
            'venue' => $selected_venue,
            'camp_terms' => $selected_camp_terms,
            'age_group' => $selected_age_group,
            'city' => $selected_city,
            'status' => $status_filter,
        ],
        [
            'is_coach' => $is_coach,
            'accessible_venues' => $coach_accessible_venues,
        ],
        false,
        $consolidated_view
    );

    $query_time = $result['query_time'];
    $all_groups = $result['all_groups'];
    $display_groups = $result['display_groups'];
    $grouped = $result['grouped'];
    $all_seasons = $result['filters']['seasons'];
    $all_venues = $result['filters']['venues'];
    $all_camp_terms = $result['filters']['camp_terms'];
    $all_age_groups = $result['filters']['age_groups'];
    $all_cities = $result['filters']['cities'];

    error_log('InterSoccer OOP: Camps aggregation time ' . $query_time . ' seconds');
    error_log('InterSoccer OOP: Camps filtered groups ' . print_r($display_groups, true));

    // Render the page
    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('Camps', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-camps&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    ↻ <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>

        <div class="export-buttons">
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" target="_blank">
                <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                <input type="hidden" name="export_type" value="camps">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                <input type="submit" name="export_camps" class="button button-primary" 
                    value="<?php _e('↓ Export All Camps', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>

        <div class="roster-filters">
            <form method="get" class="filter-form" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="intersoccer-camps">
                <?php
                // Ensure selected values appear in option lists so dropdowns persist after filter (and support combining filters)
                if ($selected_season !== '' && !in_array($selected_season, (array) $all_seasons, true)) {
                    $all_seasons = array_merge([$selected_season], (array) $all_seasons);
                    $all_seasons = array_unique($all_seasons);
                    sort($all_seasons, SORT_NATURAL | SORT_FLAG_CASE);
                }
                if ($selected_venue !== '' && !in_array($selected_venue, (array) $all_venues, true)) {
                    $all_venues = array_merge([$selected_venue], (array) $all_venues);
                    $all_venues = array_unique($all_venues);
                    sort($all_venues, SORT_NATURAL | SORT_FLAG_CASE);
                }
                if ($selected_camp_terms !== '' && !in_array($selected_camp_terms, (array) $all_camp_terms, true)) {
                    $all_camp_terms = array_merge([$selected_camp_terms], (array) $all_camp_terms);
                    $all_camp_terms = array_unique($all_camp_terms);
                    sort($all_camp_terms, SORT_NATURAL | SORT_FLAG_CASE);
                }
                if ($selected_age_group !== '' && !in_array($selected_age_group, (array) $all_age_groups, true)) {
                    $all_age_groups = array_merge([$selected_age_group], (array) $all_age_groups);
                    $all_age_groups = array_unique($all_age_groups);
                    sort($all_age_groups, SORT_NATURAL | SORT_FLAG_CASE);
                }
                if ($selected_city !== '' && !in_array($selected_city, (array) $all_cities, true)) {
                    $all_cities = array_merge([$selected_city], (array) $all_cities);
                    $all_cities = array_unique($all_cities);
                    sort($all_cities, SORT_NATURAL | SORT_FLAG_CASE);
                }
                ?>
                <div class="filter-group">
            <label>Season</label>
            <select name="season" onchange="this.form.submit()">
                <option value="">All Seasons</option>
                <?php foreach ($all_seasons as $season): ?>
                    <option value="<?php echo esc_attr($season); ?>" <?php selected($selected_season, $season); ?>>
                        <?php echo esc_html(intersoccer_normalize_season_for_display($season)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Venue</label>
            <select name="venue" onchange="this.form.submit()">
                <option value="">All Venues</option>
                <?php foreach ($all_venues as $venue): ?>
                    <option value="<?php echo esc_attr($venue); ?>" <?php selected($selected_venue, $venue); ?>>
                        <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($venue, 'pa_intersoccer-venues') : $venue); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Camp Term</label>
            <select name="camp_terms" onchange="this.form.submit()">
                <option value="">All Camp Terms</option>
                <?php foreach ($all_camp_terms as $camp_term): ?>
                    <option value="<?php echo esc_attr($camp_term); ?>" <?php selected($selected_camp_terms, $camp_term); ?>>
                        <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp_term, 'pa_camp-terms') : $camp_term); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Age Group</label>
            <select name="age_group" onchange="this.form.submit()">
                <option value="">All Age Groups</option>
                <?php foreach ($all_age_groups as $age_group): ?>
                    <option value="<?php echo esc_attr($age_group); ?>" <?php selected($selected_age_group, $age_group); ?>>
                        <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($age_group, 'pa_age-group') : $age_group); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>City</label>
            <select name="city" onchange="this.form.submit()">
                <option value="">All Cities</option>
                <?php foreach ($all_cities as $city): ?>
                    <option value="<?php echo esc_attr($city); ?>" <?php selected($selected_city, $city); ?>>
                        <?php echo esc_html($city); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select name="status" onchange="this.form.submit()">
                <option value="">Active Only</option>
                <option value="closed" <?php selected($status_filter, 'closed'); ?>>Closed Only</option>
                <option value="all" <?php selected($status_filter, 'all'); ?>>All</option>
            </select>
        </div>
        <div class="filter-group">
            <label style="display:flex;align-items:center;gap:6px;">
                <input type="hidden" name="consolidated" value="0" />
                <input type="checkbox" name="consolidated" value="1" <?php checked($consolidated_view); ?> onchange="this.form.submit()" />
                <?php _e('Consolidated (all languages)', 'intersoccer-reports-rosters'); ?>
            </label>
        </div>
        <?php if ($selected_season || $selected_venue || $selected_camp_terms || $selected_age_group || $selected_city || $status_filter): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-camps'); ?>" class="button button-secondary">
                        ↻ <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="sports-rosters">
            <?php if (empty($grouped)): ?>
                <div class="no-rosters">
                    <div class="no-rosters-icon">⚽</div>
                    <h3><?php _e('No camps found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('Try adjusting your filters or sync rosters to see available camps.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $season => $camps): ?>
                    <div class="roster-season">
                        <div class="season-header">
                            <h2 class="season-title">
                                <?php echo esc_html(intersoccer_normalize_season_for_display($season)); ?>
                            </h2>
                            <div class="season-stats">
                                <?php 
                                $season_total = array_sum(array_column($camps, 'total_players'));
                                $camp_count = count($camps);
                                $active_count = count(array_filter($camps, function($camp) { return empty($camp['event_completed']); }));
                                ?>
                                <span class="stat-item">
                                    Players: <?php echo $season_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    Camps: <?php echo $camp_count; ?> <?php _e('camps', 'intersoccer-reports-rosters'); ?>
                                </span>
                            </div>
                            <div class="season-actions">
                                <?php if ($active_count > 0): ?>
                                    <button type="button" class="close-season-btn" 
                                            data-season="<?php echo esc_attr($season); ?>"
                                            data-page="camps"
                                            title="<?php esc_attr_e('Close all active rosters in this season', 'intersoccer-reports-rosters'); ?>">
                                        <?php _e('Close All in Season', 'intersoccer-reports-rosters'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="camps-grid">
                            <?php foreach ($camps as $camp): ?>
                                <div class="camp-card">
                                    <?php
                                    $camp_sig_list = !empty($camp['merged_event_signatures'])
                                        ? $camp['merged_event_signatures']
                                        : array_filter([$camp['event_signature'] ?? '']);
                                    $camp_sigs_csv = esc_attr(implode(',', $camp_sig_list));
                                    ?>
                                    <input type="checkbox" class="roster-checkbox" 
                                           data-event-signature="<?php echo esc_attr($camp['event_signature']); ?>"
                                           data-event-signatures="<?php echo $camp_sigs_csv; ?>"
                                           data-is-closed="<?php echo !empty($camp['event_completed']) ? '1' : '0'; ?>">
                                    <div class="camp-header">
                                        <h3 class="camp-venue">
                                            📍 <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['venue'], 'pa_intersoccer-venues') : ($camp['venue'] ?: 'Unknown Venue')); ?>
                                        </h3>
                                    </div>
                                    <div class="camp-details">
                                        <div class="detail-row">
                                            <span class="detail-label">📅 Camp Term</span>
                                            <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['camp_terms'], 'pa_camp-terms') : ($camp['camp_terms'] ?: 'TBD')); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">⏰ Times</span>
                                            <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['times'], 'pa_camp-times') : ($camp['times'] ?: 'TBD')); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">👶 Age Group</span>
                                            <span class="detail-value">
                                                <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['age_group'], 'pa_age-group') : $camp['age_group']); ?>
                                            </span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">🌆 City</span>
                                            <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['city'], 'pa_city') : ($camp['city'] ?: 'N/A')); ?></span>
                                        </div>
                                    </div>
                                    <div class="camp-footer">
                                        <div class="player-count">
                                            <span class="count-number <?php echo intersoccer_get_count_class($camp['total_players']); ?>"><?php echo esc_html($camp['total_players']); ?></span>
                                            <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                        </div>
                                        <div class="camp-actions">
                                            <?php
                                            if (!empty($camp['consolidated_listing']) && !empty($camp['order_item_ids'])) {
                                                $view_params = ['page' => 'intersoccer-roster-details', 'from' => 'camps', 'order_item_ids' => implode(',', $camp['order_item_ids'])];
                                            } else {
                                                $view_params = ['page' => 'intersoccer-roster-details', 'from' => 'camps', 'event_signature' => $camp['event_signature']];
                                            }
                                            $view_url = add_query_arg($view_params, admin_url('admin.php'));
                                            $is_closed = !empty($camp['event_completed']);
                                            ?>
                                            <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
                                            </a>
                                            <?php if ($is_closed): ?>
                                                <button type="button" class="reopen-roster-btn" 
                                                        data-event-signature="<?php echo esc_attr($camp['event_signature']); ?>"
                                                        data-event-signatures="<?php echo $camp_sigs_csv; ?>"
                                                        title="<?php esc_attr_e('Reopen Roster', 'intersoccer-reports-rosters'); ?>">
                                                    <?php _e('Reopen', 'intersoccer-reports-rosters'); ?>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="close-roster-btn" 
                                                        data-event-signature="<?php echo esc_attr($camp['event_signature']); ?>"
                                                        data-event-signatures="<?php echo $camp_sigs_csv; ?>"
                                                        title="<?php esc_attr_e('Close Out Roster', 'intersoccer-reports-rosters'); ?>">
                                                    <?php _e('Close Out', 'intersoccer-reports-rosters'); ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php intersoccer_roster_listing_page_scripts(); ?>
<?php
}

/**
 * Updated Courses page with improved formatting
 */
function intersoccer_render_courses_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    // Check if user is a coach and filter venues accordingly
    $current_user = wp_get_current_user();
    $is_coach = in_array('coach', $current_user->roles);
    $coach_accessible_venues = [];

    if ($is_coach) {
        // Include the coach assignments class
        if (!class_exists('InterSoccer_Admin_Coach_Assignments')) {
            require_once WP_PLUGIN_DIR . '/customer-referral-system/includes/class-admin-coach-assignments.php';
        }
        $coach_accessible_venues = InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues($current_user->ID);
    }

    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $selected_course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';
    $selected_age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $selected_city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
    $consolidated_view = intersoccer_roster_admin_consolidated_view_from_request();

    $service = intersoccer_oop_get_roster_listing_service();
    $result = $service->getCourseListings(
        [
            'season' => $selected_season,
            'venue' => $selected_venue,
            'course_day' => $selected_course_day,
            'age_group' => $selected_age_group,
            'city' => $selected_city,
            'status' => $status_filter,
        ],
        [
            'is_coach' => $is_coach,
            'accessible_venues' => $coach_accessible_venues,
        ],
        false,
        $consolidated_view
    );

    $query_time = $result['query_time'];
    $all_groups = $result['all_groups'];
    $display_groups = $result['display_groups'];
    $grouped = $result['grouped'];
    $all_seasons = $result['filters']['seasons'];
    $all_venues = $result['filters']['venues'];
    $all_course_days = $result['filters']['course_days'];
    $all_age_groups = $result['filters']['age_groups'];
    $all_cities = $result['filters']['cities'];

    error_log('InterSoccer OOP: Courses aggregation time ' . $query_time . ' seconds');
    error_log('InterSoccer OOP: Courses filtered groups ' . print_r($display_groups, true));

    // Render the page
    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('Courses', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-courses&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    ↻ <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>

        <div class="export-buttons">
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" target="_blank">
                <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                <input type="hidden" name="export_type" value="courses">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                <input type="submit" name="export_courses" class="button button-primary" 
                    value="<?php _e('↓ Export All Courses', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>

        <div class="roster-filters">
            <form method="get" class="filter-form" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="intersoccer-courses">
                <?php
                // Ensure selected values appear in option lists so dropdowns persist (and support combining filters)
                if ($selected_season !== '' && !in_array($selected_season, (array) $all_seasons, true)) {
                    $all_seasons = array_merge([$selected_season], (array) $all_seasons);
                    $all_seasons = array_unique($all_seasons);
                    sort($all_seasons, SORT_NATURAL | SORT_FLAG_CASE);
                }
                if ($selected_venue !== '' && !in_array($selected_venue, (array) $all_venues, true)) {
                    $all_venues = array_merge([$selected_venue], (array) $all_venues);
                    $all_venues = array_unique($all_venues);
                    sort($all_venues, SORT_NATURAL | SORT_FLAG_CASE);
                }
                if ($selected_course_day !== '' && !in_array($selected_course_day, (array) $all_course_days, true)) {
                    $all_course_days = array_merge([$selected_course_day], (array) $all_course_days);
                    $all_course_days = array_unique($all_course_days);
                    sort($all_course_days, SORT_NATURAL | SORT_FLAG_CASE);
                }
                if ($selected_age_group !== '' && !in_array($selected_age_group, (array) $all_age_groups, true)) {
                    $all_age_groups = array_merge([$selected_age_group], (array) $all_age_groups);
                    $all_age_groups = array_unique($all_age_groups);
                    sort($all_age_groups, SORT_NATURAL | SORT_FLAG_CASE);
                }
                if ($selected_city !== '' && !in_array($selected_city, (array) $all_cities, true)) {
                    $all_cities = array_merge([$selected_city], (array) $all_cities);
                    $all_cities = array_unique($all_cities);
                    sort($all_cities, SORT_NATURAL | SORT_FLAG_CASE);
                }
                ?>
                <div class="filter-group">
                    <label>Season</label>
                    <select name="season" onchange="this.form.submit()">
                        <option value="">All Seasons</option>
                        <?php foreach ($all_seasons as $season): ?>
                            <option value="<?php echo esc_attr($season); ?>" <?php selected($selected_season, $season); ?>>
                                <?php echo esc_html(intersoccer_normalize_season_for_display($season)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Venue</label>
                    <select name="venue" onchange="this.form.submit()">
                        <option value="">All Venues</option>
                        <?php foreach ($all_venues as $venue): ?>
                            <option value="<?php echo esc_attr($venue); ?>" <?php selected($selected_venue, $venue); ?>>
                                <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($venue, 'pa_intersoccer-venues') : $venue); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Course Day</label>
                    <select name="course_day" onchange="this.form.submit()">
                        <option value="">All Days</option>
                        <?php foreach ($all_course_days as $course_day): ?>
                            <option value="<?php echo esc_attr($course_day); ?>" <?php selected($selected_course_day, $course_day); ?>>
                                <?php echo esc_html(ucfirst($course_day)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Age Group</label>
                    <select name="age_group" onchange="this.form.submit()">
                        <option value="">All Age Groups</option>
                        <?php foreach ($all_age_groups as $age_group): ?>
                            <option value="<?php echo esc_attr($age_group); ?>" <?php selected($selected_age_group, $age_group); ?>>
                                <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($age_group, 'pa_age-group') : $age_group); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>City</label>
                    <select name="city" onchange="this.form.submit()">
                        <option value="">All Cities</option>
                        <?php foreach ($all_cities as $city): ?>
                            <option value="<?php echo esc_attr($city); ?>" <?php selected($selected_city, $city); ?>>
                                <?php echo esc_html($city); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">Active Only</option>
                        <option value="closed" <?php selected($status_filter, 'closed'); ?>>Closed Only</option>
                        <option value="all" <?php selected($status_filter, 'all'); ?>>All</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label style="display:flex;align-items:center;gap:6px;">
                        <input type="hidden" name="consolidated" value="0" />
                        <input type="checkbox" name="consolidated" value="1" <?php checked($consolidated_view); ?> onchange="this.form.submit()" />
                        <?php _e('Consolidated (all languages)', 'intersoccer-reports-rosters'); ?>
                    </label>
                </div>
                <?php if ($selected_season || $selected_venue || $selected_course_day || $selected_age_group || $selected_city || $status_filter): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-courses'); ?>" class="button button-secondary">
                        ↻ <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Bulk Actions Bar -->
        <div class="bulk-actions-bar" id="bulk-actions-bar">
            <span class="selected-count" id="selected-count">0 selected</span>
            <button type="button" class="button button-primary" id="bulk-close-btn">
                <?php _e('Close Selected Rosters', 'intersoccer-reports-rosters'); ?>
            </button>
            <button type="button" class="button button-secondary" id="bulk-reopen-btn">
                <?php _e('Reopen Selected Rosters', 'intersoccer-reports-rosters'); ?>
            </button>
            <button type="button" class="button button-secondary" id="clear-selection-btn">
                <?php _e('Clear Selection', 'intersoccer-reports-rosters'); ?>
            </button>
        </div>

        <div class="sports-rosters">
            <?php if (empty($grouped)): ?>
                <div class="no-rosters">
                    <div class="no-rosters-icon">⚽</div>
                    <h3><?php _e('No courses found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('Try adjusting your filters or sync rosters to see available courses.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $season => $day_groups): ?>
                    <div class="roster-season">
                        <div class="season-header">
                            <h2 class="season-title">
                                <?php echo esc_html(intersoccer_normalize_season_for_display($season)); ?>
                            </h2>
                            <div class="season-stats">
                                <?php 
                                $season_total = 0;
                                $event_count = 0;
                                $active_count = 0;
                                foreach ($day_groups as $day => $courses) {
                                    $season_total += array_sum(array_column($courses, 'total_players'));
                                    $event_count += count($courses);
                                    $active_count += count(array_filter($courses, function($course) { return empty($course['event_completed']); }));
                                }
                                ?>
                                <span class="stat-item">
                                    Players: <?php echo $season_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    <?php echo esc_html(sprintf(__('Courses: %d', 'intersoccer-reports-rosters'), (int) $event_count)); ?>
                                </span>
                            </div>
                            <div class="season-actions">
                                <?php if ($active_count > 0): ?>
                                    <button type="button" class="close-season-btn" 
                                            data-season="<?php echo esc_attr($season); ?>"
                                            data-page="courses"
                                            title="<?php esc_attr_e('Close all active rosters in this season', 'intersoccer-reports-rosters'); ?>">
                                        <?php _e('Close All in Season', 'intersoccer-reports-rosters'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php foreach ($day_groups as $day => $courses): ?>
                            <?php if (!empty($courses)): ?>
                                <div class="day-group">
                                    <h3><?php echo esc_html(ucfirst($day)); ?></h3>
                                </div>
                                <div class="courses-grid">
                                    <?php foreach ($courses as $course): ?>
                                        <div class="course-card">
                                            <?php
                                            $course_sig_list = !empty($course['merged_event_signatures'])
                                                ? $course['merged_event_signatures']
                                                : array_filter([$course['event_signature'] ?? '']);
                                            $course_sigs_csv = esc_attr(implode(',', $course_sig_list));
                                            ?>
                                            <input type="checkbox" class="roster-checkbox" 
                                                   data-event-signature="<?php echo esc_attr($course['event_signature']); ?>"
                                                   data-event-signatures="<?php echo $course_sigs_csv; ?>"
                                                   data-is-closed="<?php echo !empty($course['event_completed']) ? '1' : '0'; ?>">
                                            <div class="course-header">
                                                <h3 class="course-venue">
                                                    📍 <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($course['venue'], 'pa_intersoccer-venues') : ($course['venue'] ?: 'Unknown Venue')); ?>
                                                </h3>
                                            </div>
                                            <div class="course-details">
                                                <div class="detail-row">
                                                    <span class="detail-label">👶 Age Group</span>
                                                    <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($course['age_group'], 'pa_age-group') : $course['age_group']); ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">⏰ Times</span>
                                                    <span class="detail-value"><?php echo esc_html($course['times'] ?: 'TBD'); ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">📅 Duration</span>
                                                    <span class="detail-value">
                                                        <?php 
                                                        if ($course['corrected_start_date'] !== '1970-01-01' && $course['corrected_end_date'] !== '1970-01-01') {
                                                            echo esc_html(date_i18n('M j', strtotime($course['corrected_start_date'])) . ' - ' . date_i18n('M j, Y', strtotime($course['corrected_end_date'])));
                                                        } else {
                                                            echo 'TBD';
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">🌆 City</span>
                                                    <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($course['city'], 'pa_city') : ($course['city'] ?: 'N/A')); ?></span>
                                                </div>
                                            </div>
                                            <div class="course-footer">
                                                <div class="player-count">
                                                   <span class="count-number <?php echo intersoccer_get_count_class($course['total_players']); ?>"><?php echo esc_html($course['total_players']); ?></span>
                                                    <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                                </div>
                                                <div class="course-actions">
                                                    <?php
                                                    if (!empty($course['consolidated_listing']) && !empty($course['order_item_ids'])) {
                                                        $view_params = ['page' => 'intersoccer-roster-details', 'from' => 'courses', 'order_item_ids' => implode(',', $course['order_item_ids'])];
                                                    } else {
                                                        $view_params = ['page' => 'intersoccer-roster-details', 'from' => 'courses', 'event_signature' => $course['event_signature']];
                                                    }
                                                    $view_url = add_query_arg($view_params, admin_url('admin.php'));
                                                    $is_closed = !empty($course['event_completed']);
                                                    ?>
                                                    <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                        <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
                                                    </a>
                                                    <?php if ($is_closed): ?>
                                                        <button type="button" class="reopen-roster-btn" 
                                                                data-event-signature="<?php echo esc_attr($course['event_signature']); ?>"
                                                                data-event-signatures="<?php echo $course_sigs_csv; ?>"
                                                                title="<?php esc_attr_e('Reopen Roster', 'intersoccer-reports-rosters'); ?>">
                                                            <?php _e('Reopen', 'intersoccer-reports-rosters'); ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" class="close-roster-btn" 
                                                                data-event-signature="<?php echo esc_attr($course['event_signature']); ?>"
                                                                data-event-signatures="<?php echo $course_sigs_csv; ?>"
                                                                title="<?php esc_attr_e('Close Out Roster', 'intersoccer-reports-rosters'); ?>">
                                                            <?php _e('Close Out', 'intersoccer-reports-rosters'); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php intersoccer_roster_listing_page_scripts(); ?>
<?php
}

/**
 * Render Girls Only page with improved formatting
 */
function intersoccer_render_girls_only_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    // Check if user is a coach and filter venues accordingly
    $current_user = wp_get_current_user();
    $is_coach = in_array('coach', $current_user->roles);
    $coach_accessible_venues = [];

    if ($is_coach) {
        // Include the coach assignments class
        if (!class_exists('InterSoccer_Admin_Coach_Assignments')) {
            require_once WP_PLUGIN_DIR . '/customer-referral-system/includes/class-admin-coach-assignments.php';
        }
        $coach_accessible_venues = InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues($current_user->ID);
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

    // Simplified query using the new girls_only boolean column
    $base_query = "SELECT COALESCE(r.season, 'N/A') as season,
                          COALESCE(r.venue, 'N/A') as venue,
                          COALESCE(r.city, oim.meta_value, 'N/A') as city,
                          r.age_group,
                          r.times,
                          r.camp_terms,
                          r.course_day,
                          r.activity_type,
                          MIN(r.start_date) as start_date,
                          MAX(r.end_date) as end_date,
                          COUNT(DISTINCT r.order_item_id) as total_players,
                          GROUP_CONCAT(DISTINCT r.order_item_id) as order_item_ids,
                          r.event_signature,
                          GROUP_CONCAT(DISTINCT r.player_name) as player_names,
                          GROUP_CONCAT(DISTINCT r.product_name) as product_names,
                          MAX(r.event_completed) as event_completed
                   FROM $rosters_table r
                   LEFT JOIN $order_itemmeta_table oim ON r.order_item_id = oim.order_item_id AND oim.meta_key = 'City'
                   WHERE r.girls_only = 1" . intersoccer_roster_placeholder_where();
    
    // Add closed filter based on status
    if ($status_filter === 'closed') {
        $base_query .= " AND (r.event_completed = 1)";
    } elseif ($status_filter !== 'all') {
        $base_query .= intersoccer_roster_closed_where($status_filter);
    }

    // Add coach venue filtering if user is a coach
    if ($is_coach && !empty($coach_accessible_venues)) {
        $placeholders = implode(',', array_fill(0, count($coach_accessible_venues), '%s'));
        $base_query .= $wpdb->prepare(" AND r.venue IN ($placeholders)", $coach_accessible_venues);
    } elseif ($is_coach && empty($coach_accessible_venues)) {
        // Coach has no venue assignments, show no results
        $base_query .= " AND 1=0";
    }

    $base_query .= " GROUP BY r.event_signature
                     ORDER BY r.season DESC, r.activity_type, r.venue, r.age_group";

    $start_time = microtime(true);
    $groups = $wpdb->get_results($base_query, ARRAY_A);
    $query_time = microtime(true) - $start_time;
    $filter = intersoccer_roster_placeholder_where() . intersoccer_roster_closed_where($status_filter);
    $all_venues = $wpdb->get_col("SELECT DISTINCT venue FROM $rosters_table WHERE girls_only = 1{$filter} AND venue IS NOT NULL ORDER BY venue");
    $all_camp_terms = $wpdb->get_col("SELECT DISTINCT camp_terms FROM $rosters_table WHERE girls_only = 1{$filter} AND camp_terms IS NOT NULL ORDER BY camp_terms");
    $all_course_days = $wpdb->get_col("SELECT DISTINCT course_day FROM $rosters_table WHERE girls_only = 1{$filter} AND course_day IS NOT NULL ORDER BY course_day");
    $all_age_groups = $wpdb->get_col("SELECT DISTINCT age_group FROM $rosters_table WHERE girls_only = 1{$filter} AND age_group IS NOT NULL ORDER BY age_group");
    $all_cities = $wpdb->get_col("SELECT DISTINCT COALESCE(r.city, oim.meta_value) FROM $rosters_table r LEFT JOIN $order_itemmeta_table oim ON r.order_item_id = oim.order_item_id AND oim.meta_key = 'City' WHERE r.girls_only = 1" . str_replace('is_placeholder', 'r.is_placeholder', $filter) . " AND (r.city IS NOT NULL AND r.city != '' OR (oim.meta_key = 'City' AND oim.meta_value IS NOT NULL AND oim.meta_value != '')) ORDER BY COALESCE(r.city, oim.meta_value)");
    error_log("InterSoccer: Girls Only query results: " . print_r($groups, true));
    error_log("InterSoccer: Girls Only query execution time: " . $query_time . " seconds");

    // Process groups and separate camps from courses
    $all_camps = [];
    $all_courses = [];
    $all_seasons = [];

    foreach ($groups as $group) {
        $variation_ids = isset($group['variation_ids']) && !empty($group['variation_ids']) && is_string($group['variation_ids']) ? array_filter(explode(',', $group['variation_ids'])) : [];
        if (empty($variation_ids)) {
            error_log("InterSoccer: No valid variation_ids for girls-only group - Raw: " . print_r(isset($group['variation_ids']) ? $group['variation_ids'] : 'KEY_NOT_SET', true));
            $variation_ids = [0];
        }
        $group['variation_ids'] = $variation_ids;
        
        // Determine if this is a camp or course based on activity_type
        $is_camp = (strtolower($group['activity_type']) === 'camp' || !empty($group['camp_terms']));
        
        // Normalize season for display
        $group['season'] = intersoccer_normalize_season_for_display($group['season']);
        
        // Validate and normalize dates (MIN/MAX already calculated in SQL)
        $start = !empty($group['start_date']) ? trim($group['start_date']) : '1970-01-01';
        $end = !empty($group['end_date']) ? trim($group['end_date']) : '1970-01-01';
        
        // Validate dates and filter out invalid ones
        $start_timestamp = strtotime($start);
        $end_timestamp = strtotime($end);
        
        if ($start_timestamp === false || $start === '1970-01-01' || $start === '0000-00-00') {
            $start = '1970-01-01';
        }
        if ($end_timestamp === false || $end === '1970-01-01' || $end === '0000-00-00') {
            $end = '1970-01-01';
        }
        
        $group['start_date'] = ($start !== '1970-01-01' && $start !== '0000-00-00') ? date('Y-m-d', strtotime($start)) : '1970-01-01';
        $group['end_date'] = ($end !== '1970-01-01' && $end !== '0000-00-00') ? date('Y-m-d', strtotime($end)) : '1970-01-01';

        // Collect unique seasons
        if ($group['season'] && $group['season'] !== 'N/A') {
            $all_seasons[$group['season']] = $group['season'];
        }

        // Add to appropriate array
        if ($is_camp) {
            $all_camps[] = $group;
        } else {
            $all_courses[] = $group;
        }
    }

    error_log("InterSoccer: Separated - Camps: " . count($all_camps) . ", Courses: " . count($all_courses));

    // Get filter options
    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
    $selected_venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $selected_camp_terms = isset($_GET['camp_terms']) ? sanitize_text_field($_GET['camp_terms']) : '';
    $selected_course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';
    $selected_age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $selected_city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';

    // Apply filters
    $display_camps = $all_camps;
    $display_courses = $all_courses;

    if ($selected_season) {
        $display_camps = array_filter($display_camps, function($group) use ($selected_season) {
            return $group['season'] === $selected_season;
        });
        $display_courses = array_filter($display_courses, function($group) use ($selected_season) {
            return $group['season'] === $selected_season;
        });
    }

    // Filter by type if specified
    if ($selected_type === 'camps') {
        $display_courses = [];
    } elseif ($selected_type === 'courses') {
        $display_camps = [];
    }
    if ($selected_venue) {
        $display_camps = array_filter($display_camps, function($group) use ($selected_venue) {
            return $group['venue'] === $selected_venue;
        });
        $display_courses = array_filter($display_courses, function($group) use ($selected_venue) {
            return $group['venue'] === $selected_venue;
        });
    }
    if ($selected_camp_terms) {
        $display_camps = array_filter($display_camps, function($group) use ($selected_camp_terms) {
            return $group['camp_terms'] === $selected_camp_terms;
        });
    }
    if ($selected_course_day) {
        $display_courses = array_filter($display_courses, function($group) use ($selected_course_day) {
            return $group['course_day'] === $selected_course_day;
        });
    }
    if ($selected_age_group) {
        $display_camps = array_filter($display_camps, function($group) use ($selected_age_group) {
            return $group['age_group'] === $selected_age_group;
        });
        $display_courses = array_filter($display_courses, function($group) use ($selected_age_group) {
            return $group['age_group'] === $selected_age_group;
        });
    }
    if ($selected_city) {
        $display_camps = array_filter($display_camps, function($group) use ($selected_city) {
            return $group['city'] === $selected_city;
        });
        $display_courses = array_filter($display_courses, function($group) use ($selected_city) {
            return $group['city'] === $selected_city;
        });
    }

    error_log("InterSoccer: After filters - Camps: " . count($display_camps) . ", Courses: " . count($display_courses));

    // Group camps by season
    $grouped_camps = [];
    foreach ($display_camps as $camp) {
        $season = $camp['season'] ?: 'N/A';
        $grouped_camps[$season][] = $camp;
    }
    krsort($grouped_camps);

    // Group courses by season then day
    $grouped_courses = [];
    foreach ($display_courses as $course) {
        $season = $course['season'] ?: 'N/A';
        $course_day = $course['course_day'] ?: 'N/A';
        $grouped_courses[$season][$course_day][] = $course;
    }
    krsort($grouped_courses);

    // Render the page
    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>Girls Only Events</h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-girls-only&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    Reconcile Rosters
                </a>
            </div>
        </div>

        <div class="export-buttons">
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" target="_blank">
                <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                <input type="hidden" name="export_type" value="girls_only">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                <input type="submit" name="export_girls_only" class="button button-primary" 
                    value="<?php _e('Export Girls Only', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>

        <div class="roster-filters">
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-girls-only">
                <div class="filter-group">
            <label>Season</label>
            <select name="season" onchange="this.form.submit()">
                <option value="">All Seasons</option>
                <?php foreach ($all_seasons as $season): ?>
                    <option value="<?php echo esc_attr($season); ?>" <?php selected($selected_season, $season); ?>>
                        <?php echo esc_html(intersoccer_normalize_season_for_display($season)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Type</label>
            <select name="type" onchange="this.form.submit()">
                <option value="">All Types</option>
                <option value="camps" <?php selected($selected_type, 'camps'); ?>>Camps Only</option>
                <option value="courses" <?php selected($selected_type, 'courses'); ?>>Courses Only</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Venue</label>
            <select name="venue" onchange="this.form.submit()">
                <option value="">All Venues</option>
                <?php foreach ($all_venues as $venue): ?>
                    <option value="<?php echo esc_attr($venue); ?>" <?php selected($selected_venue, $venue); ?>>
                        <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($venue, 'pa_intersoccer-venues') : $venue); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!empty($all_camp_terms)): ?>
        <div class="filter-group">
            <label>Camp Term</label>
            <select name="camp_terms" onchange="this.form.submit()">
                <option value="">All Camp Terms</option>
                <?php foreach ($all_camp_terms as $camp_term): ?>
                    <option value="<?php echo esc_attr($camp_term); ?>" <?php selected($selected_camp_terms, $camp_term); ?>>
                        <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp_term, 'pa_camp-terms') : $camp_term); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if (!empty($all_course_days)): ?>
        <div class="filter-group">
            <label>Course Day</label>
            <select name="course_day" onchange="this.form.submit()">
                <option value="">All Days</option>
                <?php foreach ($all_course_days as $course_day): ?>
                    <option value="<?php echo esc_attr($course_day); ?>" <?php selected($selected_course_day, $course_day); ?>>
                        <?php echo esc_html(ucfirst($course_day)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="filter-group">
            <label>Age Group</label>
            <select name="age_group" onchange="this.form.submit()">
                <option value="">All Age Groups</option>
                <?php foreach ($all_age_groups as $age_group): ?>
                    <option value="<?php echo esc_attr($age_group); ?>" <?php selected($selected_age_group, $age_group); ?>>
                        <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($age_group, 'pa_age-group') : $age_group); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>City</label>
            <select name="city" onchange="this.form.submit()">
                <option value="">All Cities</option>
                <?php foreach ($all_cities as $city): ?>
                    <option value="<?php echo esc_attr($city); ?>" <?php selected($selected_city, $city); ?>>
                        <?php echo esc_html($city); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select name="status" onchange="this.form.submit()">
                <option value="">Active Only</option>
                <option value="closed" <?php selected($status_filter, 'closed'); ?>>Closed Only</option>
                <option value="all" <?php selected($status_filter, 'all'); ?>>All</option>
            </select>
        </div>
        <?php if ($selected_season || $selected_type || $selected_venue || $selected_camp_terms || $selected_course_day || $selected_age_group || $selected_city || $status_filter): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-girls-only'); ?>" class="button button-secondary">
                        Clear Filters
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Bulk Actions Bar -->
        <div class="bulk-actions-bar" id="bulk-actions-bar">
            <span class="selected-count" id="selected-count">0 selected</span>
            <button type="button" class="button button-primary" id="bulk-close-btn">
                <?php _e('Close Selected Rosters', 'intersoccer-reports-rosters'); ?>
            </button>
            <button type="button" class="button button-secondary" id="bulk-reopen-btn">
                <?php _e('Reopen Selected Rosters', 'intersoccer-reports-rosters'); ?>
            </button>
            <button type="button" class="button button-secondary" id="clear-selection-btn">
                <?php _e('Clear Selection', 'intersoccer-reports-rosters'); ?>
            </button>
        </div>

        <div class="sports-rosters">
            <?php if (empty($grouped_camps) && empty($grouped_courses)): ?>
                <div class="no-rosters">
                    <div class="no-rosters-icon">⚽</div>
                    <h3><?php _e('No girls only events found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('Try adjusting your filters or sync rosters to see available events.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                
                <!-- Camps Section -->
                <?php if (!empty($grouped_camps)): ?>
                    <?php foreach ($grouped_camps as $season => $camps): ?>
                        <div class="roster-season">
                            <div class="season-header">
                                <h2 class="season-title">
                                    <?php echo esc_html(intersoccer_normalize_season_for_display($season) . ' - Girls Only Camps'); ?>
                                </h2>
                                <div class="season-stats">
                                    <?php 
                                    $camp_total = array_sum(array_column($camps, 'total_players'));
                                    $camp_count = count($camps);
                                    ?>
                                    <span class="stat-item">
                                        <?php echo $camp_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                    </span>
                                    <span class="stat-item">
                                        <?php echo $camp_count; ?> <?php _e('camps', 'intersoccer-reports-rosters'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="camps-grid">
                                <?php foreach ($camps as $camp): ?>
                                    <div class="camp-card">
                                        <div class="camp-header">
                                            <h3 class="camp-venue">
                                                📍 <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['venue'], 'pa_intersoccer-venues') : ($camp['venue'] ?: 'Unknown Venue')); ?>
                                            </h3>
                                        </div>
                                        <div class="camp-details">
                                            <div class="detail-row">
                                            <span class="detail-label">📅 Camp Term</span>
                                            <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['camp_terms'], 'pa_camp-terms') : ($camp['camp_terms'] ?: 'TBD')); ?></span>
                                        </div>
                                            <div class="detail-row">
                                                <span class="detail-label">👶 Age Group</span>
                                                <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['age_group'], 'pa_age-group') : $camp['age_group']); ?></span>
                                            </div>
                                             <div class="detail-row">
                                                <span class="detail-label">🌆 City</span>
                                                <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['city'], 'pa_city') : ($camp['city'] ?: 'N/A')); ?></span>
                                            </div>
                                            <?php if ($camp['times']): ?>
                                            <div class="detail-row">
                                                <span class="detail-label">⏰ Times</span>
                                                <span class="detail-value"><?php echo esc_html($camp['times']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="camp-footer">
                                            <div class="player-count">
                                                <span class="count-number <?php echo intersoccer_get_count_class($camp['total_players']); ?>"><?php echo esc_html($camp['total_players']); ?></span>
                                                <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                            </div>
                                            <div class="camp-actions">
                                                <?php 
                                                $view_params = ['page' => 'intersoccer-roster-details', 'from' => 'girls-only', 'event_signature' => $camp['event_signature'], 'girls_only' => '1'];
                                                $view_url = add_query_arg($view_params, admin_url('admin.php'));
                                                ?>
                                                <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                    View Roster
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Courses Section -->
                <?php if (!empty($grouped_courses)): ?>
                    <?php foreach ($grouped_courses as $season => $day_groups): ?>
                        <div class="roster-season">
                            <div class="season-header">
                                <h2 class="season-title">
                                    <?php echo esc_html(intersoccer_normalize_season_for_display($season) . ' - Girls Only Courses'); ?>
                                </h2>
                                <div class="season-stats">
                                    <?php 
                                    $course_total = 0;
                                    $course_count = 0;
                                    foreach ($day_groups as $day => $courses) {
                                        $course_total += array_sum(array_column($courses, 'total_players'));
                                        $course_count += count($courses);
                                    }
                                    ?>
                                    <span class="stat-item">
                                        <?php echo $course_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                    </span>
                                    <span class="stat-item">
                                        <?php echo $course_count; ?> <?php _e('courses', 'intersoccer-reports-rosters'); ?>
                                    </span>
                                </div>
                            </div>
                            <?php foreach ($day_groups as $day => $courses): ?>
                                <?php if (!empty($courses)): ?>
                                    <div class="day-group">
                                        <h3><?php echo esc_html(ucfirst($day)); ?></h3>
                                    </div>
                                    <div class="courses-grid">
                                        <?php foreach ($courses as $course): ?>
                                            <div class="course-card">
                                                <div class="course-header">
                                                    <h3 class="course-venue">
                                                        📍 <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($course['venue'], 'pa_intersoccer-venues') : ($course['venue'] ?: 'Unknown Venue')); ?>
                                                    </h3>
                                                </div>
                                                <div class="course-details">
                                                    <div class="detail-row">
                                                        <span class="detail-label">👶 Age Group</span>
                                                        <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($course['age_group'], 'pa_age-group') : $course['age_group']); ?></span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">⏰ Times</span>
                                                        <span class="detail-value"><?php echo esc_html($course['times'] ?: 'TBD'); ?></span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">📅 Duration</span>
                                                        <span class="detail-value">
                                                            <?php 
                                                            if ($course['start_date'] && $course['start_date'] !== '1970-01-01' && 
                                                                $course['end_date'] && $course['end_date'] !== '1970-01-01') {
                                                                echo esc_html(date_i18n('M j', strtotime($course['start_date'])) . ' - ' . date_i18n('M j, Y', strtotime($course['end_date'])));
                                                            } else {
                                                                echo 'TBD';
                                                            }
                                                            ?>
                                                        </span>
                                                    </div>
                                                    <div class="detail-row">
                                                        <span class="detail-label">🌆 City</span>
                                                        <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($course['city'], 'pa_city') : ($course['city'] ?: 'N/A')); ?></span>
                                                    </div>
                                                </div>
                                                <div class="course-footer">
                                                    <div class="player-count">
                                                        <span class="count-number <?php echo intersoccer_get_count_class($course['total_players']); ?>"><?php echo esc_html($course['total_players']); ?></span>
                                                        <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                                    </div>
                                                    <div class="course-actions">
                                                        <?php
                                                        $view_params = ['page' => 'intersoccer-roster-details', 'from' => 'girls-only', 'event_signature' => $course['event_signature'], 'girls_only' => '1'];
                                                        $view_url = add_query_arg($view_params, admin_url('admin.php'));
                                                        ?>
                                                        <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                            <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Render Tournament rosters page
 */
function intersoccer_render_tournaments_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    $current_user = wp_get_current_user();
    $is_coach = in_array('coach', $current_user->roles);
    $coach_accessible_venues = [];

    if ($is_coach) {
        if (!class_exists('InterSoccer_Admin_Coach_Assignments')) {
            require_once WP_PLUGIN_DIR . '/customer-referral-system/includes/class-admin-coach-assignments.php';
        }
        $coach_accessible_venues = InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues($current_user->ID);
    }

    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $selected_age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $selected_city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
    $selected_time = isset($_GET['times']) ? sanitize_text_field($_GET['times']) : '';

    $result = intersoccer_oop_get_tournament_listings(
        [
            'season' => $selected_season,
            'venue' => $selected_venue,
            'age_group' => $selected_age_group,
            'city' => $selected_city,
            'times' => $selected_time,
        ],
        [
            'is_coach' => $is_coach,
            'accessible_venues' => $coach_accessible_venues,
        ]
    );

    $query_time = $result['query_time'];
    $grouped = $result['grouped'];
    $all_seasons = $result['filters']['seasons'];
    $all_venues = $result['filters']['venues'];
    $all_age_groups = $result['filters']['age_groups'];
    $all_cities = $result['filters']['cities'];
    $all_times = $result['filters']['times'];

    error_log('InterSoccer OOP: Tournament aggregation time ' . $query_time . ' seconds');

    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>🏆 <?php _e('Tournaments', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=intersoccer-tournaments&action=reconcile'), 'intersoccer_reconcile')); ?>"
                   class="button button-secondary">
                    ↻ <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>

        <div class="roster-filters">
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-tournaments">
                <div class="filter-group">
                    <label><?php _e('Season', 'intersoccer-reports-rosters'); ?></label>
                    <select name="season" onchange="this.form.submit()">
                        <option value=""><?php _e('All Seasons', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_seasons as $season): ?>
                            <option value="<?php echo esc_attr($season); ?>" <?php selected($selected_season, $season); ?>>
                                <?php echo esc_html(intersoccer_normalize_season_for_display($season)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><?php _e('Venue', 'intersoccer-reports-rosters'); ?></label>
                    <select name="venue" onchange="this.form.submit()">
                        <option value=""><?php _e('All Venues', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_venues as $venue): ?>
                            <option value="<?php echo esc_attr($venue); ?>" <?php selected($selected_venue, $venue); ?>>
                                <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($venue, 'pa_intersoccer-venues') : $venue); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></label>
                    <select name="age_group" onchange="this.form.submit()">
                        <option value=""><?php _e('All Age Groups', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_age_groups as $age_group): ?>
                            <option value="<?php echo esc_attr($age_group); ?>" <?php selected($selected_age_group, $age_group); ?>>
                                <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($age_group, 'pa_age-group') : $age_group); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><?php _e('Time', 'intersoccer-reports-rosters'); ?></label>
                    <select name="times" onchange="this.form.submit()">
                        <option value=""><?php _e('All Times', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_times as $time): ?>
                            <option value="<?php echo esc_attr($time); ?>" <?php selected($selected_time, $time); ?>>
                                <?php echo esc_html($time); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><?php _e('City', 'intersoccer-reports-rosters'); ?></label>
                    <select name="city" onchange="this.form.submit()">
                        <option value=""><?php _e('All Cities', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_cities as $city): ?>
                            <option value="<?php echo esc_attr($city); ?>" <?php selected($selected_city, $city); ?>>
                                <?php echo esc_html($city); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selected_season || $selected_venue || $selected_age_group || $selected_city || $selected_time): ?>
                    <div class="filter-group">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-tournaments')); ?>" class="button button-secondary">
                            ↻ <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="sports-rosters">
            <?php if (empty($grouped)): ?>
                <div class="no-rosters">
                    <div class="no-rosters-icon">🏟️</div>
                    <h3><?php _e('No tournaments found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('Try adjusting your filters or sync rosters to see available tournaments.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $season => $events): ?>
                    <div class="roster-season">
                        <div class="season-header">
                            <h2 class="season-title">
                                <?php echo esc_html(intersoccer_normalize_season_for_display($season) . ' - ' . __('Tournaments', 'intersoccer-reports-rosters')); ?>
                            </h2>
                            <div class="season-stats">
                                <?php 
                                $player_total = array_sum(array_column($events, 'total_players'));
                                $event_count = count($events);
                                ?>
                                <span class="stat-item">
                                    <?php echo esc_html($player_total); ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    <?php echo esc_html($event_count); ?> <?php _e('events', 'intersoccer-reports-rosters'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="camps-grid">
                            <?php foreach ($events as $tournament): ?>
                                <div class="camp-card">
                                    <div class="camp-header">
                                        <h3 class="camp-venue">
                                            🏟️ <?php 
                                            // Ensure product name is in English for display
                                            $display_product_name = function_exists('intersoccer_get_english_product_name') 
                                                ? intersoccer_get_english_product_name($tournament['product_name'] ?? '', $tournament['product_id'] ?? 0)
                                                : ($tournament['product_name'] ?: __('Tournament', 'intersoccer-reports-rosters'));
                                            echo esc_html($display_product_name); 
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="camp-details">
                                        <div class="detail-row">
                                            <span class="detail-label">📍 <?php _e('Venue', 'intersoccer-reports-rosters'); ?></span>
                                            <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($tournament['venue'], 'pa_intersoccer-venues') : ($tournament['venue'] ?: 'N/A')); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">👶 <?php _e('Age Group', 'intersoccer-reports-rosters'); ?></span>
                                            <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($tournament['age_group'], 'pa_age-group') : $tournament['age_group']); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">⏰ <?php _e('Time', 'intersoccer-reports-rosters'); ?></span>
                                            <span class="detail-value"><?php echo esc_html($tournament['times'] ?: 'TBD'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">📅 <?php _e('Date', 'intersoccer-reports-rosters'); ?></span>
                                            <span class="detail-value">
                                                <?php
                                                if ($tournament['corrected_start_date'] !== '1970-01-01' && $tournament['corrected_end_date'] !== '1970-01-01') {
                                                    // Tournaments typically last one day, so show single date if start and end are the same
                                                    if ($tournament['corrected_start_date'] === $tournament['corrected_end_date']) {
                                                        echo esc_html(date_i18n('M j, Y', strtotime($tournament['corrected_start_date'])));
                                                    } else {
                                                        echo esc_html(date_i18n('M j', strtotime($tournament['corrected_start_date'])) . ' - ' . date_i18n('M j, Y', strtotime($tournament['corrected_end_date'])));
                                                    }
                                                } else {
                                                    echo esc_html__('TBD', 'intersoccer-reports-rosters');
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">🌆 <?php _e('City', 'intersoccer-reports-rosters'); ?></span>
                                            <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($tournament['city'], 'pa_city') : ($tournament['city'] ?: 'N/A')); ?></span>
                                        </div>
                                    </div>
                                    <div class="camp-footer">
                                        <div class="player-count">
                                            <span class="count-number <?php echo intersoccer_get_count_class($tournament['total_players']); ?>"><?php echo esc_html($tournament['total_players']); ?></span>
                                            <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                        </div>
                                        <div class="camp-actions">
                                            <?php
                                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&from=tournaments&event_signature=' . urlencode($tournament['event_signature']));
                                            ?>
                                            <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Alternative helper function to get course day from order item metadata
 */
function intersoccer_get_course_day_from_order_item($order_item_id) {
    global $wpdb;
    
    if (empty($order_item_id)) {
        return null;
    }
    
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
    
    // Try multiple possible metadata key variations
    $possible_keys = [
        'Course Day',
        'course_day',
        'pa_course-day',
        'Course_Day',
        'courseDay',
        '_course_day'
    ];
    
    foreach ($possible_keys as $meta_key) {
        $course_day = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $order_itemmeta_table 
             WHERE order_item_id = %d AND meta_key = %s 
             AND meta_value IS NOT NULL AND meta_value != ''
             LIMIT  1",
            $order_item_id,
            $meta_key
        ));
        
        if (!empty($course_day)) {
            error_log("InterSoccer: Retrieved course_day '$course_day' using meta_key '$meta_key' for order_item_id $order_item_id");
            return $course_day;
        }
    }
    
    // Log all available metadata if we can't find course_day
    $all_meta = $wpdb->get_results($wpdb->prepare(
       
        "SELECT meta_key, meta_value FROM $order_itemmeta_table 
         WHERE order_item_id = %d AND meta_value IS NOT NULL AND meta_value != ''",
        $order_item_id
    ), ARRAY_A);
    
    error_log("InterSoccer: Could not find course_day. All metadata for order_item_id $order_item_id: " . json_encode($all_meta));
    
    return null;
}

/**
 * Render Other Events page with improved formatting
 */
function intersoccer_render_other_events_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    // Check if user is a coach and filter venues accordingly
    $current_user = wp_get_current_user();
    $is_coach = in_array('coach', $current_user->roles);
    $coach_accessible_venues = [];

    if ($is_coach) {
        // Include the coach assignments class
        if (!class_exists('InterSoccer_Admin_Coach_Assignments')) {
            require_once WP_PLUGIN_DIR . '/customer-referral-system/includes/class-admin-coach-assignments.php';
        }
        $coach_accessible_venues = InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues($current_user->ID);
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    // Fetch Other Events data
    // Group ONLY by event_signature to ensure French and English versions combine
    // Use MAX() for non-aggregated fields to get a representative value when multiple exist
    $base_query = "SELECT MAX(COALESCE(season, 'N/A')) as season,
                      MAX(COALESCE(venue, 'N/A')) as venue,
                      MAX(COALESCE(product_name, 'N/A')) as product_name,
                      MAX(product_id) as product_id,
                      MAX(age_group) as age_group,
                      MAX(times) as times,
                      event_signature,
                      COUNT(DISTINCT order_item_id) as total_players,
                      GROUP_CONCAT(DISTINCT variation_id) as variation_ids,
                      GROUP_CONCAT(DISTINCT start_date) as start_dates,
                      GROUP_CONCAT(DISTINCT end_date) as end_dates
               FROM $rosters_table
               WHERE activity_type NOT IN ('Camp', 'Course')
               AND activity_type NOT LIKE '%Girls%'
               AND girls_only = 0";  // EXCLUDE Girls Only events (double check)

    // Add coach venue filtering if user is a coach
    if ($is_coach && !empty($coach_accessible_venues)) {
        $placeholders = implode(',', array_fill(0, count($coach_accessible_venues), '%s'));
        $base_query .= $wpdb->prepare(" AND venue IN ($placeholders)", $coach_accessible_venues);
    } elseif ($is_coach && empty($coach_accessible_venues)) {
        // Coach has no venue assignments, show no results
        $base_query .= " AND 1=0";
    }

    // Group ONLY by event_signature to ensure French and English versions combine
    // event_signature already includes all necessary identifying information
    $base_query .= " GROUP BY event_signature
                     ORDER BY season DESC, product_name, age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);

    // Use stored dates directly from the rosters table
    // The end date is already calculated and stored in the database from order item metadata
    $all_events = [];
    $all_seasons = [];
    $all_product_names = [];

    foreach ($groups as $group) {
        // Get variation IDs for reference (not needed for date calculation)
        $variation_ids = isset($group['variation_ids']) && !empty($group['variation_ids']) && is_string($group['variation_ids']) ? array_filter(explode(',', $group['variation_ids'])) : [];
        if (empty($variation_ids)) {
            $variation_ids = [0];
        }
        $group['variation_ids'] = $variation_ids;
        
        // Normalize product name to English for display (combine French and English versions)
        if (!empty($group['product_name']) && $group['product_name'] !== 'N/A') {
            $product_id = isset($group['product_id']) ? (int)$group['product_id'] : 0;
            $group['product_name'] = function_exists('intersoccer_get_english_product_name') 
                ? intersoccer_get_english_product_name($group['product_name'], $product_id)
                : $group['product_name'];
        }
        
        // Get dates directly from the database (already calculated and stored)
        $event_start = '1970-01-01';
        $event_end = '1970-01-01';
        
        if (!empty($group['start_dates']) && is_string($group['start_dates'])) {
            $start_dates_array = explode(',', $group['start_dates']);
            $event_start = !empty($start_dates_array[0]) ? trim($start_dates_array[0]) : '1970-01-01';
        }
        if (!empty($group['end_dates']) && is_string($group['end_dates'])) {
            $end_dates_array = explode(',', $group['end_dates']);
            $event_end = !empty($end_dates_array[0]) ? trim($end_dates_array[0]) : '1970-01-01';
        }

        // Validate and format dates
        $group['corrected_start_date'] = date('Y-m-d', strtotime($event_start)) ?: '1970-01-01';
        $group['corrected_end_date'] = date('Y-m-d', strtotime($event_end)) ?: '1970-01-01';

        // Collect unique seasons and product names for filters (use normalized English names)
        if ($group['season'] && $group['season'] !== 'N/A') {
            $all_seasons[$group['season']] = $group['season'];
        }
        if ($group['product_name'] && $group['product_name'] !== 'N/A') {
            $all_product_names[$group['product_name']] = $group['product_name'];
        }

        $all_events[] = $group;
    }

    // Get filter options
    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_product_name = isset($_GET['product_name']) ? sanitize_text_field($_GET['product_name']) : '';

    $display_events = $all_events;

    // Filter by season
    if ($selected_season) {
        $display_events = array_filter($display_events, function($event) use ($selected_season) {
            return $event['season'] === $selected_season;
        });
    }

    // Filter by product name
    if ($selected_product_name) {
        $display_events = array_filter($display_events, function($event) use ($selected_product_name) {
            return $event['product_name'] === $selected_product_name;
        });
    }

    error_log("InterSoccer: Filtered Other Events: " . print_r($display_events, true));

    // Group events by season
    $grouped_events = [];
    foreach ($display_events as $event) {
        $season = $event['season'] ?: 'N/A';
        $grouped_events[$season][] = $event;
    }
    ksort($grouped_events);

    // Render the page
    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('Other Events', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-other-events&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    ↻ <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>

        <div class="export-buttons">
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" target="_blank">
                <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                <input type="hidden" name="export_type" value="other">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                <input type="submit" name="export_other_events" class="button button-primary" 
                    value="<?php _e('↓ Export Other Events', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>

        <div class="roster-filters">
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-other-events">
                <div class="filter-group">
                    <label><?php _e('Season', 'intersoccer-reports-rosters'); ?></label>
                    <select name="season" onchange="this.form.submit()">
                        <option value=""><?php _e('All Seasons', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_seasons as $season): ?>
                            <option value="<?php echo esc_attr($season); ?>" <?php selected($selected_season, $season); ?>>
                                <?php echo esc_html(intersoccer_normalize_season_for_display($season)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><?php _e('Event', 'intersoccer-reports-rosters'); ?></label>
                    <select name="product_name" onchange="this.form.submit()">
                        <option value=""><?php _e('All Events', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_product_names as $product_name): ?>
                            <option value="<?php echo esc_attr($product_name); ?>" <?php selected($selected_product_name, $product_name); ?>>
                                <?php echo esc_html($product_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selected_season || $selected_product_name): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-other-events'); ?>" class="button button-secondary">
                        ↻ <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="sports-rosters">
            <?php if (empty($grouped_events)): ?>
                <div class="no-rosters">
                    <div class="no-rosters-icon">⚽</div>
                    <h3><?php _e('No events found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('Try adjusting your filters or sync rosters to see available events.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped_events as $season => $events): ?>
                    <div class="roster-season">
                        <div class="season-header">
                            <h2 class="season-title">
                                <?php echo esc_html(intersoccer_normalize_season_for_display($season)); ?>
                            </h2>
                            <div class="season-stats">
                                <?php 
                                $season_total = is_array($events) ? array_sum(array_column($events, 'total_players')) : 0;
                                $event_count = is_array($events) ? count($events) : 0;
                                ?>
                                <span class="stat-item">
                                    Players: <?php echo $season_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    Camps: <?php echo $event_count; ?> <?php _e('events', 'intersoccer-reports-rosters'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="courses-grid">
                            <?php if (is_array($events)): ?>
                                <?php foreach ($events as $event): ?>
                                    <div class="course-card">
                                        <div class="course-header">
                                            <h3 class="course-venue">
                                                📍 <?php echo esc_html($event['product_name'] ?: 'Unknown Event'); ?>
                                            </h3>
                                        </div>
                                        <div class="course-details">
                                            <div class="detail-row">
                                                <span class="detail-label">👶 Age Group</span>
                                                <span class="detail-value"><?php 
                                                if (function_exists('intersoccer_get_term_name')) {
                                                    echo esc_html(intersoccer_get_term_name($event['age_group'], 'pa_age-group'));
                                                } else {
                                                    echo esc_html($event['age_group'] ?: 'Unknown Age');
                                                }
                                                ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">⏰ Times</span>
                                                <span class="detail-value"><?php echo esc_html($event['times'] ?: 'TBD'); ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">📅 Duration</span>
                                                <span class="detail-value"><?php 
                                                if ($event['start_date'] !== '1970-01-01' && $event['end_date'] !== '1970-01-01') {
                                                    echo esc_html(date_i18n('M j', strtotime($event['start_date'])) . ' - ' . date_i18n('M j, Y', strtotime($event['end_date'])));
                                                } else {
                                                    echo 'TBD';
                                                }
                                                ?></span>
                                            </div>
                                        </div>
                                        <div class="course-footer">
                                            <div class="player-count">
                                                <span class="count-number <?php echo intersoccer_get_count_class($event['total_players']); ?>"><?php echo esc_html($event['total_players']); ?></span>
                                                <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                            </div>
                                            <div class="course-actions">
                                                <?php 
                                                // Use event_signature if available, otherwise fall back to variation_ids
                                                if (!empty($event['event_signature'])) {
                                                    $view_url = admin_url('admin.php?page=intersoccer-roster-details&event_signature=' . urlencode($event['event_signature']));
                                                } else {
                                                    $variation_ids_str = is_array($event['variation_ids']) ? implode(',', $event['variation_ids']) : (string) $event['variation_ids'];
                                                    $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_ids=' . urlencode($variation_ids_str) . '&product_name=' . urlencode($event['product_name']) . '&age_group=' . urlencode($event['age_group']) . '&times=' . urlencode($event['times']) . '&season=' . urlencode($season));
                                                }
                                                ?>
                                                <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                    <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p><?php _e('No events available for this season.', 'intersoccer-reports-rosters'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Updated All Rosters page with improved formatting
 */
function intersoccer_render_all_rosters_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    // Check if user is a coach and filter venues accordingly
    $current_user = wp_get_current_user();
    $is_coach = in_array('coach', $current_user->roles);
    $coach_accessible_venues = [];

    if ($is_coach) {
        // Include the coach assignments class
        if (!class_exists('InterSoccer_Admin_Coach_Assignments')) {
            require_once WP_PLUGIN_DIR . '/customer-referral-system/includes/class-admin-coach-assignments.php';
        }
        $coach_accessible_venues = InterSoccer_Admin_Coach_Assignments::get_coach_accessible_venues($current_user->ID);
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    $product_names_query = "SELECT DISTINCT product_name FROM $rosters_table WHERE product_name IS NOT NULL" . intersoccer_roster_placeholder_where();

    // Add coach venue filtering if user is a coach
    if ($is_coach && !empty($coach_accessible_venues)) {
        $placeholders = implode(',', array_fill(0, count($coach_accessible_venues), '%s'));
        $product_names_query .= " AND venue IN ($placeholders)";
        $product_names = $wpdb->get_col($wpdb->prepare($product_names_query, $coach_accessible_venues));
    } elseif ($is_coach && empty($coach_accessible_venues)) {
        // Coach has no venue assignments, show no products
        $product_names = [];
    } else {
        $product_names = $wpdb->get_col($product_names_query);
    }

    $product_names = array_filter($product_names); // Remove any null values
    sort($product_names);

    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('All Rosters', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-all-rosters&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    ↻ <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>
        
        <?php if (empty($product_names)) : ?>
            <div class="no-rosters">
                <div class="no-rosters-icon">⚽</div>
                <h3><?php _e('No rosters available', 'intersoccer-reports-rosters'); ?></h3>
                <p><?php _e('Please reconcile manually to sync roster data.', 'intersoccer-reports-rosters'); ?></p>
            </div>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="all">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_all" class="button button-primary" value="<?php _e('↓ Export All Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            
            <div class="sports-rosters">
                <?php
                foreach ($product_names as $product_name) {
                    // Group ONLY by event_signature to ensure French and English versions combine
                    // Use MAX() for non-aggregated fields to get a representative value when multiple exist
                    $query = "SELECT MAX(variation_id) as variation_id, MAX(product_name) as product_name, MAX(product_id) as product_id, MAX(venue) as venue, MAX(age_group) as age_group, event_signature, COUNT(DISTINCT order_item_id) as total_players
                              FROM $rosters_table
                              WHERE product_name = %s" . intersoccer_roster_placeholder_where();

                    // Add coach venue filtering if user is a coach
                    if ($is_coach && !empty($coach_accessible_venues)) {
                        $placeholders = implode(',', array_fill(0, count($coach_accessible_venues), '%s'));
                        $query .= " AND venue IN ($placeholders)";
                        $query_args = array_merge([$product_name], $coach_accessible_venues);
                    } elseif ($is_coach && empty($coach_accessible_venues)) {
                        // Coach has no venue assignments, skip this product
                        continue;
                    } else {
                        $query_args = [$product_name];
                    }

                    // Group ONLY by event_signature to ensure French and English versions combine
                    // event_signature already includes all necessary identifying information
                    $query .= " GROUP BY event_signature
                               ORDER BY product_name, venue, age_group";

                    $groups = $wpdb->get_results(
                        $wpdb->prepare($query, $query_args),
                        ARRAY_A
                    );
                    
                    if (!empty($groups)) {
                        // Normalize product name to English for display
                        $display_product_name = function_exists('intersoccer_get_english_product_name') 
                            ? intersoccer_get_english_product_name($product_name, 0)
                            : $product_name;
                        
                        echo '<div class="roster-season">';
                        echo '<div class="season-header">';
                        echo '<h2 class="season-title">' . esc_html($display_product_name) . '</h2>';
                        echo '<div class="season-stats">';
                        echo '<span class="stat-item">Players: ' . array_sum(array_column($groups, 'total_players')) . ' ' . __('players', 'intersoccer-reports-rosters') . '</span>';
                        echo '<span class="stat-item">Camps: ' . count($groups) . ' ' . __('variations', 'intersoccer-reports-rosters') . '</span>';
                        echo '</div>';
                        echo '</div>';
                        
                        echo '<div style="padding: 25px;">';
                        echo '<table class="wp-list-table widefat fixed striped" style="border-radius: 8px; overflow: hidden;">';
                        echo '<thead style="background: #f8f9fa;"><tr>';
                        echo '<th style="padding: 15px;">' . __('Product Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="padding: 15px;">' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="padding: 15px;">' . __('Age Group', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="padding: 15px;">' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="padding: 15px;">' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        
                        foreach ($groups as $group) {
                            // Use event_signature if available, otherwise fall back to variation_id
                            if (!empty($group['event_signature'])) {
                                $view_url = admin_url('admin.php?page=intersoccer-roster-details&event_signature=' . urlencode($group['event_signature']));
                            } else {
                                $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . urlencode($group['variation_id']) . '&age_group=' . urlencode($group['age_group']));
                            }
                            
                            // Normalize product name to English for display
                            $group_product_name = isset($group['product_name']) ? $group['product_name'] : $product_name;
                            $display_group_product_name = function_exists('intersoccer_get_english_product_name') 
                                ? intersoccer_get_english_product_name($group_product_name, $group['product_id'] ?? 0)
                                : $group_product_name;
                            
                            echo '<tr>';
                            echo '<td style="padding: 15px;">' . esc_html($display_group_product_name) . '</td>';
                            echo '<td style="padding: 15px;">' . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($group['venue'] ?: 'N/A', 'pa_intersoccer-venues') : ($group['venue'] ?: 'N/A')) . '</td>';
                            echo '<td style="padding: 15px;">' . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($group['age_group'], 'pa_age-group') : $group['age_group']) . '</td>';
                            echo '<td style="padding: 15px;"><strong>' . esc_html($group['total_players']) . '</strong></td>';
                            echo '<td style="padding: 15px;"><a href="' . esc_url($view_url) . '" class="button-roster-view">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
<?php
}

/**
 * Helper function to get CSS class for participant count based on business rules
 */
function intersoccer_get_count_class($count) {
    $count = (int) $count;
    if ($count <= 7) {
        return 'count-critical'; // Red - Event canceled
    } elseif ($count <= 20) {
        return 'count-low'; // Orange - Event can proceed but not ideal
    } elseif ($count <= 29) {
        return 'count-good'; // Green - Good attendance
    } else {
        return 'count-optimal'; // Blue - Optimal attendance (30+)
    }
}