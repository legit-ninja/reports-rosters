<?php
/**
 * Pure campaign metrics aggregation from normalized LineItem lists.
 *
 * @package InterSoccer\ReportsRosters\Campaign\Metrics
 */

namespace InterSoccer\ReportsRosters\Campaign\Metrics;

use InterSoccer\ReportsRosters\Campaign\CampaignDefinition;
use InterSoccer\ReportsRosters\Campaign\CampaignTimezone;
use InterSoccer\ReportsRosters\Campaign\DataQualityGate;
use InterSoccer\ReportsRosters\Campaign\FacetNormalizer;
use InterSoccer\ReportsRosters\Campaign\LineItem;

defined('ABSPATH') or die('Restricted access');

class CampaignMetricsAggregator {

	/** @var FacetNormalizer */
	private $normalizer;

	/** @var DataQualityGate */
	private $gate;

	public function __construct(FacetNormalizer $normalizer = null, DataQualityGate $gate = null) {
		$this->normalizer = $normalizer ?: new FacetNormalizer();
		$this->gate = $gate ?: new DataQualityGate();
	}

	/**
	 * @param CampaignDefinition $campaign
	 * @param LineItem[]         $campaign_lines
	 * @param LineItem[]         $baseline_lines
	 * @param array<string,mixed> $context source_id, coupon_usage_counts, baseline_window, gate
	 * @return array<string,mixed>
	 */
	public function aggregate(CampaignDefinition $campaign, array $campaign_lines, array $baseline_lines, array $context = []) {
		$source_id = (string) ($context['source_id'] ?? 'orders');
		$baseline_window = $context['baseline_window'] ?? $campaign->baseline_window();
		$gate = $context['gate'] ?? $this->gate->evaluate($source_id, $campaign->start_datetime, $campaign->end_datetime);

		$headline = $this->headline($campaign_lines, $baseline_lines);
		$volume_value = $this->volume_value($campaign_lines, $baseline_lines, $campaign->coupon_codes);
		$coupons = $this->coupon_usage($campaign_lines, $campaign->coupon_codes, $context['coupon_usage_counts'] ?? []);
		$timing = $this->timing_profile($campaign_lines, $campaign->marketing_activations);
		$attribution = $this->attribution($campaign_lines);
		$mix = $this->booking_mix($campaign_lines);
		$demand = $this->demand_destination($campaign_lines, $campaign->target_scope);
		$regions = $this->dimension_breakdown($campaign_lines, 'region');
		$venues = $this->dimension_breakdown($campaign_lines, 'venue');
		$cohorts = $this->cohorts($campaign_lines, $context['prior_family_keys'] ?? null);
		$demographics = $this->demographics($campaign_lines);
		$occupancy = $this->occupancy($campaign_lines, $baseline_lines, $campaign->capacity_overrides);
		$observation_lines = $context['observation_lines'] ?? [];
		$momentum = $this->momentum($campaign, is_array($observation_lines) ? $observation_lines : [], $context);

		$line_total = (int) $headline['line_item_bookings'];
		$warnings = array_merge($gate['warnings'] ?? [], $baseline_window['warnings'] ?? []);
		$errors = $gate['errors'] ?? [];

		if (!$this->gate->buckets_reconcile($line_total, $regions)) {
			$errors[] = 'region_breakdown_does_not_reconcile';
		}
		if (!$this->gate->buckets_reconcile($line_total, $demand['by_week'] ?? [])) {
			$warnings[] = 'demand_breakdown_partial';
		}
		if (!empty($momentum['trough']['verdict']) && $momentum['trough']['verdict'] === 'insufficient_after') {
			$warnings[] = 'momentum_after_incomplete';
		}
		if (empty($campaign->coupon_codes)) {
			$warnings[] = 'campaign_coupon_codes_empty';
		}

		$notes = $this->data_notes($campaign, $campaign_lines, $source_id, $warnings, $errors);

		return [
			'campaign' => [
				'id' => $campaign->id,
				'name' => $campaign->name,
				'start' => $campaign->start_datetime,
				'end' => $campaign->end_datetime,
				'coupon_codes' => $campaign->coupon_codes,
				'order_statuses' => $campaign->order_statuses,
				'revenue_basis' => $campaign->revenue_basis,
			],
			'baseline_window' => $baseline_window,
			'source_id' => $source_id,
			'gate' => $gate,
			'headline' => $headline,
			'volume_value' => $volume_value,
			'coupons' => $coupons,
			'timing' => $timing,
			'attribution' => $attribution,
			'mix' => $mix,
			'demand' => $demand,
			'regions' => $regions,
			'venues' => $venues,
			'cohorts' => $cohorts,
			'demographics' => $demographics,
			'occupancy' => $occupancy,
			'momentum' => $momentum,
			'data_notes' => $notes,
			'warnings' => $warnings,
			'errors' => $errors,
			'attribution_limitation' => self::attribution_limitation_copy(),
		];
	}

	/**
	 * Standing copy required on every report.
	 *
	 * @return string
	 */
	public static function attribution_limitation_copy() {
		return 'WooCommerce attribution is last-touch within the purchasing session. '
			. 'For considered purchases (camps), email and social are systematically under-credited: '
			. 'customers who open a newsletter, leave, and book later appear as direct/typein. '
			. 'Per-channel coupon codes survive that gap; UTM parameters found on referrers while '
			. 'source_type is direct/typein are listed separately as “tagged link, attribution lost”.';
	}

