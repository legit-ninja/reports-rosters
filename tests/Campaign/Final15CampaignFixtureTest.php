<?php
/**
 * FINAL15 campaign analytics regression (Section 7 goldens).
 *
 * @package InterSoccer\ReportsRosters\Tests\Campaign
 */

namespace InterSoccer\ReportsRosters\Tests\Campaign;

use InterSoccer\ReportsRosters\Campaign\CampaignBaseline;
use InterSoccer\ReportsRosters\Campaign\CampaignDefinition;
use InterSoccer\ReportsRosters\Campaign\Export\ExportAllowlist;
use InterSoccer\ReportsRosters\Campaign\FacetNormalizer;
use InterSoccer\ReportsRosters\Campaign\HposGuard;
use InterSoccer\ReportsRosters\Campaign\Metrics\CampaignMetricsAggregator;
use InterSoccer\ReportsRosters\Tests\TestCase;

class Final15CampaignFixtureTest extends TestCase {

	public function test_baseline_matched_prior_final15_window() {
		$baseline = CampaignBaseline::derive(
			'2026-07-16 00:00:00',
			'2026-07-19 23:59:59',
			'matched_prior'
		);
		$this->assertSame('2026-07-09 00:00:00', $baseline['start']);
		$this->assertSame('2026-07-12 23:59:59', $baseline['end']);
		$this->assertSame($baseline['length_seconds'], CampaignBaseline::derive(
			'2026-07-16 00:00:00',
			'2026-07-19 23:59:59',
			'matched_prior'
		)['length_seconds']);
	}

	public function test_date_only_bounds_normalize_to_full_days() {
		$this->assertSame('2026-07-16 00:00:00', \InterSoccer\ReportsRosters\Campaign\CampaignTimezone::normalize_bound('2026-07-16', 'start'));
		$this->assertSame('2026-07-19 23:59:59', \InterSoccer\ReportsRosters\Campaign\CampaignTimezone::normalize_bound('2026-07-19', 'end'));
		$baseline = CampaignBaseline::derive(
			\InterSoccer\ReportsRosters\Campaign\CampaignTimezone::normalize_bound('2026-07-16', 'start'),
			\InterSoccer\ReportsRosters\Campaign\CampaignTimezone::normalize_bound('2026-07-19', 'end'),
			'matched_prior'
		);
		$this->assertSame('2026-07-09 00:00:00', $baseline['start']);
		$this->assertSame('2026-07-12 23:59:59', $baseline['end']);
	}

	public function test_facet_normalizer_master_sql_edge_cases() {
		$n = new FacetNormalizer();
		$this->assertSame('full-week', $n->normalize_booking_type('Ganze Woche'));
		$this->assertSame('full-week', $n->normalize_booking_type('ganze-woche'));
		$this->assertSame('single-days', $n->normalize_booking_type('Einzelne Tage'));
		$this->assertSame('half-day-mini', $n->normalize_day_length('Demi-journée 3-5', ''));
		$this->assertSame('Geneva', $n->normalize_region('Genf'));
		$this->assertSame('Zug', $n->normalize_region('Zoug'));
		$this->assertSame('summer_week_5', $n->normalize_camp_week('camp_semaine-dete-5-20-24-juillet'));
		$this->assertSame('summer_week_6', $n->normalize_camp_week('semaine-dete-6'));
		$this->assertSame('summer_week_5', $n->normalize_camp_week("Semaine d'été 5 : 20-24 juillet (5 jours)"));
		$this->assertSame('Vessy', $n->normalize_venue('Geneva - Stade de Vessy (Champel)'));
		$this->assertSame('FC Seefeld', $n->normalize_venue('Zurich East - FC Seefeld'));
		$this->assertStringStartsWith('unmapped:', $n->normalize_booking_type('Weird Promo Pack'));
	}

	public function test_custom_baseline_rejects_length_mismatch() {
		$this->expectException(\InvalidArgumentException::class);
		CampaignBaseline::derive(
			'2026-07-16 00:00:00',
			'2026-07-19 23:59:59',
			'custom',
			'2026-07-01 00:00:00',
			'2026-07-02 00:00:00'
		);
	}

