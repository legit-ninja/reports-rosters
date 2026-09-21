<?php
/**
 * Roster Editor Admin UI
 * 
 * Allows manual editing of entries in the intersoccer_rosters table
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.0
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Output shared roster editor CSS styles (called once per page load).
 */
function intersoccer_roster_editor_styles() {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    ?>
    <style>
        /* Roster Editor filter panel */
        .intersoccer-editor-filters {
            background: var(--intersoccer-bg-header, #f9f9f9);
            border: 1px solid var(--intersoccer-border-subtle, #ddd);
            border-radius: var(--intersoccer-radius-md, 4px);
            padding: var(--intersoccer-space-xl, 20px);
            margin: var(--intersoccer-space-xl, 20px) 0;
        }
        .intersoccer-editor-filters > form > div {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--intersoccer-space-lg, 16px);
            margin-bottom: var(--intersoccer-space-lg, 16px);
        }
        .intersoccer-editor-filters label {
            display: block;
            margin-bottom: var(--intersoccer-space-xs, 4px);
            font-weight: 600;
            color: var(--intersoccer-text-primary, #1d2327);
        }
        .intersoccer-editor-filters input,
        .intersoccer-editor-filters select {
            width: 100%;
            padding: var(--intersoccer-space-sm, 8px);
            border: 1px solid var(--intersoccer-border-subtle, #ddd);
            border-radius: var(--intersoccer-radius-md, 4px);
            background: var(--intersoccer-bg-surface, #fff);
            transition: border-color var(--intersoccer-transition-fast, 0.15s ease);
        }
        .intersoccer-editor-filters input:focus,
        .intersoccer-editor-filters select:focus {
            border-color: var(--intersoccer-text-link, #2271b1);
            outline: var(--intersoccer-focus-ring, 2px solid #2271b1);
            outline-offset: var(--intersoccer-focus-ring-offset, 1px);
        }
        
        /* Roster Editor modal */
        .intersoccer-editor-modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--intersoccer-bg-surface, #fff);
            padding: var(--intersoccer-space-xl, 20px);
            border: 2px solid var(--intersoccer-text-link, #2271b1);
            border-radius: var(--intersoccer-radius-lg, 6px);
            box-shadow: var(--intersoccer-shadow-elevated, 0 4px 12px rgba(0, 0, 0, 0.1));
            z-index: 100000;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .intersoccer-editor-modal h3 {
            margin-top: 0;
            color: var(--intersoccer-text-primary, #1d2327);
        }
        .intersoccer-editor-modal input,
        .intersoccer-editor-modal select,
        .intersoccer-editor-modal textarea {
            width: 100%;
            padding: var(--intersoccer-space-sm, 8px);
            border: 1px solid var(--intersoccer-border-subtle, #ddd);
            border-radius: var(--intersoccer-radius-md, 4px);
            background: var(--intersoccer-bg-surface, #fff);
        }
        .intersoccer-editor-modal textarea {
            min-height: 100px;
        }
        .intersoccer-editor-modal button:focus {
            outline: var(--intersoccer-focus-ring, 2px solid #2271b1);
            outline-offset: var(--intersoccer-focus-ring-offset, 1px);
        }
        .intersoccer-editor-modal-actions {
            margin-top: var(--intersoccer-space-lg, 16px);
            display: flex;
            gap: var(--intersoccer-space-sm, 8px);
            justify-content: flex-end;
        }
        .intersoccer-editor-button-group {
            display: flex;
            gap: var(--intersoccer-space-sm, 8px);
        }
        .intersoccer-editor-loading {
            display: none;
            text-align: center;
            padding: var(--intersoccer-space-xl, 20px);
        }
        
        .intersoccer-editor-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--intersoccer-overlay-bg, rgba(0, 0, 0, 0.5));
            z-index: 99999;
            cursor: pointer;
        }
        
        /* Table styles */
        .intersoccer-roster-editor table.widefat {
            margin-top: var(--intersoccer-space-xl, 20px);
        }
        .intersoccer-roster-editor table.widefat th,
        .intersoccer-roster-editor table.widefat td {
            padding: var(--intersoccer-space-sm, 8px) var(--intersoccer-space-md, 12px);
            font-size: var(--intersoccer-font-size-md, 13px);
            vertical-align: middle;
        }
        .intersoccer-roster-editor table.widefat th {
            background: var(--intersoccer-bg-row-alt, #f8f9fa);
            font-weight: 600;
            border-bottom: 2px solid var(--intersoccer-border-default, #ccd0d4);
        }
        .intersoccer-roster-editor table.widefat tbody tr:hover {
            background: var(--intersoccer-bg-muted, #f0f0f1);
        }
        .intersoccer-roster-editor .editable-cell {
            cursor: pointer;
            position: relative;
            transition: background-color var(--intersoccer-transition-normal, 0.2s ease);
        }
        .intersoccer-roster-editor .editable-cell:hover {
            background-color: var(--intersoccer-warning-bg, #fcf9e8) !important;
        }
        .intersoccer-roster-editor .editable-cell:focus {
            outline: var(--intersoccer-focus-ring, 2px solid #2271b1);
            outline-offset: var(--intersoccer-focus-ring-offset, 1px);
        }
        .intersoccer-roster-editor .editable-cell.editing {
            background-color: var(--intersoccer-warning-bg, #fcf9e8) !important;
        }
        .intersoccer-roster-editor .row-actions {
            visibility: visible;
        }
        .tablenav {
            margin: var(--intersoccer-space-sm, 8px) 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .tablenav-pages {
            display: flex;
            align-items: center;
            gap: var(--intersoccer-space-sm, 8px);
        }
        @media (max-width: 782px) {
            .intersoccer-editor-filters > form > div {
                grid-template-columns: 1fr !important;
            }
            .intersoccer-roster-editor table.widefat {
                font-size: var(--intersoccer-font-size-sm, 12px);
            }
            .intersoccer-roster-editor table.widefat th,
            .intersoccer-roster-editor table.widefat td {
                padding: var(--intersoccer-space-xs, 4px) var(--intersoccer-space-sm, 8px);
            }
            .intersoccer-editor-modal {
                width: 95%;
                max-width: 95%;
            }
        }
    </style>
    <?php
}

/**
 * Render the roster editor as tab content (for Settings page)
 */
function intersoccer_render_roster_editor_tab() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    // Enqueue scripts and styles
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
    
    // Get filter values from URL
    $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $activity_type = isset($_GET['activity_type']) ? sanitize_text_field($_GET['activity_type']) : '';
    $season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $canton_region = isset($_GET['canton_region']) ? sanitize_text_field($_GET['canton_region']) : '';
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    $page_num = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 50;

    // Get unique values for filter dropdowns
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    $activity_types = $wpdb->get_col("SELECT DISTINCT activity_type FROM $rosters_table WHERE activity_type != '' ORDER BY activity_type");
    $seasons = $wpdb->get_col("SELECT DISTINCT season FROM $rosters_table WHERE season != '' AND season IS NOT NULL ORDER BY season DESC");
    $canton_regions = $wpdb->get_col("SELECT DISTINCT canton_region FROM $rosters_table WHERE canton_region != '' AND canton_region IS NOT NULL ORDER BY canton_region");

    // Localize script for AJAX - use inline script instead since jquery might not be enqueued properly
    $ajax_url = admin_url('admin-ajax.php');
    $ajax_nonce = wp_create_nonce('intersoccer_roster_editor');

    ?>
    <div class="intersoccer-roster-editor">
        <p><?php _e('Search, filter, and edit roster entries. Click on a row to edit inline, or use the Edit button for full editing.', 'intersoccer-reports-rosters'); ?></p>

        <!-- Filter Section -->
        <div class="intersoccer-filters intersoccer-editor-filters">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="roster-editor-filters">
                <input type="hidden" name="page" value="intersoccer-advanced" />
                <input type="hidden" name="tab" value="edit-rosters" />
                
                <div>
                    <div>
                        <label for="search" >
                            <?php _e('Search:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <input type="text" name="search" id="search" value="<?php echo esc_attr($search); ?>" 
                               placeholder="<?php esc_attr_e('Player name, Order ID, Venue...', 'intersoccer-reports-rosters'); ?>" 
 />
                    </div>
                    
                    <div>
                        <label for="activity_type" >
                            <?php _e('Activity Type:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <select name="activity_type" id="activity_type">
                            <option value=""><?php _e('All Types', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($activity_types as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($activity_type, $type); ?>>
                                    <?php echo esc_html($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="season" >
                            <?php _e('Season:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <select name="season" id="season">
                            <option value=""><?php _e('All Seasons', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($seasons as $s): ?>
                                <option value="<?php echo esc_attr($s); ?>" <?php selected($season, $s); ?>>
                                    <?php echo esc_html($s); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="canton_region" >
                            <?php _e('Canton/Region:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <select name="canton_region" id="canton_region">
                            <option value=""><?php _e('All Regions', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($canton_regions as $cr): ?>
                                <option value="<?php echo esc_attr($cr); ?>" <?php selected($canton_region, $cr); ?>>
                                    <?php echo esc_html($cr); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="start_date" >
                            <?php _e('Start Date:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <input type="text" name="start_date" id="start_date" value="<?php echo esc_attr($start_date); ?>" 
                               placeholder="YYYY-MM-DD" />
                    </div>
                    
                    <div>
                        <label for="end_date" >
                            <?php _e('End Date:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <input type="text" name="end_date" id="end_date" value="<?php echo esc_attr($end_date); ?>" 
                               placeholder="YYYY-MM-DD" />
                    </div>
                </div>
                
                <div class="intersoccer-editor-button-group">
                    <button type="submit" class="button button-primary">
                        <?php _e('Filter', 'intersoccer-reports-rosters'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-advanced&tab=edit-rosters')); ?>" class="button">
                        <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
            </form>
        </div>

        <!-- Results Container -->
        <div id="roster-editor-results">
            <div id="loading-indicator" class="intersoccer-editor-loading">
                <span class="spinner is-active" style="float: none;"></span>
                <?php _e('Loading...', 'intersoccer-reports-rosters'); ?>
            </div>
            <div id="roster-table-container"></div>
        </div>

        <!-- Inline edit modal (hidden by default) -->
        <div id="inline-edit-modal" class="intersoccer-editor-modal">
            <h3 id="inline-edit-title"><?php _e('Edit Field', 'intersoccer-reports-rosters'); ?></h3>
            <div id="inline-edit-content"></div>
            <div class="intersoccer-editor-modal-actions">
                <button type="button" class="button" id="inline-edit-cancel"><?php _e('Cancel', 'intersoccer-reports-rosters'); ?></button>
                <button type="button" class="button button-primary" id="inline-edit-save"><?php _e('Save', 'intersoccer-reports-rosters'); ?></button>
            </div>
        </div>
        <div id="inline-edit-overlay" class="intersoccer-editor-overlay"></div>
    </div>

    <?php intersoccer_roster_editor_styles(); ?>

    <script>
    // Define AJAX settings before jQuery ready
    var intersoccer_roster_editor = {
        ajaxurl: '<?php echo esc_js($ajax_url); ?>',
        nonce: '<?php echo esc_js($ajax_nonce); ?>'
    };
    
    jQuery(document).ready(function($) {
        // Initialize datepickers
        $('#start_date, #end_date').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
        });

        var currentPage = <?php echo $page_num; ?>;

        // Load initial data
        loadRosterEntries();

        // Handle filter form submission
        $('#roster-editor-filters').on('submit', function(e) {
            e.preventDefault();
            currentPage = 1; // Reset to page 1 on filter change
            loadRosterEntries();
        });

        // Handle pagination clicks
        $(document).on('click', '.tablenav-pages a[data-page]', function(e) {
            e.preventDefault();
            var page = parseInt($(this).data('page'));
            if (page > 0) {
                currentPage = page;
                loadRosterEntries();
            }
        });

        // Handle page number input
        $(document).on('change', '.current-page', function() {
            var page = parseInt($(this).val());
            var maxPage = parseInt($(this).attr('max'));
            if (page > 0 && page <= maxPage) {
                currentPage = page;
                loadRosterEntries();
            } else {
                // Reset to current page if invalid
                $(this).val(currentPage);
            }
        });

        // Load roster entries via AJAX
        function loadRosterEntries() {
            $('#loading-indicator').show();
            $('#roster-table-container').html('');

            var formData = {
                action: 'intersoccer_load_roster_entries',
                nonce: intersoccer_roster_editor.nonce,
                search: $('#search').val(),
                activity_type: $('#activity_type').val(),
                season: $('#season').val(),
                canton_region: $('#canton_region').val(),
                start_date: $('#start_date').val(),
                end_date: $('#end_date').val(),
                paged: currentPage
            };

            $.ajax({
                url: intersoccer_roster_editor.ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $('#loading-indicator').hide();
                    if (response.success) {
                        $('#roster-table-container').html(response.data.html);
                        initInlineEditing();
                    } else {
                        $('#roster-table-container').html('<p class="error">' + (response.data.message || 'Error loading data') + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    $('#loading-indicator').hide();
                    $('#roster-table-container').html('<p class="error"><?php echo esc_js(__('Connection error. Please try again.', 'intersoccer-reports-rosters')); ?></p>');
                }
            });
        }

        // Initialize inline editing
        function initInlineEditing() {
            $('.editable-cell').off('dblclick').on('dblclick', function() {
                var $cell = $(this);
                var fieldName = $cell.data('field');
                var rosterId = $cell.closest('tr').data('roster-id');
                var currentValue = $cell.text().trim();

                // Show inline edit modal
                showInlineEditModal(rosterId, fieldName, currentValue, $cell);
            });
        }

        // Show inline edit modal
        function showInlineEditModal(rosterId, fieldName, currentValue, $cell) {
            var fieldLabel = $cell.closest('th').text() || $('th[data-field="' + fieldName + '"]').text() || fieldName;

            $('#inline-edit-title').text('<?php echo esc_js(__('Edit', 'intersoccer-reports-rosters')); ?>: ' + fieldLabel);
            
            // Clean current value (remove "—" and trim)
            var cleanValue = currentValue === '—' || currentValue === '' ? '' : currentValue.trim();
            
            // Determine input type based on field (styles from .intersoccer-editor-modal CSS)
            var inputHtml = '';
            if (fieldName.includes('date')) {
                inputHtml = '<input type="text" id="inline-edit-value" class="datepicker" value="' + escapeHtml(cleanValue) + '" />';
            } else if (fieldName === 'gender' || fieldName === 'player_gender') {
                inputHtml = '<select id="inline-edit-value">' +
                    '<option value=""><?php echo esc_js(__('N/A', 'intersoccer-reports-rosters')); ?></option>' +
                    '<option value="Male"' + (cleanValue === 'Male' ? ' selected' : '') + '>Male</option>' +
                    '<option value="Female"' + (cleanValue === 'Female' ? ' selected' : '') + '>Female</option>' +
                    '<option value="Other"' + (cleanValue === 'Other' ? ' selected' : '') + '>Other</option>' +
                    '</select>';
            } else if (fieldName.includes('text') || fieldName.includes('medical') || fieldName.includes('dietary')) {
                inputHtml = '<textarea id="inline-edit-value">' + escapeHtml(cleanValue) + '</textarea>';
            } else if (fieldName.includes('price') || fieldName.includes('amount')) {
                inputHtml = '<input type="number" id="inline-edit-value" value="' + escapeHtml(cleanValue) + '" step="0.01" />';
            } else if (fieldName === 'age') {
                inputHtml = '<input type="number" id="inline-edit-value" value="' + escapeHtml(cleanValue) + '" step="1" min="0" max="100" />';
            } else {
                inputHtml = '<input type="text" id="inline-edit-value" value="' + escapeHtml(cleanValue) + '" />';
            }

            $('#inline-edit-content').html(inputHtml);
            
            // Initialize datepicker if needed
            setTimeout(function() {
                if ($('#inline-edit-value.datepicker').length) {
                    $('#inline-edit-value.datepicker').datepicker({
                        dateFormat: 'yy-mm-dd',
                        changeMonth: true,
                        changeYear: true,
                        yearRange: '2020:+2'
                    });
                }
            }, 100);

            $('#inline-edit-modal').data('roster-id', rosterId);
            $('#inline-edit-modal').data('field-name', fieldName);
            $('#inline-edit-modal').data('cell', $cell);
            $('#inline-edit-overlay, #inline-edit-modal').show();
        }

        // Save inline edit
        $('#inline-edit-save').on('click', function() {
            var $button = $(this);
            var originalText = $button.text();
            $button.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'intersoccer-reports-rosters')); ?>');
            
            var rosterId = $('#inline-edit-modal').data('roster-id');
            var fieldName = $('#inline-edit-modal').data('field-name');
            var $cell = $('#inline-edit-modal').data('cell');
            var newValue = $('#inline-edit-value').val();

            $.ajax({
                url: intersoccer_roster_editor.ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_update_roster_entry',
                    nonce: intersoccer_roster_editor.nonce,
                    roster_id: rosterId,
                    field: fieldName,
                    value: newValue
                },
                success: function(response) {
                    $button.prop('disabled', false).text(originalText);
                    if (response.success) {
                        // Format display value
                        var displayValue = newValue || '—';
                        if (displayValue === '' || displayValue === null) {
                            displayValue = '—';
                        }
                        $cell.text(displayValue);
                        $('#inline-edit-overlay, #inline-edit-modal').hide();
                        showNotification('<?php echo esc_js(__('Updated successfully', 'intersoccer-reports-rosters')); ?>', 'success');
                    } else {
                        showNotification('<?php echo esc_js(__('Update failed:', 'intersoccer-reports-rosters')); ?> ' + (response.data.message || '<?php echo esc_js(__('Unknown error', 'intersoccer-reports-rosters')); ?>'), 'error');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(originalText);
                    showNotification('<?php echo esc_js(__('Connection error', 'intersoccer-reports-rosters')); ?>', 'error');
                }
            });
        });

        // Cancel inline edit
        $('#inline-edit-cancel, #inline-edit-overlay').on('click', function() {
            $('#inline-edit-overlay, #inline-edit-modal').hide();
        });

        // Helper function to escape HTML
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Show notification
        function showNotification(message, type) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after($notice);
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
    });
    </script>
    <?php
}

/**
 * Render the main roster editor page (list view) - kept for backward compatibility
 */
function intersoccer_render_roster_editor_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    // Enqueue scripts and styles
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
    
    // Get filter values from URL
    $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $activity_type = isset($_GET['activity_type']) ? sanitize_text_field($_GET['activity_type']) : '';
    $season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $canton_region = isset($_GET['canton_region']) ? sanitize_text_field($_GET['canton_region']) : '';
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    $page_num = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 50;

    // Get unique values for filter dropdowns
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    $activity_types = $wpdb->get_col("SELECT DISTINCT activity_type FROM $rosters_table WHERE activity_type != '' ORDER BY activity_type");
    $seasons = $wpdb->get_col("SELECT DISTINCT season FROM $rosters_table WHERE season != '' AND season IS NOT NULL ORDER BY season DESC");
    $canton_regions = $wpdb->get_col("SELECT DISTINCT canton_region FROM $rosters_table WHERE canton_region != '' AND canton_region IS NOT NULL ORDER BY canton_region");

    // Localize script for AJAX - use inline script instead since jquery might not be enqueued properly
    $ajax_url = admin_url('admin-ajax.php');
    $ajax_nonce = wp_create_nonce('intersoccer_roster_editor');

    ?>
    <div class="wrap intersoccer-roster-editor">
        <h1><?php _e('Edit Rosters', 'intersoccer-reports-rosters'); ?></h1>
        <p><?php _e('Search, filter, and edit roster entries. Click on a row to edit inline, or use the Edit button for full editing.', 'intersoccer-reports-rosters'); ?></p>

        <!-- Filter Section -->
        <div class="intersoccer-filters intersoccer-editor-filters">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="roster-editor-filters">
                <input type="hidden" name="page" value="intersoccer-advanced" />
                <input type="hidden" name="tab" value="edit-rosters" />
                
                <div>
                    <div>
                        <label for="search" >
                            <?php _e('Search:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <input type="text" name="search" id="search" value="<?php echo esc_attr($search); ?>" 
                               placeholder="<?php esc_attr_e('Player name, Order ID, Venue...', 'intersoccer-reports-rosters'); ?>" 
 />
                    </div>
                    
                    <div>
                        <label for="activity_type" >
                            <?php _e('Activity Type:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <select name="activity_type" id="activity_type">
                            <option value=""><?php _e('All Types', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($activity_types as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($activity_type, $type); ?>>
                                    <?php echo esc_html($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="season" >
                            <?php _e('Season:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <select name="season" id="season">
                            <option value=""><?php _e('All Seasons', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($seasons as $s): ?>
                                <option value="<?php echo esc_attr($s); ?>" <?php selected($season, $s); ?>>
                                    <?php echo esc_html($s); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="canton_region" >
                            <?php _e('Canton/Region:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <select name="canton_region" id="canton_region">
                            <option value=""><?php _e('All Regions', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($canton_regions as $cr): ?>
                                <option value="<?php echo esc_attr($cr); ?>" <?php selected($canton_region, $cr); ?>>
                                    <?php echo esc_html($cr); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="start_date" >
                            <?php _e('Start Date:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <input type="text" name="start_date" id="start_date" value="<?php echo esc_attr($start_date); ?>" 
                               placeholder="YYYY-MM-DD" />
                    </div>
                    
                    <div>
                        <label for="end_date" >
                            <?php _e('End Date:', 'intersoccer-reports-rosters'); ?>
                        </label>
                        <input type="text" name="end_date" id="end_date" value="<?php echo esc_attr($end_date); ?>" 
                               placeholder="YYYY-MM-DD" />
                    </div>
                </div>
                
                <div class="intersoccer-editor-button-group">
                    <button type="submit" class="button button-primary">
                        <?php _e('Filter', 'intersoccer-reports-rosters'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-advanced&tab=edit-rosters')); ?>" class="button">
                        <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
            </form>
        </div>

        <!-- Results Container -->
        <div id="roster-editor-results">
            <div id="loading-indicator" class="intersoccer-editor-loading">
                <span class="spinner is-active" style="float: none;"></span>
                <?php _e('Loading...', 'intersoccer-reports-rosters'); ?>
            </div>
            <div id="roster-table-container"></div>
        </div>

        <!-- Inline edit modal (hidden by default) -->
        <div id="inline-edit-modal" class="intersoccer-editor-modal">
            <h3 id="inline-edit-title"><?php _e('Edit Field', 'intersoccer-reports-rosters'); ?></h3>
            <div id="inline-edit-content"></div>
            <div class="intersoccer-editor-modal-actions">
                <button type="button" class="button" id="inline-edit-cancel"><?php _e('Cancel', 'intersoccer-reports-rosters'); ?></button>
                <button type="button" class="button button-primary" id="inline-edit-save"><?php _e('Save', 'intersoccer-reports-rosters'); ?></button>
            </div>
        </div>
        <div id="inline-edit-overlay" class="intersoccer-editor-overlay"></div>
    </div>

    <?php intersoccer_roster_editor_styles(); ?>

    <script>
    // Define AJAX settings before jQuery ready
    var intersoccer_roster_editor = {
        ajaxurl: '<?php echo esc_js($ajax_url); ?>',
        nonce: '<?php echo esc_js($ajax_nonce); ?>'
    };
    
    jQuery(document).ready(function($) {
        // Initialize datepickers
        $('#start_date, #end_date').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
        });

        var currentPage = <?php echo $page_num; ?>;

        // Load initial data
        loadRosterEntries();

        // Handle filter form submission
        $('#roster-editor-filters').on('submit', function(e) {
            e.preventDefault();
            currentPage = 1; // Reset to page 1 on filter change
            loadRosterEntries();
        });

        // Handle pagination clicks
        $(document).on('click', '.tablenav-pages a[data-page]', function(e) {
            e.preventDefault();
            var page = parseInt($(this).data('page'));
            if (page > 0) {
                currentPage = page;
                loadRosterEntries();
            }
        });

        // Handle page number input
        $(document).on('change', '.current-page', function() {
            var page = parseInt($(this).val());
            var maxPage = parseInt($(this).attr('max'));
            if (page > 0 && page <= maxPage) {
                currentPage = page;
                loadRosterEntries();
            } else {
                // Reset to current page if invalid
                $(this).val(currentPage);
            }
        });

        // Load roster entries via AJAX
        function loadRosterEntries() {
            $('#loading-indicator').show();
            $('#roster-table-container').html('');

            var formData = {
                action: 'intersoccer_load_roster_entries',
                nonce: intersoccer_roster_editor.nonce,
                search: $('#search').val(),
                activity_type: $('#activity_type').val(),
                season: $('#season').val(),
                canton_region: $('#canton_region').val(),
                start_date: $('#start_date').val(),
                end_date: $('#end_date').val(),
                paged: currentPage
            };

            $.ajax({
                url: intersoccer_roster_editor.ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $('#loading-indicator').hide();
                    if (response.success) {
                        $('#roster-table-container').html(response.data.html);
                        initInlineEditing();
                    } else {
                        $('#roster-table-container').html('<p class="error">' + (response.data.message || 'Error loading data') + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    $('#loading-indicator').hide();
                    $('#roster-table-container').html('<p class="error"><?php echo esc_js(__('Connection error. Please try again.', 'intersoccer-reports-rosters')); ?></p>');
                }
            });
        }

        // Initialize inline editing
        function initInlineEditing() {
            $('.editable-cell').off('dblclick').on('dblclick', function() {
                var $cell = $(this);
                var fieldName = $cell.data('field');
                var rosterId = $cell.closest('tr').data('roster-id');
                var currentValue = $cell.text().trim();

                // Show inline edit modal
                showInlineEditModal(rosterId, fieldName, currentValue, $cell);
            });
        }

        // Show inline edit modal
        function showInlineEditModal(rosterId, fieldName, currentValue, $cell) {
            var fieldLabel = $cell.closest('th').text() || $('th[data-field="' + fieldName + '"]').text() || fieldName;

            $('#inline-edit-title').text('<?php echo esc_js(__('Edit', 'intersoccer-reports-rosters')); ?>: ' + fieldLabel);
            
            // Clean current value (remove "—" and trim)
            var cleanValue = currentValue === '—' || currentValue === '' ? '' : currentValue.trim();
            
            // Determine input type based on field (styles from .intersoccer-editor-modal CSS)
            var inputHtml = '';
            if (fieldName.includes('date')) {
                inputHtml = '<input type="text" id="inline-edit-value" class="datepicker" value="' + escapeHtml(cleanValue) + '" />';
            } else if (fieldName === 'gender' || fieldName === 'player_gender') {
                inputHtml = '<select id="inline-edit-value">' +
                    '<option value=""><?php echo esc_js(__('N/A', 'intersoccer-reports-rosters')); ?></option>' +
                    '<option value="Male"' + (cleanValue === 'Male' ? ' selected' : '') + '>Male</option>' +
                    '<option value="Female"' + (cleanValue === 'Female' ? ' selected' : '') + '>Female</option>' +
                    '<option value="Other"' + (cleanValue === 'Other' ? ' selected' : '') + '>Other</option>' +
                    '</select>';
            } else if (fieldName.includes('text') || fieldName.includes('medical') || fieldName.includes('dietary')) {
                inputHtml = '<textarea id="inline-edit-value">' + escapeHtml(cleanValue) + '</textarea>';
            } else if (fieldName.includes('price') || fieldName.includes('amount')) {
                inputHtml = '<input type="number" id="inline-edit-value" value="' + escapeHtml(cleanValue) + '" step="0.01" />';
            } else if (fieldName === 'age') {
                inputHtml = '<input type="number" id="inline-edit-value" value="' + escapeHtml(cleanValue) + '" step="1" min="0" max="100" />';
            } else {
                inputHtml = '<input type="text" id="inline-edit-value" value="' + escapeHtml(cleanValue) + '" />';
            }

            $('#inline-edit-content').html(inputHtml);
            
            // Initialize datepicker if needed
            setTimeout(function() {
                if ($('#inline-edit-value.datepicker').length) {
                    $('#inline-edit-value.datepicker').datepicker({
                        dateFormat: 'yy-mm-dd',
                        changeMonth: true,
                        changeYear: true,
                        yearRange: '2020:+2'
                    });
                }
            }, 100);

            $('#inline-edit-modal').data('roster-id', rosterId);
            $('#inline-edit-modal').data('field-name', fieldName);
            $('#inline-edit-modal').data('cell', $cell);
            $('#inline-edit-overlay, #inline-edit-modal').show();
        }

        // Save inline edit
        $('#inline-edit-save').on('click', function() {
            var $button = $(this);
            var originalText = $button.text();
            $button.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'intersoccer-reports-rosters')); ?>');
            
            var rosterId = $('#inline-edit-modal').data('roster-id');
            var fieldName = $('#inline-edit-modal').data('field-name');
            var $cell = $('#inline-edit-modal').data('cell');
            var newValue = $('#inline-edit-value').val();

            $.ajax({
                url: intersoccer_roster_editor.ajaxurl,
                type: 'POST',
                data: {
                    action: 'intersoccer_update_roster_entry',
                    nonce: intersoccer_roster_editor.nonce,
                    roster_id: rosterId,
                    field: fieldName,
                    value: newValue
                },
                success: function(response) {
                    $button.prop('disabled', false).text(originalText);
                    if (response.success) {
                        // Format display value
                        var displayValue = newValue || '—';
                        if (displayValue === '' || displayValue === null) {
                            displayValue = '—';
                        }
                        $cell.text(displayValue);
                        $('#inline-edit-overlay, #inline-edit-modal').hide();
                        showNotification('<?php echo esc_js(__('Updated successfully', 'intersoccer-reports-rosters')); ?>', 'success');
                    } else {
                        showNotification('<?php echo esc_js(__('Update failed:', 'intersoccer-reports-rosters')); ?> ' + (response.data.message || '<?php echo esc_js(__('Unknown error', 'intersoccer-reports-rosters')); ?>'), 'error');
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(originalText);
                    showNotification('<?php echo esc_js(__('Connection error', 'intersoccer-reports-rosters')); ?>', 'error');
                }
            });
        });

        // Cancel inline edit
        $('#inline-edit-cancel, #inline-edit-overlay').on('click', function() {
            $('#inline-edit-overlay, #inline-edit-modal').hide();
        });

        // Helper function to escape HTML
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Show notification
        function showNotification(message, type) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after($notice);
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
    });
    </script>
    <?php
}

/**
 * Render the detail edit form for a single roster entry
 */
function intersoccer_render_roster_edit_form() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters'));
    }

    $roster_id = isset($_GET['roster_id']) ? intval($_GET['roster_id']) : 0;
    $order_item_id = isset($_GET['order_item_id']) ? intval($_GET['order_item_id']) : 0;
    
    if (!$roster_id && !$order_item_id) {
        wp_die(__('Invalid roster ID or Order Item ID.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    // Try querying by roster_id first
    $roster = null;
    if ($roster_id) {
        $roster = $wpdb->get_row($wpdb->prepare("SELECT * FROM $rosters_table WHERE id = %d", $roster_id), ARRAY_A);
    }
    
    // If not found by id, try querying by order_item_id (which is unique and doesn't change)
    // This handles cases where REPLACE INTO operations changed the roster ID
    if (!$roster && $order_item_id) {
        $roster = $wpdb->get_row($wpdb->prepare("SELECT * FROM $rosters_table WHERE order_item_id = %d LIMIT 1", $order_item_id), ARRAY_A);
    }

    if (!$roster) {
        wp_die(__('Roster entry not found. The roster may have been updated or deleted. Please refresh the roster list and try again.', 'intersoccer-reports-rosters'));
    }

    // Enqueue scripts
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-datepicker');
    wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');

    wp_localize_script('jquery', 'intersoccer_roster_editor', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('intersoccer_roster_editor'),
    ]);

    ?>
    <div class="wrap">
        <h1><?php _e('Edit Roster Entry', 'intersoccer-reports-rosters'); ?></h1>
        
        <form id="roster-edit-form" method="post" action="">
            <?php wp_nonce_field('intersoccer_roster_editor', 'roster_edit_nonce'); ?>
            <input type="hidden" name="roster_id" value="<?php echo esc_attr($roster_id); ?>" />
            <input type="hidden" name="action" value="intersoccer_update_roster_entry" />

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- Left Column -->
                <div>
                    <h2><?php _e('Player Information', 'intersoccer-reports-rosters'); ?></h2>
                    <table class="form-table">
                        <?php intersoccer_render_form_field($roster, 'player_name', 'Player Name', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'first_name', 'First Name', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'last_name', 'Last Name', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'player_first_name', 'Player First Name', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'player_last_name', 'Player Last Name', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'player_dob', 'Player Date of Birth', 'date'); ?>
                        <?php intersoccer_render_form_field($roster, 'age', 'Age', 'number'); ?>
                        <?php intersoccer_render_form_field_select($roster, 'gender', 'Gender', ['N/A', 'Male', 'Female', 'Other']); ?>
                        <?php intersoccer_render_form_field_select($roster, 'player_gender', 'Player Gender', ['', 'Male', 'Female', 'Other']); ?>
                    </table>

                    <h2><?php _e('Parent/Contact Information', 'intersoccer-reports-rosters'); ?></h2>
                    <table class="form-table">
                        <?php intersoccer_render_form_field($roster, 'parent_first_name', 'Parent First Name', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'parent_last_name', 'Parent Last Name', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'parent_email', 'Parent Email', 'email'); ?>
                        <?php intersoccer_render_form_field($roster, 'parent_phone', 'Parent Phone', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'emergency_contact', 'Emergency Contact', 'text'); ?>
                    </table>

                    <h2><?php _e('Medical/Dietary Information', 'intersoccer-reports-rosters'); ?></h2>
                    <table class="form-table">
                        <?php intersoccer_render_form_field($roster, 'medical_conditions', 'Medical Conditions', 'textarea'); ?>
                        <?php intersoccer_render_form_field($roster, 'player_medical', 'Player Medical Info', 'textarea'); ?>
                        <?php intersoccer_render_form_field($roster, 'player_dietary', 'Dietary Needs', 'textarea'); ?>
                    </table>
                </div>

                <!-- Right Column -->
                <div>
                    <h2><?php _e('Event Details', 'intersoccer-reports-rosters'); ?></h2>
                    <table class="form-table">
                        <?php intersoccer_render_form_field($roster, 'product_name', 'Product Name', 'text'); ?>
                        <?php intersoccer_render_form_field_select($roster, 'activity_type', 'Activity Type', ['', 'Camp', 'Course', 'Tournament', 'Other']); ?>
                        <?php intersoccer_render_form_field($roster, 'venue', 'Venue', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'booking_type', 'Booking Type', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'selected_days', 'Selected Days', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'days_selected', 'Days Selected', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'course_day', 'Course Day', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'camp_terms', 'Camp Terms', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'age_group', 'Age Group', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'season', 'Season', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'canton_region', 'Canton/Region', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'city', 'City', 'text'); ?>
                        <?php intersoccer_render_form_field_checkbox($roster, 'girls_only', 'Girls Only'); ?>
                    </table>

                    <h2><?php _e('Dates', 'intersoccer-reports-rosters'); ?></h2>
                    <table class="form-table">
                        <?php intersoccer_render_form_field($roster, 'start_date', 'Start Date', 'date'); ?>
                        <?php intersoccer_render_form_field($roster, 'end_date', 'End Date', 'date'); ?>
                        <?php intersoccer_render_form_field($roster, 'event_dates', 'Event Dates', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'registration_timestamp', 'Registration Timestamp', 'datetime-local'); ?>
                    </table>

                    <h2><?php _e('Pricing', 'intersoccer-reports-rosters'); ?></h2>
                    <table class="form-table">
                        <?php intersoccer_render_form_field($roster, 'base_price', 'Base Price', 'number', ['step' => '0.01']); ?>
                        <?php intersoccer_render_form_field($roster, 'discount_amount', 'Discount Amount', 'number', ['step' => '0.01']); ?>
                        <?php intersoccer_render_form_field($roster, 'final_price', 'Final Price', 'number', ['step' => '0.01']); ?>
                        <?php intersoccer_render_form_field($roster, 'reimbursement', 'Reimbursement', 'number', ['step' => '0.01']); ?>
                        <?php intersoccer_render_form_field($roster, 'discount_codes', 'Discount Codes', 'text'); ?>
                    </table>

                    <h2><?php _e('Other Information', 'intersoccer-reports-rosters'); ?></h2>
                    <table class="form-table">
                        <?php intersoccer_render_form_field($roster, 'shirt_size', 'Shirt Size', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'shorts_size', 'Shorts Size', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'late_pickup', 'Late Pickup', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'late_pickup_days', 'Late Pickup Days', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'day_presence', 'Day Presence (JSON)', 'textarea'); ?>
                        <?php intersoccer_render_form_field($roster, 'avs_number', 'AVS Number', 'text'); ?>
                        <?php intersoccer_render_form_field($roster, 'event_signature', 'Event Signature', 'text', ['readonly' => 'readonly']); ?>
                    </table>
                </div>
            </div>

            <!-- Read-only fields (for information) -->
            <div class="intersoccer-info-box" style="margin-top: var(--intersoccer-space-xl, 20px);">
                <h3><?php _e('System Information (Read-only)', 'intersoccer-reports-rosters'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><?php _e('ID', 'intersoccer-reports-rosters'); ?></th>
                        <td><?php echo esc_html($roster['id']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Order ID', 'intersoccer-reports-rosters'); ?></th>
                        <td><?php echo esc_html($roster['order_id']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Order Item ID', 'intersoccer-reports-rosters'); ?></th>
                        <td><?php echo esc_html($roster['order_item_id']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Created At', 'intersoccer-reports-rosters'); ?></th>
                        <td><?php echo esc_html($roster['created_at']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Updated At', 'intersoccer-reports-rosters'); ?></th>
                        <td><?php echo esc_html($roster['updated_at']); ?></td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="button" class="button button-primary" id="save-roster"><?php _e('Save Changes', 'intersoccer-reports-rosters'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-advanced&tab=edit-rosters')); ?>" class="button"><?php _e('Cancel', 'intersoccer-reports-rosters'); ?></a>
            </p>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Initialize datepickers
        $('input[type="date"], input.datepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
        });

        // Handle datetime-local field conversion
        $('input[type="datetime-local"]').each(function() {
            var $field = $(this);
            var value = $field.val();
            if (value && value.includes(' ')) {
                // Convert MySQL datetime to datetime-local format
                $field.val(value.replace(' ', 'T'));
            }
        });

        // Save form
        $('#save-roster').on('click', function() {
            var $button = $(this);
            var originalText = $button.text();
            $button.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'intersoccer-reports-rosters')); ?>');
            try {
                var ajaxNonce = (typeof intersoccer_roster_editor !== 'undefined' && intersoccer_roster_editor && intersoccer_roster_editor.nonce)
                    ? intersoccer_roster_editor.nonce
                    : ($('input[name="roster_edit_nonce"]').val() || '');
                var ajaxUrl = (typeof intersoccer_roster_editor !== 'undefined' && intersoccer_roster_editor && intersoccer_roster_editor.ajaxurl)
                    ? intersoccer_roster_editor.ajaxurl
                    : (window.ajaxurl || '');

                // Collect all form data
                var formData = {
                    action: 'intersoccer_update_roster_entry',
                    nonce: ajaxNonce,
                    roster_id: $('input[name="roster_id"]').val()
                };

                // Get all form fields
                $('#roster-edit-form input, #roster-edit-form select, #roster-edit-form textarea').each(function() {
                    var $field = $(this);
                    var name = $field.attr('name');
                    if (name && name !== 'roster_id' && name !== 'action' && name !== 'roster_edit_nonce') {
                        var value = $field.val();
                        if ($field.attr('type') === 'checkbox') {
                            value = $field.is(':checked') ? 1 : 0;
                        }
                        formData[name] = value;
                    }
                });

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        $button.prop('disabled', false).text(originalText);
                        if (response.success) {
                            alert('<?php echo esc_js(__('Roster entry updated successfully!', 'intersoccer-reports-rosters')); ?>');
                            window.location.href = '<?php echo esc_js(admin_url('admin.php?page=intersoccer-advanced&tab=edit-rosters')); ?>';
                        } else {
                            alert('<?php echo esc_js(__('Update failed:', 'intersoccer-reports-rosters')); ?> ' + (response.data.message || '<?php echo esc_js(__('Unknown error', 'intersoccer-reports-rosters')); ?>'));
                        }
                    },
                    error: function() {
                        $button.prop('disabled', false).text(originalText);
                        alert('<?php echo esc_js(__('Connection error. Please try again.', 'intersoccer-reports-rosters')); ?>');
                    }
                });
            } catch (err) {
                $button.prop('disabled', false).text(originalText);
                alert('<?php echo esc_js(__('Unexpected error while preparing save request.', 'intersoccer-reports-rosters')); ?>');
            }
        });
    });
    </script>
    <?php
}

/**
 * Helper function to render a form field
 */
function intersoccer_render_form_field($roster, $field_name, $label, $type = 'text', $atts = []) {
    $value = isset($roster[$field_name]) ? esc_attr($roster[$field_name]) : '';
    $input_attrs = '';
    
    foreach ($atts as $key => $val) {
        $input_attrs .= ' ' . esc_attr($key) . '="' . esc_attr($val) . '"';
    }

    echo '<tr>';
    echo '<th><label for="' . esc_attr($field_name) . '">' . esc_html($label) . '</label></th>';
    echo '<td>';
    
    if ($type === 'textarea') {
        echo '<textarea name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" class="regular-text" rows="3"' . $input_attrs . '>' . esc_textarea($value) . '</textarea>';
    } elseif ($type === 'datetime-local') {
        // Convert MySQL datetime to datetime-local format
        if ($value && strpos($value, ' ') !== false) {
            $value = str_replace(' ', 'T', $value);
        }
        echo '<input type="datetime-local" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="' . $value . '" class="regular-text"' . $input_attrs . ' />';
    } else {
        $input_type = ($type === 'date') ? 'text' : $type;
        $date_class = ($type === 'date') ? ' datepicker' : '';
        echo '<input type="' . esc_attr($input_type) . '" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="' . $value . '" class="regular-text' . $date_class . '"' . $input_attrs . ' />';
    }
    
    echo '</td>';
    echo '</tr>';
}

/**
 * Helper function to render a select field
 */
function intersoccer_render_form_field_select($roster, $field_name, $label, $options) {
    $raw_value = isset($roster[$field_name]) ? (string) $roster[$field_name] : '';
    $value = esc_attr($raw_value);
    $normalized_value = strtolower(trim($raw_value));
    
    echo '<tr>';
    echo '<th><label for="' . esc_attr($field_name) . '">' . esc_html($label) . '</label></th>';
    echo '<td>';
    echo '<select name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" class="regular-text">';
    foreach ($options as $option) {
        $normalized_option = strtolower(trim((string) $option));
        $is_selected = ($value === $option)
            || ($normalized_value !== '' && $normalized_value === $normalized_option);
        $selected = $is_selected ? ' selected' : '';
        echo '<option value="' . esc_attr($option) . '"' . $selected . '>' . esc_html($option) . '</option>';
    }
    echo '</select>';
    echo '</td>';
    echo '</tr>';

}

/**
 * Helper function to render a checkbox field
 */
function intersoccer_render_form_field_checkbox($roster, $field_name, $label) {
    $value = isset($roster[$field_name]) ? intval($roster[$field_name]) : 0;
    
    echo '<tr>';
    echo '<th><label for="' . esc_attr($field_name) . '">' . esc_html($label) . '</label></th>';
    echo '<td>';
    echo '<input type="checkbox" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="1"' . checked(1, $value, false) . ' />';
    echo '</td>';
    echo '</tr>';
}

