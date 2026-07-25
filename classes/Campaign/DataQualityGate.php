<?php
/**
 * Refuse silently-wrong figures when a source is known-bad.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class DataQualityGate {

	/**
	 * @param string $source_id orders|roster
	 * @param string $start_mysql
	 * @param string $end_mysql
	 * @return array{ok:bool,errors:string[],warnings:string[]}
	 */
	public function evaluate($source_id, $start_mysql, $end_mysql) {
		$errors = [];
		$warnings = [];

		if ($source_id === 'roster') {
			// Roster prices / timestamps known bad through mid-2026; refuse revenue from roster path
			// until integrity verification is confirmed via option.
			$verified = function_exists('get_option')
				? (bool) get_option('intersoccer_roster_integrity_verified', false)
				: false;
			if (!$verified) {
				$errors[] = 'roster_source_refused_integrity_unverified';
			}
		}

		if (CampaignTimezone::crosses_dst_boundary($start_mysql, $end_mysql)) {
			$warnings[] = 'campaign_window_crosses_dst_boundary';
		}

		return [
			'ok' => empty($errors),
			'errors' => $errors,
			'warnings' => $warnings,
		];
	}

	/**
	 * Dimensional buckets must reconcile to headline line-item count.
	 *
	 * @param int                  $expected
	 * @param array<string,array>  $buckets keyed => ['bookings'=>int,...]
	 * @param string               $count_key
	 * @return bool
	 */
	public function buckets_reconcile($expected, array $buckets, $count_key = 'bookings') {
		$sum = 0;
		foreach ($buckets as $row) {
			$sum += (int) ($row[$count_key] ?? 0);
		}
		return $sum === (int) $expected;
	}
}
