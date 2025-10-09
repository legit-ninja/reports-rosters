<?php
/**
 * Other Events Page
 * 
 * Other events page UI for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\UI\Pages
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\UI\Pages;

use InterSoccerReportsRosters\Data\Repositories\RosterRepository;
use InterSoccerReportsRosters\UI\Components\TabsComponent;
use InterSoccerReportsRosters\UI\Components\ExportComponent;
use InterSoccerReportsRosters\UI\Renderers\TableRenderer;

defined('ABSPATH') or die('Restricted access');

class OtherEventsPage extends AbstractPage {
    
    /**
     * Page title
     * @var string
     */
    protected $page_title = 'Other Events & Programs';
    
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
     * Render the other events page
     */
    public function render() {
        if (!$this->check_permissions()) {
            $this->render_permission_denied();
            return;
        }
        
        $this->enqueue_assets();
        $this->render_header('Other Events & Special Programs');
        
        $active_tab = $_GET['tab'] ?? 'all';
        $filters = $this->get_filters();
        
        ?>
        <div class="intersoccer-other-events-page">
            <div class="page-description">
                <p><?php esc_html_e('Special events, tournaments, workshops, and other programs that don\'t fall into the standard camps or courses categories.', 'intersoccer-reports-rosters'); ?></p>
            </div>
            
            <?php $this->render_event_tabs($active_tab); ?>
            
            <div class="tab-content">
                <?php $this->render_event_content($active_tab, $filters); ?>
            </div>
        </div>
        <?php
        
        $this->render_footer();
    }
    
    /**
     * Render event navigation tabs
     * 
     * @param string $active_tab
     */
    private function render_event_tabs($active_tab) {
        $tabs = [
            'all' => __('All Events', 'intersoccer-reports-rosters'),
            'tournaments' => __('Tournaments', 'intersoccer-reports-rosters'),
            'workshops' => __('Workshops', 'intersoccer-reports-rosters'),
            'exhibitions' => __('Exhibitions', 'intersoccer-reports-rosters'),
            'clinics' => __('Clinics', 'intersoccer-reports-rosters'),
            'misc' => __('Miscellaneous', 'intersoccer-reports-rosters')
        ];
        
        $tabs_component = new TabsComponent();
        $tabs_component->render($tabs, $active_tab, [
            'base_url' => admin_url('admin.php?page=intersoccer-other-events'),
            'preserve_params' => ['venue', 'date_from', 'date_to', 'age_group', 'gender']
        ]);
    }
    
    /**
     * Render event content based on active tab
     * 
     * @param string $active_tab
     * @param array $filters
     */
    private function render_event_content($active_tab, $filters) {
        // Get other events (exclude camps and courses)
        $all_rosters = $this->roster_repository->find_all();
        $other_events = $this->filter_other_events($all_rosters);
        
        // Filter by tab and additional filters
        $filtered_events = $this->filter_by_tab($other_events, $active_tab, $filters);
        
        ?>
        <div class="event-content">
            <?php $this->render_event_filters($filters); ?>
            
            <div class="event-stats">
                <?php $this->render_event_statistics($filtered_events); ?>
            </div>
            
            <div class="event-actions">
                <?php $this->render_export_section($filtered_events); ?>
            </div>
            
            <div class="event-table">
                <?php $this->render_events_table($filtered_events); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Filter out camps and courses to get only other events
     * 
     * @param array $rosters
     * @return array
     */
    private function filter_other_events($rosters) {
        $other_events = [];
        
        foreach ($rosters as $roster) {
            $name = strtolower($roster->product_name ?? '');
            $type = strtolower($roster->product_type ?? '');
            
            // Exclude camps and courses
            $is_camp = strpos($name, 'camp') !== false || strpos($type, 'camp') !== false;
            $is_course = strpos($name, 'course') !== false || strpos($name, 'training') !== false || strpos($type, 'course') !== false;
            
            if (!$is_camp && !$is_course) {
                $other_events[] = $roster;
            }
        }
        
        return $other_events;
    }
    
    /**
     * Filter events by tab and additional filters
     * 
     * @param array $events
     * @param string $tab
     * @param array $filters
     * @return array
     */
    private function filter_by_tab($events, $tab, $filters) {
        $filtered = [];
        
        foreach ($events as $event) {
            $include = true;
            $name = strtolower($event->product_name ?? '');
            $description = strtolower($event->event_description ?? '');
            
            // Tab-specific filtering
            switch ($tab) {
                case 'tournaments':
                    $include = strpos($name, 'tournament') !== false || 
                              strpos($name, 'competition') !== false ||
                              strpos($description, 'tournament') !== false;
                    break;
                case 'workshops':
                    $include = strpos($name, 'workshop') !== false || 
                              strpos($name, 'seminar') !== false ||
                              strpos($description, 'workshop') !== false;
                    break;
                case 'exhibitions':
                    $include = strpos($name, 'exhibition') !== false || 
                              strpos($name, 'showcase') !== false ||
                              strpos($name, 'demo') !== false;
                    break;
                case 'clinics':
                    $include = strpos($name, 'clinic') !== false ||
                              strpos($description, 'clinic') !== false;
                    break;
                case 'misc':
                    $include = !strpos($name, 'tournament') && 
                              !strpos($name, 'workshop') &&
                              !strpos($name, 'exhibition') &&
                              !strpos($name, 'clinic');
                    break;
            }
            
            if (!$include) {
                continue;
            }
            
            // Apply general filters
            if (!empty($filters['venue']) && $event->venue !== $filters['venue']) {
                $include = false;
            }
            
            if (!empty($filters['age_group']) && $event->age_group !== $filters['age_group']) {
                $include = false;
            }
            
            if (!empty($filters['gender']) && $event->gender !== $filters['gender']) {
                $include = false;
            }
            
            if (!empty($filters['date_from']) && $event->start_date < $filters['date_from']) {
                $include = false;
            }
            
            if (!empty($filters['date_to']) && $event->end_date > $filters['date_to']) {
                $include = false;
            }
            
            if ($include) {
                $filtered[] = $event;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Render event filters
     * 
     * @param array $filters
     */
    private function render_event_filters($filters) {
        $available_filters = [
            'date_from' => __('Start Date', 'intersoccer-reports-rosters'),
            'date_to' => __('End Date', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'age_group' => __('Age Group', 'intersoccer-reports-rosters'),
            'gender' => __('Gender', 'intersoccer-reports-rosters')
        ];
        
        ?>
        <div class="event-filters">
            <h3><?php esc_html_e('Filter Events', 'intersoccer-reports-rosters'); ?></h3>
            <?php $this->render_filters($available_filters, $filters); ?>
        </div>
        <?php
    }
    
    /**
     * Render event statistics
     * 
     * @param array $events
     */
    private function render_event_statistics($events) {
        $stats = $this->calculate_event_stats($events);
        
        ?>
        <div class="event-statistics">
            <h3><?php esc_html_e('Event Statistics', 'intersoccer-reports-rosters'); ?></h3>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html($stats['total_participants']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Total Participants', 'intersoccer-reports-rosters'); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-number"><?php echo esc_html($stats['unique_events']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Unique Events', 'intersoccer-reports-rosters'); ?></div>
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
                    <div class="stat-number"><?php echo esc_html($stats['most_popular_type']); ?></div>
                    <div class="stat-label"><?php esc_html_e('Most Popular Type', 'intersoccer-reports-rosters'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Calculate event statistics
     * 
     * @param array $events
     * @return array
     */
    private function calculate_event_stats($events) {
        $total_participants = count($events);
        $unique_events = [];
        $venues = [];
        $total_age = 0;
        $age_count = 0;
        $total_revenue = 0;
        $event_types = [];
        
        foreach ($events as $event) {
            // Count unique events
            if (!in_array($event->event_name, $unique_events)) {
                $unique_events[] = $event->event_name;
            }
            
            // Count venues
            if (!in_array($event->venue, $venues)) {
                $venues[] = $event->venue;
            }
            
            // Calculate average age
            if ($event->player_age && is_numeric($event->player_age)) {
                $total_age += intval($event->player_age);
                $age_count++;
            }
            
            // Sum revenue
            if ($event->order_total && is_numeric($event->order_total)) {
                $total_revenue += floatval($event->order_total);
            }
            
            // Count event types
            $type = $this->determine_event_type($event);
            $event_types[$type] = ($event_types[$type] ?? 0) + 1;
        }
        
        $average_age = $age_count > 0 ? round($total_age / $age_count, 1) : 0;
        $most_popular_type = 'N/A';
        
        if (!empty($event_types)) {
            $most_popular_type = array_keys($event_types, max($event_types))[0];
        }
        
        return [
            'total_participants' => $total_participants,
            'unique_events' => count($unique_events),
            'venues_count' => count($venues),
            'average_age' => $average_age . ' years',
            'total_revenue' => number_format($total_revenue, 2) . ' CHF',
            'most_popular_type' => ucfirst($most_popular_type)
        ];
    }
    
    /**
     * Determine event type from event data
     * 
     * @param object $event
     * @return string
     */
    private function determine_event_type($event) {
        $name = strtolower($event->product_name ?? '');
        $description = strtolower($event->event_description ?? '');
        
        if (strpos($name, 'tournament') !== false || strpos($description, 'tournament') !== false) {
            return 'tournament';
        }
        
        if (strpos($name, 'workshop') !== false || strpos($description, 'workshop') !== false) {
            return 'workshop';
        }
        
        if (strpos($name, 'exhibition') !== false || strpos($name, 'showcase') !== false) {
            return 'exhibition';
        }
        
        if (strpos($name, 'clinic') !== false || strpos($description, 'clinic') !== false) {
            return 'clinic';
        }
        
        return 'other';
    }
    
    /**
     * Render export section
     * 
     * @param array $events
     */
    private function render_export_section($events) {
        $export_component = new ExportComponent();
        
        ?>
        <div class="export-section">
            <h3><?php esc_html_e('Export Event Data', 'intersoccer-reports-rosters'); ?></h3>
            <?php 
            $export_component->render([
                'data' => $events,
                'filename_prefix' => 'intersoccer_other_events',
                'formats' => ['excel', 'csv'],
                'include_summary' => true
            ]);
            ?>
        </div>
        <?php
    }
    
    /**
     * Render events table
     * 
     * @param array $events
     */
    private function render_events_table($events) {
        $table_renderer = new TableRenderer();
        
        $columns = [
            'event_name' => __('Event Name', 'intersoccer-reports-rosters'),
            'player_name' => __('Participant', 'intersoccer-reports-rosters'),
            'player_age' => __('Age', 'intersoccer-reports-rosters'),
            'age_group' => __('Age Group', 'intersoccer-reports-rosters'),
            'gender' => __('Gender', 'intersoccer-reports-rosters'),
            'venue' => __('Venue', 'intersoccer-reports-rosters'),
            'start_date' => __('Date', 'intersoccer-reports-rosters'),
            'parent_email' => __('Contact Email', 'intersoccer-reports-rosters'),
            'event_type' => __('Event Type', 'intersoccer-reports-rosters'),
            'registration_status' => __('Status', 'intersoccer-reports-rosters'),
            'order_total' => __('Fee', 'intersoccer-reports-rosters')
        ];
        
        // Format data for display
        $formatted_data = [];
        foreach ($events as $event) {
            $row = (array) $event;
            
            // Format date
            if ($row['start_date']) {
                $row['start_date'] = date('M j, Y', strtotime($row['start_date']));
            }
            
            // Format price
            if ($row['order_total']) {
                $row['order_total'] = number_format($row['order_total'], 2) . ' CHF';
            }
            
            // Format status
            $row['registration_status'] = ucfirst($row['registration_status'] ?? 'pending');
            
            // Add event type
            $row['event_type'] = ucfirst($this->determine_event_type($event));
            
            // Format gender
            $row['gender'] = ucfirst($row['gender'] ?? 'N/A');
            
            $formatted_data[] = $row;
        }
        
        $table_renderer->render($formatted_data, $columns, [
            'class' => 'intersoccer-other-events-table',
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