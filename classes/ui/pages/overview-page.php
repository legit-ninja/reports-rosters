<?php
/**
 * InterSoccer Overview Page
 * 
 * Main dashboard showing key statistics, charts, and system status.
 * This is the landing page that provides a comprehensive view of all InterSoccer activities.
 * 
 * @package InterSoccer_Reports_Rosters
 * @subpackage UI\Pages
 * @version 1.0.0
 */

namespace InterSoccer\ReportsRosters\UI\Pages;

use InterSoccer\Core\Logger;
use InterSoccer\UI\Components\ChartsComponent;
use InterSoccer\UI\Components\TableComponent;
use InterSoccer\Services\CacheManager;
use InterSoccer\Utils\DateHelper;

if (!defined('ABSPATH')) {
    exit;
}

class OverviewPage extends AbstractPage {

    /**
     * Charts component
     * 
     * @var ChartsComponent
     */
    private $charts;

    /**
     * Table component
     * 
     * @var TableComponent
     */
    private $table;

    /**
     * Cache manager
     * 
     * @var CacheManager
     */
    private $cache;

    /**
     * Constructor
     * 
     * @param Logger $logger   Logger instance
     * @param array  $services Services container
     */
    public function __construct(Logger $logger, array $services) {
        parent::__construct($logger, $services);
        
        $this->cache = $services['cache'];
        $this->charts = new ChartsComponent($logger);
        $this->table = new TableComponent($logger);
        
        $this->logger->debug('OverviewPage initialized');
    }

    /**
     * Render the overview page
     */
    public function render() {
        try {
            // Get dashboard data with caching
            $dashboard_data = $this->cache->remember(
                'overview_dashboard_data',
                [$this, 'get_dashboard_data'],
                'chart_data',
                900 // 15 minutes
            );
            
            ?>
            <div class="wrap intersoccer-overview">
                <?php $this->render_header(); ?>
                
                <div class="intersoccer-dashboard-grid">
                    <?php $this->render_quick_stats($dashboard_data['quick_stats']); ?>
                    <?php $this->render_charts_section($dashboard_data['charts']); ?>
                    <?php $this->render_recent_activity($dashboard_data['recent_activity']); ?>
                    <?php $this->render_system_status($dashboard_data['system_status']); ?>
                </div>
            </div>
            
            <style>
            .intersoccer-overview {
                background: #f1f1f1;
                margin: 0;
                padding: 0;
            }
            
            .intersoccer-overview h1 {
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                color: white;
                margin: 0 -20px 20px -20px;
                padding: 20px;
                font-size: 24px;
                font-weight: 600;
                text-shadow: 0 1px 2px rgba(0,0,0,0.2);
            }
            
            .intersoccer-dashboard-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
                padding: 0 20px;
            }
            
            .dashboard-card {
                background: white;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                border: 1px solid #e5e7eb;
            }
            
            .dashboard-card h2 {
                margin-top: 0;
                margin-bottom: 15px;
                color: #1f2937;
                font-size: 18px;
                font-weight: 600;
                border-bottom: 2px solid #3b82f6;
                padding-bottom: 8px;
            }
            
            .quick-stats {
                grid-column: 1 / -1;
            }
            
            .quick-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-top: 15px;
            }
            
            .stat-item {
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                padding: 20px;
                border-radius: 6px;
                text-align: center;
                border: 1px solid #e2e8f0;
            }
            
            .stat-number {
                font-size: 2.5em;
                font-weight: 700;
                color: #1e40af;
                margin: 0;
                line-height: 1;
            }
            
            .stat-label {
                color: #64748b;
                font-size: 14px;
                margin-top: 8px;
                font-weight: 500;
            }
            
            .charts-section {
                grid-column: 1 / -1;
            }
            
