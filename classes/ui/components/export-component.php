<?php
/**
 * Export Component
 * 
 * Reusable export component for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\UI\Components
 * @version 2.0.0
 */

namespace InterSoccer\ReportsRosters\UI\Components;

defined('ABSPATH') or die('Restricted access');

class ExportComponent {
    
    /**
     * Render export buttons and form
     * 
     * @param array $options Export options
     */
    public function render($options = []) {
        $data = $options['data'] ?? [];
        $filename_prefix = $options['filename_prefix'] ?? 'intersoccer_export';
        $formats = $options['formats'] ?? ['excel', 'csv'];
        $include_summary = $options['include_summary'] ?? false;
        $class = $options['class'] ?? 'intersoccer-export';
        
        if (empty($data)) {
            $this->render_no_data_message();
            return;
        }
        
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <div class="export-header">
                <h4><?php esc_html_e('Export Options', 'intersoccer-reports-rosters'); ?></h4>
                <p><?php printf(esc_html__('Export %d records to your preferred format.', 'intersoccer-reports-rosters'), count($data)); ?></p>
            </div>
            
            <form method="post" class="export-form" data-export-action="intersoccer_export_data">
                <?php wp_nonce_field('intersoccer_export_nonce', 'export_nonce'); ?>
                
                <input type="hidden" name="filename_prefix" value="<?php echo esc_attr($filename_prefix); ?>">
                <input type="hidden" name="data_count" value="<?php echo esc_attr(count($data)); ?>">
                
                <div class="export-options">
                    <div class="export-formats">
                        <label><?php esc_html_e('Format:', 'intersoccer-reports-rosters'); ?></label>
                        <?php foreach ($formats as $format): ?>
                            <label class="format-option">
                                <input type="radio" name="export_format" value="<?php echo esc_attr($format); ?>" 
                                       <?php checked(in_array($format, ['excel']) ? $format : 'csv', $format); ?>>
                                <span class="format-label">
                                    <?php echo esc_html($this->get_format_label($format)); ?>
                                    <small><?php echo esc_html($this->get_format_description($format)); ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($include_summary): ?>
                    <div class="export-summary-option">
                        <label>
                            <input type="checkbox" name="include_summary" value="1" checked>
                            <?php esc_html_e('Include summary statistics', 'intersoccer-reports-rosters'); ?>
                        </label>
                    </div>
                    <?php endif; ?>
                    
                    <div class="export-filename">
                        <label for="custom-filename"><?php esc_html_e('Custom filename (optional):', 'intersoccer-reports-rosters'); ?></label>
                        <input type="text" id="custom-filename" name="custom_filename" 
                               placeholder="<?php echo esc_attr($filename_prefix . '_' . date('Y-m-d')); ?>">
                        <small><?php esc_html_e('Leave blank for automatic naming', 'intersoccer-reports-rosters'); ?></small>
                    </div>
                </div>
                
                <div class="export-actions">
                    <button type="submit" class="button button-primary export-button">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Export Data', 'intersoccer-reports-rosters'); ?>
                    </button>
                    
                    <div class="export-status" style="display: none;">
                        <span class="dashicons dashicons-update spin"></span>
                        <span class="status-text"><?php esc_html_e('Preparing export...', 'intersoccer-reports-rosters'); ?></span>
                    </div>
                </div>
            </form>
            
            <?php $this->render_export_info($data); ?>
        </div>
        
        <?php $this->render_export_script(); ?>
        <?php
    }
    
    /**
     * Render quick export buttons (simplified version)
     * 
     * @param array $options
     */
    public function render_quick_buttons($options = []) {
        $data = $options['data'] ?? [];
        $filename_prefix = $options['filename_prefix'] ?? 'intersoccer_export';
        $formats = $options['formats'] ?? ['excel', 'csv'];
        
        if (empty($data)) {
            return;
        }
        
        ?>
        <div class="intersoccer-quick-export">
            <span class="export-label"><?php esc_html_e('Quick Export:', 'intersoccer-reports-rosters'); ?></span>
            
            <?php foreach ($formats as $format): ?>
                <a href="#" class="button quick-export-btn" 
                   data-format="<?php echo esc_attr($format); ?>"
                   data-filename="<?php echo esc_attr($filename_prefix); ?>"
                   data-count="<?php echo esc_attr(count($data)); ?>">
                    <span class="dashicons dashicons-media-spreadsheet"></span>
                    <?php echo esc_html(strtoupper($format)); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }
    
    /**
     * Get format label
     * 
     * @param string $format
     * @return string
     */
    private function get_format_label($format) {
        $labels = [
            'excel' => __('Excel (.xlsx)', 'intersoccer-reports-rosters'),
            'csv' => __('CSV (.csv)', 'intersoccer-reports-rosters'),
            'pdf' => __('PDF (.pdf)', 'intersoccer-reports-rosters')
        ];
        
        return $labels[$format] ?? strtoupper($format);
    }
    
