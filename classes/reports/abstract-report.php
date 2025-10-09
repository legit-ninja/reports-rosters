<?php
/**
 * Abstract Report
 * 
 * Base class for report functionality in InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Reports
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\Reports;

defined('ABSPATH') or die('Restricted access');

abstract class AbstractReport implements ReportInterface {
    
    /**
     * Report title
     * @var string
     */
    protected $title = '';
    
    /**
     * Report description
     * @var string
     */
    protected $description = '';
    
    /**
     * Available filters
     * @var array
     */
    protected $available_filters = [];
    
    /**
     * Report columns
     * @var array
     */
    protected $columns = [];
    
    /**
     * Database connection
     * @var wpdb
     */
    protected $db;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }
    
    /**
     * Get report title
     * 
     * @return string
     */
    public function get_title() {
        return $this->title;
    }
    
    /**
     * Get report description
     * 
     * @return string
     */
    public function get_description() {
        return $this->description;
    }
    
    /**
     * Get available filters
     * 
     * @return array
     */
    public function get_available_filters() {
        return $this->available_filters;
    }
    
    /**
     * Get report columns
     * 
     * @return array
     */
    public function get_columns() {
        return $this->columns;
    }
    
    /**
     * Validate filters
     * 
     * @param array $filters
     * @return bool
     */
    public function validate_filters($filters) {
        // Basic validation - override in child classes for specific validation
        foreach ($filters as $key => $value) {
            if (!in_array($key, array_keys($this->available_filters))) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Apply filters to query
     * 
     * @param array $filters
     * @param string $table_alias
     * @return array [where_conditions, params]
     */
    protected function apply_filters($filters, $table_alias = '') {
        $where_conditions = [];
        $params = [];
        $prefix = $table_alias ? $table_alias . '.' : '';
        
        // Date range filters
        if (!empty($filters['date_from'])) {
            $where_conditions[] = "{$prefix}start_date >= %s";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where_conditions[] = "{$prefix}end_date <= %s";
            $params[] = $filters['date_to'];
        }
        
        // Venue filter
        if (!empty($filters['venue'])) {
            $where_conditions[] = "{$prefix}venue = %s";
            $params[] = $filters['venue'];
        }
        
        // Gender filter
        if (!empty($filters['gender'])) {
            $where_conditions[] = "{$prefix}gender = %s";
            $params[] = $filters['gender'];
        }
        
        // Age group filter
        if (!empty($filters['age_group'])) {
            $where_conditions[] = "{$prefix}age_group = %s";
            $params[] = $filters['age_group'];
        }
        
        // Event type filter
        if (!empty($filters['event_type'])) {
            $where_conditions[] = "{$prefix}product_type LIKE %s";
            $params[] = '%' . $filters['event_type'] . '%';
        }
        
        // Payment status filter
        if (!empty($filters['payment_status'])) {
            $where_conditions[] = "{$prefix}payment_status = %s";
            $params[] = $filters['payment_status'];
        }
        
        return [$where_conditions, $params];
    }
    
    /**
     * Format currency value
     * 
     * @param float $value
     * @return string
     */
    protected function format_currency($value) {
        return number_format($value, 2) . ' CHF';
    }
    
    /**
     * Format date
     * 
     * @param string $date
     * @param string $format
     * @return string
     */
    protected function format_date($date, $format = 'M j, Y') {
        if (empty($date)) {
            return 'N/A';
        }
        
        return date($format, strtotime($date));
    }
    
    /**
     * Calculate percentage
     * 
     * @param int $part
     * @param int $total
     * @return float
     */
    protected function calculate_percentage($part, $total) {
        if ($total == 0) {
            return 0;
        }
        
        return round(($part / $total) * 100, 1);
    }
    
    /**
     * Get default summary statistics
     * 
     * @param array $data
     * @return array
     */
    public function get_summary($data) {
        $total_records = count($data);
        $total_revenue = 0;
        $avg_age = 0;
        $age_count = 0;
        
        foreach ($data as $row) {
            // Calculate total revenue
            if (isset($row['order_total']) && is_numeric($row['order_total'])) {
                $total_revenue += floatval($row['order_total']);
            }
            
            // Calculate average age
            if (isset($row['player_age']) && is_numeric($row['player_age'])) {
                $avg_age += intval($row['player_age']);
                $age_count++;
            }
        }
        
        $avg_age = $age_count > 0 ? round($avg_age / $age_count, 1) : 0;
        
        return [
            'total_records' => $total_records,
            'total_revenue' => $this->format_currency($total_revenue),
            'average_age' => $avg_age . ' years',
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Log report generation
     * 
     * @param array $filters
     * @param int $record_count
     */
    protected function log_report_generation($filters, $record_count) {
        error_log(sprintf(
            'InterSoccer Report: %s generated with %d records (filters: %s)',
            get_class($this),
            $record_count,
            json_encode($filters)
        ));
    }
}