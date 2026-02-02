<?php
/**
 * Roster Editor AJAX Handler (delegating)
 *
 * The roster editor UI expects HTML-heavy responses. For now we preserve behavior
 * by delegating to the existing implementation in `includes/roster-editor-ajax.php`,
 * while keeping all registrations in OOP.
 *
 * @package InterSoccer\ReportsRosters\Ajax
 */

namespace InterSoccer\ReportsRosters\Ajax;

defined('ABSPATH') or die('Restricted access');

class RosterEditorAjaxHandler {
    public function register(): void {
        add_action('wp_ajax_intersoccer_load_roster_entries', [$this, 'loadRosterEntries']);
        add_action('wp_ajax_intersoccer_update_roster_entry', [$this, 'updateRosterEntry']);
        add_action('wp_ajax_intersoccer_get_roster_entry', [$this, 'getRosterEntry']);
    }

    public function loadRosterEntries(): void {
        $this->delegate('intersoccer_ajax_load_roster_entries');
    }

    public function updateRosterEntry(): void {
        $this->delegate('intersoccer_ajax_update_roster_entry');
    }

    public function getRosterEntry(): void {
        $this->delegate('intersoccer_ajax_get_roster_entry');
    }

    private function delegate(string $function): void {
        $plugin_root = dirname(__DIR__, 2);
        $path = $plugin_root . '/includes/roster-editor-ajax.php';

        if (!file_exists($path)) {
            wp_send_json_error(['message' => __('Roster editor handler not found.', 'intersoccer-reports-rosters')]);
        }

        require_once $path;

        if (!function_exists($function)) {
            wp_send_json_error(['message' => __('Roster editor handler is unavailable.', 'intersoccer-reports-rosters')]);
        }

        $function();
    }
}

