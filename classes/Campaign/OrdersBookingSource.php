<?php
/**
 * Orders-backed booking source (legacy posts / postmeta / order items).
 *
 * Pivots itemmeta once per line item to avoid duplicate pa_* join blowups.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class OrdersBookingSource implements BookingSourceInterface {

	/** @var FacetNormalizer */
	private $normalizer;

	public function __construct(FacetNormalizer $normalizer = null) {
		$this->normalizer = $normalizer ?: new FacetNormalizer();
	}

	public function source_id() {
		return 'orders';
	}

	/**
	 * {@inheritdoc}
	 */
	public function fetch_line_items($start_mysql, $end_mysql, array $order_statuses, array $coupon_codes = []) {
		global $wpdb;

		$guard = HposGuard::assert_legacy();
		if (!$guard['ok']) {
			return [];
		}

		$statuses = array_values(array_filter(array_map('strval', $order_statuses)));
		if (empty($statuses)) {
			$statuses = ['wc-completed', 'wc-processing'];
		}

		$posts = $wpdb->posts;
		$items = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$postmeta = $wpdb->postmeta;

		$status_in = implode(',', array_fill(0, count($statuses), '%s'));
		$sql = $wpdb->prepare(
			"SELECT p.ID AS order_id, p.post_date AS booking_timestamp, p.post_status AS order_status,
			        oi.order_item_id, oi.order_item_name AS product_name,
			        pm_total.meta_value AS order_total,
			        pm_customer.meta_value AS customer_id,
			        pm_email.meta_value AS billing_email
			 FROM {$posts} p
			 INNER JOIN {$items} oi ON oi.order_id = p.ID AND oi.order_item_type = 'line_item'
			 LEFT JOIN {$postmeta} pm_total ON pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total'
			 LEFT JOIN {$postmeta} pm_customer ON pm_customer.post_id = p.ID AND pm_customer.meta_key = '_customer_user'
			 LEFT JOIN {$postmeta} pm_email ON pm_email.post_id = p.ID AND pm_email.meta_key = '_billing_email'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ({$status_in})
			   AND p.post_date >= %s AND p.post_date <= %s
			 ORDER BY p.post_date ASC, oi.order_item_id ASC",
			array_merge($statuses, [$start_mysql, $end_mysql])
		);

		$rows = $wpdb->get_results($sql, ARRAY_A);
		if (!is_array($rows) || empty($rows)) {
			return [];
		}

		$order_ids = array_values(array_unique(array_map('intval', array_column($rows, 'order_id'))));
		$item_ids = array_values(array_unique(array_map('intval', array_column($rows, 'order_item_id'))));

		$meta_by_item = $this->pivot_itemmeta($item_ids);
		$coupons_by_order = $this->load_order_coupons($order_ids);
		$attr_by_order = $this->load_attribution($order_ids);
		$coupon_codes_l = array_map('strtoupper', $coupon_codes);

		$line_items = [];
		foreach ($rows as $row) {
			$item_id = (int) $row['order_item_id'];
			$order_id = (int) $row['order_id'];
			$raw = $meta_by_item[$item_id] ?? [];
			$raw['_product_name'] = (string) ($row['product_name'] ?? '');

			$facets = $this->normalizer->normalize_from_meta($raw);

			$line_total = $this->meta_float($raw, ['_line_total', 'line_total']);
			$line_subtotal = $this->meta_float($raw, ['_line_subtotal', 'line_subtotal']);
			$sibling_discount = $this->parse_discount_amount($this->first_scalar($raw, ['Discount Amount', 'Discount']));

			$order_coupons = $coupons_by_order[$order_id] ?? [];
			$matched_codes = [];
			$coupon_discount = 0.0;
			foreach ($order_coupons as $c) {
				$code_u = strtoupper((string) $c['code']);
				if (empty($coupon_codes_l) || in_array($code_u, $coupon_codes_l, true)) {
					$matched_codes[] = (string) $c['code'];
					$coupon_discount += (float) $c['discount'];
				}
			}

			$dob = $this->first_scalar($raw, ['Attendee DOB', 'DOB', 'player_dob']);
			$gender = strtolower($this->first_scalar($raw, ['Attendee Gender', 'Gender', 'Player Gender']));
			if (!in_array($gender, ['male', 'female', 'other'], true)) {
				$gender = $gender === 'm' ? 'male' : ($gender === 'f' ? 'female' : 'other');
			}

			$age = $this->derive_age($dob, (string) $row['booking_timestamp']);

			$attr = $attr_by_order[$order_id] ?? [];
			$utm_recovered = $this->recover_utm_from_referrer($attr);

			$line_items[] = new LineItem([
				'order_id' => $order_id,
				'order_item_id' => $item_id,
				'booking_timestamp' => (string) $row['booking_timestamp'],
				'order_status' => (string) $row['order_status'],
				'order_total' => (float) ($row['order_total'] ?? 0),
				'line_total' => $line_total,
				'line_subtotal' => $line_subtotal,
				'sibling_discount' => $sibling_discount,
				'coupon_discount_order' => $coupon_discount,
				'coupon_codes' => $matched_codes,
				'used_campaign_coupon' => !empty($matched_codes),
				'customer_id' => (int) ($row['customer_id'] ?? 0),
				'billing_email' => (string) ($row['billing_email'] ?? ''),
				'product_name' => (string) ($row['product_name'] ?? ''),
				'activity_type' => $facets['activity_type'],
				'girls_only' => (int) $facets['girls_only'],
				'booking_type' => $facets['booking_type'],
				'day_length' => $facets['day_length'],
				'venue' => $facets['venue'],
				'region' => $facets['region'],
				'age_group' => $facets['age_group'],
				'camp_week' => $facets['camp_week'],
				'camp_week_index' => $facets['camp_week_index'],
				'season' => $facets['season'],
				'gender' => $gender,
				'age' => $age,
				'attribution' => $attr,
				'utm_recovered' => $utm_recovered,
			]);
		}

		return $line_items;
	}

	/**
	 * Pivot all itemmeta for the given line IDs in one query.
	 * Duplicate keys become arrays (caller takes first scalar).
	 *
	 * @param int[] $item_ids
	 * @return array<int,array<string,mixed>>
	 */
	public function pivot_itemmeta(array $item_ids) {
		global $wpdb;
		$item_ids = array_values(array_filter(array_map('intval', $item_ids)));
		if (empty($item_ids)) {
			return [];
		}

		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$out = [];
		foreach (array_chunk($item_ids, 500) as $chunk) {
			$in = implode(',', $chunk);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are intval'd.
			$rows = $wpdb->get_results(
				"SELECT order_item_id, meta_key, meta_value FROM {$itemmeta} WHERE order_item_id IN ({$in})",
				ARRAY_A
			);
			if (!is_array($rows)) {
				continue;
			}
			foreach ($rows as $r) {
				$id = (int) $r['order_item_id'];
				$key = (string) $r['meta_key'];
				$val = $r['meta_value'];
				if (!isset($out[$id])) {
					$out[$id] = [];
				}
				if (!array_key_exists($key, $out[$id])) {
					$out[$id][$key] = $val;
				} else {
					$existing = $out[$id][$key];
					if (!is_array($existing)) {
						$out[$id][$key] = [$existing, $val];
					} else {
						$out[$id][$key][] = $val;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * @param int[] $order_ids
	 * @return array<int,array<int,array{code:string,discount:float}>>
	 */
	private function load_order_coupons(array $order_ids) {
		global $wpdb;
		$order_ids = array_values(array_filter(array_map('intval', $order_ids)));
		if (empty($order_ids)) {
			return [];
		}

		$items = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$out = [];

		foreach (array_chunk($order_ids, 300) as $chunk) {
			$in = implode(',', $chunk);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				"SELECT oi.order_id, oi.order_item_name AS code,
				        MAX(CASE WHEN im.meta_key = 'discount_amount' THEN im.meta_value END) AS discount_amount,
				        MAX(CASE WHEN im.meta_key = 'coupon_data' THEN im.meta_value END) AS coupon_data
				 FROM {$items} oi
				 LEFT JOIN {$itemmeta} im ON im.order_item_id = oi.order_item_id
				 WHERE oi.order_item_type = 'coupon' AND oi.order_id IN ({$in})
				 GROUP BY oi.order_id, oi.order_item_id, oi.order_item_name",
				ARRAY_A
			);
			if (!is_array($rows)) {
				continue;
			}
			foreach ($rows as $r) {
				$oid = (int) $r['order_id'];
				$discount = (float) ($r['discount_amount'] ?? 0);
				$out[$oid][] = [
					'code' => (string) $r['code'],
					'discount' => $discount,
				];
			}
		}
		return $out;
	}

	/**
	 * @param int[] $order_ids
	 * @return array<int,array<string,string>>
	 */
	private function load_attribution(array $order_ids) {
		global $wpdb;
		$order_ids = array_values(array_filter(array_map('intval', $order_ids)));
		if (empty($order_ids)) {
			return [];
		}

		$keys = [
			'_wc_order_attribution_source_type',
			'_wc_order_attribution_utm_source',
			'_wc_order_attribution_utm_medium',
			'_wc_order_attribution_utm_campaign',
			'_wc_order_attribution_referrer',
			'_wc_order_attribution_session_entry',
		];
		$postmeta = $wpdb->postmeta;
		$out = [];
		$key_in = implode(',', array_map(static function ($k) use ($wpdb) {
			return $wpdb->prepare('%s', $k);
		}, $keys));

		foreach (array_chunk($order_ids, 300) as $chunk) {
			$in = implode(',', $chunk);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				"SELECT post_id, meta_key, meta_value FROM {$postmeta}
				 WHERE post_id IN ({$in}) AND meta_key IN ({$key_in})",
				ARRAY_A
			);
			if (!is_array($rows)) {
				continue;
			}
			foreach ($rows as $r) {
				$oid = (int) $r['post_id'];
				if (!isset($out[$oid])) {
					$out[$oid] = [
						'source_type' => '',
						'utm_source' => '',
						'utm_medium' => '',
						'utm_campaign' => '',
						'referrer' => '',
					];
				}
				$key = (string) $r['meta_key'];
				$val = (string) $r['meta_value'];
				if ($key === '_wc_order_attribution_source_type') {
					$out[$oid]['source_type'] = $val;
				} elseif ($key === '_wc_order_attribution_utm_source') {
					$out[$oid]['utm_source'] = $val;
				} elseif ($key === '_wc_order_attribution_utm_medium') {
					$out[$oid]['utm_medium'] = $val;
				} elseif ($key === '_wc_order_attribution_utm_campaign') {
					$out[$oid]['utm_campaign'] = $val;
				} elseif ($key === '_wc_order_attribution_referrer' || $key === '_wc_order_attribution_session_entry') {
					if ($out[$oid]['referrer'] === '') {
						$out[$oid]['referrer'] = $val;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * @param array<string,string> $attr
	 * @return array<string,string>|null
	 */
	private function recover_utm_from_referrer(array $attr) {
		$type = strtolower((string) ($attr['source_type'] ?? ''));
		if (!in_array($type, ['typein', 'direct', ''], true)) {
			return null;
		}
		$ref = (string) ($attr['referrer'] ?? '');
		if ($ref === '' || strpos($ref, 'utm_') === false) {
			// Also check utm fields already present under typein.
			if (!empty($attr['utm_source']) || !empty($attr['utm_campaign'])) {
				return [
					'utm_source' => (string) ($attr['utm_source'] ?? ''),
					'utm_medium' => (string) ($attr['utm_medium'] ?? ''),
					'utm_campaign' => (string) ($attr['utm_campaign'] ?? ''),
					'note' => 'tagged_link_attribution_lost',
				];
			}
			return null;
		}
		$query = [];
		$parts = function_exists('wp_parse_url') ? wp_parse_url($ref) : parse_url($ref);
		if (!empty($parts['query'])) {
			parse_str($parts['query'], $query);
		}
		if (empty($query['utm_source']) && empty($query['utm_campaign'])) {
			return null;
		}
		return [
			'utm_source' => (string) ($query['utm_source'] ?? ''),
			'utm_medium' => (string) ($query['utm_medium'] ?? ''),
			'utm_campaign' => (string) ($query['utm_campaign'] ?? ''),
			'note' => 'tagged_link_attribution_lost',
		];
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param string[]            $keys
	 * @return float
	 */
	private function meta_float(array $raw, array $keys) {
		foreach ($keys as $key) {
			if (!isset($raw[$key])) {
				continue;
			}
			$v = $raw[$key];
			if (is_array($v)) {
				$v = reset($v);
			}
			if (is_numeric($v)) {
				return (float) $v;
			}
		}
		return 0.0;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param string[]            $keys
	 * @return string
	 */
	private function first_scalar(array $raw, array $keys) {
		foreach ($keys as $key) {
			if (!isset($raw[$key])) {
				continue;
			}
			$v = $raw[$key];
			if (is_array($v)) {
				foreach ($v as $item) {
					if (is_scalar($item) && (string) $item !== '') {
						return (string) $item;
					}
				}
				continue;
			}
			if ((string) $v !== '') {
				return (string) $v;
			}
		}
		return '';
	}

	/**
	 * @param string $raw
	 * @return float
	 */
	private function parse_discount_amount($raw) {
		$raw = html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (preg_match('/([0-9]+(?:[.,][0-9]+)?)/', $raw, $m)) {
			return (float) str_replace(',', '.', $m[1]);
		}
		return 0.0;
	}

	/**
	 * @param string $dob
	 * @param string $as_of
	 * @return float|null
	 */
	private function derive_age($dob, $as_of) {
		$dob = trim((string) $dob);
		if ($dob === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $dob)) {
			return null;
		}
		try {
			$born = new \DateTimeImmutable(substr($dob, 0, 10));
			$at = new \DateTimeImmutable(substr($as_of, 0, 10));
			$diff = $born->diff($at);
			return round($diff->y + ($diff->m / 12) + ($diff->d / 365), 2);
		} catch (\Exception $e) {
			return null;
		}
	}
}