	/**
	 * @param LineItem[] $campaign_lines
	 * @param LineItem[] $baseline_lines
	 * @return array<string,mixed>
	 */
	public function headline(array $campaign_lines, array $baseline_lines) {
		$c = $this->period_totals($campaign_lines);
		$b = $this->period_totals($baseline_lines);
		$split = $this->coded_uncoded_order_totals($campaign_lines);

		return [
			'orders' => $c['orders'],
			'line_item_bookings' => $c['lines'],
			'revenue_order_totals' => $c['order_revenue'],
			'revenue_line_totals' => $c['line_revenue'],
			'avg_order_value' => $c['orders'] > 0 ? round($c['order_revenue'] / $c['orders'], 2) : 0.0,
			'coded_orders' => $split['coded_orders'],
			'coded_revenue_order_totals' => $split['coded_revenue_order_totals'],
			'uncoded_orders' => $split['uncoded_orders'],
			'uncoded_revenue_order_totals' => $split['uncoded_revenue_order_totals'],
			'baseline' => [
				'orders' => $b['orders'],
				'line_item_bookings' => $b['lines'],
				'revenue_order_totals' => $b['order_revenue'],
				'revenue_line_totals' => $b['line_revenue'],
				'avg_order_value' => $b['orders'] > 0 ? round($b['order_revenue'] / $b['orders'], 2) : 0.0,
			],
			'pct_change' => [
				'orders' => $this->pct($c['orders'], $b['orders']),
				'line_item_bookings' => $this->pct($c['lines'], $b['lines']),
				'revenue_order_totals' => $this->pct($c['order_revenue'], $b['order_revenue']),
				'avg_order_value' => $this->pct(
					$c['orders'] > 0 ? $c['order_revenue'] / $c['orders'] : 0,
					$b['orders'] > 0 ? $b['order_revenue'] / $b['orders'] : 0
				),
			],
			'labels' => [
				'revenue_order_totals' => 'Revenue (order totals)',
				'revenue_line_totals' => 'Revenue (line totals)',
				'orders' => 'Orders',
				'line_item_bookings' => 'Line-item bookings',
				'coded_orders' => 'Orders with campaign code(s)',
				'uncoded_orders' => 'Orders without campaign code(s)',
			],
		];
	}

	/**
	 * AOV with coupon discount added back (volume/value split).
	 *
	 * @param LineItem[] $campaign_lines
	 * @param LineItem[] $baseline_lines
	 * @param string[]   $coupon_codes
	 * @return array<string,mixed>
	 */
	public function volume_value(array $campaign_lines, array $baseline_lines, array $coupon_codes) {
		$c = $this->period_totals($campaign_lines);
		$b = $this->period_totals($baseline_lines);
		$coupon_discount = $this->unique_order_coupon_discount($campaign_lines, $coupon_codes);
		$sibling = $this->sum_field($campaign_lines, 'sibling_discount');

		$aov = $c['orders'] > 0 ? $c['order_revenue'] / $c['orders'] : 0;
		$aov_plus = $c['orders'] > 0 ? ($c['order_revenue'] + $coupon_discount) / $c['orders'] : 0;
		$b_aov = $b['orders'] > 0 ? $b['order_revenue'] / $b['orders'] : 0;

		return [
			'avg_order_value' => round($aov, 2),
			'avg_order_value_baseline' => round($b_aov, 2),
			'coupon_discount_total' => round($coupon_discount, 2),
			'sibling_combo_discount_total' => round($sibling, 2),
			'avg_order_value_coupon_added_back' => round($aov_plus, 2),
			'aov_vs_baseline_after_add_back_pct' => $this->pct($aov_plus, $b_aov),
			'labels' => [
				'coupon_discount' => 'Coupon discount (promotion)',
				'sibling_combo_discount' => 'Sibling/combo discount (price adjustment, not coupon)',
			],
		];
	}

	/**
	 * @param LineItem[]              $lines
	 * @param string[]                $codes
	 * @param array<string,int>       $usage_counts coupon code => usage_count meta
	 * @return array<string,mixed>
	 */
	public function coupon_usage(array $lines, array $codes, array $usage_counts = []) {
		$by_code = [];
		foreach ($codes as $code) {
			$by_code[strtoupper($code)] = [
				'code' => $code,
				'orders' => [],
				'line_items' => 0,
				'revenue' => 0.0,
				'discount' => 0.0,
				'first_redemption' => null,
				'last_redemption' => null,
				'by_day' => [],
			];
		}

		$order_seen = [];
		foreach ($lines as $line) {
			$codes_on = array_map('strtoupper', (array) $line->get('coupon_codes', []));
			if (empty($codes_on)) {
				continue;
			}
			$ts = (string) $line->get('booking_timestamp');
			$day = substr($ts, 0, 10);
			$oid = (int) $line->get('order_id');
			foreach ($codes_on as $code_u) {
				// Only CPT-configured codes — do not invent rows for incidental coupons.
				if (!isset($by_code[$code_u])) {
					continue;
				}
				$by_code[$code_u]['line_items']++;
				if (!isset($order_seen[$code_u][$oid])) {
					$order_seen[$code_u][$oid] = true;
					$by_code[$code_u]['orders'][$oid] = true;
					$by_code[$code_u]['revenue'] += (float) $line->get('order_total', 0);
					$by_code[$code_u]['discount'] += (float) $line->get('coupon_discount_order', 0);
				}
				if ($by_code[$code_u]['first_redemption'] === null || $ts < $by_code[$code_u]['first_redemption']) {
					$by_code[$code_u]['first_redemption'] = $ts;
				}
				if ($by_code[$code_u]['last_redemption'] === null || $ts > $by_code[$code_u]['last_redemption']) {
					$by_code[$code_u]['last_redemption'] = $ts;
				}
				if (!isset($by_code[$code_u]['by_day'][$day])) {
					$by_code[$code_u]['by_day'][$day] = 0;
				}
				// Count unique order redemptions per day once.
			}
		}

		// Rebuild by_day as unique orders per day.
		$order_day = [];
		foreach ($lines as $line) {
			$codes_on = array_map('strtoupper', (array) $line->get('coupon_codes', []));
			$oid = (int) $line->get('order_id');
			$day = substr((string) $line->get('booking_timestamp'), 0, 10);
			foreach ($codes_on as $code_u) {
				if (!isset($by_code[$code_u])) {
					continue;
				}
				$key = $code_u . '|' . $day . '|' . $oid;
				if (isset($order_day[$key])) {
					continue;
				}
				$order_day[$key] = true;
				if (!isset($by_code[$code_u]['by_day'][$day])) {
					$by_code[$code_u]['by_day'][$day] = 0;
				}
				$by_code[$code_u]['by_day'][$day]++;
			}
		}

		$period = $this->period_totals($lines);
		$out = [];
		foreach ($by_code as $code_u => $row) {
			$order_count = count($row['orders']);
			$meta_usage = isset($usage_counts[$code_u]) ? (int) $usage_counts[$code_u] : (isset($usage_counts[$row['code']]) ? (int) $usage_counts[$row['code']] : null);
			$warning = null;
			if ($meta_usage !== null && $meta_usage !== $order_count) {
				$warning = sprintf('usage_count meta (%d) disagrees with computed orders (%d)', $meta_usage, $order_count);
			}
			$out[$row['code']] = [
				'code' => $row['code'],
				'orders' => $order_count,
				'line_items' => $row['line_items'],
				'revenue' => round($row['revenue'], 2),
				'discount' => round($row['discount'], 2),
				'attach_rate' => $period['orders'] > 0 ? (int) round(100 * $order_count / $period['orders']) : 0,
				'avg_order_value' => $order_count > 0 ? round($row['revenue'] / $order_count, 2) : 0.0,
				'first_redemption' => $row['first_redemption'],
				'last_redemption' => $row['last_redemption'],
				'by_day' => $row['by_day'],
				'usage_count_meta' => $meta_usage,
				'usage_count_warning' => $warning,
			];
		}
		return $out;
	}

