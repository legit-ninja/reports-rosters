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
		// Streams before WordPress emits admin chrome; rendering the page first would
		// prepend ~100KB of admin HTML to the download body.
		add_action('admin_init', [self::class, 'maybe_export'], 5);
		add_action('admin_notices', [self::class, 'hpos_notice']);
	}

	/**
	 * Stream a campaign export early in the admin request.
	 *
	 * @return void
	 */
	public static function maybe_export() {
		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		if ($page !== 'intersoccer-campaign-analytics' || empty($_GET['export'])) {
			return;
		}

		if (!function_exists('intersoccer_campaign_analytics_maybe_export')) {
			$include = dirname(__DIR__, 2) . '/includes/campaign-analytics-admin.php';
			if (!file_exists($include)) {
				return;
			}
			require_once $include;
		}

		intersoccer_campaign_analytics_maybe_export(
			new \InterSoccer\ReportsRosters\Data\Repositories\CampaignRepository(),
			new CampaignSummaryStore()
		);
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
