<?php
/**
 * Admin Asset Manager
 *
 * Enqueues admin scripts/styles for Reports & Rosters pages and the WooCommerce
 * orders screen integrations.
 *
 * @package InterSoccer\ReportsRosters\Admin
 */

namespace InterSoccer\ReportsRosters\Admin;

use InterSoccer\ReportsRosters\Core\Logger;

defined('ABSPATH') or die('Restricted access');

class AssetManager {
    /**
     * @var string
     */
    private $plugin_url;

    /**
     * @var string
     */
    private $version;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct(string $plugin_url, string $version, Logger $logger = null) {
        $this->plugin_url = rtrim($plugin_url, '/') . '/';
        $this->version = $version;
        $this->logger = $logger ?: new Logger();
    }

    public function init(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen && isset($screen->id) ? (string) $screen->id : '';

        $is_intersoccer_admin_screen =
            $screen_id === 'toplevel_page_intersoccer-reports-rosters'
            || (strpos($screen_id, 'intersoccer-reports-rosters_page_') === 0)
            || (strpos($screen_id, 'reports-and-rosters_page_') === 0);

        if ($is_intersoccer_admin_screen) {
            wp_enqueue_style(
                'intersoccer-reports-rosters-css',
                $this->plugin_url . 'css/styles.css',
                [],
                $this->version
            );
        }

        // Overview charts
        if ($screen_id === 'toplevel_page_intersoccer-reports-rosters') {
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
            wp_enqueue_script(
                'intersoccer-overview-charts',
                $this->plugin_url . 'js/overview-charts.js',
                ['chart-js'],
                $this->version,
                true
            );
        }

        // Roster listing pages (tabs + close/reopen actions)
        $roster_pages = [
            'intersoccer-reports-rosters_page_intersoccer-all-rosters',
            'intersoccer-reports-rosters_page_intersoccer-camps',
            'intersoccer-reports-rosters_page_intersoccer-courses',
            'intersoccer-reports-rosters_page_intersoccer-girls-only',
            'intersoccer-reports-rosters_page_intersoccer-other-events',
            'intersoccer-reports-rosters_page_intersoccer-birthdays',
        ];

        if (in_array($screen_id, $roster_pages, true)) {
            wp_enqueue_script(
                'intersoccer-rosters-tabs',
                $this->plugin_url . 'js/rosters-tabs.js',
                ['jquery'],
                $this->version,
                true
            );

            wp_localize_script('intersoccer-rosters-tabs', 'intersoccer_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('intersoccer_reports_rosters_nonce'),
                'strings' => [
                    'error_missing_signature' => __('Error: Missing event signature', 'intersoccer-reports-rosters'),
                    'confirm_complete_prefix' => __('Are you sure you want to mark "', 'intersoccer-reports-rosters'),
                    'confirm_complete_suffix' => __('" as completed?\n\nThis will mark ALL roster entries for this event as completed and hide them from the active events list.', 'intersoccer-reports-rosters'),
                    'processing' => __('Processing...', 'intersoccer-reports-rosters'),
                    'error_prefix' => __('Error: ', 'intersoccer-reports-rosters'),
                    'unknown_error' => __('Unknown error occurred', 'intersoccer-reports-rosters'),
                    'complete_error' => __('An error occurred while marking the event as completed. Please try again.', 'intersoccer-reports-rosters'),
                ],
            ]);
        }

        $is_birthdays_screen =
            $screen_id === 'intersoccer-reports-rosters_page_intersoccer-birthdays'
            || $screen_id === 'reports-and-rosters_page_intersoccer-birthdays'
            || $hook === 'intersoccer-reports-rosters_page_intersoccer-birthdays'
            || $hook === 'reports-and-rosters_page_intersoccer-birthdays';

        if ($is_birthdays_screen) {
            wp_enqueue_style(
                'intersoccer-birthdays-calendar-css',
                $this->plugin_url . 'css/birthdays-calendar.css',
                ['intersoccer-reports-rosters-css'],
                $this->version
            );

            wp_enqueue_script(
                'intersoccer-birthdays-calendar-js',
                $this->plugin_url . 'js/birthdays-calendar.js',
                ['jquery'],
                $this->version,
                true
            );
        }

        // Advanced page AJAX helpers
        $is_advanced_screen =
            $screen_id === 'intersoccer-reports-rosters_page_intersoccer-advanced'
            || $screen_id === 'reports-and-rosters_page_intersoccer-advanced'
            || $hook === 'intersoccer-reports-rosters_page_intersoccer-advanced'
            || $hook === 'reports-and-rosters_page_intersoccer-advanced';

