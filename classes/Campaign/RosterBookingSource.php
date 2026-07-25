<?php
/**
 * Roster-backed booking source (Phase 3). Gated until integrity verified.
 *
 * Joins live order date/status; never trusts saturated roster prices.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class RosterBookingSource implements BookingSourceInterface {

	/** @var FacetNormalizer */
	private $normalizer;

	/** @var OrdersBookingSource */
	private $orders_fallback;

	public function __construct(FacetNormalizer $normalizer = null, OrdersBookingSource $orders_fallback = null) {
		$this->normalizer = $normalizer ?: new FacetNormalizer();
		$this->orders_fallback = $orders_fallback ?: new OrdersBookingSource($this->normalizer);
	}

	public function source_id() {
		return 'roster';
	}

	/**
	 * {@inheritdoc}
	 *
	 * When integrity is unverified, DataQualityGate refuses the report build.
	 * This method still prefers order totals for money and posts.post_date for time,
	 * using the roster only for demographic enrichment — same spirit as v_roster_reporting.
	 */
	public function fetch_line_items($start_mysql, $end_mysql, array $order_statuses, array $coupon_codes = []) {
		$gate = (new DataQualityGate())->evaluate('roster', $start_mysql, $end_mysql);
		if (!$gate['ok']) {
			return [];
		}

		// Until a dedicated roster SQL path is hardened, reuse orders source for money/time
		// and optionally enrich from roster rows.
		$lines = $this->orders_fallback->fetch_line_items($start_mysql, $end_mysql, $order_statuses, $coupon_codes);
		return $this->enrich_from_roster($lines);
	}

	/**
	 * @param LineItem[] $lines
	 * @return LineItem[]
	 */
	private function enrich_from_roster(array $lines) {
		global $wpdb;
		if (empty($lines)) {
			return $lines;
		}

		$item_ids = [];
		foreach ($lines as $line) {
			$item_ids[] = (int) $line->get('order_item_id');
		}
		$item_ids = array_values(array_unique(array_filter($item_ids)));
		if (empty($item_ids)) {
			return $lines;
		}

		$table = $wpdb->prefix . 'intersoccer_rosters';
		$map = [];
		foreach (array_chunk($item_ids, 500) as $chunk) {
			$in = implode(',', $chunk);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				"SELECT order_item_id, gender, player_dob, dob, girls_only, venue, canton_region, age_group
				 FROM {$table} WHERE order_item_id IN ({$in})",
				ARRAY_A
			);
			if (!is_array($rows)) {
				continue;
			}
			foreach ($rows as $r) {
				$map[(int) $r['order_item_id']] = $r;
			}
		}

		$enriched = [];
		foreach ($lines as $line) {
			$id = (int) $line->get('order_item_id');
			if (!isset($map[$id])) {
				$enriched[] = $line;
				continue;
			}
			$r = $map[$id];
			$patch = [];
			if (!$line->get('gender') || $line->get('gender') === 'other') {
				$g = strtolower((string) ($r['gender'] ?? ''));
				if (in_array($g, ['male', 'female'], true)) {
					$patch['gender'] = $g;
				}
			}
			if ((int) $line->get('girls_only') === 0 && !empty($r['girls_only'])) {
				$patch['girls_only'] = 1;
			}
			$enriched[] = empty($patch) ? $line : $line->with($patch);
		}
		return $enriched;
	}
}
