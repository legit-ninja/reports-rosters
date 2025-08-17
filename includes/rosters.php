<?php
/**
 * Rosters pages for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.14
 * @author Jeremy Lee
 */

defined('ABSPATH') or die('Restricted access');

// Include utility functions
require_once plugin_dir_path(__FILE__) . 'utils.php';

/**
 * Add inline CSS for side-by-side export buttons
 */
add_action('admin_head', function () {
    echo '<style>
        .export-buttons { display: flex; gap: 10px; }
        .export-buttons form { display: inline-block; }
    </style>';
});

/**
 * Render the All Rosters page.
 */
function intersoccer_render_all_rosters_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    error_log('InterSoccer: Database prefix: ' . $wpdb->prefix);

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    $product_names = $wpdb->get_col("SELECT DISTINCT product_name FROM $rosters_table WHERE product_name IS NOT NULL ORDER BY product_name");
    error_log('InterSoccer: Retrieved ' . count($product_names) . ' unique product names for All Rosters on ' . current_time('mysql'));

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('All Rosters', 'intersoccer-reports-rosters'); ?></h1>
        <?php if (empty($product_names)) : ?>
            <p><?php _e('No rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="all">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_all" class="button button-primary" value="<?php _e('Export All Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            <div class="roster-groups">
                <?php
                foreach ($product_names as $product_name) {
                    $groups = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT variation_id, product_name, venue, age_group, COUNT(DISTINCT order_item_id) as total_players
                             FROM $rosters_table
                             WHERE product_name = %s
                             GROUP BY variation_id, product_name, venue, age_group
                             ORDER BY product_name, venue, age_group",
                            $product_name
                        ),
                        ARRAY_A
                    );
                    error_log('InterSoccer: All rosters query: ' . $wpdb->last_query);
                    error_log('InterSoccer: All rosters results: ' . json_encode($groups));
                    if (!empty($groups)) {
                        echo '<div class="roster-group">';
                        echo '<h3>' . esc_html($product_name) . ' (' . array_sum(array_column($groups, 'total_players')) . ' players)</h3>';
                        echo '<table class="wp-list-table widefat fixed striped">';
                        echo '<thead><tr>';
                        echo '<th style="width:22.5%">' . __('Product Name', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:22.5%">' . __('Venue', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:12.5%">' . __('Age Group', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Total Players', 'intersoccer-reports-rosters') . '</th>';
                        echo '<th style="width:10%">' . __('Actions', 'intersoccer-reports-rosters') . '</th>';
                        echo '</tr></thead><tbody>';
                        foreach ($groups as $group) {
                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . urlencode($group['variation_id']) . '&age_group=' . urlencode($group['age_group']));
                            echo '<tr>';
                            echo '<td>' . esc_html(intersoccer_get_term_name($group['product_name'], 'product')) . '</td>';
                            echo '<td>' . esc_html(intersoccer_get_term_name($group['venue'] ?: 'N/A', 'pa_intersoccer-venues')) . '</td>';
                            echo '<td>' . esc_html(intersoccer_get_term_name($group['age_group'], 'pa_age-group')) . '</td>';
                            echo '<td>' . esc_html($group['total_players']) . '</td>';
                            echo '<td><a href="' . esc_url($view_url) . '" class="button">' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        <?php endif; ?>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-all-rosters&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
    </div>
    <?php
}

/**
 * Render the Camps page.
 */