	/**
	 * @param LineItem[]                  $lines
	 * @param array<int,array<string,mixed>> $activations
	 * @return array<string,mixed>
	 */
	public function timing_profile(array $lines, array $activations = []) {
		$by_day = [];
		$by_hour = [];

		foreach ($lines as $line) {
			$ts = (string) $line->get('booking_timestamp');
			$day = substr($ts, 0, 10);
			$hour = (int) substr($ts, 11, 2);
			$oid = (int) $line->get('order_id');
			$used_coupon = (bool) $line->get('used_campaign_coupon');
			if (!isset($by_day[$day])) {
				$by_day[$day] = [
					'line_items' => 0,
					'line_revenue' => 0.0,
					'order_revenue' => 0.0,
					'coupon_line_items' => 0,
					'orders' => [],
					'coupon_orders' => [],
				];
			}
			$by_day[$day]['line_items']++;
			$by_day[$day]['line_revenue'] += (float) $line->get('line_total', 0);
			if ($used_coupon) {
				$by_day[$day]['coupon_line_items']++;
				$by_day[$day]['coupon_orders'][$oid] = true;
			}
			if (!isset($by_day[$day]['orders'][$oid])) {
				$by_day[$day]['orders'][$oid] = true;
				$by_day[$day]['order_revenue'] += (float) $line->get('order_total', 0);
			}

			$key = $day . '|' . $hour;
			if (!isset($by_hour[$key])) {
				$by_hour[$key] = [
					'day' => $day,
					'hour' => $hour,
					'line_items' => 0,
					'line_revenue' => 0.0,
					'order_revenue' => 0.0,
					'orders' => [],
					'coupon_line_items' => 0,
					'coupon_orders' => [],
				];
			}
			$by_hour[$key]['line_items']++;
			$by_hour[$key]['line_revenue'] += (float) $line->get('line_total', 0);
			if ($used_coupon) {
				$by_hour[$key]['coupon_line_items']++;
				$by_hour[$key]['coupon_orders'][$oid] = true;
			}
			if (!isset($by_hour[$key]['orders'][$oid])) {
				$by_hour[$key]['orders'][$oid] = true;
				$by_hour[$key]['order_revenue'] += (float) $line->get('order_total', 0);
			}
		}

		$days_out = [];
		foreach ($by_day as $day => $row) {
			$day_name = '';
			try {
				$dt = new \DateTimeImmutable($day . ' 12:00:00');
				$day_name = $dt->format('l');
			} catch (\Exception $e) {
				$day_name = '';
			}
			$days_out[$day] = [
				'day_name' => $day_name,
				'line_items' => $row['line_items'],
				'line_revenue' => round($row['line_revenue'], 2),
				'order_revenue' => round($row['order_revenue'], 2),
				'coupon_line_items' => $row['coupon_line_items'],
				'coupon_orders' => count($row['coupon_orders']),
				'orders' => count($row['orders']),
			];
		}
		ksort($days_out);

		$hours_out = [];
		foreach ($by_hour as $row) {
			$hours_out[] = [
				'day' => $row['day'],
				'hour' => $row['hour'],
				'line_items' => $row['line_items'],
				'line_revenue' => round($row['line_revenue'], 2),
				'order_revenue' => round($row['order_revenue'], 2),
				'orders' => count($row['orders']),
				'coupon_line_items' => $row['coupon_line_items'],
				'coupon_orders' => count($row['coupon_orders']),
			];
		}
		usort($hours_out, static function ($a, $b) {
			return [$a['day'], $a['hour']] <=> [$b['day'], $b['hour']];
		});

		return [
			'by_day' => $days_out,
			'by_hour' => $hours_out,
			'marketing_activations' => $activations,
		];
	}

