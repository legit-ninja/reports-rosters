<?php
/**
 * InterSoccer Camps Page
 * 
 * Displays and manages camp rosters with filtering, sorting, and export capabilities.
 * Handles both full-week and single-day camp bookings.
 * 
 * @package InterSoccer_Reports_Rosters
 * @subpackage UI\Pages
 * @version 1.0.0
 */

namespace InterSoccer\ReportsRosters\UI\Pages;

use InterSoccer\Core\Logger;
use InterSoccer\UI\Components\TableComponent;
use InterSoccer\UI\Components\ExportComponent;
use InterSoccer\Services\CacheManager;
use InterSoccer\Utils\DateHelper;
use InterSoccer\Utils\ValidationHelper;

if (!defined('ABSPATH')) {
    exit;
}

class CampsPage extends AbstractPage {

    /**
     * Table component
     * 
     * @var TableComponent
     */
    private $table;

    /**
     * Export component
     * 
     * @var ExportComponent
     */
    private $export;

    /**
     * Cache manager
     * 
     * @var CacheManager
     */
    private $cache;

    /**
     * Available filters
     * 
     * @var array
     */
    private $available_filters = [];

    /**
     * Constructor
     * 
     * @param Logger $logger   Logger instance
     * @param array  $services Services container
     */
    public function __construct(Logger $logger, array $services) {
        parent::__construct($logger, $services);
        
        $this->cache = $services['cache'];
        $this->table = new TableComponent($logger);
        $this->export = new ExportComponent($logger, $services);
        
        $this->setup_filters();
        
        $this->logger->debug('CampsPage initialized');
    }

    /**
     * Get page-specific configuration
     * 
     * @return array Page configuration
     */
    protected function get_page_config() {
        return [
            'title' => __('Camps', 'intersoccer-reports-rosters'),
            'capability' => 'read',
            'show_filters' => true,
            'show_export' => true,
            'per_page' => 100,
            'allow_sorting' => true,
            'cache_duration' => 1800 // 30 minutes
        ];
    }

