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
            __('InterSoccer Final Camp Reports', 'intersoccer-reports-rosters'),
            __('Final Camp Reports', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-final-camp-reports',
            [$this, 'render_final_camp_reports']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('InterSoccer Final Course Reports', 'intersoccer-reports-rosters'),
            __('Final Course Reports', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-final-course-reports',
            [$this, 'render_final_course_reports']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('All Rosters', 'intersoccer-reports-rosters'),
            __('All Rosters', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-all-rosters',
            [$this, 'render_all_rosters']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Rosters', 'intersoccer-reports-rosters'),
            __('Rosters', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-rosters',
            [$this, 'render_rosters']
        );

        // Hidden legacy list pages → unified Rosters with activity_type.
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

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Campaign Analytics', 'intersoccer-reports-rosters'),
            __('Campaign Analytics', 'intersoccer-reports-rosters'),
            'manage_options',
            'intersoccer-campaign-analytics',
            [$this, 'render_campaign_analytics']
        );

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

        if (function_exists('intersoccer_rosters_maybe_redirect_legacy_list_page')) {
            intersoccer_rosters_maybe_redirect_legacy_list_page($page);
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

    public function render_final_camp_reports(): void {
        $this->require_include('reports-ui.php');
        if (function_exists('intersoccer_render_final_camp_reports_page')) {
            intersoccer_render_final_camp_reports_page();
            return;
        }
        wp_die(__('Final camp reports page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_final_course_reports(): void {
        $this->require_include('reports-ui.php');
        if (function_exists('intersoccer_render_final_course_reports_page')) {
            intersoccer_render_final_course_reports_page();
            return;
        }
        wp_die(__('Final course reports page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_all_rosters(): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_render_all_rosters_page')) {
            intersoccer_render_all_rosters_page();
            return;
        }
        wp_die(__('All rosters page is not available.', 'intersoccer-reports-rosters'));
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
        $this->redirect_legacy_list_page('girls_only');
    }

    public function render_legacy_tournaments_redirect(): void {
        $this->redirect_legacy_list_page('tournaments');
    }

    /**
     * @param string $activity_type camps|courses|girls_only|tournaments
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