	/**
	 * @param LineItem[] $lines
	 * @return array<string,mixed>
	 */
	public function attribution(array $lines) {
		$buckets = [];
		$referrals = [];
		$utm_lost = [];
		$order_seen = [];

		foreach ($lines as $line) {
			$oid = (int) $line->get('order_id');
			if (isset($order_seen[$oid])) {
				continue;
			}
			$order_seen[$oid] = true;
			$order_rev = (float) $line->get('order_total', 0);
			$attr = (array) $line->get('attribution', []);
			$type = strtolower((string) ($attr['source_type'] ?? 'unknown'));
			$utm_source = strtolower((string) ($attr['utm_source'] ?? ''));
			$utm_medium = strtolower((string) ($attr['utm_medium'] ?? ''));
			$utm_campaign = (string) ($attr['utm_campaign'] ?? '');
			$referrer = (string) ($attr['referrer'] ?? '');

			$bucket = 'unknown';
			if (in_array($type, ['typein', 'direct'], true) || $type === '') {
				$bucket = 'direct-typein';
			} elseif ($type === 'organic' || ($utm_medium === 'organic' && $utm_source === 'google')) {
				$bucket = 'google organic';
			} elseif ($type === 'paid' || $utm_medium === 'cpc' || $utm_medium === 'ppc') {
				$bucket = ($utm_source === 'google' || $utm_source === '') ? 'google cpc' : $utm_source . ' cpc';
			} elseif ($type === 'referral') {
				$bucket = 'referral';
				$host = $referrer !== '' ? (string) ((function_exists('wp_parse_url') ? wp_parse_url($referrer, PHP_URL_HOST) : parse_url($referrer, PHP_URL_HOST)) ?: $referrer) : 'unknown';
				if (!isset($referrals[$host])) {
					$referrals[$host] = ['orders' => 0, 'revenue' => 0.0];
				}
				$referrals[$host]['orders']++;
				$referrals[$host]['revenue'] += $order_rev;
			} elseif ($utm_campaign !== '' || $utm_source !== '') {
				$bucket = 'utm ' . ($utm_campaign !== '' ? $utm_campaign : $utm_source);
			}

			$recovered = $line->get('utm_recovered');
			if (is_array($recovered)) {
				$utm_lost[] = array_merge($recovered, ['order_id' => $oid, 'revenue' => $order_rev]);
				// Still count under direct but note recovery.
				if ($bucket === 'direct-typein' && !empty($recovered['utm_campaign'])) {
					$alt = 'utm ' . $recovered['utm_campaign'];
					if (!isset($buckets[$alt])) {
						$buckets[$alt] = ['orders' => 0, 'revenue' => 0.0];
					}
					$buckets[$alt]['orders']++;
					$buckets[$alt]['revenue'] += $order_rev;
					continue;
				}
			}

			if (!isset($buckets[$bucket])) {
				$buckets[$bucket] = ['orders' => 0, 'revenue' => 0.0];
			}
			$buckets[$bucket]['orders']++;
			$buckets[$bucket]['revenue'] += $order_rev;
		}

		foreach ($buckets as $k => $row) {
			$buckets[$k]['revenue'] = round($row['revenue'], 2);
		}
		foreach ($referrals as $k => $row) {
			$referrals[$k]['revenue'] = round($row['revenue'], 2);
		}

		return [
			'by_source' => $buckets,
			'referrals' => $referrals,
			'tagged_link_attribution_lost' => $utm_lost,
			'limitation' => self::attribution_limitation_copy(),
		];
	}

	/**
	 * @param LineItem[] $lines
	 * @return array<string,mixed>
	 */
	public function booking_mix(array $lines) {
		$activity = [];
		$booking = [];
		$day_length = [];

		foreach ($lines as $line) {
			if ((int) $line->get('girls_only') === 1) {
				$act_key = 'girls_only';
			} else {
				$act_key = (string) $line->get('activity_type', 'other');
			}
			$bt = (string) $line->get('booking_type', 'other');
			$dl = (string) $line->get('day_length', 'other');
			$rev = (float) $line->get('line_total', 0);

			foreach (
				[
					'activity' => $act_key,
					'booking' => $bt,
					'day_length' => $dl,
				] as $bucket => $key
			) {
				if ($bucket === 'activity') {
					$target =& $activity;
				} elseif ($bucket === 'booking') {
					$target =& $booking;
				} else {
					$target =& $day_length;
				}
				if (!isset($target[$key])) {
					$target[$key] = ['bookings' => 0, 'revenue' => 0.0];
				}
				$target[$key]['bookings']++;
				$target[$key]['revenue'] += $rev;
				unset($target);
			}
		}

		foreach ([$activity, $booking, $day_length] as $set) {
			// no-op placeholder for clarity
		}
		foreach ($activity as $k => $row) {
			$activity[$k]['revenue'] = round($row['revenue'], 2);
		}
		foreach ($booking as $k => $row) {
			$booking[$k]['revenue'] = round($row['revenue'], 2);
		}
		foreach ($day_length as $k => $row) {
			$day_length[$k]['revenue'] = round($row['revenue'], 2);
		}

		return [
			'activity' => $activity,
			'booking_type' => $booking,
			'day_length' => $day_length,
		];
	}

