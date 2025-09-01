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
 * Add amazing interface styles
 */
add_action('admin_head', function () {
    echo '<style>
        /* Reset and base styles */
        .roster-app {
            all: initial;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.5;
            color: #111827;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            margin: 0 -20px 0 -22px;
            position: relative;
        }
        
        .roster-app * {
            box-sizing: border-box;
        }
        
        /* Header with enhanced gradient */
        .roster-header {
            background: linear-gradient(135deg, #4338ca 0%, #7c3aed 50%, #db2777 100%);
            color: white;
            padding: 28px 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.12);
            position: sticky;
            top: 0px;
            z-index: 1000;
            border-radius: 0 0 16px 16px;
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .event-title {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .girls-only-badge {
            background: rgba(255,255,255,0.25);
            backdrop-filter: blur(10px);
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .header-stats {
            display: flex;
            gap: 20px;
            font-size: 15px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.1);
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        
        /* Controls with glass morphism */
        .controls-section {
            background: rgba(255,255,255,0.8);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.2);
            padding: 20px 32px;
            position: sticky;
            top: 120px;
            z-index: 999;
            margin: 20px 0;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        
        .controls-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
        }
        
        .search-container {
            position: relative;
            flex: 1;
            min-width: 280px;
            max-width: 400px;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 15px;
            background: white;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .search-input:focus {
            outline: none;
            border-color: #4338ca;
            box-shadow: 0 0 0 4px rgba(67, 56, 202, 0.1), 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 16px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 8px 16px;
            background: rgba(255,255,255,0.9);
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            backdrop-filter: blur(8px);
        }
        
        .filter-btn:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
            transform: translateY(-1px);
        }
        
        .filter-btn.active {
            background: #4338ca;
            border-color: #4338ca;
            color: white;
            box-shadow: 0 4px 12px rgba(67, 56, 202, 0.3);
        }
        
        .sort-select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            font-weight: 500;
        }
        
        /* Main content area */
        .main-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 32px 40px;
        }
        
        /* Beautiful player grid */
        .players-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .player-card {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 16px;
            padding: 24px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }
        
        .player-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4338ca, #7c3aed, #db2777);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .player-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            border-color: rgba(67, 56, 202, 0.3);
        }
        
        .player-card:hover::before {
            opacity: 1;
        }
        
        .player-card.selected {
            background: rgba(239, 246, 255, 0.95);
            border-color: #4338ca;
            transform: translateY(-4px);
        }
        
        .player-card.selected::before {
            opacity: 1;
        }
        
        .player-card.unknown {
            background: rgba(254, 242, 242, 0.9);
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .player-card.has-medical {
            border-left: 6px solid #f59e0b;
        }
        
        .player-card.has-medical::after {
            content: "⚕️";
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 18px;
            opacity: 0.7;
        }
        
        /* Card header */
        .card-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .player-avatar {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: linear-gradient(135deg, #4338ca, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 20px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(67, 56, 202, 0.3);
        }
        
        .unknown .player-avatar {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .player-info {
            flex: 1;
            min-width: 0;
        }
        
        .player-name {
            font-weight: 700;
            font-size: 18px;
            color: #111827;
            margin-bottom: 4px;
            line-height: 1.2;
        }
        
        .unknown .player-name {
            color: #ef4444;
            font-style: italic;
        }
        
        .player-meta {
            display: flex;
            gap: 12px;
            margin-bottom: 8px;
            font-size: 14px;
            color: #6b7280;
            flex-wrap: wrap;
        }
        
        .meta-item {
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
        }
        
        /* Status badges */
        .status-badges {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-confirmed {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .badge-unknown {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .badge-medical {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        
        .booking-type-badge {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        /* Contact info */
        .contact-info {
            margin-bottom: 16px;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            color: #4b5563;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s ease;
        }
        
        .contact-item:hover {
            color: #4338ca;
        }
        
        .contact-icon {
            font-size: 14px;
            opacity: 0.7;
        }
        
        /* Card actions */
        .card-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px solid #f3f4f6;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            padding: 8px 12px;
            background: rgba(67, 56, 202, 0.1);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
            color: #4338ca;
            font-weight: 500;
        }
        
        .action-btn:hover {
            background: #4338ca;
            color: white;
            transform: translateY(-1px);
        }
        
        .view-details-btn {
            background: linear-gradient(135deg, #4338ca, #7c3aed);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .view-details-btn:hover {
            background: linear-gradient(135deg, #3730a3, #6d28d9);
            transform: translateY(-1px);
        }
        
        /* Detail Panel - Enhanced */
        .detail-panel {
            position: fixed;
            top: 0;
            right: 0;
            width: 480px;
            height: 100vh;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-left: 1px solid rgba(255,255,255,0.2);
            box-shadow: -8px 0 32px rgba(0,0,0,0.12);
            transform: translateX(100%);
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1001;
            overflow-y: auto;
        }
        
        .detail-panel.open {
            transform: translateX(0);
        }
        
        .detail-header {
            background: linear-gradient(135deg, #4338ca, #7c3aed);
            color: white;
            padding: 32px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .detail-title {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 8px 0;
            color: white;
        }
        
        .detail-subtitle {
            opacity: 0.9;
            font-size: 14px;
            margin: 0;
        }
        
        .close-btn {
            position: absolute;
            top: 24px;
            right: 24px;
            background: rgba(255,255,255,0.2);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            font-size: 18px;
            cursor: pointer;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
        }
        
        .close-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .detail-content {
            padding: 32px;
        }
        
        .detail-section {
            margin-bottom: 32px;
            background: rgba(255,255,255,0.8);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-icon {
            font-size: 18px;
        }
        
        .detail-grid {
            display: grid;
            gap: 16px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(243, 244, 246, 0.5);
        }
        
        .detail-item:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 600;
        }
        
        .detail-value {
            font-size: 14px;
            color: #111827;
            font-weight: 600;
            text-align: right;
        }
        
        .contact-link {
            color: #4338ca;
            text-decoration: none;
            font-weight: 600;
        }
        
        .contact-link:hover {
            text-decoration: underline;
        }
        
        .medical-alert {
            background: linear-gradient(135deg, #fef3c7, #fed7aa);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 16px;
            color: #92400e;
            font-weight: 600;
            line-height: 1.5;
        }
        
        /* Attendance grid in detail panel */
        .attendance-grid-detail {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
            margin-top: 16px;
        }
        
        .attendance-day {
            text-align: center;
            padding: 12px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .day-present {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .day-absent {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        /* Export section */
        .export-section {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 16px;
            padding: 24px;
            margin: 32px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        }
        
        .export-btn {
            background: linear-gradient(135deg, #4338ca, #7c3aed);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(67, 56, 202, 0.4);
            background: linear-gradient(135deg, #3730a3, #6d28d9);
        }
        
        .results-summary {
            font-size: 16px;
            color: #374151;
            font-weight: 600;
        }
        
        /* Responsive design */
        @media (max-width: 1200px) {
            .players-grid {
                grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
                gap: 16px;
            }
            
            .detail-panel {
                width: 420px;
            }
        }
        
        @media (max-width: 768px) {
            .roster-app {
                margin: 0;
            }
            
            .roster-header {
                padding: 24px 20px;
                border-radius: 0;
            }
            
            .event-title {
                font-size: 24px;
            }
            
            .controls-section {
                margin: 16px 20px;
                padding: 16px 20px;
                top: 100px;
            }
            
            .main-content {
                padding: 0 20px 40px;
            }
            
            .controls-content {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }
            
            .search-container {
                max-width: none;
                min-width: auto;
            }
            
            .players-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .detail-panel {
                width: 100%;
                left: 0;
                transform: translateY(100%);
            }
            
            .detail-panel.open {
                transform: translateY(0);
            }
        }
        
        /* Loading and animations */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .player-card {
            animation: slideInUp 0.4s ease-out;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
            background: rgba(255,255,255,0.8);
            border-radius: 16px;
            backdrop-filter: blur(16px);
        }
        
        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 12px;
            color: #374151;
        }
        
        .empty-state p {
            font-size: 16px;
            max-width: 400px;
            margin: 0 auto;
        }
    </style>';
});

/**
 * Render the amazing roster interface
 */
function intersoccer_render_roster_details_page() {
    if (!current_user_can('manage_options') && !current_user_can('coach')) {
        wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
    }

    global $wpdb;
    $rosters_table = $wpdb->prefix . 'intersoccer_rosters';

    // Get query parameters (same as before)
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

    // Build query (same as before)
    $query = "SELECT r.player_name, r.first_name, r.last_name, r.gender, r.parent_phone, r.parent_email, r.age, r.medical_conditions, r.late_pickup, r.booking_type, r.course_day, r.shirt_size, r.shorts_size, r.day_presence, r.order_item_id, r.variation_id, r.age_group, r.activity_type, r.product_name, r.camp_terms, r.venue, r.times, r.product_id, r.girls_only";
    $query .= " FROM $rosters_table r";
    
    $where_clauses = [];
    $query_params = [];

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
        echo '<div class="roster-app"><div class="empty-state"><h3>No Parameters</h3><p>Invalid parameters provided for roster lookup.</p></div></div>';
        return;
    }

    $query .= " WHERE " . implode(' AND ', $where_clauses);
    $query .= " ORDER BY r.player_name, r.variation_id, r.order_item_id";
    
    $rosters = $wpdb->get_results($wpdb->prepare($query, $query_params), OBJECT);

    if (!$rosters) {
        echo '<div class="roster-app"><div class="empty-state"><h3>No Players Found</h3><p>No roster entries match the specified criteria.</p></div></div>';
        return;
    }

    // Calculate stats
    $base_roster = $rosters[0];
    $is_camp_like = ($base_roster->activity_type === 'Camp' || !empty($base_roster->camp_terms));
    $is_girls_only = (bool) $base_roster->girls_only;
    
    $total_count = count($rosters);
    $unknown_count = count(array_filter($rosters, fn($row) => $row->player_name === 'Unknown Attendee'));
    $confirmed_count = $total_count - $unknown_count;
    $medical_count = count(array_filter($rosters, fn($row) => !empty($row->medical_conditions) && $row->medical_conditions !== 'N/A'));

    ?>
    <div class="roster-app">
        <!-- Header -->
        <div class="roster-header">
            <div class="header-content">
                <div>
                    <h1 class="event-title">
                        <?php 
                        if ($is_camp_like) {
                            // For camps, show camp terms and age group
                            $camp_name = function_exists('intersoccer_get_term_name') ? 
                                intersoccer_get_term_name($base_roster->camp_terms, 'pa_camp-terms') : 
                                $base_roster->camp_terms;
                            $age_display = function_exists('intersoccer_get_term_name') ? 
                                intersoccer_get_term_name($base_roster->age_group, 'pa_age-group') : 
                                $base_roster->age_group;
                            echo esc_html($camp_name . ' - ' . $age_display);
                        } else {
                            // For courses, show course day and age group
                            $age_display = function_exists('intersoccer_get_term_name') ? 
                                intersoccer_get_term_name($base_roster->age_group, 'pa_age-group') : 
                                $base_roster->age_group;
                            echo esc_html(ucfirst($base_roster->course_day) . ' - ' . $age_display);
                        }
                        ?>
                        <?php if ($is_girls_only): ?>
                        <span class="girls-only-badge">Girls Only</span>
                        <?php endif; ?>
                    </h1>
                    <div style="font-size: 16px; opacity: 0.9; margin-top: 8px; font-weight: 500;">
                        <?php 
                        $venue_name = function_exists('intersoccer_get_term_name') ? 
                            intersoccer_get_term_name($base_roster->venue, 'pa_intersoccer-venues') : 
                            $base_roster->venue;
                        $times_display = function_exists('intersoccer_get_term_name') ? 
                            intersoccer_get_term_name($base_roster->times, 'pa_camp-times') : 
                            $base_roster->times;
                        echo esc_html($venue_name . ' • ' . $times_display);
                        if ($is_camp_like) {
                            echo ' • Camp';
                        } else {
                            echo ' • Course';
                        }
                        ?>
                    </div>
                </div>
                
                <div class="header-stats">
                    <div class="stat-item"><?php echo $total_count; ?> Total</div>
                    <div class="stat-item"><?php echo $confirmed_count; ?> Confirmed</div>
                    <?php if ($unknown_count > 0): ?>
                    <div class="stat-item" style="background: rgba(239,68,68,0.3); border-color: rgba(239,68,68,0.4);"><?php echo $unknown_count; ?> Unknown</div>
                    <?php endif; ?>
                    <?php if ($medical_count > 0): ?>
                    <div class="stat-item" style="background: rgba(245,158,11,0.3); border-color: rgba(245,158,11,0.4);"><?php echo $medical_count; ?> Medical</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls-section">
            <div class="controls-content">
                <div class="search-container">
                    <span class="search-icon">🔍</span>
                    <input type="text" class="search-input" id="playerSearch" placeholder="Search by name, email, or phone...">
                </div>
                
                <div class="filter-buttons">
                    <button class="filter-btn active" data-filter="all">All Players</button>
                    <button class="filter-btn" data-filter="confirmed">Confirmed</button>
                    <?php if ($unknown_count > 0): ?>
                    <button class="filter-btn" data-filter="unknown">Unknown</button>
                    <?php endif; ?>
                    <?php if ($medical_count > 0): ?>
                    <button class="filter-btn" data-filter="medical">Medical</button>
                    <?php endif; ?>
                </div>
                
                <select class="sort-select" id="sortSelect">
                    <option value="name-asc">Name A-Z</option>
                    <option value="name-desc">Name Z-A</option>
                    <option value="age-asc">Age (Youngest)</option>
                    <option value="age-desc">Age (Oldest)</option>
                </select>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Players Grid -->
            <div class="players-grid" id="playersGrid">
                <?php foreach ($rosters as $index => $player): ?>
                    <?php 
                    $is_unknown = $player->player_name === 'Unknown Attendee';
                    $has_medical = !empty($player->medical_conditions) && $player->medical_conditions !== 'N/A';
                    $day_presence = !empty($player->day_presence) ? json_decode($player->day_presence, true) : [];
                    
                    $card_classes = ['player-card'];
                    if ($is_unknown) $card_classes[] = 'unknown';
                    if ($has_medical) $card_classes[] = 'has-medical';
                    
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
                    
                    $full_name = trim(($player->first_name ?: '') . ' ' . ($player->last_name ?: ''));
                    ?>
                    
                    <div class="<?php echo implode(' ', $card_classes); ?>"
                         data-player-index="<?php echo $index; ?>"
                         data-search="<?php echo esc_attr($search_text); ?>"
                         data-filter-type="<?php echo $is_unknown ? 'unknown' : 'confirmed'; ?>"
                         data-has-medical="<?php echo $has_medical ? 'true' : 'false'; ?>"
                         data-age="<?php echo intval($player->age ?: 0); ?>"
                         data-name="<?php echo esc_attr($full_name); ?>">
                        
                        <!-- Card Header -->
                        <div class="card-header">
                            <div class="player-avatar"><?php echo esc_html($initials); ?></div>
                            <div class="player-info">
                                <div class="player-name"><?php echo esc_html($full_name ?: 'Unknown Player'); ?></div>
                                <div class="player-meta">
                                    <?php if ($player->age): ?>
                                    <span class="meta-item">Age <?php echo esc_html($player->age); ?></span>
                                    <?php endif; ?>
                                    <?php if ($player->gender): ?>
                                    <span class="meta-item"><?php echo esc_html($player->gender); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Status Badges -->
                        <div class="status-badges">
                            <?php if ($is_unknown): ?>
                            <span class="status-badge badge-unknown">Unknown</span>
                            <?php else: ?>
                            <span class="status-badge badge-confirmed">Confirmed</span>
                            <?php endif; ?>
                            
                            <?php if ($has_medical): ?>
                            <span class="status-badge badge-medical">Medical Alert</span>
                            <?php endif; ?>
                            
                            <?php if ($player->booking_type && $player->booking_type !== 'N/A'): ?>
                            <span class="booking-type-badge"><?php echo esc_html($player->booking_type); ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Contact Info -->
                        <div class="contact-info">
                            <?php if ($player->parent_email && $player->parent_email !== 'N/A'): ?>
                            <a href="mailto:<?php echo esc_attr($player->parent_email); ?>" class="contact-item">
                                <span class="contact-icon">📧</span>
                                <?php echo esc_html($player->parent_email); ?>
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($player->parent_phone && $player->parent_phone !== 'N/A'): ?>
                            <a href="tel:<?php echo esc_attr($player->parent_phone); ?>" class="contact-item">
                                <span class="contact-icon">📞</span>
                                <?php echo esc_html($player->parent_phone); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Card Actions -->
                        <div class="card-actions">
                            <div class="action-buttons">
                                <?php if ($player->parent_phone && $player->parent_phone !== 'N/A'): ?>
                                <a href="tel:<?php echo esc_attr($player->parent_phone); ?>" class="action-btn">Call</a>
                                <?php endif; ?>
                                <?php if ($player->parent_email && $player->parent_email !== 'N/A'): ?>
                                <a href="mailto:<?php echo esc_attr($player->parent_email); ?>" class="action-btn">Email</a>
                                <?php endif; ?>
                            </div>
                            <button class="view-details-btn" onclick="openPlayerDetail(<?php echo $index; ?>)">
                                View Details
                            </button>
                        </div>
                        
                        <!-- Hidden data for detail panel -->
                        <script type="application/json" class="player-data">
                        <?php 
                        echo json_encode([
                            'index' => $index,
                            'name' => $full_name ?: 'Unknown Player',
                            'firstName' => $player->first_name ?: '',
                            'lastName' => $player->last_name ?: '',
                            'age' => $player->age ?: '',
                            'gender' => $player->gender ?: '',
                            'email' => $player->parent_email ?: '',
                            'phone' => $player->parent_phone ?: '',
                            'medical' => $player->medical_conditions ?: '',
                            'latePickup' => $player->late_pickup === 'Yes',
                            'bookingType' => $player->booking_type ?: '',
                            'isUnknown' => $is_unknown,
                            'hasMedical' => $has_medical,
                            'dayPresence' => $day_presence,
                            'shirtSize' => $player->shirt_size ?: '',
                            'shortsSize' => $player->shorts_size ?: '',
                            'isCampLike' => $is_camp_like,
                            'isGirlsOnly' => $is_girls_only
                        ], JSON_HEX_APOS | JSON_HEX_QUOT);
                        ?>
                        </script>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Export Section -->
            <div class="export-section">
                <div class="results-summary">
                    <span id="resultsCount"><?php echo $total_count; ?> players shown</span>
                    <?php if ($unknown_count > 0): ?>
                    • <span style="color: #ef4444; font-weight: 700;"><?php echo $unknown_count; ?> need assignment</span>
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

        <!-- Detail Panel -->
        <div class="detail-panel" id="detailPanel">
            <div class="detail-header">
                <div>
                    <h2 class="detail-title" id="detailTitle">Player Details</h2>
                    <p class="detail-subtitle" id="detailSubtitle">Complete information</p>
                </div>
                <button class="close-btn" onclick="closePlayerDetail()">×</button>
            </div>
            <div class="detail-content" id="detailContent">
                <!-- Content populated by JavaScript -->
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('playerSearch');
        const sortSelect = document.getElementById('sortSelect');
        const filterBtns = document.querySelectorAll('.filter-btn');
        const playersGrid = document.getElementById('playersGrid');
        const playerCards = playersGrid.querySelectorAll('.player-card');
        const resultsCount = document.getElementById('resultsCount');
        const detailPanel = document.getElementById('detailPanel');
        
        let currentFilter = 'all';
        let currentSort = 'name-asc';
        let currentSearch = '';
        
        // Search with debouncing
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentSearch = this.value.toLowerCase();
                filterAndSort();
            }, 200);
        });
        
        // Filter buttons
        filterBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                filterBtns.forEach(b => b.classList.remove('active'));
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
        
        // Filter and sort function
        function filterAndSort() {
            let visibleCards = [];
            
            playerCards.forEach(card => {
                const matchesSearch = currentSearch === '' || 
                    card.dataset.search.includes(currentSearch);
                
                let matchesFilter = true;
                switch(currentFilter) {
                    case 'confirmed':
                        matchesFilter = card.dataset.filterType === 'confirmed';
                        break;
                    case 'unknown':
                        matchesFilter = card.dataset.filterType === 'unknown';
                        break;
                    case 'medical':
                        matchesFilter = card.dataset.hasMedical === 'true';
                        break;
                }
                
                if (matchesSearch && matchesFilter) {
                    card.style.display = 'block';
                    visibleCards.push(card);
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Sort visible cards
            visibleCards.sort((a, b) => {
                switch(currentSort) {
                    case 'name-desc':
                        return b.dataset.name.localeCompare(a.dataset.name);
                    case 'age-asc':
                        return parseInt(a.dataset.age) - parseInt(b.dataset.age);
                    case 'age-desc':
                        return parseInt(b.dataset.age) - parseInt(a.dataset.age);
                    default:
                        return a.dataset.name.localeCompare(b.dataset.name);
                }
            });
            
            // Reorder DOM
            visibleCards.forEach((card, index) => {
                card.style.order = index;
            });
            
            // Update count
            resultsCount.textContent = `${visibleCards.length} players shown`;
            searchInput.placeholder = `Search ${visibleCards.length} players...`;
        }
        
        // Player detail functions
        window.openPlayerDetail = function(index) {
            const card = document.querySelector(`[data-player-index="${index}"]`);
            if (!card) return;
            
            const playerDataScript = card.querySelector('.player-data');
            if (!playerDataScript) return;
            
            const playerData = JSON.parse(playerDataScript.textContent);
            
            // Update detail panel
            document.getElementById('detailTitle').textContent = playerData.name;
            document.getElementById('detailSubtitle').textContent = `${playerData.age ? 'Age ' + playerData.age : ''} ${playerData.gender ? '• ' + playerData.gender : ''}`;
            
            let detailHTML = '';
            
            // Contact Section
            if (playerData.email || playerData.phone) {
                detailHTML += '<div class="detail-section">';
                detailHTML += '<div class="section-title"><span class="section-icon">📞</span>Contact Information</div>';
                detailHTML += '<div class="detail-grid">';
                
                if (playerData.email && playerData.email !== 'N/A') {
                    detailHTML += `<div class="detail-item">
                        <span class="detail-label">Email</span>
                        <a href="mailto:${playerData.email}" class="detail-value contact-link">${playerData.email}</a>
                    </div>`;
                }
                
                if (playerData.phone && playerData.phone !== 'N/A') {
                    detailHTML += `<div class="detail-item">
                        <span class="detail-label">Phone</span>
                        <a href="tel:${playerData.phone}" class="detail-value contact-link">${playerData.phone}</a>
                    </div>`;
                }
                
                detailHTML += '</div></div>';
            }
            
            // Player Information Section
            detailHTML += '<div class="detail-section">';
            detailHTML += '<div class="section-title"><span class="section-icon">👤</span>Player Information</div>';
            detailHTML += '<div class="detail-grid">';
            
            if (playerData.age) {
                detailHTML += `<div class="detail-item">
                    <span class="detail-label">Age</span>
                    <span class="detail-value">${playerData.age} years old</span>
                </div>`;
            }
            
            if (playerData.gender) {
                detailHTML += `<div class="detail-item">
                    <span class="detail-label">Gender</span>
                    <span class="detail-value">${playerData.gender}</span>
                </div>`;
            }
            
            if (playerData.bookingType && playerData.bookingType !== 'N/A') {
                detailHTML += `<div class="detail-item">
                    <span class="detail-label">Booking Type</span>
                    <span class="detail-value">${playerData.bookingType}</span>
                </div>`;
            }
            
            if (playerData.latePickup) {
                detailHTML += `<div class="detail-item">
                    <span class="detail-label">Late Pickup</span>
                    <span class="detail-value" style="color: #ef4444; font-weight: 700;">Yes (18:00)</span>
                </div>`;
            }
            
            detailHTML += '</div></div>';
            
            // Medical Section
            if (playerData.medical && playerData.medical !== 'N/A') {
                detailHTML += '<div class="detail-section">';
                detailHTML += '<div class="section-title"><span class="section-icon">⚕️</span>Medical/Dietary Information</div>';
                detailHTML += `<div class="medical-alert">${playerData.medical}</div>`;
                detailHTML += '</div>';
            }
            
            // Girls Only Section
            if (playerData.isGirlsOnly && (playerData.shirtSize || playerData.shortsSize)) {
                detailHTML += '<div class="detail-section">';
                detailHTML += '<div class="section-title"><span class="section-icon">👕</span>Uniform Sizes</div>';
                detailHTML += '<div class="detail-grid">';
                
                if (playerData.shirtSize && playerData.shirtSize !== 'N/A') {
                    detailHTML += `<div class="detail-item">
                        <span class="detail-label">Shirt Size</span>
                        <span class="detail-value">${playerData.shirtSize}</span>
                    </div>`;
                }
                
                if (playerData.shortsSize && playerData.shortsSize !== 'N/A') {
                    detailHTML += `<div class="detail-item">
                        <span class="detail-label">Shorts Size</span>
                        <span class="detail-value">${playerData.shortsSize}</span>
                    </div>`;
                }
                
                detailHTML += '</div></div>';
            }
            
            // Attendance Section
            if (playerData.isCampLike && playerData.dayPresence && Object.keys(playerData.dayPresence).length > 0) {
                detailHTML += '<div class="detail-section">';
                detailHTML += '<div class="section-title"><span class="section-icon">📅</span>Weekly Attendance</div>';
                detailHTML += '<div class="attendance-grid-detail">';
                
                const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                days.forEach(day => {
                    const present = playerData.dayPresence[day] === 'Yes';
                    const dayShort = day.substring(0, 3);
                    const className = present ? 'day-present' : 'day-absent';
                    detailHTML += `<div class="attendance-day ${className}">${dayShort}</div>`;
                });
                
                detailHTML += '</div></div>';
            }
            
            document.getElementById('detailContent').innerHTML = detailHTML;
            
            // Mark card as selected
            playerCards.forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            
            // Show panel
            detailPanel.classList.add('open');
        };
        
        window.closePlayerDetail = function() {
            detailPanel.classList.remove('open');
            playerCards.forEach(c => c.classList.remove('selected'));
        };
        
        // Close panel on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && detailPanel.classList.contains('open')) {
                closePlayerDetail();
            }
            
            // Search shortcut
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                searchInput.focus();
            }
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