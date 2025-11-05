<?php
/**
 * CSV Exporter
 * 
 * CSV export functionality for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\Export
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Export;

defined('ABSPATH') or die('Restricted access');

class CsvExporter extends AbstractExporter {
    
    /**
     * Get file extension
     * 
     * @return string
     */
    public function get_file_extension() {
        return 'csv';
    }
    
    /**
     * Get MIME type
     * 
     * @return string
     */
    public function get_mime_type() {
        return 'text/csv';
    }
    
    /**
     * Export data to CSV file
     * 
     * @param array $data
     * @param array $options
     * @return string|false
     */
    public function export($data, $options = []) {
        if (!$this->validate_data($data)) {
            return false;
        }
        
        // Process data
        $processed_data = $this->process_data($data, $options);
        $filename = $this->get_filename($options);
        $file_path = $this->get_file_path($filename);
        
        try {
            $handle = fopen($file_path, 'w');
            if (!$handle) {
                return false;
            }
            
            // Set CSV options
            $delimiter = isset($options['delimiter']) ? $options['delimiter'] : ',';
            $enclosure = isset($options['enclosure']) ? $options['enclosure'] : '"';
            $escape = isset($options['escape']) ? $options['escape'] : '\\';
            
            // Write BOM for UTF-8 support in Excel
            if (isset($options['utf8_bom']) && $options['utf8_bom']) {
                fwrite($handle, "\xEF\xBB\xBF");
            }
            
            // Get headers
            $headers = isset($options['headers']) ? $options['headers'] : array_keys($processed_data[0]);
            
            // Write header row
            fputcsv($handle, $headers, $delimiter, $enclosure, $escape);
            
            // Write data rows
            foreach ($processed_data as $row) {
                $csv_row = [];
                foreach ($headers as $header) {
                    $csv_row[] = isset($row[$header]) ? $row[$header] : '';
                }
                fputcsv($handle, $csv_row, $delimiter, $enclosure, $escape);
            }
            
            fclose($handle);
            
            $this->log_export($filename, count($processed_data), $options);
            
            return $file_path;
            
        } catch (Exception $e) {
            error_log('InterSoccer CSV Export Error: ' . $e->getMessage());
            if (isset($handle)) {
                fclose($handle);
            }
            return false;
        }
    }
    
    /**
     * Process data for CSV format
     * 
     * @param array $data
     * @param array $options
     * @return array
     */
    protected function process_data($data, $options = []) {
        $processed = [];
        
        foreach ($data as $row) {
            $processed_row = [];
            
            if (is_object($row) && method_exists($row, 'get_export_data')) {
                $processed_row = $row->get_export_data();
            } elseif (is_object($row)) {
                $processed_row = get_object_vars($row);
            } elseif (is_array($row)) {
                $processed_row = $row;
            }
            
            // Clean up data for CSV
            foreach ($processed_row as $key => $value) {
                // Handle line breaks and special characters
                if (is_string($value)) {
                    $value = trim($value);
                    // Replace line breaks with spaces for CSV compatibility
                    $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
                    // Remove extra whitespace
                    $value = preg_replace('/\s+/', ' ', $value);
                }
                $processed_row[$key] = $value;
            }
            
            $processed[] = $processed_row;
        }
        
        return $processed;
    }
    
    /**
     * Send file for download
     * 
     * @param string $file_path
     * @param string $download_name
     */
    public function send_download($file_path, $download_name = null) {
        if (!file_exists($file_path)) {
            return false;
        }
        
        if (!$download_name) {
            $download_name = basename($file_path);
        }
        
        $this->prepare_download_headers($download_name);
        
        readfile($file_path);
        
        // Clean up file after download
        unlink($file_path);
        
        exit;
    }
    
    /**
     * Export data directly to output (for immediate download)
     * 
     * @param array $data
     * @param array $options
     */
    public function export_to_output($data, $options = []) {
        if (!$this->validate_data($data)) {
            return false;
        }
        
        // Process data
        $processed_data = $this->process_data($data, $options);
        $filename = $this->get_filename($options);
        
        // Set headers for download
        $this->prepare_download_headers($filename);
        
        // Create output handle
        $handle = fopen('php://output', 'w');
        
        // Set CSV options
        $delimiter = isset($options['delimiter']) ? $options['delimiter'] : ',';
        $enclosure = isset($options['enclosure']) ? $options['enclosure'] : '"';
        $escape = isset($options['escape']) ? $options['escape'] : '\\';
        
        // Write BOM for UTF-8 support in Excel
        if (isset($options['utf8_bom']) && $options['utf8_bom']) {
            fwrite($handle, "\xEF\xBB\xBF");
        }
        
        // Get headers
        $headers = isset($options['headers']) ? $options['headers'] : array_keys($processed_data[0]);
        
        // Write header row
        fputcsv($handle, $headers, $delimiter, $enclosure, $escape);
        
        // Write data rows
        foreach ($processed_data as $row) {
            $csv_row = [];
            foreach ($headers as $header) {
                $csv_row[] = isset($row[$header]) ? $row[$header] : '';
            }
            fputcsv($handle, $csv_row, $delimiter, $enclosure, $escape);
        }
        
        fclose($handle);
        
        $this->log_export($filename, count($processed_data), $options);
        
        exit;
    }
}