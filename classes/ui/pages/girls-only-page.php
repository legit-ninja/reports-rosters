<?php
/**
 * Girls Only Page
 * 
 * Girls-only events page UI for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\UI\Pages
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\UI\Pages;

use InterSoccerReportsRosters\Data\Repositories\RosterRepository;
use InterSoccerReportsRosters\UI\Components\TabsComponent;
use InterSoccerReportsRosters\UI\Components\ExportComponent;
use InterSoccerReportsRosters\UI\Components\ChartsComponent;
use InterSoccerReportsRosters\UI\Renderers\TableRenderer;

defined('ABSPATH') or die('Restricted access');

class GirlsOnlyPage extends AbstractPage {
    
    /**
     * Page title
     * @var string
     */
    protected $page_title = 'Girls Only Programs';
    
    /**
     * Capability required
     * @var string
     */
    protected $capability = 'intersoccer_view_reports';
    
    /**
     * Roster repository
     * @var RosterRepository
     */
    private $roster_repository;
    
    /**
     * Constructor
     * 
     * @param Plugin $plugin
     */
    public function __construct($plugin) {
        parent::__construct($plugin);
        $this->roster_repository = new RosterRepository();
    }
    
    /**
     * Render the girls only page
     */
    public function render() {
        if (!$this->check_permissions()) {
            $this->render_permission_denied();
            return;
        }
        
        $this->enqueue_assets();
        $this->render_header('Girls Only Programs & Events');
        
        $active_tab = $_GET['tab'] ?? 'all';
        $filters = $this->get_filters();
        
        ?>
        <div class="intersoccer-girls-only-page">
            <div class="page-description">
                <p><?php esc_html_e('Dedicated programs and events designed specifically for female participants, promoting inclusion and skill development in a supportive environment.', 'intersoccer-reports-rosters'); ?></p>
            </div>
            
            <?php $this->render_girls_tabs($active_tab); ?>
            
            <div class="tab-content">
                <?php $this->render_girls_content($active_tab, $filters); ?>
            </div>
        </div>
        <?php
        
        $this->render_footer();
    }
    
    /**
     * Render girls only navigation tabs
     * 
     * @param string $active_tab
     */
    private function render_girls_tabs($active_tab) {
        $tabs = [
            'all' => __('All Programs', 'intersoccer-reports-rosters'),
            'camps' => __('Girls Camps', 'intersoccer-reports-rosters'),
            'courses' => __('Skills Training', 'intersoccer-reports-rosters'),
            'events' => __('Special Events', 'intersoccer-reports-rosters'),
            'analytics' => __('Analytics', 'intersoccer-reports-rosters')
        ];
        
        $tabs_component = new TabsComponent();
        $tabs_component->render($tabs, $active_tab, [
            'base_url' => admin_url('admin.php?page=intersoccer-girls-only'),
            'preserve_params' => ['venue', 'date_from', 'date_to', 'age_group']
        ]);
    }
    
    /**
     * Render girls content based on active tab
     * 
     * @param string $active_tab
     * @param array $filters
     */
    private function render_girls_content($active_tab, $filters) {
        // Get girls-only rosters
        $rosters = $this->roster_repository->find_girls_only();
        
        // Filter rosters based on tab and filters
        $filtered_rosters = $this->filter_girls_rosters($rosters, $active_tab, $filters);
        
        switch ($active_tab) {
            case 'all':
                $this->render_all_programs($filtered_rosters, $filters);
                break;
            case 'camps':
                $this->render_girls_camps($filtered_rosters, $filters);
                break;
            case 'courses':
                $this->render_skills_training($filtered_rosters, $filters);
                break;
            case 'events':
                $this->render_special_events($filtered_rosters, $filters);
                break;
            case 'analytics':
                $this->render_analytics($filtered_rosters, $filters);
                break;
            default:
                $this->render_all_programs($filtered_rosters, $filters);
                break;
        }
    }
    
    /**
     * Filter girls rosters based on tab and filters
     * 
     * @param array $rosters
     * @param string $tab
     * @param array $filters
     * @return array
     */
    private function filter_girls_rosters($rosters, $tab, $filters) {
        $filtered = [];
        
        foreach ($rosters as $roster) {
            $include = true;
            
            // Tab-specific filtering
            switch ($tab) {
                case 'camps':
                    $include = strpos(strtolower($roster->product_name ?? ''), 'camp') !== false ||
                              strpos(strtolower($roster->product_type ?? ''), 'camp') !== false;
                    break;
                case 'courses':
                    $include = strpos(strtolower($roster->product_name ?? ''), 'course') !== false ||
                              strpos(strtolower($roster->product_name ?? ''), 'training') !== false ||
                              strpos(strtolower($roster->product_type ?? ''), 'course') !== false;
                    break;
                case 'events':
                    $include = !strpos(strtolower($roster->product_name ?? ''), 'camp') &&
                              !strpos(strtolower($roster->product_name ?? ''), 'course') &&
                              !strpos(strtolower($roster->product_name ?? ''), 'training');
                    break;
            }
            
            if (!$include) {
                continue;
            }
            
            // Apply general filters
            if (!empty($filters['venue']) && $roster->venue !== $filters['venue']) {
                $include = false;
            }
            
            if (!empty($filters['age_group']) && $roster->age_group !== $filters['age_group']) {
                $include = false;
            }
            
            if (!empty($filters['date_from']) && $roster->start_date < $filters['date_from']) {
                $include = false;
            }
            
            if (!empty($filters['date_to']) && $roster->end_date > $filters['date_to']) {
                $include = false;
            }
            
            if ($include) {
                $filtered[] = $roster;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Render all programs tab
     * 
     * @param array $rosters
     * @param array $filters
     */
    private function render_all_programs($rosters, $filters) {
        ?>
        <div class="all-programs-tab">
            <?php $this->render_girls_filters($filters); ?>
            
            <div class="programs-overview">
                <h3><?php esc_html_e('Program Overview', 'intersoccer-reports-rosters'); ?></h3>
                <?php $this->render_program_statistics($rosters); ?>
            </div>
            
            <div class="export-section">
                <?php $this->render_export_section($rosters, 'girls_programs'); ?>
            </div>
            
            <div class="programs-table">
                <h3><?php esc_html_e('All Participants', 'intersoccer-reports-rosters'); ?></h3>
                <?php $this->render_girls_table($rosters); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render girls camps tab
     * 
     * @param array $rosters
     * @param array $filters
     */
    private function render_girls_camps($rosters, $filters) {
        $camp_stats = $this->get_camp_breakdown($rosters);
        
        ?>
        <div class="girls-camps-tab">
            <?php $this->render_girls_filters($filters); ?>
            
            <div class="camp-statistics">
                <h3><?php esc_html_e('Girls Camp Statistics', 'intersoccer-reports-rosters'); ?></h3>
                <?php $this->render_camp_stats_cards($camp_stats); ?>
            </div>
            
            <div class="camp-breakdown">
                <h3><?php esc_html_e('Camps by Age Group', 'intersoccer-reports-rosters'); ?></h3>
                <?php $this->render_age_breakdown_chart($rosters); ?>
            </div>
            
            <div class="camps-table">
                <h3><?php esc_html_e('Camp Participants', 'intersoccer-reports-rosters'); ?></h3>
                <?php $this->render_girls_table($rosters, 'camps'); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render skills training tab
     * 
     * @param array $rosters
     * @param array $filters
     */
    private function render_skills_training($rosters, $filters) {
        ?>
        <div class="skills-training-tab">
            <?php $this->render_girls_filters($filters); ?>
            
            <div class="training-overview">
                <h3><?php esc_html_e('Skills Development Programs', 'intersoccer-reports-rosters'); ?></h3>
                <p><?php esc_html_e('Focused training sessions designed to develop technical skills, tactical understanding, and confidence in female players.', 'intersoccer-reports-rosters'); ?></p>
                
                <?php $this->render_training_statistics($rosters); ?>
            </div>
            
            <div class="skill-levels-chart">
                <h3><?php esc_html_e('Skill Level Distribution', 'intersoccer-reports-rosters'); ?></h3>
                <?php $this->render_skill_levels_chart($rosters); ?>
            </div>
            
            <div class="training-table">
                <h3><?php esc_html_e('Training Participants', 'intersoccer-reports-rosters'); ?></h3>
                <?php $this->render_girls_table($rosters, 'training'); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render special events tab
     * 
     * @param array $rosters
     * @param array $filters
     */
    private function render_special_events($rosters, $filters) {
        ?>
        <div class="special-events-tab">
            <?php $this->render_girls_filters($filters); ?>
            
            <div class="events-overview">
                <h3><?php esc_html_e('Special Events & Programs', 'intersoccer-reports-rosters'); ?></h3>
                <p><?php esc_html_e('Tournaments, workshops, and special programs celebrating female participation in soccer.', 'intersoccer-reports-rosters'); ?></p>
                
                <?php $this->render_events_statistics($rosters); ?>
            </div>
            
            <div class="events-table">
                <h3><?php esc_html_e('Event Participants', 'intersoccer-reports-rosters'); ?></h3>
                <?php $this->render_girls_table($rosters, 'events'); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render analytics tab
     * 
     * @param array $rosters
     * @param array $filters
     */
    private function render_analytics($rosters, $filters) {
        ?>
        <div class="analytics-tab">
            <h3><?php esc_html_e('Girls Programs Analytics', 'intersoccer-reports-rosters'); ?></h3>
            
            <div class="analytics-grid">
                <div class="chart-container">
                    <h4><?php esc_html_e('Participation Trends', 'intersoccer-reports-rosters'); ?></h4>
                    <?php $this->render_participation_trends($rosters); ?>
                </div>
                
                <div class="chart-container">
                    <h4><?php esc_html_e('Age Distribution', 'intersoccer-reports-rosters'); ?></h4>
                    <?php $this->render_age_distribution_chart($rosters); ?>
                </div>
                
                <div class="chart-container">
                    <h4><?php esc_html_e('Venue Usage', 'intersoccer-reports-rosters'); ?></h4>
                    <?php $this->render_venue_chart($rosters); ?>
                </div>
                
                <div class="chart-container">
                    <h4><?php esc_html_e('Program Types', 'intersoccer-reports-rosters'); ?></h4>
                    <?php $this->render_program_types_chart($rosters); ?>
                </div>
            </div>
            
            <div class="retention-analysis">
                <h4><?php esc_html_e('Participation Analysis', 'intersoccer-reports-rosters'); ?></h4>
                <?php $this->render_participation_analysis($rosters); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render girls-specific filters
     * 
     * @param array $filters
     */
    private function render_girls_filters($filters) {
        $available_filters = [
            'date_from' => __('Start Date', 'intersoccer-reports-rosters'),
            'date_to' => __('End Date', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'age_group' => __('Age Group', 'intersoccer-reports-rosters')
        ];
        
        ?>
        <div class="girls-filters">
            <h3><?php esc_html_e('Filter Programs', 'intersoccer-reports-rosters'); ?></h3>
            <?php $this->render_filters($available_filters, $filters); ?>
        </div>
        <?php
    }
    
    /**
     * Render program statistics
     * 
     * @param array $rosters
     */
    private function render_program_statistics($rosters) {
        $stats = $this->calculate_program_stats($rosters);
        
        ?>
        <div class="program-statistics">
            <div class="stats-grid">
                <div class="stat-card highlight">
                    <div class="stat-number"><?php echo esc_html($stats['total_participants']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Total Participants', 'intersoccer-reports-rosters'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html($stats['unique_programs']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Programs Offered', 'intersoccer-reports-rosters'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html($stats['venues_count']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Venues Used', 'intersoccer-reports-rosters'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html($stats['average_age']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Average Age', 'intersoccer-reports-rosters'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html($stats['total_revenue']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Total Revenue', 'intersoccer-reports-rosters'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html($stats['completion_rate']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Completion Rate', 'intersoccer-reports-rosters'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Calculate program statistics
     * 
     * @param array $rosters
     * @return array
     */
    private function calculate_program_stats($rosters) {
        $total_participants = count($rosters);
        $unique_programs = [];
        $venues = [];
        $total_age = 0;
        $age_count = 0;
        $total_revenue = 0;
        $completed = 0;
        
        foreach ($rosters as $roster) {
            if (!in_array($roster->event_name, $unique_programs)) {
                $unique_programs[] = $roster->event_name;
            }
            
            if (!in_array($roster->venue, $venues)) {
                $venues[] = $roster->venue;
            }
            
            if ($roster->player_age && is_numeric($roster->player_age)) {
                $total_age += intval($roster->player_age);
                $age_count++;
            }
            
            if ($roster->order_total && is_numeric($roster->order_total)) {
                $total_revenue += floatval($roster->order_total);
            }
            
            if (strtolower($roster->registration_status ?? '') === 'complete') {
                $completed++;
            }
        }
        
        return [
            'total_participants' => $total_participants,
            'unique_programs' => count($unique_programs),
            'venues_count' => count($venues),
            'average_age' => $age_count > 0 ? round($total_age / $age_count, 1) . ' years' : 'N/A',
            'total_revenue' => number_format($total_revenue, 2) . ' CHF',
            'completion_rate' => $total_participants > 0 ? round(($completed / $total_participants) * 100, 1) . '%' : '0%'
        ];
    }
    
    /**
     * Render export section
     * 
     * @param array $rosters
     * @param string $type
     */
    private function render_export_section($rosters, $type = 'girls_programs') {
        $export_component = new ExportComponent();
        
        ?>
        <div class="export-section">
            <h3><?php esc_html_e('Export Data', 'intersoccer-reports-rosters'); ?></h3>
            <?php 
            $export_component->render([
                'data' => $rosters,
                'filename_prefix' => 'intersoccer_' . $type,
                'formats' => ['excel', 'csv'],
                'include_summary' => true
            ]);
            ?>
        </div>
        <?php
    }
    
    /**
     * Render girls table
     * 
     * @param array $rosters
     * @param string $context
     */
    private function render_girls_table($rosters, $context = 'all') {
        $table_renderer = new TableRenderer();
        
        $columns = [
            'event_name' => __('Program', 'intersoccer-reports-rosters'),
            'player_name' => __('Participant', 'intersoccer-reports-rosters'),
            'player_age' => __('Age', 'intersoccer-reports-rosters'),
            'age_group' => __('Age Group', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'start_date' => __('Start Date', 'intersoccer-reports-rosters'),
            'parent_email' => __('Contact Email', 'intersoccer-reports-rosters'),
            'registration_status' => __('Status', 'intersoccer-reports-rosters'),
            'order_total' => __('Fee', 'intersoccer-reports-rosters')
        ];
        
        // Add context-specific columns
        if ($context === 'training') {
            $columns['skill_level'] = __('Skill Level', 'intersoccer-reports-rosters');
        }
        
        // Format data for display
        $formatted_data = [];
        foreach ($rosters as $roster) {
            $row = (array) $roster;
            
            if ($row['start_date']) {
                $row['start_date'] = date('M j, Y', strtotime($row['start_date']));
            }
            
            if ($row['order_total']) {
                $row['order_total'] = number_format($row['order_total'], 2) . ' CHF';
            }
            
            $row['registration_status'] = ucfirst($row['registration_status'] ?? 'pending');
            $row['skill_level'] = ucfirst($row['skill_level'] ?? 'N/A');
            
            $formatted_data[] = $row;
        }
        
        $table_renderer->render($formatted_data, $columns, [
            'class' => 'intersoccer-girls-table',
            'sortable' => true,
            'pagination' => true,
            'per_page' => 25,
            'search' => true,
            'export_buttons' => true
        ]);
    }
}

    
    /**
     * Render various chart components
     * 
     **/
