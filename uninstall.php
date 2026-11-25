<?php
/**
 * Remove the repair capability and repair options when the plugin is deleted.
 * Roster tables are not dropped.
 *
 * @package InterSoccer_Reports_Rosters
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/repair-contract.php';

if (function_exists('intersoccer_rr_repair_uninstall')) {
    intersoccer_rr_repair_uninstall();
}
