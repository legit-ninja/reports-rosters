<?php
/**
 * InterSoccer Charts Component
 * 
 * Renders interactive charts using Chart.js for the dashboard and reports.
 * Provides a consistent charting interface across all pages.
 * 
 * @package InterSoccer_Reports_Rosters
 * @subpackage UI\Components
 * @version 1.0.0
 */

namespace InterSoccer\UI\Components;

use InterSoccer\Core\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class ChartsComponent {

    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;

    /**
     * Chart counter for unique IDs
     * 
     * @var int
     */
    private static $chart_counter = 0;

    /**
     * Default chart colors (InterSoccer brand palette)
     * 
     * @var array
     */
    private $brand_colors = [
        'primary' => '#1e40af',    // Blue
        'secondary' => '#059669',   // Green
        'accent' => '#dc2626',     // Red
        'warning' => '#d97706',    // Orange
        'info' => '#0891b2',       // Cyan
        'success' => '#16a34a',    // Green
        'purple' => '#9333ea',     // Purple
        'pink' => '#e11d48',       // Pink
        'indigo' => '#4f46e5',     // Indigo
        'teal' => '#0d9488'        // Teal
    ];

    /**
     * Constructor
     * 
     * @param Logger $logger Logger instance
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
        
        $this->logger->debug('ChartsComponent initialized');
    }

    /**
     * Render venue attendance chart
     * 
     * @param array $venue_data Venue attendance data
     * @param array $options    Chart options
     */
    public function render_venue_chart($venue_data, $options = []) {
        $chart_id = 'venue-chart-' . ++self::$chart_counter;
        
        $defaults = [
            'type' => 'bar',
            'title' => __('Attendance by Venue', 'intersoccer-reports-rosters'),
            'height' => 300,
            'colors' => [$this->brand_colors['primary']],
            'responsive' => true
        ];
        
        $options = array_merge($defaults, $options);
        
        if (empty($venue_data)) {
            $this->render_no_data_chart($chart_id, $options['title']);
            return;
        }
        
        // Prepare data
        $labels = array_column($venue_data, 'venue');
        $data = array_column($venue_data, 'count');
        
        // Truncate venue names for display
        $labels = array_map(function($venue) {
            return strlen($venue) > 20 ? substr($venue, 0, 17) . '...' : $venue;
        }, $labels);
        
        $chart_config = [
            'type' => $options['type'],
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => __('Attendees', 'intersoccer-reports-rosters'),
                    'data' => array_map('intval', $data),
                    'backgroundColor' => $this->generate_color_array($options['colors'], count($data)),
                    'borderColor' => $options['colors'][0],
                    'borderWidth' => 2,
                    'borderRadius' => 4,
                    'borderSkipped' => false,
                ]]
            ],
            'options' => [
                'responsive' => $options['responsive'],
                'plugins' => [
                    'title' => [
                        'display' => false
                    ],
                    'legend' => [
                        'display' => false
                    ],
                    'tooltip' => [
                        'callbacks' => [
                            'title' => 'js:function(context) { return context[0].label; }',
                            'label' => 'js:function(context) { return context.parsed.y + " attendees"; }'
                        ]
                    ]
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'ticks' => [
                            'stepSize' => 1
                        ],
                        'title' => [
                            'display' => true,
                            'text' => __('Number of Attendees', 'intersoccer-reports-rosters')
                        ]
                    ],
                    'x' => [
                        'title' => [
                            'display' => true,
                            'text' => __('Venues', 'intersoccer-reports-rosters')
                        ]
                    ]
                ]
            ]
        ];
        
        $this->render_chart($chart_id, $chart_config, $options);
    }

    /**
     * Render age distribution chart
     * 
     * @param array $age_data Age distribution data
     * @param array $options  Chart options
     */
    public function render_age_distribution_chart($age_data, $options = []) {
        $chart_id = 'age-chart-' . ++self::$chart_counter;
        
        $defaults = [
            'type' => 'doughnut',
            'title' => __('Age Group Distribution', 'intersoccer-reports-rosters'),
            'height' => 300,
            'colors' => array_values($this->brand_colors),
            'responsive' => true
        ];
        
        $options = array_merge($defaults, $options);
        
        if (empty($age_data)) {
            $this->render_no_data_chart($chart_id, $options['title']);
            return;
        }
        
        // Prepare data
        $labels = array_column($age_data, 'age_group');
        $data = array_column($age_data, 'count');
        
        $chart_config = [
            'type' => $options['type'],
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'data' => array_map('intval', $data),
                    'backgroundColor' => $this->generate_color_array($options['colors'], count($data)),
                    'borderColor' => '#ffffff',
                    'borderWidth' => 2,
                    'hoverBorderWidth' => 3
                ]]
            ],
            'options' => [
                'responsive' => $options['responsive'],
                'plugins' => [
                    'title' => [
                        'display' => false
                    ],
                    'legend' => [
                        'display' => true,
                        'position' => 'right',
                        'labels' => [
                            'usePointStyle' => true,
                            'padding' => 15
                        ]
                    ],
                    'tooltip' => [
                        'callbacks' => [
                            'label' => 'js:function(context) { 
                                var label = context.label || "";
                                var value = context.parsed;
                                var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                var percentage = Math.round((value / total) * 100);
                                return label + ": " + value + " (" + percentage + "%)";
                            }'
                        ]
                    ]
                ],
                'cutout' => '60%',
                'maintainAspectRatio' => false
            ]
        ];
        
        $this->render_chart($chart_id, $chart_config, $options);
    }

    /**
     * Render gender distribution chart
     * 
     * @param array $gender_data Gender distribution data
     * @param array $options     Chart options
     */
    public function render_gender_chart($gender_data, $options = []) {
        $chart_id = 'gender-chart-' . ++self::$chart_counter;
        
        $defaults = [
            'type' => 'pie',
            'title' => __('Gender Distribution', 'intersoccer-reports-rosters'),
            'height' => 300,
            'colors' => [$this->brand_colors['primary'], $this->brand_colors['secondary'], $this->brand_colors['accent']],
            'responsive' => true
        ];
        
        $options = array_merge($defaults, $options);
        
        if (empty($gender_data)) {
            $this->render_no_data_chart($chart_id, $options['title']);
            return;
        }
        
        // Prepare data with proper labels
        $labels = [];
        $data = [];
        
        foreach ($gender_data as $item) {
            switch (strtolower($item['gender'])) {
                case 'male':
                    $labels[] = __('Male', 'intersoccer-reports-rosters');
                    break;
                case 'female':
                    $labels[] = __('Female', 'intersoccer-reports-rosters');
                    break;
                default:
                    $labels[] = __('Other', 'intersoccer-reports-rosters');
                    break;
            }
            $data[] = intval($item['count']);
        }
        
        $chart_config = [
            'type' => $options['type'],
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'data' => $data,
                    'backgroundColor' => $this->generate_color_array($options['colors'], count($data)),
                    'borderColor' => '#ffffff',
                    'borderWidth' => 2,
                    'hoverBorderWidth' => 3
                ]]
            ],
            'options' => [
                'responsive' => $options['responsive'],
                'plugins' => [
                    'title' => [
                        'display' => false
                    ],
                    'legend' => [
                        'display' => true,
                        'position' => 'bottom',
                        'labels' => [
                            'usePointStyle' => true,
                            'padding' => 15
                        ]
                    ],
                    'tooltip' => [
                        'callbacks' => [
                            'label' => 'js:function(context) { 
                                var label = context.label || "";
                                var value = context.parsed;
                                var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                var percentage = Math.round((value / total) * 100);
                                return label + ": " + value + " (" + percentage + "%)";
                            }'
                        ]
                    ]
                ],
                'maintainAspectRatio' => false
            ]
        ];
        
        $this->render_chart($chart_id, $chart_config, $options);
    }

    /**
     * Render weekly trends chart
     * 
     * @param array $trends_data Weekly trends data
     * @param array $options     Chart options
     */
    public function render_trends_chart($trends_data, $options = []) {
        $chart_id = 'trends-chart-' . ++self::$chart_counter;
        
        $defaults = [
            'type' => 'line',
            'title' => __('Registration Trends', 'intersoccer-reports-rosters'),
            'height' => 300,
            'colors' => [$this->brand_colors['primary']],
            'responsive' => true
        ];
        
        $options = array_merge($defaults, $options);
        
        if (empty($trends_data)) {
            $this->render_no_data_chart($chart_id, $options['title']);
            return;
        }
        
        // Prepare data with formatted week labels
        $labels = [];
        $data = [];
        
        foreach ($trends_data as $item) {
            // Convert 2024-45 format to "Week 45, 2024"
            $parts = explode('-', $item['week']);
            if (count($parts) === 2) {
                $year = $parts[0];
                $week = $parts[1];
                $labels[] = sprintf(__('Week %s', 'intersoccer-reports-rosters'), $week);
            } else {
                $labels[] = $item['week'];
            }
            $data[] = intval($item['count']);
        }
        
        $chart_config = [
            'type' => $options['type'],
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => __('Registrations', 'intersoccer-reports-rosters'),
                    'data' => $data,
                    'borderColor' => $options['colors'][0],
                    'backgroundColor' => $this->hex_to_rgba($options['colors'][0], 0.1),
                    'borderWidth' => 3,
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => $options['colors'][0],
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 5,
                    'pointHoverRadius' => 8
                ]]
            ],
            'options' => [
                'responsive' => $options['responsive'],
                'plugins' => [
                    'title' => [
                        'display' => false
                    ],
                    'legend' => [
                        'display' => false
                    ],
                    'tooltip' => [
                        'mode' => 'index',
                        'intersect' => false,
                        'callbacks' => [
                            'label' => 'js:function(context) { return context.parsed.y + " registrations"; }'
                        ]
                    ]
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'ticks' => [
                            'stepSize' => 1
                        ],
                        'title' => [
                            'display' => true,
                            'text' => __('Number of Registrations', 'intersoccer-reports-rosters')
                        ]
                    ],
                    'x' => [
                        'title' => [
                            'display' => true,
                            'text' => __('Week', 'intersoccer-reports-rosters')
                        ]
                    ]
                ],
                'interaction' => [
                    'mode' => 'nearest',
                    'axis' => 'x',
                    'intersect' => false
                ],
                'maintainAspectRatio' => false
            ]
        ];
        
        $this->render_chart($chart_id, $chart_config, $options);
    }

    /**
     * Render a generic chart
     * 
     * @param string $chart_id     Unique chart ID
     * @param array  $chart_config Chart.js configuration
     * @param array  $options      Component options
     */
    private function render_chart($chart_id, $chart_config, $options) {
        $height = $options['height'] ?? 300;
        
        ?>
        <div class="intersoccer-chart-container" style="position: relative; height: <?php echo $height; ?>px;">
            <canvas id="<?php echo esc_attr($chart_id); ?>"></canvas>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('<?php echo esc_js($chart_id); ?>');
            if (ctx) {
                const config = <?php echo $this->encode_chart_config($chart_config); ?>;
                new Chart(ctx, config);
            }
        });
        </script>
        <?php
        
        $this->logger->debug('Chart rendered', [
            'chart_id' => $chart_id,
            'type' => $chart_config['type']
        ]);
    }

    /**
     * Render no data chart placeholder
     * 
     * @param string $chart_id Chart ID
     * @param string $title    Chart title
     */
    private function render_no_data_chart($chart_id, $title) {
        ?>
        <div class="intersoccer-chart-container intersoccer-no-data" style="position: relative; height: 300px; display: flex; align-items: center; justify-content: center; background: #f9fafb; border: 2px dashed #d1d5db; border-radius: 8px;">
            <div class="no-data-content" style="text-align: center; color: #6b7280;">
                <div style="font-size: 48px; margin-bottom: 12px;">📊</div>
                <div style="font-size: 16px; font-weight: 500; margin-bottom: 4px;">
                    <?php _e('No Data Available', 'intersoccer-reports-rosters'); ?>
                </div>
                <div style="font-size: 14px;">
                    <?php printf(__('No data to display for %s', 'intersoccer-reports-rosters'), esc_html($title)); ?>
                </div>
            </div>
        </div>
        <?php
        
        $this->logger->debug('No data chart rendered', [
            'chart_id' => $chart_id,
            'title' => $title
        ]);
    }

    /**
     * Generate color array for chart datasets
     * 
     * @param array $colors Color palette
     * @param int   $count  Number of colors needed
     * 
     * @return array Generated colors
     */
    private function generate_color_array($colors, $count) {
        $result = [];
        
        for ($i = 0; $i < $count; $i++) {
            $color_index = $i % count($colors);
            $result[] = $colors[$color_index];
        }
        
        return $result;
    }

    /**
     * Convert hex color to RGBA
     * 
     * @param string $hex   Hex color
     * @param float  $alpha Alpha value
     * 
     * @return string RGBA color string
     */
    private function hex_to_rgba($hex, $alpha = 1.0) {
        $hex = ltrim($hex, '#');
        
        if (strlen($hex) === 3) {
            $hex = str_repeat(substr($hex, 0, 1), 2) . 
                   str_repeat(substr($hex, 1, 1), 2) . 
                   str_repeat(substr($hex, 2, 1), 2);
        }
        
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        return "rgba({$r}, {$g}, {$b}, {$alpha})";
    }

    /**
     * Encode chart configuration for JavaScript
     * 
     * @param array $config Chart configuration
     * 
     * @return string JSON encoded configuration
     */
    private function encode_chart_config($config) {
        $json = wp_json_encode($config, JSON_UNESCAPED_UNICODE);
        
        // Replace JavaScript function placeholders
        $json = preg_replace('/"js:([^"]+)"/', '$1', $json);
        
        return $json;
    }

    /**
     * Render chart loading placeholder
     * 
     * @param string $chart_id Chart ID
     */
    public function render_loading_chart($chart_id) {
        ?>
        <div class="intersoccer-chart-container intersoccer-loading" id="<?php echo esc_attr($chart_id); ?>-loading" style="position: relative; height: 300px; display: flex; align-items: center; justify-content: center; background: #f9fafb; border-radius: 8px;">
            <div class="loading-content" style="text-align: center; color: #6b7280;">
                <div class="loading-spinner" style="width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #3b82f6; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 12px;"></div>
                <div style="font-size: 14px;">
                    <?php _e('Loading chart data...', 'intersoccer-reports-rosters'); ?>
                </div>
            </div>
        </div>
        
        <style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>
        <?php
    }

    /**
     * Get brand colors
     * 
     * @return array Brand color palette
     */
    public function get_brand_colors() {
        return $this->brand_colors;
    }

    /**
     * Set custom brand colors
     * 
     * @param array $colors Custom color palette
     */
    public function set_brand_colors($colors) {
        $this->brand_colors = array_merge($this->brand_colors, $colors);
        
        $this->logger->debug('Brand colors updated', [
            'colors' => array_keys($colors)
        ]);
    }

    /**
     * Render comparison chart (for comparing events, venues, etc.)
     * 
     * @param array $datasets Multiple datasets for comparison
     * @param array $options  Chart options
     */
    public function render_comparison_chart($datasets, $options = []) {
        $chart_id = 'comparison-chart-' . ++self::$chart_counter;
        
        $defaults = [
            'type' => 'bar',
            'title' => __('Comparison Chart', 'intersoccer-reports-rosters'),
            'height' => 400,
            'colors' => array_values($this->brand_colors),
            'responsive' => true,
            'grouped' => true
        ];
        
        $options = array_merge($defaults, $options);
        
        if (empty($datasets)) {
            $this->render_no_data_chart($chart_id, $options['title']);
            return;
        }
        
        // Prepare datasets for Chart.js
        $chart_datasets = [];
        $color_index = 0;
        
        foreach ($datasets as $dataset) {
            $color = $options['colors'][$color_index % count($options['colors'])];
            
            $chart_datasets[] = [
                'label' => $dataset['label'],
                'data' => array_map('intval', $dataset['data']),
                'backgroundColor' => $this->hex_to_rgba($color, 0.8),
                'borderColor' => $color,
                'borderWidth' => 2
            ];
            
            $color_index++;
        }
        
        $chart_config = [
            'type' => $options['type'],
            'data' => [
                'labels' => $datasets[0]['labels'] ?? [],
                'datasets' => $chart_datasets
            ],
            'options' => [
                'responsive' => $options['responsive'],
                'plugins' => [
                    'title' => [
                        'display' => false
                    ],
                    'legend' => [
                        'display' => true,
                        'position' => 'top'
                    ]
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'ticks' => [
                            'stepSize' => 1
                        ]
                    ]
                ],
                'maintainAspectRatio' => false
            ]
        ];
        
        $this->render_chart($chart_id, $chart_config, $options);
    }

    /**
     * Render time series chart for trends over time
     * 
     * @param array $time_series Time series data
     * @param array $options     Chart options
     */
    public function render_time_series_chart($time_series, $options = []) {
        $chart_id = 'timeseries-chart-' . ++self::$chart_counter;
        
        $defaults = [
            'type' => 'line',
            'title' => __('Time Series Chart', 'intersoccer-reports-rosters'),
            'height' => 350,
            'colors' => [$this->brand_colors['primary']],
            'responsive' => true,
            'fill' => false
        ];
        
        $options = array_merge($defaults, $options);
        
        if (empty($time_series)) {
            $this->render_no_data_chart($chart_id, $options['title']);
            return;
        }
        
        $chart_config = [
            'type' => $options['type'],
            'data' => [
                'labels' => array_column($time_series, 'date'),
                'datasets' => [[
                    'label' => $options['data_label'] ?? __('Value', 'intersoccer-reports-rosters'),
                    'data' => array_map('intval', array_column($time_series, 'value')),
                    'borderColor' => $options['colors'][0],
                    'backgroundColor' => $options['fill'] ? $this->hex_to_rgba($options['colors'][0], 0.1) : 'transparent',
                    'borderWidth' => 3,
                    'fill' => $options['fill'],
                    'tension' => 0.4,
                    'pointRadius' => 4,
                    'pointHoverRadius' => 8
                ]]
            ],
            'options' => [
                'responsive' => $options['responsive'],
                'plugins' => [
                    'title' => [
                        'display' => false
                    ],
                    'legend' => [
                        'display' => false
                    ]
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true
                    ],
                    'x' => [
                        'type' => 'time',
                        'time' => [
                            'parser' => 'YYYY-MM-DD',
                            'tooltipFormat' => 'MMM DD, YYYY',
                            'displayFormats' => [
                                'day' => 'MMM DD',
                                'week' => 'MMM DD',
                                'month' => 'MMM YYYY'
                            ]
                        ]
                    ]
                ],
                'maintainAspectRatio' => false
            ]
        ];
        
        $this->render_chart($chart_id, $chart_config, $options);
    }

    /**
     * Render custom chart with full configuration control
     * 
     * @param array $full_config Complete Chart.js configuration
     * @param array $options     Component options
     */
    public function render_custom_chart($full_config, $options = []) {
        $chart_id = 'custom-chart-' . ++self::$chart_counter;
        
        $defaults = [
            'height' => 300,
            'responsive' => true
        ];
        
        $options = array_merge($defaults, $options);
        
        $this->render_chart($chart_id, $full_config, $options);
    }

    /**
     * Get chart statistics for debugging
     * 
     * @return array Chart rendering statistics
     */
    public function get_chart_stats() {
        return [
            'total_charts_rendered' => self::$chart_counter,
            'available_colors' => count($this->brand_colors),
            'brand_colors' => $this->brand_colors
        ];
    }
}