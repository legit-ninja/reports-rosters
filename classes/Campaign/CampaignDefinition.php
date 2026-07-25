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
		];
		$json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
		return md5((string) $json);
	}
}
