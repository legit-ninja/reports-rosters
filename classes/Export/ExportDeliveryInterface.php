<?php
/**
 * Delivery channel for generated export payloads (browser download, O365, …).
 *
 * Office 365 Graph auth stays in SyncService; adapters should upload bytes only.
 * Do not re-query roster SQL or reinvent Excel column schemas here.
 *
 * @package InterSoccer\ReportsRosters\Export
 */

namespace InterSoccer\ReportsRosters\Export;

defined('ABSPATH') or die('Restricted access');

interface ExportDeliveryInterface {

	/**
	 * Deliver a generated export payload.
	 *
	 * @param RosterExportPayload $payload
	 * @return array{success:bool,message?:string,error?:string}
	 */
	public function deliver(RosterExportPayload $payload): array;
}
