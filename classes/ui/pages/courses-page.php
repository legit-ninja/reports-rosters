<?php
/**
 * Courses Page
 * 
 * Courses roster page UI for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\UI\Pages
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\UI\Pages;

use InterSoccerReportsRosters\Data\Repositories\RosterRepository;
use InterSoccerReportsRosters\UI\Components\TabsComponent;
use InterSoccerReportsRosters\UI\Components\ExportComponent;
use InterSoccerReportsRosters\UI\Renderers\TableRenderer;

defined('ABSPATH') or die('Restricted access');

class CoursesPage extends AbstractPage {
    
    /**
     * Page title
     * @var string
     */
    protected $page_title = 'Courses Rosters';
    
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
     * Render the courses page
     */
    public function render() {
        if (!$this->check_permissions()) {
            $this->render_permission_denied();
            return;
        }
        
        $this->enqueue_assets();
        $this->render_header('Training Courses & Skills Development');
        
        $active_tab = $_GET['tab'] ?? 'all';
        $filters = $this->get_filters();
        
        ?>
        <div class="intersoccer-courses-page">
            <div class="page-description">
                <p><?php esc_html_e('Training courses, skill development programs, and coaching sessions.', 'intersoccer-reports-rosters'); ?></p>
            </div>
            
            <?php $this->render_course_tabs($active_tab); ?>
            
            <div class="tab-content">
                <?php $this->render_course_content($active_tab, $filters); ?>
            </div>
        </div>
        <?php
        
        $this->render_footer();
    }
    
    /**
     * Render course navigation tabs
     * 
     * @param string $active_tab
     */
    private function render_course_tabs($active_tab) {
        $tabs = [
            'all' => __('All Courses', 'intersoccer-reports-rosters'),
            'beginner' => __('Beginner', 'intersoccer-reports-rosters'),
            'intermediate' => __('Intermediate', 'intersoccer-reports-rosters'),
            'advanced' => __('Advanced', 'intersoccer-reports-rosters'),
            'goalkeeper' => __('Goalkeeper', 'intersoccer-reports-rosters'),
            'coaching' => __('Coaching', 'intersoccer-reports-rosters')
        ];
        
        $tabs_component = new TabsComponent();
        $tabs_component->render($tabs, $active_tab, [
            'base_url' => admin_url('admin.php?page=intersoccer-courses'),
            'preserve_params' => ['venue', 'date_from', 'date_to', 'age_group', 'gender']
        ]);
    }
    
    /**
     * Render course content based on active tab
     * 
     * @param string $active_tab
     * @param array $filters
     */
    private function render_course_content($active_tab, $filters) {
        // Add course-specific filters
        $course_filters = $this->get_course_filters($active_tab, $filters);
        
        // Get course rosters
        $rosters = $this->roster_repository->find_courses();
        
        // Filter rosters based on tab and filters
        $filtered_rosters = $this->filter_course_rosters($rosters, $course_filters);
        
        ?>
        <div class="course-content">
            <?php $this->render_course_filters($filters); ?>
            
            <div class="course-stats">
                <?php $this->render_course_statistics($filtered_rosters); ?>
            </div>
            
            <div class="course-actions">
                <?php $this->render_export_section($filtered_rosters); ?>
            </div>
            
            <div class="course-roster-table">
                <?php $this->render_course_table($filtered_rosters); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get course-specific filters
     * 
     * @param string $active_tab
     * @param array $base_filters
     * @return array
     */
    private function get_course_filters($active_tab, $base_filters) {
        $filters = $base_filters;
        
        // Add skill level filter based on tab
        switch ($active_tab) {
            case 'beginner':
                $filters['skill_level'] = 'beginner';
                break;
            case 'intermediate':
                $filters['skill_level'] = 'intermediate';
                break;
            case 'advanced':
                $filters['skill_level'] = 'advanced';
                break;
            case 'goalkeeper':
                $filters['course_type'] = 'goalkeeper';
                break;
            case 'coaching':
                $filters['course_type'] = 'coaching';
                break;
        }
        
        return $filters;
    }
    
    /**
     * Filter course rosters
     * 
     * @param array $rosters
     * @param array $filters
     * @return array
     */
    private function filter_course_rosters($rosters, $filters) {
        $filtered = [];
        
        foreach ($rosters as $roster) {
            $include = true;
            
            // Apply filters
            if (!empty($filters['venue']) && $roster->venue !== $filters['venue']) {
                $include = false;
            }
            
            if (!empty($filters['age_group']) && $roster->age_group !== $filters['age_group']) {
                $include = false;
            }
            
            if (!empty($filters['gender']) && $roster->gender !== $filters['gender']) {
                $include = false;
            }
            
            if (!empty($filters['date_from']) && $roster->start_date < $filters['date_from']) {
                $include = false;
            }
            
            if (!empty($filters['date_to']) && $roster->end_date > $filters['date_to']) {
                $include = false;
            }
            
            // Skill level filter
            if (!empty($filters['skill_level'])) {
                $skill_match = strpos(strtolower($roster->skill_level ?? ''), $filters['skill_level']) !== false ||
                              strpos(strtolower($roster->product_name ?? ''), $filters['skill_level']) !== false;
                if (!$skill_match) {
                    $include = false;
                }
            }
            
            // Course type filter
            if (!empty($filters['course_type'])) {
                $type_match = strpos(strtolower($roster->product_name ?? ''), $filters['course_type']) !== false ||
                             strpos(strtolower($roster->event_description ?? ''), $filters['course_type']) !== false;
                if (!$type_match) {
                    $include = false;
                }
            }
            
            if ($include) {
                $filtered[] = $roster;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Render course filters
     * 
     * @param array $filters
     */
    private function render_course_filters($filters) {
        $available_filters = [
            'date_from' => __('Start Date', 'intersoccer-reports-rosters'),
            'date_to' => __('End Date', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'age_group' => __('Age Group', 'intersoccer-reports-rosters'),
            'gender' => __('Gender', 'intersoccer-reports-rosters')
        ];
        
        ?>
        <div class="course-filters">
            <h3><?php esc_html_e('Filter Courses', 'intersoccer-reports-rosters'); ?></h3>
            <?php $this->render_filters($available_filters, $filters); ?>
        </div>
        <?php
    }
    
    /**
     * Render course statistics
     * 
     * @param array $rosters
     */
    private function render_course_statistics($rosters) {
        $stats = $this->calculate_course_stats($rosters);
        
        ?>
        <div class="course-statistics">
            <h3><?php esc_html_e('Course Statistics', 'intersoccer-reports-rosters'); ?></h3>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html($stats['total_participants']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Total Participants', 'intersoccer-reports-rosters'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html($stats['unique_courses']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Unique Courses', 'intersoccer-reports-rosters'); ?></div>
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
     * Calculate course statistics
     * 
     * @param array $rosters
     * @return array
     */
    private function calculate_course_stats($rosters) {
        $total_participants = count($rosters);
        $unique_courses = [];
        $venues = [];
        $total_age = 0;
        $age_count = 0;
        $total_revenue = 0;
        $completed_courses = 0;
        
        foreach ($rosters as $roster) {
            // Count unique courses
            if (!in_array($roster->event_name, $unique_courses)) {
                $unique_courses[] = $roster->event_name;
            }
            
            // Count venues
            if (!in_array($roster->venue, $venues)) {
                $venues[] = $roster->venue;
            }
            
            // Calculate average age
            if ($roster->player_age && is_numeric($roster->player_age)) {
                $total_age += intval($roster->player_age);
                $age_count++;
            }
            
            // Sum revenue
            if ($roster->order_total && is_numeric($roster->order_total)) {
                $total_revenue += floatval($roster->order_total);
            }
            
            // Count completed courses
            if (strtolower($roster->registration_status ?? '') === 'complete') {
                $completed_courses++;
            }
        }
        
        $average_age = $age_count > 0 ? round($total_age / $age_count, 1) : 0;
        $completion_rate = $total_participants > 0 ? round(($completed_courses / $total_participants) * 100, 1) : 0;
        
        return [
            'total_participants' => $total_participants,
            'unique_courses' => count($unique_courses),
            'venues_count' => count($venues),
            'average_age' => $average_age . ' years',
            'total_revenue' => number_format($total_revenue, 2) . ' CHF',
            'completion_rate' => $completion_rate . '%'
        ];
    }
    
    /**
     * Render export section
     * 
     * @param array $rosters
     */
    private function render_export_section($rosters) {
        $export_component = new ExportComponent();
        
        ?>
        <div class="export-section">
            <h3><?php esc_html_e('Export Course Data', 'intersoccer-reports-rosters'); ?></h3>
            <?php 
            $export_component->render([
                'data' => $rosters,
                'filename_prefix' => 'intersoccer_courses',
                'formats' => ['excel', 'csv'],
                'include_summary' => true
            ]);
            ?>
        </div>
        <?php
    }
    
    /**
     * Render course table
     * 
     * @param array $rosters
     */
    private function render_course_table($rosters) {
        $table_renderer = new TableRenderer();
        
        $columns = [
            'event_name' => __('Course Name', 'intersoccer-reports-rosters'),
            'player_name' => __('Participant', 'intersoccer-reports-rosters'),
            'player_age' => __('Age', 'intersoccer-reports-rosters'),
            'age_group' => __('Age Group', 'intersoccer-reports-rosters'),
            'gender' => __('Gender', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'start_date' => __('Start Date', 'intersoccer-reports-rosters'),
            'end_date' => __('End Date', 'intersoccer-reports-rosters'),
            'skill_level' => __('Skill Level', 'intersoccer-reports-rosters'),
            'parent_email' => __('Contact Email', 'intersoccer-reports-rosters'),
            'registration_status' => __('Status', 'intersoccer-reports-rosters'),
            'order_total' => __('Fee', 'intersoccer-reports-rosters')
        ];
        
        // Format data for display
        $formatted_data = [];
        foreach ($rosters as $roster) {
            $row = (array) $roster;
            
            // Format dates
            if ($row['start_date']) {
                $row['start_date'] = date('M j, Y', strtotime($row['start_date']));
            }
            if ($row['end_date']) {
                $row['end_date'] = date('M j, Y', strtotime($row['end_date']));
            }
            
            // Format price
            if ($row['order_total']) {
                $row['order_total'] = number_format($row['order_total'], 2) . ' CHF';
            }
            
            // Format status
            $row['registration_status'] = ucfirst($row['registration_status'] ?? 'pending');
            
            // Format skill level
            $row['skill_level'] = ucfirst($row['skill_level'] ?? 'N/A');
            
            // Format gender
            $row['gender'] = ucfirst($row['gender'] ?? 'N/A');
            
            $formatted_data[] = $row;
        }
        
        $table_renderer->render($formatted_data, $columns, [
            'class' => 'intersoccer-courses-table',
            'sortable' => true,
            'pagination' => true,
            'per_page' => 25,
            'search' => true,
            'export_buttons' => true
        ]);
    }
    
    /**
     * Enqueue page-specific assets
     */
    protected function enqueue_assets() {
        wp_enqueue_script('intersoccer-rosters-tabs', $this->plugin->get_plugin_url() . 'js/rosters-tabs.js', ['jquery'], '2.0.0', true);
        wp_enqueue_style('intersoccer-rosters-css', $this->plugin->get_plugin_url() . 'css/rosters.css', [], '2.0.0');
        
        wp_localize_script('intersoccer-rosters-tabs', 'intersoccer_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('intersoccer_reports_rosters_nonce')
        ]);
    }
}