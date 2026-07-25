<?php
/**
 * One-time seed helper for Swiss National Day 2026 campaign (run via eval-file / mu-plugin).
 *
 * Usage (local sandbox WP-CLI):
 *   wp eval-file includes/campaign-seed-swiss-national-day.php
 *
 * Does nothing if a campaign titled "Swiss National Day 2026" already exists.
 */

defined('ABSPATH') or die('Restricted access');

$title = 'Swiss National Day 2026';
$existing = get_posts([
	'post_type' => 'intersoccer_campaign',
	'title' => $title,
	'post_status' => 'any',
	'numberposts' => 1,
]);
if (!empty($existing)) {
	if (defined('WP_CLI') && WP_CLI) {
		WP_CLI::log('Campaign already exists: ID ' . $existing[0]->ID);
	}
	return;
}

$id = wp_insert_post([
	'post_type' => 'intersoccer_campaign',
	'post_title' => $title,
	'post_content' => 'Swiss National Day promotion — pre-registered for Campaign Analytics.',
	'post_status' => 'publish',
]);
if (is_wp_error($id) || !$id) {
	if (defined('WP_CLI') && WP_CLI) {
		WP_CLI::error('Failed to create campaign');
	}
	return;
}

update_post_meta($id, '_isrr_campaign_start', '2026-08-01 00:00:00');
update_post_meta($id, '_isrr_campaign_end', '2026-08-01 23:59:59');
update_post_meta($id, '_isrr_campaign_coupons', ['AUG1MAIL', 'AUG1SOCIAL', 'AUG1FLYER']);
update_post_meta($id, '_isrr_campaign_baseline_mode', 'matched_prior');
update_post_meta($id, '_isrr_campaign_statuses', ['completed', 'processing']);
update_post_meta($id, '_isrr_campaign_target_scope', [
	'camp_weeks' => ['summer_week_6', 'summer_week_7', 'summer_week_8'],
]);
update_post_meta($id, '_isrr_campaign_activations', []);
update_post_meta($id, '_isrr_campaign_capacity', new stdClass());

(new \InterSoccer\ReportsRosters\Campaign\CampaignSummaryStore())->enqueue_rebuild((int) $id);

if (defined('WP_CLI') && WP_CLI) {
	WP_CLI::success('Created Swiss National Day campaign ID ' . $id);
}