	public function test_facet_normalizer_merges_french_summer_week_5() {
		$n = new FacetNormalizer();
		$en = $n->normalize_camp_week('Summer Week 5: July 20-24 (5 days)');
		$fr = $n->normalize_camp_week('Semaine 5 d\'été: 20-24 juillet');
		// French pattern may use semaine N
		$fr2 = $n->normalize_camp_week('Camps d\'été — Semaine 5');
		$this->assertSame('summer_week_5', $en);
		$this->assertSame('summer_week_5', $fr2);
		$this->assertSame($en, $n->normalize_camp_week('Summer Week 5'));
	}

	public function test_girls_only_html_entity_activity() {
		$n = new FacetNormalizer();
		$this->assertTrue($n->text_indicates_girls_only('Camp, Girls&#039; Only'));
		$facets = $n->normalize_from_meta(['Activity Type' => 'Camp, Girls&#039; Only']);
		$this->assertSame(1, $facets['girls_only']);
		$this->assertSame('camp', $facets['activity_type']);
	}

	public function test_final15_headline_and_traps() {
		$campaign = CampaignDefinition::from_array([
			'id' => 1,
			'name' => 'FINAL15',
			'start_datetime' => '2026-07-16 00:00:00',
			'end_datetime' => '2026-07-19 23:59:59',
			'coupon_codes' => ['FINAL15'],
			'baseline_mode' => 'matched_prior',
			'order_statuses' => ['wc-completed', 'wc-processing'],
		]);

		$agg = new CampaignMetricsAggregator();
		$campaign_lines = Final15FixtureFactory::campaign_lines();
		$baseline_lines = Final15FixtureFactory::baseline_lines();

		$this->assertCount(85, $campaign_lines);
		$this->assertCount(49, $baseline_lines);

		$payload = $agg->aggregate($campaign, $campaign_lines, $baseline_lines, [
			'source_id' => 'orders',
			'gate' => ['ok' => true, 'errors' => [], 'warnings' => []],
			'coupon_usage_counts' => ['FINAL15' => 25],
			'prior_family_keys' => Final15FixtureFactory::prior_family_keys(),
		]);

		$h = $payload['headline'];
		$this->assertSame(74, $h['orders']);
		$this->assertSame(85, $h['line_item_bookings']);
		$this->assertEqualsWithDelta(26405.75, $h['revenue_order_totals'], 0.05);
		$this->assertEqualsWithDelta(26486.75, $h['revenue_line_totals'], 0.05);
		$this->assertSame(41, $h['baseline']['orders']);
		$this->assertSame(49, $h['baseline']['line_item_bookings']);
		$this->assertEqualsWithDelta(18020.15, $h['baseline']['revenue_order_totals'], 0.05);
		// Exact (74-41)/41 = 80.49% → rounds to 80; published FINAL15 prose used +81%.
		$this->assertContains($h['pct_change']['orders'], [80, 81]);
		$this->assertSame(73, $h['pct_change']['line_item_bookings']);
		$this->assertSame(47, $h['pct_change']['revenue_order_totals']);

		// Trap: regions sum to 85 including not_recorded
		$region_sum = 0;
		foreach ($payload['regions'] as $row) {
			$region_sum += (int) $row['bookings'];
		}
		$this->assertSame(85, $region_sum);
		$this->assertArrayHasKey('not_recorded', $payload['regions']);
		$this->assertSame(2, $payload['regions']['not_recorded']['bookings']);

		// Trap: Summer Week 5 = 42 after FR/EN merge (fixture already normalized)
		$this->assertSame(42, $payload['demand']['by_week']['summer_week_5']['bookings']);

		$coupon = $payload['coupons']['FINAL15'];
		$this->assertSame(25, $coupon['orders']);
		$this->assertSame(31, $coupon['line_items']);
		$this->assertEqualsWithDelta(9710.00, $coupon['revenue'], 0.05);
		$this->assertSame(34, $coupon['attach_rate']);
		$this->assertSame(25, $coupon['usage_count_meta']);
		$this->assertNull($coupon['usage_count_warning']);
		$this->assertSame('2026-07-19 23:35:54', $coupon['last_redemption']);

		$mix = $payload['mix']['activity'];
		$this->assertSame(78, $mix['camp']['bookings']);
		$this->assertSame(3, $mix['girls_only']['bookings']);
		$this->assertSame(4, $mix['course']['bookings']);

		// Section E mix — booking type + day length (MASTER goldens).
		$bt = $payload['mix']['booking_type'];
		$this->assertSame(46, $bt['full-week']['bookings']);
		$this->assertSame(33, $bt['single-days']['bookings']);
		$this->assertSame(4, $bt['full-term']['bookings']);
		$dl = $payload['mix']['day_length'];
		$this->assertSame(41, $dl['full-day']['bookings']);
		$this->assertSame(29, $dl['half-day-mini']['bookings']);

		// Timing BY DAY line items (MASTER E).
		$timing = $payload['timing']['by_day'];
		$this->assertSame(13, $timing['2026-07-16']['line_items']);
		$this->assertSame(15, $timing['2026-07-17']['line_items']);
		$this->assertSame(16, $timing['2026-07-18']['line_items']);
		$this->assertSame(41, $timing['2026-07-19']['line_items']);
		$this->assertSame(26, $timing['2026-07-19']['coupon_line_items']);

		// Cohorts 43 new / 31 existing orders.
		$this->assertSame(43, $payload['cohorts']['new']['orders']);
		$this->assertSame(31, $payload['cohorts']['existing']['orders']);

		// Top venues (MASTER E).
		$this->assertGreaterThanOrEqual(17, (int) ($payload['venues']['Vessy']['bookings'] ?? 0));
		$this->assertGreaterThanOrEqual(14, (int) ($payload['venues']['FC Seefeld']['bookings'] ?? 0));

		$this->assertSame(77, $payload['demographics']['gender']['male']);
		$this->assertSame(8, $payload['demographics']['gender']['female']);
		$this->assertEqualsWithDelta(5.99, $payload['demographics']['mean_age'], 0.05);
		$this->assertSame(45, $payload['demographics']['aged_3_to_5']);

		$this->assertStringContainsString('last-touch', $payload['attribution_limitation']);
		$this->assertNotEmpty($payload['data_notes']);
	}

