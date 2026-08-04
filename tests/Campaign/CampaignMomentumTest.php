<?php
/**
 * Sales momentum trough / rate regression tests.
 *
 * @package InterSoccer\ReportsRosters\Tests\Campaign
 */

namespace InterSoccer\ReportsRosters\Tests\Campaign;

use InterSoccer\ReportsRosters\Campaign\CampaignDefinition;
use InterSoccer\ReportsRosters\Campaign\LineItem;
use InterSoccer\ReportsRosters\Campaign\Metrics\CampaignMetricsAggregator;
use InterSoccer\ReportsRosters\Tests\TestCase;

class CampaignMomentumTest extends TestCase {

	public function test_observation_window_defaults() {
		$campaign = CampaignDefinition::from_array([
			'id' => 1,
			'name' => 'SWISS15',
			'start_datetime' => '2026-07-30 00:00:00',
			'end_datetime' => '2026-08-02 22:00:00',
			'coupon_codes' => ['swiss15'],
		]);
		$obs = $campaign->observation_window();
		$this->assertSame(4, $obs['before_weeks']);
		$this->assertSame(2, $obs['after_weeks']);
		$this->assertSame(28, $obs['before_days']);
		$this->assertSame(14, $obs['after_days']);
		$this->assertSame('2026-07-02 00:00:00', $obs['before_start']);
		$this->assertSame('2026-07-20 00:00:00', $obs['daily_start']);
		// Daily pad starts earlier than before_start? No — before is Jul 2, daily is Jul 20; obs start = before.
		$this->assertSame('2026-07-02 00:00:00', $obs['start']);
		$this->assertSame('2026-08-16 22:00:00', $obs['after_end']);
	}

	public function test_trough_insufficient_after_when_coverage_short() {
		$campaign = $this->swiss_campaign();
		$agg = new CampaignMetricsAggregator();
		$lines = array_merge(
			$this->orders_on_day('2026-07-15', 10, 100.0),
			$this->orders_on_day('2026-07-31', 20, 200.0, true),
			$this->orders_on_day('2026-08-03', 5, 50.0)
		);
		$result = $agg->momentum($campaign, $lines, [
			'observation_window' => $campaign->observation_window(),
		]);
		$this->assertFalse($result['observation']['after_complete']);
		$this->assertSame('insufficient_after', $result['trough']['verdict']);

		$by_id = [];
		foreach ($result['phases'] as $p) {
			$by_id[$p['id']] = $p;
		}
		$this->assertSame(28, $by_id['before']['days']);
		$this->assertSame(4, $by_id['during']['days']);
		$this->assertSame(14, $by_id['after']['days']);
		$this->assertSame(10, $by_id['before']['orders']);
		$this->assertSame(20, $by_id['during']['orders']);
		$this->assertSame(5, $by_id['after']['orders']);
		$this->assertEqualsWithDelta(2.5, $by_id['before']['orders_per_week_equiv'], 0.01);
		$this->assertEqualsWithDelta(35.0, $by_id['during']['orders_per_week_equiv'], 0.01);
		$this->assertEqualsWithDelta(2.5, $by_id['after']['orders_per_week_equiv'], 0.01);
	}

	public function test_trough_shifting_when_after_complete_and_dip() {
		$campaign = $this->swiss_campaign();
		$agg = new CampaignMetricsAggregator();
		$obs = $campaign->observation_window();
		$lines = array_merge(
			$this->orders_on_day('2026-07-10', 28, 100.0),
			$this->orders_on_day('2026-07-31', 40, 200.0, true),
			$this->orders_on_day('2026-08-10', 7, 50.0)
		);
		// Force after_complete by adding an order at after_end.
		$lines[] = $this->line(9999, $obs['after_end'], 10.0, false);

		$result = $agg->momentum($campaign, $lines, ['observation_window' => $obs]);
		$this->assertTrue($result['observation']['after_complete']);
		$this->assertSame('shifting', $result['trough']['verdict']);
		$this->assertNotNull($result['trough']['after_vs_before_orders_ratio']);
		$this->assertLessThan(1.0, (float) $result['trough']['after_vs_before_orders_ratio']);
	}

