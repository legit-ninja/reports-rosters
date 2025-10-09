<?php
/**
 * Tabs Component
 * 
 * Reusable tabs component for InterSoccer Reports & Rosters Plugin
 * 
 * @package InterSoccerReportsRosters\UI\Components
 * @version 2.0.0
 */

namespace InterSoccerReportsRosters\UI\Components;

defined('ABSPATH') or die('Restricted access');

class TabsComponent {
    
    /**
     * Render tabs navigation
     * 
     * @param array $tabs Array of tab_key => tab_label
     * @param string $active_tab Currently active tab
     * @param array $options Additional options
     */
    public function render($tabs, $active_tab, $options = []) {
        $base_url = $options['base_url'] ?? '';
        $preserve_params = $options['preserve_params'] ?? [];
        $class = $options['class'] ?? 'intersoccer-tabs';
        
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <?php 
                    $tab_url = $this->build_tab_url($base_url, $tab_key, $preserve_params);
                    $is_active = ($active_tab === $tab_key);
                    $tab_class = 'nav-tab' . ($is_active ? ' nav-tab-active' : '');
                    ?>
                    <a href="<?php echo esc_url($tab_url); ?>" 
                       class="<?php echo esc_attr($tab_class); ?>"
                       <?php if ($is_active): ?>aria-current="page"<?php endif; ?>>
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }
    
    /**
     * Build tab URL with preserved parameters
     * 
     * @param string $base_url
     * @param string $tab_key
     * @param array $preserve_params
     * @return string
     */
    private function build_tab_url($base_url, $tab_key, $preserve_params = []) {
        $url_parts = parse_url($base_url);
        $query_params = [];
        
        // Parse existing query parameters
        if (isset($url_parts['query'])) {
            parse_str($url_parts['query'], $query_params);
        }
        
        // Add preserved parameters from current request
        foreach ($preserve_params as $param) {
            if (isset($_GET[$param]) && !empty($_GET[$param])) {
                $query_params[$param] = sanitize_text_field($_GET[$param]);
            }
        }
        
        // Add tab parameter
        $query_params['tab'] = $tab_key;
        
        // Rebuild URL
        $url = $url_parts['scheme'] . '://' . $url_parts['host'];
        if (isset($url_parts['port'])) {
            $url .= ':' . $url_parts['port'];
        }
        $url .= $url_parts['path'];
        
        if (!empty($query_params)) {
            $url .= '?' . http_build_query($query_params);
        }
        
        return $url;
    }
    
    /**
     * Render tab content wrapper
     * 
     * @param string $tab_id
     * @param bool $is_active
     * @param array $options
     */
    public function render_tab_content_start($tab_id, $is_active = false, $options = []) {
        $class = $options['class'] ?? 'tab-content';
        $style = $is_active ? '' : 'display: none;';
        
        ?>
        <div id="<?php echo esc_attr($tab_id); ?>" 
             class="<?php echo esc_attr($class . ($is_active ? ' active' : '')); ?>"
             style="<?php echo esc_attr($style); ?>">
        <?php
    }
    
    /**
     * Close tab content wrapper
     */
    public function render_tab_content_end() {
        ?>
        </div>
        <?php
    }
    
    /**
     * Render JavaScript for tab switching (if needed for AJAX tabs)
     * 
     * @param array $options
     */
    public function render_tab_script($options = []) {
        $ajax_enabled = $options['ajax'] ?? false;
        $nonce = $options['nonce'] ?? '';
        
        if (!$ajax_enabled) {
            return;
        }
        
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.intersoccer-tabs .nav-tab').on('click', function(e) {
                e.preventDefault();
                
                var $tab = $(this);
                var tabUrl = $tab.attr('href');
                var urlParams = new URLSearchParams(tabUrl.split('?')[1]);
                var activeTab = urlParams.get('tab');
                
                // Update active tab visually
                $('.intersoccer-tabs .nav-tab').removeClass('nav-tab-active');
                $tab.addClass('nav-tab-active');
                
                // Load tab content via AJAX
                $.ajax({
                    url: intersoccer_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'intersoccer_load_tab',
                        tab: activeTab,
                        nonce: '<?php echo esc_js($nonce); ?>',
                        // Add other parameters as needed
                    },
                    beforeSend: function() {
                        $('.tab-content').html('<div class="loading">Loading...</div>');
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.tab-content').html(response.data);
                        } else {
                            $('.tab-content').html('<div class="error">Error loading content</div>');
                        }
                    },
                    error: function() {
                        $('.tab-content').html('<div class="error">Error loading content</div>');
                    }
                });
                
                // Update URL without page reload
                if (history.pushState) {
                    history.pushState(null, null, tabUrl);
                }
            });
        });
        </script>
        <?php
    }
}