	public function test_privacy_allowlist_strips_forbidden() {
		$row = ExportAllowlist::filter_row([
			'derived_age' => 6,
			'gender' => 'male',
			'first_name' => 'Secret',
			'dob' => '2019-01-01',
			'billing_email' => 'x@y.com',
			'avs_number' => '756.1234',
			'venue' => 'Vessy',
			'medical_conditions' => 'asthma',
		]);
		$this->assertArrayHasKey('derived_age', $row);
		$this->assertArrayHasKey('venue', $row);
		$this->assertArrayNotHasKey('first_name', $row);
		$this->assertArrayNotHasKey('dob', $row);
		$this->assertArrayNotHasKey('billing_email', $row);
		$this->assertArrayNotHasKey('avs_number', $row);
		$this->assertArrayNotHasKey('medical_conditions', $row);
	}

	public function test_occupancy_capacity_not_set_never_zero() {
		$campaign = CampaignDefinition::from_array([
			'id' => 1,
			'name' => 'T',
			'start_datetime' => '2026-07-16 00:00:00',
			'end_datetime' => '2026-07-19 23:59:59',
			'coupon_codes' => [],
			'capacity_overrides' => [],
		]);
		$agg = new CampaignMetricsAggregator();
		$lines = Final15FixtureFactory::campaign_lines();
		$payload = $agg->aggregate($campaign, array_slice($lines, 0, 5), [], [
			'source_id' => 'orders',
			'gate' => ['ok' => true, 'errors' => [], 'warnings' => []],
		]);
		foreach ($payload['occupancy'] as $row) {
			$this->assertSame('capacity not set', $row['occupancy']);
			$this->assertNull($row['capacity']);
		}
	}

	public function test_hpos_guard_legacy_default() {
		$r = HposGuard::assert_legacy();
		$this->assertTrue($r['ok']);
	}

	public function test_usage_count_disagreement_warning() {
		$campaign = CampaignDefinition::from_array([
			'id' => 1,
			'name' => 'FINAL15',
			'start_datetime' => '2026-07-16 00:00:00',
			'end_datetime' => '2026-07-19 23:59:59',
			'coupon_codes' => ['FINAL15'],
		]);
		$agg = new CampaignMetricsAggregator();
		$payload = $agg->aggregate(
			$campaign,
			Final15FixtureFactory::campaign_lines(),
			Final15FixtureFactory::baseline_lines(),
			[
				'source_id' => 'orders',
				'gate' => ['ok' => true, 'errors' => [], 'warnings' => []],
				'coupon_usage_counts' => ['FINAL15' => 99],
			]
		);
		$this->assertNotNull($payload['coupons']['FINAL15']['usage_count_warning']);
	}
}
