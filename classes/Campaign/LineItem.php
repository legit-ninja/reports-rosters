<?php
/**
 * Normalized booking line for campaign aggregation.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

/**
 * Immutable-ish DTO (array-backed) for a single order line in a campaign window.
 */
class LineItem {

	/** @var array<string,mixed> */
	private $data;

	/**
	 * @param array<string,mixed> $data
	 */
	public function __construct(array $data) {
		$this->data = $data;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get($key, $default = null) {
		return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * @param array<string,mixed> $patch
	 * @return self
	 */
	public function with(array $patch) {
		return new self(array_merge($this->data, $patch));
	}
}