	/**
	 * @param LineItem[]           $lines
	 * @param array<string,mixed>  $target_scope
	 * @return array<string,mixed>
	 */
	public function demand_destination(array $lines, array $target_scope = []) {
		$by_week = [];
		$in_scope = 0;
		$out_scope = 0;
		$scope_weeks = array_map('strval', (array) ($target_scope['camp_weeks'] ?? []));
		$scope_venues = array_map('strval', (array) ($target_scope['venues'] ?? []));

		foreach ($lines as $line) {
			$key = (string) $line->get('camp_week', 'not_recorded');
			$display = $this->normalizer->camp_week_display($key);
			if (!isset($by_week[$key])) {
				$by_week[$key] = ['key' => $key, 'label' => $display, 'bookings' => 0, 'revenue' => 0.0];
			}
			$by_week[$key]['bookings']++;
			$by_week[$key]['revenue'] += (float) $line->get('line_total', 0);

			$scoped = empty($scope_weeks) && empty($scope_venues);
			if (!$scoped) {
				$week_ok = empty($scope_weeks) || in_array($key, $scope_weeks, true) || in_array($display, $scope_weeks, true);
				$venue_ok = empty($scope_venues) || in_array((string) $line->get('venue'), $scope_venues, true);
				if ($week_ok && $venue_ok) {
					$in_scope++;
				} else {
					$out_scope++;
				}
			}
		}

		foreach ($by_week as &$row) {
			$row['revenue'] = round($row['revenue'], 2);
		}
		uasort($by_week, static function ($a, $b) {
			return $b['bookings'] <=> $a['bookings'];
		});

		return [
			'by_week' => $by_week,
			'in_scope_bookings' => $in_scope,
			'out_of_scope_bookings' => $out_scope,
			'target_scope_defined' => !empty($scope_weeks) || !empty($scope_venues),
		];
	}

	/**
	 * @param LineItem[] $lines
	 * @param string     $field
	 * @return array<string,array{bookings:int,revenue:float}>
	 */
	public function dimension_breakdown(array $lines, $field) {
		$out = [];
		foreach ($lines as $line) {
			$key = (string) $line->get($field, 'not_recorded');
			if ($key === '') {
				$key = 'not_recorded';
			}
			if (!isset($out[$key])) {
				$out[$key] = ['bookings' => 0, 'revenue' => 0.0];
			}
			$out[$key]['bookings']++;
			$out[$key]['revenue'] += (float) $line->get('line_total', 0);
		}
		foreach ($out as &$row) {
			$row['revenue'] = round($row['revenue'], 2);
		}
		return $out;
	}

	/**
	 * @param LineItem[]      $lines
	 * @param array<string,bool>|null $prior_family_keys email or customer keys known before campaign
	 * @return array<string,mixed>
	 */
	public function cohorts(array $lines, $prior_family_keys = null) {
		$customer_ids = [];
		foreach ($lines as $line) {
			$cid = (int) $line->get('customer_id', 0);
			if ($cid > 0) {
				$customer_ids[] = $cid;
			}
		}
		$populated_ratio = count($lines) > 0 ? count(array_filter($customer_ids)) / count($lines) : 0;
		$reliable = $populated_ratio >= 0.5;

		$new_orders = [];
		$existing_orders = [];
		$new_families = [];
		$existing_families = [];
		$new_rev = 0.0;
		$existing_rev = 0.0;
		$order_class = [];

		foreach ($lines as $line) {
			$oid = (int) $line->get('order_id');
			$email = strtolower(trim((string) $line->get('billing_email', '')));
			$cid = (int) $line->get('customer_id', 0);
			$family = $cid > 0 ? 'c:' . $cid : ($email !== '' ? 'e:' . $email : 'o:' . $oid);

			if (!isset($order_class[$oid])) {
				$is_existing = is_array($prior_family_keys) && isset($prior_family_keys[$family]);
				// Without prior map, treat missing prior as "new" only when flag provided; else unknown.
				if ($prior_family_keys === null) {
					$is_existing = false;
					// Heuristic: mark unreliable if customer_id sparse.
				}
				$order_class[$oid] = $is_existing ? 'existing' : 'new';
				if ($is_existing) {
					$existing_orders[$oid] = true;
					$existing_families[$family] = true;
					$existing_rev += (float) $line->get('order_total', 0);
				} else {
					$new_orders[$oid] = true;
					$new_families[$family] = true;
					$new_rev += (float) $line->get('order_total', 0);
				}
			}
		}

		return [
			'reliable' => $reliable,
			'customer_id_populated_ratio' => round($populated_ratio, 2),
			'new' => [
				'orders' => count($new_orders),
				'families' => count($new_families),
				'revenue' => round($new_rev, 2),
			],
			'existing' => [
				'orders' => count($existing_orders),
				'families' => count($existing_families),
				'revenue' => round($existing_rev, 2),
			],
		];
	}

	/**
	 * @param LineItem[] $lines
	 * @return array<string,mixed>
	 */
	public function demographics(array $lines) {
		$male = 0;
		$female = 0;
		$ages = [];
		$age_3_5 = 0;
		$girls_only_bookings = 0;

		foreach ($lines as $line) {
			$g = (string) $line->get('gender', '');
			if ($g === 'male') {
				$male++;
			} elseif ($g === 'female') {
				$female++;
			}
			$age = $line->get('age');
			if (is_numeric($age)) {
				$ages[] = (float) $age;
				if ($age >= 3 && $age <= 5) {
					$age_3_5++;
				}
			}
			if ((int) $line->get('girls_only') === 1) {
				$girls_only_bookings++;
			}
		}

		$mean = count($ages) ? round(array_sum($ages) / count($ages), 2) : null;

		return [
			'gender' => ['male' => $male, 'female' => $female],
			'mean_age' => $mean,
			'aged_3_to_5' => $age_3_5,
			'total' => count($lines),
			'girls_only_bookings' => $girls_only_bookings,
		];
	}

