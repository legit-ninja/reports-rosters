<?php
/**
 * Seasonless camp terms, with the legacy season-week form left unchanged.
 */

use InterSoccer\ReportsRosters\Campaign\FacetNormalizer;
use InterSoccer\ReportsRosters\Services\RosterListingService;
use PHPUnit\Framework\TestCase;

class CampTermFormatTest extends TestCase {
	protected function tearDown(): void {
		unset(
			$GLOBALS['intersoccer_test_get_posts_callback'],
			$GLOBALS['intersoccer_test_post_titles'],
			$GLOBALS['intersoccer_test_wc_order_item_meta']
		);
		if (class_exists('InterSoccerRRTestMeta')) {
			InterSoccerRRTestMeta::$data = [];
		}
		parent::tearDown();
	}

	public function test_new_human_form_parses_week_dates_and_day_count_in_any_case() {
		$plain = intersoccer_reports_parse_camp_term_details(
			'Week 1: March 22-25 (4 days)',
			'Easter 2026'
		);
		$this->assertSame(1, $plain['week']);
		$this->assertSame(4, $plain['day_count']);
		$this->assertSame('2026-03-22', $plain['start_date']);
		$this->assertSame('2026-03-25', $plain['end_date']);

		$shouted = intersoccer_reports_parse_camp_term_details(
			'WEEK 1: MARCH 22-25 (4 DAYS CAMPS)',
			'Easter 2026'
		);
		$this->assertSame(1, $shouted['week']);
		$this->assertSame(4, $shouted['day_count']);
		$this->assertSame('2026-03-22', $shouted['start_date']);
		$this->assertSame('2026-03-25', $shouted['end_date']);

		$singular = intersoccer_reports_parse_camp_term_details(
			'week 2: march 22-25 (4 day extra)',
			'Easter 2026'
		);
		$this->assertSame(2, $singular['week']);
		$this->assertSame(4, $singular['day_count']);
		$this->assertSame('2026-03-22', $singular['start_date']);
		$this->assertSame('2026-03-25', $singular['end_date']);
	}

	public function test_new_slugs_parse_with_and_without_a_trailing_word() {
		foreach (['week-1-march-22-25-4-days', 'week-1-march-22-25-4-days-camps'] as $slug) {
			$parsed = intersoccer_reports_parse_camp_term_details($slug, 'Easter 2026');
			$this->assertSame(1, $parsed['week'], $slug);
			$this->assertSame(4, $parsed['day_count'], $slug);
			$this->assertSame('2026-03-22', $parsed['start_date'], $slug);
			$this->assertSame('2026-03-25', $parsed['end_date'], $slug);
		}

		$upper = intersoccer_reports_parse_camp_term_details('WEEK-1-MARCH-22-25-4-DAYS', 'Easter 2026');
		$this->assertSame('2026-03-22', $upper['start_date']);
		$this->assertSame('2026-03-25', $upper['end_date']);
		$this->assertSame(4, $upper['day_count']);
	}

	public function test_legacy_season_week_slugs_including_french_and_german_keep_their_dates() {
		list($start, $end) = intersoccer_parse_camp_dates_fixed(
			'summer-week-4-july-13-july-17-5-days',
			'Summer Camps 2026'
		);
		$this->assertSame('2026-07-13', $start);
		$this->assertSame('2026-07-17', $end);

		$same_month = intersoccer_reports_parse_camp_term_details(
			'summer-week-1-july-13-17-5-days',
			'Summer Camps 2026'
		);
		$this->assertSame(1, $same_month['week']);
		$this->assertSame(5, $same_month['day_count']);
		$this->assertSame('2026-07-13', $same_month['start_date']);
		$this->assertSame('2026-07-17', $same_month['end_date']);

		list($fr_start, $fr_end) = intersoccer_parse_camp_dates_fixed(
			'semaine-dete-4-13-17-juillet-5-jours',
			'Summer 2026'
		);
		$this->assertSame('2026-07-13', $fr_start);
		$this->assertSame('2026-07-17', $fr_end);

		list($de_start, $de_end) = intersoccer_parse_camp_dates_fixed(
			'sommer-woche-4-13-17-juli-5-tage',
			'Summer 2026'
		);
		$this->assertSame('2026-07-13', $de_start);
		$this->assertSame('2026-07-17', $de_end);

		$human = intersoccer_reports_parse_camp_term_details(
			'Summer Week 5: July 20-24 (5 days)',
			'Summer 2026'
		);
		$this->assertSame(5, $human['week']);
		$this->assertSame(5, $human['day_count']);
		$this->assertSame('2026-07-20', $human['start_date']);
		$this->assertSame('2026-07-24', $human['end_date']);
	}

	public function test_date_meta_wins_over_a_parseable_term() {
		$vid = 87011;
		update_post_meta($vid, '_camp_start_date', '2026-04-06');
		update_post_meta($vid, '_camp_end_date', '2026-04-09');

		$from_variation = intersoccer_reports_resolve_camp_schedule(
			$vid,
			0,
			'Week 1: March 22-25 (4 days)',
			'Easter 2026'
		);
		$this->assertSame('2026-04-06', $from_variation[0]);
		$this->assertSame('2026-04-09', $from_variation[1]);
		$this->assertSame('variation_meta', $from_variation[3]);

		$GLOBALS['intersoccer_test_wc_order_item_meta'] = [
			87021 => [
				'Camp Start Date' => '2026-08-03',
				'Camp End Date' => '2026-08-07',
			],
		];
		$from_item = intersoccer_reports_resolve_camp_schedule(
			0,
			87021,
			'week-1-march-22-25-4-days',
			'Easter 2026'
		);
		$this->assertSame('2026-08-03', $from_item[0]);
		$this->assertSame('2026-08-07', $from_item[1]);
		$this->assertSame('order_item_meta', $from_item[3]);
	}

