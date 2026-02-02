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

    public function register_menus(): void {
        // Load reports.php for ALL admin requests so:
        // 1) When page=intersoccer-reports: enqueue scripts (admin_enqueue_scripts)
        // 2) When AJAX: wp_ajax_intersoccer_filter_report is registered (AJAX requests
        //    hit admin-ajax.php and do NOT have page=intersoccer-reports in $_GET)
        $this->require_include('reports.php');

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
            __('Camps', 'intersoccer-reports-rosters'),
            __('Camps', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-camps',
            [$this, 'render_camps']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Courses', 'intersoccer-reports-rosters'),
            __('Courses', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-courses',
            [$this, 'render_courses']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Girls Only', 'intersoccer-reports-rosters'),
            __('Girls Only', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-girls-only',
            [$this, 'render_girls_only']
        );

        add_submenu_page(
            'intersoccer-reports-rosters',
            __('Tournaments', 'intersoccer-reports-rosters'),
            __('Tournaments', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-tournaments',
            [$this, 'render_tournaments']
        );

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
            __('InterSoccer Settings', 'intersoccer-reports-rosters'),
            __('Settings', 'intersoccer-reports-rosters'),
            'read',
            'intersoccer-advanced',
            [$this, 'render_advanced']
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

    public function render_reports(): void {
        $this->require_include('reports.php');
        if (function_exists('intersoccer_render_reports_page')) {
            intersoccer_render_reports_page();
            return;
        }
        wp_die(__('Reports page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_final_camp_reports(): void {
        $this->require_include('event-reports.php');
        if (function_exists('intersoccer_render_final_camp_reports_page')) {
            intersoccer_render_final_camp_reports_page();
            return;
        }
        wp_die(__('Final camp reports page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_final_course_reports(): void {
        $this->require_include('event-reports.php');
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

    public function render_camps(): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_render_camps_page')) {
            intersoccer_render_camps_page();
            return;
        }
        wp_die(__('Camps page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_courses(): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_render_courses_page')) {
            intersoccer_render_courses_page();
            return;
        }
        wp_die(__('Courses page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_girls_only(): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_render_girls_only_page')) {
            intersoccer_render_girls_only_page();
            return;
        }
        wp_die(__('Girls Only page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_tournaments(): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_render_tournaments_page')) {
            intersoccer_render_tournaments_page();
            return;
        }
        wp_die(__('Tournaments page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_other_events(): void {
        $this->require_include('rosters.php');
        if (function_exists('intersoccer_render_other_events_page')) {
            intersoccer_render_other_events_page();
            return;
        }
        wp_die(__('Other Events page is not available.', 'intersoccer-reports-rosters'));
    }

    public function render_advanced(): void {
        $this->require_include('advanced.php');
        if (function_exists('intersoccer_render_advanced_page')) {
            intersoccer_render_advanced_page();
            return;
        }
        wp_die(__('Advanced page is not available.', 'intersoccer-reports-rosters'));
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