        if ($is_advanced_screen) {
            $advanced_js_path = dirname(__DIR__, 2) . '/js/advanced-ajax.js';
            $advanced_js_ver = $this->version;
            if (is_readable($advanced_js_path)) {
                $advanced_js_ver = $this->version . '.' . (string) filemtime($advanced_js_path);
            }
            wp_enqueue_script(
                'intersoccer-advanced-ajax',
                $this->plugin_url . 'js/advanced-ajax.js',
                ['jquery'],
                $advanced_js_ver,
                true
            );

            wp_localize_script('intersoccer-advanced-ajax', 'intersoccer_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('intersoccer_rebuild_nonce'),
            ]);
        }

        // Reports & Rosters - Advanced page (rebuild UI)
        if ($is_advanced_screen) {
            wp_enqueue_style(
                'intersoccer-reports-rosters-rebuild-admin-css',
                $this->plugin_url . 'css/rebuild-admin.css',
                [],
                $this->version
            );

            wp_enqueue_script(
                'intersoccer-rebuild-admin-ajax',
                $this->plugin_url . 'js/rebuild-admin.js',
                ['jquery'],
                $this->version,
                true
            );

            wp_localize_script('intersoccer-rebuild-admin-ajax', 'intersoccerRebuild', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('intersoccer_rebuild_nonce'),
                'strings' => [
                    'confirm_rebuild' => __('Are you sure you want to rebuild the database? This will clear all existing roster data and rebuild it from WooCommerce orders.', 'intersoccer-reports-rosters'),
                    'confirm_stop' => __('Are you sure you want to stop the rebuild process?', 'intersoccer-reports-rosters'),
                    'rebuilding' => __('Rebuilding database...', 'intersoccer-reports-rosters'),
                    'completed' => __('Database rebuild completed!', 'intersoccer-reports-rosters'),
                    'error' => __('An error occurred during the rebuild process.', 'intersoccer-reports-rosters'),
                    'processing' => __('Processing batch', 'intersoccer-reports-rosters'),
                    'of' => __('of', 'intersoccer-reports-rosters'),
                    'upgrading' => __('Upgrading database...', 'intersoccer-reports-rosters'),
                    'upgrade_success' => __('Database upgrade completed successfully.', 'intersoccer-reports-rosters'),
                    'upgrade_failed' => __('Database upgrade failed.', 'intersoccer-reports-rosters'),
                    'upgrade_failed_network' => __('Database upgrade failed due to a network error.', 'intersoccer-reports-rosters'),
                ],
            ]);
        }

        // WooCommerce Orders screen integration
        $is_classic_order_edit =
            $hook === 'post.php'
            && isset($_GET['post'])
            && get_post_type((int) $_GET['post']) === 'shop_order';
        $is_hpos_order_edit =
            $hook === 'woocommerce_page_wc-orders'
            && isset($_GET['action'])
            && sanitize_text_field((string) $_GET['action']) === 'edit';
        $is_orders_screen =
            $hook === 'woocommerce_page_wc-orders'
            || ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order')
            || $is_classic_order_edit
            || $is_hpos_order_edit;

        // #region agent log
        error_log(
            'DEBUG21E376 H2 asset_manager_orders_screen_eval ' .
            wp_json_encode([
                'hook' => $hook,
                'screen_id' => $screen_id,
                'is_orders_screen' => $is_orders_screen,
                'is_classic_order_edit' => $is_classic_order_edit,
                'is_hpos_order_edit' => $is_hpos_order_edit,
            ])
        );
        // #endregion

        if ($is_orders_screen) {
            wp_enqueue_script(
                'intersoccer-orders-js',
                $this->plugin_url . 'js/woo-op.js',
                ['jquery'],
                $this->version,
                true
            );

            wp_enqueue_style(
                'intersoccer-styles',
                $this->plugin_url . 'css/styles.css',
                [],
                $this->version
            );

            wp_localize_script('intersoccer-orders-js', 'intersoccer_orders', [
                'nonce' => wp_create_nonce('intersoccer_rebuild_nonce'),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'strings' => [
                    'process_orders' => __('Process Orders', 'intersoccer-reports-rosters'),
                    'confirm_process' => __('Are you sure you want to process all pending orders? This will populate rosters for processing or on-hold orders and transition them to completed.', 'intersoccer-reports-rosters'),
                    'processing' => __('Processing orders... Please wait.', 'intersoccer-reports-rosters'),
                    'dismiss' => __('Dismiss this notice.', 'intersoccer-reports-rosters'),
                    'orders_processed' => __('Orders processed.', 'intersoccer-reports-rosters'),
                    'processing_failed' => __('Processing failed:', 'intersoccer-reports-rosters'),
                ],
            ]);

            wp_enqueue_script(
                'intersoccer-order-item-sync-controls',
                $this->plugin_url . 'js/order-item-sync-controls.js',
                ['jquery'],
                $this->version,
                true
            );

            wp_localize_script('intersoccer-order-item-sync-controls', 'intersoccer_order_item_sync', [
                'nonce' => wp_create_nonce('intersoccer_rebuild_nonce'),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'strings' => [
                    'checking' => __('Checking sync...', 'intersoccer-reports-rosters'),
                    'fixing' => __('Fixing sync...', 'intersoccer-reports-rosters'),
                    'check_failed' => __('Sync check failed.', 'intersoccer-reports-rosters'),
                    'fix_failed' => __('Sync fix failed.', 'intersoccer-reports-rosters'),
                    'fix_confirm' => __('Run safe sync fix for this order item?', 'intersoccer-reports-rosters'),
                    'in_sync' => __('In sync.', 'intersoccer-reports-rosters'),
                    'out_of_sync' => __('Out of sync.', 'intersoccer-reports-rosters'),
                    'fixed' => __('Fix applied.', 'intersoccer-reports-rosters'),
                    'no_action' => __('No changes were needed.', 'intersoccer-reports-rosters'),
                    'badge_checking' => __('Checking...', 'intersoccer-reports-rosters'),
                    'badge_fixing' => __('Fixing...', 'intersoccer-reports-rosters'),
                    'badge_in_sync' => __('In sync', 'intersoccer-reports-rosters'),
                    'badge_out_of_sync' => __('Out of sync', 'intersoccer-reports-rosters'),
                    'badge_fixed' => __('Fixed', 'intersoccer-reports-rosters'),
                    'badge_error' => __('Error', 'intersoccer-reports-rosters'),
                ],
            ]);
        }
    }
}

