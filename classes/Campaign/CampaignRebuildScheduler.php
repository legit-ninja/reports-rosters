<?php
/**
 * Rebuild campaign summaries via WP-Cron (chunk-friendly; no WP-CLI required).
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

use InterSoccer\ReportsRosters\Campaign\Metrics\CampaignMetricsAggregator;
use InterSoccer\ReportsRosters\Data\Repositories\CampaignRepository;

defined('ABSPATH') or die('Restricted access');

class CampaignRebuildScheduler {

	const HOOK = 'intersoccer_campaign_rebuild';

	/** @var CampaignRepository */
	private $repo;

	/** @var CampaignSummaryStore */
	private $store;

	/** @var CampaignMetricsAggregator */
	private $aggregator;

	public function __construct(
		CampaignRepository $repo = null,
		CampaignSummaryStore $store = null,
		CampaignMetricsAggregator $aggregator = null
	) {
		$this->repo = $repo ?: new CampaignRepository();
		$this->store = $store ?: new CampaignSummaryStore();
		$this->aggregator = $aggregator ?: new CampaignMetricsAggregator();
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action(self::HOOK, [$this, 'process_queue']);
		// Register custom interval before scheduling — wp_schedule_event rejects unknown schedules.
		add_filter('cron_schedules', [$this, 'schedules']);
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + 60, 'intersoccer_campaign_fifteen', self::HOOK);
		}
	}

	/**
	 * @param array<string,array> $schedules
	 * @return array<string,array>
	 */
	public function schedules($schedules) {
		$schedules['intersoccer_campaign_fifteen'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display' => __('Every 15 minutes (Campaign Analytics)', 'intersoccer-reports-rosters'),
		];
		return $schedules;
	}

	/**
	 * @return void
	 */
	public function process_queue() {
		$queue = get_option('intersoccer_campaign_rebuild_queue', []);
		if (!is_array($queue) || empty($queue)) {
			// Refresh in-window campaigns periodically.
			foreach ($this->repo->all() as $campaign) {
				if ($this->is_active_or_recent($campaign)) {
					$queue[] = $campaign->id;
				}
			}
			$queue = array_values(array_unique($queue));
		}
		if (empty($queue)) {
			return;
		}

		$id = (int) array_shift($queue);
		update_option('intersoccer_campaign_rebuild_queue', $queue, false);
		$this->rebuild_campaign($id);
	}

	/**
	 * @param int $campaign_id
	 * @return array<string,mixed>|null
	 */
	public function rebuild_campaign($campaign_id) {
		$guard = HposGuard::assert_legacy();
		if (!$guard['ok']) {
			$this->store->upsert($campaign_id, 'hpos', 'n/a', 'failed', null, [$guard['message']]);
			return null;
		}

		$campaign = $this->repo->find($campaign_id);
		if (!$campaign || $campaign->start_datetime === '' || $campaign->end_datetime === '') {
			return null;
		}

		$hash = $campaign->definition_hash();
		$status_set = implode(',', $campaign->order_statuses);
		$this->store->upsert($campaign_id, $hash, $status_set, 'building', null, []);

		$source = $this->resolve_source();
		$gate = (new DataQualityGate())->evaluate($source->source_id(), $campaign->start_datetime, $campaign->end_datetime);
		if (!$gate['ok']) {
			$this->store->upsert($campaign_id, $hash, $status_set, 'failed', null, $gate['errors']);
			return null;
		}

		try {
			$baseline = $campaign->baseline_window();
		} catch (\Exception $e) {
			$this->store->upsert($campaign_id, $hash, $status_set, 'failed', null, [$e->getMessage()]);
			return null;
		}

		$campaign_lines = $source->fetch_line_items(
			$campaign->start_datetime,
			$campaign->end_datetime,
			$campaign->order_statuses,
			$campaign->coupon_codes
		);
		$baseline_lines = $source->fetch_line_items(
			$baseline['start'],
			$baseline['end'],
			$campaign->order_statuses,
			[]
		);

		$usage_counts = $this->coupon_usage_counts($campaign->coupon_codes);
		$prior_keys = $this->prior_family_keys($campaign_lines);

		$payload = $this->aggregator->aggregate($campaign, $campaign_lines, $baseline_lines, [
			'source_id' => $source->source_id(),
			'baseline_window' => $baseline,
			'gate' => $gate,
			'coupon_usage_counts' => $usage_counts,
			'prior_family_keys' => $prior_keys,
			'child_rows' => $this->child_rows_for_export($campaign_lines),
		]);
		$payload['child_rows'] = $this->child_rows_for_export($campaign_lines);

		$this->store->upsert($campaign_id, $hash, $status_set, 'ready', $payload, $payload['warnings'] ?? []);
		return $payload;
	}

	/**
	 * @return BookingSourceInterface
	 */
	public function resolve_source() {
		$choice = function_exists('get_option')
			? (string) get_option('intersoccer_campaign_booking_source', 'orders')
			: 'orders';
		$choice = apply_filters('intersoccer_campaign_booking_source', $choice);
		if ($choice === 'roster') {
			return new RosterBookingSource();
		}
		return new OrdersBookingSource();
	}

	/**
	 * @param CampaignDefinition $campaign
	 * @return bool
	 */
	private function is_active_or_recent(CampaignDefinition $campaign) {
		if ($campaign->start_datetime === '' || $campaign->end_datetime === '') {
			return false;
		}
		$now = current_time('mysql');
		$recent = gmdate('Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS);
		return ($campaign->start_datetime <= $now && $campaign->end_datetime >= $now)
			|| $campaign->end_datetime >= $recent;
	}

	/**
	 * @param string[] $codes
	 * @return array<string,int>
	 */
	private function coupon_usage_counts(array $codes) {
		$out = [];
		foreach ($codes as $code) {
			$coupon = function_exists('wc_get_coupon_id_by_code') ? wc_get_coupon_id_by_code($code) : 0;
			if (!$coupon) {
				continue;
			}
			$out[strtoupper($code)] = (int) get_post_meta($coupon, 'usage_count', true);
		}
		return $out;
	}

	/**
	 * Families with a prior paid order before the earliest campaign line.
	 *
	 * @param LineItem[] $campaign_lines
	 * @return array<string,bool>
	 */
	private function prior_family_keys(array $campaign_lines) {
		global $wpdb;
		$emails = [];
		$customers = [];
		$min_ts = null;
		foreach ($campaign_lines as $line) {
			$email = strtolower(trim((string) $line->get('billing_email', '')));
			$cid = (int) $line->get('customer_id', 0);
			if ($email !== '') {
				$emails[$email] = true;
			}
			if ($cid > 0) {
				$customers[$cid] = true;
			}
			$ts = (string) $line->get('booking_timestamp');
			if ($min_ts === null || $ts < $min_ts) {
				$min_ts = $ts;
			}
		}
		if ($min_ts === null) {
			return [];
		}

		$keys = [];
		$posts = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		// Customer IDs with earlier completed/processing orders.
		foreach (array_keys($customers) as $cid) {
			$found = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID FROM {$posts} p
				 INNER JOIN {$postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_customer_user' AND pm.meta_value = %s
				 WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed','wc-processing')
				   AND p.post_date < %s LIMIT 1",
				(string) $cid,
				$min_ts
			));
			if ($found) {
				$keys['c:' . $cid] = true;
			}
		}
		foreach (array_keys($emails) as $email) {
			$found = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT p.ID FROM {$posts} p
				 INNER JOIN {$postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_email' AND pm.meta_value = %s
				 WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed','wc-processing')
				   AND p.post_date < %s LIMIT 1",
				$email,
				$min_ts
			));
			if ($found) {
				$keys['e:' . $email] = true;
			}
		}
		return $keys;
	}

	/**
	 * Privacy-allowlisted child rows for Excel export.
	 *
	 * @param LineItem[] $lines
	 * @return array<int,array<string,mixed>>
	 */
	private function child_rows_for_export(array $lines) {
		$allow = Export\ExportAllowlist::allowed_keys();
		$rows = [];
		foreach ($lines as $line) {
			$row = [
				'derived_age' => $line->get('age'),
				'gender' => $line->get('gender'),
				'activity' => $line->get('activity_type'),
				'girls_only' => (int) $line->get('girls_only'),
				'product' => $line->get('product_name'),
				'booking_type' => $line->get('booking_type'),
				'venue' => $line->get('venue'),
				'region' => $line->get('region'),
				'camp_week' => $line->get('camp_week'),
				'price_paid' => $line->get('line_total'),
				'sibling_discount' => $line->get('sibling_discount'),
				'coupon_used' => $line->get('used_campaign_coupon') ? 1 : 0,
				'coupon_codes' => implode(',', (array) $line->get('coupon_codes', [])),
				'booking_timestamp' => $line->get('booking_timestamp'),
			];
			$filtered = [];
			foreach ($allow as $key) {
				if (array_key_exists($key, $row)) {
					$filtered[$key] = $row[$key];
				}
			}
			$rows[] = $filtered;
		}
		return $rows;
	}
}
