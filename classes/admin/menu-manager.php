<?php
/**
 * InterSoccer Menu Manager
 * 
 * Manages all admin menu pages and their integration with the UI layer.
 * Creates a clean, organized menu structure for different user roles.
 * 
 * @package InterSoccer_Reports_Rosters
 * @subpackage Admin
 * @version 1.0.0
 */

namespace InterSoccer\Admin;

use InterSoccer\Core\Logger;
use InterSoccer\UI\Pages\OverviewPage;
use InterSoccer\UI\Pages\ReportsPage;
use InterSoccer\UI\Pages\CampsPage;
use InterSoccer\UI\Pages\CoursesPage;
use InterSoccer\UI\Pages\GirlsOnlyPage;
use InterSoccer\UI\Pages\OtherEventsPage;
use InterSoccer\UI\Pages\AdvancedPage;
use InterSoccer\UI\Pages\RosterDetailsPage;
use InterSoccer\Exceptions\PluginException;

if (!defined('ABSPATH')) {
    exit;
}

class MenuManager {

    /**
     * Logger instance
     * 
     * @var Logger
     */
    private $logger;

    /**
     * Services container
     * 
     * @var array
     */
    private $services;

    /**
     * Page instances
     * 
     * @var array
     */
    private $pages = [];

    /**
     * Menu configuration
     * 
     * @var array
     */
    private $menu_config = [
        'main_menu' => [
            'page_title' => 'InterSoccer Reports and Rosters',
            'menu_title' => 'Reports & Rosters',
            'capability' => 'read',
            'menu_slug' => 'intersoccer-reports-rosters',
            'icon' => 'dashicons-chart-bar',
            'position' => 30
        ],
        'submenus' => [
            'overview' => [
                'page_title' => 'InterSoccer Overview',
                'menu_title' => 'Overview',
                'capability' => 'read',
                'menu_slug' => 'intersoccer-reports-rosters'
            ],
            'reports' => [
                'page_title' => 'InterSoccer Reports',
                'menu_title' => 'Reports',
                'capability' => 'read',
                'menu_slug' => 'intersoccer-reports'
            ],
            'camps' => [
                'page_title' => 'Camps Rosters',
                'menu_title' => 'Camps',
                'capability' => 'read',
                'menu_slug' => 'intersoccer-camps'
            ],
            'courses' => [
                'page_title' => 'Courses Rosters',
                'menu_title' => 'Courses',
                'capability' => 'read',
                'menu_slug' => 'intersoccer-courses'
            ],
            'girls_only' => [
                'page_title' => 'Girls Only Events',
                'menu_title' => 'Girls Only',
                'capability' => 'read',
                'menu_slug' => 'intersoccer-girls-only'
            ],
            'other_events' => [
                'page_title' => 'Other Events',
                'menu_title' => 'Other Events',
                'capability' => 'read',
                'menu_slug' => 'intersoccer-other-events'
            ],
            'advanced' => [
                'page_title' => 'InterSoccer Advanced Tools',
                'menu_title' => 'Advanced',
                'capability' => 'manage_options',
                'menu_slug' => 'intersoccer-advanced'
            ],
            'roster_details' => [
                'page_title' => 'Roster Details',
                'menu_title' => null, // Hidden from menu
                'capability' => 'read',
                'menu_slug' => 'intersoccer-roster-details'
            ]
        ]
    ];

    /**
     * Constructor
     * 
     * @param Logger $logger   Logger instance
     * @param array  $services Services container
     */
    public function __construct(Logger $logger, array $services) {
        $this->logger = $logger;
        $this->services = $services;
        
        // Hook into WordPress admin menu system
        add_action('admin_menu', [$this, 'register_menus']);
        
        $this->logger->debug('MenuManager initialized');
    }