    /**
     * Get format description
     * 
     * @param string $format
     * @return string
     */
    private function get_format_description($format) {
        $descriptions = [
            'excel' => __('Best for analysis and formatting', 'intersoccer-reports-rosters'),
            'csv' => __('Universal format, works with all applications', 'intersoccer-reports-rosters'),
            'pdf' => __('Print-ready format', 'intersoccer-reports-rosters')
        ];
        
        return $descriptions[$format] ?? '';
    }
    
    /**
     * Render export information
     * 
     * @param array $data
     */
    private function render_export_info($data) {
        $record_count = count($data);
        $sample_record = !empty($data) ? (array) $data[0] : [];
        $field_count = count($sample_record);
        
        ?>
        <div class="export-info">
            <h5><?php esc_html_e('Export Information', 'intersoccer-reports-rosters'); ?></h5>
            <ul>
                <li><?php printf(esc_html__('Records: %d', 'intersoccer-reports-rosters'), $record_count); ?></li>
                <li><?php printf(esc_html__('Fields: %d', 'intersoccer-reports-rosters'), $field_count); ?></li>
                <li><?php printf(esc_html__('Estimated file size: %s', 'intersoccer-reports-rosters'), $this->estimate_file_size($record_count, $field_count)); ?></li>
            </ul>
            
            <?php if (!empty($sample_record)): ?>
            <details class="export-preview">
                <summary><?php esc_html_e('Preview Fields', 'intersoccer-reports-rosters'); ?></summary>
                <div class="fields-list">
                    <?php foreach (array_keys($sample_record) as $field): ?>
                        <span class="field-tag"><?php echo esc_html($field); ?></span>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render no data message
     */
    private function render_no_data_message() {
        ?>
        <div class="intersoccer-export no-data">
            <div class="no-data-message">
                <span class="dashicons dashicons-info"></span>
                <p><?php esc_html_e('No data available for export. Please adjust your filters or check back later.', 'intersoccer-reports-rosters'); ?></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Estimate file size
     * 
     * @param int $record_count
     * @param int $field_count
     * @return string
     */
    private function estimate_file_size($record_count, $field_count) {
        // Very rough estimation
        $avg_field_size = 20; // bytes per field
        $estimated_bytes = $record_count * $field_count * $avg_field_size;
        
        if ($estimated_bytes < 1024) {
            return $estimated_bytes . ' B';
        } elseif ($estimated_bytes < 1024 * 1024) {
            return round($estimated_bytes / 1024, 1) . ' KB';
        } else {
            return round($estimated_bytes / (1024 * 1024), 1) . ' MB';
        }
    }
    
    /**
     * Render JavaScript for export functionality
     */
    private function render_export_script() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Handle export form submission
            $('.export-form').on('submit', function(e) {
                e.preventDefault();
                
                var $form = $(this);
                var $button = $form.find('.export-button');
                var $status = $form.find('.export-status');
                
                // Show loading state
                $button.prop('disabled', true);
                $status.show();
                
                // Create hidden form for file download
                var $downloadForm = $('<form>', {
                    method: 'post',
                    action: intersoccer_ajax.ajax_url,
                    style: 'display: none;'
                });
                
                // Add form data
                $form.find('input, select').each(function() {
                    var $input = $(this);
                    if ($input.attr('type') === 'radio' || $input.attr('type') === 'checkbox') {
                        if ($input.is(':checked')) {
                            $downloadForm.append($input.clone());
                        }
                    } else {
                        $downloadForm.append($input.clone());
                    }
                });
                
                // Add AJAX action
                $downloadForm.append('<input type="hidden" name="action" value="intersoccer_export_download">');
                
                // Append to body and submit
                $('body').append($downloadForm);
                $downloadForm.submit();
                
                // Clean up and reset UI
                setTimeout(function() {
                    $downloadForm.remove();
                    $button.prop('disabled', false);
                    $status.hide();
                }, 2000);
            });
            
            // Handle quick export buttons
            $('.quick-export-btn').on('click', function(e) {
                e.preventDefault();
                
                var $btn = $(this);
                var format = $btn.data('format');
                var filename = $btn.data('filename');
                var count = $btn.data('count');
                
                // Create and submit quick export form
                var $quickForm = $('<form>', {
                    method: 'post',
                    action: intersoccer_ajax.ajax_url,
                    style: 'display: none;'
                });
                
                $quickForm.append('<input type="hidden" name="action" value="intersoccer_export_download">');
                $quickForm.append('<input type="hidden" name="export_format" value="' + format + '">');
                $quickForm.append('<input type="hidden" name="filename_prefix" value="' + filename + '">');
                $quickForm.append('<input type="hidden" name="export_nonce" value="' + $('#export_nonce').val() + '">');
                
                $('body').append($quickForm);
                $quickForm.submit();
                
                setTimeout(function() {
                    $quickForm.remove();
                }, 1000);
            });
        });
        </script>
        <?php
    }
}