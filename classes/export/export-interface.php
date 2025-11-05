<?php
/**
 * Exporter Interface
 * 
 * Interface for export functionality in InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Export
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Export;

defined('ABSPATH') or die('Restricted access');

interface ExporterInterface {
    
    /**
     * Export data to file
     * 
     * @param array $data Data to export
     * @param array $options Export options
     * @return string|false File path on success, false on failure
     */
    public function export($data, $options = []);
    
    /**
     * Get supported file extension
     * 
     * @return string
     */
    public function get_file_extension();
    
    /**
     * Get MIME type
     * 
     * @return string
     */
    public function get_mime_type();
    
    /**
     * Validate export data
     * 
     * @param array $data
     * @return bool
     */
    public function validate_data($data);
    
    /**
     * Get export filename
     * 
     * @param array $options
     * @return string
     */
    public function get_filename($options = []);
}