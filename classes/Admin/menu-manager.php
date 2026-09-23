<?php
/**
 * Admin Menu Manager
 *
 * Registers all admin menus/pages for Reports & Rosters.
 *
 * For now, page rendering delegates to the existing include-based page
 * implementations (loaded lazily per page). This keeps the runtime entrypoints
 * OOP-driven while we complete the migration.
 *
 * @package InterSoccer\ReportsRosters\Admin
 */

namespace InterSoccer\ReportsRosters\Admin;

use InterSoccer\ReportsRosters\Core\Logger;

defined('ABSPATH') or die('Restricted access');

class MenuManager {
    /**
     * @var string
     */
    private $plugin_file;

    /**
     * @var string
     */
    private $plugin_path;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var array
     */
    private $services;

    public function __construct(string $plugin_file, Logger $logger = null, array $services = []) {
        $this->plugin_file = $plugin_file;
        $this->plugin_path = plugin_dir_path($plugin_file);
        $this->logger = $logger ?: new Logger();
        $this->services = $services;
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'register_menus']);
    }

    /**
     * Stream player camp status Excel before admin-header.php prints HTML.
     */
    public function maybe_export_player_camp_status(): void {
        if (empty($_GET['export_excel'])) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== 'intersoccer-player-camp-status') {
            return;
        }

        $this->require_include('reports-data.php');
        $this->require_include('player-camp-status.php');
        if (function_exists('intersoccer_player_camp_status_maybe_export_excel')) {
            intersoccer_player_camp_status_maybe_export_excel();
        }
    }

    /**
     * Register Campaign Analytics under WooCommerce → Analytics.
     *
     * For a classic PHP page to appear in WC Analytics (a React-based menu):
     * 1. Register the page as a hidden WP submenu (parent=null) so WordPress
     *    knows the slug and capability checks pass on direct URL access.
     * 2. Manually add a menu item to the Analytics submenu ($submenu) that
     *    links to our classic admin.php?page= URL.
     *
     * The page slug remains 'intersoccer-campaign-analytics' so existing deep
     * links, CampaignModule exports, and bookmarks continue to work.
     *
     * Called from register_menus() since Plugin.php invokes that method directly.
     */
    public function register_campaign_analytics_under_wc_analytics(): void {
        // Step 1: Register as a hidden WP admin page (parent=null).
        // This creates the page in WordPress's admin system so capability checks
        // pass and the render callback is invoked when accessing the URL directly.
        add_submenu_page(
            null,
            __('Campaign Analytics', 'intersoccer-reports-rosters'),
            __('Campaign Analytics', 'intersoccer-reports-rosters'),
            'manage_options',
            'intersoccer-campaign-analytics',
            [$this, 'render_campaign_analytics']
        );

        // Step 2: Add menu item to WooCommerce Analytics submenu.
        // WC Analytics menu slug is 'wc-admin&path=/analytics/overview'.
        // Plugin.php hooks register_menus at priority 5, WC registers at priority 10.
        // Schedule at priority 999 to ensure WC Analytics menu exists.
        add_action('admin_menu', [$this, 'inject_campaign_analytics_into_wc_analytics_submenu'], 999);

        // Step 3: Connect the PHP page to WC Admin for breadcrumbs/header.
        // Screen ID for a hidden page (parent=null) is 'admin_page_{slug}'.
        if (function_exists('wc_admin_connect_page')) {
            wc_admin_connect_page([
                'id'        => 'intersoccer-campaign-analytics',
                'parent'    => 'woocommerce-analytics',
                'screen_id' => 'admin_page_intersoccer-campaign-analytics',
                'title'     => [__('Analytics', 'intersoccer-reports-rosters'), __('Campaign Analytics', 'intersoccer-reports-rosters')],
                'path'      => add_query_arg('page', 'intersoccer-campaign-analytics', 'admin.php'),
            ]);
        }
    }

    /**
     * Inject Campaign Analytics into WC Analytics submenu.
     *
     * Called at admin_menu priority 999 to ensure WC Analytics menu exists.
     */
    public function inject_campaign_analytics_into_wc_analytics_submenu(): void {
        global $submenu;

        $analytics_menu_slug = 'wc-admin&path=/analytics/overview';

        if (!isset($submenu[$analytics_menu_slug])) {
            return;
        }

        // Format: [0]=title, [1]=capability, [2]=slug/url, [3]=page_title (optional)
        $submenu[$analytics_menu_slug][] = [
            __('Campaign Analytics', 'intersoccer-reports-rosters'),
            'manage_options',
            'intersoccer-campaign-analytics',
            __('Campaign Analytics', 'intersoccer-reports-rosters'),
        ];
    }

    public function register_menus(): void {
        // Load reports.php for ALL admin requests so:
        // 1) When page=intersoccer-reports: enqueue scripts (admin_enqueue_scripts)
        // 2) When AJAX: wp_ajax_intersoccer_filter_report is registered (AJAX requests
        //    hit admin-ajax.php and do NOT have page=intersoccer-reports in $_GET)
        $this->require_include('reports.php');

        // Plugin invokes register_menus() directly (not init()); must register export here
        // so admin_init/load-* fire before admin-header HTML contaminates the .xlsx.
        add_action('admin_init', [$this, 'maybe_export_player_camp_status'], 1);
        add_action('admin_init', [$this, 'maybe_redirect_legacy_admin_pages'], 1);

        add_menu_page(
            __('InterSoccer Reports and Rosters', 'intersoccer-reports-rosters'),
            __('Reports and Rosters', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-reports-rosters',
            [$this, 'render_overview'],
            'dashicons-chart-bar',
            30
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('InterSoccer Overview', 'intersoccer-reports-rosters'),
            __('Overview', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-reports-rosters',
            [$this, 'render_overview']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Live Snapshot', 'intersoccer-reports-rosters'),
            __('Live Snapshot', 'intersoccer-reports-rosters'),
            'manage_options',
            'intersoccer-live-snapshot',
            [$this, 'render_live_snapshot']
        );

        $player_camp_status_hook = add_submenu_page(
            'intersoccer-reports-rosters',
            __('Player camp status', 'intersoccer-reports-rosters'),
            __('Player camp status', 'intersoccer-reports-rosters'),
            'manage_options',
            'intersoccer-player-camp-status',
            [$this, 'render_player_camp_status']
        );
        if (is_string($player_camp_status_hook) && $player_camp_status_hook !== '') {
            add_action('load-' . $player_camp_status_hook, [$this, 'maybe_export_player_camp_status']);
        }

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('InterSoccer Booking Reports', 'intersoccer-reports-rosters'),
            __('Booking Reports', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-reports',
            [$this, 'render_reports']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Final Reports', 'intersoccer-reports-rosters'),
            __('Final Reports', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-final-reports',
            [$this, 'render_final_reports']
        );

        // Hidden legacy alias pages for Final Camp/Course Reports → redirect to unified Final Reports.
        add_submenu_page(null, '', '', 'read', 'intersoccer-final-camp-reports', [$this, 'render_legacy_final_camp_redirect']);
        add_submenu_page(null, '', '', 'read', 'intersoccer-final-course-reports', [$this, 'render_legacy_final_course_redirect']);

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Rosters', 'intersoccer-reports-rosters'),
            __('Rosters', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-rosters',
            [$this, 'render_rosters']
        );

        // Hidden legacy list pages → unified Rosters with activity_type.
        // All Rosters retired: consolidated into Rosters (keep slug for redirects/bookmarks).
        add_submenu_page(null, '', '', 'read', 'intersoccer-all-rosters', [$this, 'render_legacy_all_rosters_redirect']);
        add_submenu_page(null, '', '', 'read', 'intersoccer-camps', [$this, 'render_legacy_camps_redirect']);
        add_submenu_page(null, '', '', 'read', 'intersoccer-courses', [$this, 'render_legacy_courses_redirect']);
        add_submenu_page(null, '', '', 'read', 'intersoccer-girls-only', [$this, 'render_legacy_girls_only_redirect']);
        add_submenu_page(null, '', '', 'read', 'intersoccer-tournaments', [$this, 'render_legacy_tournaments_redirect']);

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Other Events', 'intersoccer-reports-rosters'),
            __('Other Events', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-other-events',
            [$this, 'render_other_events']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Birthdays', 'intersoccer-reports-rosters'),
            __('Birthdays', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-birthdays',
            [$this, 'render_birthdays']
        );

        // Campaign Analytics is registered under WooCommerce → Analytics.
        // Must be called here since Plugin.php invokes register_menus() directly.
        $this->register_campaign_analytics_under_wc_analytics();

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Signature drift', 'intersoccer-reports-rosters'),
            __('Signature drift', 'intersoccer-reports-rosters'),
            'manage_options',
            'intersoccer-signature-drift',
            [$this, 'render_signature_drift']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('InterSoccer Settings', 'intersoccer-reports-rosters'),
            __('Settings', 'intersoccer-reports-rosters'),
            'manage_options',
            'intersoccer-advanced',
            [$this, 'render_advanced']
        );

        // Hidden: Roster Sync Queue lives under Settings → Roster Sync Queue tab.
        add_submenu_page(
            null,
            '',
            '',
            'manage_options',
            'intersoccer-roster-sync-queue',
            [$this, 'render_roster_sync_queue_redirect']
        );

        // Hidden detail/edit pages
        add_submenu_page(
            null,
            '',
            '',
            'read',
            'intersoccer-roster-details',
            [$this, 'render_roster_details']
        );

        add_submenu_page(
            null,
            '',
            '',
            'manage_options',
            'intersoccer-roster-edit',
            [$this, 'render_roster_edit']
        );
    }


    /**
     * Redirect legacy list / sync-queue bookmarks before headers are sent.
     */
    public function maybe_redirect_legacy_admin_pages(): void {
        if (!is_admin()) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        if ($page === '') {
            return;
        }

        $this->require_include('rosters.php');

        if ($page === 'intersoccer-roster-sync-queue') {
            if (!headers_sent()) {
                wp_safe_redirect(admin_url('admin.php?page=intersoccer-advanced&tab=roster-sync'));
                exit;
            }
            return;
        }

        // Hub deep link: page=intersoccer-reports&tab=final-reports → redirect to Final Reports page.
        if ($page === 'intersoccer-reports' && isset($_GET['tab']) && $_GET['tab'] === 'final-reports') {
            $this->redirect_hub_final_tab_to_final_reports_page();
            return;
        }

        if (function_exists('intersoccer_rosters_maybe_redirect_legacy_list_page')) {
            intersoccer_rosters_maybe_redirect_legacy_list_page($page);
        }
    }

    /**
     * Redirect hub deep link (page=intersoccer-reports&tab=final-reports) to the Final Reports page.
     */
    private function redirect_hub_final_tab_to_final_reports_page(): void {
        $activity_type = isset($_GET['activity_type']) ? sanitize_text_field(wp_unslash((string) $_GET['activity_type'])) : 'Camp';
        $url = $this->build_final_reports_url($activity_type, $_GET);

        if (!headers_sent()) {
            wp_safe_redirect($url);
            exit;
        }
    }

    private function require_include(string $relative_file): void {
        $path = $this->plugin_path . 'includes/' . ltrim($relative_file, '/');
        if (file_exists($path)) {
            require_once $path;
            return;
        }

        $this->logger->error('MenuManager: include file missing', [
            'file' => $relative_file,
            'path' => $path,
        ]);
    }

    public function render_overview(): void {
        // The legacy overview renderer lives in the main plugin file today.
        // As we continue the cutover we will move it into an include or OOP page.
        if (function_exists('intersoccer_render_plugin_overview_page')) {
            intersoccer_render_plugin_overview_page();
            return;
        }

        wp_die(__('Overview page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_live_snapshot(): void {
        $this->require_include('live-snapshot.php');
        if (function_exists('intersoccer_render_live_snapshot_page')) {
            intersoccer_render_live_snapshot_page();
            return;
        }
        wp_die(__('Live Snapshot page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_player_camp_status(): void {
        $this->require_include('reports-data.php');
        $this->require_include('player-camp-status.php');
        if (function_exists('intersoccer_render_player_camp_status_page')) {
            intersoccer_render_player_camp_status_page();
            return;
        }
        wp_die(__('Player camp status page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_reports(): void {
        $this->require_include('reports.php');
        if (function_exists('intersoccer_render_reports_page')) {
            intersoccer_render_reports_page();
            return;
        }
        wp_die(__('Reports page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_final_reports(): void {
        $this->require_include('reports-ui.php');
        if (function_exists('intersoccer_render_final_reports_standalone_page')) {
            intersoccer_render_final_reports_standalone_page();
            return;
        }
        wp_die(__('Final reports page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_legacy_final_camp_redirect(): void {
        $this->redirect_to_final_reports_page('Camp');
    }

    public function render_legacy_final_course_redirect(): void {
        $this->redirect_to_final_reports_page('Course');
    }

    /**
     * Redirect legacy Final Camp/Course page slugs to the unified Final Reports page.
     *
     * @param string $activity_type Camp|Course
     */
    private function redirect_to_final_reports_page(string $activity_type): void {
        $url = $this->build_final_reports_url($activity_type, $_GET);
        if (!headers_sent()) {
            wp_safe_redirect($url);
            exit;
        }
    }

    /**
     * Build the canonical Final Reports URL with activity type and preserved query args.
     *
     * @param string $activity_type Camp|Course
     * @param array  $query_args    Original $_GET array.
     * @return string
     */
    public function build_final_reports_url(string $activity_type, array $query_args = []): string {
        $preserved_keys = ['year', 'region', 'season_type', 'live', 'urgency_only', 'exclude_buyclub'];
        $args = ['page' => 'intersoccer-final-reports', 'activity_type' => $activity_type];

        foreach ($preserved_keys as $key) {
            if (isset($query_args[$key]) && $query_args[$key] !== '') {
                $args[$key] = $query_args[$key];
            }
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    public function render_all_rosters(): void {
        $this->render_legacy_all_rosters_redirect();
    }

    public function render_legacy_all_rosters_redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=intersoccer-rosters'));
        exit;
    }

    public function render_rosters(): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_render_rosters_page')) {
            intersoccer_render_rosters_page();
            return;
        }
        wp_die(__('Rosters page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_legacy_camps_redirect(): void {
        $this->redirect_legacy_list_page('camps');
    }

    public function render_legacy_courses_redirect(): void {
        $this->redirect_legacy_list_page('courses');
    }

    public function render_legacy_girls_only_redirect(): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_rosters_unified_url')) {
            $get = $_GET;
            $get['girls_only_mode'] = 'yes';
            wp_safe_redirect(intersoccer_rosters_unified_url('camps', $get));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=intersoccer-rosters&activity_type=camps&girls_only_mode=yes'));
        exit;
    }

    public function render_legacy_tournaments_redirect(): void {
        $this->redirect_legacy_list_page('tournaments');
    }

    /**
     * @param string $activity_type camps|courses|tournaments
     */
    private function redirect_legacy_list_page(string $activity_type): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_rosters_unified_url')) {
            wp_safe_redirect(intersoccer_rosters_unified_url($activity_type, $_GET));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=intersoccer-rosters&activity_type=' . rawurlencode($activity_type)));
        exit;
    }

    public function render_other_events(): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_render_other_events_page')) {
            intersoccer_render_other_events_page();
            return;
        }
        wp_die(__('Other Events page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_birthdays(): void {
        $this->require_include('birthdays.php');
        if (function_exists('intersoccer_render_birthdays_page')) {
            intersoccer_render_birthdays_page();
            return;
        }
        wp_die(__('Birthdays page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_advanced(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
        }
        $this->require_include('roster-editor.php');
        $this->require_include('advanced.php');
        if (function_exists('intersoccer_render_advanced_page')) {
            intersoccer_render_advanced_page();
            return;
        }
        wp_die(__('Advanced page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_roster_sync_queue_redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=intersoccer-advanced&tab=roster-sync'));
        exit;
    }

    public function render_signature_drift(): void {
        $this->require_include('signature-drift-report.php');
        if (function_exists('intersoccer_render_signature_drift_report_page')) {
            intersoccer_render_signature_drift_report_page();
            return;
        }
        wp_die(__('Signature drift report is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_campaign_analytics(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'intersoccer-reports-rosters'));
        }
        $this->require_include('campaign-analytics-admin.php');
        if (function_exists('intersoccer_render_campaign_analytics_page')) {
            intersoccer_render_campaign_analytics_page();
            return;
        }
        wp_die(__('Campaign Analytics page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_roster_details(): void {
        $this->require_include('roster-details.php');
        if (function_exists('intersoccer_render_roster_details_page')) {
            intersoccer_render_roster_details_page();
            return;
        }
        wp_die(__('Roster details page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_roster_edit(): void {
        $this->require_include('roster-editor.php');
        if (function_exists('intersoccer_render_roster_edit_form')) {
            intersoccer_render_roster_edit_form();
            return;
        }
        wp_die(__('Roster edit page is not available.', 'intersoccer-reports-rosters'));
    }
}
