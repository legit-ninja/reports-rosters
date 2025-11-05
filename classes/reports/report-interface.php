<?php
/**
 * Report Interface
 * 
 * Interface for report functionality in InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Reports
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Reports;

defined('ABSPATH') or die('Restricted access');

interface ReportInterface {
    
    /**
     * Generate report data
     * 
     * @param array $filters Report filters
     * @return array
     */
    public function generate($filters = []);
    
    /**
     * Get report title
     * 
     * @return string
     */
    public function get_title();
    
    /**
     * Get report description
     * 
     * @return string
     */
    public function get_description();
    
    /**
     * Get available filters
     * 
     * @return array
     */
    public function get_available_filters();
    
    /**
     * Get report columns/fields
     * 
     * @return array
     */
    public function get_columns();
    
    /**
     * Validate filters
     * 
     * @param array $filters
     * @return bool
     */
    public function validate_filters($filters);
    
    /**
     * Get summary statistics
     * 
     * @param array $data
     * @return array
     */
    public function get_summary($data);
}