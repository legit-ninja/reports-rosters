<?php
/**
 * Updated Rosters pages with improved formatting and user experience
 * Replace the existing functions in rosters.php with these updated versions
 */

/**
 * Add improved inline CSS for better layout
 */
add_action('admin_head', function () {
    echo '<style>
        /* Enhanced styling for roster pages */
        .intersoccer-rosters-page {
            max-width: 100%;
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
            color: #0073aa;
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
 * Updated Camps page with improved formatting
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
        // Safe date parsing - check if function exists first
        if (function_exists('intersoccer_parse_camp_date')) {
            $camp_date = intersoccer_parse_camp_date($group['camp_terms']);
        } else {
            // Simple fallback date parsing
            $camp_date = null;
            if ($group['camp_terms'] && $group['camp_terms'] !== 'N/A') {
                if (preg_match('/(\w+)\s+(\d{1,2})-(\d{1,2})/', $group['camp_terms'], $matches)) {
                    $month = $matches[1];
                    $start_day = $matches[2];
                    $current_year = date('Y');
                    $camp_date = date('Y-m-d', strtotime("$month $start_day, $current_year"));
                }
            }
        }
        $group['parsed_date'] = $camp_date;
        
        // Extract season from camp_terms - safe extraction
        if (function_exists('intersoccer_extract_season_from_camp_terms')) {
            $group['extracted_season'] = intersoccer_extract_season_from_camp_terms($group['camp_terms']);
        } else {
            // Simple fallback season extraction
            $season = null;
            if ($group['camp_terms']) {
                $camp_terms_lower = strtolower($group['camp_terms']);
                if (strpos($camp_terms_lower, 'spring') !== false) $season = 'Spring';
                elseif (strpos($camp_terms_lower, 'summer') !== false) $season = 'Summer';
                elseif (strpos($camp_terms_lower, 'autumn') !== false || strpos($camp_terms_lower, 'fall') !== false) $season = 'Autumn';
                elseif (strpos($camp_terms_lower, 'winter') !== false) $season = 'Winter';
            }
            $group['extracted_season'] = $season;
        }
        
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

        <!-- Enhanced Filters -->
        <div class="roster-filters">
            <form method="get" action="" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-camps">
                
                <div class="filter-group">
                    <label><?php _e('Season', 'intersoccer-reports-rosters'); ?></label>
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
                    <label><?php _e('Camp Week', 'intersoccer-reports-rosters'); ?></label>
                    <select name="camp_term" onchange="this.form.submit()">
                        <option value=""><?php _e('All Weeks', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_camp_terms as $term): ?>
                            <option value="<?php echo esc_attr($term); ?>" <?php selected($selected_camp_term, $term); ?>>
                                <?php 
                                // Safe camp term display
                                if (function_exists('intersoccer_format_camp_term')) {
                                    echo esc_html(intersoccer_format_camp_term($term));
                                } else {
                                    // Simple fallback formatting
                                    $display_term = $term;
                                    if ($display_term && $display_term !== 'N/A') {
                                        $display_term = str_replace(['-', '_'], ' ', $display_term);
                                        $display_term = ucwords($display_term);
                                    }
                                    echo esc_html($display_term);
                                }
                                ?>
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
                                <?php 
                                // Safe camp term formatting
                                $formatted_term = $term_data['term'];
                                if (function_exists('intersoccer_format_camp_term')) {
                                    $formatted_term = intersoccer_format_camp_term($term_data['term']);
                                } else {
                                    // Simple fallback formatting
                                    if ($formatted_term && $formatted_term !== 'N/A') {
                                        $formatted_term = str_replace(['-', '_'], ' ', $formatted_term);
                                        $formatted_term = ucwords($formatted_term);
                                    } else {
                                        $formatted_term = 'Unscheduled Camp';
                                    }
                                }
                                echo esc_html($formatted_term);
                                ?>
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
                                            📍 <?php 
                                            // Safe term name display
                                            if (function_exists('intersoccer_get_term_name')) {
                                                echo esc_html(intersoccer_get_term_name($camp['venue'], 'pa_intersoccer-venues'));
                                            } else {
                                                echo esc_html($camp['venue'] ?: 'Unknown Venue');
                                            }
                                            ?>
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
                                            <span class="detail-label">👶 Age Group</span>
                                            <span class="detail-value"><?php 
                                            // Safe age group display
                                            if (function_exists('intersoccer_get_term_name')) {
                                                echo esc_html(intersoccer_get_term_name($camp['age_group'], 'pa_age-group'));
                                            } else {
                                                echo esc_html($camp['age_group'] ?: 'Unknown Age');
                                            }
                                            ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">⏰ Times</span>
                                            <span class="detail-value"><?php echo esc_html($camp['times'] ?: 'TBD'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">📋 Booking</span>
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
    <?php
}

/**
 * Updated Courses page with improved formatting
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
                    <label><?php _e('Show', 'intersoccer-reports-rosters'); ?></label>
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
                    <label><?php _e('Season', 'intersoccer-reports-rosters'); ?></label>
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
                    <label><?php _e('Course Day', 'intersoccer-reports-rosters'); ?></label>
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

        <!-- Course Display -->
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
                                <h3><?php echo esc_html($day); ?> - <?php echo array_sum(array_column($courses, 'total_players')); ?> players</h3>
                                <div class="courses-grid">
                                    <?php foreach ($courses as $course): ?>
                                        <div class="course-card">
                                            <div class="course-header">
                                                <h3 class="course-venue">
                                                    📍 <?php 
                                                    // Safe term name display
                                                    if (function_exists('intersoccer_get_term_name')) {
                                                        echo esc_html(intersoccer_get_term_name($course['venue'], 'pa_intersoccer-venues'));
                                                    } else {
                                                        echo esc_html($course['venue'] ?: 'Unknown Venue');
                                                    }
                                                    ?>
                                                </h3>
                                            </div>
                                            
                                            <div class="course-details">
                                                <div class="detail-row">
                                                    <span class="detail-label">👶 Age Group</span>
                                                    <span class="detail-value"><?php 
                                                    // Safe age group display
                                                    if (function_exists('intersoccer_get_term_name')) {
                                                        echo esc_html(intersoccer_get_term_name($course['age_group'], 'pa_age-group'));
                                                    } else {
                                                        echo esc_html($course['age_group'] ?: 'Unknown Age');
                                                    }
                                                    ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">⏰ Times</span>
                                                    <span class="detail-value"><?php echo esc_html($course['times'] ?: 'TBD'); ?></span>
                                                </div>
                                                <div class="detail-row">
                                                    <span class="detail-label">📅 Duration</span>
                                                    <span class="detail-value">
                                                        <?php 
                                                        if ($course['corrected_start_date'] && $course['corrected_end_date']) {
                                                            echo esc_html(date('M j', strtotime($course['corrected_start_date'])) . ' - ' . date('M j, Y', strtotime($course['corrected_end_date'])));
                                                        } else {
                                                            echo 'TBD';
                                                        }
                                                        ?>
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
    <?php
}

/**
 * Updated All Rosters page with improved formatting
 */
function intersoccer_render_all_rosters_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }
    
    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    $product_names = $wpdb->get_col("SELECT DISTINCT product_name FROM $rosters_table WHERE product_name IS NOT NULL ORDER BY product_name");

    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('All Rosters', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-all-rosters&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    🔄 <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
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
                    <input type="submit" name="export_all" class="button button-primary" value="<?php _e('📥 Export All Rosters', 'intersoccer-reports-rosters'); ?>">
                </form>
            </div>
            
            <div class="sports-rosters">
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
                    
                    if (!empty($groups)) {
                        echo '<div class="roster-season">';
                        echo '<div class="season-header">';
                        echo '<h2 class="season-title">' . esc_html($product_name) . '</h2>';
                        echo '<div class="season-stats">';
                        echo '<span class="stat-item">👥 ' . array_sum(array_column($groups, 'total_players')) . ' ' . __('players', 'intersoccer-reports-rosters') . '</span>';
                        echo '<span class="stat-item">📚 ' . count($groups) . ' ' . __('variations', 'intersoccer-reports-rosters') . '</span>';
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
                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . urlencode($group['variation_id']) . '&age_group=' . urlencode($group['age_group']));
                            echo '<tr>';
                            echo '<td style="padding: 15px;">' . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($group['product_name'], 'product') : $group['product_name']) . '</td>';
                            echo '<td style="padding: 15px;">' . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($group['venue'] ?: 'N/A', 'pa_intersoccer-venues') : ($group['venue'] ?: 'N/A')) . '</td>';
                            echo '<td style="padding: 15px;">' . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($group['age_group'], 'pa_age-group') : $group['age_group']) . '</td>';
                            echo '<td style="padding: 15px;"><strong>' . esc_html($group['total_players']) . '</strong></td>';
                            echo '<td style="padding: 15px;"><a href="' . esc_url($view_url) . '" class="button-roster-view">👀 ' . __('View Roster', 'intersoccer-reports-rosters') . '</a></td>';
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