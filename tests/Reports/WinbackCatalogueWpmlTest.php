<?php
/**
 * Win-back catalogue enumeration with WPML translation groups.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackCatalogueWpmlTest extends TestCase {
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

		$this->reset_catalogue_test_state();
	}

	protected function tearDown(): void {
		$this->reset_catalogue_test_state();
		global $wpdb;
		if ($this->saved_wpdb !== null) {
			$wpdb = $this->saved_wpdb;
		}
		$this->saved_wpdb = null;
		$this->catalogue_wpdb = null;
		parent::tearDown();
	}

	private function reset_catalogue_test_state(): void {
		\InterSoccerRRTestMeta::reset();
		if (function_exists('intersoccer_winback_reset_catalogue_enumeration_memo')) {
			intersoccer_winback_reset_catalogue_enumeration_memo();
		}
		if (function_exists('intersoccer_winback_reset_catalogue_target_cache')) {
			intersoccer_winback_reset_catalogue_target_cache();
		}
		unset(
			$GLOBALS['intersoccer_test_get_posts_return'],
			$GLOBALS['intersoccer_test_get_posts_callback'],
			$GLOBALS['intersoccer_test_post_status'],
			$GLOBALS['intersoccer_test_post_titles'],
			$GLOBALS['intersoccer_test_post_parents'],
			$GLOBALS['intersoccer_test_apply_filters_callback'],
			$GLOBALS['intersoccer_test_terms_by_slug'],
			$GLOBALS['intersoccer_test_terms_by_id'],
			$GLOBALS['intersoccer_test_winback_upcoming_catalogue']
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
			if ($element_type === 'pa_intersoccer-venues' && isset($GLOBALS['intersoccer_test_wpml_term_map'][ $element_id ])) {
				return (int) $GLOBALS['intersoccer_test_wpml_term_map'][ $element_id ];
			}
			if ($element_type === 'pa_age-group' && isset($GLOBALS['intersoccer_test_wpml_term_map'][ $element_id ])) {
				return (int) $GLOBALS['intersoccer_test_wpml_term_map'][ $element_id ];
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

	public function test_catalogue_includes_fr_de_course_when_en_parent_is_draft(): void {
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
		$GLOBALS['intersoccer_test_post_titles'] = [
			$en_parent => 'EN Course (draft)',
			$fr_parent => 'FR Course Basel',
			$de_parent => 'DE Course Basel',
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
		update_post_meta($en_var, 'attribute_pa_intersoccer-venues', 'basel-margarethenhalle');
		update_post_meta($en_var, 'attribute_pa_age-group', $age);
		update_post_meta($fr_var, 'attribute_pa_intersoccer-venues', 'bale-margarethenhalle');
		update_post_meta($fr_var, 'attribute_pa_age-group', $age);
		update_post_meta($de_var, 'attribute_pa_intersoccer-venues', 'basel-margarethenhalle');
		update_post_meta($de_var, 'attribute_pa_age-group', $age);

		$this->catalogue_wpdb->variation_posts_by_parent = [
			$fr_parent => [$fr_var],
			$de_parent => [$de_var],
		];

		$catalogue = intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$rows = $this->flatten_catalogue_rows($catalogue);
		$this->assertCount(1, $rows);
		$row = $rows[0];
		$this->assertSame('course', $row['kind']);
		$this->assertSame($start, $row['start_date']);
		$this->assertSame('basel-margarethenhalle', $row['venue_slug']);
		$this->assertSame($age, $row['age_group_slug']);
		$this->assertSame($en_var, (int) $row['source_variation_id']);
		$this->assertSame($en_parent, (int) $row['product_id']);
		$this->assertSame('EN Course (draft)', $row['product_name']);

		$resolved = intersoccer_winback_resolve_catalogue_target_and_more(
			$catalogue,
			'course',
			'basel-margarethenhalle',
			$age,
			'2022-06-01'
		);
		$this->assertNotNull($resolved['target']);
		$this->assertSame($start, $resolved['target']['start_date']);
	}

	public function test_catalogue_en_parent_published_unchanged(): void {
		$en_parent = 5001;
		$en_var = 6001;
		$start = '2026-09-01';

		$this->stub_wpml_maps([$en_parent => $en_parent], [$en_var => $en_var]);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$en_parent];
		$GLOBALS['intersoccer_test_post_status'] = [
			$en_parent => 'publish',
			$en_var => 'publish',
		];
		$GLOBALS['intersoccer_test_post_titles'] = [$en_parent => 'Published EN Course'];
		$GLOBALS['intersoccer_test_post_parents'] = [$en_var => $en_parent];
		update_post_meta($en_parent, '_intersoccer_product_type', 'course');
		update_post_meta($en_var, '_course_start_date', $start);
		update_post_meta($en_var, 'attribute_pa_intersoccer-venues', 'geneva-lake');
		update_post_meta($en_var, 'attribute_pa_age-group', '5-13y');
		$this->catalogue_wpdb->variation_posts_by_parent = [$en_parent => [$en_var]];

		$catalogue = intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$rows = $this->flatten_catalogue_rows($catalogue);
		$this->assertCount(1, $rows);
		$this->assertSame($en_parent, (int) $rows[0]['product_id']);
		$this->assertSame('Published EN Course', $rows[0]['product_name']);
		$this->assertSame('geneva-lake', $rows[0]['venue_slug']);
		$this->assertSame('5-13y', $rows[0]['age_group_slug']);
		$this->assertSame($start, $rows[0]['start_date']);
	}

	public function test_catalogue_empty_when_no_published_translation_parent(): void {
		$GLOBALS['intersoccer_test_get_posts_return'] = [];
		$this->catalogue_wpdb->variation_posts_by_parent = [];
		$catalogue = intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$this->assertSame([], $catalogue);
	}

	public function test_catalogue_maps_fr_only_variation_facets_to_en_slugs(): void {
		$fr_parent = 7001;
		$fr_var = 8001;
		$start = '2026-10-01';

		$this->stub_wpml_maps([$fr_parent => $fr_parent], [$fr_var => $fr_var]);
		$GLOBALS['intersoccer_test_wpml_term_map'] = [
			901 => 902,
			911 => 912,
		];
		$GLOBALS['intersoccer_test_terms_by_slug'] = [
			'pa_intersoccer-venues' => [
				'bale-margarethenhalle' => ['term_id' => 901, 'slug' => 'bale-margarethenhalle'],
			],
			'pa_age-group' => [
				'5-8-ans' => ['term_id' => 911, 'slug' => '5-8-ans'],
			],
		];
		$GLOBALS['intersoccer_test_terms_by_id'] = [
			902 => ['term_id' => 902, 'slug' => 'basel-margarethenhalle'],
			912 => ['term_id' => 912, 'slug' => '5-8y'],
		];

		$GLOBALS['intersoccer_test_get_posts_return'] = [$fr_parent];
		$GLOBALS['intersoccer_test_post_status'] = [$fr_parent => 'publish', $fr_var => 'publish'];
		$GLOBALS['intersoccer_test_post_titles'] = [$fr_parent => 'FR-only course'];
		$GLOBALS['intersoccer_test_post_parents'] = [$fr_var => $fr_parent];
		update_post_meta($fr_parent, '_intersoccer_product_type', 'course');
		update_post_meta($fr_var, '_course_start_date', $start);
		update_post_meta($fr_var, 'attribute_pa_intersoccer-venues', 'bale-margarethenhalle');
		update_post_meta($fr_var, 'attribute_pa_age-group', '5-8-ans');
		$this->catalogue_wpdb->variation_posts_by_parent = [$fr_parent => [$fr_var]];

		$catalogue = intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$rows = $this->flatten_catalogue_rows($catalogue);
		$this->assertCount(1, $rows);
		$this->assertSame('basel-margarethenhalle', $rows[0]['venue_slug']);
		$this->assertSame('5-8y', $rows[0]['age_group_slug']);
		$this->assertSame($fr_parent, (int) $rows[0]['product_id']);
	}
}

class WinbackCatalogueTestWpdb extends \InterSoccerTestWpdb {
	/** @var array<int,array<int,int>> */
	public $variation_posts_by_parent = [];

	public function get_results($query = null, $output = OBJECT) {
		$sql = (string) $query;
		if (stripos($sql, 'product_variation') !== false && stripos($sql, 'post_parent IN') !== false) {
			if (!preg_match('/\bIN\s*\(([^)]+)\)/i', $sql, $m)) {
				return [];
			}
			preg_match_all('/\b(\d+)\b/', $m[1], $nums);
			$parent_ids = array_map('intval', $nums[1] ?? []);
			$rows = [];
			foreach ($parent_ids as $parent_id) {
				foreach ($this->variation_posts_by_parent[ $parent_id ] ?? [] as $variation_id) {
					$rows[] = [
						'ID' => (int) $variation_id,
						'post_parent' => (int) $parent_id,
						'post_status' => 'publish',
					];
				}
			}

			return $rows;
		}

		return parent::get_results($query, $output);
	}
}

}