    /**
     * Render the camps page
     */
    public function render() {
        try {
            // Check permissions
            $this->check_permission('read');
            
            // Handle form submissions
            $this->handle_form_submissions();
            
            // Get current filters
            $current_filters = $this->get_current_filters();
            
            // Get camps data with caching
            $cache_key = $this->get_cache_key('camps_' . md5(serialize($current_filters)));
            $camps_data = $this->cache->remember(
                $cache_key,
                function() use ($current_filters) {
                    return $this->get_camps_data($current_filters);
                },
                'roster_data',
                $this->config['cache_duration']
            );
            
            ?>
            <div class="wrap intersoccer-camps">
                <?php 
                $this->render_header(
                    __('Camps Rosters', 'intersoccer-reports-rosters'),
                    __('Manage and export camp attendance rosters for all InterSoccer camp events.', 'intersoccer-reports-rosters'),
                    $this->get_header_actions()
                ); 
                ?>
                
                <?php $this->render_messages(); ?>
                
                <div class="camps-content">
                    <?php $this->render_camps_stats($camps_data['stats']); ?>
                    
                    <div class="camps-main-section">
                        <?php $this->render_filters_section($current_filters); ?>
                        <?php $this->render_camps_table($camps_data['camps'], $current_filters); ?>
                    </div>
                </div>
            </div>
            
            <style>
            .camps-content {
                display: grid;
                grid-template-columns: 1fr;
                gap: 20px;
                margin-top: 20px;
            }
            
            .camps-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .stat-card {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                text-align: center;
                border-left: 4px solid #3b82f6;
            }
            
            .stat-number {
                font-size: 2em;
                font-weight: 700;
                color: #1e40af;
                margin: 0;
            }
            
            .stat-label {
                color: #64748b;
                margin-top: 5px;
                font-weight: 500;
            }
            
            .camps-main-section {
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            .filters-section {
                background: #f8fafc;
                padding: 20px;
                border-bottom: 1px solid #e5e7eb;
            }
            
            .filters-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                align-items: end;
            }
            
            .filter-group {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            
            .filter-group label {
                font-weight: 500;
                color: #374151;
                font-size: 14px;
            }
            
            .filter-group select,
            .filter-group input {
                padding: 8px 12px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                font-size: 14px;
            }
            
            .filter-actions {
                display: flex;
                gap: 10px;
            }
            
            .camps-table-section {
                padding: 20px;
            }
            
            .table-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .table-info {
                color: #6b7280;
                font-size: 14px;
            }
            
            .table-actions {
                display: flex;
                gap: 10px;
            }
            
            .camps-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 14px;
            }
            
            .camps-table th {
                background: #f9fafb;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                color: #374151;
                border-bottom: 2px solid #e5e7eb;
                position: sticky;
                top: 0;
                z-index: 10;
            }
            
            .camps-table td {
                padding: 12px;
                border-bottom: 1px solid #f3f4f6;
                vertical-align: top;
            }
            
            .camps-table tbody tr:hover {
                background: #f9fafb;
            }
            
            .camp-type-badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 500;
            }
            
            .camp-type-full-week {
                background: #dbeafe;
                color: #1e40af;
            }
            
            .camp-type-single-day {
                background: #fef3c7;
                color: #d97706;
            }
            
            .camp-type-girls-only {
                background: #fce7f3;
                color: #be185d;
            }
            
            .player-info {
                font-weight: 500;
                color: #111827;
            }
            
            .player-details {
                font-size: 13px;
                color: #6b7280;
                margin-top: 2px;
            }
            
            .venue-info {
                max-width: 200px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .medical-info {
                max-width: 150px;
                font-size: 12px;
            }
            
            .medical-none {
                color: #9ca3af;
                font-style: italic;
            }
            
            .medical-conditions {
                color: #dc2626;
                font-weight: 500;
            }
            
            .contact-info {
                font-size: 13px;
            }
            
            .no-camps-message {
                text-align: center;
                padding: 60px 20px;
                color: #6b7280;
                background: #f9fafb;
                border-radius: 8px;
                margin: 20px;
            }
            
            .no-camps-icon {
                font-size: 48px;
                margin-bottom: 16px;
                opacity: 0.5;
            }
            
            @media (max-width: 768px) {
                .filters-grid {
                    grid-template-columns: 1fr;
                }
                
                .table-header {
                    flex-direction: column;
                    align-items: stretch;
                }
                
                .camps-table {
                    font-size: 12px;
                }
                
                .camps-table th,
                .camps-table td {
                    padding: 8px;
                }
            }
            </style>
            <?php
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to render camps page', [
                'error' => $e->getMessage(),
                'user_id' => $this->current_user_id
            ]);
            
