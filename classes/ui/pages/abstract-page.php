<?php
/**
 * Abstract Page
 * 
 * Base class for UI pages in InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\UI\Pages
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\UI\Pages;

use InterSoccerReportsRosters\Core\Plugin;

defined('ABSPATH') or die('Restricted access');

abstract class AbstractPage {
    
    /**
     * Plugin instance
     * @var Plugin
     */
    protected $plugin;
    
    /**
     * Page title
     * @var string
     */
    protected $page_title = '';
    
    /**
     * Page capability required
     * @var string
     */
    protected $capability = 'intersoccer_view_reports';
    
    /**
     * Constructor
     * 
     * @param Plugin $plugin
     */
    public function __construct(Plugin $plugin) {
        $this->plugin = $plugin;
    }
    
    /**
     * Render the page
     */
    abstract public function render();
    
    /**
     * Check user permissions
     * 
     * @return bool
     */
    protected function check_permissions() {
        return current_user_can($this->capability);
    }
    
    /**
     * Render permission denied message
     */
    protected function render_permission_denied() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Access Denied', 'intersoccer-reports-rosters') . '</h1>';
        echo '<p>' . esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-reports-rosters') . '</p>';
        echo '</div>';
    }
    
    /**
     * Get page title
     * 
     * @return string
     */
    public function get_title() {
        return $this->page_title;
    }
    
    /**
     * Render page header
     * 
     * @param string $title
     */
    protected function render_header($title = null) {
        $title = $title ?: $this->page_title;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($title); ?></h1>
        <?php
    }
    
    /**
     * Render page footer
     */
    protected function render_footer() {
        ?>
        </div>
        <?php
    }
    
    /**
     * Enqueue page-specific scripts and styles
     */
    protected function enqueue_assets() {
        // Override in child classes
    }
    
    /**
     * Handle AJAX requests
     */
    protected function handle_ajax() {
        // Override in child classes
    }
    
    /**
     * Get current filters from request
     * 
     * @return array
     */
    protected function get_filters() {
        return [
            'venue' => sanitize_text_field($_GET['venue'] ?? ''),
            'age_group' => sanitize_text_field($_GET['age_group'] ?? ''),
            'gender' => sanitize_text_field($_GET['gender'] ?? ''),
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_GET['date_to'] ?? ''),
            'event_type' => sanitize_text_field($_GET['event_type'] ?? ''),
            'payment_status' => sanitize_text_field($_GET['payment_status'] ?? '')
        ];
    }
    
    /**
     * Render filter form
     * 
     * @param array $available_filters
     * @param array $current_filters
     */
    protected function render_filters($available_filters, $current_filters = []) {
        ?>
        <form method="get" class="intersoccer-filters">
            <?php
            // Preserve other query parameters
            foreach ($_GET as $key => $value) {
                if (!in_array($key, array_keys($available_filters))) {
                    echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
                }
            }
            ?>
            
            <div class="filter-row">
                <?php foreach ($available_filters as $key => $label): ?>
                    <div class="filter-field">
                        <label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                        <?php $this->render_filter_field($key, $current_filters[$key] ?? ''); ?>
                    </div>
                <?php endforeach; ?>
                
                <div class="filter-actions">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Apply Filters', 'intersoccer-reports-rosters'); ?>
                    </button>
                    <a href="<?php echo esc_url(remove_query_arg(array_keys($available_filters))); ?>" class="button">
                        <?php esc_html_e('Clear Filters', 'intersoccer-reports-rosters'); ?>
                    </a>
                </div>
            </div>
        </form>
        <?php
    }
    
    /**
     * Render individual filter field
     * 
     * @param string $key
     * @param string $value
     */
    protected function render_filter_field($key, $value) {
        switch ($key) {
            case 'venue':
                $this->render_venue_select($value);
                break;
            case 'age_group':
                $this->render_age_group_select($value);
                break;
            case 'gender':
                $this->render_gender_select($value);
                break;
            case 'date_from':
            case 'date_to':
                echo '<input type="date" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
                break;
            case 'event_type':
                $this->render_event_type_select($value);
                break;
            case 'payment_status':
                $this->render_payment_status_select($value);
                break;
            default:
                echo '<input type="text" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
                break;
        }
    }
    
    /**
     * Render venue select dropdown
     * 
     * @param string $selected
     */
    protected function render_venue_select($selected) {
        global $wpdb;
        $table = $wpdb->prefix . 'intersoccer_rosters';
        $venues = $wpdb->get_col("SELECT DISTINCT venue FROM {$table} WHERE venue != '' ORDER BY venue");
        
        echo '<select name="venue">';
        echo '<option value="">' . esc_html__('All Venues', 'intersoccer-reports-rosters') . '</option>';
        foreach ($venues as $venue) {
            echo '<option value="' . esc_attr($venue) . '"' . selected($selected, $venue, false) . '>';
            echo esc_html($venue);
            echo '</option>';
        }
        echo '</select>';
    }
    
    /**
     * Render age group select dropdown
     * 
     * @param string $selected
     */
    protected function render_age_group_select($selected) {
        $age_groups = ['U7', 'U9', 'U11', 'U13', 'U15', 'U17', 'U19', 'Adult'];
        
        echo '<select name="age_group">';
        echo '<option value="">' . esc_html__('All Ages', 'intersoccer-reports-rosters') . '</option>';
        foreach ($age_groups as $age_group) {
            echo '<option value="' . esc_attr($age_group) . '"' . selected($selected, $age_group, false) . '>';
            echo esc_html($age_group);
            echo '</option>';
        }
        echo '</select>';
    }
    
    /**
     * Render gender select dropdown
     * 
     * @param string $selected
     */
    protected function render_gender_select($selected) {
        $genders = [
            'male' => __('Male', 'intersoccer-reports-rosters'),
            'female' => __('Female', 'intersoccer-reports-rosters'),
            'other' => __('Other', 'intersoccer-reports-rosters')
        ];
        
        echo '<select name="gender">';
        echo '<option value="">' . esc_html__('All Genders', 'intersoccer-reports-rosters') . '</option>';
        foreach ($genders as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }
        echo '</select>';
    }
    
    /**
     * Render event type select dropdown
     * 
     * @param string $selected
     */
    protected function render_event_type_select($selected) {
        $event_types = [
            'camp' => __('Camps', 'intersoccer-reports-rosters'),
            'course' => __('Courses', 'intersoccer-reports-rosters'),
            'girls_only' => __('Girls Only', 'intersoccer-reports-rosters'),
            'other' => __('Other', 'intersoccer-reports-rosters')
        ];
        
        echo '<select name="event_type">';
        echo '<option value="">' . esc_html__('All Types', 'intersoccer-reports-rosters') . '</option>';
        foreach ($event_types as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }
        echo '</select>';
    }
    
    /**
     * Render payment status select dropdown
     * 
     * @param string $selected
     */
    protected function render_payment_status_select($selected) {
        $statuses = [
            'completed' => __('Paid', 'intersoccer-reports-rosters'),
            'pending' => __('Pending', 'intersoccer-reports-rosters'),
            'failed' => __('Failed', 'intersoccer-reports-rosters'),
            'cancelled' => __('Cancelled', 'intersoccer-reports-rosters')
        ];
        
        echo '<select name="payment_status">';
        echo '<option value="">' . esc_html__('All Statuses', 'intersoccer-reports-rosters') . '</option>';
        foreach ($statuses as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }
        echo '</select>';
    }
}