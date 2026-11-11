<?php
/**
 * Seasonless camp terms, with the legacy season-week form left unchanged.
 */

use InterSoccer\ReportsRosters\Campaign\FacetNormalizer;
use InterSoccer\ReportsRosters\Services\RosterListingService;
use PHPUnit\Framework\TestCase;

class CampTermFormatTest extends TestCase {
	/** @var array<string,mixed>|null */
	private $lastGetPostsArgs;

	protected function tearDown(): void {
		$this->lastGetPostsArgs = null;
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
		$test = $this;
		$GLOBALS['intersoccer_test_get_posts_callback'] = static function ($args) use ($test) {
			$test->lastGetPostsArgs = $args;
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
		$this->assertMissingDatesQueryShape();
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

	public function test_missing_dates_notice_includes_a_high_id_past_the_old_ascending_window() {
		$statuses = [];
		$meta = [];
		for ($id = 20001; $id <= 20450; $id++) {
			$statuses[$id] = 'publish';
			$meta[$id] = [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
			];
		}
		$statuses[99000] = 'publish';
		$meta[99000] = [
			'_intersoccer_product_type' => 'camp',
			'attribute_pa_camp-terms' => 'TBD',
		];
		$statuses[99001] = 'private';
		$meta[99001] = [
			'_intersoccer_product_type' => 'camp',
			'attribute_pa_camp-terms' => 'TBD',
		];
		$statuses[99002] = 'draft';
		$meta[99002] = [
			'_intersoccer_product_type' => 'camp',
			'attribute_pa_camp-terms' => 'TBD',
		];
		InterSoccerRRTestMeta::$data = $meta;
		$this->stubVariationPosts($statuses);

		$rows = intersoccer_reports_list_camp_variations_missing_dates();
		$this->assertMissingDatesQueryShape();
		$ids = array_column($rows, 'variation_id');
		$this->assertSame(451, count($ids));
		$this->assertSame([99000, 20450, 20449], array_slice($ids, 0, 3));
		$this->assertContains(20411, $ids);
		$this->assertNotContains(99001, $ids);
		$this->assertNotContains(99002, $ids);

		$notice = intersoccer_reports_render_camp_missing_dates_notice();
		$this->assertStringContainsString('Showing 40 of 451', $notice);
		$this->assertStringContainsString('variation 99000)', $notice);
		$this->assertStringContainsString('variation 20412)', $notice);
		$this->assertStringNotContainsString('variation 20411)', $notice);
		$this->assertStringNotContainsString('variation 99001)', $notice);
		$this->assertStringNotContainsString('variation 99002)', $notice);
	}

	public function test_missing_dates_notice_lists_published_variations_only() {
		InterSoccerRRTestMeta::$data = [
			701 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
			],
			702 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
			],
			703 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
			],
		];
		$this->stubVariationPosts([
			701 => 'publish',
			702 => 'private',
			703 => 'draft',
		]);