	/**
	 * @param LineItem[]          $campaign_lines
	 * @param LineItem[]          $baseline_lines
	 * @param array<string,int>   $capacity_overrides
	 * @return array<string,mixed>
	 */
	public function occupancy(array $campaign_lines, array $baseline_lines, array $capacity_overrides) {
		$groups = [];
		foreach (['during' => $campaign_lines, 'before' => $baseline_lines] as $period => $lines) {
			foreach ($lines as $line) {
				$week = (string) $line->get('camp_week', 'not_recorded');
				$venue = (string) $line->get('venue', 'not_recorded');
				$age = (string) $line->get('age_group', 'not_recorded');
				$key = $week . '|' . $venue . '|' . $age;
				if (!isset($groups[$key])) {
					$groups[$key] = [
						'camp_week' => $week,
						'venue' => $venue,
						'age_group' => $age,
						'booked_before' => 0,
						'booked_during' => 0,
						'capacity' => null,
						'occupancy' => 'capacity not set',
					];
				}
				if ($period === 'during') {
					$groups[$key]['booked_during']++;
				} else {
					$groups[$key]['booked_before']++;
				}
			}
		}

		foreach ($groups as $key => &$row) {
			$logical = implode('|', [
				(string) ($row['camp_week']),
				(string) ($row['venue']),
				(string) ($row['age_group']),
			]);
			// Also try season|week_index|venue|age style keys from capacity_overrides.
			$cap = null;
			foreach ($capacity_overrides as $ck => $cv) {
				if ($ck === $logical || $ck === $key || strpos($ck, (string) $row['venue']) !== false && strpos($ck, (string) $row['camp_week']) !== false) {
					$cap = (int) $cv;
					break;
				}
			}
			if ($cap !== null && $cap > 0) {
				$row['capacity'] = $cap;
				$total = $row['booked_before'] + $row['booked_during'];
				$row['occupancy'] = round(100 * $total / $cap, 1);
			} else {
				$row['capacity'] = null;
				$row['occupancy'] = 'capacity not set';
			}
		}

		return array_values($groups);
	}