            $this->render_error('Unable to load camps data. Please try refreshing the page.');
        }
    }

    /**
     * Get header actions
     * 
     * @return array Header action buttons
     */
    private function get_header_actions() {
        $actions = [];
        
        // Export action
        $actions[] = [
            'label' => __('Export Camps', 'intersoccer-reports-rosters'),
            'url' => '#',
            'class' => 'button-primary intersoccer-export-camps',
            'icon' => 'dashicons-download'
        ];
        
        // Refresh data action
        $actions[] = [
            'label' => __('Refresh Data', 'intersoccer-reports-rosters'),
            'url' => admin_url('admin.php?page=intersoccer-camps&refresh=1'),
            'class' => 'button-secondary',
            'icon' => 'dashicons-update'
        ];
        
        return $actions;
    }

    /**
     * Setup available filters
     */
    private function setup_filters() {
        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        
        try {
            // Get unique seasons for camps
            $seasons = $wpdb->get_col(
                "SELECT DISTINCT season FROM {$rosters_table} 
                 WHERE event_type = 'camp' AND season != '' 
                 ORDER BY season DESC"
            );
            
            // Get unique venues for camps
            $venues = $wpdb->get_col(
                "SELECT DISTINCT venue FROM {$rosters_table} 
                 WHERE event_type = 'camp' AND venue != '' 
                 ORDER BY venue ASC"
            );
            
            // Get unique age groups for camps
            $age_groups = $wpdb->get_col(
                "SELECT DISTINCT age_group FROM {$rosters_table} 
                 WHERE event_type = 'camp' AND age_group != '' AND age_group != 'N/A'
                 ORDER BY age_group ASC"
            );
            
            $this->available_filters = [
                'season' => $seasons ?: [],
                'venue' => $venues ?: [],
                'age_group' => $age_groups ?: [],
                'booking_type' => ['Full Week', 'Single Day(s)'],
                'gender' => ['male', 'female', 'other']
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to setup filters', [
                'error' => $e->getMessage()
            ]);
            
            $this->available_filters = [
                'season' => [],
                'venue' => [],
                'age_group' => [],
                'booking_type' => ['Full Week', 'Single Day(s)'],
                'gender' => ['male', 'female', 'other']
            ];
        }
    }

    /**
     * Get current filter values
     * 
     * @return array Current filters
     */
    private function get_current_filters() {
        return [
            'season' => $this->get_request_param('season', ''),
            'venue' => $this->get_request_param('venue', ''),
            'age_group' => $this->get_request_param('age_group', ''),
            'booking_type' => $this->get_request_param('booking_type', ''),
            'gender' => $this->get_request_param('gender', ''),
            'search' => $this->get_request_param('search', ''),
            'date_from' => $this->get_request_param('date_from', ''),
            'date_to' => $this->get_request_param('date_to', ''),
            'medical_only' => $this->get_request_param('medical_only', false, 'bool')
        ];
    }

    /**
     * Handle form submissions
     */
    private function handle_form_submissions() {
        // Handle refresh request
        if (isset($_GET['refresh'])) {
            // Clear caches
            $this->cache->clear_group('roster_data');
            $this->add_message(__('Camp data refreshed successfully.', 'intersoccer-reports-rosters'), 'success');
        }
        
        // Handle export request
        if (isset($_POST['export_camps'])) {
            if ($this->verify_nonce('intersoccer_export_nonce', 'intersoccer_export_camps')) {
                $this->handle_export_request();
            }
        }
    }

    /**
     * Handle export request
     */
    private function handle_export_request() {
        try {
            $export_format = $this->get_request_param('export_format', 'excel');
            $current_filters = $this->get_current_filters();
            
            // Get camps data for export
            $camps_data = $this->get_camps_data($current_filters);
            
            // Use export component
            $export_result = $this->export->export_camps($camps_data['camps'], $export_format);
            
            if ($export_result['success']) {
                // Force download
                header('Content-Type: ' . $export_result['content_type']);
                header('Content-Disposition: attachment; filename="' . $export_result['filename'] . '"');
                header('Content-Length: ' . strlen($export_result['content']));
                
                echo $export_result['content'];
                exit;
            } else {
                $this->add_message($export_result['error'], 'error');
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Export failed', [
                'error' => $e->getMessage(),
                'user_id' => $this->current_user_id
            ]);
            
            $this->add_message(__('Export failed. Please try again.', 'intersoccer-reports-rosters'), 'error');
        }
    }

    /**
     * Render camps statistics
     * 
     * @param array $stats Statistics data
     */
    private function render_camps_stats($stats) {
        ?>
        <div class="camps-stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_camps']); ?></div>
                <div class="stat-label"><?php _e('Total Camps', 'intersoccer-reports-rosters'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_attendees']); ?></div>
                <div class="stat-label"><?php _e('Total Attendees', 'intersoccer-reports-rosters'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['active_camps']); ?></div>
                <div class="stat-label"><?php _e('Active Camps', 'intersoccer-reports-rosters'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['unique_venues']); ?></div>
                <div class="stat-label"><?php _e('Venues', 'intersoccer-reports-rosters'); ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render filters section
     * 
     * @param array $current_filters Current filter values
     */
    private function render_filters_section($current_filters) {
        ?>
        <div class="filters-section">
            <form method="get" action="<?php echo admin_url('admin.php'); ?>" class="camps-filters-form">
                <input type="hidden" name="page" value="intersoccer-camps">
                
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="season"><?php _e('Season', 'intersoccer-reports-rosters'); ?></label>
                        <select name="season" id="season">
                            <option value=""><?php _e('All Seasons', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($this->available_filters['season'] as $season): ?>
                                <option value="<?php echo esc_attr($season); ?>" <?php selected($current_filters['season'], $season); ?>>
                                    <?php echo esc_html($season); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="venue"><?php _e('Venue', 'intersoccer-reports-rosters'); ?></label>
                        <select name="venue" id="venue">
                            <option value=""><?php _e('All Venues', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($this->available_filters['venue'] as $venue): ?>
                                <option value="<?php echo esc_attr($venue); ?>" <?php selected($current_filters['venue'], $venue); ?>>
                                    <?php echo esc_html($venue); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="age_group"><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></label>
                        <select name="age_group" id="age_group">
                            <option value=""><?php _e('All Age Groups', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($this->available_filters['age_group'] as $age_group): ?>
                                <option value="<?php echo esc_attr($age_group); ?>" <?php selected($current_filters['age_group'], $age_group); ?>>
                                    <?php echo esc_html($age_group); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="booking_type"><?php _e('Booking Type', 'intersoccer-reports-rosters'); ?></label>
                        <select name="booking_type" id="booking_type">
                            <option value=""><?php _e('All Types', 'intersoccer-reports-rosters'); ?></option>
                            <?php foreach ($this->available_filters['booking_type'] as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($current_filters['booking_type'], $type); ?>>
                                    <?php echo esc_html($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="search"><?php _e('Search', 'intersoccer-reports-rosters'); ?></label>
                        <input type="text" name="search" id="search" placeholder="<?php _e('Player name, email...', 'intersoccer-reports-rosters'); ?>" 
                               value="<?php echo esc_attr($current_filters['search']); ?>">
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="button button-primary">
                            <?php _e('Filter', 'intersoccer-reports-rosters'); ?>
                        </button>
                        <a href="<?php echo admin_url('admin.php?page=intersoccer-camps'); ?>" class="button button-secondary">
                            <?php _e('Clear', 'intersoccer-reports-rosters'); ?>
                        </a>
                    </div>
                </div>
                
                <div style="margin-top: 15px;">
                    <label>
                        <input type="checkbox" name="medical_only" value="1" <?php checked($current_filters['medical_only']); ?>>
                        <?php _e('Show only attendees with medical conditions', 'intersoccer-reports-rosters'); ?>
                    </label>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render camps table
     * 
     * @param array $camps   Camps data
     * @param array $filters Current filters
     */
    private function render_camps_table($camps, $filters) {
        ?>
        <div class="camps-table-section">
            <div class="table-header">
                <div class="table-info">
                    <?php printf(
                        __('Showing %d camps with %d total attendees', 'intersoccer-reports-rosters'),
                        count($camps),
                        array_sum(array_column($camps, 'attendee_count'))
                    ); ?>
                </div>
                <div class="table-actions">
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('intersoccer_export_camps', 'intersoccer_export_nonce'); ?>
                        <input type="hidden" name="export_camps" value="1">
                        <select name="export_format">
                            <option value="excel">Excel (.xlsx)</option>
                            <option value="csv">CSV (.csv)</option>
                        </select>
                        <button type="submit" class="button button-secondary">
                            <span class="dashicons dashicons-download"></span>
                            <?php _e('Export', 'intersoccer-reports-rosters'); ?>
                        </button>
                    </form>
                </div>
            </div>
            
            <?php if (empty($camps)): ?>
                <div class="no-camps-message">
                    <div class="no-camps-icon">🏕️</div>
                    <h3><?php _e('No Camps Found', 'intersoccer-reports-rosters'); ?></h3>
                    <p><?php _e('No camps match your current filters. Try adjusting your search criteria.', 'intersoccer-reports-rosters'); ?></p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="camps-table">
                        <thead>
                            <tr>
                                <th><?php _e('Camp Details', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Attendee', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Age Group', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Venue', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Medical/Dietary', 'intersoccer-reports-rosters'); ?></th>
                                <th><?php _e('Contact', 'intersoccer-reports-rosters'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($camps as $camp): ?>
                                <tr>
                                    <td>
                                        <div class="camp-info">
                                            <strong><?php echo esc_html($camp['season']); ?> Camp</strong>
                                            <div class="camp-details">
                                                <?php echo esc_html(DateHelper::format_date_range($camp['start_date'], $camp['end_date'], 'medium')); ?>
                                            </div>
                                            <span class="camp-type-badge camp-type-<?php echo esc_attr(strtolower(str_replace(['(', ')', ' '], ['', '', '-'], $camp['booking_type']))); ?>">
                                                <?php echo esc_html($camp['booking_type']); ?>
                                            </span>
                                            <?php if (!empty($camp['selected_days'])): ?>
                                                <div class="selected-days">
                                                    <small><?php printf(__('Days: %s', 'intersoccer-reports-rosters'), esc_html($camp['selected_days'])); ?></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="player-info">
                                            <?php echo esc_html($camp['first_name'] . ' ' . $camp['last_name']); ?>
                                        </div>
                                        <div class="player-details">
                                            <?php if (!empty($camp['dob'])): ?>
                                                <?php printf(__('Age: %d', 'intersoccer-reports-rosters'), DateHelper::get_age($camp['dob'])); ?><br>
                                            <?php endif; ?>
                                            <?php if (!empty($camp['gender'])): ?>
                                                <?php echo ucfirst(esc_html($camp['gender'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="age-group-badge">
                                            <?php echo esc_html($camp['age_group']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="venue-info">
                                            <?php echo esc_html($camp['venue']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="medical-info">
                                            <?php if (empty($camp['medical_conditions']) || strtolower($camp['medical_conditions']) === 'none'): ?>
                                                <span class="medical-none"><?php _e('None', 'intersoccer-reports-rosters'); ?></span>
                                            <?php else: ?>
                                                <span class="medical-conditions">
                                                    <?php echo esc_html($camp['medical_conditions']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="contact-info">
                                            <?php if (!empty($camp['parent_email'])): ?>
                                                <a href="mailto:<?php echo esc_attr($camp['parent_email']); ?>">
                                                    <?php echo esc_html($camp['parent_email']); ?>
                                                </a><br>
                                            <?php endif; ?>
                                            <?php if (!empty($camp['parent_phone'])): ?>
                                                <a href="tel:<?php echo esc_attr($camp['parent_phone']); ?>">
                                                    <?php echo esc_html($camp['parent_phone']); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get camps data with filtering and sorting
     * 
     * @param array $filters Filter parameters
     * 
     * @return array Camps data with statistics
     */
    public function get_camps_data($filters = []) {
        global $wpdb;
        
        try {
            $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
            
            // Build WHERE clause
            $where_conditions = ["event_type = 'camp'"];
            $where_values = [];
            
            if (!empty($filters['season'])) {
                $where_conditions[] = "season = %s";
                $where_values[] = $filters['season'];
            }
            
            if (!empty($filters['venue'])) {
                $where_conditions[] = "venue = %s";
                $where_values[] = $filters['venue'];
            }
            
            if (!empty($filters['age_group'])) {
                $where_conditions[] = "age_group = %s";
                $where_values[] = $filters['age_group'];
            }
            
            if (!empty($filters['booking_type'])) {
                $where_conditions[] = "booking_type = %s";
                $where_values[] = $filters['booking_type'];
            }
            
            if (!empty($filters['gender'])) {
                $where_conditions[] = "gender = %s";
                $where_values[] = $filters['gender'];
            }
            
            if (!empty($filters['search'])) {
                $where_conditions[] = "(first_name LIKE %s OR last_name LIKE %s OR parent_email LIKE %s)";
                $search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
                $where_values[] = $search_term;
                $where_values[] = $search_term;
                $where_values[] = $search_term;
            }
            
            if (!empty($filters['date_from'])) {
                $where_conditions[] = "start_date >= %s";
                $where_values[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where_conditions[] = "end_date <= %s";
                $where_values[] = $filters['date_to'];
            }
            
            if (!empty($filters['medical_only'])) {
                $where_conditions[] = "medical_conditions != '' AND medical_conditions != 'None' AND medical_conditions IS NOT NULL";
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            // Get camps data
            $query = "SELECT * FROM {$rosters_table} WHERE {$where_clause} ORDER BY start_date DESC, venue ASC, last_name ASC";
            
            if (!empty($where_values)) {
                $query = $wpdb->prepare($query, $where_values);
            }
            
            $camps = $wpdb->get_results($query, ARRAY_A);
            
            // Calculate statistics
            $stats = [
                'total_camps' => count(array_unique(array_column($camps, 'product_variation_id'))),
                'total_attendees' => count($camps),
                'active_camps' => count(array_filter($camps, function($camp) {
                    return strtotime($camp['end_date']) >= time();
                })),
                'unique_venues' => count(array_unique(array_column($camps, 'venue')))
            ];
            
            $this->logger->debug('Camps data retrieved', [
                'total_records' => count($camps),
                'filters_applied' => array_filter($filters),
                'stats' => $stats
            ]);
            
            return [
                'camps' => $camps,
                'stats' => $stats,
                'filters' => $filters,
                'generated_at' => time()
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to get camps data', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            
            return [
                'camps' => [],
                'stats' => ['total_camps' => 0, 'total_attendees' => 0, 'active_camps' => 0, 'unique_venues' => 0],
                'filters' => $filters,
                'generated_at' => time()
            ];
        }
    }

    /**
     * Enqueue page-specific assets
     */
    public function enqueue_assets() {
        parent::enqueue_assets();
        
        // Camps-specific JavaScript
        wp_enqueue_script(
            'intersoccer-camps',
            plugin_dir_url(__FILE__) . '../../../assets/js/camps.js',
            ['jquery', 'intersoccer-common'],
            '1.5.0',
            true
        );
        
        // Localize script for AJAX functionality
        wp_localize_script('intersoccer-camps', 'intersoccer_camps', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('intersoccer_camps_nonce'),
            'page_url' => admin_url('admin.php?page=intersoccer-camps'),
            'i18n' => [
                'loading' => __('Loading camps data...', 'intersoccer-reports-rosters'),
                'export_loading' => __('Preparing export...', 'intersoccer-reports-rosters'),
                'export_success' => __('Export completed successfully', 'intersoccer-reports-rosters'),
                'export_error' => __('Export failed. Please try again.', 'intersoccer-reports-rosters'),
                'filter_loading' => __('Applying filters...', 'intersoccer-reports-rosters'),
                'no_results' => __('No camps found matching your criteria', 'intersoccer-reports-rosters'),
                'confirm_refresh' => __('This will refresh all camp data. Continue?', 'intersoccer-reports-rosters')
            ]
        ]);
        
        $this->logger->debug('Camps page assets enqueued');
    }

    /**
     * Handle AJAX request for dynamic filtering
     */
    public function ajax_filter_camps() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'intersoccer_camps_nonce')) {
            wp_die('Security check failed', 'Unauthorized', ['response' => 403]);
        }
        
        // Check permissions
        if (!current_user_can('read')) {
            wp_die('Insufficient permissions', 'Unauthorized', ['response' => 403]);
        }
        
        try {
            // Get filter parameters
            $filters = [
                'season' => sanitize_text_field($_POST['season'] ?? ''),
                'venue' => sanitize_text_field($_POST['venue'] ?? ''),
                'age_group' => sanitize_text_field($_POST['age_group'] ?? ''),
                'booking_type' => sanitize_text_field($_POST['booking_type'] ?? ''),
                'gender' => sanitize_text_field($_POST['gender'] ?? ''),
                'search' => sanitize_text_field($_POST['search'] ?? ''),
                'medical_only' => (bool) ($_POST['medical_only'] ?? false)
            ];
            
            // Get filtered data
            $camps_data = $this->get_camps_data($filters);
            
            wp_send_json_success([
                'camps' => $camps_data['camps'],
                'stats' => $camps_data['stats'],
                'html' => $this->render_camps_table_html($camps_data['camps'], $filters),
                'message' => sprintf(
                    __('Found %d camps with %d attendees', 'intersoccer-reports-rosters'),
                    $camps_data['stats']['total_camps'],
                    $camps_data['stats']['total_attendees']
                )
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX camps filtering failed', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);
            
            wp_send_json_error([
                'message' => __('Failed to filter camps data', 'intersoccer-reports-rosters')
            ]);
        }
    }

    /**
     * Handle AJAX export request
     */
    public function ajax_export_camps() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'intersoccer_camps_nonce')) {
            wp_die('Security check failed', 'Unauthorized', ['response' => 403]);
        }
        
        // Check permissions
        if (!current_user_can('read')) {
            wp_die('Insufficient permissions', 'Unauthorized', ['response' => 403]);
        }
        
        try {
            $export_format = sanitize_text_field($_POST['format'] ?? 'excel');
            $filters = json_decode(stripslashes($_POST['filters'] ?? '{}'), true);
            
            // Get camps data for export
            $camps_data = $this->get_camps_data($filters);
            
            // Generate export
            $export_result = $this->export->export_camps($camps_data['camps'], $export_format);
            
            if ($export_result['success']) {
                wp_send_json_success([
                    'download_url' => $export_result['download_url'],
                    'filename' => $export_result['filename'],
                    'message' => __('Export ready for download', 'intersoccer-reports-rosters')
                ]);
            } else {
                wp_send_json_error([
                    'message' => $export_result['error']
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX camps export failed', [
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);
            
            wp_send_json_error([
                'message' => __('Export failed. Please try again.', 'intersoccer-reports-rosters')
            ]);
        }
    }

    /**
     * Render camps table HTML for AJAX responses
     * 
     * @param array $camps   Camps data
     * @param array $filters Current filters
     * 
     * @return string Table HTML
     */
    private function render_camps_table_html($camps, $filters) {
        ob_start();
        $this->render_camps_table($camps, $filters);
        return ob_get_clean();
    }

    /**
     * Get camps statistics for dashboard widget
     * 
     * @return array Dashboard statistics
     */
    public function get_dashboard_stats() {
        $cache_key = $this->get_cache_key('dashboard_stats');
        
        return $this->cache->remember(
            $cache_key,
            function() {
                $camps_data = $this->get_camps_data();
                
                return [
                    'total_camps' => $camps_data['stats']['total_camps'],
                    'total_attendees' => $camps_data['stats']['total_attendees'],
                    'active_camps' => $camps_data['stats']['active_camps'],
                    'venues_count' => $camps_data['stats']['unique_venues'],
                    'this_week_camps' => $this->get_this_week_camps_count(),
                    'medical_conditions_count' => $this->get_medical_conditions_count()
                ];
            },
            'roster_data',
            3600 // 1 hour
        );
    }

    /**
     * Get count of camps this week
     * 
     * @return int Number of camps this week
     */
    private function get_this_week_camps_count() {
        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));
        
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT product_variation_id) FROM {$rosters_table} 
             WHERE event_type = 'camp' 
             AND start_date BETWEEN %s AND %s",
            $week_start,
            $week_end
        )));
    }

    /**
     * Get count of attendees with medical conditions
     * 
     * @return int Number of attendees with medical conditions
     */
    private function get_medical_conditions_count() {
        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        
        return intval($wpdb->get_var(
            "SELECT COUNT(*) FROM {$rosters_table} 
             WHERE event_type = 'camp' 
             AND medical_conditions != '' 
             AND medical_conditions != 'None' 
             AND medical_conditions IS NOT NULL"
        ));
    }

    /**
     * Get camps by venue for reporting
     * 
     * @return array Camps grouped by venue
     */
    public function get_camps_by_venue() {
        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        
        $cache_key = $this->get_cache_key('camps_by_venue');
        
        return $this->cache->remember(
            $cache_key,
            function() use ($wpdb, $rosters_table) {
                return $wpdb->get_results(
                    "SELECT venue, COUNT(*) as attendee_count, COUNT(DISTINCT product_variation_id) as camp_count
                     FROM {$rosters_table} 
                     WHERE event_type = 'camp' AND venue != ''
                     GROUP BY venue 
                     ORDER BY attendee_count DESC",
                    ARRAY_A
                );
            },
            'roster_data',
            1800 // 30 minutes
        );
    }

    /**
     * Get upcoming camps for notifications
     * 
     * @param int $days_ahead Number of days to look ahead
     * 
     * @return array Upcoming camps
     */
    public function get_upcoming_camps($days_ahead = 7) {
        global $wpdb;
        $rosters_table = $wpdb->prefix . 'intersoccer_rosters';
        
        $today = date('Y-m-d');
        $future_date = date('Y-m-d', strtotime("+{$days_ahead} days"));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT product_variation_id, season, venue, start_date, end_date, COUNT(*) as attendee_count
             FROM {$rosters_table} 
             WHERE event_type = 'camp' 
             AND start_date BETWEEN %s AND %s
             GROUP BY product_variation_id, season, venue, start_date, end_date
             ORDER BY start_date ASC",
            $today,
            $future_date
        ), ARRAY_A);
    }
}