    /**
     * Register all menu pages
     */
    public function register_menus() {
        try {
            // Register main menu page
            $this->register_main_menu();
            
            // Register submenu pages
            $this->register_submenus();
            
            $this->logger->info('All menu pages registered successfully', [
                'pages_count' => count($this->pages)
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to register menu pages', [
                'error' => $e->getMessage()
            ]);
            
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>InterSoccer:</strong> Menu registration failed: ';
                echo esc_html($e->getMessage());
                echo '</p></div>';
            });
        }
    }

    /**
     * Register main menu page
     */
    private function register_main_menu() {
        $config = $this->menu_config['main_menu'];
        
        $hook_suffix = add_menu_page(
            $config['page_title'],
            $config['menu_title'],
            $config['capability'],
            $config['menu_slug'],
            [$this, 'render_overview_page'],
            $config['icon'],
            $config['position']
        );
        
        // Initialize overview page
        $this->pages['overview'] = new OverviewPage(
            $this->logger,
            $this->services
        );
        
        $this->logger->debug('Main menu page registered', [
            'hook_suffix' => $hook_suffix,
            'menu_slug' => $config['menu_slug']
        ]);
    }

    /**
     * Register submenu pages
     */
    private function register_submenus() {
        $parent_slug = $this->menu_config['main_menu']['menu_slug'];
        
        foreach ($this->menu_config['submenus'] as $key => $config) {
            // Skip overview as it's the main page
            if ($key === 'overview') {
                continue;
            }
            
            // Skip hidden pages (no menu_title)
            if ($config['menu_title'] === null) {
                $this->register_hidden_page($key, $config);
                continue;
            }
            
            $hook_suffix = add_submenu_page(
                $parent_slug,
                $config['page_title'],
                $config['menu_title'],
                $config['capability'],
                $config['menu_slug'],
                [$this, 'render_' . $key . '_page']
            );
            
            // Initialize page instance
            $this->initialize_page($key, $config);
            
            $this->logger->debug('Submenu page registered', [
                'key' => $key,
                'hook_suffix' => $hook_suffix,
                'menu_slug' => $config['menu_slug']
            ]);
        }
    }

    /**
     * Register hidden page (accessible by URL but not in menu)
     * 
     * @param string $key    Page key
     * @param array  $config Page configuration
     */
    private function register_hidden_page($key, $config) {
        // Add page without menu entry
        add_action('admin_init', function() use ($key, $config) {
            if (isset($_GET['page']) && $_GET['page'] === $config['menu_slug']) {
                if (!current_user_can($config['capability'])) {
                    wp_die(__('You do not have sufficient permissions to access this page.'));
                }
            }
        });
        
        // Initialize page instance
        $this->initialize_page($key, $config);
        
        $this->logger->debug('Hidden page registered', [
            'key' => $key,
            'menu_slug' => $config['menu_slug']
        ]);
    }

    /**
     * Initialize page instance
     * 
     * @param string $key    Page key
     * @param array  $config Page configuration
     */
    private function initialize_page($key, $config) {
        switch ($key) {
            case 'reports':
                $this->pages[$key] = new ReportsPage($this->logger, $this->services);
                break;
                
            case 'camps':
                $this->pages[$key] = new CampsPage($this->logger, $this->services);
                break;
                
            case 'courses':
                $this->pages[$key] = new CoursesPage($this->logger, $this->services);
                break;
                
            case 'girls_only':
                $this->pages[$key] = new GirlsOnlyPage($this->logger, $this->services);
                break;
                
            case 'other_events':
                $this->pages[$key] = new OtherEventsPage($this->logger, $this->services);
                break;
                
            case 'advanced':
                $this->pages[$key] = new AdvancedPage($this->logger, $this->services);
                break;
                
            case 'roster_details':
                $this->pages[$key] = new RosterDetailsPage($this->logger, $this->services);
                break;
                
            default:
                throw new PluginException("Unknown page key: {$key}");
        }
    }

    /**
     * Render overview page
     */
    public function render_overview_page() {
        $this->render_page('overview');
    }

    /**
     * Render reports page
     */
    public function render_reports_page() {
        $this->render_page('reports');
    }

    /**
     * Render camps page
     */
    public function render_camps_page() {
        $this->render_page('camps');
    }

    /**
     * Render courses page
     */
    public function render_courses_page() {
        $this->render_page('courses');
    }

    /**
     * Render girls only page
     */
    public function render_girls_only_page() {
        $this->render_page('girls_only');
    }

    /**
     * Render other events page
     */
    public function render_other_events_page() {
        $this->render_page('other_events');
    }

    /**
     * Render advanced page
     */
    public function render_advanced_page() {
        $this->render_page('advanced');
    }

    /**
     * Render roster details page
     */
    public function render_roster_details_page() {
        $this->render_page('roster_details');
    }

    /**
     * Generic page renderer with error handling
     * 
     * @param string $page_key Page key to render
     */
    private function render_page($page_key) {
        try {
            if (!isset($this->pages[$page_key])) {
                throw new PluginException("Page '{$page_key}' not initialized");
            }
            
            // Check user capabilities
            $config = $this->get_page_config($page_key);
            if (!current_user_can($config['capability'])) {
                wp_die(__('You do not have sufficient permissions to access this page.'));
            }
            
            // Render the page
            $this->pages[$page_key]->render();
            
            $this->logger->debug('Page rendered successfully', [
                'page' => $page_key,
                'user_id' => get_current_user_id()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to render page', [
                'page' => $page_key,
                'error' => $e->getMessage(),
                'user_id' => get_current_user_id()
            ]);
            
            // Show error page
            $this->render_error_page($e->getMessage());
        }
    }

    /**
     * Render error page
     * 
     * @param string $message Error message
     */
    private function render_error_page($message) {
        ?>
        <div class="wrap">
            <h1><?php _e('InterSoccer Reports & Rosters', 'intersoccer-reports-rosters'); ?></h1>
            <div class="notice notice-error">
                <p>
                    <strong><?php _e('Error:', 'intersoccer-reports-rosters'); ?></strong>
                    <?php echo esc_html($message); ?>
                </p>
                <p>
                    <?php _e('Please contact the administrator if this problem persists.', 'intersoccer-reports-rosters'); ?>
                </p>
            </div>
            
            <div class="card">
                <h2><?php _e('Troubleshooting', 'intersoccer-reports-rosters'); ?></h2>
                <ul>
                    <li><?php _e('Ensure all required plugins are active and up to date', 'intersoccer-reports-rosters'); ?></li>
                    <li><?php _e('Check if your user role has sufficient permissions', 'intersoccer-reports-rosters'); ?></li>
                    <li><?php _e('Try refreshing the page or clearing your browser cache', 'intersoccer-reports-rosters'); ?></li>
                    <li>
                        <a href="<?php echo admin_url('admin.php?page=intersoccer-advanced'); ?>">
                            <?php _e('Visit the Advanced page to check system status', 'intersoccer-reports-rosters'); ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Get page configuration
     * 
     * @param string $page_key Page key
     * 
     * @return array Page configuration
     */
    private function get_page_config($page_key) {
        if ($page_key === 'overview') {
            return $this->menu_config['main_menu'];
        }
        
        if (isset($this->menu_config['submenus'][$page_key])) {
            return $this->menu_config['submenus'][$page_key];
        }
        
        throw new PluginException("Unknown page key: {$page_key}");
    }

    /**
     * Get page instance
     * 
     * @param string $page_key Page key
     * 
     * @return mixed Page instance
     */
    public function get_page($page_key) {
        if (!isset($this->pages[$page_key])) {
            throw new PluginException("Page '{$page_key}' not found");
        }
        
        return $this->pages[$page_key];
    }

    /**
     * Check if user can access page
     * 
     * @param string $page_key Page key
     * @param int    $user_id  User ID (default: current user)
     * 
     * @return bool True if user can access page
     */
    public function user_can_access_page($page_key, $user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        try {
            $config = $this->get_page_config($page_key);
            return user_can($user_id, $config['capability']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get available pages for current user
     * 
     * @return array Available pages with their configurations
     */
    public function get_available_pages() {
        $available = [];
        
        // Check main page
        if ($this->user_can_access_page('overview')) {
            $available['overview'] = $this->menu_config['main_menu'];
        }
        
        // Check submenus
        foreach ($this->menu_config['submenus'] as $key => $config) {
            // Skip hidden pages
            if ($config['menu_title'] === null) {
                continue;
            }
            
            if ($this->user_can_access_page($key)) {
                $available[$key] = $config;
            }
        }
        
        return $available;
    }

    /**
     * Get menu breadcrumbs for current page
     * 
     * @return array Breadcrumb navigation
     */
    public function get_breadcrumbs() {
        $current_screen = get_current_screen();
        if (!$current_screen || strpos($current_screen->id, 'intersoccer') === false) {
            return [];
        }
        
        $breadcrumbs = [
            [
                'title' => 'Reports & Rosters',
                'url' => admin_url('admin.php?page=intersoccer-reports-rosters'),
                'current' => false
            ]
        ];
        
        // Determine current page
        $current_page = $_GET['page'] ?? '';
        
        foreach ($this->menu_config['submenus'] as $key => $config) {
            if ($config['menu_slug'] === $current_page && $config['menu_title'] !== null) {
                $breadcrumbs[] = [
                    'title' => $config['menu_title'],
                    'url' => admin_url('admin.php?page=' . $config['menu_slug']),
                    'current' => true
                ];
                break;
            }
        }
        
        // Mark first item as not current if we found a submenu
        if (count($breadcrumbs) > 1) {
            $breadcrumbs[0]['current'] = false;
        } else {
            $breadcrumbs[0]['current'] = true;
        }
        
        return $breadcrumbs;
    }

    /**
     * Enqueue page-specific assets
     * 
     * @param string $page_key Page key
     */
    public function enqueue_page_assets($page_key) {
        if (!isset($this->pages[$page_key])) {
            return;
        }
        
        try {
            // Let the page handle its own asset enqueuing
            if (method_exists($this->pages[$page_key], 'enqueue_assets')) {
                $this->pages[$page_key]->enqueue_assets();
            }
            
            $this->logger->debug('Page assets enqueued', [
                'page' => $page_key
            ]);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to enqueue page assets', [
                'page' => $page_key,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get menu statistics for admin dashboard
     * 
     * @return array Menu usage statistics
     */
    public function get_menu_stats() {
        return [
            'total_pages' => count($this->pages),
            'accessible_pages' => count($this->get_available_pages()),
            'hidden_pages' => count(array_filter($this->menu_config['submenus'], function($config) {
                return $config['menu_title'] === null;
            })),
            'admin_only_pages' => count(array_filter($this->menu_config['submenus'], function($config) {
                return $config['capability'] === 'manage_options';
            }))
        ];
    }

    /**
     * Handle page redirects and URL rewrites
     */
    public function handle_page_redirects() {
        // Handle legacy page redirects
        $legacy_redirects = [
            'intersoccer-all-rosters' => 'intersoccer-camps',
            'intersoccer-export-rosters' => 'intersoccer-camps'
        ];
        
        $current_page = $_GET['page'] ?? '';
        
        if (isset($legacy_redirects[$current_page])) {
            $redirect_url = admin_url('admin.php?page=' . $legacy_redirects[$current_page]);
            
            $this->logger->info('Legacy page redirect', [
                'from' => $current_page,
                'to' => $legacy_redirects[$current_page]
            ]);
            
            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Add contextual help to pages
     * 
     * @param string $page_key Page key
     */
    public function add_contextual_help($page_key) {
        $screen = get_current_screen();
        
        if (!$screen || !isset($this->pages[$page_key])) {
            return;
        }
        
        $help_content = $this->get_help_content($page_key);
        
        if (!empty($help_content)) {
            foreach ($help_content as $help_tab) {
                $screen->add_help_tab($help_tab);
            }
            
            // Add sidebar
            $screen->set_help_sidebar($this->get_help_sidebar());
        }
    }

    /**
     * Get help content for a page
     * 
     * @param string $page_key Page key
     * 
     * @return array Help tabs content
     */
    private function get_help_content($page_key) {
        $help_content = [
            'overview' => [
                [
                    'id' => 'intersoccer-overview-help',
                    'title' => __('Overview Dashboard', 'intersoccer-reports-rosters'),
                    'content' => '<p>' . __('The Overview dashboard provides key statistics about your InterSoccer events, including attendance by venue, age distribution, and seasonal trends.', 'intersoccer-reports-rosters') . '</p>'
                ]
            ],
            'camps' => [
                [
                    'id' => 'intersoccer-camps-help',
                    'title' => __('Camps Rosters', 'intersoccer-reports-rosters'),
                    'content' => '<p>' . __('View and export rosters for camp events. Use the filters to find specific camps by season, venue, or date range.', 'intersoccer-reports-rosters') . '</p>'
                ]
            ],
            'courses' => [
                [
                    'id' => 'intersoccer-courses-help',
                    'title' => __('Courses Rosters', 'intersoccer-reports-rosters'),
                    'content' => '<p>' . __('Manage course rosters and track ongoing registrations. Courses are weekly events that run throughout a season.', 'intersoccer-reports-rosters') . '</p>'
                ]
            ],
            'advanced' => [
                [
                    'id' => 'intersoccer-advanced-help',
                    'title' => __('Advanced Tools', 'intersoccer-reports-rosters'),
                    'content' => '<p>' . __('Advanced tools for database management, cache control, and system diagnostics. Use with caution as these operations can affect site performance.', 'intersoccer-reports-rosters') . '</p>'
                ]
            ]
        ];
        
        return $help_content[$page_key] ?? [];
    }

    /**
     * Get help sidebar content
     * 
     * @return string Help sidebar HTML
     */
    private function get_help_sidebar() {
        return '<p><strong>' . __('For more information:', 'intersoccer-reports-rosters') . '</strong></p>' .
               '<p><a href="https://intersoccer.ch" target="_blank">' . __('InterSoccer Website', 'intersoccer-reports-rosters') . '</a></p>' .
               '<p><a href="mailto:info@intersoccer.ch">' . __('Contact Support', 'intersoccer-reports-rosters') . '</a></p>';
    }
}