	/**
	 * Sales momentum: weekly series, before/during/after trough rates, daily zoom.
	 *
	 * @param CampaignDefinition $campaign
	 * @param LineItem[]         $observation_lines
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function momentum(CampaignDefinition $campaign, array $observation_lines, array $context = []) {
		$obs = $context['observation_window'] ?? $campaign->observation_window();
		$campaign_start = (string) $campaign->start_datetime;
		$campaign_end = (string) $campaign->end_datetime;
		$before_start = (string) ($obs['before_start'] ?? '');
		$after_end = (string) ($obs['after_end'] ?? '');
		$daily_start = (string) ($obs['daily_start'] ?? $before_start);
		$before_days = (int) ($obs['before_days'] ?? ($campaign->momentum_before_weeks * 7));
		$after_days = (int) ($obs['after_days'] ?? ($campaign->momentum_after_weeks * 7));

		$latest_order_at = null;
		foreach ($observation_lines as $line) {
			$ts = (string) $line->get('booking_timestamp');
			if ($ts !== '' && ($latest_order_at === null || $ts > $latest_order_at)) {
				$latest_order_at = $ts;
			}
		}
		$after_complete = $latest_order_at !== null && $latest_order_at >= $after_end;

		$weekly_map = [];
		$daily_map = [];
		$phase_buckets = [
			'before' => ['orders' => [], 'revenue' => 0.0, 'lines' => 0],
			'during' => ['orders' => [], 'revenue' => 0.0, 'lines' => 0],
			'after' => ['orders' => [], 'revenue' => 0.0, 'lines' => 0],
		];

		foreach ($observation_lines as $line) {
			$ts = (string) $line->get('booking_timestamp');
			if ($ts === '') {
				continue;
			}
			$oid = (int) $line->get('order_id');
			$order_rev = (float) $line->get('order_total', 0);
			$used_coupon = (bool) $line->get('used_campaign_coupon');

			$phase = 'during';
			if ($ts < $campaign_start) {
				$phase = 'before';
			} elseif ($ts > $campaign_end) {
				$phase = 'after';
			}

			// Phase trough uses configured before window start (not daily pad).
			$in_before_phase = ($ts >= $before_start && $ts < $campaign_start);
			$in_during_phase = ($ts >= $campaign_start && $ts <= $campaign_end);
			$in_after_phase = ($ts > $campaign_end && $ts <= $after_end);

			if ($in_before_phase || $in_during_phase || $in_after_phase) {
				$pkey = $in_before_phase ? 'before' : ($in_during_phase ? 'during' : 'after');
				$phase_buckets[$pkey]['lines']++;
				if (!isset($phase_buckets[$pkey]['orders'][$oid])) {
					$phase_buckets[$pkey]['orders'][$oid] = true;
					$phase_buckets[$pkey]['revenue'] += $order_rev;
				}
			}

			// Weekly series across full observation fetch.
			try {
				$dt = new \DateTimeImmutable($ts);
				$iso_week = (int) $dt->format('oW');
				// ISO week start (Monday).
				$week_start = $dt->modify('monday this week')->format('Y-m-d');
				$day = $dt->format('Y-m-d');
				$day_name = $dt->format('l');
			} catch (\Exception $e) {
				continue;
			}

			if (!isset($weekly_map[$iso_week])) {
				$weekly_map[$iso_week] = [
					'iso_week' => $iso_week,
					'week_start' => $week_start,
					'orders' => [],
					'coupon_orders' => [],
					'line_item_bookings' => 0,
					'revenue_order_totals' => 0.0,
					'coupon_revenue_order_totals' => 0.0,
				];
			}
			$weekly_map[$iso_week]['line_item_bookings']++;
			if (!isset($weekly_map[$iso_week]['orders'][$oid])) {
				$weekly_map[$iso_week]['orders'][$oid] = true;
				$weekly_map[$iso_week]['revenue_order_totals'] += $order_rev;
			}
			if ($used_coupon && !isset($weekly_map[$iso_week]['coupon_orders'][$oid])) {
				$weekly_map[$iso_week]['coupon_orders'][$oid] = true;
				$weekly_map[$iso_week]['coupon_revenue_order_totals'] += $order_rev;
			}

			if ($ts >= $daily_start && $ts <= $after_end) {
				if (!isset($daily_map[$day])) {
					$daily_map[$day] = [
						'day' => $day,
						'day_name' => $day_name,
						'phase' => $phase,
						'orders' => [],
						'coupon_orders' => [],
						'revenue_order_totals' => 0.0,
						'coupon_revenue_order_totals' => 0.0,
					];
				}
				// If a calendar day spans during→after (e.g. expiry mid-day), keep first phase
				// for the day key only when all orders share a phase; otherwise emit split keys.
				$phase_day_key = $day . '|' . $phase;
				if (!isset($daily_map[$phase_day_key])) {
					$daily_map[$phase_day_key] = [
						'day' => $day,
						'day_name' => $day_name,
						'phase' => $phase,
						'orders' => [],
						'coupon_orders' => [],
						'revenue_order_totals' => 0.0,
						'coupon_revenue_order_totals' => 0.0,
					];
				}
				if (!isset($daily_map[$phase_day_key]['orders'][$oid])) {
					$daily_map[$phase_day_key]['orders'][$oid] = true;
					$daily_map[$phase_day_key]['revenue_order_totals'] += $order_rev;
				}
				if ($used_coupon && !isset($daily_map[$phase_day_key]['coupon_orders'][$oid])) {
					$daily_map[$phase_day_key]['coupon_orders'][$oid] = true;
					$daily_map[$phase_day_key]['coupon_revenue_order_totals'] += $order_rev;
				}
			}
		}

		// Drop non-split day stubs (keys without |).
		foreach (array_keys($daily_map) as $k) {
			if (strpos((string) $k, '|') === false) {
				unset($daily_map[$k]);
			}
		}

		$weekly = [];
		foreach ($weekly_map as $row) {
			$weekly[] = [
				'iso_week' => $row['iso_week'],
				'week_start' => $row['week_start'],
				'orders' => count($row['orders']),
				'coupon_orders' => count($row['coupon_orders']),
				'line_item_bookings' => $row['line_item_bookings'],
				'revenue_order_totals' => round($row['revenue_order_totals'], 2),
				'coupon_revenue_order_totals' => round($row['coupon_revenue_order_totals'], 2),
			];
		}
		usort($weekly, static function ($a, $b) {
			return strcmp((string) $a['week_start'], (string) $b['week_start']);
		});

		$during_seconds = CampaignTimezone::parse_local($campaign_end)->getTimestamp()
			- CampaignTimezone::parse_local($campaign_start)->getTimestamp();
		$during_days_for_rate = max(1, (int) round($during_seconds / 86400.0));

		$phase_defs = [
			'before' => ['label' => 'BEFORE (' . (int) ($obs['before_weeks'] ?? 4) . ' wk)', 'days' => $before_days],
			'during' => ['label' => 'DURING (promo)', 'days' => $during_days_for_rate],
			'after' => ['label' => 'AFTER (' . (int) ($obs['after_weeks'] ?? 2) . ' wk)', 'days' => $after_days],
		];

		$phases = [];
		$rates = [];
		foreach ($phase_defs as $id => $def) {
			$orders = count($phase_buckets[$id]['orders']);
			$rev = round($phase_buckets[$id]['revenue'], 2);
			$days = max(1, (int) $def['days']);
			$orders_eq = round($orders / ($days / 7.0), 1);
			$rev_eq = round($rev / ($days / 7.0), 2);
			$rates[$id] = $orders_eq;
			$phases[] = [
				'id' => $id,
				'label' => $def['label'],
				'days' => $days,
				'orders' => $orders,
				'revenue_order_totals' => $rev,
				'orders_per_week_equiv' => $orders_eq,
				'revenue_per_week_equiv' => $rev_eq,
			];
		}

		$notes = [
			'Rising after-weeks in peak booking season may be seasonal, not promo-driven.',
			'Phase rates use total demand (all orders); coupon_orders overlay is campaign codes only.',
		];
		$ratio = null;
		$verdict = 'inconclusive';
		if (!$after_complete) {
			$verdict = 'insufficient_after';
			$notes[] = 'AFTER window is incomplete relative to configured after_weeks; trough verdict is provisional.';
		} elseif ($rates['before'] > 0) {
			$ratio = round($rates['after'] / $rates['before'], 3);
			$verdict = ($rates['after'] < $rates['before']) ? 'shifting' : 'generating';
		} elseif ($rates['after'] > 0) {
			$verdict = 'generating';
			$ratio = null;
		}

		$daily = [];
		foreach ($daily_map as $row) {
			$daily[] = [
				'day' => $row['day'],
				'day_name' => $row['day_name'],
				'phase' => $row['phase'],
				'orders' => count($row['orders']),
				'coupon_orders' => count($row['coupon_orders']),
				'revenue_order_totals' => round($row['revenue_order_totals'], 2),
				'coupon_revenue_order_totals' => round($row['coupon_revenue_order_totals'], 2),
			];
		}
		usort($daily, static function ($a, $b) {
			$c = strcmp((string) $a['day'], (string) $b['day']);
			if ($c !== 0) {
				return $c;
			}
			$order = ['before' => 0, 'during' => 1, 'after' => 2];
			return ($order[$a['phase']] ?? 9) <=> ($order[$b['phase']] ?? 9);
		});

		return [
			'observation' => [
				'start' => (string) ($obs['start'] ?? ''),
				'end' => $after_end,
				'before_start' => $before_start,
				'after_end' => $after_end,
				'daily_start' => $daily_start,
				'before_weeks' => (int) ($obs['before_weeks'] ?? $campaign->momentum_before_weeks),
				'after_weeks' => (int) ($obs['after_weeks'] ?? $campaign->momentum_after_weeks),
				'latest_order_at' => $latest_order_at,
				'after_complete' => $after_complete,
			],
			'weekly' => $weekly,
			'phases' => $phases,
			'trough' => [
				'verdict' => $verdict,
				'after_vs_before_orders_ratio' => $ratio,
				'notes' => $notes,
			],
			'daily' => $daily,
		];
	}

	/**
	 * @param LineItem[] $lines
	 * @return array{orders:int,lines:int,order_revenue:float,line_revenue:float}
	 */
	private function period_totals(array $lines) {
		$orders = [];
		$order_revenue = 0.0;
		$line_revenue = 0.0;
		foreach ($lines as $line) {
			$oid = (int) $line->get('order_id');
			$line_revenue += (float) $line->get('line_total', 0);
			if (!isset($orders[$oid])) {
				$orders[$oid] = true;
				$order_revenue += (float) $line->get('order_total', 0);
			}
		}
		return [
			'orders' => count($orders),
			'lines' => count($lines),
			'order_revenue' => round($order_revenue, 2),
			'line_revenue' => round($line_revenue, 2),
		];
	}

