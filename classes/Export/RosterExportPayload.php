<?php
/**
 * Immutable Excel (or other binary) export payload for delivery adapters.
 *
 * PhpSpreadsheet remains the SoT for .xlsx generation. Delivery (download,
 * Office 365 upload, etc.) must consume this shape — not re-build reports.
 *
 * @package InterSoccer\ReportsRosters\Export
 */

namespace InterSoccer\ReportsRosters\Export;

defined('ABSPATH') or die('Restricted access');

final class RosterExportPayload {

	/** @var string Raw file bytes */
	private $bytes;

	/** @var string Suggested filename (e.g. roster_….xlsx) */
	private $filename;

	/** @var string MIME type */
	private $mime;

	/** @var array<string,mixed> Non-PII metadata (activity_type, product_id, row_count, …) */
	private $metadata;

	/**
	 * @param string               $bytes
	 * @param string               $filename
	 * @param string               $mime
	 * @param array<string,mixed>  $metadata
	 */
	public function __construct(
		string $bytes,
		string $filename,
		string $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		array $metadata = []
	) {
		$this->bytes    = $bytes;
		$this->filename = $filename;
		$this->mime     = $mime;
		$this->metadata = $metadata;
	}

	public function getBytes(): string {
		return $this->bytes;
	}

	public function getFilename(): string {
		return $this->filename;
	}

	public function getMime(): string {
		return $this->mime;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getMetadata(): array {
		return $this->metadata;
	}
}
