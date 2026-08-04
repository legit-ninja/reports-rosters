<?php
/**
 * Campaign entity loaded from CPT meta.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class CampaignDefinition {

	/** @var int */
	public $id;

	/** @var string */
	public $name;

	/** @var string */
	public $description;

	/** @var string */
	public $start_datetime;

	/** @var string */
	public $end_datetime;

	/** @var string[] */
	public $coupon_codes = [];

	/** @var string */
	public $baseline_mode = 'matched_prior';

	/** @var string */
	public $baseline_custom_start = '';

	/** @var string */
	public $baseline_custom_end = '';

	/** @var array<string,mixed> */
	public $target_scope = [];

	/** @var array<string,int> */
	public $capacity_overrides = [];

	/** @var array<int,array<string,mixed>> */
	public $marketing_activations = [];

	/** @var string[] */
	public $order_statuses = ['wc-completed', 'wc-processing'];

	/** @var string order_totals|line_totals */
	public $revenue_basis = 'order_totals';

	/** @var int weeks of total demand before campaign start (momentum trough) */
	public $momentum_before_weeks = 4;

	/** @var int weeks after campaign end (momentum trough) */
	public $momentum_after_weeks = 2;

	/** Days before campaign start included in daily zoom (M3). */
	const MOMENTUM_DAILY_PAD_DAYS = 10;

	/**
	 * @param array<string,mixed> $row
	 * @return self
	 */
	public static function from_array(array $row) {
		$self = new self();
		$self->id = (int) ($row['id'] ?? 0);
		$self->name = (string) ($row['name'] ?? '');
		$self->description = (string) ($row['description'] ?? '');
		$self->start_datetime = (string) ($row['start_datetime'] ?? '');
		$self->end_datetime = (string) ($row['end_datetime'] ?? '');
		$self->coupon_codes = array_values(array_filter(array_map('strval', (array) ($row['coupon_codes'] ?? []))));
		$self->baseline_mode = (string) ($row['baseline_mode'] ?? 'matched_prior');
		$self->baseline_custom_start = (string) ($row['baseline_custom_start'] ?? '');
		$self->baseline_custom_end = (string) ($row['baseline_custom_end'] ?? '');
		$self->target_scope = (array) ($row['target_scope'] ?? []);
		$self->capacity_overrides = array_map('intval', (array) ($row['capacity_overrides'] ?? []));
		$self->marketing_activations = (array) ($row['marketing_activations'] ?? []);
		$statuses = (array) ($row['order_statuses'] ?? ['wc-completed', 'wc-processing']);
		$self->order_statuses = array_map(static function ($s) {
			$s = (string) $s;
			return strpos($s, 'wc-') === 0 ? $s : 'wc-' . $s;
		}, $statuses);
		$self->revenue_basis = ($row['revenue_basis'] ?? 'order_totals') === 'line_totals' ? 'line_totals' : 'order_totals';
		$self->momentum_before_weeks = max(1, (int) ($row['momentum_before_weeks'] ?? 4));
		$self->momentum_after_weeks = max(1, (int) ($row['momentum_after_weeks'] ?? 2));
		return $self;
	}

	/**
	 * @return array{start:string,end:string,mode:string,warnings:string[],length_seconds:int}
	 */
	public function baseline_window() {
		return CampaignBaseline::derive(
			$this->start_datetime,
			$this->end_datetime,
			$this->baseline_mode,
			$this->baseline_custom_start,
			$this->baseline_custom_end
		);
	}

	/**
	 * Wide window for sales momentum (before + during + after + daily pad).
	 *
	 * @return array{
	 *   start:string,
	 *   end:string,
	 *   before_start:string,
	 *   after_end:string,
	 *   before_weeks:int,
	 *   after_weeks:int,
	 *   before_days:int,
	 *   after_days:int,
	 *   daily_start:string
	 * }
	 */
	public function observation_window() {
		$start = CampaignTimezone::parse_local($this->start_datetime);
		$end = CampaignTimezone::parse_local($this->end_datetime);
		$before_days = $this->momentum_before_weeks * 7;
		$after_days = $this->momentum_after_weeks * 7;

		$before_start = $start->modify('-' . $before_days . ' days');
		$after_end = $end->modify('+' . $after_days . ' days');
		$daily_start = $start->modify('-' . self::MOMENTUM_DAILY_PAD_DAYS . ' days');

		$obs_start = $before_start < $daily_start ? $before_start : $daily_start;

		return [
			'start' => CampaignTimezone::to_mysql_local($obs_start),
			'end' => CampaignTimezone::to_mysql_local($after_end),
			'before_start' => CampaignTimezone::to_mysql_local($before_start),
			'after_end' => CampaignTimezone::to_mysql_local($after_end),
			'before_weeks' => $this->momentum_before_weeks,
			'after_weeks' => $this->momentum_after_weeks,
			'before_days' => $before_days,
			'after_days' => $after_days,
			'daily_start' => CampaignTimezone::to_mysql_local($daily_start),
		];
	}

	/**
	 * Stable hash for summary cache invalidation.
	 *
	 * @return string
	 */
	public function definition_hash() {
		$payload = [
			$this->start_datetime,
			$this->end_datetime,
			$this->coupon_codes,
			$this->baseline_mode,
			$this->baseline_custom_start,
			$this->baseline_custom_end,
			$this->target_scope,
			$this->capacity_overrides,
			$this->order_statuses,
			$this->revenue_basis,
			$this->momentum_before_weeks,
			$this->momentum_after_weeks,
		];
		$json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
		return md5((string) $json);
	}
}
