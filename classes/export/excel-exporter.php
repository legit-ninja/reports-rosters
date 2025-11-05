<?php
/**
 * Excel Exporter
 * 
 * Excel export functionality for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccer\ReportsRosters\Export
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\Export;

defined('ABSPATH') or die('Restricted access');

class ExcelExporter extends AbstractExporter {
    
    /**
     * Get file extension
     * 
     * @return string
     */
    public function get_file_extension() {
        return 'xlsx';
    }
    
    /**
     * Get MIME type
     * 
     * @return string
     */
    public function get_mime_type() {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
    
    /**
     * Export data to Excel file
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
            // Create Excel content using simple XML approach
            $excel_content = $this->create_excel_xml($processed_data, $options);
            
            if (file_put_contents($file_path, $excel_content) === false) {
                return false;
            }
            
            $this->log_export($filename, count($processed_data), $options);
            
            return $file_path;
            
        } catch (Exception $e) {
            error_log('InterSoccer Excel Export Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create Excel XML content
     * 
     * @param array $data
     * @param array $options
     * @return string
     */
    private function create_excel_xml($data, $options = []) {
        if (empty($data)) {
            return '';
        }
        
        // Get headers from first row or options
        $headers = isset($options['headers']) ? $options['headers'] : array_keys($data[0]);
        
        // Start XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        $xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
        $xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
        $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        $xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        
        // Add styles
        $xml .= $this->get_excel_styles();
        
        // Add worksheet
        $worksheet_name = isset($options['sheet_name']) ? $options['sheet_name'] : 'InterSoccer Export';
        $xml .= '<Worksheet ss:Name="' . htmlspecialchars($worksheet_name) . '">' . "\n";
        $xml .= '<Table>' . "\n";
        
        // Add header row
        $xml .= '<Row ss:StyleID="HeaderStyle">' . "\n";
        foreach ($headers as $header) {
            $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>' . "\n";
        }
        $xml .= '</Row>' . "\n";
        
        // Add data rows
        foreach ($data as $row) {
            $xml .= '<Row>' . "\n";
            foreach ($headers as $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $type = is_numeric($value) ? 'Number' : 'String';
                $xml .= '<Cell><Data ss:Type="' . $type . '">' . htmlspecialchars($value) . '</Data></Cell>' . "\n";
            }
            $xml .= '</Row>' . "\n";
        }
        
        $xml .= '</Table>' . "\n";
        $xml .= '</Worksheet>' . "\n";
        $xml .= '</Workbook>';
        
        return $xml;
    }
    
    /**
     * Get Excel styles
     * 
     * @return string
     */
    private function get_excel_styles() {
        return '<Styles>
            <Style ss:ID="HeaderStyle">
                <Font ss:Bold="1"/>
                <Interior ss:Color="#E0E0E0" ss:Pattern="Solid"/>
                <Borders>
                    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
                    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
                    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
                    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
                </Borders>
            </Style>
        </Styles>' . "\n";
    }
    
    /**
     * Process data for Excel format
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
            
            // Clean up data for Excel
            foreach ($processed_row as $key => $value) {
                // Handle special characters and formatting
                if (is_string($value)) {
                    $value = trim($value);
                    // Remove any control characters
                    $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
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
}