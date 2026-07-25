<?php
/**
 * Synthetic FINAL15 fixtures reproducing Section 7 acceptance numbers.
 *
 * @package InterSoccer\ReportsRosters\Tests\Campaign
 */

namespace InterSoccer\ReportsRosters\Tests\Campaign;

use InterSoccer\ReportsRosters\Campaign\LineItem;

class Final15FixtureFactory {

	/**
	 * @return LineItem[]
	 */
	public static function campaign_lines() {
		$specs = self::line_specs();
		$order_totals = self::order_totals_map($specs);
		$lines = [];
		foreach ($specs as $i => $spec) {
			$oid = (int) $spec['order_id'];
			$uses = !empty($spec['coupon']);
			$lines[] = new LineItem([
				'order_id' => $oid,
				'order_item_id' => 1000 + $i,
				'booking_timestamp' => $spec['ts'],
				'order_status' => 'wc-completed',
				'order_total' => $order_totals[$oid],
				'line_total' => $spec['line_total'],
				'line_subtotal' => $spec['line_total'],
				'sibling_discount' => 0.0,
				'coupon_discount_order' => $uses ? round(1710.0 / 25, 2) : 0.0,
				'coupon_codes' => $uses ? ['FINAL15'] : [],
				'used_campaign_coupon' => $uses,
				'customer_id' => $oid,
				'billing_email' => 'family' . $oid . '@example.com',
				'product_name' => !empty($spec['girls_only']) ? "Camp, Girls' Only" : 'Summer Camp',
				'activity_type' => $spec['activity'],
				'girls_only' => !empty($spec['girls_only']) ? 1 : 0,
				'booking_type' => $spec['booking_type'],
				'day_length' => $spec['day_length'],
				'venue' => $spec['venue'],
				'region' => $spec['region'],
				'age_group' => $spec['age_group'],
				'camp_week' => $spec['camp_week'],
				'gender' => $spec['gender'],
				'age' => $spec['age'],
				'attribution' => $spec['attribution'],
				'utm_recovered' => null,
			]);
		}
		// Fix coupon discount remainder on order 25
		foreach ($lines as $i => $line) {
			if ((int) $line->get('order_id') === 25 && $line->get('used_campaign_coupon')) {
				$lines[$i] = $line->with(['coupon_discount_order' => round(1710.0 - 68.4 * 24, 2)]);
				break;
			}
		}
		return $lines;
	}

	/**
	 * @return LineItem[]
	 */
	public static function baseline_lines() {
		$totals = self::distribute(41, 18020.15);
		$line_parts = self::distribute(49, 18020.15);
		$lines = [];
		$line = 0;
		$oid = 1;
		for ($d = 0; $d < 8; $d++) {
			for ($k = 0; $k < 2; $k++) {
				$lines[] = self::baseline_line($oid, $line, $totals[$oid - 1], $line_parts[$line]);
				$line++;
			}
			$oid++;
		}
		while ($line < 49) {
			$lines[] = self::baseline_line($oid, $line, $totals[$oid - 1], $line_parts[$line]);
			$line++;
			$oid++;
		}
		return $lines;
	}

	/**
	 * @return array<string,bool>
	 */
	public static function prior_family_keys() {
		$keys = [];
		for ($oid = 44; $oid <= 74; $oid++) {
			$keys['e:family' . $oid . '@example.com'] = true;
			$keys['c:' . $oid] = true;
		}
		return $keys;
	}

