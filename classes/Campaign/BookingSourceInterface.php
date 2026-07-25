<?php
/**
 * Booking source contract (orders now, roster later).
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

interface BookingSourceInterface {

	/**
	 * Fetch normalized line items for a datetime window and status set.
	 *
	 * @param string   $start_mysql Site-local Y-m-d H:i:s
	 * @param string   $end_mysql   Site-local Y-m-d H:i:s
	 * @param string[] $order_statuses e.g. wc-completed
	 * @param string[] $coupon_codes Campaign codes (for coupon flags)
	 * @return LineItem[]
	 */
	public function fetch_line_items($start_mysql, $end_mysql, array $order_statuses, array $coupon_codes = []);

	/**
	 * Source identifier for UI / data-notes.
	 *
	 * @return string orders|roster
	 */
	public function source_id();
}