            .charts-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 20px;
                margin-top: 15px;
            }
            
            .chart-container {
                background: white;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 20px;
                min-height: 300px;
            }
            
            .chart-title {
                font-weight: 600;
                margin-bottom: 15px;
                color: #374151;
            }
            
            .recent-activity {
                grid-column: span 2;
            }
            
            .activity-list {
                max-height: 300px;
                overflow-y: auto;
            }
            
            .activity-item {
                display: flex;
                align-items: center;
                padding: 10px 0;
                border-bottom: 1px solid #f3f4f6;
            }
            
            .activity-item:last-child {
                border-bottom: none;
            }
            
            .activity-icon {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 12px;
                font-size: 14px;
                font-weight: bold;
            }
            
            .activity-camp {
                background: #dbeafe;
                color: #1d4ed8;
            }
            
            .activity-course {
                background: #dcfce7;
                color: #166534;
            }
            
            .activity-other {
                background: #fef3c7;
                color: #d97706;
            }
            
            .activity-content {
                flex: 1;
            }
            
            .activity-title {
                font-weight: 500;
                color: #111827;
            }
            
            .activity-meta {
                font-size: 13px;
                color: #6b7280;
                margin-top: 2px;
            }
            
            .system-status {
                grid-column: span 1;
            }
            
            .status-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #f3f4f6;
            }
            
            .status-item:last-child {
                border-bottom: none;
            }
            
            .status-label {
                font-weight: 500;
                color: #374151;
            }
            
            .status-value {
                font-size: 14px;
            }
            
            .status-good {
                color: #059669;
                font-weight: 600;
            }
            
            .status-warning {
                color: #d97706;
                font-weight: 600;
            }
            
            .status-error {
                color: #dc2626;
                font-weight: 600;
            }
            
            @media (max-width: 768px) {
                .intersoccer-dashboard-grid {
                    grid-template-columns: 1fr;
                    padding: 0 10px;
                }
                
                .quick-stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .charts-grid {
                    grid-template-columns: 1fr;
                }
                
                .recent-activity,
                .system-status {
                    grid-column: span 1;
                }
            }
            </style>
            <?php
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to render overview page', [
                'error' => $e->getMessage()
            ]);
            
            $this->render_error('Unable to load dashboard data. Please try refreshing the page.');
        }
    }

    /**
     * Render page header
     */
    private function render_header() {
        ?>
        <h1>
            <span class="dashicons dashicons-chart-bar" style="margin-right: 10px;"></span>
            <?php _e('InterSoccer Reports & Rosters - Overview', 'intersoccer-reports-rosters'); ?>
        </h1>
        <?php
    }

    /**
     * Render quick statistics section
     * 
     * @param array $stats Quick stats data
     */
    private function render_quick_stats($stats) {
        ?>
        <div class="dashboard-card quick-stats">
            <h2><?php _e('Quick Statistics', 'intersoccer-reports-rosters'); ?></h2>
            <div class="quick-stats-grid">
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($stats['total_attendees']); ?></div>
                    <div class="stat-label"><?php _e('Total Attendees', 'intersoccer-reports-rosters'); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($stats['active_events']); ?></div>
                    <div class="stat-label"><?php _e('Active Events', 'intersoccer-reports-rosters'); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($stats['total_venues']); ?></div>
                    <div class="stat-label"><?php _e('Venues', 'intersoccer-reports-rosters'); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo number_format($stats['this_week_registrations']); ?></div>
                    <div class="stat-label"><?php _e('This Week\'s Registrations', 'intersoccer-reports-rosters'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render charts section
     * 
     * @param array $charts_data Charts data
     */
    private function render_charts_section($charts_data) {
        ?>
        <div class="dashboard-card charts-section">
            <h2><?php _e('Analytics Overview', 'intersoccer-reports-rosters'); ?></h2>
            <div class="charts-grid">
                <div class="chart-container">
                    <h3 class="chart-title"><?php _e('Attendance by Venue', 'intersoccer-reports-rosters'); ?></h3>
                    <?php $this->charts->render_venue_chart($charts_data['venue_data']); ?>
                </div>
                <div class="chart-container">
                    <h3 class="chart-title"><?php _e('Age Group Distribution', 'intersoccer-reports-rosters'); ?></h3>
                    <?php $this->charts->render_age_distribution_chart($charts_data['age_data']); ?>
                </div>
                <div class="chart-container">
                    <h3 class="chart-title"><?php _e('Gender Distribution', 'intersoccer-reports-rosters'); ?></h3>
                    <?php $this->charts->render_gender_chart($charts_data['gender_data']); ?>
                </div>
                <div class="chart-container">
                    <h3 class="chart-title"><?php _e('Weekly Registration Trends', 'intersoccer-reports-rosters'); ?></h3>
                    <?php $this->charts->render_trends_chart($charts_data['trends_data']); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render recent activity section
     * 
     * @param array $activities Recent activities
     */
    private function render_recent_activity($activities) {
        ?>
        <div class="dashboard-card recent-activity">
            <h2><?php _e('Recent Activity', 'intersoccer-reports-rosters'); ?></h2>
            <div class="activity-list">
                <?php if (empty($activities)): ?>
                    <p style="color: #6b7280; font-style: italic; text-align: center; padding: 20px;">
                        <?php _e('No recent activity to display', 'intersoccer-reports-rosters'); ?>
                    </p>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon activity-<?php echo esc_attr(strtolower($activity['type'])); ?>">
                                <?php echo substr($activity['type'], 0, 1); ?>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?php echo esc_html($activity['title']); ?></div>
                                <div class="activity-meta">
                                    <?php echo esc_html($activity['venue']); ?> • 
                                    <?php echo esc_html($activity['attendees']); ?> attendees • 
                                    <?php echo esc_html(DateHelper::format_date_range($activity['start_date'], $activity['end_date'], 'short')); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render system status section
     * 
     * @param array $status System status data
     */
    private function render_system_status($status) {
        ?>
        <div class="dashboard-card system-status">
            <h2><?php _e('System Status', 'intersoccer-reports-rosters'); ?></h2>
            
            <div class="status-item">
                <span class="status-label"><?php _e('Database', 'intersoccer-reports-rosters'); ?></span>
                <span class="status-value <?php echo $status['database']['status'] === 'good' ? 'status-good' : 'status-error'; ?>">
                    <?php echo $status['database']['status'] === 'good' ? __('Connected', 'intersoccer-reports-rosters') : __('Error', 'intersoccer-reports-rosters'); ?>
                </span>
            </div>
            
            <div class="status-item">
                <span class="status-label"><?php _e('Cache Backend', 'intersoccer-reports-rosters'); ?></span>
                <span class="status-value status-good"><?php echo ucfirst($status['cache']['backend']); ?></span>
            </div>
            
            <div class="status-item">
                <span class="status-label"><?php _e('WooCommerce', 'intersoccer-reports-rosters'); ?></span>
                <span class="status-value <?php echo $status['woocommerce']['active'] ? 'status-good' : 'status-error'; ?>">
                    <?php echo $status['woocommerce']['active'] ? $status['woocommerce']['version'] : __('Inactive', 'intersoccer-reports-rosters'); ?>
                </span>
            </div>
            
            <div class="status-item">
                <span class="status-label"><?php _e('Total Cache Size', 'intersoccer-reports-rosters'); ?></span>
                <span class="status-value status-good"><?php echo size_format($status['cache']['total_size']); ?></span>
            </div>
            
            <div class="status-item">
                <span class="status-label"><?php _e('Last Data Sync', 'intersoccer-reports-rosters'); ?></span>
                <span class="status-value <?php echo $status['last_sync']['age'] > 3600 ? 'status-warning' : 'status-good'; ?>">
                    <?php echo $status['last_sync']['formatted']; ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * Get dashboard data
     * 
     * @return array Dashboard data for charts and stats
     */
    public function get_dashboard_data() {
        global $wpdb;
        
        try {
            $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
            
            // Quick stats
            $quick_stats = $this->get_quick_stats($rosters_table);
            
            // Charts data
            $charts_data = $this->get_charts_data($rosters_table);
            
            // Recent activity
            $recent_activity = $this->get_recent_activity($rosters_table);
            
            // System status
            $system_status = $this->get_system_status();
            
            $this->logger->debug('Dashboard data retrieved successfully');
            
            return [
                'quick_stats' => $quick_stats,
                'charts' => $charts_data,
                'recent_activity' => $recent_activity,
                'system_status' => $system_status,
                'generated_at' => time()
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get dashboard data', [
                'error' => $e->getMessage()
            ]);
            
            // Return default data structure
            return [
                'quick_stats' => ['total_attendees' => 0, 'active_events' => 0, 'total_venues' => 0, 'this_week_registrations' => 0],
                'charts' => ['venue_data' => [], 'age_data' => [], 'gender_data' => [], 'trends_data' => []],
                'recent_activity' => [],
                'system_status' => ['database' => ['status' => 'error'], 'cache' => ['backend' => 'unknown', 'total_size' => 0], 'woocommerce' => ['active' => false], 'last_sync' => ['formatted' => 'Unknown', 'age' => 0]],
                'generated_at' => time()
            ];
        }
    }

    /**
     * Get quick statistics
     * 
     * @param string $table_name Rosters table name
     * 
     * @return array Quick stats
     */
    private function get_quick_stats($table_name) {
        global $wpdb;
        
        // Total attendees
        $total_attendees = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        
        // Active events (current and future)
        $active_events = $wpdb->get_var(
            "SELECT COUNT(DISTINCT product_variation_id) FROM {$table_name} 
             WHERE end_date >= CURDATE()"
        );
        
        // Total venues
        $total_venues = $wpdb->get_var(
            "SELECT COUNT(DISTINCT venue) FROM {$table_name} 
             WHERE venue != '' AND venue IS NOT NULL"
        );
        
        // This week's registrations
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));
        
        $this_week_registrations = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE DATE(created_at) BETWEEN %s AND %s",
            $week_start,
            $week_end
        ));
        
        return [
            'total_attendees' => intval($total_attendees),
            'active_events' => intval($active_events),
            'total_venues' => intval($total_venues),
            'this_week_registrations' => intval($this_week_registrations)
        ];
    }
        
    /**
     * Get charts data
     * 
     * @param string $table_name Rosters table name
     * 
     * @return array Charts data
     */
    private function get_charts_data($table_name) {
        global $wpdb;
        
        // Venue data
        $venue_data = $wpdb->get_results(
            "SELECT venue, COUNT(*) as count 
             FROM {$table_name} 
             WHERE venue != '' AND venue IS NOT NULL 
             GROUP BY venue 
             ORDER BY count DESC 
             LIMIT 10",
            ARRAY_A
        );
        
        // Age distribution
        $age_data = $wpdb->get_results(
            "SELECT age_group, COUNT(*) as count 
             FROM {$table_name} 
             WHERE age_group != '' AND age_group != 'N/A' 
             GROUP BY age_group 
             ORDER BY count DESC",
            ARRAY_A
        );
        
        // Gender distribution (ordered: male, female, other)
        $gender_data = $wpdb->get_results(
            "SELECT gender, COUNT(*) as count 
             FROM {$table_name} 
             WHERE gender != '' AND gender != 'N/A' 
             GROUP BY gender 
             ORDER BY 
                CASE gender 
                    WHEN 'male' THEN 1 
                    WHEN 'female' THEN 2 
                    ELSE 3 
                END",
            ARRAY_A
        );
        
        // Weekly trends (last 12 weeks)
        $twelve_weeks_ago = date('Y-m-d', strtotime('-12 weeks'));
        $trends_data = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(start_date, '%%Y-%%u') as week, COUNT(*) as count 
             FROM {$table_name} 
             WHERE start_date >= %s 
             GROUP BY week 
             ORDER BY week ASC",
            $twelve_weeks_ago
        ), ARRAY_A);
        
        return [
            'venue_data' => $venue_data ?: [],
            'age_data' => $age_data ?: [],
            'gender_data' => $gender_data ?: [],
            'trends_data' => $trends_data ?: []
        ];
    }

    /**
     * Get recent activity
     * 
     * @param string $table_name Rosters table name
     * 
     * @return array Recent activities
     */
    private function get_recent_activity($table_name) {
        global $wpdb;
        
        $activities = $wpdb->get_results(
            "SELECT DISTINCT 
                event_type as type,
                season,
                venue,
                start_date,
                end_date,
                COUNT(*) as attendees
             FROM {$table_name} 
             WHERE start_date >= CURDATE() - INTERVAL 30 DAY
             GROUP BY event_type, season, venue, start_date, end_date
             ORDER BY start_date DESC 
             LIMIT 8",
            ARRAY_A
        );
        
        $formatted_activities = [];
        
        foreach ($activities as $activity) {
            $title = $this->format_activity_title($activity);
            
            $formatted_activities[] = [
                'type' => ucfirst($activity['type']),
                'title' => $title,
                'venue' => $activity['venue'],
                'attendees' => intval($activity['attendees']),
                'start_date' => $activity['start_date'],
                'end_date' => $activity['end_date']
            ];
        }
        
        return $formatted_activities;
    }

    /**
     * Format activity title
     * 
     * @param array $activity Activity data
     * 
     * @return string Formatted title
     */
    private function format_activity_title($activity) {
        $type = ucfirst($activity['type']);
        $season = $activity['season'];
        
        if ($activity['type'] === 'camp') {
            return "{$season} {$type}";
        } elseif ($activity['type'] === 'course') {
            return "{$season} {$type}";
        } else {
            return "{$type} Event";
        }
    }

    /**
     * Get system status
     * 
     * @return array System status information
     */
    private function get_system_status() {
        global $wpdb;
        
        // Database status
        $db_status = 'good';
        try {
            $wpdb->get_var("SELECT 1");
        } catch (\Exception $e) {
            $db_status = 'error';
        }
        
        // Cache status
        $cache_stats = $this->cache->get_stats();
        
        // WooCommerce status
        $woocommerce_active = class_exists('WooCommerce');
        $woocommerce_version = $woocommerce_active ? WC()->version : '';
        
        // Last sync time
        $last_sync = get_option('intersoccer_last_data_sync', 0);
        $sync_age = time() - $last_sync;
        
        if ($sync_age < 3600) {
            $sync_formatted = sprintf(__('%d minutes ago', 'intersoccer-reports-rosters'), floor($sync_age / 60));
        } elseif ($sync_age < 86400) {
            $sync_formatted = sprintf(__('%d hours ago', 'intersoccer-reports-rosters'), floor($sync_age / 3600));
        } else {
            $sync_formatted = sprintf(__('%d days ago', 'intersoccer-reports-rosters'), floor($sync_age / 86400));
        }
        
        return [
            'database' => [
                'status' => $db_status
            ],
            'cache' => [
                'backend' => $cache_stats['backend'],
                'total_size' => $cache_stats['total_size']
            ],
            'woocommerce' => [
                'active' => $woocommerce_active,
                'version' => $woocommerce_version
            ],
            'last_sync' => [
                'timestamp' => $last_sync,
                'age' => $sync_age,
                'formatted' => $sync_formatted
            ]
        ];
    }

    /**
     * Enqueue page-specific assets
     */
    public function enqueue_assets() {
        // Chart.js for dashboard charts
        wp_enqueue_script(
            'chart-js',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js',
            [],
            '3.9.1',
            true
        );
        
        // Overview page specific JavaScript
        wp_enqueue_script(
            'intersoccer-overview',
            plugin_dir_url(__FILE__) . '../../../assets/js/overview-charts.js',
            ['chart-js', 'jquery'],
            '1.5.0',
            true
        );
        
        // Localize script with chart data and settings
        $chart_data = $this->cache->get('overview_dashboard_data', 'chart_data');
        
        wp_localize_script('intersoccer-overview', 'intersoccer_overview', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('intersoccer_overview_nonce'),
            'chart_data' => $chart_data['charts'] ?? [],
            'refresh_interval' => 300000, // 5 minutes
            'i18n' => [
                'loading' => __('Loading...', 'intersoccer-reports-rosters'),
                'error' => __('Error loading data', 'intersoccer-reports-rosters'),
                'no_data' => __('No data available', 'intersoccer-reports-rosters')
            ]
        ]);
        
        $this->logger->debug('Overview page assets enqueued');
    }

    /**
     * Handle AJAX request for refreshing dashboard data
     */
    public function ajax_refresh_dashboard() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'intersoccer_overview_nonce')) {
            wp_die('Security check failed', 'Unauthorized', ['response' => 403]);
        }
        
        // Check permissions
        if (!current_user_can('read')) {
            wp_die('Insufficient permissions', 'Unauthorized', ['response' => 403]);
        }
        
        try {
            // Clear cache and get fresh data
            $this->cache->delete('overview_dashboard_data', 'chart_data');
            $dashboard_data = $this->get_dashboard_data();
            
            wp_send_json_success([
                'data' => $dashboard_data,
                'message' => __('Dashboard data refreshed successfully', 'intersoccer-reports-rosters')
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX dashboard refresh failed', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);
            
            wp_send_json_error([
                'message' => __('Failed to refresh dashboard data', 'intersoccer-reports-rosters')
            ]);
        }
    }

    /**
     * Get page configuration for menu system
     * 
     * @return array Page configuration
     */
    public function get_page_config() {
        return [
            'title' => __('Overview', 'intersoccer-reports-rosters'),
            'capability' => 'read',
            'menu_slug' => 'intersoccer-reports-rosters',
            'has_charts' => true,
            'refresh_interval' => 900, // 15 minutes
            'cache_groups' => ['chart_data', 'roster_data']
        ];
    }
}