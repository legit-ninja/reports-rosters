<?php
/**
 * Site-local campaign window handling (fixed offset when timezone_string empty).
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class CampaignTimezone {

	/**
	 * Effective timezone for campaign wall-clock bounds.
	 *
	 * @return \DateTimeZone
	 */
	public static function site_timezone() {
		$string = function_exists('get_option') ? (string) get_option('timezone_string') : '';
		if ($string !== '') {
			try {
				return new \DateTimeZone($string);
			} catch (\Exception $e) {
				// fall through
			}
		}

		$offset = function_exists('get_option') ? (float) get_option('gmt_offset', 0) : 0.0;
		$hours  = (int) $offset;
		$mins   = (int) abs(($offset - $hours) * 60);
		$sign   = $offset >= 0 ? '+' : '-';
		$name   = sprintf('%s%02d:%02d', $sign, abs($hours), $mins);
		return new \DateTimeZone($name);
	}

	/**
	 * Parse site-local Y-m-d H:i:s into DateTimeImmutable.
	 *
	 * @param string $local
	 * @return \DateTimeImmutable
	 */
	public static function parse_local($local) {
		return new \DateTimeImmutable((string) $local, self::site_timezone());
	}

	/**
	 * Normalize a campaign bound from admin input.
	 *
	 * Date-only values (Y-m-d) expand to start-of-day or end-of-day so a window
	 * like 2026-07-16 → 2026-07-19 includes the entire final calendar day
	 * (FINAL15 goldens require through 23:59:59 on the end date).
	 *
	 * @param string $raw   User or stored value
	 * @param string $which start|end
	 * @return string Y-m-d H:i:s or empty
	 */
	public static function normalize_bound($raw, $which = 'start') {
		$raw = trim((string) $raw);
		if ($raw === '') {
			return '';
		}
		// Date only: YYYY-MM-DD
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
			return $which === 'end' ? ($raw . ' 23:59:59') : ($raw . ' 00:00:00');
		}
		// Date + time without seconds
		if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}$/', $raw)) {
			$raw = str_replace('T', ' ', $raw) . ':00';
		}
		try {
			$dt = self::parse_local($raw);
			return self::to_mysql_local($dt);
		} catch (\Exception $e) {
			return $raw;
		}
	}

	/**
	 * MySQL post_date comparison string (site-local wall clock, as WP stores orders).
	 *
	 * @param \DateTimeImmutable $dt
	 * @return string
	 */
	public static function to_mysql_local(\DateTimeImmutable $dt) {
		return $dt->setTimezone(self::site_timezone())->format('Y-m-d H:i:s');
	}

	/**
	 * True when window may cross EU DST change (last Sunday Mar/Oct).
	 *
	 * @param string $start_local
	 * @param string $end_local
	 * @return bool
	 */
	public static function crosses_dst_boundary($start_local, $end_local) {
		$tz = self::site_timezone();
		// Fixed-offset zones never transition; still warn if calendar spans transition Sundays
		// because production uses gmt_offset=2 (CEST) year-round.
		$start = self::parse_local($start_local);
		$end   = self::parse_local($end_local);
		if ($end < $start) {
			return false;
		}

		foreach ([$start->format('Y'), $end->format('Y')] as $year) {
			$year = (int) $year;
			$mar  = self::last_sunday_of_month($year, 3, $tz);
			$oct  = self::last_sunday_of_month($year, 10, $tz);
			foreach ([$mar, $oct] as $boundary) {
				if ($boundary >= $start && $boundary <= $end) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param int            $year
	 * @param int            $month
	 * @param \DateTimeZone  $tz
	 * @return \DateTimeImmutable
	 */
	private static function last_sunday_of_month($year, $month, \DateTimeZone $tz) {
		$dt = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
		$dt = $dt->modify('last day of this month');
		while ((int) $dt->format('w') !== 0) {
			$dt = $dt->modify('-1 day');
		}
		return $dt->setTime(2, 0, 0);
	}
}