	public function test_derive_season_uses_the_season_field_and_not_a_new_style_fragment() {
		$with_season = $this->deriveSeason([
			'season' => 'Easter 2026',
			'camp_terms' => 'WEEK 1: MARCH 22-25 (4 DAYS)',
			'product_name' => 'Summer Sample Camp',
		]);
		$this->assertSame('Easter 2026', $with_season);
		$this->assertStringNotContainsString('MARCH 22', $with_season);

		$attribute_only = $this->deriveSeason([
			'season' => 'Autumn',
			'camp_terms' => 'week-1-march-22-25-4-days',
			'product_name' => 'Spring leftover',
		]);
		$this->assertSame('Autumn', $attribute_only);

		$from_legacy_term = $this->deriveSeason([
			'season' => '',
			'camp_terms' => 'summer-week-1-july-13-17-5-days',
			'product_name' => '',
		]);
		$this->assertSame('Summer', $from_legacy_term);

		$no_fragment = $this->deriveSeason([
			'season' => '',
			'camp_terms' => 'Week 1: March 22-25 (4 days)',
			'product_name' => '',
		]);
		$this->assertSame('N/A', $no_fragment);
		$this->assertStringNotContainsString('MARCH', strtoupper($no_fragment));

		$slug_fragment = $this->deriveSeason([
			'season' => 'N/A',
			'camp_terms' => 'week-1-march-22-25-4-days-camps',
			'product_name' => '',
		]);
		$this->assertSame('N/A', $slug_fragment);
		$this->assertNotSame('Week', $slug_fragment);
	}

	public function test_facet_week_key_uses_the_season_field_for_a_seasonless_term() {
		$n = new FacetNormalizer();
		$facets = $n->normalize_from_meta([
			'Camp Terms' => 'Week 1: March 22-25 (4 days)',
			'pa_program-season' => 'Easter',
			'_product_name' => 'Summer Sample Camp',
		]);
		$this->assertSame('easter_week_1', $facets['camp_week']);
		$this->assertSame('Easter', $facets['season']);

		$slug = $n->normalize_from_meta([
			'attribute_pa_camp-terms' => 'week-1-march-22-25-4-days-camps',
			'Season' => 'Autumn 2026',
		]);
		$this->assertSame('autumn_week_1', $slug['camp_week']);

		$no_season = $n->normalize_camp_week('Week 1: March 22-25 (4 days)');
		$this->assertSame('week_1', $no_season);
		$this->assertNotSame('summer_week_1', $no_season);

		$this->assertSame('summer_week_5', $n->normalize_camp_week('Summer Week 5: July 20-24 (5 days)'));
		$this->assertSame('summer_week_5', $n->normalize_camp_week('camp_semaine-dete-5-20-24-juillet'));
		$this->assertSame('summer_week_4', $n->normalize_camp_week('semaine-dete-4-13-17-juillet-5-jours'));
		$this->assertSame(
			'summer_week_5',
			$n->normalize_camp_week("Semaine d'été 5: 20-24 juillet (5 jours)")
		);
	}

	public function test_missing_dates_notice_flags_only_camps_with_neither_meta_nor_a_parseable_term() {
		$GLOBALS['intersoccer_test_post_titles'] = [
			901 => 'Sample Camp A',
			902 => 'Sample Camp B',
			903 => 'Sample Camp C',
			904 => 'Sample Course A',
		];
		$GLOBALS['intersoccer_test_get_posts_callback'] = static function () {
			return [901, 902, 903, 904];
		};
		InterSoccerRRTestMeta::$data = [
			901 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
			],
			902 => [
				'_intersoccer_product_type' => 'camp',
				'_camp_start_date' => '2026-03-22',
				'_camp_end_date' => '2026-03-25',
				'attribute_pa_camp-terms' => 'not-a-date',
			],
			903 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'week-1-march-22-25-4-days',
				'attribute_pa_program-season' => 'Easter 2026',
			],
			904 => [
				'_intersoccer_product_type' => 'course',
			],
		];

		$rows = intersoccer_reports_list_camp_variations_missing_dates();
		$ids = array_column($rows, 'variation_id');
		$this->assertSame([901], $ids);
		$this->assertTrue(intersoccer_reports_camp_variation_missing_dates(901));
		$this->assertFalse(intersoccer_reports_camp_variation_missing_dates(902));
		$this->assertFalse(intersoccer_reports_camp_variation_missing_dates(903));
		$this->assertFalse(intersoccer_reports_camp_variation_missing_dates(904));

		$notice = intersoccer_reports_render_camp_missing_dates_notice();
		$this->assertStringContainsString('Camp variations missing dates', $notice);
		$this->assertStringContainsString('Sample Camp A', $notice);
		$this->assertStringContainsString('variation 901', $notice);
		$this->assertStringNotContainsString('Sample Camp B', $notice);
		$this->assertStringNotContainsString('Sample Camp C', $notice);
		$this->assertStringNotContainsString('Sample Course A', $notice);

		$GLOBALS['intersoccer_test_get_posts_callback'] = static function () {
			return [902];
		};
		$this->assertSame('', intersoccer_reports_render_camp_missing_dates_notice());
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function deriveSeason(array $row): string {
		$service = (new ReflectionClass(RosterListingService::class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod(RosterListingService::class, 'deriveSeasonFromCamp');
		$method->setAccessible(true);

		return (string) $method->invoke($service, $row);
	}
}