	/**
	 * @param array<int,array<string,mixed>> $specs
	 * @return array<int,float>
	 */
	private static function order_totals_map(array $specs) {
		$coupon_oids = [];
		$all_oids = [];
		foreach ($specs as $s) {
			$all_oids[(int) $s['order_id']] = true;
			if (!empty($s['coupon'])) {
				$coupon_oids[(int) $s['order_id']] = true;
			}
		}
		$coupon_list = array_keys($coupon_oids);
		sort($coupon_list);
		$non_coupon = array_values(array_diff(array_keys($all_oids), $coupon_list));
		$coupon_totals = self::distribute(count($coupon_list), 9710.00);
		$rest_totals = self::distribute(count($non_coupon), 26405.75 - 9710.00);
		$map = [];
		foreach ($coupon_list as $i => $oid) {
			$map[$oid] = $coupon_totals[$i];
		}
		foreach ($non_coupon as $i => $oid) {
			$map[$oid] = $rest_totals[$i];
		}
		return $map;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function line_specs() {
		$regions = self::expand(['Geneva' => 44, 'Zurich' => 25, 'Vaud' => 12, 'Basel-Stadt' => 1, 'Zug' => 1, 'not_recorded' => 2]);
		$venues = self::expand(['Vessy' => 17, 'FC Seefeld' => 14, 'Varembé' => 12, 'Langnau am Albis' => 10, 'Colovray' => 5, 'Frontenex' => 5, 'Other Venue' => 22]);
		$weeks = self::expand([
			'summer_week_5' => 42, 'summer_week_6' => 12, 'summer_week_7' => 12, 'summer_week_8' => 8,
			'summer_week_4' => 3, 'summer_week_9' => 2, 'autumn_courses' => 3, 'summer_week_3' => 3,
		]);
		$booking = self::expand(['full-week' => 46, 'single-days' => 33, 'full-term' => 4, 'other' => 2]);
		$day_length = self::expand(['full-day' => 41, 'half-day-mini' => 29, 'other' => 15]);
		$genders = array_merge(array_fill(0, 77, 'male'), array_fill(0, 8, 'female'));
		$activities = array_merge(
			array_fill(0, 78, ['camp', 0]),
			array_fill(0, 3, ['camp', 1]),
			array_fill(0, 4, ['course', 0])
		);

		/*
		 * Construct 74 orders / 85 lines with coupon intensity by day.
		 * Orders 1-25 = coupon orders (31 lines). Orders 26-74 = 49 lines.
		 * Day line counts: 13,15,16,41. Coupon lines: 0,1,4,26.
		 */
		$day_plan = [
			['2026-07-16', 13, 3820.00, 0],
			['2026-07-17', 15, 4421.50, 1],
			['2026-07-18', 16, 6408.25, 4],
			['2026-07-19', 41, 11837.00, 26],
		];

		// Coupon order line capacities: 6 orders×2 + 19×1 = 31
		$coupon_order_queue = [];
		for ($o = 1; $o <= 6; $o++) {
			$coupon_order_queue[] = $o;
			$coupon_order_queue[] = $o;
		}
		for ($o = 7; $o <= 25; $o++) {
			$coupon_order_queue[] = $o;
		}

		$non_coupon_queue = range(26, 74); // 49 orders
		// Need 85-31=54 non-coupon lines on 49 orders → 5 doubles + 44 singles = 54
		$non_coupon_line_orders = [];
		for ($o = 26; $o <= 30; $o++) {
			$non_coupon_line_orders[] = $o;
			$non_coupon_line_orders[] = $o;
		}
		for ($o = 31; $o <= 74; $o++) {
			$non_coupon_line_orders[] = $o;
		}

		$coupon_qi = 0;
		$non_qi = 0;
		$specs = [];
		$line_i = 0;
		foreach ($day_plan as $plan) {
			$parts = self::distribute($plan[1], $plan[2]);
			for ($i = 0; $i < $plan[1]; $i++) {
				$is_coupon = $i < $plan[3];
				if ($is_coupon) {
					$oid = $coupon_order_queue[$coupon_qi++];
				} else {
					$oid = $non_coupon_line_orders[$non_qi++];
				}
				$hour = 12;
				$min = 0;
				$sec = 0;
				if ($plan[0] === '2026-07-19' && $is_coupon && $oid === 25) {
					$hour = 23;
					$min = 35;
					$sec = 54;
				}
				$act = $activities[$line_i];
				$specs[] = [
					'order_id' => $oid,
					'ts' => sprintf('%s %02d:%02d:%02d', $plan[0], $hour, $min, $sec),
					'line_total' => $parts[$i],
					'coupon' => $is_coupon,
					'activity' => $act[0],
					'girls_only' => $act[1],
					'booking_type' => $booking[$line_i],
					'day_length' => $day_length[$line_i],
					'venue' => $venues[$line_i],
					'region' => $regions[$line_i],
					'age_group' => $line_i < 45 ? '3-5y Mini' : '6-9y Full Day',
					'camp_week' => $weeks[$line_i],
					'gender' => $genders[$line_i],
					'age' => $line_i < 45 ? 4.5 : 7.7,
					'attribution' => self::attr_for($oid),
				];
				$line_i++;
			}
		}

		$sum_age = 0.0;
		foreach ($specs as $s) {
			$sum_age += $s['age'];
		}
		$adj = 5.99 - ($sum_age / 85);
		foreach ($specs as $i => $s) {
			$specs[$i]['age'] = round($s['age'] + $adj, 2);
		}

		return $specs;
	}

	/**
	 * @param int $oid
	 * @return array<string,string>
	 */
	private static function attr_for($oid) {
		if ($oid <= 44) {
			return ['source_type' => 'typein', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'referrer' => ''];
		}
		if ($oid <= 64) {
			return ['source_type' => 'organic', 'utm_source' => 'google', 'utm_medium' => 'organic', 'utm_campaign' => '', 'referrer' => ''];
		}
		if ($oid <= 68) {
			return ['source_type' => 'paid', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => '', 'referrer' => ''];
		}
		if ($oid <= 72) {
			$hosts = ['parentville.ch', 'vaudfamille.ch', 'mail.google.com', 'bing.com'];
			return ['source_type' => 'referral', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'referrer' => 'https://' . $hosts[$oid - 69] . '/'];
		}
		if ($oid === 73) {
			return ['source_type' => 'utm', 'utm_source' => 'flyer', 'utm_medium' => 'qr', 'utm_campaign' => 'QR/Flyer', 'referrer' => ''];
		}
		return ['source_type' => 'unknown', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'referrer' => ''];
	}

	/**
	 * @param int   $oid
	 * @param int   $line
	 * @param float $order_total
	 * @param float $line_total
	 * @return LineItem
	 */
	private static function baseline_line($oid, $line, $order_total, $line_total) {
		return new LineItem([
			'order_id' => $oid,
			'order_item_id' => 5000 + $line,
			'booking_timestamp' => '2026-07-10 12:00:00',
			'order_status' => 'wc-completed',
			'order_total' => $order_total,
			'line_total' => $line_total,
			'line_subtotal' => $line_total,
			'sibling_discount' => 0,
			'coupon_discount_order' => 0,
			'coupon_codes' => [],
			'used_campaign_coupon' => false,
			'customer_id' => $oid,
			'billing_email' => 'base' . $oid . '@example.com',
			'product_name' => 'Camp',
			'activity_type' => 'camp',
			'girls_only' => 0,
			'booking_type' => 'full-week',
			'day_length' => 'full-day',
			'venue' => 'Vessy',
			'region' => 'Geneva',
			'age_group' => '6-9y',
			'camp_week' => 'summer_week_4',
			'gender' => 'male',
			'age' => 7.0,
			'attribution' => ['source_type' => 'typein'],
			'utm_recovered' => null,
		]);
	}

	/**
	 * @param array<string,int> $counts
	 * @return string[]
	 */
	private static function expand(array $counts) {
		$out = [];
		foreach ($counts as $k => $n) {
			for ($i = 0; $i < $n; $i++) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/**
	 * @param int   $n
	 * @param float $total
	 * @return float[]
	 */
	private static function distribute($n, $total) {
		if ($n <= 0) {
			return [];
		}
		$each = round($total / $n, 2);
		$out = array_fill(0, $n, $each);
		$out[$n - 1] = round($out[$n - 1] + ($total - array_sum($out)), 2);
		return $out;
	}
}
