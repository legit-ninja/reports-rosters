<?php
/**
 * Load campaign definitions from CPT.
 *
 * @package InterSoccer\ReportsRosters\Data\Repositories
 */

namespace InterSoccer\ReportsRosters\Data\Repositories;

use InterSoccer\ReportsRosters\Campaign\CampaignDefinition;
use InterSoccer\ReportsRosters\Campaign\CampaignPostType;
use InterSoccer\ReportsRosters\Campaign\CampaignTimezone;

defined('ABSPATH') or die('Restricted access');

class CampaignRepository {

	/**
	 * @param int $id
	 * @return CampaignDefinition|null
	 */
	public function find($id) {
		$post = get_post((int) $id);
		if (!$post || $post->post_type !== CampaignPostType::POST_TYPE) {
			return null;
		}
		return $this->from_post($post);
	}

	/**
	 * @return CampaignDefinition[]
	 */
	public function all() {
		$posts = get_posts([
			'post_type' => CampaignPostType::POST_TYPE,
			'post_status' => ['publish', 'draft', 'private'],
			'numberposts' => 200,
			'orderby' => 'date',
			'order' => 'DESC',
		]);
		$out = [];
		foreach ($posts as $post) {
			$out[] = $this->from_post($post);
		}
		return $out;
	}

	/**
	 * @param \WP_Post $post
	 * @return CampaignDefinition
	 */
	public function from_post($post) {
		$statuses = get_post_meta($post->ID, '_isrr_campaign_statuses', true);
		if (!is_array($statuses) || empty($statuses)) {
			$statuses = ['completed', 'processing'];
		}
		$statuses = array_map(static function ($s) {
			$s = (string) $s;
			return strpos($s, 'wc-') === 0 ? $s : 'wc-' . $s;
		}, $statuses);

		return CampaignDefinition::from_array([
			'id' => (int) $post->ID,
			'name' => $post->post_title,
			'description' => $post->post_content,
			'start_datetime' => CampaignTimezone::normalize_bound((string) get_post_meta($post->ID, '_isrr_campaign_start', true), 'start'),
			'end_datetime' => CampaignTimezone::normalize_bound((string) get_post_meta($post->ID, '_isrr_campaign_end', true), 'end'),
			'coupon_codes' => (array) get_post_meta($post->ID, '_isrr_campaign_coupons', true),
			'baseline_mode' => (string) (get_post_meta($post->ID, '_isrr_campaign_baseline_mode', true) ?: 'matched_prior'),
			'baseline_custom_start' => CampaignTimezone::normalize_bound((string) get_post_meta($post->ID, '_isrr_campaign_baseline_start', true), 'start'),
			'baseline_custom_end' => CampaignTimezone::normalize_bound((string) get_post_meta($post->ID, '_isrr_campaign_baseline_end', true), 'end'),
			'target_scope' => (array) get_post_meta($post->ID, '_isrr_campaign_target_scope', true),
			'capacity_overrides' => (array) get_post_meta($post->ID, '_isrr_campaign_capacity', true),
			'marketing_activations' => (array) get_post_meta($post->ID, '_isrr_campaign_activations', true),
			'order_statuses' => $statuses,
			'revenue_basis' => 'order_totals',
			'momentum_before_weeks' => (int) (get_post_meta($post->ID, '_isrr_campaign_momentum_before_weeks', true) ?: 4),
			'momentum_after_weeks' => (int) (get_post_meta($post->ID, '_isrr_campaign_momentum_after_weeks', true) ?: 2),
		]);
	}
}
