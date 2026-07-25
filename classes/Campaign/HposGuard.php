<?php
/**
 * Hard-fail campaign analytics when HPOS custom order tables are enabled.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class HposGuard {

	/**
	 * @return bool True when legacy posts/postmeta order storage is in use.
	 */
	public static function using_legacy_orders() {
		if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
			try {
				if (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
					return false;
				}
			} catch (\Throwable $e) {
				// Assume legacy if WC util throws.
			}
		}
		return true;
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public static function assert_legacy() {
		if (self::using_legacy_orders()) {
			return ['ok' => true, 'message' => ''];
		}
		return [
			'ok' => false,
			'message' => 'Campaign Analytics requires legacy WooCommerce order storage (posts/postmeta). HPOS is enabled; refusing to run queries.',
		];
	}
}
