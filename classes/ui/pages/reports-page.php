<?php
/**
 * Reports Page
 * 
 * Reports page UI for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\UI\Pages
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\UI\Pages;

use InterSoccerReportsRosters\Reports\OverviewReport;
use InterSoccerReportsRosters\Reports\CampReport;
use InterSoccerReportsRosters\UI\Components\TabsComponent;
use InterSoccerReportsRosters\UI\Components\ChartsComponent;
use InterSoccerReportsRosters\UI\Renderers\TableRenderer;

defined('ABSPATH') or die('Restricted access');

class ReportsPage extends AbstractPage {
    
    /**
     * Page title
     * @var string
     */
    protected $page_title = 'InterSoccer Reports';
    
    /**
     * Capability required
     * @var string
     */
    protected $capability = 'intersoccer_view_reports';
    
    /**
     * Render the reports page
     */
    public function render() {
        if (!$this->check_permissions()) {
            $this->render_permission_denied();
            return;
        }
        
        $this->enqueue_assets();
        $this->render_header('InterSoccer Reports');
        
        // Get current tab
        $active_tab = $_GET['tab'] ?? 'overview';
        $filters = $this->get_filters();
        
        ?>
        <div class="intersoccer-reports-page">
            <?php $this->render_tabs($active_tab); ?>
            
            <div class="tab-content">
                <?php $this->render_tab_content($active_tab, $filters); ?>
            </div>
        </div>
        <?php
        
        $this->render_footer();
    }
    
    /**
     * Render navigation tabs
     * 
     * @param string $active_tab
     */
    private function render_tabs($active_tab) {
        $tabs = [
            'overview' => __('Overview', 'intersoccer-reports-rosters'),
            'camps' => __('Camps Report', 'intersoccer-reports-rosters'),
            'courses' => __('Courses Report', 'intersoccer-reports-rosters'),
            'analytics' => __('Analytics', 'intersoccer-reports-rosters'),
            'custom' => __('Custom Report', 'intersoccer-reports-rosters')
        ];
        
        $tabs_component = new TabsComponent();
        $tabs_component->render($tabs, $active_tab, [
            'base_url' => admin_url('admin.php?page=intersoccer-reports'),
            'preserve_params' => ['venue', 'date_from', 'date_to']
        ]);
    }
    
    /**
     * Render tab content
     * 
     * @param string $active_tab
     * @param array $filters
     */
    private function render_tab_content($active_tab, $filters) {
        switch ($active_tab) {
            case 'overview':
                $this->render_overview_tab($filters);
                break;
            case 'camps':
                $this->render_camps_tab($filters);
                break;
            case 'courses':
                $this->render_courses_tab($filters);
                break;
            case 'analytics':
                $this->render_analytics_tab($filters);
                break;
            case 'custom':
                $this->render_custom_tab($filters);
                break;
            default:
                $this->render_overview_tab($filters);
                break;
        }
    }
    
    /**
     * Render overview tab
     * 
     * @param array $filters
     */
    private function render_overview_tab($filters) {
        ?>
        <div class="overview-tab">
            <?php $this->render_filter_section($filters); ?>
            
            <?php
            $overview_report = new OverviewReport();
            $report_data = $overview_report->generate($filters);
            ?>
            
            <div class="report-summary">
                <h2><?php esc_html_e('Overall Statistics', 'intersoccer-reports-rosters'); ?></h2>
                <?php $this->render_stats_cards($report_data['overall_stats']); ?>
            </div>
            
            <div class="report-charts">
                <h2><?php esc_html_e('Visual Analytics', 'intersoccer-reports-rosters'); ?></h2>
                <?php $this->render_overview_charts($report_data['chart_data']); ?>
            </div>
            
            <div class="venue-breakdown">
                <h2><?php esc_html_e('Venue Breakdown', 'intersoccer-reports-rosters'); ?></h2>
                <?php $this->render_venue_table($report_data['venue_stats']); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render camps tab
     * 
     * @param array $filters
     */
    private function render_camps_tab($filters) {
        ?>
        <div class="camps-tab">
            <?php $this->render_filter_section($filters); ?>
            
            <?php
            $camp_report = new CampReport();
            $report_data = $camp_report->generate($filters);
            ?>
            
            <div class="camp-statistics">
                <h2><?php esc_html_e('Camp Statistics', 'intersoccer-reports-rosters'); ?></h2>
                <?php $this->render_stats_cards($report_data['statistics']); ?>
            </div>
            
            <div class="camp-details">
                <h2><?php esc_html_e('Individual Camps', 'intersoccer-reports-rosters'); ?></h2>
                <?php $this->render_camps_table($report_data['camps']); ?>
            </div>
            
            <div class="attendance-patterns">
                <h2><?php esc_html_e('Attendance Patterns', 'intersoccer-reports-rosters'); ?></h2>
                <?php $this->render_attendance_charts($report_data['attendance_patterns']); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render courses tab
     * 
     * @param array $filters
     */
    private function render_courses_tab($filters) {
        // Add course-specific filter
        $course_filters = array_merge($filters, ['event_type' => 'course']);
        
        ?>
        <div class="courses-tab">
            <?php $this->render_filter_section($filters); ?>
            
            <div class="courses-content">
                <h2><?php esc_html_e('Course Activities Report', 'intersoccer-reports-rosters'); ?></h2>
                <p><?php esc_html_e('Detailed analysis of training courses and skill development programs.', 'intersoccer-reports-rosters'); ?></p>
                
                <?php
                // Use overview report with course filter
                $overview_report = new OverviewReport();
                $report_data = $overview_report->generate($course_filters);
                
                $this->render_stats_cards($report_data['overall_stats']);
                $this->render_venue_table($report_data['venue_stats']);
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render analytics tab
     * 
     * @param array $filters
     */
    private function render_analytics_tab($filters) {
        ?>
        <div class="analytics-tab">
            <h2><?php esc_html_e('Advanced Analytics', 'intersoccer-reports-rosters'); ?></h2>
            
            <?php $this->render_filter_section($filters); ?>
            
            <div class="analytics-widgets">
                <div class="widget-row">
                    <div class="widget widget-trends">
                        <h3><?php esc_html_e('Participation Trends', 'intersoccer-reports-rosters'); ?></h3>
                        <div id="trends-chart" style="height: 300px;"></div>
                    </div>
                    
                    <div class="widget widget-demographics">
                        <h3><?php esc_html_e('Demographics', 'intersoccer-reports-rosters'); ?></h3>
                        <div id="demographics-chart" style="height: 300px;"></div>
                    </div>
                </div>
                
                <div class="widget-row">
                    <div class="widget widget-revenue">
                        <h3><?php esc_html_e('Revenue Analysis', 'intersoccer-reports-rosters'); ?></h3>
                        <div id="revenue-chart" style="height: 300px;"></div>
                    </div>
                    
                    <div class="widget widget-retention">
                        <h3><?php esc_html_e('Customer Retention', 'intersoccer-reports-rosters'); ?></h3>
                        <div id="retention-chart" style="height: 300px;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render custom report tab
     * 
     * @param array $filters
     */
    private function render_custom_tab($filters) {
        ?>
        <div class="custom-tab">
            <h2><?php esc_html_e('Custom Report Builder', 'intersoccer-reports-rosters'); ?></h2>
            
            <form method="post" class="custom-report-form">
                <div class="form-section">
                    <h3><?php esc_html_e('Report Configuration', 'intersoccer-reports-rosters'); ?></h3>
                    
                    <div class="form-row">
                        <label for="report-name"><?php esc_html_e('Report Name', 'intersoccer-reports-rosters'); ?></label>
                        <input type="text" id="report-name" name="report_name" required>
                    </div>
                    
                    <div class="form-row">
                        <label for="report-type"><?php esc_html_e('Report Type', 'intersoccer-reports-rosters'); ?></label>
                        <select id="report-type" name="report_type">
                            <option value="participants"><?php esc_html_e('Participants Report', 'intersoccer-reports-rosters'); ?></option>
                            <option value="revenue"><?php esc_html_e('Revenue Report', 'intersoccer-reports-rosters'); ?></option>
                            <option value="events"><?php esc_html_e('Events Report', 'intersoccer-reports-rosters'); ?></option>
                            <option value="attendance"><?php esc_html_e('Attendance Report', 'intersoccer-reports-rosters'); ?></option>
                        </select>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3><?php esc_html_e('Filters & Criteria', 'intersoccer-reports-rosters'); ?></h3>
                    <?php $this->render_custom_filters(); ?>
                </div>
                
                <div class="form-section">
                    <h3><?php esc_html_e('Output Options', 'intersoccer-reports-rosters'); ?></h3>
                    
                    <div class="form-row">
                        <label><?php esc_html_e('Format', 'intersoccer-reports-rosters'); ?></label>
                        <label><input type="radio" name="output_format" value="screen" checked> <?php esc_html_e('Display on Screen', 'intersoccer-reports-rosters'); ?></label>
                        <label><input type="radio" name="output_format" value="excel"> <?php esc_html_e('Export to Excel', 'intersoccer-reports-rosters'); ?></label>
                        <label><input type="radio" name="output_format" value="csv"> <?php esc_html_e('Export to CSV', 'intersoccer-reports-rosters'); ?></label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Generate Report', 'intersoccer-reports-rosters'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render filter section
     * 
     * @param array $filters
     */
    private function render_filter_section($filters) {
        $available_filters = [
            'date_from' => __('Start Date', 'intersoccer-reports-rosters'),
            'date_to' => __('End Date', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'event_type' => __('Event Type', 'intersoccer-reports-rosters'),
            'age_group' => __('Age Group', 'intersoccer-reports-rosters'),
            'gender' => __('Gender', 'intersoccer-reports-rosters')
        ];
        
        ?>
        <div class="report-filters">
            <h3><?php esc_html_e('Filters', 'intersoccer-reports-rosters'); ?></h3>
            <?php $this->render_filters($available_filters, $filters); ?>
        </div>
        <?php
    }
    
    /**
     * Render statistics cards
     * 
     * @param array $stats
     */
    private function render_stats_cards($stats) {
        ?>
        <div class="stats-cards">
            <?php foreach ($stats as $key => $value): ?>
                <div class="stat-card">
                    <div class="stat-value"><?php echo esc_html($value); ?></div>
                    <div class="stat-label"><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
    
    /**
     * Render overview charts
     * 
     * @param array $chart_data
     */
    private function render_overview_charts($chart_data) {
        $charts_component = new ChartsComponent();
        
        ?>
        <div class="charts-grid">
            <div class="chart-container">
                <h4><?php esc_html_e('Venue Distribution', 'intersoccer-reports-rosters'); ?></h4>
                <?php $charts_component->render_pie_chart('venue-chart', $chart_data['venue_distribution']); ?>
            </div>
            
            <div class="chart-container">
                <h4><?php esc_html_e('Age Distribution', 'intersoccer-reports-rosters'); ?></h4>
                <?php $charts_component->render_bar_chart('age-chart', $chart_data['age_distribution']); ?>
            </div>
            
            <div class="chart-container">
                <h4><?php esc_html_e('Monthly Trends', 'intersoccer-reports-rosters'); ?></h4>
                <?php $charts_component->render_line_chart('trends-chart', $chart_data['monthly_trends']); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render venue table
     * 
     * @param array $venue_stats
     */
    private function render_venue_table($venue_stats) {
        $table_renderer = new TableRenderer();
        
        $columns = [
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'participant_count' => __('Participants', 'intersoccer-reports-rosters'),
            'event_count' => __('Events', 'intersoccer-reports-rosters'),
            'revenue' => __('Revenue', 'intersoccer-reports-rosters'),
            'avg_age' => __('Avg Age', 'intersoccer-reports-rosters'),
            'gender_distribution' => __('Gender Split', 'intersoccer-reports-rosters')
        ];
        
        $table_renderer->render($venue_stats, $columns, [
            'class' => 'intersoccer-venue-table',
            'sortable' => true,
            'pagination' => false
        ]);
    }
    
    /**
     * Render camps table
     * 
     * @param array $camps
     */
    private function render_camps_table($camps) {
        $table_renderer = new TableRenderer();
        
        $columns = [
            'event_name' => __('Camp Name', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'date_range' => __('Dates', 'intersoccer-reports-rosters'),
            'participant_count' => __('Participants', 'intersoccer-reports-rosters'),
            'age_range' => __('Age Range', 'intersoccer-reports-rosters'),
            'revenue' => __('Revenue', 'intersoccer-reports-rosters'),
            'payment_rate' => __('Payment Rate', 'intersoccer-reports-rosters')
        ];
        
        $table_renderer->render($camps, $columns, [
            'class' => 'intersoccer-camps-table',
            'sortable' => true,
            'pagination' => true,
            'per_page' => 15
        ]);
    }
    
    /**
     * Render attendance charts
     * 
     * @param array $attendance_data
     */
    private function render_attendance_charts($attendance_data) {
        $charts_component = new ChartsComponent();
        
        ?>
        <div class="attendance-charts">
            <div class="chart-container">
                <h4><?php esc_html_e('Weekly Attendance', 'intersoccer-reports-rosters'); ?></h4>
                <?php $charts_component->render_line_chart('weekly-attendance', $attendance_data['weekly_attendance']); ?>
            </div>
            
            <div class="chart-container">
                <h4><?php esc_html_e('Age Group Distribution', 'intersoccer-reports-rosters'); ?></h4>
                <?php $charts_component->render_pie_chart('age-distribution', $attendance_data['age_distribution']); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render custom filters for custom report builder
     */
    private function render_custom_filters() {
        ?>
        <div class="custom-filters">
            <div class="filter-group">
                <h4><?php esc_html_e('Date Range', 'intersoccer-reports-rosters'); ?></h4>
                <div class="form-row">
                    <label for="custom-date-from"><?php esc_html_e('From', 'intersoccer-reports-rosters'); ?></label>
                    <input type="date" id="custom-date-from" name="custom_date_from">
                </div>
                <div class="form-row">
                    <label for="custom-date-to"><?php esc_html_e('To', 'intersoccer-reports-rosters'); ?></label>
                    <input type="date" id="custom-date-to" name="custom_date_to">
                </div>
            </div>
            
            <div class="filter-group">
                <h4><?php esc_html_e('Categories', 'intersoccer-reports-rosters'); ?></h4>
                <div class="checkbox-group">
                    <label><input type="checkbox" name="include_camps" checked> <?php esc_html_e('Camps', 'intersoccer-reports-rosters'); ?></label>
                    <label><input type="checkbox" name="include_courses" checked> <?php esc_html_e('Courses', 'intersoccer-reports-rosters'); ?></label>
                    <label><input type="checkbox" name="include_girls_only" checked> <?php esc_html_e('Girls Only', 'intersoccer-reports-rosters'); ?></label>
                    <label><input type="checkbox" name="include_other" checked> <?php esc_html_e('Other Events', 'intersoccer-reports-rosters'); ?></label>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Enqueue page-specific assets
     */
    protected function enqueue_assets() {
        wp_enqueue_script('intersoccer-reports-charts', $this->plugin->get_plugin_url() . 'js/reports-charts.js', ['chart-js'], '2.0.0', true);
        wp_enqueue_style('intersoccer-reports-css', $this->plugin->get_plugin_url() . 'css/reports.css', [], '2.0.0');
    }
}