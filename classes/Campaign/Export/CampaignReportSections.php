<?php
/**
 * Shared section content for Campaign Word/HTML exports (SWISS15 house structure).
 *
 * @package InterSoccer\ReportsRosters\Campaign\Export
 */

namespace InterSoccer\ReportsRosters\Campaign\Export;

defined('ABSPATH') or die('Restricted access');

class CampaignReportSections {

	const NAVY = '1F4E5F';
	const GREY = '595959';
	const RED = '9C2A2A';
	const BUSINESS_AGE_FOOTNOTE = 'InterSoccer has traded 12+ years; comparisons are limited by booking-system data coverage since May 2025, not business age.';

	/**
	 * @param array<string,mixed> $payload
	 * @return bool
	 */
	public static function is_gate_blocked(array $payload) {
		$gate = (array) ($payload['gate'] ?? []);
		if (array_key_exists('ok', $gate) && !$gate['ok']) {
			return true;
		}
		return !empty($payload['_export_stub']);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return string[]
	 */
	public static function blocked_reasons(array $payload) {
		$gate = (array) ($payload['gate'] ?? []);
		$reasons = array_merge(
			(array) ($gate['errors'] ?? []),
			(array) ($payload['errors'] ?? [])
		);
		$out = [];
		foreach ($reasons as $r) {
			$r = trim((string) $r);
			if ($r !== '') {
				$out[] = $r;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @return string
	 */
	public static function attribution_limitation(array $payload) {
		$lim = (string) ($payload['attribution_limitation'] ?? '');
		if ($lim !== '') {
			return $lim;
		}
		$attr = (array) ($payload['attribution'] ?? []);
		return (string) ($attr['limitation'] ?? '');
	}

	/**
	 * Build full report model for Docx/HTML renderers.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public static function build(array $payload) {
		if (self::is_gate_blocked($payload)) {
			$campaign = (array) ($payload['campaign'] ?? []);
			return [
				'stub' => true,
				'title' => ((string) ($campaign['name'] ?? 'Campaign')) . ' — cannot produce report',
				'subtitle' => 'Data quality gate blocked this export',
				'reasons' => self::blocked_reasons($payload),
				'business_age' => self::BUSINESS_AGE_FOOTNOTE,
			];
		}

		$campaign = (array) ($payload['campaign'] ?? []);
		$baseline = (array) ($payload['baseline_window'] ?? []);
		$headline = (array) ($payload['headline'] ?? []);
		$volume = (array) ($payload['volume_value'] ?? []);
		$timing = (array) ($payload['timing'] ?? []);
		$mix = (array) ($payload['mix'] ?? []);
		$demand = (array) ($payload['demand'] ?? []);
		$regions = (array) ($payload['regions'] ?? []);
		$cohorts = (array) ($payload['cohorts'] ?? []);
		$coupons = (array) ($payload['coupons'] ?? []);
		$attribution = (array) ($payload['attribution'] ?? []);
		$warnings = array_values(array_filter(array_map('strval', (array) ($payload['warnings'] ?? []))));
		$errors = array_values(array_filter(array_map('strval', (array) ($payload['errors'] ?? []))));
		$data_notes = (array) ($payload['data_notes'] ?? []);

		$name = (string) ($campaign['name'] ?? 'Campaign');
		$start = (string) ($campaign['start'] ?? '');
		$end = (string) ($campaign['end'] ?? '');
		$window_human = self::format_window_human($start, $end);
		$baseline_label = (string) ($baseline['label'] ?? 'matched previous period');
		$revenue_basis = (string) ($campaign['revenue_basis'] ?? 'order totals');
		$prepared = function_exists('current_time') ? current_time('j F Y') : gmdate('j F Y');

		$orders = (int) ($headline['orders'] ?? 0);
		$bookings = (int) ($headline['line_item_bookings'] ?? 0);
		$revenue = (float) ($headline['revenue_order_totals'] ?? 0);
		$aov = (float) ($headline['avg_order_value'] ?? 0);
		$pct = (array) ($headline['pct_change'] ?? []);
		$base = (array) ($headline['baseline'] ?? []);

		$peak = (array) ($timing['peak_day'] ?? []);
		$peak_name = (string) ($peak['day_name'] ?? '');
		$top_season = self::top_season_row($demand, $payload);
		$season_label = (string) ($top_season['label'] ?? '');

		$timing_title = 'The timing';
		if ($peak_name !== '') {
			$timing_title = 'The timing — a ' . $peak_name . ' finale';
		}
		$demand_title = 'Where the demand went';
		if ($season_label !== '') {
			$demand_title = 'Where the demand went — ' . $season_label;
		}

		$region_sum = 0;
		foreach ($regions as $row) {
			$region_sum += (int) ($row['bookings'] ?? 0);
		}
		$regions_reconcile = ($bookings === 0) || ($region_sum === $bookings);
		$region_warning = '';
		if (!$regions_reconcile) {
			$region_warning = in_array('region_breakdown_does_not_reconcile', $errors, true)
				? 'Region bookings (' . $region_sum . ') do not reconcile to headline line-item bookings (' . $bookings . ').'
				: 'Region bookings (' . $region_sum . ') do not equal headline bookings (' . $bookings . ').';
			foreach ($warnings as $w) {
				if (stripos($w, 'region') !== false) {
					$region_warning .= ' ' . $w;
					break;
				}
			}
		}

		$window_note = self::window_note_paragraph($data_notes, $campaign, $timing);
		$coded_orders = (int) ($headline['coded_orders'] ?? 0);
		$coded_pct = $orders > 0 ? (int) round(100 * $coded_orders / $orders) : 0;

		$headline_rows = [
			['Measure', 'Promotion', 'Baseline', 'Change'],
			[
				'Orders',
				(string) $orders,
				isset($base['orders']) ? (string) (int) $base['orders'] : '—',
				self::pct_label($pct['orders'] ?? null),
			],
			[
				'Individual child bookings',
				(string) $bookings,
				isset($base['line_item_bookings']) ? (string) (int) $base['line_item_bookings'] : '—',
				self::pct_label($pct['line_item_bookings'] ?? null),
			],
			[
				'Revenue',
				self::chf($revenue),
				isset($base['revenue_order_totals']) ? self::chf((float) $base['revenue_order_totals']) : '—',
				self::pct_label($pct['revenue_order_totals'] ?? null),
			],
			[
				'Average order value',
				self::chf($aov),
				isset($base['avg_order_value']) ? self::chf((float) $base['avg_order_value']) : '—',
				self::pct_label($pct['avg_order_value'] ?? null),
			],
		];
		if (!empty($coupons) || $coded_orders > 0) {
			$headline_rows[] = [
				'Orders using a code',
				$coded_orders . ($coded_pct ? ' (' . $coded_pct . '%)' : ''),
				'—',
				'—',
			];
		}

		$day_table = self::timing_table($timing);
		$season_table = self::season_table($demand, $payload);
		$mix_table = self::mix_table($mix);
		$region_table = self::region_table($regions, $bookings, $headline);
		$cohort_table = self::cohort_table($cohorts);
		$cohort_label = (string) ($cohorts['cohort_label'] ?? 'first booking in our system');

		$slug = function_exists('sanitize_title')
			? sanitize_title($name ?: 'campaign')
			: preg_replace('/[^a-z0-9]+/i', '-', strtolower($name ?: 'campaign'));

		return [
			'stub' => false,
			'title' => $name . ' — Booking Performance Report',
			'subtitle' => $name . ' · promotion period ' . $window_human,
			'provenance' => sprintf(
				'Promotion period %s, compared against %s. Prepared %s from the booking system. Revenue basis: %s.',
				$window_human,
				$baseline_label,
				$prepared,
				$revenue_basis === 'order_totals' || $revenue_basis === '' ? 'order totals' : $revenue_basis
			),
			'window_note' => $window_note,
			'exec_summary' => self::exec_summary_paragraphs($headline, $volume, $top_season, $warnings, $name),
			'headline_rows' => $headline_rows,
			'volume_prose' => self::volume_prose($headline, $volume),
			'line_order_aside' => self::line_vs_order_aside($headline),
			'timing_title' => $timing_title,
			'day_table' => $day_table,
			'timing_prose' => self::timing_prose($timing, $headline),
			'demand_title' => $demand_title,
			'season_table' => $season_table,
			'demand_prose' => self::demand_prose($top_season, $season_table, $bookings),
			'mix_table' => $mix_table,
			'mix_prose' => self::mix_prose($mix_table, $bookings),
			'region_table' => $region_table,
			'region_warning' => $region_warning,
			'region_prose' => self::region_prose($regions, $bookings),
			'cohort_label' => $cohort_label,
			'cohort_table' => $cohort_table,
			'cohort_prose' => self::cohort_prose($cohorts, $cohort_label),
			'codes_prose' => self::codes_prose($coupons, $headline),
			'attribution_prose' => self::attribution_prose($attribution),
			'attribution_limitation' => self::attribution_limitation($payload),
			'recommendations' => self::recommendations($timing, $top_season, $volume, $coupons),
			'gaps' => self::gaps($data_notes, $warnings),
			'footnote' => [
				'Accompanying file: campaign-' . $slug . '.xlsx — every booking in the promotion window with date, time, derived age, gender, activity, venue, region and whether a campaign code was used. No names, dates of birth, contact or medical details.',
				'Revenue is the sum of order totals unless otherwise labelled.',
				self::BUSINESS_AGE_FOOTNOTE,
			],
			'warnings' => $warnings,
		];
	}

	/**
	 * @param array<int,array{topic?:string,detail?:string}|string> $notes
	 * @param array<string,mixed> $campaign
	 * @param array<string,mixed> $timing
	 * @return string
	 */
	private static function window_note_paragraph(array $notes, array $campaign, array $timing) {
		foreach ($notes as $note) {
			if (is_array($note)) {
				$topic = strtolower((string) ($note['topic'] ?? ''));
				$detail = (string) ($note['detail'] ?? '');
				if ($detail !== '' && (strpos($topic, 'window') !== false || preg_match('/\b(window|cutoff|expir|partial|complete)\b/i', $detail))) {
					return $detail;
				}
			} elseif (is_string($note) && preg_match('/\b(window|cutoff|expir|partial)\b/i', $note)) {
				return $note;
			}
		}
		$deadline = trim((string) ($timing['deadline_note'] ?? ''));
		if ($deadline !== '') {
			$end = (string) ($campaign['end'] ?? '');
			return 'Campaign window ends ' . $end . '. ' . $deadline;
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $headline
	 * @param array<string,mixed> $volume
	 * @param array<string,mixed> $top_season
	 * @param string[]            $warnings
	 * @param string              $name
	 * @return array<int,array{text:string,style:string}>
	 */
	private static function exec_summary_paragraphs(array $headline, array $volume, array $top_season, array $warnings, $name) {
		$orders = (int) ($headline['orders'] ?? 0);
		$bookings = (int) ($headline['line_item_bookings'] ?? 0);
		$revenue = (float) ($headline['revenue_order_totals'] ?? 0);
		$aov = (float) ($headline['avg_order_value'] ?? 0);
		$pct_orders = $headline['pct_change']['orders'] ?? null;
		$pct_rev = $headline['pct_change']['revenue_order_totals'] ?? null;

		$paras = [];
		$paras[] = [
			'text' => sprintf(
				'%s brought in %d orders and %d individual child bookings worth %s (average order value %s).',
				$name,
				$orders,
				$bookings,
				self::chf($revenue),
				self::chf($aov)
			),
			'style' => 'body',
		];

		$season = (string) ($top_season['label'] ?? '');
		$key = $season !== ''
			? sprintf('It sold into %s — the largest demand destination in this window — while protecting higher-value commitments.', $season)
			: 'Families booked higher-value commitments; average order value is the volume/value story to watch.';
		$paras[] = ['text' => $key, 'style' => 'key'];

		$change = '';
		if ($pct_orders !== null && $pct_orders !== '') {
			$change = sprintf(
				'Orders moved %+d%% versus the baseline period',
				(int) $pct_orders
			);
			if ($pct_rev !== null && $pct_rev !== '') {
				$change .= sprintf(', while revenue moved %+d%%', (int) $pct_rev);
			}
			$change .= '.';
			$paras[] = ['text' => $change, 'style' => empty($warnings) ? 'body' : 'caveat'];
		} elseif (!empty($warnings)) {
			$paras[] = [
				'text' => 'Read figures with care: ' . implode('; ', array_slice($warnings, 0, 3)) . '.',
				'style' => 'caveat',
			];
		}

		return $paras;
	}

	/**
	 * @param array<string,mixed> $headline
	 * @param array<string,mixed> $volume
	 * @return string
	 */
	private static function volume_prose(array $headline, array $volume) {
		$pct_o = $headline['pct_change']['orders'] ?? null;
		$pct_r = $headline['pct_change']['revenue_order_totals'] ?? null;
		$pct_a = $headline['pct_change']['avg_order_value'] ?? null;
		$aov = (float) ($volume['avg_order_value'] ?? $headline['avg_order_value'] ?? 0);
		$aov_add = (float) ($volume['avg_order_value_coupon_added_back'] ?? 0);
		$parts = [];
		if ($pct_o !== null && $pct_r !== null) {
			$parts[] = sprintf(
				'Orders grew %+d%% while revenue grew %+d%%',
				(int) $pct_o,
				(int) $pct_r
			);
			if ($pct_a !== null && $pct_a !== '') {
				$parts[] = sprintf('because average order value moved %+d%% (to %s)', (int) $pct_a, self::chf($aov));
			}
		}
		if ($aov_add > 0 && abs($aov_add - $aov) > 0.01) {
			$parts[] = sprintf(
				'with coupon discount added back, AOV is %s (%+d%% vs baseline)',
				self::chf($aov_add),
				(int) ($volume['aov_vs_baseline_after_add_back_pct'] ?? 0)
			);
		}
		return $parts ? (implode(', ', $parts) . '.') : '';
	}

	/**
	 * @param array<string,mixed> $headline
	 * @return string
	 */
	private static function line_vs_order_aside(array $headline) {
		$order_rev = (float) ($headline['revenue_order_totals'] ?? 0);
		$line_rev = (float) ($headline['revenue_line_totals'] ?? 0);
		if ($line_rev <= 0 || abs($order_rev - $line_rev) < 0.05) {
			return '';
		}
		return sprintf(
			'Day-level revenue summed from line totals is %s; headline revenue uses order totals (%s). Small differences are expected when multi-line orders span the window.',
			self::chf($line_rev),
			self::chf($order_rev)
		);
	}

	/**
	 * @param array<string,mixed> $timing
	 * @return array{headers:string[],rows:array<int,string[]>}
	 */
	private static function timing_table(array $timing) {
		$headers = ['Day', 'Orders', 'Bookings', 'Using code', 'Revenue (CHF)'];
		$rows = [];
		$tot_o = $tot_b = $tot_c = 0;
		$tot_r = 0.0;
		foreach ((array) ($timing['by_day'] ?? []) as $date => $row) {
			$row = (array) $row;
			$day_label = trim((string) ($row['day_name'] ?? '') . ' ' . $date);
			$o = (int) ($row['orders'] ?? 0);
			$b = (int) ($row['line_items'] ?? 0);
			$c = (int) ($row['coupon_orders'] ?? 0);
			$r = (float) ($row['order_revenue'] ?? $row['line_revenue'] ?? 0);
			$tot_o += $o;
			$tot_b += $b;
			$tot_c += $c;
			$tot_r += $r;
			$rows[] = [$day_label, (string) $o, (string) $b, (string) $c, self::num($r)];
		}
		if ($rows) {
			$rows[] = ['TOTAL', (string) $tot_o, (string) $tot_b, (string) $tot_c, self::num($tot_r)];
		}
		return ['headers' => $headers, 'rows' => $rows];
	}

	/**
	 * @param array<string,mixed> $timing
	 * @param array<string,mixed> $headline
	 * @return array<int,array{text:string,style:string}>
	 */
	private static function timing_prose(array $timing, array $headline) {
		$out = [];
		$peak = (array) ($timing['peak_day'] ?? []);
		$bookings = (int) ($headline['line_item_bookings'] ?? 0);
		if (!empty($peak['date'])) {
			$share = $bookings > 0 ? (int) round(100 * (int) $peak['line_items'] / $bookings) : 0;
			$text = sprintf(
				'%s %s was the peak day with %d bookings (%d%% of the period)',
				(string) ($peak['day_name'] ?? ''),
				(string) $peak['date'],
				(int) ($peak['line_items'] ?? 0),
				$share
			);
			$deadline = trim((string) ($timing['deadline_note'] ?? ''));
			if ($deadline !== '') {
				$text .= ' — ' . $deadline;
			}
			$text .= '.';
			$out[] = ['text' => $text, 'style' => 'key'];
		}
		$aside = self::line_vs_order_aside($headline);
		if ($aside !== '') {
			$out[] = ['text' => $aside, 'style' => 'aside'];
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $demand
	 * @param array<string,mixed> $payload
	 * @return array{label:string,bookings:int,revenue:float}
	 */
	private static function top_season_row(array $demand, array $payload) {
		$table = self::season_table($demand, $payload);
		$best = ['label' => '', 'bookings' => 0, 'revenue' => 0.0];
		foreach ($table['rows'] as $row) {
			if (($row[0] ?? '') === 'TOTAL') {
				continue;
			}
			$b = (int) ($row[1] ?? 0);
			if ($b > $best['bookings']) {
				$best = [
					'label' => (string) $row[0],
					'bookings' => $b,
					'revenue' => (float) str_replace([',', ' '], '', (string) ($row[2] ?? '0')),
				];
			}
		}
		return $best;
	}

	/**
	 * Group demand by season for Word table.
	 *
	 * @param array<string,mixed> $demand
	 * @param array<string,mixed> $payload
	 * @return array{headers:string[],rows:array<int,string[]>}
	 */
	private static function season_table(array $demand, array $payload) {
		$headers = ['Season booked', 'Bookings', 'Revenue (CHF)'];
		$buckets = [];
		foreach ((array) ($demand['by_week'] ?? []) as $key => $row) {
			$row = (array) $row;
			$label = self::season_from_week_key((string) ($row['key'] ?? $key), (string) ($row['label'] ?? $key));
			if (!isset($buckets[$label])) {
				$buckets[$label] = ['bookings' => 0, 'revenue' => 0.0];
			}
			$buckets[$label]['bookings'] += (int) ($row['bookings'] ?? 0);
			$buckets[$label]['revenue'] += (float) ($row['revenue'] ?? 0);
		}
		// Prefer child_rows season counts when present (house Excel path).
		$child = (array) ($payload['child_rows'] ?? []);
		if ($child) {
			$from_child = [];
			foreach ($child as $r) {
				$r = (array) $r;
				$label = self::display_season((string) ($r['season'] ?? ''));
				if (!isset($from_child[$label])) {
					$from_child[$label] = ['bookings' => 0, 'revenue' => 0.0];
				}
				$from_child[$label]['bookings']++;
			}
			if ($from_child) {
				// Keep revenue from week buckets when available; otherwise bookings-only from child.
				foreach ($from_child as $label => $meta) {
					if (!isset($buckets[$label])) {
						$buckets[$label] = ['bookings' => $meta['bookings'], 'revenue' => 0.0];
					} else {
						$buckets[$label]['bookings'] = $meta['bookings'];
					}
				}
			}
		}
		uasort($buckets, static function ($a, $b) {
			return $b['bookings'] <=> $a['bookings'];
		});
		$rows = [];
		$tb = 0;
		$tr = 0.0;
		foreach ($buckets as $label => $meta) {
			$tb += $meta['bookings'];
			$tr += $meta['revenue'];
			$rows[] = [$label, (string) $meta['bookings'], self::num($meta['revenue'])];
		}
		if ($rows) {
			$rows[] = ['TOTAL', (string) $tb, self::num($tr)];
		}
		return ['headers' => $headers, 'rows' => $rows];
	}

	/**
	 * @param string $key
	 * @param string $fallback
	 * @return string
	 */
	private static function season_from_week_key($key, $fallback) {
		if (preg_match('/^(summer|autumn|winter|easter)/i', $key, $m)) {
			return ucfirst(strtolower($m[1]));
		}
		if ($key === 'not_recorded' || $key === '') {
			return 'Not recorded';
		}
		return $fallback !== '' ? $fallback : $key;
	}

	/**
	 * @param string $raw
	 * @return string
	 */
	public static function display_season($raw) {
		$raw = trim($raw);
		if ($raw === '' || $raw === 'not_recorded') {
			return '(not recorded)';
		}
		return $raw;
	}

	/**
	 * @param array<string,mixed> $top
	 * @param array{headers:string[],rows:array<int,string[]>} $table
	 * @param int $bookings
	 * @return array<int,array{text:string,style:string}>
	 */
	private static function demand_prose(array $top, array $table, $bookings) {
		$out = [];
		if (($top['bookings'] ?? 0) > 0 && $bookings > 0) {
			$share = (int) round(100 * $top['bookings'] / $bookings);
			$out[] = [
				'text' => sprintf(
					'%s was %d%% of bookings (%d) — the planning-relevant destination for this campaign.',
					$top['label'],
					$share,
					$top['bookings']
				),
				'style' => 'key',
			];
		}
		$out[] = [
			'text' => 'Camp-week detail can be unreliable when FR/EN season labels are not fully merged; treat week splits as directional.',
			'style' => 'aside',
		];
		return $out;
	}

	/**
	 * @param array<string,mixed> $mix
	 * @return array{headers:string[],rows:array<int,string[]>}
	 */
	private static function mix_table(array $mix) {
		$headers = ['Type', 'Bookings', 'Revenue (CHF)'];
		$rows = [];
		$bt = (array) ($mix['booking_type'] ?? []);
		$activity = (array) ($mix['activity'] ?? []);
		// Prefer booking-type rollup (house “What was booked”); fall back to activity.
		$source = $bt ?: $activity;
		uasort($source, static function ($a, $b) {
			return ((int) ($b['bookings'] ?? 0)) <=> ((int) ($a['bookings'] ?? 0));
		});
		foreach ($source as $key => $row) {
			$rows[] = [
				self::display_booking_type((string) $key),
				(string) (int) ($row['bookings'] ?? 0),
				self::num((float) ($row['revenue'] ?? 0)),
			];
		}
		return ['headers' => $headers, 'rows' => $rows];
	}

	/**
	 * @param string $key
	 * @return string
	 */
	public static function display_booking_type($key) {
		$map = [
			'full-week' => 'Full Week',
			'full-term' => 'Full Term',
			'single-days' => 'Single Day(s)',
			'single-day' => 'Single Day(s)',
			'camp' => 'Camps',
			'course' => 'Courses',
			'girls_only' => "Girls' Only",
			'other' => '(not recorded)',
			'not_recorded' => '(not recorded)',
			'' => '(not recorded)',
		];
		return $map[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key));
	}

	/**
	 * @param array{headers:string[],rows:array<int,string[]>} $table
	 * @param int $bookings
	 * @return string
	 */
	private static function mix_prose(array $table, $bookings) {
		if (empty($table['rows']) || $bookings <= 0) {
			return '';
		}
		$top = $table['rows'][0];
		return sprintf(
			'%s led with %s of %d bookings.',
			$top[0],
			$top[1],
			$bookings
		);
	}

	/**
	 * @param array<string,array> $regions
	 * @param int                 $bookings
	 * @param array<string,mixed> $headline
	 * @return array{headers:string[],rows:array<int,string[]>}
	 */
	private static function region_table(array $regions, $bookings, array $headline) {
		$headers = ['Region', 'Bookings', 'Revenue (CHF)'];
		$rows = [];
		$sorted = $regions;
		uasort($sorted, static function ($a, $b) {
			return ((int) ($b['bookings'] ?? 0)) <=> ((int) ($a['bookings'] ?? 0));
		});
		$sum_b = 0;
		$sum_r = 0.0;
		foreach ($sorted as $name => $row) {
			$b = (int) ($row['bookings'] ?? 0);
			$r = (float) ($row['revenue'] ?? 0);
			$sum_b += $b;
			$sum_r += $r;
			$label = ($name === '' || $name === 'not_recorded') ? 'Not recorded' : (string) $name;
			$rows[] = [$label, (string) $b, self::num($r)];
		}
		if ($rows) {
			$rows[] = ['TOTAL', (string) $sum_b, self::num($sum_r)];
		}
		return ['headers' => $headers, 'rows' => $rows];
	}

	/**
	 * @param array<string,array> $regions
	 * @param int                 $bookings
	 * @return string
	 */
	private static function region_prose(array $regions, $bookings) {
		if (!$regions || $bookings <= 0) {
			return '';
		}
		$sorted = $regions;
		uasort($sorted, static function ($a, $b) {
			return ((int) ($b['bookings'] ?? 0)) <=> ((int) ($a['bookings'] ?? 0));
		});
		$top_name = array_key_first($sorted);
		$top = $sorted[$top_name];
		$share = (int) round(100 * (int) $top['bookings'] / $bookings);
		$label = ($top_name === 'not_recorded') ? 'Not recorded' : (string) $top_name;
		return sprintf('%s led with %d bookings (%d%% of the period).', $label, (int) $top['bookings'], $share);
	}

	/**
	 * @param array<string,mixed> $cohorts
	 * @return array{headers:string[],rows:array<int,string[]>}
	 */
	private static function cohort_table(array $cohorts) {
		$headers = ['Category', 'Orders', 'Families', 'Revenue (CHF)'];
		$label = (string) ($cohorts['cohort_label'] ?? 'first booking in our system');
		$new_label = $label === 'first booking in our system'
			? 'First booking in our system'
			: 'New';
		$existing_label = 'Existing / returning';
		$new = (array) ($cohorts['new'] ?? []);
		$existing = (array) ($cohorts['existing'] ?? []);
		return [
			'headers' => $headers,
			'rows' => [
				[
					$existing_label,
					(string) (int) ($existing['orders'] ?? 0),
					(string) (int) ($existing['families'] ?? 0),
					self::num((float) ($existing['revenue'] ?? 0)),
				],
				[
					$new_label,
					(string) (int) ($new['orders'] ?? 0),
					(string) (int) ($new['families'] ?? 0),
					self::num((float) ($new['revenue'] ?? 0)),
				],
			],
		];
	}

	/**
	 * @param array<string,mixed> $cohorts
	 * @param string              $cohort_label
	 * @return string
	 */
	private static function cohort_prose(array $cohorts, $cohort_label) {
		if ($cohort_label === 'first booking in our system') {
			return '“First booking in our system” means platform adoption in the booking dataset, not necessarily first-ever InterSoccer customers — the dataset only covers bookings since May 2025.';
		}
		return 'Cohort split uses available prior-order history; treat as directional when customer identifiers are sparse.';
	}

	/**
	 * @param array<string,array> $coupons
	 * @param array<string,mixed> $headline
	 * @return string
	 */
	private static function codes_prose(array $coupons, array $headline) {
		if (!$coupons) {
			return 'No campaign coupon codes were configured for this window.';
		}
		$parts = [];
		foreach ($coupons as $row) {
			$row = (array) $row;
			$parts[] = sprintf(
				'%s: %d orders, %d line items, %s revenue, attach rate %d%%',
				(string) ($row['code'] ?? ''),
				(int) ($row['orders'] ?? 0),
				(int) ($row['line_items'] ?? 0),
				self::chf((float) ($row['revenue'] ?? 0)),
				(int) ($row['attach_rate'] ?? $row['attach_rate_pct'] ?? 0)
			);
		}
		$coded = (int) ($headline['coded_orders'] ?? 0);
		$uncoded = (int) ($headline['uncoded_orders'] ?? 0);
		return implode('. ', $parts) . sprintf('. Coded vs uncoded orders: %d / %d.', $coded, $uncoded);
	}

	/**
	 * @param array<string,mixed> $attribution
	 * @return string
	 */
	private static function attribution_prose(array $attribution) {
		$by = (array) ($attribution['by_source'] ?? []);
		if (!$by) {
			return '';
		}
		uasort($by, static function ($a, $b) {
			return ((int) ($b['orders'] ?? 0)) <=> ((int) ($a['orders'] ?? 0));
		});
		$bits = [];
		$i = 0;
		foreach ($by as $name => $row) {
			$bits[] = sprintf('%s (%d orders)', $name, (int) ($row['orders'] ?? 0));
			if (++$i >= 5) {
				break;
			}
		}
		return 'On attribution: ' . implode(', ', $bits) . '.';
	}

	/**
	 * @param array<string,mixed> $timing
	 * @param array<string,mixed> $top_season
	 * @param array<string,mixed> $volume
	 * @param array<string,array> $coupons
	 * @return array<int,array{lead:string,rest:string}>
	 */
	private static function recommendations(array $timing, array $top_season, array $volume, array $coupons) {
		$recs = [];
		$peak = (array) ($timing['peak_day'] ?? []);
		if (!empty($peak['day_name'])) {
			$recs[] = [
				'lead' => 'The ' . $peak['day_name'] . ' deadline worked.',
				'rest' => ' Demand concentrated on the peak day; keep the expiry on the closing day of the window.',
			];
		} else {
			$recs[] = [
				'lead' => 'Keep a clear closing-day deadline.',
				'rest' => ' Peak-day concentration is the usual pattern for short promotions.',
			];
		}
		if (!empty($top_season['label'])) {
			$recs[] = [
				'lead' => 'Point creative and inventory at ' . $top_season['label'] . '.',
				'rest' => ' That was the largest demand destination in this run.',
			];
		}
		$recs[] = [
			'lead' => 'Push per-channel codes harder.',
			'rest' => empty($coupons)
				? ' Distinct codes per channel make last-touch attribution gaps recoverable.'
				: ' Most volume still funnels through one code; distinct codes per channel would survive the last-touch gap.',
		];
		$aov_pct = $volume['aov_vs_baseline_after_add_back_pct'] ?? ($volume['avg_order_value'] ?? null);
		$recs[] = [
			'lead' => 'Protect average order value.',
			'rest' => ' The offer should continue to attract full terms and full weeks, not discounted scraps.',
		];
		return $recs;
	}

	/**
	 * @param array<int,array{topic?:string,detail?:string}|string> $notes
	 * @param string[] $warnings
	 * @return string[]
	 */
	private static function gaps(array $notes, array $warnings) {
		$fixed = [
			'Marketing spend and channel return — spend by platform is not in our systems, so cost-per-booking and true ROI cannot be reconstructed.',
			'Email and social are systematically under-credited in last-touch attribution; customers who open a newsletter, leave, and book later appear as direct/typein.',
			'A reliable per-camp-week Summer breakdown when FR/EN season labels are still merging.',
		];
		foreach ($notes as $note) {
			if (!is_array($note)) {
				continue;
			}
			$topic = strtolower((string) ($note['topic'] ?? ''));
			$detail = trim((string) ($note['detail'] ?? ''));
			if ($detail === '') {
				continue;
			}
			if (preg_match('/\b(gap|cannot|missing|unreliable|roi|spend)\b/i', $topic . ' ' . $detail)) {
				$fixed[] = $detail;
			}
		}
		return array_values(array_unique($fixed));
	}

	/**
	 * @param string $start
	 * @param string $end
	 * @return string
	 */
	private static function format_window_human($start, $end) {
		try {
			$s = new \DateTimeImmutable(substr($start, 0, 19));
			$e = new \DateTimeImmutable(substr($end, 0, 19));
			return $s->format('l j F') . ' to ' . $e->format('l j F Y');
		} catch (\Exception $ex) {
			return trim($start . ' – ' . $end);
		}
	}

	/**
	 * @param mixed $pct
	 * @return string
	 */
	private static function pct_label($pct) {
		if ($pct === null || $pct === '') {
			return '—';
		}
		$n = (int) $pct;
		return ($n > 0 ? '+' : '') . $n . '%';
	}

	/**
	 * @param float $n
	 * @return string
	 */
	public static function chf($n) {
		return 'CHF ' . number_format((float) $n, 2, '.', ',');
	}

	/**
	 * @param float $n
	 * @return string
	 */
	public static function num($n) {
		return number_format((float) $n, 2, '.', ',');
	}

	/**
	 * House Bookings display columns from an allowlisted child row.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,string|int>
	 */
	public static function booking_display_row(array $row) {
		$ts = (string) ($row['booking_timestamp'] ?? '');
		$date = strlen($ts) >= 10 ? substr($ts, 0, 10) : '';
		$time = strlen($ts) >= 19 ? substr($ts, 11, 8) : '';
		$day = '';
		if ($date !== '') {
			try {
				$day = (new \DateTimeImmutable($date . ' 12:00:00'))->format('l');
			} catch (\Exception $e) {
				$day = '';
			}
		}
		$region = (string) ($row['region'] ?? '');
		if ($region === '' || $region === 'not_recorded') {
			$region = '(not set)';
		}
		$venue = (string) ($row['venue'] ?? '');
		if ($venue === '' || strtoupper($venue) === 'NULL') {
			$venue = '(not recorded)';
		}
		$activity = (string) ($row['activity'] ?? '');
		if ($activity === '') {
			$activity = '(not recorded)';
		} else {
			$activity = ucfirst($activity);
		}
		return [
			'Order ID' => (int) ($row['order_id'] ?? 0),
			'Date' => $date,
			'Day' => $day,
			'Time' => $time,
			'Child age' => $row['derived_age'] ?? '',
			'Gender' => (string) ($row['gender'] ?? ''),
			'Activity' => $activity,
			'Booking type' => self::display_booking_type((string) ($row['booking_type'] ?? '')),
			'Season' => self::display_season((string) ($row['season'] ?? '')),
			'Region' => $region,
			'Venue' => $venue,
			'Code used' => !empty($row['coupon_used']) ? 'Yes' : 'No',
		];
	}

	/**
	 * @return string[]
	 */
	public static function booking_headers() {
		return [
			'Order ID',
			'Date',
			'Day',
			'Time',
			'Child age',
			'Gender',
			'Activity',
			'Booking type',
			'Season',
			'Region',
			'Venue',
			'Code used',
		];
	}
}
