<?php
/**
 * Roster Editor AJAX Handlers
 * 
 * Handles AJAX requests for loading and updating roster entries
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.0
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

/**
 * AJAX handler to load roster entries with filters and pagination
 */
function intersoccer_ajax_load_roster_entries() {
    check_ajax_referer('intersoccer_roster_editor', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have sufficient permissions.', 'intersoccer-reports-rosters')]);
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    // Get filter parameters
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    $activity_type = isset($_POST['activity_type']) ? sanitize_text_field($_POST['activity_type']) : '';
    $season = isset($_POST['season']) ? sanitize_text_field($_POST['season']) : '';
    $canton_region = isset($_POST['canton_region']) ? sanitize_text_field($_POST['canton_region']) : '';
    $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
    $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
    $per_page = 50;
    $offset = ($paged - 1) * $per_page;

    // Build WHERE clause
    $where_conditions = ['1=1'];
    $where_values = [];

    if (!empty($search)) {
        $where_conditions[] = "(player_name LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR venue LIKE %s OR order_id = %s)";
        $search_like = '%' . $wpdb->esc_like($search) . '%';
        $where_values[] = $search_like;
        $where_values[] = $search_like;
        $where_values[] = $search_like;
        $where_values[] = $search_like;
        // Try to parse as order ID if it's numeric
        if (is_numeric($search)) {
            $where_values[] = intval($search);
        } else {
            $where_values[] = 0;
        }
    }

    if (!empty($activity_type)) {
        $where_conditions[] = "activity_type = %s";
        $where_values[] = $activity_type;
    }

    if (!empty($season)) {
        $where_conditions[] = "season = %s";
        $where_values[] = $season;
    }

    if (!empty($canton_region)) {
        $where_conditions[] = "canton_region = %s";
        $where_values[] = $canton_region;
    }

    if (!empty($start_date)) {
        $where_conditions[] = "start_date >= %s";
        $where_values[] = $start_date;
    }

    if (!empty($end_date)) {
        $where_conditions[] = "start_date <= %s";
        $where_values[] = $end_date;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Get total count
    if (!empty($where_values)) {
        $count_query = $wpdb->prepare("SELECT COUNT(*) FROM $rosters_table WHERE $where_clause", $where_values);
    } else {
        $count_query = "SELECT COUNT(*) FROM $rosters_table WHERE $where_clause";
    }
    $total_items = $wpdb->get_var($count_query);

    // Get entries
    $order_by = "ORDER BY id DESC";
    $limit = $wpdb->prepare("LIMIT %d OFFSET %d", $per_page, $offset);

    if (!empty($where_values)) {
        $query = $wpdb->prepare(
            "SELECT * FROM $rosters_table WHERE $where_clause $order_by $limit",
            $where_values
        );
    } else {
        $query = "SELECT * FROM $rosters_table WHERE $where_clause $order_by $limit";
    }

    $entries = $wpdb->get_results($query, ARRAY_A);

    // Render table HTML
    ob_start();
    ?>
    <?php
    $total_pages = ceil($total_items / $per_page);
    if ($total_pages > 1 || $total_items > 0): ?>
    <div class="tablenav top">
        <div class="tablenav-pages">
            <?php if ($total_pages > 1): ?>
                <span class="pagination-links">
                    <?php if ($paged > 1): ?>
                        <a class="first-page button" href="#" data-page="1" title="<?php esc_attr_e('First page', 'intersoccer-reports-rosters'); ?>">&laquo;</a>
                        <a class="prev-page button" href="#" data-page="<?php echo esc_attr($paged - 1); ?>" title="<?php esc_attr_e('Previous page', 'intersoccer-reports-rosters'); ?>">&lsaquo;</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
                    <?php endif; ?>
                    
                    <span class="paging-input">
                        <label for="current-page-selector" class="screen-reader-text"><?php _e('Current Page', 'intersoccer-reports-rosters'); ?></label>
                        <input class="current-page" id="current-page-selector" type="number" min="1" max="<?php echo esc_attr($total_pages); ?>" value="<?php echo esc_attr($paged); ?>" aria-describedby="table-paging" />
                        <span class="tablenav-paging-text">
                            <?php printf(__('of %s', 'intersoccer-reports-rosters'), '<span class="total-pages">' . number_format_i18n($total_pages) . '</span>'); ?>
                        </span>
                    </span>
                    
                    <?php if ($paged < $total_pages): ?>
                        <a class="next-page button" href="#" data-page="<?php echo esc_attr($paged + 1); ?>" title="<?php esc_attr_e('Next page', 'intersoccer-reports-rosters'); ?>">&rsaquo;</a>
                        <a class="last-page button" href="#" data-page="<?php echo esc_attr($total_pages); ?>" title="<?php esc_attr_e('Last page', 'intersoccer-reports-rosters'); ?>">&raquo;</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <span class="displaying-num"><?php echo sprintf(_n('%s item', '%s items', $total_items, 'intersoccer-reports-rosters'), number_format_i18n($total_items)); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 60px;"><?php _e('ID', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 150px;" data-field="player_name" class="editable-header"><?php _e('Player Name', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 100px;" data-field="first_name" class="editable-header"><?php _e('First Name', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 100px;" data-field="last_name" class="editable-header"><?php _e('Last Name', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 80px;" data-field="age" class="editable-header"><?php _e('Age', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 80px;" data-field="gender" class="editable-header"><?php _e('Gender', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 150px;" data-field="venue" class="editable-header"><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 100px;" data-field="activity_type" class="editable-header"><?php _e('Activity Type', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 120px;" data-field="start_date" class="editable-header"><?php _e('Start Date', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 100px;"><?php _e('Order ID', 'intersoccer-reports-rosters'); ?></th>
                <th style="width: 150px;"><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($entries)): ?>
                <tr>
                    <td colspan="11" style="text-align: center; padding: 20px;">
                        <?php _e('No roster entries found.', 'intersoccer-reports-rosters'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($entries as $entry): ?>
                    <tr data-roster-id="<?php echo esc_attr($entry['id']); ?>">
                        <td><?php echo esc_html($entry['id']); ?></td>
                        <td class="editable-cell" data-field="player_name"><?php echo esc_html($entry['player_name'] ?? '—'); ?></td>
                        <td class="editable-cell" data-field="first_name"><?php echo esc_html($entry['first_name'] ?? '—'); ?></td>
                        <td class="editable-cell" data-field="last_name"><?php echo esc_html($entry['last_name'] ?? '—'); ?></td>
                        <td class="editable-cell" data-field="age"><?php echo esc_html($entry['age'] ?? '—'); ?></td>
                        <td class="editable-cell" data-field="gender"><?php echo esc_html($entry['gender'] ?? '—'); ?></td>
                        <td class="editable-cell" data-field="venue"><?php echo esc_html($entry['venue'] ?? '—'); ?></td>
                        <td class="editable-cell" data-field="activity_type"><?php echo esc_html($entry['activity_type'] ?? '—'); ?></td>
                        <td class="editable-cell" data-field="start_date"><?php echo esc_html($entry['start_date'] && $entry['start_date'] !== '1970-01-01' ? $entry['start_date'] : '—'); ?></td>
                        <td><?php echo esc_html($entry['order_id']); ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=intersoccer-roster-edit&roster_id=' . $entry['id'])); ?>" class="button button-small">
                                <?php _e('Edit', 'intersoccer-reports-rosters'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="pagination-links">
                <?php if ($paged > 1): ?>
                    <a class="first-page button" href="#" data-page="1" title="<?php esc_attr_e('First page', 'intersoccer-reports-rosters'); ?>">&laquo;</a>
                    <a class="prev-page button" href="#" data-page="<?php echo esc_attr($paged - 1); ?>" title="<?php esc_attr_e('Previous page', 'intersoccer-reports-rosters'); ?>">&lsaquo;</a>
                <?php else: ?>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
                <?php endif; ?>
                
                <span class="paging-input">
                    <label for="current-page-selector-bottom" class="screen-reader-text"><?php _e('Current Page', 'intersoccer-reports-rosters'); ?></label>
                    <input class="current-page" id="current-page-selector-bottom" type="number" min="1" max="<?php echo esc_attr($total_pages); ?>" value="<?php echo esc_attr($paged); ?>" aria-describedby="table-paging" />
                    <span class="tablenav-paging-text">
                        <?php printf(__('of %s', 'intersoccer-reports-rosters'), '<span class="total-pages">' . number_format_i18n($total_pages) . '</span>'); ?>
                    </span>
                </span>
                
                <?php if ($paged < $total_pages): ?>
                    <a class="next-page button" href="#" data-page="<?php echo esc_attr($paged + 1); ?>" title="<?php esc_attr_e('Next page', 'intersoccer-reports-rosters'); ?>">&rsaquo;</a>
                    <a class="last-page button" href="#" data-page="<?php echo esc_attr($total_pages); ?>" title="<?php esc_attr_e('Last page', 'intersoccer-reports-rosters'); ?>">&raquo;</a>
                <?php else: ?>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
                    <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
                <?php endif; ?>
            </span>
        </div>
    </div>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();

    wp_send_json_success([
        'html' => $html,
        'total_items' => $total_items,
        'total_pages' => $total_pages,
        'current_page' => $paged,
    ]);
}

/**
 * AJAX handler to update a roster entry
 */
function intersoccer_ajax_update_roster_entry() {
    check_ajax_referer('intersoccer_roster_editor', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have sufficient permissions.', 'intersoccer-reports-rosters')]);
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $roster_id = isset($_POST['roster_id']) ? intval($_POST['roster_id']) : 0;
    
    if (!$roster_id) {
        wp_send_json_error(['message' => __('Invalid roster ID.', 'intersoccer-reports-rosters')]);
    }

    // Check if entry exists
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $rosters_table WHERE id = %d", $roster_id), ARRAY_A);
    if (!$existing) {
        wp_send_json_error(['message' => __('Roster entry not found.', 'intersoccer-reports-rosters')]);
    }

    // Determine update mode: single field update or full update
    if (isset($_POST['field']) && isset($_POST['value'])) {
        // Single field update (inline editing)
        $field = sanitize_text_field($_POST['field']);
        $value = $_POST['value'];
        
        // Validate and sanitize the field
        $sanitized_value = intersoccer_sanitize_roster_field($field, $value);
        
        if ($sanitized_value === false) {
            wp_send_json_error(['message' => __('Invalid field or value.', 'intersoccer-reports-rosters')]);
        }

        // Check if field is editable (not a protected field)
        if (in_array($field, ['id', 'order_id', 'order_item_id', 'created_at', 'updated_at'])) {
            wp_send_json_error(['message' => __('This field cannot be edited.', 'intersoccer-reports-rosters')]);
        }

        // Update single field
        $update_data = [$field => $sanitized_value];
        $where = ['id' => $roster_id];
        
        $result = $wpdb->update($rosters_table, $update_data, $where);
        
        if ($result === false) {
            wp_send_json_error(['message' => __('Update failed: ', 'intersoccer-reports-rosters') . $wpdb->last_error]);
        }
        
        wp_send_json_success(['message' => __('Field updated successfully.', 'intersoccer-reports-rosters')]);
        
    } else {
        // Full form update
        $update_data = [];
        $protected_fields = ['id', 'order_id', 'order_item_id', 'created_at', 'updated_at'];
        
        // Get all editable fields from POST data
        foreach ($_POST as $key => $value) {
            // Skip non-field keys
            if (in_array($key, ['action', 'nonce', 'roster_id', 'roster_edit_nonce'])) {
                continue;
            }
            
            // Skip protected fields
            if (in_array($key, $protected_fields)) {
                continue;
            }
            
            // Validate field name (should be a valid column name)
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $key)) {
                continue; // Skip invalid field names
            }
            
            // Sanitize the value
            $sanitized_value = intersoccer_sanitize_roster_field($key, $value);
            if ($sanitized_value !== false) {
                $update_data[$key] = $sanitized_value;
            }
        }

        if (empty($update_data)) {
            wp_send_json_error(['message' => __('No valid fields to update.', 'intersoccer-reports-rosters')]);
        }

        $where = ['id' => $roster_id];
        
        $result = $wpdb->update($rosters_table, $update_data, $where);
        
        if ($result === false) {
            wp_send_json_error(['message' => __('Update failed: ', 'intersoccer-reports-rosters') . $wpdb->last_error]);
        }

        wp_send_json_success([
            'message' => __('Roster entry updated successfully.', 'intersoccer-reports-rosters'),
            'updated_fields' => array_keys($update_data)
        ]);
    }
}

/**
 * AJAX handler to get a single roster entry
 */
function intersoccer_ajax_get_roster_entry() {
    check_ajax_referer('intersoccer_roster_editor', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have sufficient permissions.', 'intersoccer-reports-rosters')]);
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    $roster_id = isset($_POST['roster_id']) ? intval($_POST['roster_id']) : 0;
    
    if (!$roster_id) {
        wp_send_json_error(['message' => __('Invalid roster ID.', 'intersoccer-reports-rosters')]);
    }

    $roster = $wpdb->get_row($wpdb->prepare("SELECT * FROM $rosters_table WHERE id = %d", $roster_id), ARRAY_A);
    
    if (!$roster) {
        wp_send_json_error(['message' => __('Roster entry not found.', 'intersoccer-reports-rosters')]);
    }

    wp_send_json_success(['roster' => $roster]);
}

/**
 * Sanitize a roster field value based on field type
 * 
 * @param string $field_name Field name
 * @param mixed $value Field value
 * @return mixed Sanitized value or false if invalid
 */
function intersoccer_sanitize_roster_field($field_name, $value) {
    // Handle empty values
    if ($value === '' || $value === null) {
        // Return appropriate default based on field type
        if (strpos($field_name, 'date') !== false) {
            return '1970-01-01'; // Default date
        }
        if (strpos($field_name, 'price') !== false || strpos($field_name, 'amount') !== false) {
            return '0.00';
        }
        if ($field_name === 'age' || $field_name === 'is_placeholder' || $field_name === 'event_completed' || $field_name === 'girls_only') {
            return 0;
        }
        return '';
    }

    // Date fields
    if (strpos($field_name, 'date') !== false || strpos($field_name, 'dob') !== false) {
        // Validate date format (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return sanitize_text_field($value);
        }
        // Try to parse other date formats
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        return '1970-01-01'; // Default invalid date
    }

    // Datetime fields
    if (strpos($field_name, 'timestamp') !== false || strpos($field_name, 'created_at') !== false || strpos($field_name, 'updated_at') !== false) {
        // Convert datetime-local format to MySQL datetime
        if (strpos($value, 'T') !== false) {
            $value = str_replace('T', ' ', $value);
        }
        // Validate MySQL datetime format
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return sanitize_text_field($value);
        }
        return null; // Allow NULL for datetime
    }

    // Numeric fields (decimal/float)
    if (strpos($field_name, 'price') !== false || strpos($field_name, 'amount') !== false || strpos($field_name, 'reimbursement') !== false) {
        return floatval($value);
    }

    // Integer fields
    if (in_array($field_name, ['age', 'order_id', 'order_item_id', 'variation_id', 'product_id', 'is_placeholder', 'event_completed'])) {
        return intval($value);
    }

    // Boolean fields (stored as TINYINT)
    if ($field_name === 'girls_only' || $field_name === 'is_placeholder' || $field_name === 'event_completed') {
        return $value ? 1 : 0;
    }

    // Email fields
    if (strpos($field_name, 'email') !== false) {
        return sanitize_email($value);
    }

    // Text/textarea fields
    if (in_array($field_name, ['medical_conditions', 'player_medical', 'player_dietary', 'day_presence', 'selected_days', 'late_pickup_days'])) {
        return sanitize_textarea_field($value);
    }

    // Phone fields
    if (strpos($field_name, 'phone') !== false || $field_name === 'emergency_contact' || $field_name === 'avs_number') {
        return sanitize_text_field($value);
    }

    // Gender fields - validate against allowed values
    if ($field_name === 'gender' || $field_name === 'player_gender') {
        $allowed = ['N/A', 'Male', 'Female', 'Other', ''];
        $value = sanitize_text_field($value);
        return in_array($value, $allowed) ? $value : 'N/A';
    }

    // Late pickup
    if ($field_name === 'late_pickup') {
        $allowed = ['Yes', 'No'];
        $value = sanitize_text_field($value);
        return in_array($value, $allowed) ? $value : 'No';
    }

    // Default: sanitize as text
    return sanitize_text_field($value);
}

