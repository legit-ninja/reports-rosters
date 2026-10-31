<?php
/**
 * User-facing copy for the schema 2.4.0 upgrade button (Advanced admin).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!function_exists('intersoccer_db_upgrade_2_4_0_user_help_text')) {
	/**
	 * Help note under Upgrade Database (matches migrate_rosters_schema_2_4_0 column list).
	 *
	 * @return string
	 */
	function intersoccer_db_upgrade_2_4_0_user_help_text() {
		return __(
			'Note: Schema 2.4.0 copies the full rosters table to a backup table (removed automatically after 14 days), then widens event_type, camp_times, and times on the live table to varchar(191). Use with caution.',
			'intersoccer-reports-rosters'
		);
	}
}

if (!function_exists('intersoccer_db_upgrade_2_4_0_user_confirm_text')) {
	/**
	 * Browser confirm before submitting the upgrade form via AJAX.
	 *
	 * @return string
	 */
	function intersoccer_db_upgrade_2_4_0_user_confirm_text() {
		return __(
			'Run schema 2.4.0? A full roster backup table will be created first, then event_type, camp_times, and times will be widened to 191 characters. Continue?',
			'intersoccer-reports-rosters'
		);
	}
}
