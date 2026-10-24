<?php
/**
 * Follow-up 12: en_unpublished_variations includes EN parent draft (reason parent).
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackCatalogueFu12Test extends TestCase {
	/** @var \wpdb|null */
	private $saved_wpdb = null;

	/** @var WinbackCatalogueTestWpdb|null */
	private $catalogue_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->saved_wpdb = $wpdb;
		$this->catalogue_wpdb = new WinbackCatalogueTestWpdb();
		$this->catalogue_wpdb->variation_posts_by_parent = [];
		$wpdb = $this->catalogue_wpdb;

		$includes = dirname(__DIR__, 2) . '/includes';
		require_once $includes . '/roster-report-loader.php';
		require_once $includes . '/utils.php';
		require_once $includes . '/winback-report-facets.php';
		require_once $includes . '/winback-catalogue.php';
		require_once $includes . '/winback-report-engine.php';

		$this->reset_fu12_state();
	}

	protected function tearDown(): void {
		$this->reset_fu12_state();
		global $wpdb;
		if ($this->saved_wpdb !== null) {
			$wpdb = $this->saved_wpdb;
		}
		$this->saved_wpdb = null;
		$this->catalogue_wpdb = null;
		parent::tearDown();
	}

	private function reset_fu12_state(): void {
		\InterSoccerRRTestMeta::reset();
		if (function_exists('intersoccer_winback_reset_catalogue_enumeration_memo')) {
			intersoccer_winback_reset_catalogue_enumeration_memo();
		}
		if (function_exists('intersoccer_winback_reset_catalogue_target_cache')) {
			intersoccer_winback_reset_catalogue_target_cache();
		}
		if (function_exists('intersoccer_winback_reset_en_unpublished_variations')) {
			intersoccer_winback_reset_en_unpublished_variations();
		}
		if (function_exists('intersoccer_winback_reset_unmapped_facets')) {
			intersoccer_winback_reset_unmapped_facets();
		}
		unset(
			$GLOBALS['intersoccer_test_get_posts_return'],
			$GLOBALS['intersoccer_test_get_posts_callback'],
			$GLOBALS['intersoccer_test_post_status'],
			$GLOBALS['intersoccer_test_post_titles'],
			$GLOBALS['intersoccer_test_post_parents'],
			$GLOBALS['intersoccer_test_apply_filters_callback'],
			$GLOBALS['intersoccer_test_winback_upcoming_catalogue'],
			$GLOBALS['intersoccer_test_winback_history_rows'],
			$GLOBALS['intersoccer_test_winback_upcoming_roster_rows']
		);
	}

	/**
	 * @param array<int,int> $product_map
	 * @param array<int,int> $variation_map
	 */
	private function stub_wpml_maps(array $product_map, array $variation_map): void {
		$GLOBALS['intersoccer_test_apply_filters_callback'] = static function ($hook, $value, ...$args) use ($product_map, $variation_map) {
			if ($hook === 'wpml_default_language') {
				return 'en';
			}
			if ($hook !== 'wpml_object_id') {
				return $value;
			}
			$element_id = (int) $value;
			$element_type = isset($args[0]) ? (string) $args[0] : '';
			if ($element_type === 'product' && isset($product_map[ $element_id ])) {
				return $product_map[ $element_id ];
			}
			if ($element_type === 'product_variation' && isset($variation_map[ $element_id ])) {
				return $variation_map[ $element_id ];
			}

			return $value;
		};
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function flatten_catalogue_rows(array $catalogue): array {
		$rows = [];
		foreach ($catalogue as $list) {
			foreach ((array) $list as $row) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	public function test_en_unpublished_lists_published_en_variation_when_en_parent_is_draft(): void {
		$en_parent = 1001;
		$fr_parent = 1002;
		$de_parent = 1003;
		$en_var = 2001;
		$fr_var = 2002;
		$de_var = 2003;
		$start = '2026-11-15';
		$age = '5-8y';

		$this->stub_wpml_maps(
			[$fr_parent => $en_parent, $de_parent => $en_parent],
			[$fr_var => $en_var, $de_var => $en_var]
		);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$fr_parent, $de_parent];
		$GLOBALS['intersoccer_test_post_status'] = [
			$en_parent => 'draft',
			$fr_parent => 'publish',
			$de_parent => 'publish',
			$en_var => 'publish',
			$fr_var => 'publish',
			$de_var => 'publish',
		];
		$GLOBALS['intersoccer_test_post_titles'] = [$en_parent => 'EN Course (draft)'];
		$GLOBALS['intersoccer_test_post_parents'] = [
			$en_var => $en_parent,
			$fr_var => $fr_parent,
			$de_var => $de_parent,
		];
		foreach ([$en_parent, $fr_parent, $de_parent] as $pid) {
			update_post_meta($pid, '_intersoccer_product_type', 'course');
		}
		update_post_meta($en_var, '_course_start_date', $start);
		update_post_meta($en_var, 'attribute_pa_intersoccer-venues', 'basel-margarethenhalle');
		update_post_meta($en_var, 'attribute_pa_age-group', $age);
		update_post_meta($fr_var, 'attribute_pa_intersoccer-venues', 'basel-margarethenhalle');
		update_post_meta($fr_var, 'attribute_pa_age-group', $age);
		update_post_meta($de_var, 'attribute_pa_intersoccer-venues', 'basel-margarethenhalle');
		update_post_meta($de_var, 'attribute_pa_age-group', $age);
		$this->catalogue_wpdb->variation_posts_by_parent = [
			$fr_parent => [$fr_var],
			$de_parent => [$de_var],
		];

		intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$list = intersoccer_winback_get_en_unpublished_variations();
		$this->assertCount(1, $list);
		$this->assertSame($en_var, $list[0]['en_variation_id']);
		$this->assertSame('publish', $list[0]['en_variation_status']);
		$this->assertSame('draft', $list[0]['en_parent_status']);
		$this->assertSame('parent', $list[0]['reason']);
		$this->assertSame([$fr_var, $de_var], $list[0]['translation_variation_ids']);
	}

	public function test_en_unpublished_reason_variation_when_en_parent_published(): void {
		$en_parent = 11001;
		$fr_parent = 11002;
		$en_draft = 12002;
		$en_private = 12003;
		$fr_draft = 12012;
		$fr_private = 12013;

		$this->stub_wpml_maps(
			[$fr_parent => $en_parent],
			[
				$fr_draft => $en_draft,
				$fr_private => $en_private,
			]
		);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$fr_parent];
		$GLOBALS['intersoccer_test_post_status'] = [
			$en_parent => 'publish',
			$fr_parent => 'publish',
			$en_draft => 'draft',
			$en_private => 'private',
			$fr_draft => 'publish',
			$fr_private => 'publish',
		];
		$GLOBALS['intersoccer_test_post_titles'] = [$en_parent => 'Camp EN'];
		$GLOBALS['intersoccer_test_post_parents'] = [
			$en_draft => $en_parent,
			$en_private => $en_parent,
			$fr_draft => $fr_parent,
			$fr_private => $fr_parent,
		];
		update_post_meta($en_parent, '_intersoccer_product_type', 'camp');
		update_post_meta($en_draft, '_camp_start_date', '2026-10-01');
		update_post_meta($en_draft, 'attribute_pa_intersoccer-venues', 'zurich-test');
		update_post_meta($en_draft, 'attribute_pa_age-group', '5-12y');
		update_post_meta($en_private, '_camp_start_date', '2026-11-01');
		update_post_meta($en_private, 'attribute_pa_intersoccer-venues', 'zurich-test');
		update_post_meta($en_private, 'attribute_pa_age-group', '5-12y');
		$this->catalogue_wpdb->variation_posts_by_parent = [
			$fr_parent => [$fr_draft, $fr_private],
		];

		intersoccer_winback_enumerate_upcoming_catalogue('2026-06-01');
		$list = intersoccer_winback_get_en_unpublished_variations();
		$this->assertCount(2, $list);
		$this->assertSame('variation', $list[0]['reason']);
		$this->assertSame('draft', $list[0]['en_variation_status']);
		$this->assertSame('publish', $list[0]['en_parent_status']);
		$this->assertSame('variation', $list[1]['reason']);
		$this->assertSame('private', $list[1]['en_variation_status']);
	}

	public function test_en_unpublished_reason_parent_when_both_parent_and_variation_unpublished(): void {
		$en_parent = 13001;
		$fr_parent = 13002;
		$en_var = 14001;
		$fr_var = 14002;

		$this->stub_wpml_maps([$fr_parent => $en_parent], [$fr_var => $en_var]);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$fr_parent];
		$GLOBALS['intersoccer_test_post_status'] = [
			$en_parent => 'draft',
			$fr_parent => 'publish',
			$en_var => 'private',
			$fr_var => 'publish',
		];
		$GLOBALS['intersoccer_test_post_titles'] = [$en_parent => 'Draft parent'];
		$GLOBALS['intersoccer_test_post_parents'] = [
			$en_var => $en_parent,
			$fr_var => $fr_parent,
		];
		update_post_meta($en_parent, '_intersoccer_product_type', 'camp');
		update_post_meta($en_var, '_camp_start_date', '2026-12-01');
		update_post_meta($en_var, 'attribute_pa_intersoccer-venues', 'geneva-lake');
		update_post_meta($en_var, 'attribute_pa_age-group', '5-8y');
		$this->catalogue_wpdb->variation_posts_by_parent = [$fr_parent => [$fr_var]];

		intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$list = intersoccer_winback_get_en_unpublished_variations();
		$this->assertCount(1, $list);
		$this->assertSame('parent', $list[0]['reason']);
		$this->assertSame('private', $list[0]['en_variation_status']);
		$this->assertSame('draft', $list[0]['en_parent_status']);
	}

	public function test_en_unpublished_empty_when_en_published_but_translations_not_published(): void {
		$en_parent = 15001;
		$fr_parent = 15002;
		$en_var = 16001;
		$fr_var = 16002;

		$this->stub_wpml_maps([$fr_parent => $en_parent], [$fr_var => $en_var]);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$fr_parent];
		$GLOBALS['intersoccer_test_post_status'] = [
			$en_parent => 'publish',
			$fr_parent => 'publish',
			$en_var => 'publish',
			$fr_var => 'draft',
		];
		$GLOBALS['intersoccer_test_post_titles'] = [$en_parent => 'Published'];
		$GLOBALS['intersoccer_test_post_parents'] = [
			$en_var => $en_parent,
			$fr_var => $fr_parent,
		];
		update_post_meta($en_parent, '_intersoccer_product_type', 'course');
		update_post_meta($en_var, '_course_start_date', '2026-09-01');
		update_post_meta($en_var, 'attribute_pa_intersoccer-venues', 'basel-margarethenhalle');
		update_post_meta($en_var, 'attribute_pa_age-group', '5-8y');
		$this->catalogue_wpdb->variation_posts_by_parent = [$fr_parent => [$fr_var]];

		intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$this->assertSame([], intersoccer_winback_get_en_unpublished_variations());
	}

	public function test_en_unpublished_empty_for_past_start_date(): void {
		$en_parent = 17001;
		$fr_parent = 17002;
		$en_var = 18001;
		$fr_var = 18002;

		$this->stub_wpml_maps([$fr_parent => $en_parent], [$fr_var => $en_var]);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$fr_parent];
		$GLOBALS['intersoccer_test_post_status'] = [
			$en_parent => 'draft',
			$fr_parent => 'publish',
			$en_var => 'publish',
			$fr_var => 'publish',
		];
		$GLOBALS['intersoccer_test_post_titles'] = [$en_parent => 'Past'];
		$GLOBALS['intersoccer_test_post_parents'] = [
			$en_var => $en_parent,
			$fr_var => $fr_parent,
		];
		update_post_meta($en_parent, '_intersoccer_product_type', 'camp');
		update_post_meta($en_var, '_camp_start_date', '2025-01-01');
		update_post_meta($en_var, 'attribute_pa_intersoccer-venues', 'zurich-test');
		update_post_meta($en_var, 'attribute_pa_age-group', '5-12y');
		$this->catalogue_wpdb->variation_posts_by_parent = [$fr_parent => [$fr_var]];

		intersoccer_winback_enumerate_upcoming_catalogue('2026-06-01');
		$this->assertSame([], intersoccer_winback_get_en_unpublished_variations());
	}

	public function test_catalogue_targets_unchanged_when_en_parent_is_draft(): void {
		$en_parent = 1001;
		$fr_parent = 1002;
		$de_parent = 1003;
		$en_var = 2001;
		$fr_var = 2002;
		$de_var = 2003;
		$start = '2026-11-15';
		$age = '5-8y';
		$venue = 'basel-margarethenhalle';
		$dob = '2018-01-01';

		$this->stub_wpml_maps(
			[$fr_parent => $en_parent, $de_parent => $en_parent],
			[$fr_var => $en_var, $de_var => $en_var]
		);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$fr_parent, $de_parent];
		$GLOBALS['intersoccer_test_post_status'] = [
			$en_parent => 'draft',
			$fr_parent => 'publish',
			$de_parent => 'publish',
			$en_var => 'draft',
			$fr_var => 'publish',
			$de_var => 'publish',
		];
		$GLOBALS['intersoccer_test_post_titles'] = [
			$en_parent => 'EN Course (draft)',
		];
		$GLOBALS['intersoccer_test_post_parents'] = [
			$en_var => $en_parent,
			$fr_var => $fr_parent,
			$de_var => $de_parent,
		];
		foreach ([$en_parent, $fr_parent, $de_parent] as $pid) {
			update_post_meta($pid, '_intersoccer_product_type', 'course');
		}
		update_post_meta($en_var, '_course_start_date', $start);
		update_post_meta($en_var, 'attribute_pa_intersoccer-venues', $venue);
		update_post_meta($en_var, 'attribute_pa_age-group', $age);
		update_post_meta($fr_var, 'attribute_pa_intersoccer-venues', $venue);
		update_post_meta($fr_var, 'attribute_pa_age-group', $age);
		update_post_meta($de_var, 'attribute_pa_intersoccer-venues', $venue);
		update_post_meta($de_var, 'attribute_pa_age-group', $age);
		$this->catalogue_wpdb->variation_posts_by_parent = [
			$fr_parent => [$fr_var],
			$de_parent => [$de_var],
		];

		$catalogue = intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$rows = $this->flatten_catalogue_rows($catalogue);
		$this->assertCount(1, $rows);

		$resolved = intersoccer_winback_resolve_catalogue_target_and_more(
			$catalogue,
			'course',
			$venue,
			$age,
			$dob
		);
		$this->assertNotNull($resolved['target']);
		$this->assertSame('EN Course (draft)', $resolved['target']['product']);
		$this->assertSame($venue, $resolved['target']['venue']);
		$this->assertSame($age, $resolved['target']['age_group']);
		$this->assertSame($start, $resolved['target']['start_date']);
		$this->assertSame([], $resolved['more_targets']);
	}

	public function test_engine_result_includes_en_unpublished_variations_and_resets_between_runs(): void {
		$GLOBALS['intersoccer_test_winback_history_rows'] = [];
		$GLOBALS['intersoccer_test_winback_upcoming_roster_rows'] = [];
		$GLOBALS['intersoccer_test_winback_upcoming_catalogue'] = [
			'camp|v|5-12y' => [
				[
					'kind' => 'camp',
					'variation_id' => 1,
					'source_variation_id' => 1,
					'product_id' => 1,
					'start_date' => '2026-08-01',
					'product_name' => 'Camp',
					'venue_slug' => 'v',
					'age_group_slug' => '5-12y',
					'full' => false,
				],
			],
		];

		$result = intersoccer_winback_report_engine_generate(['kind' => 'all', 'lookback_months' => 12]);
		$this->assertArrayHasKey('rows', $result);
		$this->assertArrayHasKey('unmapped_facets', $result);
		$this->assertArrayHasKey('en_unpublished_variations', $result);
		$this->assertSame([], $result['en_unpublished_variations']);

		intersoccer_winback_register_en_unpublished_variation([
			'en_variation_id' => 99,
			'en_variation_status' => 'draft',
			'en_parent_status' => 'publish',
			'reason' => 'variation',
			'translation_variation_id' => 100,
			'product' => 'X',
			'start_date' => '2026-12-01',
			'venue' => 'v',
			'kind' => 'camp',
		]);
		$result2 = intersoccer_winback_report_engine_generate(['kind' => 'all', 'lookback_months' => 12]);
		$this->assertSame([], $result2['en_unpublished_variations']);
	}
}

}
