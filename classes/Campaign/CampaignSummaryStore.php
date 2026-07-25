<?php
/**
 * Persist campaign summary payloads (avoid compute-on-page-load).
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class CampaignSummaryStore {

	const TABLE = 'intersoccer_campaign_summaries';

	/**
	 * Create table via dbDelta.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL,
			definition_hash varchar(32) NOT NULL DEFAULT '',
			status_set varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'building',
			computed_at datetime DEFAULT NULL,
			payload_json longtext NULL,
			warnings_json longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_def (campaign_id, definition_hash, status_set),
			KEY status_idx (status),
			KEY campaign_id_idx (campaign_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	/**
	 * @param int    $campaign_id
	 * @param string $definition_hash
	 * @param string $status_set
	 * @return array<string,mixed>|null
	 */
	public function get($campaign_id, $definition_hash, $status_set) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE campaign_id = %d AND definition_hash = %s AND status_set = %s LIMIT 1",
				$campaign_id,
				$definition_hash,
				$status_set
			),
			ARRAY_A
		);
		if (!is_array($row)) {
			return null;
		}
		$row['payload'] = $row['payload_json'] ? json_decode($row['payload_json'], true) : null;
		$row['warnings'] = $row['warnings_json'] ? json_decode($row['warnings_json'], true) : [];
		return $row;
	}

	/**
	 * Latest ready summary for campaign (any hash).
	 *
	 * @param int $campaign_id
	 * @return array<string,mixed>|null
	 */
	public function get_latest_ready($campaign_id) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE campaign_id = %d AND status = 'ready' ORDER BY computed_at DESC LIMIT 1",
				$campaign_id
			),
			ARRAY_A
		);
		if (!is_array($row)) {
			return null;
		}
		$row['payload'] = $row['payload_json'] ? json_decode($row['payload_json'], true) : null;
		$row['warnings'] = $row['warnings_json'] ? json_decode($row['warnings_json'], true) : [];
		return $row;
	}

	/**
	 * @param int                  $campaign_id
	 * @param string               $definition_hash
	 * @param string               $status_set
	 * @param string               $status
	 * @param array<string,mixed>|null $payload
	 * @param array                $warnings
	 * @return void
	 */
	public function upsert($campaign_id, $definition_hash, $status_set, $status, $payload = null, array $warnings = []) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$existing = $this->get($campaign_id, $definition_hash, $status_set);
		$data = [
			'campaign_id' => $campaign_id,
			'definition_hash' => $definition_hash,
			'status_set' => $status_set,
			'status' => $status,
			'computed_at' => current_time('mysql'),
			'payload_json' => $payload === null ? null : wp_json_encode($payload),
			'warnings_json' => wp_json_encode($warnings),
		];
		if ($existing) {
			$wpdb->update($table, $data, ['id' => (int) $existing['id']]);
		} else {
			$wpdb->insert($table, $data);
		}
	}

	/**
	 * Queue rebuild by marking building and appending campaign id to option queue.
	 *
	 * @param int $campaign_id
	 * @return void
	 */
	public function enqueue_rebuild($campaign_id) {
		$queue = get_option('intersoccer_campaign_rebuild_queue', []);
		if (!is_array($queue)) {
			$queue = [];
		}
		$queue[] = (int) $campaign_id;
		$queue = array_values(array_unique(array_filter($queue)));
		update_option('intersoccer_campaign_rebuild_queue', $queue, false);
	}
}
