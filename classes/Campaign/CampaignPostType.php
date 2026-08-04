<?php
/**
 * CPT + meta for campaign definitions.
 *
 * @package InterSoccer\ReportsRosters\Campaign
 */

namespace InterSoccer\ReportsRosters\Campaign;

defined('ABSPATH') or die('Restricted access');

class CampaignPostType {

	const POST_TYPE = 'intersoccer_campaign';

	/**
	 * @return void
	 */
	public function register() {
		register_post_type(self::POST_TYPE, [
			'labels' => [
				'name' => __('Campaigns', 'intersoccer-reports-rosters'),
				'singular_name' => __('Campaign', 'intersoccer-reports-rosters'),
				'add_new_item' => __('Add Campaign', 'intersoccer-reports-rosters'),
				'edit_item' => __('Edit Campaign', 'intersoccer-reports-rosters'),
			],
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => false,
			'capability_type' => 'post',
			'map_meta_cap' => true,
			'supports' => ['title', 'editor'],
			'has_archive' => false,
		]);

		add_action('add_meta_boxes', [$this, 'meta_boxes']);
		add_action('save_post_' . self::POST_TYPE, [$this, 'save_meta'], 10, 2);
		add_action('save_post_' . self::POST_TYPE, [$this, 'queue_rebuild_on_save'], 20, 2);
	}

