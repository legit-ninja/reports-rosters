<?php
/**
 * Campaign Analytics admin UI (reads summary store only).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

/**
 * Render Campaign Analytics admin page.
 *
 * @return void
 */
function intersoccer_render_campaign_analytics_page() {
	if (!current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have permission to access this page.', 'intersoccer-reports-rosters'));
	}

	$repo = new \InterSoccer\ReportsRosters\Data\Repositories\CampaignRepository();
	$store = new \InterSoccer\ReportsRosters\Campaign\CampaignSummaryStore();
	$scheduler = new \InterSoccer\ReportsRosters\Campaign\CampaignRebuildScheduler();

	if (isset($_POST['isrr_campaign_refresh']) && check_admin_referer('isrr_campaign_refresh')) {
		$cid = isset($_POST['campaign_id']) ? (int) $_POST['campaign_id'] : 0;
		if ($cid > 0) {
			$store->enqueue_rebuild($cid);
			// Attempt immediate rebuild for responsiveness (still chunk-safe for one campaign).
			$scheduler->rebuild_campaign($cid);
			echo '<div class="notice notice-success"><p>' . esc_html__('Campaign rebuild queued and run.', 'intersoccer-reports-rosters') . '</p></div>';
		}
	}

	$campaigns = $repo->all();
	$selected = isset($_GET['campaign_id']) ? (int) $_GET['campaign_id'] : 0;
	if ($selected <= 0 && !empty($campaigns)) {
		$selected = (int) $campaigns[0]->id;
	}

	$definition = $selected ? $repo->find($selected) : null;
	$summary = null;
	$payload = null;
	$status = 'missing';
	if ($definition) {
		$hash = $definition->definition_hash();
		$status_set = implode(',', $definition->order_statuses);
		$summary = $store->get($definition->id, $hash, $status_set);
		if (!$summary) {
			$summary = $store->get_latest_ready($definition->id);
		}
		if ($summary) {
			$status = (string) ($summary['status'] ?? 'missing');
			$payload = $summary['payload'] ?? null;
		}
	}

	$new_url = admin_url('post-new.php?post_type=intersoccer_campaign');
	?>
	<div class="wrap">
		<h1><?php esc_html_e('Campaign Analytics', 'intersoccer-reports-rosters'); ?></h1>
		<p>
			<a class="button button-primary" href="<?php echo esc_url($new_url); ?>"><?php esc_html_e('Add campaign', 'intersoccer-reports-rosters'); ?></a>
			<?php if ($definition) : ?>
				<a class="button" href="<?php echo esc_url(get_edit_post_link($definition->id)); ?>"><?php esc_html_e('Edit campaign', 'intersoccer-reports-rosters'); ?></a>
			<?php endif; ?>
		</p>

		<form method="get">
			<input type="hidden" name="page" value="intersoccer-campaign-analytics" />
			<label for="campaign_id"><?php esc_html_e('Campaign', 'intersoccer-reports-rosters'); ?></label>
			<select name="campaign_id" id="campaign_id" onchange="this.form.submit()">
				<option value="0"><?php esc_html_e('— Select —', 'intersoccer-reports-rosters'); ?></option>
				<?php foreach ($campaigns as $c) : ?>
					<option value="<?php echo esc_attr((string) $c->id); ?>" <?php selected($selected, $c->id); ?>><?php echo esc_html($c->name); ?></option>
				<?php endforeach; ?>
			</select>
		</form>

		<?php if ($definition) : ?>
			<form method="post" style="margin-top:1em;">
				<?php wp_nonce_field('isrr_campaign_refresh'); ?>
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr((string) $definition->id); ?>" />
				<button class="button" name="isrr_campaign_refresh" value="1"><?php esc_html_e('Refresh now', 'intersoccer-reports-rosters'); ?></button>
				<a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'intersoccer-campaign-analytics', 'campaign_id' => $definition->id, 'export' => 'xlsx']), 'isrr_campaign_export')); ?>"><?php esc_html_e('Export data (Excel)', 'intersoccer-reports-rosters'); ?></a>
				<a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'intersoccer-campaign-analytics', 'campaign_id' => $definition->id, 'export' => 'docx']), 'isrr_campaign_export')); ?>"><?php esc_html_e('Export report (Word)', 'intersoccer-reports-rosters'); ?></a>
			</form>

			<p><strong><?php esc_html_e('Summary status:', 'intersoccer-reports-rosters'); ?></strong> <?php echo esc_html($status); ?>
				<?php if (!empty($summary['computed_at'])) : ?>
					— <?php echo esc_html((string) $summary['computed_at']); ?>
				<?php endif; ?>
			</p>

			<?php if ($status === 'building' || $status === 'missing') : ?>
				<div class="notice notice-info"><p><?php esc_html_e('Building… Last ready payload is shown when available. Use Refresh now to queue a rebuild. Exports require a stored summary (ready or data-quality stub).', 'intersoccer-reports-rosters'); ?></p></div>
			<?php endif; ?>

			<?php
			$gate_blocked = is_array($payload)
				&& class_exists('\InterSoccer\ReportsRosters\Campaign\Export\CampaignReportSections')
				&& \InterSoccer\ReportsRosters\Campaign\Export\CampaignReportSections::is_gate_blocked($payload);
			?>
			<?php if ($gate_blocked || ($status === 'failed' && is_array($payload))) : ?>
				<div class="notice notice-error"><p><?php esc_html_e('Data quality gate blocked this campaign. Export will download a one-page stub listing blocked reasons — not a partial report.', 'intersoccer-reports-rosters'); ?></p>
					<pre><?php echo esc_html(wp_json_encode(is_array($payload) ? ($payload['errors'] ?? $summary['warnings'] ?? []) : ($summary['warnings'] ?? []), JSON_PRETTY_PRINT)); ?></pre>
				</div>
			<?php elseif (is_array($payload)) : ?>
				<?php intersoccer_campaign_analytics_render_payload($payload, $definition); ?>
			<?php elseif ($status === 'failed') : ?>
				<div class="notice notice-error"><p><?php esc_html_e('Rebuild failed. Refresh again after fixing data-quality issues. No exportable stub is stored yet.', 'intersoccer-reports-rosters'); ?></p>
					<pre><?php echo esc_html(wp_json_encode($summary['warnings'] ?? [], JSON_PRETTY_PRINT)); ?></pre>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * @param array<string,mixed> $payload
 * @param \InterSoccer\ReportsRosters\Campaign\CampaignDefinition $definition
 * @return void
 */
