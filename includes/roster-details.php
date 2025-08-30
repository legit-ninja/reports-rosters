<?php
/**
 * Roster Details and Specific Event Pages
 *
 * Handles rendering of detailed roster views.
 *
 * @package InterSoccer_Reports_Rosters
 * @version 1.4.49  // Incremented for activity type and referer fix
 * @author Jeremy Lee
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once plugin_dir_path(dirname(__FILE__)) . 'includes/roster-data.php';

/**
 * Add auction house style CSS
 */
add_action('admin_head', function () {
    echo '<style>
        /* Reset admin styles to prevent plugin conflicts */
        .roster-app {
            all: initial;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            line-height: 1.4;
            color: #2c3e50;
            background: #f5f7fa;
            min-height: 100vh;
            padding: 0;
            margin: 0 -20px 0 -22px;
        }
        
        .roster-app * {
            box-sizing: border-box;
        }
        
        /* Header Section */
        .roster-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 24px 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: sticky;
            top: 32px;
            z-index: 100;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .roster-title {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .event-badge {
            background: rgba(255,255,255,0.25);
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .header-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .stat-pill {
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        /* Controls Bar */
        .controls-bar {
            background: white;
            border-bottom: 1px solid #e1e8ed;
            padding: 16px 30px;
            position: sticky;
            top: 96px;
            z-index: 99;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .controls-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .search-box {
            position: relative;
            flex: 1;
            min-width: 280px;
            max-width: 400px;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 16px 10px 40px;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            background: #fafbfc;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #8899a6;
            font-size: 16px;
        }
        
        .filter-tabs {
            display: flex;
            background: #f5f8fa;
            border-radius: 8px;
            padding: 3px;
            gap: 2px;
        }
        
        .filter-tab {
            padding: 8px 16px;
            background: transparent;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #657786;
        }
        
        .filter-tab:hover {
            background: white;
            color: #14171a;
        }
        
        .filter-tab.active {
            background: white;
            color: #667eea;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .sort-dropdown {
            padding: 8px 12px;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            background: white;
            font-size: 13px;
            cursor: pointer;
            min-width: 140px;
        }
        
        /* Main Content */
        .roster-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 30px;
        }
        
        /* Player List Table */
        .players-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #e1e8ed;
        }
        
        .table-header {
            background: #f8fafc;
            border-bottom: 2px solid #e1e8ed;
            padding: 0;
            display: grid;
            grid-template-columns: 40px 200px 300px 140px 180px 120px 140px 100px;
            gap: 0;
            font-weight: 600;
            font-size: 12px;
            color: #536471;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .header-cell {
            padding: 16px 12px;
            display: flex;
            align-items: center;
            border-right: 1px solid #e1e8ed;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        
        .header-cell:hover {
            background: #f0f3f7;
        }
        
        .header-cell:last-child {
            border-right: none;
        }
        
        .player-row {
            display: grid;
            grid-template-columns: 40px 200px 300px 140px 180px 120px 140px 100px;
            gap: 0;
            border-bottom: 1px solid #f0f3f7;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .player-row:hover {
            background: #f8fafc;
        }
        
        .player-row.unknown {
            background: #fff5f5;
            border-left: 4px solid #ef4444;
        }
        
        .player-row.has-medical {
            border-left: 4px solid #f59e0b;
        }
        
        .player-row.highlighted {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
        }
        
        .player-cell {
            padding: 16px 12px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            text-align: left;
            border-right: 1px solid #f0f3f7;
            font-size: 14px;
            min-height: 54px;
            flex-wrap: wrap;
            overflow: hidden;
        }
        
        .player-cell:last-child {
            border-right: none;
        }
        
        /* Player Avatar */
        .player-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 12px;
            margin-right: 8px;
            flex-shrink: 0;
        }
        
        /* Player Name Cell */
        .player-name-cell {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .player-name {
            font-weight: 600;
            color: #14171a;
        }
        
        .player-age {
            font-size: 12px;
            color: #657786;
        }
        
        .unknown-player .player-name {
            color: #ef4444;
            font-style: italic;
        }
        
        /* Contact Cell */
        .contact-cell {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .contact-item {
            font-size: 12px;
            color: #657786;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        
        .contact-item:hover {
            color: #667eea;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .badge-confirmed {
            background: #dcfce7;
            color: #166534;
        }
        
        .badge-unknown {
            background: #fef2f2;
            color: #991b1b;
        }
        
        .badge-medical {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-late-pickup {
            background: #e0e7ff;
            color: #3730a3;
        }
        
        /* Attendance Grid */
        .attendance-grid {
            display: flex;
            gap: 2px;
        }
        
        .day-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #e5e7eb;
        }
        
        .day-dot.present {
            background: #22c55e;
        }
        
        .day-dot.absent {
            background: #ef4444;
        }
        
        /* Gender Badge */
        .gender-badge {
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
            color: white;
            text-align: center;
        }
        
        .gender-m {
            background: #3b82f6;
        }
        
        .gender-f {
            background: #ec4899;
        }
        
        .gender-other {
            background: #8b5cf6;
        }
        
        /* Export Section */
        .export-bar {
            background: white;
            padding: 16px 30px;
            border-top: 1px solid #e1e8ed;
            position: sticky;
            bottom: 0;
            z-index: 98;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.06);
        }
        
        .export-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .export-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .export-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .results-info {
            font-size: 13px;
            color: #657786;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .table-header,
            .player-row {
                grid-template-columns: 36px 180px 250px 120px 160px 100px 80px;
            }
            
            .header-cell:nth-child(7),
            .player-cell:nth-child(7) {
                display: none;
            }
        }
        
        @media (max-width: 968px) {
            .roster-header {
                padding: 20px;
            }
            
            .controls-bar,
            .roster-content,
            .export-bar {
                padding-left: 20px;
                padding-right: 20px;
            }
            
            .controls-content {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            
            .search-box {
                min-width: 100%;
                max-width: none;
            }
            
            .filter-tabs {
                justify-content: center;
            }
            
            .table-header,
            .player-row {
                grid-template-columns: 32px 250px 160px 80px;
            }
            
            .header-cell:nth-child(n+5),
            .player-cell:nth-child(n+5) {
                display: none;
            }
            
            .player-cell {
                padding: 8px 12px;
                min-height: 48px;
            }
        }
        
        @media (max-width: 640px) {
            .roster-header {
                padding: 16px;
            }
            
            .roster-title {
                font-size: 20px;
            }
            
            .header-stats {
                width: 100%;
                justify-content: space-between;
            }
            
            .table-header,
            .player-row {
                grid-template-columns: 200px 100px 60px;
            }
            
            .header-cell:nth-child(1),
            .player-cell:nth-child(1) {
                display: none; /* Hide avatar on small screens */
            }
            
            .header-cell:nth-child(n+4),
            .player-cell:nth-child(n+4) {
                display: none;
            }
            
            .player-name-cell {
                display: flex;
                align-items: center;
                justify-content: flex-start;
                flex-wrap: wrap;
                gap: 8px;
            }
        }
        
        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        
        /* Tooltips */
        .tooltip {
            position: relative;
        }
        
        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1f2937;
            color: white;
            padding: 6px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            z-index: 1000;
        }
    </style>';
});

/**
 * Render the auction house style roster page
 */
function intersoccer_render_roster_details_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Get query parameters
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    $variation_id = isset($_GET['variation_id']) ? intval($_GET['variation_id']) : 0;
    $camp_terms = isset($_GET['camp_terms']) ? sanitize_text_field($_GET['camp_terms']) : '';
    $course_day = isset($_GET['course_day']) ? sanitize_text_field($_GET['course_day']) : '';
    $venue = isset($_GET['venue']) ? sanitize_text_field($_GET['venue']) : '';
    $age_group = isset($_GET['age_group']) ? sanitize_text_field($_GET['age_group']) : '';
    $times = isset($_GET['times']) ? sanitize_text_field($_GET['times']) : '';
    $product_name = isset($_GET['product_name']) ? sanitize_text_field($_GET['product_name']) : '';
    $event_dates = isset($_GET['event_dates']) ? sanitize_text_field($_GET['event_dates']) : '';
    $from_page = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : '';
    $girls_only = isset($_GET['girls_only']) ? (bool) $_GET['girls_only'] : false;

    // Build query
    $query = "SELECT r.player_name, r.first_name, r.last_name, r.gender, r.parent_phone, r.parent_email, r.age, r.medical_conditions, r.late_pickup, r.booking_type, r.course_day, r.shirt_size, r.shorts_size, r.day_presence, r.order_item_id, r.variation_id, r.age_group, r.activity_type, r.product_name, r.camp_terms, r.venue, r.times, r.product_id, r.girls_only";
    $query .= " FROM $rosters_table r";
    
    $where_clauses = [];
    $query_params = [];

    // Apply filters based on parameters
    if ($girls_only) {
        $where_clauses[] = "r.girls_only = 1";
    }

    if ($product_id > 0) {
        $where_clauses[] = "r.product_id = %d";
        $query_params[] = $product_id;
    }

    if ($camp_terms && $camp_terms !== 'N/A') {
        $where_clauses[] = "(r.camp_terms = %s OR r.camp_terms LIKE %s)";
        $query_params[] = $camp_terms;
        $query_params[] = '%' . $wpdb->esc_like($camp_terms) . '%';
    }

    if ($course_day && $course_day !== 'N/A') {
        $where_clauses[] = "(r.course_day = %s OR r.course_day LIKE %s)";
        $query_params[] = $course_day;
        $query_params[] = '%' . $wpdb->esc_like($course_day) . '%';
    }

    if ($venue) {
        $where_clauses[] = "r.venue = %s";
        $query_params[] = $venue;
    }

    if ($age_group) {
        $where_clauses[] = "r.age_group LIKE %s";
        $query_params[] = '%' . $wpdb->esc_like($age_group) . '%';
    }

    if ($times) {
        $where_clauses[] = "r.times = %s";
        $query_params[] = $times;
    }

    if (empty($where_clauses)) {
        echo '<div class="roster-app"><div style="padding: 40px; text-align: center;">No valid parameters provided.</div></div>';
        return;
    }

    $query .= " WHERE " . implode(' AND ', $where_clauses);
    $query .= " ORDER BY r.player_name, r.variation_id, r.order_item_id";
    
    $rosters = $wpdb->get_results($wpdb->prepare($query, $query_params), OBJECT);

    if (!$rosters) {
        echo '<div class="roster-app"><div style="padding: 40px; text-align: center;">No players found for the specified criteria.</div></div>';
        return;
    }

    // Get base roster info
    $base_roster = $rosters[0];
    $is_camp_like = ($base_roster->activity_type === 'Camp' || !empty($base_roster->camp_terms));
    $is_girls_only = (bool) $base_roster->girls_only;

    // Calculate stats
    $total_count = count($rosters);
    $unknown_count = count(array_filter($rosters, fn($row) => $row->player_name === 'Unknown Attendee'));
    $confirmed_count = $total_count - $unknown_count;
    $medical_count = count(array_filter($rosters, fn($row) => !empty($row->medical_conditions) && $row->medical_conditions !== 'N/A'));
    $late_pickup_count = count(array_filter($rosters, fn($row) => $row->late_pickup === 'Yes'));

    ?>
    <div class="roster-app">
        <!-- Header -->
        <div class="roster-header">
            <div class="header-content">
                <div>
                    <h1 class="roster-title">
                        <?php echo esc_html($base_roster->course_day ?: $base_roster->camp_terms); ?>
                        <?php if ($is_girls_only): ?>
                        <span class="event-badge">Girls Only</span>
                        <?php endif; ?>
                    </h1>
                    <div style="margin-top: 8px; font-size: 14px; opacity: 0.9;">
                        <?php echo esc_html($base_roster->venue); ?> • 
                        <?php echo esc_html($base_roster->age_group); ?> • 
                        <?php echo esc_html($base_roster->times); ?>
                    </div>
                </div>
                
                <div class="header-stats">
                    <div class="stat-pill">
                        <strong><?php echo $total_count; ?></strong> Total
                    </div>
                    <div class="stat-pill">
                        <strong><?php echo $confirmed_count; ?></strong> Confirmed
                    </div>
                    <?php if ($unknown_count > 0): ?>
                    <div class="stat-pill">
                        <strong><?php echo $unknown_count; ?></strong> Unknown
                    </div>
                    <?php endif; ?>
                    <?php if ($medical_count > 0): ?>
                    <div class="stat-pill">
                        <strong><?php echo $medical_count; ?></strong> Medical
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls-bar">
            <div class="controls-content">
                <div class="search-box">
                    <span class="search-icon">⚡</span>
                    <input type="text" class="search-input" id="playerSearch" 
                           placeholder="Search players by name, email, or phone...">
                </div>
                
                <div class="filter-tabs">
                    <button class="filter-tab active" data-filter="all">All Players</button>
                    <button class="filter-tab" data-filter="confirmed">Confirmed</button>
                    <?php if ($unknown_count > 0): ?>
                    <button class="filter-tab" data-filter="unknown">Unknown</button>
                    <?php endif; ?>
                    <?php if ($medical_count > 0): ?>
                    <button class="filter-tab" data-filter="medical">Medical</button>
                    <?php endif; ?>
                </div>
                
                <select class="sort-dropdown" id="sortSelect">
                    <option value="name-asc">Name A-Z</option>
                    <option value="name-desc">Name Z-A</option>
                    <option value="age-asc">Age (Youngest)</option>
                    <option value="age-desc">Age (Oldest)</option>
                </select>
            </div>
        </div>

        <!-- Main Content -->
        <div class="roster-content">
            <div class="players-table">
                <!-- Table Header -->
                <div class="table-header">
                    <div class="header-cell"></div>
                    <div class="header-cell">Player</div>
                    <div class="header-cell">Contact</div>
                    <div class="header-cell">Status</div>
                    <div class="header-cell">Details</div>
                    <div class="header-cell">Gender</div>
                    <?php if ($is_camp_like): ?>
                    <div class="header-cell">Attendance</div>
                    <?php endif; ?>
                    <div class="header-cell">Actions</div>
                </div>

                <!-- Player Rows -->
                <div id="playersContainer">
                    <?php foreach ($rosters as $player): ?>
                        <?php 
                        $is_unknown = $player->player_name === 'Unknown Attendee';
                        $has_medical = !empty($player->medical_conditions) && $player->medical_conditions !== 'N/A';
                        $day_presence = !empty($player->day_presence) ? json_decode($player->day_presence, true) : [];
                        
                        $row_classes = ['player-row'];
                        if ($is_unknown) $row_classes[] = 'unknown';
                        if ($has_medical) $row_classes[] = 'has-medical';
                        
                        $search_text = strtolower(
                            ($player->first_name ?: '') . ' ' . 
                            ($player->last_name ?: '') . ' ' . 
                            ($player->parent_email ?: '') . ' ' . 
                            ($player->parent_phone ?: '')
                        );
                        
                        $initials = '';
                        if ($player->first_name) $initials .= substr($player->first_name, 0, 1);
                        if ($player->last_name) $initials .= substr($player->last_name, 0, 1);
                        if (empty($initials)) $initials = '?';
                        ?>
                        
                        <div class="<?php echo implode(' ', $row_classes); ?>" 
                             data-search="<?php echo esc_attr($search_text); ?>"
                             data-filter-type="<?php echo $is_unknown ? 'unknown' : 'confirmed'; ?>"
                             data-has-medical="<?php echo $has_medical ? 'true' : 'false'; ?>"
                             data-age="<?php echo intval($player->age ?: 0); ?>"
                             data-name="<?php echo esc_attr($player->first_name . ' ' . $player->last_name); ?>">
                            
                            <!-- Avatar -->
                            <div class="player-cell">
                                <div class="player-avatar"><?php echo esc_html($initials); ?></div>
                            </div>
                            
                            <!-- Player Name -->
                            <div class="player-cell player-name-cell">
                                <div class="player-name <?php echo $is_unknown ? 'unknown-player' : ''; ?>">
                                    <?php echo esc_html(trim(($player->first_name ?: '') . ' ' . ($player->last_name ?: ''))); ?>
                                </div>
                                <?php if ($player->age): ?>
                                <div class="player-age">Age <?php echo esc_html($player->age); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Contact -->
                            <div class="player-cell contact-cell">
                                <?php if ($player->parent_email && $player->parent_email !== 'N/A'): ?>
                                <a href="mailto:<?php echo esc_attr($player->parent_email); ?>" class="contact-item">
                                    <?php echo esc_html($player->parent_email); ?>
                                </a>
                                <?php endif; ?>
                                <?php if ($player->parent_phone && $player->parent_phone !== 'N/A'): ?>
                                <a href="tel:<?php echo esc_attr($player->parent_phone); ?>" class="contact-item">
                                    <?php echo esc_html($player->parent_phone); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Status -->
                            <div class="player-cell">
                                <?php if ($is_unknown): ?>
                                <span class="status-badge badge-unknown">Unknown</span>
                                <?php else: ?>
                                <span class="status-badge badge-confirmed">Confirmed</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Details -->
                            <div class="player-cell">
                                <?php if ($has_medical): ?>
                                <div class="tooltip" data-tooltip="<?php echo esc_attr($player->medical_conditions); ?>">
                                    <span class="status-badge badge-medical">Medical</span>
                                </div>
                                <?php endif; ?>
                                <?php if ($player->late_pickup === 'Yes'): ?>
                                <span class="status-badge badge-late-pickup">Late Pickup</span>
                                <?php endif; ?>
                                <?php if ($is_camp_like && $player->booking_type && $player->booking_type !== 'N/A'): ?>
                                <div style="font-size: 12px; color: #657786; margin-top: 4px;">
                                    <?php echo esc_html($player->booking_type); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Gender -->
                            <div class="player-cell">
                                <?php if ($player->gender): ?>
                                <div class="gender-badge gender-<?php echo strtolower(substr($player->gender, 0, 1)); ?>">
                                    <?php echo esc_html(strtoupper(substr($player->gender, 0, 1))); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Attendance (for camps) -->
                            <?php if ($is_camp_like): ?>
                            <div class="player-cell">
                                <div class="attendance-grid" title="Mon-Tue-Wed-Thu-Fri">
                                    <?php 
                                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                                    foreach ($days as $day): 
                                        $presence = $day_presence[$day] ?? 'No';
                                        $class = ($presence === 'Yes') ? 'present' : 'absent';
                                    ?>
                                    <div class="day-dot <?php echo $class; ?>"></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Actions -->
                            <div class="player-cell">
                                <div style="display: flex; gap: 4px; font-size: 12px;">
                                    <?php if ($player->parent_phone && $player->parent_phone !== 'N/A'): ?>
                                    <a href="tel:<?php echo esc_attr($player->parent_phone); ?>" 
                                       style="color: #22c55e; text-decoration: none; padding: 4px;" title="Call">📞</a>
                                    <?php endif; ?>
                                    <?php if ($player->parent_email && $player->parent_email !== 'N/A'): ?>
                                    <a href="mailto:<?php echo esc_attr($player->parent_email); ?>" 
                                       style="color: #3b82f6; text-decoration: none; padding: 4px;" title="Email">✉️</a>
                                    <?php endif; ?>
                                    <?php if ($has_medical): ?>
                                    <span style="color: #f59e0b; padding: 4px;" title="Medical Needs">⚕️</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Export Bar -->
        <div class="export-bar">
            <div class="export-content">
                <div class="results-info">
                    <span id="resultsCount"><?php echo $total_count; ?> players shown</span>
                    <?php if ($unknown_count > 0): ?>
                    • <span style="color: #ef4444;"><?php echo $unknown_count; ?> need assignment</span>
                    <?php endif; ?>
                </div>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" target="_blank">
                    <input type="hidden" name="action" value="intersoccer_export_roster">
                    <input type="hidden" name="use_fields" value="1">
                    <input type="hidden" name="camp_terms" value="<?php echo esc_attr($camp_terms); ?>">
                    <input type="hidden" name="course_day" value="<?php echo esc_attr($course_day); ?>">
                    <input type="hidden" name="venue" value="<?php echo esc_attr($venue); ?>">
                    <input type="hidden" name="age_group" value="<?php echo esc_attr($age_group); ?>">
                    <input type="hidden" name="times" value="<?php echo esc_attr($times); ?>">
                    <input type="hidden" name="girls_only" value="<?php echo ($is_girls_only ? '1' : '0'); ?>">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('intersoccer_reports_rosters_nonce')); ?>">
                    <button type="submit" class="export-btn">Export to Excel</button>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript for functionality -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('playerSearch');
        const sortSelect = document.getElementById('sortSelect');
        const filterTabs = document.querySelectorAll('.filter-tab');
        const playersContainer = document.getElementById('playersContainer');
        const playerRows = playersContainer.querySelectorAll('.player-row');
        const resultsCount = document.getElementById('resultsCount');
        
        let currentFilter = 'all';
        let currentSort = 'name-asc';
        let currentSearch = '';
        
        // Search functionality with debouncing
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentSearch = this.value.toLowerCase();
                filterAndSort();
            }, 200);
        });
        
        // Filter tabs
        filterTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                filterTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                currentFilter = this.dataset.filter;
                filterAndSort();
            });
        });
        
        // Sort dropdown
        sortSelect.addEventListener('change', function() {
            currentSort = this.value;
            filterAndSort();
        });
        
        function filterAndSort() {
            let visibleRows = [];
            
            // Filter rows
            playerRows.forEach(row => {
                const matchesSearch = currentSearch === '' || 
                    row.dataset.search.includes(currentSearch);
                
                let matchesFilter = true;
                switch(currentFilter) {
                    case 'confirmed':
                        matchesFilter = row.dataset.filterType === 'confirmed';
                        break;
                    case 'unknown':
                        matchesFilter = row.dataset.filterType === 'unknown';
                        break;
                    case 'medical':
                        matchesFilter = row.dataset.hasMedical === 'true';
                        break;
                }
                
                if (matchesSearch && matchesFilter) {
                    row.style.display = 'grid';
                    visibleRows.push(row);
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Sort visible rows
            visibleRows.sort((a, b) => {
                switch(currentSort) {
                    case 'name-desc':
                        return b.dataset.name.localeCompare(a.dataset.name);
                    case 'age-asc':
                        return parseInt(a.dataset.age) - parseInt(b.dataset.age);
                    case 'age-desc':
                        return parseInt(b.dataset.age) - parseInt(a.dataset.age);
                    default: // name-asc
                        return a.dataset.name.localeCompare(b.dataset.name);
                }
            });
            
            // Reorder DOM elements
            visibleRows.forEach((row, index) => {
                row.style.order = index;
            });
            
            // Update results count
            resultsCount.textContent = `${visibleRows.length} players shown`;
            
            // Update search placeholder
            searchInput.placeholder = `Search ${visibleRows.length} players...`;
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'f':
                        e.preventDefault();
                        searchInput.focus();
                        break;
                }
            }
        });
        
        // Row hover effects for better UX
        playerRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.005)';
                this.style.zIndex = '10';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1)';
                this.style.zIndex = '1';
            });
        });
        
        // Initialize
        filterAndSort();
    });
    </script>
    <?php
}

/**
 * Helper function to categorize ages into ranges
 */
function get_age_range($age) {
    $age = intval($age);
    if ($age < 6) return '3-5';
    if ($age < 9) return '6-8';
    if ($age < 12) return '9-11';
    if ($age < 15) return '12-14';
    if ($age < 18) return '15-17';
    return '18+';
}
?>