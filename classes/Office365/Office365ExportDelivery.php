<?php
/**
 * Office 365 delivery adapter — uploads PhpSpreadsheet bytes via SyncService.
 *
 * Does not generate Excel; only delivers RosterExportPayload.
 *
 * @package InterSoccer\ReportsRosters\Office365
 */

namespace InterSoccer\ReportsRosters\Office365;

use InterSoccer\ReportsRosters\Export\ExportDeliveryInterface;
use InterSoccer\ReportsRosters\Export\RosterExportPayload;

defined('ABSPATH') or die('Restricted access');

final class Office365ExportDelivery implements ExportDeliveryInterface {

	/** @var SyncService */
	private $sync;

	public function __construct(SyncService $sync = null) {
		$this->sync = $sync ?? new SyncService();
	}

	/**
	 * {@inheritdoc}
	 */
	public function deliver(RosterExportPayload $payload): array {
		return $this->sync->uploadFile($payload->getFilename(), $payload->getBytes());
	}
}
