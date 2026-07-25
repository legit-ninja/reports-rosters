<?php
/**
 * Auto-derive day-of-week-matched baseline windows of equal length.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class CampaignBaseline {

	/**
	 * @param string $start_local Y-m-d H:i:s
	 * @param string $end_local   Y-m-d H:i:s
	 * @param string $mode        matched_prior|same_dates_last_year|custom
	 * @param string $custom_start
	 * @param string $custom_end
	 * @return array{start:string,end:string,mode:string,warnings:string[],length_seconds:int}
	 */
	public static function derive($start_local, $end_local, $mode = 'matched_prior', $custom_start = '', $custom_end = '') {
		$start = CampaignTimezone::parse_local($start_local);
		$end   = CampaignTimezone::parse_local($end_local);
		if ($end < $start) {
			throw new \InvalidArgumentException('Campaign end must be on or after start');
		}

		$length = $end->getTimestamp() - $start->getTimestamp();
		$warnings = [];

		$mode = in_array($mode, ['matched_prior', 'same_dates_last_year', 'custom'], true)
			? $mode
			: 'matched_prior';

		if ($mode === 'custom') {
			$b_start = CampaignTimezone::parse_local($custom_start);
			$b_end   = CampaignTimezone::parse_local($custom_end);
			$b_len   = $b_end->getTimestamp() - $b_start->getTimestamp();
			if ($b_len !== $length) {
				throw new \InvalidArgumentException('Custom baseline length must equal campaign length');
			}
			return [
				'start' => CampaignTimezone::to_mysql_local($b_start),
				'end' => CampaignTimezone::to_mysql_local($b_end),
				'mode' => 'custom',
				'warnings' => $warnings,
				'length_seconds' => $length,
			];
		}

		if ($mode === 'same_dates_last_year') {
			$b_start = $start->modify('-1 year');
			$b_end   = $b_start->modify('+' . $length . ' seconds');
			if ((int) $b_start->format('w') !== (int) $start->format('w')
				|| (int) $b_end->format('w') !== (int) $end->format('w')) {
				$warnings[] = 'same_dates_last_year_weekday_mismatch';
			}
			return [
				'start' => CampaignTimezone::to_mysql_local($b_start),
				'end' => CampaignTimezone::to_mysql_local($b_end),
				'mode' => 'same_dates_last_year',
				'warnings' => $warnings,
				'length_seconds' => $length,
			];
		}

		// matched_prior: same length ending the second before campaign start,
		// preserving weekday of start (and thus the whole span for fixed-length windows).
		$b_end   = $start->modify('-1 second');
		$b_start = $b_end->modify('-' . $length . ' seconds');

		// Ensure start weekday matches (clock-based length already preserves DoW for whole days).
		if ((int) $b_start->format('w') !== (int) $start->format('w')) {
			// Shift back in 7-day steps until weekdays align (partial weeks / DST edge).
			while ((int) $b_start->format('w') !== (int) $start->format('w')) {
				$b_start = $b_start->modify('-1 day');
				$b_end   = $b_start->modify('+' . $length . ' seconds');
			}
		}

		return [
			'start' => CampaignTimezone::to_mysql_local($b_start),
			'end' => CampaignTimezone::to_mysql_local($b_end),
			'mode' => 'matched_prior',
			'warnings' => $warnings,
			'length_seconds' => $length,
		];
	}
}
