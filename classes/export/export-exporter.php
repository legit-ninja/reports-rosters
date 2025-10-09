<?php
/**
 * Abstract Exporter
 * 
 * Base class for export functionality in InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Export
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\Export;

defined('ABSPATH') or die('Restricted access');

abstract class AbstractExporter implements ExporterInterface {
    
    /**
     * Export directory
     * @var string
     */
    protected $export_dir;
    
    /**
     * Default filename prefix
     * @var string
     */
    protected $filename_prefix = 'intersoccer_export';
    
    /**
     * Constructor
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->export_dir = $upload_dir['basedir'] . '/intersoccer-exports/';
        
        // Create export directory if it doesn't exist
        if (!file_exists($this->export_dir)) {
            wp_mkdir_p($this->export_dir);
            
            // Create .htaccess to protect downloads
            file_put_contents($this->export_dir . '.htaccess', "deny from all\n");
        }
    }
    
    /**
     * Validate export data
     * 
     * @param array $data
     * @return bool
     */
    public function validate_data($data) {
        return is_array($data) && !empty($data);
    }
    
    /**
     * Get export filename
     * 
     * @param array $options
     * @return string
     */
    public function get_filename($options = []) {
        $prefix = isset($options['prefix']) ? $options['prefix'] : $this->filename_prefix;
        $timestamp = isset($options['timestamp']) ? $options['timestamp'] : date('Y-m-d_H-i-s');
        $suffix = isset($options['suffix']) ? '_' . $options['suffix'] : '';
        
        return $prefix . '_' . $timestamp . $suffix . '.' . $this->get_file_extension();
    }
    
    /**
     * Get full file path
     * 
     * @param string $filename
     * @return string
     */
    protected function get_file_path($filename) {
        return $this->export_dir . $filename;
    }
    
    /**
     * Clean old export files
     * 
     * @param int $max_age_hours
     */
    public function clean_old_files($max_age_hours = 24) {
        if (!is_dir($this->export_dir)) {
            return;
        }
        
        $files = scandir($this->export_dir);
        $cutoff_time = time() - ($max_age_hours * 3600);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess') {
                continue;
            }
            
            $file_path = $this->export_dir . $file;
            if (is_file($file_path) && filemtime($file_path) < $cutoff_time) {
                unlink($file_path);
            }
        }
    }
    
    /**
     * Prepare headers for download
     * 
     * @param string $filename
     */
    protected function prepare_download_headers($filename) {
        if (headers_sent()) {
            return;
        }
        
        header('Content-Type: ' . $this->get_mime_type());
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
    }
    
    /**
     * Process data before export
     * 
     * @param array $data
     * @param array $options
     * @return array
     */
    protected function process_data($data, $options = []) {
        // Override in child classes for specific processing
        return $data;
    }
    
    /**
     * Log export activity
     * 
     * @param string $filename
     * @param int $record_count
     * @param array $options
     */
    protected function log_export($filename, $record_count, $options = []) {
        error_log(sprintf(
            'InterSoccer Export: %s exported %d records to %s',
            get_class($this),
            $record_count,
            $filename
        ));
    }
}