<?php
/**
 * Self-hosted admin vendor assets (Chart.js, jQuery UI theme).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') || exit;

if (!function_exists('intersoccer_rr_plugin_main_file')) {
    /**
     * Main plugin bootstrap file path.
     */
    function intersoccer_rr_plugin_main_file(): string {
        return dirname(__DIR__) . '/intersoccer-reports-rosters.php';
    }
}

if (!function_exists('intersoccer_rr_register_local_admin_vendor_assets')) {
    /**
     * Register Chart.js and jQuery UI base theme for admin screens (enqueue by handle).
     */
    function intersoccer_rr_register_local_admin_vendor_assets(): void {
        $main = intersoccer_rr_plugin_main_file();

        wp_register_script(
            'intersoccer-rr-chart-js',
            plugins_url('js/vendor/chart.js/chart.min.js', $main),
            [],
            '3.9.1',
            true
        );

        wp_register_style(
            'intersoccer-rr-jquery-ui-css',
            plugins_url('css/vendor/jquery-ui/jquery-ui.css', $main),
            [],
            '1.12.1'
        );
    }
}

add_action('admin_init', 'intersoccer_rr_register_local_admin_vendor_assets', 5);