function intersoccer_render_camps_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    // Fetch camp data with season extraction from camp_terms
    $base_query = "SELECT COALESCE(camp_terms, 'N/A') as camp_terms, 
                          COALESCE(venue, 'N/A') as venue, 
                          age_group, 
                          times,
                          booking_type,
                          COUNT(DISTINCT order_item_id) as total_players,
                          GROUP_CONCAT(DISTINCT variation_id) as variation_ids,
                          GROUP_CONCAT(DISTINCT player_name) as player_names
                   FROM $rosters_table
                   WHERE activity_type = 'Camp'
                   GROUP BY camp_terms, venue, age_group, times, booking_type 
                   ORDER BY camp_terms, venue, age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    
    // Parse camps with season extraction
    $all_camps = [];
    $all_camp_terms = [];
    $all_seasons = [];
    
    foreach ($groups as $group) {
        $camp_date = intersoccer_parse_camp_date($group['camp_terms']);
        $group['parsed_date'] = $camp_date;
        
        // Extract season from camp_terms
        $group['extracted_season'] = intersoccer_extract_season_from_camp_terms($group['camp_terms']);
        
        // Collect unique camp terms and seasons for filters
        if ($group['camp_terms'] && $group['camp_terms'] !== 'N/A') {
            $all_camp_terms[$group['camp_terms']] = $group['camp_terms'];
        }
        if ($group['extracted_season']) {
            $all_seasons[$group['extracted_season']] = $group['extracted_season'];
        }
        
        $all_camps[] = $group;
    }

    // Get filter options
    $selected_camp_term = isset($_GET['camp_term']) ? sanitize_text_field($_GET['camp_term']) : '';
    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    
    $display_camps = $all_camps;
    
    // Filter by camp term if selected
    if ($selected_camp_term) {
        $display_camps = array_filter($display_camps, function($camp) use ($selected_camp_term) {
            return $camp['camp_terms'] === $selected_camp_term;
        });
    }
    
    // Filter by season if selected
    if ($selected_season) {
        $display_camps = array_filter($display_camps, function($camp) use ($selected_season) {
            return $camp['extracted_season'] === $selected_season;
        });
    }

    // Group camps by week/term for better organization
    $grouped_camps = [];
    foreach ($display_camps as $camp) {
        $term = $camp['camp_terms'];
        if (!isset($grouped_camps[$term])) {
            $grouped_camps[$term] = [
                'term' => $term,
                'parsed_date' => $camp['parsed_date'],
                'season' => $camp['extracted_season'],
                'camps' => []
            ];
        }
        $grouped_camps[$term]['camps'][] = $camp;
    }
    
    // Sort by date
    uasort($grouped_camps, function($a, $b) {
        if (!$a['parsed_date'] && !$b['parsed_date']) return 0;
        if (!$a['parsed_date']) return 1;
        if (!$b['parsed_date']) return -1;
        return strcmp($a['parsed_date'], $b['parsed_date']);
    });

    // Sort camp terms and seasons for filter dropdowns
    ksort($all_camp_terms);
    ksort($all_seasons);
    ?>
    
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('Camp Rosters', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-camps&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    🔄 <?php _e('Sync Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" style="display: inline;">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="camps">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <button type="submit" class="button button-primary">
                        📥 <?php _e('Export All Camps', 'intersoccer-reports-rosters'); ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Enhanced Filters with Camp Terms and Season -->
        <div class="roster-filters">
            <form method="get" action="" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-camps">
                
                <div class="filter-group">
                    <label><?php _e('Season:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="season" onchange="this.form.submit()">
                        <option value=""><?php _e('All Seasons', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_seasons as $season): ?>
                            <option value="<?php echo esc_attr($season); ?>" <?php selected($selected_season, $season); ?>>
                                <?php echo esc_html($season); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><?php _e('Camp Terms:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="camp_term" onchange="this.form.submit()">
                        <option value=""><?php _e('All Terms', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_camp_terms as $term): ?>
                            <option value="<?php echo esc_attr($term); ?>" <?php selected($selected_camp_term, $term); ?>>
                                <?php echo esc_html(intersoccer_format_camp_term($term)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($selected_camp_term || $selected_season): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-camps'); ?>" class="button button-secondary">
                        🔄 <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Sports Roster Layout -->
        <div class="sports-rosters">
            <?php if (empty($grouped_camps)): ?>
                <div class="no-rosters">
                    <div class="no-rosters-icon">⚽</div>
                    <h3><?php _e('No camps found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('Try adjusting your filters or sync rosters to see available camps.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped_camps as $term_data): ?>
                    <div class="roster-week">
                        <div class="week-header">
                            <h2 class="week-title">
                                <?php echo esc_html(intersoccer_format_camp_term($term_data['term'])); ?>
                                <?php if ($term_data['parsed_date']): ?>
                                    <span class="week-date"><?php echo esc_html(date('M j, Y', strtotime($term_data['parsed_date']))); ?></span>
                                <?php endif; ?>
                            </h2>
                            <div class="week-stats">
                                <?php 
                                $week_total = array_sum(array_column($term_data['camps'], 'total_players'));
                                $camp_count = count($term_data['camps']);
                                ?>
                                <span class="stat-item">
                                    👥 <?php echo $week_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    🏕️ <?php echo $camp_count; ?> <?php _e('sessions', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <?php if ($term_data['season']): ?>
                                <span class="stat-item">
                                    🌍 <?php echo esc_html($term_data['season']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="camps-grid">
                            <?php foreach ($term_data['camps'] as $camp): ?>
                                <div class="camp-card">
                                    <div class="camp-header">
                                        <h3 class="camp-venue">
                                            📍 <?php echo esc_html(intersoccer_get_term_name($camp['venue'], 'pa_intersoccer-venues')); ?>
                                        </h3>
                                        <div class="camp-type">
                                            <?php 
                                            $is_half_day = stripos($camp['age_group'], 'half') !== false || stripos($camp['age_group'], '3-5') !== false;
                                            echo $is_half_day ? '🌅 Half Day' : '☀️ Full Day';
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="camp-details">
                                        <div class="detail-row">
                                            <span class="detail-label">👶 Age:</span>
                                            <span class="detail-value"><?php echo esc_html(intersoccer_get_term_name($camp['age_group'], 'pa_age-group')); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">⏰ Times:</span>
                                            <span class="detail-value"><?php echo esc_html($camp['times'] ?: 'TBD'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">📋 Type:</span>
                                            <span class="detail-value"><?php echo esc_html($camp['booking_type'] ?: 'Full Week'); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="camp-footer">
                                        <div class="player-count">
                                            <span class="count-number"><?php echo esc_html($camp['total_players']); ?></span>
                                            <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                        </div>
                                        <div class="camp-actions">
                                            <?php 
                                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&camp_terms=' . urlencode($camp['camp_terms']) . '&venue=' . urlencode($camp['venue']) . '&age_group=' . urlencode($camp['age_group']) . '&times=' . urlencode($camp['times']));
                                            ?>
                                            <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                👀 <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
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

    <style>
    /* Existing styles remain, add adjustments for dropdowns */
    .filter-group select {
        min-width: 250px; /* Increased width */
        max-width: 400px; /* Increased to prevent overflow */
        width: auto;
        box-sizing: border-box; /* Ensure padding doesn't cause overflow */
    }
    
    .filter-group {
        flex: 0 1 auto; /* Allow flexibility without growing too much */
        max-width: 400px; /* Prevent individual groups from overflowing */
        margin-right: 10px;
    }
    
    .roster-filters .filter-form {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    </style>
    <?php
}

/**
 * Extract camp date
**/
function intersoccer_parse_camp_date_enhanced($camp_terms, $start_date_fallback = null) {
    if (empty($camp_terms) || $camp_terms === 'N/A') {
        // Fallback to start_date if available
        if ($start_date_fallback && $start_date_fallback !== '0000-00-00' && $start_date_fallback !== '1970-01-01') {
            return $start_date_fallback;
        }
        return null;
    }
    
    $current_year = date('Y');
    
    // Handle various camp term formats
    $patterns = [
        // "Summer Week 9: August 18-22 (5 days)"
        '/(\w+)\s+Week\s+\d+:\s*(\w+)\s+(\d{1,2})-(\d{1,2})(?:\s*\(\d+\s+days?\))?/i',
        // "Autumn Week 3: October 13-17"
        '/(\w+)\s+Week\s+\d+:\s*(\w+)\s+(\d{1,2})-(\d{1,2})/i',
        // "Week 9: August 18-22"
        '/Week\s+\d+:\s*(\w+)\s+(\d{1,2})-(\d{1,2})/i',
        // "August 18-22" 
        '/(\w+)\s+(\d{1,2})-(\d{1,2})/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $camp_terms, $matches)) {
            if (count($matches) >= 4) {
                $season = isset($matches[1]) && !is_numeric($matches[1]) ? $matches[1] : null;
                $month = is_numeric($matches[1]) ? $matches[2] : $matches[count($matches) - 2];
                $start_day = $matches[count($matches) - 1];
                
                // Adjust year based on season and month
                $year = $current_year;
                if ($season) {
                    $year = intersoccer_adjust_year_for_season($season, $month, $current_year);
                }
                
                $date = date('Y-m-d', strtotime("$month $start_day, $year"));
                if ($date && $date !== '1970-01-01') {
                    return $date;
                }
            }
        }
    }
    
    // Final fallback to start_date
    if ($start_date_fallback && $start_date_fallback !== '0000-00-00' && $start_date_fallback !== '1970-01-01') {
        return $start_date_fallback;
    }
    
    return null;
}

/**
 * Extract season from camp terms
 */
function intersoccer_extract_season_from_camp_terms($camp_terms) {
    if (empty($camp_terms) || $camp_terms === 'N/A') {
        return null;
    }
    
    $camp_terms_lower = strtolower($camp_terms);
    
    // Look for season keywords in camp terms
    $seasons = [
        'spring' => 'Spring',
        'summer' => 'Summer', 
        'autumn' => 'Autumn',
        'fall' => 'Autumn',
        'winter' => 'Winter'
    ];
    
    foreach ($seasons as $keyword => $season) {
        if (strpos($camp_terms_lower, $keyword) !== false) {
            return $season;
        }
    }
    
    return null;
}

/**
 * Helper function to adjust year based on season
 */
function intersoccer_adjust_year_for_season($season, $month, $current_year) {
    $season_lower = strtolower($season);
    $month_lower = strtolower($month);
    $current_month = date('n');
    
    // Season-based year adjustment
    if (in_array($season_lower, ['autumn', 'fall', 'winter'])) {
        // For autumn/winter seasons that might span into next year
        if (in_array($month_lower, ['january', 'february', 'march'])) {
            return $current_year + 1;
        }
        // If we're currently in early year and looking at autumn/winter of same year
        if ($current_month <= 6 && in_array($month_lower, ['september', 'october', 'november', 'december'])) {
            return $current_year - 1;
        }
    }
    
    return $current_year;
}

/**
 * HELPER: Parse camp date from camp terms
 */
function intersoccer_parse_camp_date($camp_terms) {
    if (empty($camp_terms) || $camp_terms === 'N/A') {
        return null;
    }
    
    $current_year = date('Y');
    
    // Handle formats like "Summer Week 9: August 18-22"
    if (preg_match('/(\w+)\s+Week\s+\d+:\s*(\w+)\s+(\d{1,2})-(\d{1,2})/', $camp_terms, $matches)) {
        $month = $matches[2];
        $start_day = $matches[3];
        
        $date = date('Y-m-d', strtotime("$month $start_day, $current_year"));
        return $date;
    }
    
    // Handle formats like "Autumn Week 3: October 13-17"
    if (preg_match('/(\w+)\s+Week\s+\d+:\s*(\w+)\s+(\d{1,2})-(\d{1,2})/', $camp_terms, $matches)) {
        $season = $matches[1];
        $month = $matches[2];
        $start_day = $matches[3];
        
        // Adjust year for seasons that might span year boundary
        $year = $current_year;
        if (in_array(strtolower($season), ['autumn', 'winter']) && in_array(strtolower($month), ['december', 'january', 'february'])) {
            if (strtolower($month) === 'january' || strtolower($month) === 'february') {
                $year = $current_year + 1;
            }
        }
        
        $date = date('Y-m-d', strtotime("$month $start_day, $year"));
        return $date;
    }
    
    return null;
}

/**
 * HELPER: Format camp term for display
 */
function intersoccer_format_camp_term($term) {
    if (empty($term) || $term === 'N/A') {
        return 'Unscheduled Camp';
    }
    
    // Convert slug-like terms to readable format
    $formatted = str_replace(['-', '_'], ' ', $term);
    $formatted = ucwords($formatted);
    
    // Handle specific patterns and add emojis for seasons
    $formatted = preg_replace_callback('/(\w+)\s+Week\s+(\d+)/i', function($matches) {
        $season = $matches[1];
        $week = $matches[2];
        $emoji = intersoccer_get_season_emoji($season);
        return "$emoji $season Week $week";
    }, $formatted);
    
    return $formatted;
}

/**
 * Get season emoji
 */
function intersoccer_get_season_emoji($season) {
    $season_lower = strtolower($season);
    $emojis = [
        'spring' => '🌸',
        'summer' => '☀️',
        'autumn' => '🍂',
        'fall' => '🍂',
        'winter' => '❄️'
    ];
    
    return $emojis[$season_lower] ?? '📅';
}

/**
 * Render the Courses page.
 */
function intersoccer_render_courses_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    $posts_table = $wpdb->prefix . 'posts';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    // Fetch course data with proper start/end dates from variation attributes and program-season
    $base_query = "
        SELECT 
            COALESCE(r.venue, 'N/A') as venue,
            r.age_group,
            r.times,
            r.course_day,
            COUNT(DISTINCT r.order_item_id) as total_players,
            GROUP_CONCAT(DISTINCT r.variation_id) as variation_ids,
            MIN(CASE 
                WHEN r.start_date = '1970-01-01' OR r.start_date = '0000-00-00' 
                THEN COALESCE(
                    (SELECT meta_value FROM {$postmeta_table} WHERE post_id = r.variation_id AND meta_key = 'attribute_pa_start-date'),
                    r.start_date
                )
                ELSE r.start_date 
            END) as corrected_start_date,
            MIN(CASE 
                WHEN r.end_date = '1970-01-01' OR r.end_date = '0000-00-00' 
                THEN COALESCE(
                    (SELECT meta_value FROM {$postmeta_table} WHERE post_id = r.variation_id AND meta_key = 'attribute_pa_end-date'),
                    r.end_date
                )
                ELSE r.end_date 
            END) as corrected_end_date,
            (SELECT meta_value FROM {$postmeta_table} pm 
             WHERE pm.post_id = r.variation_id 
             AND pm.meta_key = 'attribute_pa_program-season' 
             LIMIT 1) as program_season
        FROM {$rosters_table} r
        JOIN {$posts_table} p ON r.variation_id = p.ID
        WHERE r.activity_type = 'Course'
        GROUP BY r.venue, r.age_group, r.times, r.course_day, p.post_parent
        ORDER BY program_season, corrected_start_date, venue, age_group
    ";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    error_log('InterSoccer: Courses query: ' . $wpdb->last_query);
    
    // Process groups and collect filters
    $active_courses = [];
    $past_courses = [];
    $all_seasons = [];
    $all_course_days = [];
    
    $current_date = current_time('Y-m-d');
    
    foreach ($groups as &$group) {
        // Fix potential date formatting issues
        if (substr($group['corrected_start_date'], 0, 2) === '00') {
            $group['corrected_start_date'] = '20' . substr($group['corrected_start_date'], 2);
        }
        if (substr($group['corrected_end_date'], 0, 2) === '00') {
            $group['corrected_end_date'] = '20' . substr($group['corrected_end_date'], 2);
        }
        
        $start_date = $group['corrected_start_date'] !== '1970-01-01' ? $group['corrected_start_date'] : null;
        $end_date = $group['corrected_end_date'] !== '1970-01-01' ? $group['corrected_end_date'] : null;
        
        $group['is_active'] = ($end_date && $end_date >= $current_date) || (!$end_date && $start_date && $start_date <= $current_date);
        
        // Collect seasons from pa_program-season
        if ($group['program_season']) {
            $all_seasons[$group['program_season']] = $group['program_season'];
        } else {
            $group['program_season'] = 'Unknown';
        }
        
        // Collect course days
        if ($group['course_day'] && $group['course_day'] !== 'N/A') {
            $all_course_days[$group['course_day']] = $group['course_day'];
        }
        
        if ($group['is_active']) {
            $active_courses[] = $group;
        } else {
            $past_courses[] = $group;
        }
    }

    // Get filter options
    $selected_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'active';
    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';
    
    $display_courses = ($selected_status === 'past') ? $past_courses : $active_courses;
    
    // Filter by season
    if ($selected_season) {
        $display_courses = array_filter($display_courses, function($course) use ($selected_season) {
            return $course['program_season'] === $selected_season;
        });
    }
    
    // Filter by course day
    if ($selected_course_day) {
        $display_courses = array_filter($display_courses, function($course) use ($selected_course_day) {
            return $course['course_day'] === $selected_course_day;
        });
    }

    // Group courses by season and day
    $grouped_courses = [];
    foreach ($display_courses as $course) {
        $season = $course['program_season'] ?: 'Unknown';
        $day = $course['course_day'] ?: 'N/A';
        
        if (!isset($grouped_courses[$season])) {
            $grouped_courses[$season] = [];
        }
        if (!isset($grouped_courses[$season][$day])) {
            $grouped_courses[$season][$day] = [];
        }
        $grouped_courses[$season][$day][] = $course;
    }
    
    ksort($grouped_courses);
    ksort($all_seasons);
    ksort($all_course_days);
    ?>
    
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('Course Rosters', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-courses&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    🔄 <?php _e('Sync Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" style="display: inline;">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="courses">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <button type="submit" class="button button-primary">
                        📥 <?php _e('Export All Courses', 'intersoccer-reports-rosters'); ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Filters for Courses -->
        <div class="roster-filters">
            <form method="get" action="" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-courses">
                
                <div class="filter-group">
                    <label><?php _e('Show:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="active" <?php selected($selected_status, 'active'); ?>>
                            🏃 <?php _e('Active Courses', 'intersoccer-reports-rosters'); ?> (<?php echo count($active_courses); ?>)
                        </option>
                        <option value="past" <?php selected($selected_status, 'past'); ?>>
                            📅 <?php _e('Past Courses', 'intersoccer-reports-rosters'); ?> (<?php echo count($past_courses); ?>)
                        </option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><?php _e('Season:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="season" onchange="this.form.submit()">
                        <option value=""><?php _e('All Seasons', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_seasons as $season): ?>
                            <option value="<?php echo esc_attr($season); ?>" <?php selected($selected_season, $season); ?>>
                                <?php echo esc_html($season); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label><?php _e('Course Day:', 'intersoccer-reports-rosters'); ?></label>
                    <select name="course_day" onchange="this.form.submit()">
                        <option value=""><?php _e('All Days', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_course_days as $day): ?>
                            <option value="<?php echo esc_attr($day); ?>" <?php selected($selected_course_day, $day); ?>>
                                <?php echo esc_html($day); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($selected_season || $selected_course_day || $selected_status !== 'active'): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-courses'); ?>" class="button button-secondary">
                        🔄 <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Course Display (Adapt similar card layout as camps if needed) -->
        <div class="sports-rosters">
            <?php if (empty($grouped_courses)): ?>
                <div class="no-rosters">
                    <div class="no-rosters-icon">⚽</div>
                    <h3><?php _e('No courses found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('Try adjusting your filters or sync rosters to see available courses.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped_courses as $season => $days): ?>
                    <div class="roster-season">
                        <div class="season-header">
                            <h2 class="season-title">
                                <?php echo esc_html($season); ?>
                            </h2>
                            <div class="season-stats">
                                <?php 
                                $season_total = array_sum(array_map(function($day_groups) {
                                    return array_sum(array_column($day_groups, 'total_players'));
                                }, $days));
                                $course_count = array_sum(array_map('count', $days));
                                ?>
                                <span class="stat-item">
                                    👥 <?php echo $season_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    📚 <?php echo $course_count; ?> <?php _e('courses', 'intersoccer-reports-rosters'); ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php foreach ($days as $day => $courses): ?>
                            <div class="day-group">
                                <h3><?php echo esc_html($day); ?> (<?php echo array_sum(array_column($courses, 'total_players')); ?> players)</h3>
                                <div class="courses-grid">
                                    <?php foreach ($courses as $course): ?>
                                        <div class="course-card">
                                            <!-- Adapt card content similar to camp cards -->
                                            <div class="course-header">
                                                <h3 class="course-venue">
                                                    📍 <?php echo esc_html(intersoccer_get_term_name($course['venue'], 'pa_intersoccer-venues')); ?>
                                                </h3>
                                            </div>
                                            
                                            <div class="course-details">
                                                <div class="detail-row">
                                                    <span class="detail-label">👶 Age:</span>
                                                    <span class="detail-value"><?php echo esc_html(intersoccer_get_term_name($course['age_group'], 'pa_age-group')); ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">⏰ Times:</span>
                                                    <span class="detail-value"><?php echo esc_html($course['times'] ?: 'TBD'); ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">📅 Dates:</span>
                                                    <span class="detail-value">
                                                        <?php echo esc_html($course['corrected_start_date'] . ' to ' . $course['corrected_end_date']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <div class="course-footer">
                                                <div class="player-count">
                                                    <span class="count-number"><?php echo esc_html($course['total_players']); ?></span>
                                                    <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                                </div>
                                                <div class="course-actions">
                                                    <?php 
                                                    $view_url = admin_url('admin.php?page=intersoccer-roster-details&venue=' . urlencode($course['venue']) . '&age_group=' . urlencode($course['age_group']) . '&times=' . urlencode($course['times']) . '&course_day=' . urlencode($course['course_day']));
                                                    ?>
                                                    <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                        👀 <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <style>
    /* Add similar styles as camps, with adjustments if needed */
    .filter-group select {
        min-width: 250px; /* Increased width */
        max-width: 400px; /* Increased to prevent overflow */
        width: auto;
        box-sizing: border-box;
    }
    
    .filter-group {
        flex: 0 1 auto;
        max-width: 400px;
        margin-right: 10px;
    }
    
    .roster-filters .filter-form {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
    }
    </style>
    <?php
}


/**
 * Render the Girls Only page.
 */
function intersoccer_render_girls_only_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';
    error_log('InterSoccer: Database prefix: ' . $wpdb->prefix);

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    // Fetch unique parent product IDs for Girls Only rosters
    $parent_product_ids = $wpdb->get_results(
        "SELECT DISTINCT p.post_parent as parent_id, parent.post_title as product_name
         FROM $rosters_table r
         JOIN $posts_table p ON r.variation_id = p.ID
         JOIN $posts_table parent ON p.post_parent = parent.ID
         WHERE r.activity_type IN ('Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only')
         ORDER BY parent.post_title",
        ARRAY_A
    );
    error_log('InterSoccer: Retrieved ' . count($parent_product_ids) . ' unique parent product IDs for Girls Only on ' . current_time('mysql'));

    // Get the selected product ID from the request, default to empty
    $selected_product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

    // Build the query for Girls Only rosters
    $base_query = "SELECT r.variation_id, r.product_name, r.venue, r.age_group, r.times, r.camp_terms, 
                          COUNT(DISTINCT r.order_item_id) as total_players,
                          SUM(CASE WHEN r.player_name = 'Unknown Attendee' THEN 1 ELSE 0 END) as unknown_count,
                          p.post_parent as parent_id,
                          parent.post_title as parent_product_name
                   FROM $rosters_table r
                   JOIN $posts_table p ON r.variation_id = p.ID
                   JOIN $posts_table parent ON p.post_parent = parent.ID
                   WHERE r.activity_type IN ('Girls Only', 'Camp, Girls Only', 'Camp, Girls\' only')";
    if ($selected_product_id) {
        $base_query .= $wpdb->prepare(" AND p.post_parent = %d", $selected_product_id);
    }
    $base_query .= " GROUP BY r.variation_id, r.product_name, r.venue, r.age_group, r.times, r.camp_terms, p.post_parent, parent.post_title
                    ORDER BY parent.post_title, r.camp_terms, r.venue, r.age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    error_log('InterSoccer: Girls Only query: ' . $wpdb->last_query);
    error_log('InterSoccer: Girls Only results: ' . json_encode($groups));
    error_log('InterSoccer: Last SQL error: ' . $wpdb->last_error);

    // Group by parent product ID
    $grouped_by_product = [];
    foreach ($groups as $group) {
        $parent_id = $group['parent_id'];
        if (!isset($grouped_by_product[$parent_id])) {
            $grouped_by_product[$parent_id] = [
                'product_name' => $group['parent_product_name'],
                'rosters' => []
            ];
        }
        $grouped_by_product[$parent_id]['rosters'][] = $group;
    }

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('Girls Only', 'intersoccer-reports-rosters'); ?></h1>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-girls-only&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        <?php if (empty($parent_product_ids)) : ?>
            <p><?php _e('No Girls Only rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <!-- Product ID Filter -->
            <form method="get" action="" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="intersoccer-girls-only">
                <label for="product_id"><?php _e('Filter by Product:', 'intersoccer-reports-rosters'); ?></label>
                <select name="product_id" id="product_id" onchange="this.form.submit()">
                    <option value=""><?php _e('All Products', 'intersoccer-reports-rosters'); ?></option>
                    <?php foreach ($parent_product_ids as $product) : ?>
                        <option value="<?php echo esc_attr($product['parent_id']); ?>" <?php selected($selected_product_id, $product['parent_id']); ?>>
                            <?php echo esc_html($product['product_name'] . ' (ID: ' . $product['parent_id'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="girls_only_full_day">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_girls_only_full_day" class="button button-primary" value="<?php _e('Export Full-Day Girls Only Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="girls_only_half_day">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_girls_only_half_day" class="button button-primary" value="<?php _e('Export Half-Day Girls Only Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>

            <div class="roster-groups">
                <?php foreach ($grouped_by_product as $parent_id => $product_data) : ?>
                    <?php
                    $total_players = array_sum(array_column($product_data['rosters'], 'total_players'));
                    ?>
                    <div class="product-group">
                        <h2><?php echo esc_html($product_data['product_name']) . ' (ID: ' . esc_html($parent_id) . ', ' . esc_html($total_players) . ' players)'; ?></h2>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th width='50%'><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                                    <th width='10%'><?php _e('Camp Times', 'intersoccer-reports-rosters'); ?></th>
                                    <th width='10%'><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></th>
                                    <th width='10%'><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                                    <th width='10%'><?php _e('Variation IDs', 'intersoccer-reports-rosters'); ?></th>
                                    <th width='10%'><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($product_data['rosters'] as $roster) : ?>
                                    <?php
                                    $unknown_count = $roster['unknown_count'] ?? 0;
                                    $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . urlencode($roster['variation_id']) . '&age_group=' . urlencode($roster['age_group']) . '&times=' . urlencode($roster['times']));
                                    error_log('InterSoccer: Girls Only View Roster URL for variation ' . $roster['variation_id'] . ': ' . $view_url);
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html(intersoccer_get_term_name($roster['venue'], 'pa_intersoccer-venues')); ?></td>
                                        <td><?php echo esc_html($roster['times'] ?? 'N/A'); ?></td>
                                        <td><?php echo esc_html(intersoccer_get_term_name($roster['age_group'], 'pa_age-group')); ?></td>
                                        <td><?php echo esc_html($roster['total_players']); ?></td>
                                        <td><?php echo esc_html($roster['variation_id'] ?: 'N/A'); ?></td>
                                        <td><a href="<?php echo esc_url($view_url); ?>" class="button"><?php _e('View Roster', 'intersoccer-reports-rosters'); ?></a></td>
                                    </tr>
                                    <?php if ($unknown_count > 0) : ?>
                                        <tr>
                                            <td colspan="5" style="color: red;">
                                                <?php echo esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)); ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($grouped_by_product)) : ?>
                    <p><?php _e('No Girls Only rosters available.', 'intersoccer-reports-rosters'); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the Other Events page.
 */
function intersoccer_render_other_events_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';
    error_log('InterSoccer: Database prefix: ' . $wpdb->prefix);

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    // Fetch unique product names for Other Events filter
    $product_names_list = $wpdb->get_col("SELECT DISTINCT product_name FROM $rosters_table WHERE (activity_type NOT IN ('Camp', 'Course', 'Girls Only') OR activity_type IS NULL OR activity_type = 'unknown') AND product_name != '' ORDER BY product_name");
    error_log('InterSoccer: Retrieved ' . count($product_names_list) . ' unique product names for Other Events on ' . current_time('mysql'));

    // Get the selected product name from the request, default to empty
    $selected_product_name = isset($_GET['product_name']) ? sanitize_text_field($_GET['product_name']) : '';

    // Build query for other events, grouping by product_name, event_dates (or term if available), venue, age_group
    $base_query = "SELECT r.product_name, r.event_dates, r.venue, r.age_group, r.times,
                          COUNT(DISTINCT r.order_item_id) as total_players,
                          SUM(CASE WHEN r.player_name = 'Unknown Attendee' THEN 1 ELSE 0 END) as unknown_count,
                          GROUP_CONCAT(DISTINCT r.variation_id) as variation_ids,
                          p.post_parent,
                          parent.post_title as parent_product_name
                   FROM $rosters_table r
                   JOIN $posts_table p ON r.variation_id = p.ID
                   JOIN $posts_table parent ON p.post_parent = parent.ID
                   WHERE (r.activity_type NOT IN ('Camp', 'Course', 'Girls Only') OR r.activity_type IS NULL OR r.activity_type = 'unknown')";
    if ($selected_product_name) {
        $base_query .= $wpdb->prepare(" AND r.product_name = %s", $selected_product_name);
    }
    $base_query .= " GROUP BY r.product_name, r.event_dates, r.venue, r.age_group, r.times, p.post_parent, parent.post_title
                    ORDER BY r.product_name, r.event_dates, r.venue, r.age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    error_log('InterSoccer: Other Events query: ' . $wpdb->last_query);
    error_log('InterSoccer: Other Events results count: ' . count($groups));
    error_log('InterSoccer: Last SQL error: ' . $wpdb->last_error);
    if ($groups) {
        error_log('InterSoccer: Other Events data sample: ' . json_encode(array_slice($groups, 0, 5)));
    }

    // Group by parent product and event_dates (or term if event_dates 'N/A')
    $grouped_by_product = [];
    foreach ($groups as $group) {
        $parent_id = $group['post_parent'];
        $event_key = ($group['event_dates'] !== 'N/A') ? $group['event_dates'] : ($group['term'] ?: 'N/A');
        if (!isset($grouped_by_product[$parent_id])) {
            $grouped_by_product[$parent_id] = [
                'product_name' => $group['parent_product_name'],
                'events' => []
            ];
        }
        if (!isset($grouped_by_product[$parent_id]['events'][$event_key])) {
            $grouped_by_product[$parent_id]['events'][$event_key] = [];
        }
        $grouped_by_product[$parent_id]['events'][$event_key][] = $group;
    }

    $reconcile_nonce = wp_create_nonce('intersoccer_reconcile');
    ?>
    <div class="wrap">
        <h1><?php _e('Other Events', 'intersoccer-reports-rosters'); ?></h1>
        <p><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-other-events&action=reconcile'), 'intersoccer_reconcile'); ?>" class="button"><?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?></a></p>
        <?php if (empty($product_names_list)) : ?>
            <p><?php _e('No other event rosters available. Please reconcile manually.', 'intersoccer-reports-rosters'); ?></p>
        <?php else : ?>
            <!-- Product Name Filter -->
            <form method="get" action="" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="intersoccer-other-events">
                <label for="product_name"><?php _e('Filter by Product Name:', 'intersoccer-reports-rosters'); ?></label>
                <select name="product_name" id="product_name" onchange="this.form.submit()">
                    <option value=""><?php _e('All Products', 'intersoccer-reports-rosters'); ?></option>
                    <?php foreach ($product_names_list as $product_name) : ?>
                        <option value="<?php echo esc_attr($product_name); ?>" <?php selected($selected_product_name, $product_name); ?>>
                            <?php echo esc_html($product_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="export-buttons">
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" class="export-form">
                    <input type="hidden" name="action" value="intersoccer_export_all_rosters">
                    <input type="hidden" name="export_type" value="other_events">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <input type="submit" name="export_other_events" class="button button-primary" value="<?php _e('Export All Other Event Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>

            <div class="roster-groups">
                <?php foreach ($grouped_by_product as $parent_id => $product_data) : ?>
                    <?php
                    $total_players = array_sum(array_map(function($event_groups) {
                        return array_sum(array_column($event_groups, 'total_players'));
                    }, $product_data['events']));
                    ?>
                    <div class="product-group">
                        <h2><?php echo esc_html($product_data['product_name']) . ' (ID: ' . esc_html($parent_id) . ', ' . esc_html($total_players) . ' players)'; ?></h2>
                        <?php foreach ($product_data['events'] as $event_key => $event_groups) : ?>
                            <div class="event-group">
                                <h3><?php echo esc_html($event_key) . ' (' . array_sum(array_column($event_groups, 'total_players')) . ' players)'; ?></h3>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th width='60%'><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                                            <th width='10%'><?php _e('Times', 'intersoccer-reports-rosters'); ?></th>
                                            <th width='10%'><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></th>
                                            <th width='10%'><?php _e('Total Players', 'intersoccer-reports-rosters'); ?></th>
                                            <th width='10%'><?php _e('Actions', 'intersoccer-reports-rosters'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($event_groups as $group) : ?>
                                            <?php
                                            $unknown_count = $group['unknown_count'] ?? 0;
                                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&product_name=' . urlencode($group['product_name']) . '&event_dates=' . urlencode($event_key) . '&venue=' . urlencode($group['venue']) . '&age_group=' . urlencode($group['age_group']) . '&times=' . urlencode($group['times']));
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html(intersoccer_get_term_name($group['venue'], 'pa_intersoccer-venues')); ?></td>
                                                <td><?php echo esc_html($group['times'] ?? 'N/A'); ?></td>
                                                <td><?php echo esc_html(intersoccer_get_term_name($group['age_group'], 'pa_age-group')); ?></td>
                                                <td><?php echo esc_html($group['total_players']); ?></td>
                                                <td><a href="<?php echo esc_url($view_url); ?>" class="button"><?php _e('View Roster', 'intersoccer-reports-rosters'); ?></a></td>
                                            </tr>
                                            <?php if ($unknown_count > 0) : ?>
                                                <tr>
                                                    <td colspan="5" style="color: red;">
                                                        <?php echo esc_html(sprintf(_n('%d Unknown Attendee entry found. Please update player assignments in the Player Management UI.', '%d Unknown Attendee entries found. Please update player assignments in the Player Management UI.', $unknown_count, 'intersoccer-reports-rosters'), $unknown_count)); ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($grouped_by_product)) : ?>
                    <p><?php _e('No other event rosters available.', 'intersoccer-reports-rosters'); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * AJAX handler for reconcile
 */
add_action('wp_ajax_intersoccer_reconcile_rosters', 'intersoccer_reconcile_rosters_ajax');
function intersoccer_reconcile_rosters_ajax() {
    check_ajax_referer('intersoccer_reconcile', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_send_json_error(__('You do not have permission to reconcile rosters.', 'intersoccer-reports-rosters'));
    }
    $result = intersoccer_rebuild_rosters_and_reports();
    if ($result['status'] === 'success') {
        wp_send_json_success(__('Rosters reconciled successfully. Inserted ' . $result['inserted'] . ' records.', 'intersoccer-reports-rosters'));
    } else {
        wp_send_json_error(__('Roster reconciliation failed: ' . $result['message'], 'intersoccer-reports-rosters'));
    }
}
?>
<?php
/**
 * Debug validation for season filtering
 * Add these functions to validate the corrected filtering logic
 */

/**
 * Validate camp season extraction from camp_terms
 */
function intersoccer_debug_camp_season_extraction() {
    if (!WP_DEBUG || !WP_DEBUG_LOG) {
        return;
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    
    error_log('=== Camp Season Extraction Debug ===');
    
    // Get sample camp terms and test season extraction
    $camp_terms_samples = $wpdb->get_col("
        SELECT DISTINCT camp_terms 
        FROM $rosters_table 
        WHERE activity_type = 'Camp' 
            AND camp_terms IS NOT NULL 
            AND camp_terms != 'N/A'
            AND camp_terms != ''
        LIMIT 20
    ");
    
    foreach ($camp_terms_samples as $camp_term) {
        $extracted_season = intersoccer_extract_season_from_camp_terms($camp_term);
        error_log(sprintf(
            'Camp Term: "%s" | Extracted Season: "%s"',
            $camp_term,
            $extracted_season ?: 'NULL'
        ));
    }
    
    error_log('=== Camp Season Extraction Debug Completed ===');
}

/**
 * Validate course program-season attribute usage
 */
function intersoccer_debug_course_program_season() {
    if (!WP_DEBUG || !WP_DEBUG_LOG) {
        return;
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    
    error_log('=== Course Program Season Debug ===');
    
    // Check what pa_program-season values exist
    $program_seasons = $wpdb->get_results("
        SELECT DISTINCT 
            pm.meta_value as program_season,
            COUNT(DISTINCT r.id) as roster_count
        FROM $postmeta_table pm
        JOIN $posts_table p ON pm.post_id = p.ID
        JOIN $rosters_table r ON p.ID = (SELECT post_parent FROM $posts_table WHERE ID = r.variation_id)
        WHERE pm.meta_key = 'attribute_pa_program-season'
            AND r.activity_type = 'Course'
        GROUP BY pm.meta_value
        ORDER BY roster_count DESC
    ", ARRAY_A);
    
    error_log('Available Program Seasons for Courses: ' . json_encode($program_seasons, JSON_PRETTY_PRINT));
    
    // Check for camp vs course separation
    $all_program_seasons = $wpdb->get_results("
        SELECT DISTINCT 
            pm.meta_value as program_season,
            CASE 
                WHEN LOWER(pm.meta_value) LIKE '%camp%' THEN 'Camp Season'
                ELSE 'Course Season'
            END as season_type,
            COUNT(DISTINCT r.id) as roster_count
        FROM $postmeta_table pm
        JOIN $posts_table p ON pm.post_id = p.ID
        JOIN $rosters_table r ON p.ID = (SELECT post_parent FROM $posts_table WHERE ID = r.variation_id)
        WHERE pm.meta_key = 'attribute_pa_program-season'
        GROUP BY pm.meta_value
        ORDER BY season_type, roster_count DESC
    ", ARRAY_A);
    
    error_log('Camp vs Course Season Separation: ' . json_encode($all_program_seasons, JSON_PRETTY_PRINT));
    
    // Check courses without program-season
    $courses_without_season = $wpdb->get_var("
        SELECT COUNT(DISTINCT r.id)
        FROM $rosters_table r
        JOIN $posts_table p ON r.variation_id = p.ID
        LEFT JOIN $postmeta_table pm ON p.post_parent = pm.post_id 
            AND pm.meta_key = 'attribute_pa_program-season'
        WHERE r.activity_type = 'Course'
            AND pm.meta_value IS NULL
    ");
    
    error_log("Courses without program-season attribute: $courses_without_season");
    
    error_log('=== Course Program Season Debug Completed ===');
}

/**
 * Test the complete filtering logic
 */
function intersoccer_debug_filtering_logic() {
    if (!WP_DEBUG || !WP_DEBUG_LOG) {
        return;
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    
    error_log('=== Complete Filtering Logic Debug ===');
    
    // Test camp filtering
    $camp_filter_test = $wpdb->get_results("
        SELECT 
            camp_terms,
            COUNT(*) as count,
            GROUP_CONCAT(DISTINCT venue) as venues
        FROM $rosters_table
        WHERE activity_type = 'Camp'
            AND camp_terms IS NOT NULL
            AND camp_terms != 'N/A'
        GROUP BY camp_terms
        ORDER BY count DESC
        LIMIT 10
    ", ARRAY_A);
    
    error_log('Camp Filter Test Results:');
    foreach ($camp_filter_test as $camp) {
        $season = intersoccer_extract_season_from_camp_terms($camp['camp_terms']);
        error_log(sprintf(
            '  Term: "%s" | Count: %d | Season: "%s" | Venues: %s',
            $camp['camp_terms'],
            $camp['count'],
            $season ?: 'NULL',
            $camp['venues']
        ));
    }
    
    // Test course filtering
    $course_filter_test = $wpdb->get_results("
        SELECT 
            r.course_day,
            pm.meta_value as program_season,
            COUNT(*) as count,
            GROUP_CONCAT(DISTINCT r.venue) as venues
        FROM $rosters_table r
        JOIN $posts_table p ON r.variation_id = p.ID
        LEFT JOIN $postmeta_table pm ON p.post_parent = pm.post_id 
            AND pm.meta_key = 'attribute_pa_program-season'
        WHERE r.activity_type = 'Course'
        GROUP BY r.course_day, pm.meta_value
        ORDER BY count DESC
        LIMIT 10
    ", ARRAY_A);
    
    error_log('Course Filter Test Results:');
    foreach ($course_filter_test as $course) {
        $is_camp_season = stripos($course['program_season'], 'camp') !== false;
        error_log(sprintf(
            '  Day: "%s" | Season: "%s" | Count: %d | Is Camp Season: %s | Venues: %s',
            $course['course_day'] ?: 'NULL',
            $course['program_season'] ?: 'NULL',
            $course['count'],
            $is_camp_season ? 'YES' : 'NO',
            $course['venues']
        ));
    }
    
    error_log('=== Complete Filtering Logic Debug Completed ===');
}

/**
 * Check data quality issues specific to filtering
 */
function intersoccer_debug_filter_data_quality() {
    if (!WP_DEBUG || !WP_DEBUG_LOG) {
        return;
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    
    error_log('=== Filter Data Quality Debug ===');
    
    // Check camp data quality
    $camp_data_quality = $wpdb->get_results("
        SELECT 
            'Camps with NULL camp_terms' as issue,
            COUNT(*) as count
        FROM $rosters_table 
        WHERE activity_type = 'Camp' 
            AND (camp_terms IS NULL OR camp_terms = 'N/A' OR camp_terms = '')
        
        UNION ALL
        
        SELECT 
            'Camps with valid camp_terms' as issue,
            COUNT(*) as count
        FROM $rosters_table 
        WHERE activity_type = 'Camp' 
            AND camp_terms IS NOT NULL 
            AND camp_terms != 'N/A' 
            AND camp_terms != ''
    ", ARRAY_A);
    
    error_log('Camp Data Quality: ' . json_encode($camp_data_quality, JSON_PRETTY_PRINT));
    
    // Check course data quality
    $course_data_quality = $wpdb->get_results("
        SELECT 
            'Courses with program-season' as issue,
            COUNT(DISTINCT r.id) as count
        FROM $rosters_table r
        JOIN $posts_table p ON r.variation_id = p.ID
        JOIN $postmeta_table pm ON p.post_parent = pm.post_id 
            AND pm.meta_key = 'attribute_pa_program-season'
        WHERE r.activity_type = 'Course'
            AND pm.meta_value IS NOT NULL
            AND pm.meta_value != ''
        
        UNION ALL
        
        SELECT 
            'Courses without program-season' as issue,
            COUNT(DISTINCT r.id) as count
        FROM $rosters_table r
        JOIN $posts_table p ON r.variation_id = p.ID
        LEFT JOIN $postmeta_table pm ON p.post_parent = pm.post_id 
            AND pm.meta_key = 'attribute_pa_program-season'
        WHERE r.activity_type = 'Course'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
    ", ARRAY_A);
    
    error_log('Course Data Quality: ' . json_encode($course_data_quality, JSON_PRETTY_PRINT));
    
    error_log('=== Filter Data Quality Debug Completed ===');
}

/**
 * Main debug function to run all validations
 * Call this from the beginning of your render functions
 */
function intersoccer_debug_all_filtering() {
    intersoccer_debug_camp_season_extraction();
    intersoccer_debug_course_program_season();
    intersoccer_debug_filtering_logic();
    intersoccer_debug_filter_data_quality();
}

/**
 * Quick fix for missing program-season attributes
 * Run this once if courses are missing season data
 */
function intersoccer_fix_missing_program_seasons() {
    if (!current_user_can('manage_options')) {
        return false;
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $posts_table = $wpdb->prefix . 'posts';
    $postmeta_table = $wpdb->prefix . 'postmeta';
    
    error_log('=== Fixing Missing Program Seasons ===');
    
    // Find courses without program-season and try to infer from start_date or other attributes
    $courses_needing_seasons = $wpdb->get_results("
        SELECT DISTINCT p.post_parent as product_id, r.start_date, r.season as old_season
        FROM $rosters_table r
        JOIN $posts_table p ON r.variation_id = p.ID
        LEFT JOIN $postmeta_table pm ON p.post_parent = pm.post_id 
            AND pm.meta_key = 'attribute_pa_program-season'
        WHERE r.activity_type = 'Course'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        GROUP BY p.post_parent, r.start_date, r.season
    ", ARRAY_A);
    
    $fixes_applied = 0;
    
    foreach ($courses_needing_seasons as $course) {
        $inferred_season = 'Ongoing';
        
        // Try to infer season from old season data
        if ($course['old_season']) {
            $inferred_season = $course['old_season'];
        }
        // Try to infer from start_date
        elseif ($course['start_date'] && $course['start_date'] !== '0000-00-00') {
            $month = date('n', strtotime($course['start_date']));
            if (in_array($month, [3,4,5])) $inferred_season = 'Spring';
            elseif (in_array($month, [6,7,8])) $inferred_season = 'Summer';
            elseif (in_array($month, [9,10,11])) $inferred_season = 'Autumn';
            elseif (in_array($month, [12,1,2])) $inferred_season = 'Winter';
        }
        
        // Add the program-season attribute
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM $postmeta_table 
             WHERE post_id = %d AND meta_key = 'attribute_pa_program-season'",
            $course['product_id']
        ));
        
        if (!$existing) {
            $wpdb->insert(
                $postmeta_table,
                [
                    'post_id' => $course['product_id'],
                    'meta_key' => 'attribute_pa_program-season',
                    'meta_value' => $inferred_season
                ]
            );
            $fixes_applied++;
            error_log("Added program-season '$inferred_season' to product ID {$course['product_id']}");
        }
    }
    
    error_log("=== Applied $fixes_applied program-season fixes ===");
    
    return $fixes_applied;
}
?>