function intersoccer_campaign_analytics_render_payload(array $payload, $definition) {
	$h = (array) ($payload['headline'] ?? []);
	$vv = (array) ($payload['volume_value'] ?? []);
	?>
	<div class="notice notice-warning"><p><strong><?php esc_html_e('Attribution limitation', 'intersoccer-reports-rosters'); ?>:</strong>
		<?php echo esc_html((string) ($payload['attribution_limitation'] ?? '')); ?></p></div>

	<p>
		<?php esc_html_e('Window:', 'intersoccer-reports-rosters'); ?>
		<code><?php echo esc_html($definition->start_datetime . ' → ' . $definition->end_datetime); ?></code>
		|
		<?php esc_html_e('Baseline:', 'intersoccer-reports-rosters'); ?>
		<code><?php echo esc_html(($payload['baseline_window']['start'] ?? '') . ' → ' . ($payload['baseline_window']['end'] ?? '')); ?></code>
		|
		<?php esc_html_e('Statuses:', 'intersoccer-reports-rosters'); ?>
		<code><?php echo esc_html(implode(', ', $definition->order_statuses)); ?></code>
		|
		<?php esc_html_e('Source:', 'intersoccer-reports-rosters'); ?>
		<code><?php echo esc_html((string) ($payload['source_id'] ?? 'orders')); ?></code>
	</p>

	<h2><?php esc_html_e('Headline comparison', 'intersoccer-reports-rosters'); ?></h2>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e('Metric', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Campaign', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Baseline', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Change', 'intersoccer-reports-rosters'); ?></th></tr></thead>
		<tbody>
			<tr><td><?php esc_html_e('Orders', 'intersoccer-reports-rosters'); ?></td><td><?php echo esc_html((string) ($h['orders'] ?? '')); ?></td><td><?php echo esc_html((string) ($h['baseline']['orders'] ?? '')); ?></td><td><?php echo esc_html((string) ($h['pct_change']['orders'] ?? '') . '%'); ?></td></tr>
			<tr><td><?php esc_html_e('Line-item bookings', 'intersoccer-reports-rosters'); ?></td><td><?php echo esc_html((string) ($h['line_item_bookings'] ?? '')); ?></td><td><?php echo esc_html((string) ($h['baseline']['line_item_bookings'] ?? '')); ?></td><td><?php echo esc_html((string) ($h['pct_change']['line_item_bookings'] ?? '') . '%'); ?></td></tr>
			<tr><td><?php esc_html_e('Revenue (order totals)', 'intersoccer-reports-rosters'); ?></td><td><?php echo esc_html((string) ($h['revenue_order_totals'] ?? '')); ?></td><td><?php echo esc_html((string) ($h['baseline']['revenue_order_totals'] ?? '')); ?></td><td><?php echo esc_html((string) ($h['pct_change']['revenue_order_totals'] ?? '') . '%'); ?></td></tr>
			<tr><td><?php esc_html_e('With campaign code(s)', 'intersoccer-reports-rosters'); ?></td><td colspan="3"><?php echo esc_html(sprintf(
				/* translators: 1: order count 2: revenue */
				__('%1$s orders · %2$s revenue', 'intersoccer-reports-rosters'),
				(string) ($h['coded_orders'] ?? '0'),
				(string) ($h['coded_revenue_order_totals'] ?? '0')
			)); ?></td></tr>
			<tr><td><?php esc_html_e('Without campaign code(s)', 'intersoccer-reports-rosters'); ?></td><td colspan="3"><?php echo esc_html(sprintf(
				/* translators: 1: order count 2: revenue */
				__('%1$s orders · %2$s revenue', 'intersoccer-reports-rosters'),
				(string) ($h['uncoded_orders'] ?? '0'),
				(string) ($h['uncoded_revenue_order_totals'] ?? '0')
			)); ?></td></tr>
			<tr><td><?php esc_html_e('Revenue (line totals)', 'intersoccer-reports-rosters'); ?></td><td colspan="3"><?php echo esc_html((string) ($h['revenue_line_totals'] ?? '')); ?></td></tr>
			<tr><td><?php esc_html_e('Avg order value', 'intersoccer-reports-rosters'); ?></td><td><?php echo esc_html((string) ($h['avg_order_value'] ?? '')); ?></td><td><?php echo esc_html((string) ($h['baseline']['avg_order_value'] ?? '')); ?></td><td><?php echo esc_html((string) ($h['pct_change']['avg_order_value'] ?? '') . '%'); ?></td></tr>
		</tbody>
	</table>

	<h2><?php esc_html_e('Volume / value split', 'intersoccer-reports-rosters'); ?></h2>
	<ul>
		<li><?php echo esc_html(sprintf(/* translators: %s amount */ __('Coupon discount (promotion): %s', 'intersoccer-reports-rosters'), (string) ($vv['coupon_discount_total'] ?? ''))); ?></li>
		<li><?php echo esc_html(sprintf(/* translators: %s amount */ __('Sibling/combo discount (not coupon): %s', 'intersoccer-reports-rosters'), (string) ($vv['sibling_combo_discount_total'] ?? ''))); ?></li>
		<li><?php echo esc_html(sprintf(/* translators: %s amount */ __('AOV with coupon added back: %s', 'intersoccer-reports-rosters'), (string) ($vv['avg_order_value_coupon_added_back'] ?? ''))); ?></li>
	</ul>

	<h2><?php esc_html_e('Coupon usage', 'intersoccer-reports-rosters'); ?></h2>
	<table class="widefat striped">
		<thead><tr><th>Code</th><th>Orders</th><th>Lines</th><th>Revenue</th><th>Discount</th><th>Attach %</th><th>Last redemption</th><th>usage_count</th></tr></thead>
		<tbody>
		<?php foreach ((array) ($payload['coupons'] ?? []) as $c) : ?>
			<tr>
				<td><?php echo esc_html((string) ($c['code'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($c['orders'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($c['line_items'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($c['revenue'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($c['discount'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($c['attach_rate'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($c['last_redemption'] ?? '')); ?></td>
				<td><?php
					echo esc_html((string) ($c['usage_count_meta'] ?? '—'));
					if (!empty($c['usage_count_warning'])) {
						echo '<br><span style="color:#b32d2e;">' . esc_html((string) $c['usage_count_warning']) . '</span>';
					}
				?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e('Timing profile', 'intersoccer-reports-rosters'); ?></h2>
	<table class="widefat striped">
		<thead><tr><th>Day</th><th>Orders</th><th>Line items</th><th>Line revenue</th><th>Order revenue</th><th>Coupon orders</th><th>Coupon lines</th></tr></thead>
		<tbody>
		<?php foreach ((array) ($payload['timing']['by_day'] ?? []) as $day => $row) : ?>
			<tr>
				<td><?php
					$label = (string) $day;
					if (!empty($row['day_name'])) {
						$label .= ' (' . $row['day_name'] . ')';
					}
					echo esc_html($label);
				?></td>
				<td><?php echo esc_html((string) ($row['orders'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['line_items'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['line_revenue'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['order_revenue'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['coupon_orders'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['coupon_line_items'] ?? '')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if (!empty($payload['timing']['by_hour'])) : ?>
		<details style="margin:1em 0;">
			<summary><?php esc_html_e('Hour grid', 'intersoccer-reports-rosters'); ?></summary>
			<table class="widefat striped">
				<thead><tr><th>Day</th><th>Hour</th><th>Orders</th><th>Lines</th><th>Order revenue</th><th>Coupon orders</th></tr></thead>
				<tbody>
				<?php foreach ((array) $payload['timing']['by_hour'] as $row) : ?>
					<tr>
						<td><?php echo esc_html((string) ($row['day'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['hour'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['orders'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['line_items'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['order_revenue'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['coupon_orders'] ?? '')); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	<?php endif; ?>
	<?php if (!empty($payload['timing']['marketing_activations'])) : ?>
		<h3><?php esc_html_e('Marketing activations', 'intersoccer-reports-rosters'); ?></h3>
		<pre><?php echo esc_html(wp_json_encode($payload['timing']['marketing_activations'], JSON_PRETTY_PRINT)); ?></pre>
	<?php endif; ?>

	<?php
	$momentum = (array) ($payload['momentum'] ?? []);
	$trough = (array) ($momentum['trough'] ?? []);
	$obs = (array) ($momentum['observation'] ?? []);
	?>
	<h2><?php esc_html_e('Sales momentum', 'intersoccer-reports-rosters'); ?></h2>
	<?php if (!empty($trough['verdict']) && $trough['verdict'] === 'insufficient_after') : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e('AFTER window is incomplete — trough verdict is provisional. Wait until configured after_weeks of orders exist past campaign end.', 'intersoccer-reports-rosters'); ?></p></div>
	<?php endif; ?>
	<p>
		<strong><?php esc_html_e('Verdict:', 'intersoccer-reports-rosters'); ?></strong>
		<code><?php echo esc_html((string) ($trough['verdict'] ?? '—')); ?></code>
		<?php if (isset($trough['after_vs_before_orders_ratio']) && $trough['after_vs_before_orders_ratio'] !== null) : ?>
			— <?php echo esc_html(sprintf(/* translators: %s ratio */ __('After/before order-rate ratio: %s', 'intersoccer-reports-rosters'), (string) $trough['after_vs_before_orders_ratio'])); ?>
		<?php endif; ?>
	</p>
	<p class="description">
		<?php esc_html_e('Observation:', 'intersoccer-reports-rosters'); ?>
		<code><?php echo esc_html(($obs['before_start'] ?? '') . ' → ' . ($obs['after_end'] ?? '')); ?></code>
		|
		<?php esc_html_e('Latest order in window:', 'intersoccer-reports-rosters'); ?>
		<code><?php echo esc_html((string) ($obs['latest_order_at'] ?? '—')); ?></code>
	</p>
	<?php if (!empty($trough['notes'])) : ?>
		<ul>
			<?php foreach ((array) $trough['notes'] as $note) : ?>
				<li><?php echo esc_html((string) $note); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h3><?php esc_html_e('Trough phases (per-week equivalent)', 'intersoccer-reports-rosters'); ?></h3>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e('Phase', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Days', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Orders', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Orders / week', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Revenue', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Revenue / week', 'intersoccer-reports-rosters'); ?></th></tr></thead>
		<tbody>
		<?php foreach ((array) ($momentum['phases'] ?? []) as $row) : ?>
			<tr>
				<td><?php echo esc_html((string) ($row['label'] ?? $row['id'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['days'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['orders'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['orders_per_week_equiv'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['revenue_order_totals'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['revenue_per_week_equiv'] ?? '')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e('Weekly momentum', 'intersoccer-reports-rosters'); ?></h3>
	<table class="widefat striped">
		<thead><tr><th><?php esc_html_e('Week start', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('ISO week', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Orders', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Campaign coupon orders', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Line bookings', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Revenue', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Coupon revenue', 'intersoccer-reports-rosters'); ?></th></tr></thead>
		<tbody>
		<?php foreach ((array) ($momentum['weekly'] ?? []) as $row) : ?>
			<tr>
				<td><?php echo esc_html((string) ($row['week_start'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['iso_week'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['orders'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['coupon_orders'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['line_item_bookings'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['revenue_order_totals'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['coupon_revenue_order_totals'] ?? '')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if (!empty($momentum['daily'])) : ?>
		<details style="margin:1em 0;">
			<summary><?php esc_html_e('Daily zoom (before / during / after)', 'intersoccer-reports-rosters'); ?></summary>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e('Day', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Phase', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Orders', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Campaign coupon orders', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Revenue', 'intersoccer-reports-rosters'); ?></th><th><?php esc_html_e('Coupon revenue', 'intersoccer-reports-rosters'); ?></th></tr></thead>
				<tbody>
				<?php foreach ((array) $momentum['daily'] as $row) : ?>
					<tr>
						<td><?php
							$label = (string) ($row['day'] ?? '');
							if (!empty($row['day_name'])) {
								$label .= ' (' . $row['day_name'] . ')';
							}
							echo esc_html($label);
						?></td>
						<td><?php echo esc_html((string) ($row['phase'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['orders'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['coupon_orders'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['revenue_order_totals'] ?? '')); ?></td>
						<td><?php echo esc_html((string) ($row['coupon_revenue_order_totals'] ?? '')); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	<?php endif; ?>

	<h2><?php esc_html_e('Attribution', 'intersoccer-reports-rosters'); ?></h2>
	<table class="widefat striped">
		<thead><tr><th>Source</th><th>Orders</th><th>Revenue</th></tr></thead>
		<tbody>
		<?php foreach ((array) ($payload['attribution']['by_source'] ?? []) as $src => $row) : ?>
			<tr>
				<td><?php echo esc_html((string) $src); ?></td>
				<td><?php echo esc_html(is_array($row) ? (string) ($row['orders'] ?? '') : (string) $row); ?></td>
				<td><?php echo esc_html(is_array($row) ? (string) ($row['revenue'] ?? '') : '—'); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if (!empty($payload['attribution']['referrals'])) : ?>
		<h3><?php esc_html_e('Referral hosts', 'intersoccer-reports-rosters'); ?></h3>
		<table class="widefat striped">
			<thead><tr><th>Host</th><th>Orders</th><th>Revenue</th></tr></thead>
			<tbody>
			<?php foreach ((array) $payload['attribution']['referrals'] as $host => $row) : ?>
				<tr>
					<td><?php echo esc_html((string) $host); ?></td>
					<td><?php echo esc_html(is_array($row) ? (string) ($row['orders'] ?? '') : (string) $row); ?></td>
					<td><?php echo esc_html(is_array($row) ? (string) ($row['revenue'] ?? '') : '—'); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e('Booking mix', 'intersoccer-reports-rosters'); ?></h2>
	<?php foreach (['activity' => __('Activity', 'intersoccer-reports-rosters'), 'booking_type' => __('Booking type', 'intersoccer-reports-rosters'), 'day_length' => __('Day length', 'intersoccer-reports-rosters')] as $mix_key => $mix_label) : ?>
		<h3><?php echo esc_html($mix_label); ?></h3>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html($mix_label); ?></th><th>Bookings</th><th>Revenue</th></tr></thead>
			<tbody>
			<?php foreach ((array) ($payload['mix'][$mix_key] ?? []) as $name => $row) : ?>
				<tr>
					<td><?php echo esc_html((string) $name); ?></td>
					<td><?php echo esc_html((string) ($row['bookings'] ?? '')); ?></td>
					<td><?php echo esc_html((string) ($row['revenue'] ?? '')); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>

	<h2><?php esc_html_e('Demand destination', 'intersoccer-reports-rosters'); ?></h2>
	<table class="widefat striped">
		<thead><tr><th>Week / term</th><th>Bookings</th><th>Revenue</th></tr></thead>
		<tbody>
		<?php foreach ((array) ($payload['demand']['by_week'] ?? []) as $row) : ?>
			<tr>
				<td><?php echo esc_html((string) ($row['label'] ?? $row['key'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['bookings'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['revenue'] ?? '')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if (!empty($payload['demand']['target_scope_defined'])) : ?>
		<p><?php echo esc_html(sprintf(
			/* translators: 1: in-scope count 2: out-of-scope count */
			__('In-scope: %1$d · Out-of-scope: %2$d', 'intersoccer-reports-rosters'),
			(int) ($payload['demand']['in_scope_bookings'] ?? 0),
			(int) ($payload['demand']['out_of_scope_bookings'] ?? 0)
		)); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e('Regions', 'intersoccer-reports-rosters'); ?></h2>
	<table class="widefat striped">
		<thead><tr><th>Region</th><th>Bookings</th><th>Revenue</th></tr></thead>
		<tbody>
		<?php foreach ((array) ($payload['regions'] ?? []) as $name => $row) : ?>
			<tr>
				<td><?php echo esc_html($name === 'not_recorded' ? 'not recorded' : (string) $name); ?></td>
				<td><?php echo esc_html((string) ($row['bookings'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['revenue'] ?? '')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e('Venues', 'intersoccer-reports-rosters'); ?></h2>
	<table class="widefat striped">
		<thead><tr><th>Venue</th><th>Bookings</th><th>Revenue</th></tr></thead>
		<tbody>
		<?php foreach ((array) ($payload['venues'] ?? []) as $name => $row) : ?>
			<tr>
				<td><?php echo esc_html($name === 'not_recorded' ? 'not recorded' : (string) $name); ?></td>
				<td><?php echo esc_html((string) ($row['bookings'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['revenue'] ?? '')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e('Cohorts', 'intersoccer-reports-rosters'); ?></h2>
	<?php $cohorts = (array) ($payload['cohorts'] ?? []); ?>
	<table class="widefat striped">
		<thead><tr><th>Cohort</th><th>Orders</th><th>Families</th><th>Revenue</th></tr></thead>
		<tbody>
			<?php foreach (['new' => __('New family', 'intersoccer-reports-rosters'), 'existing' => __('Existing family', 'intersoccer-reports-rosters')] as $ck => $clabel) : ?>
				<?php $crow = (array) ($cohorts[$ck] ?? []); ?>
				<tr>
					<td><?php echo esc_html($clabel); ?></td>
					<td><?php echo esc_html((string) ($crow['orders'] ?? '')); ?></td>
					<td><?php echo esc_html((string) ($crow['families'] ?? '')); ?></td>
					<td><?php echo esc_html((string) ($crow['revenue'] ?? '')); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if (isset($cohorts['reliable']) && !$cohorts['reliable']) : ?>
		<p class="description"><?php esc_html_e('Cohort reliability flag is off (sparse customer IDs). Treat new/existing as approximate.', 'intersoccer-reports-rosters'); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e('Demographics', 'intersoccer-reports-rosters'); ?></h2>
	<?php $demo = (array) ($payload['demographics'] ?? []); ?>
	<ul>
		<li><?php echo esc_html(sprintf(
			/* translators: 1: male count 2: female count */
			__('Gender: %1$d male / %2$d female', 'intersoccer-reports-rosters'),
			(int) ($demo['gender']['male'] ?? 0),
			(int) ($demo['gender']['female'] ?? 0)
		)); ?></li>
		<li><?php echo esc_html(sprintf(/* translators: %s mean age */ __('Mean age: %s', 'intersoccer-reports-rosters'), (string) ($demo['mean_age'] ?? ''))); ?></li>
		<li><?php echo esc_html(sprintf(/* translators: %d count */ __('Aged 3–5: %d', 'intersoccer-reports-rosters'), (int) ($demo['aged_3_to_5'] ?? 0))); ?></li>
		<li><?php echo esc_html(sprintf(/* translators: %d count */ __('Girls Only bookings: %d', 'intersoccer-reports-rosters'), (int) ($demo['girls_only_bookings'] ?? 0))); ?></li>
	</ul>

	<h2><?php esc_html_e('Occupancy', 'intersoccer-reports-rosters'); ?></h2>
	<table class="widefat striped">
		<thead><tr><th>Week</th><th>Venue</th><th>Age</th><th>Before</th><th>During</th><th>Capacity</th><th>Occupancy</th></tr></thead>
		<tbody>
		<?php foreach ((array) ($payload['occupancy'] ?? []) as $row) : ?>
			<tr>
				<td><?php echo esc_html((string) ($row['camp_week'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['venue'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['age_group'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['booked_before'] ?? '')); ?></td>
				<td><?php echo esc_html((string) ($row['booked_during'] ?? '')); ?></td>
				<td><?php echo esc_html($row['capacity'] === null ? '—' : (string) $row['capacity']); ?></td>
				<td><?php echo esc_html((string) ($row['occupancy'] ?? 'capacity not set')); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * @param \InterSoccer\ReportsRosters\Data\Repositories\CampaignRepository $repo
 * @param \InterSoccer\ReportsRosters\Campaign\CampaignSummaryStore $store
 * @return void
 */
function intersoccer_campaign_analytics_maybe_export($repo, $store) {
	if (empty($_GET['export']) || empty($_GET['campaign_id'])) {
		return;
	}
	if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'isrr_campaign_export')) {
		return;
	}
	if (!current_user_can('manage_options')) {
		return;
	}

	$definition = $repo->find((int) $_GET['campaign_id']);
	if (!$definition) {
		return;
	}
	$hash = $definition->definition_hash();
	$status_set = implode(',', $definition->order_statuses);
	$summary = $store->get($definition->id, $hash, $status_set);
	if (!$summary || empty($summary['payload']) || !is_array($summary['payload'])) {
		$summary = $store->get_latest_ready($definition->id);
	}
	$payload = is_array($summary) ? ($summary['payload'] ?? null) : null;
	if (!is_array($payload)) {
		wp_die(esc_html__('No summary to export. Refresh the campaign first (exports use the cached summary, not a live recompute).', 'intersoccer-reports-rosters'));
	}

	$type = sanitize_key(wp_unslash($_GET['export']));
	$slug = sanitize_title($definition->name ?: 'campaign');

	if ($type === 'xlsx') {
		$exporter = new \InterSoccer\ReportsRosters\Campaign\Export\ExcelExporter();
		$binary = $exporter->build($payload);
		if (substr($binary, 0, 2) === 'PK') {
			intersoccer_campaign_send_download(
				$binary,
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'campaign-' . $slug . '.xlsx'
			);
		}
		intersoccer_campaign_send_download($binary, 'text/csv; charset=utf-8', 'campaign-' . $slug . '.csv');
	}

	if ($type === 'docx') {
		$exporter = new \InterSoccer\ReportsRosters\Campaign\Export\DocxExporter();
		$file = $exporter->build($payload);
		intersoccer_campaign_send_download(
			$file['body'],
			$file['mime'],
			'campaign-' . $slug . '.' . $file['ext']
		);
	}
}

/**
 * Stream a generated export as a download.
 *
 * Discards buffered admin output so the body contains only the file bytes.
 *
 * @param string $body     File contents.
 * @param string $mime     Content type.
 * @param string $filename Download filename.
 * @return void
 */
function intersoccer_campaign_send_download($body, $mime, $filename) {
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	nocache_headers();
	header('Content-Type: ' . $mime);
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Content-Length: ' . strlen($body));
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $body;
	exit;
}
