<?php
/**
 * Rosters pages for InterSoccer Reports and Rosters plugin.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.0.7
 * @author Jeremy Lee
 */

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
            color: #ffffffff;
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

    // Fetch camp data with season extraction from camp_terms and city from order item metadata
    $base_query = "SELECT COALESCE(r.camp_terms, 'N/A') as camp_terms,
                      COALESCE(r.venue, 'N/A') as venue,
                      COALESCE(oim.meta_value, 'N/A') as city,
                      r.age_group,
                      r.times,
                      COUNT(DISTINCT r.order_item_id) as total_players,
                      GROUP_CONCAT(DISTINCT r.order_item_id) as order_item_ids,
                      r.event_signature,
                      GROUP_CONCAT(DISTINCT r.player_name) as player_names,
                      GROUP_CONCAT(DISTINCT r.start_date) as start_dates,
                      GROUP_CONCAT(DISTINCT r.end_date) as end_dates,
                      GROUP_CONCAT(DISTINCT r.product_name) as product_names
               FROM $rosters_table r
               LEFT JOIN $order_itemmeta_table oim ON r.order_item_id = oim.order_item_id AND oim.meta_key = 'City'
               WHERE r.activity_type IN ('Camp', 'Camp, Girls Only', 'Camp, Girls\' only')
               AND r.girls_only = 0";  // EXCLUDE Girls Only events

    // Add coach venue filtering if user is a coach
    if ($is_coach && !empty($coach_accessible_venues)) {
        $placeholders = implode(',', array_fill(0, count($coach_accessible_venues), '%s'));
        $base_query .= $wpdb->prepare(" AND r.venue IN ($placeholders)", $coach_accessible_venues);
    } elseif ($is_coach && empty($coach_accessible_venues)) {
        // Coach has no venue assignments, show no results
        $base_query .= " AND 1=0";
    }

    $base_query .= " GROUP BY r.event_signature
                     ORDER BY r.camp_terms, r.venue, r.age_group";    $start_time = microtime(true);
    $groups = $wpdb->get_results($base_query, ARRAY_A);
    $query_time = microtime(true) - $start_time;
    $all_venues = $wpdb->get_col("SELECT DISTINCT venue FROM $rosters_table WHERE activity_type = 'Camp' AND girls_only = 0 AND venue IS NOT NULL ORDER BY venue");
    $all_camp_terms = $wpdb->get_col("SELECT DISTINCT camp_terms FROM $rosters_table WHERE activity_type = 'Camp' AND girls_only = 0 AND camp_terms IS NOT NULL ORDER BY camp_terms");
    $all_age_groups = $wpdb->get_col("SELECT DISTINCT age_group FROM $rosters_table WHERE activity_type = 'Camp' AND girls_only = 0 AND age_group IS NOT NULL ORDER BY age_group");
    $all_cities = $wpdb->get_col("SELECT DISTINCT oim.meta_value FROM $rosters_table r LEFT JOIN $order_itemmeta_table oim ON r.order_item_id = oim.order_item_id WHERE r.activity_type = 'Camp' AND r.girls_only = 0 AND oim.meta_key = 'City' AND oim.meta_value IS NOT NULL ORDER BY oim.meta_value");

    error_log("InterSoccer: Camps query results: " . print_r($groups, true));
    error_log("InterSoccer: Camps query execution time: " . $query_time . " seconds");

    // Log unique city values
    $cities = $wpdb->get_col("SELECT DISTINCT oim.meta_value FROM $order_itemmeta_table oim WHERE oim.meta_key = 'City' AND oim.meta_value IS NOT NULL");
    error_log("InterSoccer: Unique cities from order item metadata for Camps: " . json_encode($cities));

    // Parse groups with date calculations
    $all_groups = [];
    $all_seasons = [];
    foreach ($groups as $group) {
        $variation_ids = isset($group['variation_ids']) && !empty($group['variation_ids']) && is_string($group['variation_ids']) ? array_filter(explode(',', $group['variation_ids'])) : [];
        if (empty($variation_ids)) {
            error_log("InterSoccer: No valid variation_ids for camp group - Raw: " . print_r(isset($group['variation_ids']) ? $group['variation_ids'] : 'KEY_NOT_SET', true));
            $variation_ids = [0];
        }
        $group['variation_ids'] = $variation_ids;
        $variation_id = $variation_ids[0];
        $variation = $variation_id ? wc_get_product($variation_id) : false;
        $parent_product = $variation ? wc_get_product($variation->get_parent_id()) : false;

        // Log variation access
        error_log("InterSoccer: Camp variation $variation_id - Loaded: " . ($variation ? 'Yes' : 'No') . ", Meta start: " . ($variation ? $variation->get_meta('_course_start_date') : 'N/A'));

        // Extract season from camp_terms
        $season = 'N/A';
        if ($group['camp_terms'] && $group['camp_terms'] !== 'N/A') {
            $parts = explode('-', $group['camp_terms']);
            $season = !empty($parts[0]) ? ucfirst($parts[0]) : 'N/A';
        }
        $group['season'] = intersoccer_normalize_season_for_display($season);

        // Fetch meta
        $start = $variation ? $variation->get_meta('_course_start_date') : ($parent_product ? $parent_product->get_meta('_course_start_date') : '1970-01-01');
        $end = $variation ? $variation->get_meta('_course_end_date') : ($parent_product ? $parent_product->get_meta('_course_end_date') : '1970-01-01');

        // Validate and format dates
        $group['corrected_start_date'] = date('Y-m-d', strtotime($start)) ?: '1970-01-01';
        $group['corrected_end_date'] = date('Y-m-d', strtotime($end)) ?: '1970-01-01';

        // Collect unique seasons
        if ($season && $season !== 'N/A') {
            $all_seasons[$season] = $season;
        }

        $all_groups[] = $group;
    }

    // Get filter options
    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $selected_camp_terms = isset($_GET['camp_terms']) ? sanitize_text_field($_GET['camp_terms']) : '';
    $selected_age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $selected_city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
    $display_groups = $all_groups;

    // Filter by season
    if ($selected_season) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_season) {
            return $group['season'] === $selected_season;
        });
    }
    if ($selected_venue) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_venue) {
            return $group['venue'] === $selected_venue;
        });
    }
    if ($selected_camp_terms) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_camp_terms) {
            return $group['camp_terms'] === $selected_camp_terms;
        });
    }
    if ($selected_age_group) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_age_group) {
            return $group['age_group'] === $selected_age_group;
        });
    }
    if ($selected_city) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_city) {
            return $group['city'] === $selected_city;
        });
    }

    error_log("InterSoccer: Filtered Camps: " . print_r($display_groups, true));

    // Group by season
    $grouped = [];
    foreach ($display_groups as $group) {
        $season = $group['season'] ?: 'N/A';
        $grouped[$season][] = $group;
    }
    krsort($grouped); // Latest seasons first

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
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-camps">
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
        <?php if ($selected_season || $selected_venue || $selected_camp_terms || $selected_age_group || $selected_city): ?>
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
                                ?>
                                <span class="stat-item">
                                    Players: <?php echo $season_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    Camps: <?php echo $camp_count; ?> <?php _e('camps', 'intersoccer-reports-rosters'); ?>
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
                                            <span class="detail-value"><?php echo esc_html($camp['city'] ?: 'N/A'); ?></span>
                                        </div>
                                    </div>
                                    <div class="camp-footer">
                                        <div class="player-count">
                                            <span class="count-number <?php echo intersoccer_get_count_class($camp['total_players']); ?>"><?php echo esc_html($camp['total_players']); ?></span>
                                            <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                        </div>
                                        <div class="camp-actions">
                                            <?php 
                                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&from=camps&event_signature=' . urlencode($camp['event_signature']) . '&camp_terms=' . urlencode($camp['camp_terms'] ?: 'N/A') . '&venue=' . urlencode($camp['venue']) . '&age_group=' . urlencode($camp['age_group']) . '&times=' . urlencode($camp['times']));
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

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
    $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

    // Clear all caches
    wp_cache_flush();
    delete_transient('intersoccer_rosters_cache');

    // Fetch course data with season and city from order item metadata
    $base_query = "SELECT COALESCE(r.season, 'N/A') as season,
                      COALESCE(r.venue, 'N/A') as venue,
                      COALESCE(oim.meta_value, 'N/A') as city,
                      r.age_group,
                      r.times,
                      r.course_day,
                      COUNT(DISTINCT r.order_item_id) as total_players,
                      GROUP_CONCAT(DISTINCT r.order_item_id) as order_item_ids,
                      r.event_signature,
                      GROUP_CONCAT(DISTINCT r.player_name) as player_names,
                      GROUP_CONCAT(DISTINCT r.start_date) as start_dates,
                      GROUP_CONCAT(DISTINCT r.end_date) as end_dates,
                      GROUP_CONCAT(DISTINCT r.product_name) as product_names
               FROM $rosters_table r
               LEFT JOIN $order_itemmeta_table oim ON r.order_item_id = oim.order_item_id AND oim.meta_key = 'City'
               WHERE r.activity_type IN ('Course', 'Course, Girls Only', 'Course, Girls\' only')
               AND r.girls_only = 0";  // EXCLUDE Girls Only events

    // Add coach venue filtering if user is a coach
    if ($is_coach && !empty($coach_accessible_venues)) {
        $placeholders = implode(',', array_fill(0, count($coach_accessible_venues), '%s'));
        $base_query .= $wpdb->prepare(" AND r.venue IN ($placeholders)", $coach_accessible_venues);
    } elseif ($is_coach && empty($coach_accessible_venues)) {
        // Coach has no venue assignments, show no results
        $base_query .= " AND 1=0";
    }

    $base_query .= " GROUP BY r.event_signature
                     ORDER BY r.season, r.venue, r.age_group";

    $start_time = microtime(true);
    $groups = $wpdb->get_results($base_query, ARRAY_A);
    $query_time = microtime(true) - $start_time;
    $all_venues = $wpdb->get_col("SELECT DISTINCT venue FROM $rosters_table WHERE activity_type = 'Course' AND girls_only = 0 AND venue IS NOT NULL ORDER BY venue");
    $all_course_days = $wpdb->get_col("SELECT DISTINCT course_day FROM $rosters_table WHERE activity_type = 'Course' AND girls_only = 0 AND course_day IS NOT NULL ORDER BY course_day");
    $all_age_groups = $wpdb->get_col("SELECT DISTINCT age_group FROM $rosters_table WHERE activity_type = 'Course' AND girls_only = 0 AND age_group IS NOT NULL ORDER BY age_group");
    $all_cities = $wpdb->get_col("SELECT DISTINCT oim.meta_value FROM $rosters_table r LEFT JOIN $order_itemmeta_table oim ON r.order_item_id = oim.order_item_id WHERE r.activity_type = 'Course' AND r.girls_only = 0 AND oim.meta_key = 'City' AND oim.meta_value IS NOT NULL ORDER BY oim.meta_value");
    error_log("InterSoccer: Courses query results: " . print_r($groups, true));
    error_log("InterSoccer: Courses query execution time: " . $query_time . " seconds");

    // Log unique city values
    $cities = $wpdb->get_col("SELECT DISTINCT oim.meta_value FROM $order_itemmeta_table oim WHERE oim.meta_key = 'City' AND oim.meta_value IS NOT NULL");
    error_log("InterSoccer: Unique cities from order item metadata for Courses: " . json_encode($cities));

    // Parse groups with date calculations
    $all_groups = [];
    $all_seasons = [];
    foreach ($groups as $group) {
        $variation_ids = isset($group['variation_ids']) && !empty($group['variation_ids']) && is_string($group['variation_ids']) ? array_filter(explode(',', $group['variation_ids'])) : [];
        if (empty($variation_ids)) {
            error_log("InterSoccer: No valid variation_ids for course group - Raw: " . print_r(isset($group['variation_ids']) ? $group['variation_ids'] : 'KEY_NOT_SET', true));
            $variation_ids = [0];
        }
        $group['variation_ids'] = $variation_ids;
        $variation_id = $variation_ids[0];
        $variation = $variation_id ? wc_get_product($variation_id) : false;
        $parent_product = $variation ? wc_get_product($variation->get_parent_id()) : false;

        // Log variation access
        error_log("InterSoccer: Course variation $variation_id - Loaded: " . ($variation ? 'Yes' : 'No') . ", Meta start: " . ($variation ? $variation->get_meta('_course_start_date') : 'N/A'));

        // Fetch meta
        $start = $variation ? $variation->get_meta('_course_start_date') : ($parent_product ? $parent_product->get_meta('_course_start_date') : '1970-01-01');
        $total_weeks = $variation ? (int) $variation->get_meta('_course_total_weeks') : ($parent_product ? (int) $parent_product->get_meta('_course_total_weeks') : 0);
        $holidays = $variation ? ($variation->get_meta('_course_holiday_dates') ?: []) : ($parent_product ? ($parent_product->get_meta('_course_holiday_dates') ?: []) : []);
        $days = $parent_product ? (wc_get_product_terms($parent_product->get_id(), 'pa_course-day', ['fields' => 'names']) ?: wc_get_product_terms($parent_product->get_id(), 'pa_days-of-week', ['fields' => 'names']) ?: ['Monday']) : ['Monday'];

        // Calculate end date
        $end = '1970-01-01';
        if ($start !== '1970-01-01' && $total_weeks > 0) {
            $end = calculate_course_end_date($variation_id, $start, $total_weeks, $holidays, $days);
            error_log("InterSoccer: Calculated course dates for variation $variation_id - Start: $start, End: $end");
        } else {
            error_log("InterSoccer: No valid meta for variation $variation_id - Using stored dates");
            $start = !empty($group['start_dates']) && is_string($group['start_dates']) ? explode(',', $group['start_dates'])[0] : '1970-01-01';
            $end = !empty($group['end_dates']) && is_string($group['end_dates']) ? explode(',', $group['end_dates'])[0] : '1970-01-01';
        }

        // Validate and format dates
        $group['corrected_start_date'] = date('Y-m-d', strtotime($start)) ?: '1970-01-01';
        $group['corrected_end_date'] = date('Y-m-d', strtotime($end)) ?: '1970-01-01';

        // Normalize season for display
        $group['season'] = intersoccer_normalize_season_for_display($group['season']);

        // Collect unique seasons
        if ($group['season'] && $group['season'] !== 'N/A') {
            $all_seasons[$group['season']] = $group['season'];
        }

        $all_groups[] = $group;
    }

    // Get filter options
    $selected_season = isset($_GET['season']) ? sanitize_text_field($_GET['season']) : '';
    $selected_venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $selected_course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';
    $selected_age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $selected_city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';

    $display_groups = $all_groups;

    // Filter by season
    if ($selected_season) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_season) {
            return $group['season'] === $selected_season;
        });
    }
    if ($selected_venue) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_venue) {
            return $group['venue'] === $selected_venue;
        });
    }
    if ($selected_course_day) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_course_day) {
            return $group['course_day'] === $selected_course_day;
        });
    }
    if ($selected_age_group) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_age_group) {
            return $group['age_group'] === $selected_age_group;
        });
    }
    if ($selected_city) {
        $display_groups = array_filter($display_groups, function($group) use ($selected_city) {
            return $group['city'] === $selected_city;
        });
    }

    error_log("InterSoccer: Filtered Courses: " . print_r($display_groups, true));

    // Group by season then course_day
    $grouped = [];
    foreach ($display_groups as $group) {
        $season = $group['season'] ?: 'N/A';
        $course_day = $group['course_day'] ?: 'N/A';
        $grouped[$season][$course_day][] = $group;
    }
    krsort($grouped); // Latest seasons first

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
            <form method="get" class="filter-form">
                <input type="hidden" name="page" value="intersoccer-courses">
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
                <?php if ($selected_season): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-courses'); ?>" class="button button-secondary">
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
                                foreach ($day_groups as $day => $courses) {
                                    $season_total += array_sum(array_column($courses, 'total_players'));
                                    $event_count += count($courses);
                                }
                                ?>
                                <span class="stat-item">
                                    Players: <?php echo $season_total; ?> <?php _e('players', 'intersoccer-reports-rosters'); ?>
                                </span>
                                <span class="stat-item">
                                    Camps: <?php echo $event_count; ?> <?php _e('courses', 'intersoccer-reports-rosters'); ?>
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
                                                    <span class="detail-value"><?php echo esc_html($course['city'] ?: 'N/A'); ?></span>
                                                </div>
                                            </div>
                                            <div class="course-footer">
                                                <div class="player-count">
                                                   <span class="count-number <?php echo intersoccer_get_count_class($course['total_players']); ?>"><?php echo esc_html($course['total_players']); ?></span>
                                                    <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                                </div>
                                                <div class="course-actions">
                                                    <?php
                                                    $view_url = admin_url('admin.php?page=intersoccer-roster-details&from=courses&event_signature=' . urlencode($course['event_signature']) . '&course_day=' . urlencode($course['course_day'] ?: 'N/A') . '&venue=' . urlencode($course['venue']) . '&age_group=' . urlencode($course['age_group']) . '&times=' . urlencode($course['times']));
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

    // Simplified query using the new girls_only boolean column
    $base_query = "SELECT COALESCE(r.season, 'N/A') as season,
                          COALESCE(r.venue, 'N/A') as venue,
                          COALESCE(oim.meta_value, 'N/A') as city,
                          r.age_group,
                          r.times,
                          r.camp_terms,
                          r.course_day,
                          r.activity_type,
                          r.start_date,
                          r.end_date,
                          COUNT(DISTINCT r.order_item_id) as total_players,
                          GROUP_CONCAT(DISTINCT r.order_item_id) as order_item_ids,
                          r.event_signature,
                          GROUP_CONCAT(DISTINCT r.player_name) as player_names,
                          GROUP_CONCAT(DISTINCT r.product_name) as product_names
                   FROM $rosters_table r
                   LEFT JOIN $order_itemmeta_table oim ON r.order_item_id = oim.order_item_id AND oim.meta_key = 'City'
                   WHERE r.girls_only = 1";

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
    $all_venues = $wpdb->get_col("SELECT DISTINCT venue FROM $rosters_table WHERE girls_only = 1 AND venue IS NOT NULL ORDER BY venue");
    $all_camp_terms = $wpdb->get_col("SELECT DISTINCT camp_terms FROM $rosters_table WHERE girls_only = 1 AND camp_terms IS NOT NULL ORDER BY camp_terms");
    $all_course_days = $wpdb->get_col("SELECT DISTINCT course_day FROM $rosters_table WHERE girls_only = 1 AND course_day IS NOT NULL ORDER BY course_day");
    $all_age_groups = $wpdb->get_col("SELECT DISTINCT age_group FROM $rosters_table WHERE girls_only = 1 AND age_group IS NOT NULL ORDER BY age_group");
    $all_cities = $wpdb->get_col("SELECT DISTINCT oim.meta_value FROM $rosters_table r LEFT JOIN $order_itemmeta_table oim ON r.order_item_id = oim.order_item_id WHERE r.girls_only = 1 AND oim.meta_key = 'City' AND oim.meta_value IS NOT NULL ORDER BY oim.meta_value");
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
        <?php if ($selected_season || $selected_type || $selected_venue || $selected_camp_terms || $selected_course_day || $selected_age_group || $selected_city): ?>
                <div class="filter-group">
                    <a href="<?php echo admin_url('admin.php?page=intersoccer-girls-only'); ?>" class="button button-secondary">
                        Clear Filters
                    </a>
                </div>
                <?php endif; ?>
            </form>
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
                                                📍 <?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($course['venue'], 'pa_intersoccer-venues') : ($course['venue'] ?: 'Unknown Venue')); ?>
                                            </h3>
                                        </div>
                                        <div class="camp-details">
                                            <div class="detail-row">
                                            <span class="detail-label">📅 Camp Term</span>
                                            <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($camp['camp_terms'], 'pa_camp-terms') : ($camp['camp_terms'] ?: 'TBD')); ?></span>
                                        </div>
                                            <div class="detail-row">
                                                <span class="detail-label">👶 Age Group</span>
                                                <span class="detail-value"><?php echo esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($course['age_group'], 'pa_age-group') : $course['age_group']); ?></span>
                                            </div>
                                             <div class="detail-row">
                                                <span class="detail-label">🌆 City</span>
                                                <span class="detail-value"><?php echo esc_html($camp['city'] ?: 'N/A'); ?></span>
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
                                                $view_url = admin_url('admin.php?page=intersoccer-roster-details&from=girls-only&event_signature=' . urlencode($camp['event_signature']) . '&camp_terms=' . urlencode($camp['camp_terms'] ?: 'N/A') . '&venue=' . urlencode($camp['venue']) . '&age_group=' . urlencode($camp['age_group']) . '&times=' . urlencode($camp['times']) . '&girls_only=1');
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
                                                        <span class="detail-value"><?php echo esc_html($course['city'] ?: 'N/A'); ?></span>
                                                    </div>
                                                </div>
                                                <div class="course-footer">
                                                    <div class="player-count">
                                                        <span class="count-number <?php echo intersoccer_get_count_class($course['total_players']); ?>"><?php echo esc_html($course['total_players']); ?></span>
                                                        <span class="count-label"><?php _e('players', 'intersoccer-reports-rosters'); ?></span>
                                                    </div>
                                                    <div class="course-actions">
                                                        <?php
                                                        $view_url = admin_url('admin.php?page=intersoccer-roster-details&from=girls-only&event_signature=' . urlencode($course['event_signature']) . '&course_day=' . urlencode($course['course_day'] ?: 'N/A') . '&venue=' . urlencode($course['venue']) . '&age_group=' . urlencode($course['age_group']) . '&times=' . urlencode($course['times']) . '&girls_only=1');
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
    $base_query = "SELECT COALESCE(season, 'N/A') as season,
                      COALESCE(venue, 'N/A') as venue,
                      COALESCE(product_name, 'N/A') as product_name,
                      age_group,
                      times,
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

    $base_query .= " GROUP BY season, product_name, age_group, times
                     ORDER BY season DESC, product_name, age_group";

    $groups = $wpdb->get_results($base_query, ARRAY_A);
    error_log("InterSoccer: Other Events query results: " . print_r($groups, true));

    // Parse data with proper date handling
    $all_events = [];
    $all_seasons = [];
    $all_product_names = [];

    foreach ($groups as $group) {
        // Get variation for meta access
        $variation_ids = isset($group['variation_ids']) && !empty($group['variation_ids']) && is_string($group['variation_ids']) ? array_filter(explode(',', $group['variation_ids'])) : [];
        if (empty($variation_ids)) {
            error_log("InterSoccer: No valid variation_ids for event group - Raw: " . print_r($group['variation_ids'], true));
            $variation_ids = [0];
        }
        $group['variation_ids'] = $variation_ids;
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
                                                $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_ids=' . urlencode($event['variation_ids']) . '&product_name=' . urlencode($event['product_name']) . '&age_group=' . urlencode($event['age_group']) . '&times=' . urlencode($event['times']) . '&season=' . urlencode($season));
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

    $product_names_query = "SELECT DISTINCT product_name FROM $rosters_table WHERE product_name IS NOT NULL";

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
                    $query = "SELECT variation_id, product_name, venue, age_group, COUNT(DISTINCT order_item_id) as total_players
                              FROM $rosters_table
                              WHERE product_name = %s";

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

                    $query .= " GROUP BY variation_id, product_name, venue, age_group
                               ORDER BY product_name, venue, age_group";

                    $groups = $wpdb->get_results(
                        $wpdb->prepare($query, $query_args),
                        ARRAY_A
                    );
                    
                    if (!empty($groups)) {
                        echo '<div class="roster-season">';
                        echo '<div class="season-header">';
                        echo '<h2 class="season-title">' . esc_html($product_name) . '</h2>';
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
                            $view_url = admin_url('admin.php?page=intersoccer-roster-details&variation_id=' . urlencode($group['variation_id']) . '&age_group=' . urlencode($group['age_group']));
                            echo '<tr>';
                            echo '<td style="padding: 15px;">' . esc_html(function_exists('intersoccer_get_term_name') ? intersoccer_get_term_name($group['product_name'], 'product') : $group['product_name']) . '</td>';
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