	/**
	 * Split unique orders by used_campaign_coupon (campaign-configured codes only).
	 *
	 * @param LineItem[] $lines
	 * @return array{
	 *   coded_orders:int,
	 *   coded_revenue_order_totals:float,
	 *   uncoded_orders:int,
	 *   uncoded_revenue_order_totals:float
	 * }
	 */
	private function coded_uncoded_order_totals(array $lines) {
		$coded = [];
		$uncoded = [];
		$coded_rev = 0.0;
		$uncoded_rev = 0.0;

		foreach ($lines as $line) {
			$oid = (int) $line->get('order_id');
			if (isset($coded[$oid]) || isset($uncoded[$oid])) {
				continue;
			}
			$rev = (float) $line->get('order_total', 0);
			if ((bool) $line->get('used_campaign_coupon')) {
				$coded[$oid] = true;
				$coded_rev += $rev;
			} else {
				$uncoded[$oid] = true;
				$uncoded_rev += $rev;
			}
		}

		return [
			'coded_orders' => count($coded),
			'coded_revenue_order_totals' => round($coded_rev, 2),
			'uncoded_orders' => count($uncoded),
			'uncoded_revenue_order_totals' => round($uncoded_rev, 2),
		];
	}

	/**
	 * @param LineItem[] $lines
	 * @param string[]   $codes
	 * @return float
	 */
	private function unique_order_coupon_discount(array $lines, array $codes) {
		$codes_u = array_map('strtoupper', $codes);
		$seen = [];
		$total = 0.0;
		foreach ($lines as $line) {
			$oid = (int) $line->get('order_id');
			if (isset($seen[$oid])) {
				continue;
			}
			$on = array_map('strtoupper', (array) $line->get('coupon_codes', []));
			$hit = empty($codes_u) ? !empty($on) : (bool) array_intersect($on, $codes_u);
			if ($hit) {
				$seen[$oid] = true;
				$total += (float) $line->get('coupon_discount_order', 0);
			}
		}
		return $total;
	}

	/**
	 * @param LineItem[] $lines
	 * @param string     $field
	 * @return float
	 */
	private function sum_field(array $lines, $field) {
		$sum = 0.0;
		foreach ($lines as $line) {
			$sum += (float) $line->get($field, 0);
		}
		return $sum;
	}

	/**
	 * @param float|int $current
	 * @param float|int $baseline
	 * @return int|null
	 */
	private function pct($current, $baseline) {
		if ((float) $baseline == 0.0) {
			return null;
		}
		return (int) round(100 * (((float) $current - (float) $baseline) / (float) $baseline));
	}

	/**
	 * @param CampaignDefinition $campaign
	 * @param LineItem[]         $lines
	 * @param string             $source_id
	 * @param string[]           $warnings
	 * @param string[]           $errors
	 * @return array<int,array{topic:string,detail:string}>
	 */
	private function data_notes(CampaignDefinition $campaign, array $lines, $source_id, array $warnings, array $errors) {
		$missing_age = 0;
		$missing_region = 0;
		foreach ($lines as $line) {
			if ((string) $line->get('age_group', 'not_recorded') === 'not_recorded') {
				$missing_age++;
			}
			if ((string) $line->get('region', 'not_recorded') === 'not_recorded') {
				$missing_region++;
			}
		}

		$notes = [
			['topic' => 'Source', 'detail' => 'Booking time, status, and revenue from ' . $source_id . ' path (orders preferred).'],
			['topic' => 'Revenue basis', 'detail' => 'Default “revenue” means order totals; line totals shown separately.'],
			['topic' => 'Order statuses', 'detail' => implode(', ', $campaign->order_statuses)],
			['topic' => 'Discounts', 'detail' => 'Coupon discount reported separately from sibling/combo (PV price adjustments).'],
			['topic' => 'Attribution', 'detail' => self::attribution_limitation_copy()],
			['topic' => 'Sales momentum', 'detail' => 'Before/during/after rates answer generate vs shift. Incomplete after windows are provisional. Peak season can raise after-weeks without the promo generating demand.'],
			['topic' => 'Missing age group', 'detail' => $missing_age . ' line items'],
			['topic' => 'Missing region', 'detail' => $missing_region . ' line items'],
		];
		foreach ($warnings as $w) {
			$notes[] = ['topic' => 'Warning', 'detail' => $w];
		}
		foreach ($errors as $e) {
			$notes[] = ['topic' => 'Error', 'detail' => $e];
		}
		return $notes;
	}
}
