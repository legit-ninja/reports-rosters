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
 * Updated Camps page with improved formatting, no booking_type split, and City display
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
                          city,
                          COUNT(DISTINCT order_item_id) as total_players,
                          GROUP_CONCAT(DISTINCT variation_id) as variation_ids,
                          GROUP_CONCAT(DISTINCT player_name) as player_names,
                          GROUP_CONCAT(DISTINCT start_date) as start_dates,
                          GROUP_CONCAT(DISTINCT end_date) as end_dates
                   FROM $rosters_table
                   WHERE activity_type = 'Camp'
                   GROUP BY camp_terms, venue, age_group, times, city 
                   ORDER BY camp_terms, venue, age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    error_log("InterSoccer: Camps query results: " . print_r($groups, true));
    
    // Parse camps with season extraction
    $all_camps = [];
    $all_camp_terms = [];
    $all_seasons = [];
    
    foreach ($groups as $group) {
        // Get variation for meta access
        $variation_ids = !empty($group['variation_ids']) && is_string($group['variation_ids']) ? array_filter(explode(',', $group['variation_ids'])) : [];
        if (empty($variation_ids)) {
            error_log("InterSoccer: No valid variation_ids for camp group - Raw: " . print_r($group['variation_ids'], true));
            $variation_ids = [0];
        }
        $variation_id = $variation_ids[0];
        $variation = $variation_id ? wc_get_product($variation_id) : false;
        $parent_product = $variation ? wc_get_product($variation->get_parent_id()) : false;

        // Log variation access
        error_log("InterSoccer: Camp variation $variation_id - Loaded: " . ($variation ? 'Yes' : 'No') . ", Meta start: " . ($variation ? $variation->get_meta('_course_start_date') : 'N/A'));

        // Fetch course-specific meta
        $course_start = $variation ? $variation->get_meta('_course_start_date') : ($parent_product ? $parent_product->get_meta('_course_start_date') : '1970-01-01');
        $total_weeks = $variation ? (int) $variation->get_meta('_course_total_weeks') : ($parent_product ? (int) $parent_product->get_meta('_course_total_weeks') : 0);
        $holidays = $variation ? ($variation->get_meta('_course_holiday_dates') ?: []) : ($parent_product ? ($parent_product->get_meta('_course_holiday_dates') ?: []) : []);
        $course_days = $parent_product ? (wc_get_product_terms($parent_product->get_id(), 'pa_course-day', ['fields' => 'names']) ?: wc_get_product_terms($parent_product->get_id(), 'pa_days-of-week', ['fields' => 'names']) ?: ['Monday']) : ['Monday'];

        // Calculate end date if meta is available
        $course_end = '1970-01-01';
        if ($course_start !== '1970-01-01' && $total_weeks > 0) {
            $course_end = calculate_course_end_date($variation_id, $course_start, $total_weeks, $holidays, $course_days);
            error_log("InterSoccer: Calculated camp dates for variation $variation_id - Start: $course_start, End: $course_end");
        } else {
            error_log("InterSoccer: No valid meta for variation $variation_id - Using stored dates");
            $course_start = !empty($group['start_dates']) && is_string($group['start_dates']) ? explode(',', $group['start_dates'])[0] : '1970-01-01';
            $course_end = !empty($group['end_dates']) && is_string($group['end_dates']) ? explode(',', $group['end_dates'])[0] : '1970-01-01';
        }

        // Validate and format dates
        $group['corrected_start_date'] = date('Y-m-d', strtotime($course_start)) ?: '1970-01-01';
        $group['corrected_end_date'] = date('Y-m-d', strtotime($course_end)) ?: '1970-01-01';

        // Extract season from camp terms
        $camp_term = $group['camp_terms'] ?: 'N/A';
        $term_parts = explode('-', $camp_term);
        $season = !empty($term_parts[0]) ? ucfirst(trim($term_parts[0])) . ' ' . (preg_match('/\d{4}/', $camp_term, $matches) ? $matches[0] : '') : 'N/A';
        $season = trim($season);
        $group['season'] = $season;

        // Log city value
        error_log("InterSoccer: City value for camp: " . print_r($group['city'] ?? 'N/A', true));

        // Collect unique camp terms and seasons
        if ($camp_term && $camp_term !== 'N/A') {
            $all_camp_terms[$camp_term] = $camp_term;
        }
        if ($season && $season !== 'N/A') {
            $all_seasons[$season] = $season;
        }

        $all_camps[] = $group;
    }

    // Get filter options
    $selected_camp_term = isset($_GET['camp_terms']) ? sanitize_text_field($_GET['camp_terms']) : '';
    $selected_venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $selected_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'active';

    $display_camps = $all_camps;

    // Filter by camp term
    if ($selected_camp_term) {
        $display_camps = array_filter($display_camps, function($camp) use ($selected_camp_term) {
            return $camp['camp_terms'] === $selected_camp_term;
        });
    }

    // Filter by venue
    if ($selected_venue) {
        $display_camps = array_filter($display_camps, function($camp) use ($selected_venue) {
            return $camp['venue'] === $selected_venue;
        });
    }

    // Filter by status (active = end_date >= today)
    if ($selected_status === 'active') {
        $today = current_time('Y-m-d');
        $display_camps = array_filter($display_camps, function($camp) use ($today) {
            return $camp['corrected_end_date'] >= $today;
        });
    }

    error_log("InterSoccer: Filtered camps: " . print_r($display_camps, true));

    // Group camps by season
    $grouped_camps = [];
    foreach ($display_camps as $camp) {
        $season = $camp['season'] ?: 'N/A';
        $grouped_camps[$season][] = $camp;
    }
    ksort($grouped_camps);

    // Get all venues for filter
    $all_venues = array_unique(array_column($all_camps, 'venue'));
    sort($all_venues);

    // Render the page
    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('Camps', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-camps&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    🔄 <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>

        <div class="export-buttons">
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="action" value="intersoccer_export_camps">
                <input type="hidden" name="export_type" value="camps">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                <input type="submit" name="export_camps" class="button button-primary" 
                       value="<?php _e('📥 Export Camps', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>

        <div class="roster-filters">
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-camps">
                <div class="filter-group">
                    <label><?php _e('Camp Term', 'intersoccer-reports-rosters'); ?></label>
                    <select name="camp_terms" onchange="this.form.submit()">
                        <option value=""><?php _e('All Terms', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_camp_terms as $term): ?>
                            <option value="<?php echo esc_attr($term); ?>" <?php selected($selected_camp_term, $term); ?>>
                                <?php echo esc_html($term); ?>
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
                                <?php 
                                if (function_exists('intersoccer_get_term_name')) {
                                    echo esc_html(intersoccer_get_term_name($venue, 'pa_intersoccer-venues'));
                                } else {
                                    echo esc_html($venue);
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><?php _e('Status', 'intersoccer-reports-rosters'); ?></label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all"><?php _e('All', 'intersoccer-reports-rosters'); ?></option>
                        <option value="active" <?php selected($selected_status, 'active'); ?>>
                            <?php _e('Active', 'intersoccer-reports-rosters'); ?>
                        </option>
                    </select>
                </div>
                <?php if ($selected_camp_term || $selected_venue || $selected_status !== 'active'): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-camps'); ?>" class="button button-secondary">
                        🔄 <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="sports-rosters">
            <?php if (empty($grouped_camps)): ?>
                <div class="no-rosters">
                    <div class="no-rosters-icon">⚽</div>
                    <h3><?php _e('No camps found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('Try adjusting your filters or sync rosters to see available camps.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped_camps as $season => $camps): ?>
                    <div class="roster-season">
                        <div class="season-header">
                            <h2 class="season-title">
                                <?php echo esc_html($season); ?>
                            </h2>
                            <div class="season-stats">
                                <?php 
                                $season_total = is_array($camps) ? array_sum(array_column($camps, 'total_players')) : 0;
                                $camp_count = is_array($camps) ? count($camps) : 0;
                                ?>
                                <span class="stat-item">
                                    👥 <?php echo $season_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    📚 <?php echo $camp_count; ?> <?php _e('camps', 'intersoccer-reports-rosters'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="camps-grid">
                        <?php if (is_array($camps)): ?>
                            <?php foreach ($camps as $camp): ?>
                                <div class="camp-card">
                                    <div class="camp-header">
                                        <h3 class="camp-venue">
                                            📍 <?php 
                                            if (function_exists('intersoccer_get_term_name')) {
                                                echo esc_html(intersoccer_get_term_name($camp['venue'], 'pa_intersoccer-venues'));
                                            } else {
                                                echo esc_html($camp['venue'] ?: 'Unknown Venue');
                                            }
                                            ?>
                                        </h3>
                                        <!-- Removed .camp-type span to avoid overlap -->
                                    </div>
                                    <div class="camp-details">
                                        <div class="detail-row">
                                            <span class="detail-label">👶 Age Group</span>
                                            <span class="detail-value"><?php 
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
                                            <span class="detail-label">📅 Camp Term</span>
                                            <span class="detail-value"><?php echo esc_html($camp['camp_terms'] ?: 'N/A'); ?></span>
                                        </div>
                                        <div class="detail-row">
                                            <span class="detail-label">📅 Duration</span>
                                            <span class="detail-value"><?php 
                                            if ($camp['corrected_start_date'] !== '1970-01-01' && $camp['corrected_end_date'] !== '1970-01-01') {
                                                echo esc_html(date('M j', strtotime($camp['corrected_start_date'])) . ' - ' . date('M j, Y', strtotime($camp['corrected_end_date'])));
                                            } else {
                                                echo 'TBD';
                                            }
                                            ?></span>
                                        </div>
                                    </div>
                                    <div class="camp-footer">
                                        <div class="player-count">
                                            <span class="count-number"><?php echo esc_html($camp['total_players']); ?></span>
                                            <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                        </div>
                                        <div class="camp-actions">
                                            <?php 
                                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&venue=' . urlencode($camp['venue']) . '&age_group=' . urlencode($camp['age_group']) . '&times=' . urlencode($camp['times']) . '&camp_terms=' . urlencode($camp['camp_terms']));
                                            ?>
                                            <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                👀 <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p><?php _e('No camps available for this season.', 'intersoccer-reports-rosters'); ?></p>
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
 * Updated Courses page with improved formatting
 */
function intersoccer_render_courses_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    // Fetch course data
    $base_query = "SELECT COALESCE(season, 'N/A') as season,
                          COALESCE(course_day, 'N/A') as course_day,
                          COALESCE(venue, 'N/A') as venue,
                          age_group,
                          times,
                          COUNT(DISTINCT order_item_id) as total_players,
                          GROUP_CONCAT(DISTINCT variation_id) as variation_ids,
                          GROUP_CONCAT(DISTINCT start_date) as start_dates,
                          GROUP_CONCAT(DISTINCT end_date) as end_dates,
                          GROUP_CONCAT(DISTINCT product_name) as product_names
                   FROM $rosters_table
                   WHERE activity_type = 'Course'
                   GROUP BY season, course_day, venue, age_group, times
                   ORDER BY season DESC, course_day, venue, age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);

    // Parse courses with proper date handling
    $all_courses = [];
    $all_seasons = [];
    $all_course_days = [];
    $all_venues = [];

    foreach ($groups as $group) {
        // Get variation for meta access
        $variation_ids = !empty($group['variation_ids']) && is_string($group['variation_ids']) ? array_filter(explode(',', $group['variation_ids'])) : [];
        if (empty($variation_ids)) {
            error_log("InterSoccer: No valid variation_ids for group - Raw: " . print_r($group['variation_ids'], true));
            $variation_ids = [0]; // Fallback to prevent errors
        }
        $variation_id = $variation_ids[0];
        $variation = $variation_id ? wc_get_product($variation_id) : false;
        $parent_product = $variation ? wc_get_product($variation->get_parent_id()) : false;

        // Log variation access
        error_log("InterSoccer: Course variation $variation_id - Loaded: " . ($variation ? 'Yes' : 'No') . ", Meta start: " . ($variation ? $variation->get_meta('_course_start_date') : 'N/A') . ", End: " . ($variation ? $variation->get_meta('_end_date') : 'N/A'));

        // Fetch course-specific meta
        $course_start = $variation ? $variation->get_meta('_course_start_date') : ($parent_product ? $parent_product->get_meta('_course_start_date') : '1970-01-01');
        $total_weeks = $variation ? (int) $variation->get_meta('_course_total_weeks') : ($parent_product ? (int) $parent_product->get_meta('_course_total_weeks') : 0);
        $holidays = $variation ? ($variation->get_meta('_course_holiday_dates') ?: []) : ($parent_product ? ($parent_product->get_meta('_course_holiday_dates') ?: []) : []);
        $course_days = $parent_product ? (wc_get_product_terms($parent_product->get_id(), 'pa_course-day', ['fields' => 'names']) ?: wc_get_product_terms($parent_product->get_id(), 'pa_days-of-week', ['fields' => 'names']) ?: ['Monday']) : ['Monday'];

        // Calculate end date if meta is available
        $course_end = '1970-01-01';
        if ($course_start !== '1970-01-01' && $total_weeks > 0) {
            $course_end = calculate_course_end_date($variation_id, $course_start, $total_weeks, $holidays, $course_days);
            error_log("InterSoccer: Calculated course dates for variation $variation_id - Start: $course_start, End: $course_end");
        } else {
            error_log("InterSoccer: No valid meta for variation $variation_id - Using stored dates");
            $course_start = !empty($group['start_dates']) && is_string($group['start_dates']) ? explode(',', $group['start_dates'])[0] : '1970-01-01';
            $course_end = !empty($group['end_dates']) && is_string($group['end_dates']) ? explode(',', $group['end_dates'])[0] : '1970-01-01';
        }

        // Validate and format dates
        $group['corrected_start_date'] = date('Y-m-d', strtotime($course_start)) ?: '1970-01-01';
        $group['corrected_end_date'] = date('Y-m-d', strtotime($course_end)) ?: '1970-01-01';

        // Collect unique seasons, days, and venues for filters
        if ($group['season'] && $group['season'] !== 'N/A') {
            $all_seasons[$group['season']] = $group['season'];
        }
        if ($group['course_day'] && $group['course_day'] !== 'N/A') {
            $all_course_days[$group['course_day']] = $group['course_day'];
        }
        if ($group['venue'] && $group['venue'] !== 'N/A') {
            $all_venues[$group['venue']] = $group['venue'];
        }

        $all_courses[] = $group;
    }

    // Get filter options
    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';
    $selected_venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';

    $display_courses = $all_courses;

    // Filter by season
    if ($selected_season) {
        $display_courses = array_filter($display_courses, function($course) use ($selected_season) {
            return $course['season'] === $selected_season;
        });
    }

    // Filter by course day
    if ($selected_course_day) {
        $display_courses = array_filter($display_courses, function($course) use ($selected_course_day) {
            return $course['course_day'] === $selected_course_day;
        });
    }

    // Filter by venue
    if ($selected_venue) {
        $display_courses = array_filter($display_courses, function($course) use ($selected_venue) {
            return $course['venue'] === $selected_venue;
        });
    }

    // Group courses by season and day
    $grouped_courses = [];
    foreach ($display_courses as $course) {
        $season = $course['season'] ?: 'N/A';
        $day = $course['course_day'] ?: 'N/A';
        $grouped_courses[$season][$day][] = $course;
    }
    ksort($grouped_courses);
    foreach ($grouped_courses as &$season) {
        ksort($season);
    }

    // Render the page
    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('Courses', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-courses&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    🔄 <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>

        <div class="export-buttons">
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="action" value="intersoccer_export_courses">
                <input type="hidden" name="export_type" value="courses">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                <input type="submit" name="export_courses" class="button button-primary" 
                       value="<?php _e('📥 Export Courses', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>

        <div class="roster-filters">
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-courses">
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
                
                <div class="filter-group">
                    <label><?php _e('Venue', 'intersoccer-reports-rosters'); ?></label>
                    <select name="venue" onchange="this.form.submit()">
                        <option value=""><?php _e('All Venues', 'intersoccer-reports-rosters'); ?></option>
                        <?php foreach ($all_venues as $venue): ?>
                            <option value="<?php echo esc_attr($venue); ?>" <?php selected($selected_venue, $venue); ?>>
                                <?php 
                                if (function_exists('intersoccer_get_term_name')) {
                                    echo esc_html(intersoccer_get_term_name($venue, 'pa_intersoccer-venues'));
                                } else {
                                    echo esc_html($venue);
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($selected_season || $selected_course_day || $selected_venue): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-courses'); ?>" class="button button-secondary">
                        🔄 <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

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
                                $season_total = 0;
                                $course_count = 0;
                                if (is_array($days)) {
                                    $season_total = array_sum(array_map(function($day_groups) {
                                        return is_array($day_groups) ? array_sum(array_column($day_groups, 'total_players')) : 0;
                                    }, $days));
                                    $course_count = array_sum(array_map(function($day_groups) {
                                        return is_array($day_groups) ? count($day_groups) : 0;
                                    }, $days));
                                } else {
                                    error_log("InterSoccer: Invalid days structure for season $season: " . print_r($days, true));
                                }
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
                                                    <span class="detail-value"><?php 
                                                    if ($course['corrected_start_date'] !== '1970-01-01' && $course['corrected_end_date'] !== '1970-01-01') {
                                                        echo esc_html(date('M j', strtotime($course['corrected_start_date'])) . ' - ' . date('M j, Y', strtotime($course['corrected_end_date'])));
                                                    } else {
                                                        echo 'TBD';
                                                    }
                                                    ?></span>
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
 * Render Girls Only page with improved formatting
 */
function intersoccer_render_girls_only_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    // Fetch Girls Only data
    $base_query = "SELECT COALESCE(season, 'N/A') as season,
                      COALESCE(product_name, 'N/A') as product_name,
                      age_group,
                      times,
                      COUNT(DISTINCT order_item_id) as total_players,
                      GROUP_CONCAT(DISTINCT variation_id) as variation_ids,
                      GROUP_CONCAT(DISTINCT start_date) as start_dates,
                      GROUP_CONCAT(DISTINCT end_date) as end_dates
               FROM $rosters_table
               WHERE activity_type LIKE '%Girls Only%'
               GROUP BY season, product_name, age_group, times
               ORDER BY season DESC, product_name, age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    error_log("InterSoccer: Girls Only query results: " . print_r($groups, true));

    // Parse data with proper date handling
    $all_courses = [];
    $all_seasons = [];
    $all_product_names = [];

    foreach ($groups as $group) {
        // Get variation for meta access
        $variation_ids = !empty($group['variation_ids']) && is_string($group['variation_ids']) ? array_filter(explode(',', $group['variation_ids'])) : [];
        if (empty($variation_ids)) {
            error_log("InterSoccer: No valid variation_ids for group - Raw: " . print_r($group['variation_ids'], true));
            $variation_ids = [0];
        }
        $variation_id = $variation_ids[0];
        $variation = $variation_id ? wc_get_product($variation_id) : false;
        $parent_product = $variation ? wc_get_product($variation->get_parent_id()) : false;

        // Log variation access
        error_log("InterSoccer: Girls Only variation $variation_id - Loaded: " . ($variation ? 'Yes' : 'No') . ", Meta start: " . ($variation ? $variation->get_meta('_course_start_date') : 'N/A'));

        // Fetch course-specific meta
        $course_start = $variation ? $variation->get_meta('_course_start_date') : ($parent_product ? $parent_product->get_meta('_course_start_date') : '1970-01-01');
        $total_weeks = $variation ? (int) $variation->get_meta('_course_total_weeks') : ($parent_product ? (int) $parent_product->get_meta('_course_total_weeks') : 0);
        $holidays = $variation ? ($variation->get_meta('_course_holiday_dates') ?: []) : ($parent_product ? ($parent_product->get_meta('_course_holiday_dates') ?: []) : []);
        $course_days = $parent_product ? (wc_get_product_terms($parent_product->get_id(), 'pa_course-day', ['fields' => 'names']) ?: wc_get_product_terms($parent_product->get_id(), 'pa_days-of-week', ['fields' => 'names']) ?: ['Monday']) : ['Monday'];

        // Calculate end date if meta is available
        $course_end = '1970-01-01';
        if ($course_start !== '1970-01-01' && $total_weeks > 0) {
            $course_end = calculate_course_end_date($variation_id, $course_start, $total_weeks, $holidays, $course_days);
            error_log("InterSoccer: Calculated Girls Only dates for variation $variation_id - Start: $course_start, End: $course_end");
        } else {
            error_log("InterSoccer: No valid meta for variation $variation_id - Using stored dates");
            $course_start = !empty($group['start_dates']) && is_string($group['start_dates']) ? explode(',', $group['start_dates'])[0] : '1970-01-01';
            $course_end = !empty($group['end_dates']) && is_string($group['end_dates']) ? explode(',', $group['end_dates'])[0] : '1970-01-01';
        }

        // Validate and format dates
        $group['corrected_start_date'] = date('Y-m-d', strtotime($course_start)) ?: '1970-01-01';
        $group['corrected_end_date'] = date('Y-m-d', strtotime($course_end)) ?: '1970-01-01';

        // Collect unique seasons and product names for filters
        if ($group['season'] && $group['season'] !== 'N/A') {
            $all_seasons[$group['season']] = $group['season'];
        }
        if ($group['product_name'] && $group['product_name'] !== 'N/A') {
            $all_product_names[$group['product_name']] = $group['product_name'];
        }

        $all_courses[] = $group;
    }

    // Get filter options
    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_product_name = isset($_GET['product_name']) ? sanitize_text_field($_GET['product_name']) : '';

    $display_courses = $all_courses;

    // Filter by season
    if ($selected_season) {
        $display_courses = array_filter($display_courses, function($course) use ($selected_season) {
            return $course['season'] === $selected_season;
        });
    }

    // Filter by product name
    if ($selected_product_name) {
        $display_courses = array_filter($display_courses, function($course) use ($selected_product_name) {
            return $course['product_name'] === $selected_product_name;
        });
    }

    error_log("InterSoccer: Filtered Girls Only courses: " . print_r($display_courses, true));

    // Group courses by season
    $grouped_courses = [];
    foreach ($display_courses as $course) {
        $season = $course['season'] ?: 'N/A';
        $grouped_courses[$season][] = $course;
    }
    ksort($grouped_courses);

    // Render the page
    ?>
    <div class="wrap intersoccer-rosters-page">
        <div class="roster-header">
            <h1>⚽ <?php _e('Girls Only', 'intersoccer-reports-rosters'); ?></h1>
            <div class="header-actions">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=intersoccer-girls-only&action=reconcile'), 'intersoccer_reconcile'); ?>" 
                   class="button button-secondary">
                    🔄 <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>

        <div class="export-buttons">
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="action" value="intersoccer_export_girls_only">
                <input type="hidden" name="export_type" value="girls_only">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                <input type="submit" name="export_girls_only" class="button button-primary" 
                       value="<?php _e('📥 Export Girls Only', 'intersoccer-reports-rosters'); ?>">
            </form>
        </div>

        <div class="roster-filters">
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-girls-only">
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
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-girls-only'); ?>" class="button button-secondary">
                        🔄 <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="sports-rosters">
            <?php if (empty($grouped_courses)): ?>
                <div class="no-rosters">
                    <div class="no-rosters-icon">⚽</div>
                    <h3><?php _e('No Girls Only events found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('Try adjusting your filters or sync rosters to see available events.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped_courses as $season => $courses): ?>
                    <div class="roster-season">
                        <div class="season-header">
                            <h2 class="season-title">
                                <?php echo esc_html($season); ?>
                            </h2>
                            <div class="season-stats">
                                <?php 
                                $season_total = is_array($courses) ? array_sum(array_column($courses, 'total_players')) : 0;
                                $course_count = is_array($courses) ? count($courses) : 0;
                                ?>
                                <span class="stat-item">
                                    👥 <?php echo $season_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    📚 <?php echo $course_count; ?> <?php _e('events', 'intersoccer-reports-rosters'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="courses-grid">
                            <?php if (is_array($courses)): ?>
                                <?php foreach ($courses as $course): ?>
                                    <div class="course-card">
                                        <div class="course-header">
                                            <h3 class="course-venue">
                                                📍 <?php echo esc_html($course['product_name'] ?: 'Unknown Event'); ?>
                                            </h3>
                                        </div>
                                        <div class="course-details">
                                            <div class="detail-row">
                                                <span class="detail-label">👶 Age Group</span>
                                                <span class="detail-value"><?php 
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
                                                <span class="detail-value"><?php 
                                                if ($course['corrected_start_date'] !== '1970-01-01' && $course['corrected_end_date'] !== '1970-01-01') {
                                                    echo esc_html(date('M j', strtotime($course['corrected_start_date'])) . ' - ' . date('M j, Y', strtotime($course['corrected_end_date'])));
                                                } else {
                                                    echo 'TBD';
                                                }
                                                ?></span>
                                            </div>
                                        </div>
                                        <div class="course-footer">
                                            <div class="player-count">
                                                <span class="count-number"><?php echo esc_html($course['total_players']); ?></span>
                                                <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                            </div>
                                            <div class="course-actions">
                                                <?php 
                                                $view_url = admin_url('admin.php?page=intersoccer-roster-details&product_name=' . urlencode($course['product_name']) . '&age_group=' . urlencode($course['age_group']) . '&times=' . urlencode($course['times']));
                                                ?>
                                                <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                    👀 <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
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
 * Render Other Events page with improved formatting
 */
function intersoccer_render_other_events_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    // Fetch Other Events data
    $base_query = "SELECT COALESCE(season, 'N/A') as season,
                          COALESCE(product_name, 'N/A') as product_name,
                          age_group,
                          times,
                          COUNT(DISTINCT order_item_id) as total_players,
                          GROUP_CONCAT(DISTINCT variation_id) as variation_ids,
                          GROUP_CONCAT(DISTINCT start_date) as start_dates,
                          GROUP_CONCAT(DISTINCT end_date) as end_dates
                   FROM $rosters_table
                   WHERE activity_type NOT IN ('Camp', 'Course', 'Girls Only')
                   GROUP BY season, product_name, age_group, times
                   ORDER BY season DESC, product_name, age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    error_log("InterSoccer: Other Events query results: " . print_r($groups, true));

    // Parse data with proper date handling
    $all_events = [];
    $all_seasons = [];
    $all_product_names = [];

    foreach ($groups as $group) {
        // Get variation for meta access
        $variation_ids = !empty($group['variation_ids']) && is_string($group['variation_ids']) ? array_filter(explode(',', $group['variation_ids'])) : [];
        if (empty($variation_ids)) {
            error_log("InterSoccer: No valid variation_ids for event group - Raw: " . print_r($group['variation_ids'], true));
            $variation_ids = [0];
        }
        $variation_id = $variation_ids[0];
        $variation = $variation_id ? wc_get_product($variation_id) : false;
        $parent_product = $variation ? wc_get_product($variation->get_parent_id()) : false;

        // Log variation access
        error_log("InterSoccer: Other Events variation $variation_id - Loaded: " . ($variation ? 'Yes' : 'No') . ", Meta start: " . ($variation ? $variation->get_meta('_course_start_date') : 'N/A'));

        // Fetch event-specific meta
        $event_start = $variation ? $variation->get_meta('_course_start_date') : ($parent_product ? $parent_product->get_meta('_course_start_date') : '1970-01-01');
        $total_weeks = $variation ? (int) $variation->get_meta('_course_total_weeks') : ($parent_product ? (int) $parent_product->get_meta('_course_total_weeks') : 0);
        $holidays = $variation ? ($variation->get_meta('_course_holiday_dates') ?: []) : ($parent_product ? ($parent_product->get_meta('_course_holiday_dates') ?: []) : []);
        $event_days = $parent_product ? (wc_get_product_terms($parent_product->get_id(), 'pa_course-day', ['fields' => 'names']) ?: wc_get_product_terms($parent_product->get_id(), 'pa_days-of-week', ['fields' => 'names']) ?: ['Monday']) : ['Monday'];

        // Calculate end date if meta is available
        $event_end = '1970-01-01';
        if ($event_start !== '1970-01-01' && $total_weeks > 0) {
            $event_end = calculate_course_end_date($variation_id, $event_start, $total_weeks, $holidays, $event_days);
            error_log("InterSoccer: Calculated Other Events dates for variation $variation_id - Start: $event_start, End: $event_end");
        } else {
            error_log("InterSoccer: No valid meta for variation $variation_id - Using stored dates");
            $event_start = !empty($group['start_dates']) && is_string($group['start_dates']) ? explode(',', $group['start_dates'])[0] : '1970-01-01';
            $event_end = !empty($group['end_dates']) && is_string($group['end_dates']) ? explode(',', $group['end_dates'])[0] : '1970-01-01';
        }

        // Validate and format dates
        $group['corrected_start_date'] = date('Y-m-d', strtotime($event_start)) ?: '1970-01-01';
        $group['corrected_end_date'] = date('Y-m-d', strtotime($event_end)) ?: '1970-01-01';

        // Collect unique seasons and product names for filters
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
                    🔄 <?php _e('Reconcile Rosters', 'intersoccer-reports-rosters'); ?>
                </a>
            </div>
        </div>

        <div class="export-buttons">
            <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="action" value="intersoccer_export_other_events">
                <input type="hidden" name="export_type" value="other_events">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                <input type="submit" name="export_other_events" class="button button-primary" 
                       value="<?php _e('📥 Export Other Events', 'intersoccer-reports-rosters'); ?>">
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
                                <?php echo esc_html($season); ?>
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
                        🔄 <?php _e('Clear Filters', 'intersoccer-reports-rosters'); ?>
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
                                <?php echo esc_html($season); ?>
                            </h2>
                            <div class="season-stats">
                                <?php 
                                $season_total = is_array($events) ? array_sum(array_column($events, 'total_players')) : 0;
                                $event_count = is_array($events) ? count($events) : 0;
                                ?>
                                <span class="stat-item">
                                    👥 <?php echo $season_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    📚 <?php echo $event_count; ?> <?php _e('events', 'intersoccer-reports-rosters'); ?>
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
                                                if ($event['corrected_start_date'] !== '1970-01-01' && $event['corrected_end_date'] !== '1970-01-01') {
                                                    echo esc_html(date('M j', strtotime($event['corrected_start_date'])) . ' - ' . date('M j, Y', strtotime($event['corrected_end_date'])));
                                                } else {
                                                    echo 'TBD';
                                                }
                                                ?></span>
                                            </div>
                                        </div>
                                        <div class="course-footer">
                                            <div class="player-count">
                                                <span class="count-number"><?php echo esc_html($event['total_players']); ?></span>
                                                <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                            </div>
                                            <div class="course-actions">
                                                <?php 
                                                $view_url = admin_url('admin.php?page=intersoccer-roster-details&product_name=' . urlencode($event['product_name']) . '&age_group=' . urlencode($event['age_group']) . '&times=' . urlencode($event['times']));
                                                ?>
                                                <a href="<?php echo esc_url($view_url); ?>" class="button-roster-view">
                                                    👀 <?php _e('View Roster', 'intersoccer-reports-rosters'); ?>
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