	/**
	 * @return void
	 */
	public function meta_boxes() {
		add_meta_box(
			'intersoccer_campaign_settings',
			__('Campaign settings', 'intersoccer-reports-rosters'),
			[$this, 'render_meta_box'],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * @param \WP_Post $post
	 * @return void
	 */
	public function render_meta_box($post) {
		wp_nonce_field('intersoccer_campaign_meta', 'intersoccer_campaign_meta_nonce');
		$start = get_post_meta($post->ID, '_isrr_campaign_start', true);
		$end = get_post_meta($post->ID, '_isrr_campaign_end', true);
		$codes = get_post_meta($post->ID, '_isrr_campaign_coupons', true);
		$codes = is_array($codes) ? implode(', ', $codes) : (string) $codes;
		$baseline = get_post_meta($post->ID, '_isrr_campaign_baseline_mode', true) ?: 'matched_prior';
		$statuses = get_post_meta($post->ID, '_isrr_campaign_statuses', true);
		$statuses = is_array($statuses) ? implode(',', $statuses) : (string) ($statuses ?: 'completed,processing');
		$target = get_post_meta($post->ID, '_isrr_campaign_target_scope', true);
		$target_json = is_string($target) ? $target : wp_json_encode($target ?: new \stdClass());
		$capacity = get_post_meta($post->ID, '_isrr_campaign_capacity', true);
		$capacity_json = is_string($capacity) ? $capacity : wp_json_encode($capacity ?: new \stdClass());
		$activations = get_post_meta($post->ID, '_isrr_campaign_activations', true);
		$activations_json = is_string($activations) ? $activations : wp_json_encode($activations ?: []);
		$momentum_before = (int) get_post_meta($post->ID, '_isrr_campaign_momentum_before_weeks', true);
		if ($momentum_before < 1) {
			$momentum_before = 4;
		}
		$momentum_after = (int) get_post_meta($post->ID, '_isrr_campaign_momentum_after_weeks', true);
		if ($momentum_after < 1) {
			$momentum_after = 2;
		}
		?>
		<p>
			<label><?php esc_html_e('Start (site local)', 'intersoccer-reports-rosters'); ?><br>
			<input type="text" class="widefat" name="isrr_campaign_start" value="<?php echo esc_attr((string) $start); ?>" placeholder="2026-08-01 or 2026-08-01 00:00:00" /></label>
			<span class="description"><?php esc_html_e('Date-only values use 00:00:00.', 'intersoccer-reports-rosters'); ?></span>
		</p>
		<p>
			<label><?php esc_html_e('End (site local)', 'intersoccer-reports-rosters'); ?><br>
			<input type="text" class="widefat" name="isrr_campaign_end" value="<?php echo esc_attr((string) $end); ?>" placeholder="2026-08-01 or 2026-08-01 23:59:59" /></label>
			<span class="description"><?php esc_html_e('Date-only values use 23:59:59 (full last day).', 'intersoccer-reports-rosters'); ?></span>
		</p>
		<p>
			<label><?php esc_html_e('Coupon codes (comma-separated; multiple for per-channel attribution)', 'intersoccer-reports-rosters'); ?><br>
			<input type="text" class="widefat" name="isrr_campaign_coupons" value="<?php echo esc_attr($codes); ?>" placeholder="swiss15" /></label>
		</p>
		<p>
			<label><?php esc_html_e('Momentum: weeks before campaign', 'intersoccer-reports-rosters'); ?><br>
			<input type="number" min="1" max="52" class="small-text" name="isrr_campaign_momentum_before_weeks" value="<?php echo esc_attr((string) $momentum_before); ?>" /></label>
		</p>
		<p>
			<label><?php esc_html_e('Momentum: weeks after campaign', 'intersoccer-reports-rosters'); ?><br>
			<input type="number" min="1" max="52" class="small-text" name="isrr_campaign_momentum_after_weeks" value="<?php echo esc_attr((string) $momentum_after); ?>" /></label>
			<span class="description"><?php esc_html_e('Used for generate-vs-shift trough rates (defaults 4 / 2).', 'intersoccer-reports-rosters'); ?></span>
		</p>
		<p>
			<label><?php esc_html_e('Baseline mode', 'intersoccer-reports-rosters'); ?><br>
			<select name="isrr_campaign_baseline_mode">
				<option value="matched_prior" <?php selected($baseline, 'matched_prior'); ?>><?php esc_html_e('Matched prior period (default)', 'intersoccer-reports-rosters'); ?></option>
				<option value="same_dates_last_year" <?php selected($baseline, 'same_dates_last_year'); ?>><?php esc_html_e('Same dates last year', 'intersoccer-reports-rosters'); ?></option>
				<option value="custom" <?php selected($baseline, 'custom'); ?>><?php esc_html_e('Custom range', 'intersoccer-reports-rosters'); ?></option>
			</select></label>
		</p>
		<p>
			<label><?php esc_html_e('Custom baseline start', 'intersoccer-reports-rosters'); ?><br>
			<input type="text" class="widefat" name="isrr_campaign_baseline_start" value="<?php echo esc_attr((string) get_post_meta($post->ID, '_isrr_campaign_baseline_start', true)); ?>" /></label>
		</p>
		<p>
			<label><?php esc_html_e('Custom baseline end', 'intersoccer-reports-rosters'); ?><br>
			<input type="text" class="widefat" name="isrr_campaign_baseline_end" value="<?php echo esc_attr((string) get_post_meta($post->ID, '_isrr_campaign_baseline_end', true)); ?>" /></label>
		</p>
		<p>
			<label><?php esc_html_e('Order statuses (comma-separated, without wc- prefix)', 'intersoccer-reports-rosters'); ?><br>
			<input type="text" class="widefat" name="isrr_campaign_statuses" value="<?php echo esc_attr($statuses); ?>" /></label>
		</p>
		<p>
			<label><?php esc_html_e('Target scope JSON (optional)', 'intersoccer-reports-rosters'); ?><br>
			<textarea class="widefat" rows="4" name="isrr_campaign_target_scope"><?php echo esc_textarea($target_json); ?></textarea></label>
		</p>
		<p>
			<label><?php esc_html_e('Capacity overrides JSON (logical keys → int)', 'intersoccer-reports-rosters'); ?><br>
			<textarea class="widefat" rows="4" name="isrr_campaign_capacity"><?php echo esc_textarea($capacity_json); ?></textarea></label>
		</p>
		<p>
			<label><?php esc_html_e('Marketing activations JSON', 'intersoccer-reports-rosters'); ?><br>
			<textarea class="widefat" rows="4" name="isrr_campaign_activations"><?php echo esc_textarea($activations_json); ?></textarea></label>
		</p>
		<?php
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $post
	 * @return void
	 */
	public function save_meta($post_id, $post) {
		if (!isset($_POST['intersoccer_campaign_meta_nonce'])
			|| !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['intersoccer_campaign_meta_nonce'])), 'intersoccer_campaign_meta')) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (!current_user_can('manage_options')) {
			return;
		}

		$start = isset($_POST['isrr_campaign_start']) ? sanitize_text_field(wp_unslash($_POST['isrr_campaign_start'])) : '';
		$end = isset($_POST['isrr_campaign_end']) ? sanitize_text_field(wp_unslash($_POST['isrr_campaign_end'])) : '';
		update_post_meta($post_id, '_isrr_campaign_start', CampaignTimezone::normalize_bound($start, 'start'));
		update_post_meta($post_id, '_isrr_campaign_end', CampaignTimezone::normalize_bound($end, 'end'));

		$codes_raw = isset($_POST['isrr_campaign_coupons']) ? sanitize_text_field(wp_unslash($_POST['isrr_campaign_coupons'])) : '';
		$codes = array_values(array_filter(array_map('trim', explode(',', $codes_raw))));
		update_post_meta($post_id, '_isrr_campaign_coupons', $codes);

		$mode = isset($_POST['isrr_campaign_baseline_mode']) ? sanitize_key(wp_unslash($_POST['isrr_campaign_baseline_mode'])) : 'matched_prior';
		update_post_meta($post_id, '_isrr_campaign_baseline_mode', $mode);
		$b_start = isset($_POST['isrr_campaign_baseline_start']) ? sanitize_text_field(wp_unslash($_POST['isrr_campaign_baseline_start'])) : '';
		$b_end = isset($_POST['isrr_campaign_baseline_end']) ? sanitize_text_field(wp_unslash($_POST['isrr_campaign_baseline_end'])) : '';
		update_post_meta($post_id, '_isrr_campaign_baseline_start', CampaignTimezone::normalize_bound($b_start, 'start'));
		update_post_meta($post_id, '_isrr_campaign_baseline_end', CampaignTimezone::normalize_bound($b_end, 'end'));

		$statuses_raw = isset($_POST['isrr_campaign_statuses']) ? sanitize_text_field(wp_unslash($_POST['isrr_campaign_statuses'])) : 'completed,processing';
		$statuses = array_values(array_filter(array_map('trim', explode(',', $statuses_raw))));
		update_post_meta($post_id, '_isrr_campaign_statuses', $statuses);

		$before_w = isset($_POST['isrr_campaign_momentum_before_weeks']) ? (int) $_POST['isrr_campaign_momentum_before_weeks'] : 4;
		$after_w = isset($_POST['isrr_campaign_momentum_after_weeks']) ? (int) $_POST['isrr_campaign_momentum_after_weeks'] : 2;
		update_post_meta($post_id, '_isrr_campaign_momentum_before_weeks', max(1, min(52, $before_w)));
		update_post_meta($post_id, '_isrr_campaign_momentum_after_weeks', max(1, min(52, $after_w)));

		foreach (['target_scope' => 'isrr_campaign_target_scope', 'capacity' => 'isrr_campaign_capacity', 'activations' => 'isrr_campaign_activations'] as $meta => $field) {
			$raw = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';
			$decoded = json_decode(is_string($raw) ? $raw : '', true);
			update_post_meta($post_id, '_isrr_campaign_' . $meta, $decoded !== null ? $decoded : []);
		}
	}

	/**
	 * @param int $post_id
	 * @return void
	 */
	public function queue_rebuild_on_save($post_id) {
		if (get_post_type($post_id) !== self::POST_TYPE) {
			return;
		}
		(new CampaignSummaryStore())->enqueue_rebuild((int) $post_id);
	}
}
