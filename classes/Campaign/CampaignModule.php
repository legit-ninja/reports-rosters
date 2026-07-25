<?php
/**
 * Bootstrap Campaign Analytics module.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class CampaignModule {

	/** @var bool */
	private static $booted = false;

	/**
	 * @return void
	 */
	public static function boot() {
		if (self::$booted) {
			return;
		}
		self::$booted = true;

		$cpt = new CampaignPostType();
		$cpt->register();

		$scheduler = new CampaignRebuildScheduler();
		$scheduler->register();

		add_action('admin_init', [self::class, 'maybe_create_table']);
		add_action('admin_notices', [self::class, 'hpos_notice']);
	}

	/**
	 * @return void
	 */
	public static function maybe_create_table() {
		$ver = get_option('intersoccer_campaign_summaries_db', '');
		if ($ver === '1') {
			return;
		}
		CampaignSummaryStore::create_table();
		update_option('intersoccer_campaign_summaries_db', '1', false);
	}

	/**
	 * @return void
	 */
	public static function hpos_notice() {
		if (!is_admin() || !current_user_can('manage_options')) {
			return;
		}
		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		if ($page !== 'intersoccer-campaign-analytics') {
			return;
		}
		$guard = HposGuard::assert_legacy();
		if ($guard['ok']) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html($guard['message'])
		);
	}
}
