<?php
/**
 * Hardcoded privacy allowlist for agency exports (not configurable).
 *
 * @package InterSoccer\ReportsRosters\Campaign\Export
 */

namespace InterSoccer\ReportsRosters\Campaign\Export;

defined('ABSPATH') or die('Restricted access');

class ExportAllowlist {

	/**
	 * @return string[]
	 */
	public static function allowed_keys() {
		return [
			'order_id',
			'derived_age',
			'gender',
			'activity',
			'girls_only',
			'product',
			'booking_type',
			'venue',
			'region',
			'season',
			'camp_week',
			'price_paid',
			'sibling_discount',
			'coupon_used',
			'coupon_codes',
			'booking_timestamp',
		];
	}

	/**
	 * Keys that must never appear (defence in depth).
	 *
	 * @return string[]
	 */
	public static function forbidden_keys() {
		return [
			'child_name',
			'first_name',
			'last_name',
			'dob',
			'player_dob',
			'date_of_birth',
			'parent_name',
			'parent_email',
			'billing_email',
			'email',
			'phone',
			'parent_phone',
			'emergency_contact',
			'emergency_phone',
			'medical_conditions',
			'dietary',
			'dietary_needs',
			'avs_number',
			'avs',
		];
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function filter_row(array $row) {
		$allowed = array_flip(self::allowed_keys());
		$out = [];
		foreach ($row as $key => $value) {
			$key_l = strtolower((string) $key);
			if (in_array($key_l, self::forbidden_keys(), true)) {
				continue;
			}
			if (!isset($allowed[$key])) {
				continue;
			}
			$out[$key] = $value;
		}
		return $out;
	}
}