	public function test_trough_generating_when_after_holds() {
		$campaign = $this->swiss_campaign();
		$agg = new CampaignMetricsAggregator();
		$obs = $campaign->observation_window();
		$lines = array_merge(
			$this->orders_on_day('2026-07-10', 14, 100.0),
			$this->orders_on_day('2026-07-31', 20, 200.0, true),
			$this->orders_on_day('2026-08-10', 28, 50.0)
		);
		$lines[] = $this->line(8888, $obs['after_end'], 10.0, false);

		$result = $agg->momentum($campaign, $lines, ['observation_window' => $obs]);
		$this->assertTrue($result['observation']['after_complete']);
		$this->assertSame('generating', $result['trough']['verdict']);
	}

	public function test_daily_splits_expiry_day_and_coupon_overlay() {
		$campaign = $this->swiss_campaign();
		$agg = new CampaignMetricsAggregator();
		$lines = [
			$this->line(1, '2026-08-02 12:00:00', 100.0, true),
			$this->line(2, '2026-08-02 23:00:00', 200.0, false),
		];
		$result = $agg->momentum($campaign, $lines, [
			'observation_window' => $campaign->observation_window(),
		]);
		$aug2 = array_values(array_filter($result['daily'], static function ($r) {
			return ($r['day'] ?? '') === '2026-08-02';
		}));
		$this->assertCount(2, $aug2);
		$phases = array_column($aug2, 'phase');
		$this->assertContains('during', $phases);
		$this->assertContains('after', $phases);
		foreach ($aug2 as $row) {
			if ($row['phase'] === 'during') {
				$this->assertSame(1, $row['orders']);
				$this->assertSame(1, $row['coupon_orders']);
			}
			if ($row['phase'] === 'after') {
				$this->assertSame(1, $row['orders']);
				$this->assertSame(0, $row['coupon_orders']);
			}
		}
	}

	public function test_aggregate_includes_momentum_key() {
		$campaign = $this->swiss_campaign();
		$agg = new CampaignMetricsAggregator();
		$payload = $agg->aggregate($campaign, [], [], [
			'source_id' => 'orders',
			'gate' => ['ok' => true, 'errors' => [], 'warnings' => []],
			'observation_lines' => $this->orders_on_day('2026-07-31', 3, 100.0, true),
			'observation_window' => $campaign->observation_window(),
		]);
		$this->assertArrayHasKey('momentum', $payload);
		$this->assertArrayHasKey('phases', $payload['momentum']);
		$this->assertContains('momentum_after_incomplete', $payload['warnings']);
	}

	/**
	 * @return CampaignDefinition
	 */
	private function swiss_campaign() {
		return CampaignDefinition::from_array([
			'id' => 42,
			'name' => 'SWISS15',
			'start_datetime' => '2026-07-30 00:00:00',
			'end_datetime' => '2026-08-02 22:00:00',
			'coupon_codes' => ['swiss15'],
			'momentum_before_weeks' => 4,
			'momentum_after_weeks' => 2,
		]);
	}

	/**
	 * @param string $day Y-m-d
	 * @param int    $count
	 * @param float  $order_total
	 * @param bool   $coupon
	 * @return LineItem[]
	 */
	private function orders_on_day($day, $count, $order_total, $coupon = false) {
		$out = [];
		for ($i = 0; $i < $count; $i++) {
			$oid = crc32($day . '-' . $i);
			if ($oid < 0) {
				$oid = -$oid;
			}
			$out[] = $this->line($oid + ($coupon ? 100000 : 0), $day . ' 12:00:00', $order_total, $coupon);
		}
		return $out;
	}

	/**
	 * @param int    $oid
	 * @param string $ts
	 * @param float  $order_total
	 * @param bool   $coupon
	 * @return LineItem
	 */
	private function line($oid, $ts, $order_total, $coupon) {
		return new LineItem([
			'order_id' => (int) $oid,
			'order_item_id' => (int) $oid,
			'booking_timestamp' => $ts,
			'order_status' => 'wc-completed',
			'order_total' => $order_total,
			'line_total' => $order_total,
			'used_campaign_coupon' => $coupon,
			'coupon_codes' => $coupon ? ['swiss15'] : [],
			'customer_id' => (int) $oid,
			'billing_email' => 'u' . $oid . '@example.com',
			'activity_type' => 'camp',
			'girls_only' => 0,
			'booking_type' => 'full-week',
			'day_length' => 'full-day',
			'venue' => 'Vessy',
			'region' => 'Geneva',
			'age_group' => '6-9',
			'camp_week' => 'summer_week_6',
			'season' => 'Summer Camps 2026',
			'gender' => 'male',
			'age' => 7,
			'attribution' => [],
		]);
	}
}
