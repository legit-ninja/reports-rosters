<?php
/**
 * Stand-in for wc_get_order_status_name() in the course status admin UI tests.
 *
 * Unknown slugs stay raw. An empty name exercises the admin fallback.
 */

namespace {
if (!function_exists('wc_get_order_status_name')) {
	/**
	 * @param string $status
	 * @return string
	 */
	function wc_get_order_status_name($status) {
		$status = (string) $status;
		if ($status === 'wc-on-hold') {
			return 'On hold <b>named</b>';
		}
		if ($status === 'empty-name') {
			return '';
		}

		return $status;
	}
}
}