		$rows = intersoccer_reports_list_camp_variations_missing_dates();
		$this->assertMissingDatesQueryShape();
		$this->assertSame([701], array_column($rows, 'variation_id'));
		$this->assertStringContainsString('variation 701)', intersoccer_reports_render_camp_missing_dates_notice());
		$this->assertStringNotContainsString('variation 702)', intersoccer_reports_render_camp_missing_dates_notice());
		$this->assertStringNotContainsString('variation 703)', intersoccer_reports_render_camp_missing_dates_notice());
	}

	public function test_missing_dates_notice_lists_newest_variations_first() {
		$meta = [];
		$statuses = [];
		foreach ([10, 20, 30] as $id) {
			$statuses[$id] = 'publish';
			$meta[$id] = [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
			];
		}
		InterSoccerRRTestMeta::$data = $meta;
		$GLOBALS['intersoccer_test_post_titles'] = [
			10 => 'Sample Camp Low',
			20 => 'Sample Camp Mid',
			30 => 'Sample Camp High',
		];
		$this->stubVariationPosts($statuses);

		$rows = intersoccer_reports_list_camp_variations_missing_dates();
		$this->assertMissingDatesQueryShape();
		$this->assertSame([30, 20, 10], array_column($rows, 'variation_id'));

		$notice = intersoccer_reports_render_camp_missing_dates_notice();
		$this->assertStringNotContainsString('Showing 40 of', $notice);
		$pos_high = strpos($notice, 'variation 30)');
		$pos_mid = strpos($notice, 'variation 20)');
		$pos_low = strpos($notice, 'variation 10)');
		$this->assertNotFalse($pos_high);
		$this->assertLessThan($pos_mid, $pos_high);
		$this->assertLessThan($pos_low, $pos_mid);
	}

	public function test_missing_dates_keeps_camps_with_no_season_and_skips_a_finished_season() {
		$past_year = (int) date('Y') - 2;
		$upcoming_year = (int) date('Y') + 1;
		InterSoccerRRTestMeta::$data = [
			810 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
			],
			811 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
				'attribute_pa_program-season' => 'Summer ' . $past_year,
			],
			812 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
				'attribute_pa_program-season' => 'summer-camps-' . $upcoming_year,
			],
			813 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'TBD',
				'pa_program-season' => 'Autumn',
			],
			814 => [
				'_intersoccer_product_type' => 'camp',
				'_camp_start_date' => '2026-03-22',
				'attribute_pa_camp-terms' => 'not-a-date',
				'attribute_pa_program-season' => 'Summer ' . $upcoming_year,
			],
			815 => [
				'_intersoccer_product_type' => 'camp',
				'attribute_pa_camp-terms' => 'week-1-march-22-25-4-days',
				'attribute_pa_program-season' => 'Easter ' . $upcoming_year,
			],
			816 => [
				'_intersoccer_product_type' => 'course',
			],
		];
		$this->stubVariationPosts([
			810 => 'publish',
			811 => 'publish',
			812 => 'publish',
			813 => 'publish',
			814 => 'publish',
			815 => 'publish',
			816 => 'publish',
		]);

		$this->assertTrue(intersoccer_reports_camp_variation_missing_dates(810));
		$this->assertTrue(intersoccer_reports_camp_variation_missing_dates(811));
		$this->assertFalse(intersoccer_reports_camp_variation_missing_dates(814));
		$this->assertFalse(intersoccer_reports_camp_variation_missing_dates(815));
		$this->assertFalse(intersoccer_reports_camp_variation_missing_dates(816));

		$rows = intersoccer_reports_list_camp_variations_missing_dates();
		$this->assertMissingDatesQueryShape();
		$this->assertSame([813, 812, 810], array_column($rows, 'variation_id'));
	}

	public function test_missing_dates_season_scope_uses_the_season_field_and_a_reference_date() {
		$as_of = '2026-11-11';
		$this->assertTrue(intersoccer_reports_camp_program_season_in_missing_dates_scope('', $as_of));
		$this->assertTrue(intersoccer_reports_camp_program_season_in_missing_dates_scope('N/A', $as_of));
		$this->assertTrue(intersoccer_reports_camp_program_season_in_missing_dates_scope('Autumn', $as_of));
		$this->assertFalse(intersoccer_reports_camp_program_season_in_missing_dates_scope('Summer 2024', $as_of));
		$this->assertTrue(intersoccer_reports_camp_program_season_in_missing_dates_scope('Summer 2027', $as_of));
		$this->assertTrue(intersoccer_reports_camp_program_season_in_missing_dates_scope('autumn-2026', $as_of));
		$this->assertFalse(intersoccer_reports_camp_program_season_in_missing_dates_scope('summer-camps-2026', $as_of));
		$this->assertTrue(intersoccer_reports_camp_program_season_in_missing_dates_scope('Winter 2026', '2026-02-01'));
		$this->assertFalse(intersoccer_reports_camp_program_season_in_missing_dates_scope('Winter 2026', $as_of));
		$this->assertTrue(intersoccer_reports_camp_program_season_in_missing_dates_scope('Easter 2026', '2026-04-01'));
		$this->assertFalse(intersoccer_reports_camp_program_season_in_missing_dates_scope('Easter 2026', $as_of));
	}

	/**
	 * @param array<int,string> $id_to_status
	 */
	private function stubVariationPosts(array $id_to_status): void {
		$test = $this;
		$GLOBALS['intersoccer_test_get_posts_callback'] = static function ($args) use ($test, $id_to_status) {
			$test->lastGetPostsArgs = $args;
			$statuses = $args['post_status'] ?? ['publish'];
			if (!is_array($statuses)) {
				$statuses = [$statuses];
			}
			$ids = [];
			foreach ($id_to_status as $id => $status) {
				if (in_array((string) $status, $statuses, true)) {
					$ids[] = (int) $id;
				}
			}
			$order = strtoupper((string) ($args['order'] ?? 'DESC'));
			if (($args['orderby'] ?? 'ID') === 'ID') {
				if ($order === 'ASC') {
					sort($ids, SORT_NUMERIC);
				} else {
					rsort($ids, SORT_NUMERIC);
				}
			}
			$per_page = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : -1;
			if ($per_page > 0) {
				$ids = array_slice($ids, 0, $per_page);
			}

			return $ids;
		};
	}

	private function assertMissingDatesQueryShape(): void {
		$args = $this->lastGetPostsArgs;
		$this->assertIsArray($args);
		$this->assertSame('product_variation', $args['post_type'] ?? null);
		$statuses = $args['post_status'] ?? null;
		if (!is_array($statuses)) {
			$statuses = [$statuses];
		}
		$this->assertSame(['publish'], array_values($statuses));
		$this->assertSame('ID', $args['orderby'] ?? null);
		$this->assertSame('DESC', strtoupper((string) ($args['order'] ?? '')));
		$this->assertSame(-1, (int) ($args['posts_per_page'] ?? 0));
		$this->assertNotSame('ASC', strtoupper((string) ($args['order'] ?? '')));
		$this->assertNotSame(400, (int) ($args['posts_per_page'] ?? 0));
		$this->assertTrue($this->metaQueryIncludesKeys($args['meta_query'] ?? [], [
			'attribute_pa_camp-terms',
			'_intersoccer_product_type',
			'attribute_pa_activity-type',
		]));
		$this->assertTrue($this->metaQueryLooksForMissingCampDates($args['meta_query'] ?? []));
	}

	/**
	 * @param mixed              $query
	 * @param array<int,string>  $keys
	 */
	private function metaQueryIncludesKeys($query, array $keys): bool {
		$found = array_fill_keys($keys, false);
		$walk = function ($node) use (&$walk, &$found) {
			if (!is_array($node)) {
				return;
			}
			if (isset($node['key']) && array_key_exists($node['key'], $found)) {
				$found[$node['key']] = true;
			}
			foreach ($node as $child) {
				if (is_array($child)) {
					$walk($child);
				}
			}
		};
		$walk($query);
		foreach ($found as $present) {
			if (!$present) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed $query
	 */
	private function metaQueryLooksForMissingCampDates($query): bool {
		$found = [
			'_camp_start_date' => false,
			'_camp_end_date' => false,
		];
		$walk = function ($node) use (&$walk, &$found) {
			if (!is_array($node)) {
				return;
			}
			if (isset($node['key']) && array_key_exists($node['key'], $found)) {
				$compare = strtoupper((string) ($node['compare'] ?? '='));
				$value = array_key_exists('value', $node) ? $node['value'] : null;
				if ($compare === 'NOT EXISTS' || ($compare === '=' && $value === '')) {
					$found[$node['key']] = true;
				}
			}
			foreach ($node as $child) {
				if (is_array($child)) {
					$walk($child);
				}
			}
		};
		$walk($query);

		return $found['_camp_start_date'] && $found['_camp_end_date'];
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
