<?php
/**
 * Follow-up 11: decoded catalogue titles, EN parent product_id, en_unpublished_variations.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports {

use InterSoccer\ReportsRosters\Tests\TestCase;

class WinbackCatalogueFu11Test extends TestCase {
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
		require_once $includes . '/winback-report-admin.php';

		$this->reset_fu11_state();
	}

	protected function tearDown(): void {
		$this->reset_fu11_state();
		global $wpdb;
		if ($this->saved_wpdb !== null) {
			$wpdb = $this->saved_wpdb;
		}
		$this->saved_wpdb = null;
		$this->catalogue_wpdb = null;
		parent::tearDown();
	}

	private function reset_fu11_state(): void {
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

	/**
	 * Excel export uses target product strings as plain cell values (no HTML escaping).
	 */
	private function winback_export_target_product_cell(array $target): string {
		return is_array($target) ? (string) ( $target['product'] ?? '' ) : '';
	}

	public function test_catalogue_decodes_html_entities_in_target_product_names(): void {
		$parent = 91001;
		$var = 92001;
		$start = '2026-12-01';
		$encoded_title = 'Cours d&#8217;hiver Bâle &amp; région';

		$this->stub_wpml_maps([$parent => $parent], [$var => $var]);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$parent];
		$GLOBALS['intersoccer_test_post_status'] = [$parent => 'publish', $var => 'publish'];
		$GLOBALS['intersoccer_test_post_titles'] = [$parent => $encoded_title];
		$GLOBALS['intersoccer_test_post_parents'] = [$var => $parent];
		update_post_meta($parent, '_intersoccer_product_type', 'course');
		update_post_meta($var, '_course_start_date', $start);
		update_post_meta($var, 'attribute_pa_intersoccer-venues', 'basel-margarethenhalle');
		update_post_meta($var, 'attribute_pa_age-group', '5-8y');
		$this->catalogue_wpdb->variation_posts_by_parent = [$parent => [$var]];

		$catalogue = intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$rows = $this->flatten_catalogue_rows($catalogue);
		$this->assertCount(1, $rows);
		$expected = html_entity_decode('Cours d&#8217;hiver Bâle &amp; région', ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$this->assertSame($expected, $rows[0]['product_name']);

		$resolved = intersoccer_winback_resolve_catalogue_target_and_more(
			$catalogue,
			'course',
			'basel-margarethenhalle',
			'5-8y',
			'2018-01-01'
		);
		$this->assertNotNull($resolved['target']);
		$this->assertSame($expected, $resolved['target']['product']);
		$this->assertSame($expected, $this->winback_export_target_product_cell($resolved['target']));

		$screen = esc_html($resolved['target']['product']);
		$this->assertSame($expected, html_entity_decode($screen, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		$this->assertStringNotContainsString('&#8217;', $screen);
		$this->assertStringNotContainsString('&amp;#8217;', $screen);
	}

	public function test_en_parent_draft_uses_en_title_and_id_and_fr_de_dedupe(): void {
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
			$en_var => 'draft',
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
		update_post_meta($fr_var, 'attribute_pa_intersoccer-venues', 'basel-margarethenhalle');
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
		$this->assertSame($en_parent, (int) $rows[0]['product_id']);
		$this->assertSame('EN Course (draft)', $rows[0]['product_name']);
	}

	public function test_fr_only_product_uses_enumerated_translation_title_and_id(): void {
		$fr_parent = 7001;
		$fr_var = 8001;
		$start = '2026-10-01';

		$this->stub_wpml_maps([$fr_parent => $fr_parent], [$fr_var => $fr_var]);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$fr_parent];
		$GLOBALS['intersoccer_test_post_status'] = [$fr_parent => 'publish', $fr_var => 'publish'];
		$GLOBALS['intersoccer_test_post_titles'] = [$fr_parent => 'FR-only course'];
		$GLOBALS['intersoccer_test_post_parents'] = [$fr_var => $fr_parent];
		update_post_meta($fr_parent, '_intersoccer_product_type', 'course');
		update_post_meta($fr_var, '_course_start_date', $start);
		update_post_meta($fr_var, 'attribute_pa_intersoccer-venues', 'geneva-lake');
		update_post_meta($fr_var, 'attribute_pa_age-group', '5-8y');
		$this->catalogue_wpdb->variation_posts_by_parent = [$fr_parent => [$fr_var]];

		$catalogue = intersoccer_winback_enumerate_upcoming_catalogue('2026-01-01');
		$rows = $this->flatten_catalogue_rows($catalogue);
		$this->assertCount(1, $rows);
		$this->assertSame($fr_parent, (int) $rows[0]['product_id']);
		$this->assertSame('FR-only course', $rows[0]['product_name']);
	}

	public function test_en_unpublished_variations_lists_draft_and_private_en_variations_only(): void {
		$en_parent = 11001;
		$fr_parent = 11002;
		$en_pub = 12001;
		$en_draft = 12002;
		$en_private = 12003;
		$fr_draft = 12012;
		$fr_private = 12013;
		$en_past = 12004;
		$fr_past = 12014;

		$this->stub_wpml_maps(
			[$fr_parent => $en_parent],
			[
				$fr_draft => $en_draft,
				$fr_private => $en_private,
				$en_pub => $en_pub,
				$fr_past => $en_past,
			]
		);
		$GLOBALS['intersoccer_test_get_posts_return'] = [$fr_parent];
		$GLOBALS['intersoccer_test_post_status'] = [
			$en_parent => 'draft',
			$fr_parent => 'publish',
			$en_pub => 'publish',
			$en_draft => 'draft',
			$en_private => 'private',
			$fr_draft => 'publish',
			$fr_private => 'publish',
			$en_past => 'draft',
			$fr_past => 'publish',
		];
		$GLOBALS['intersoccer_test_post_titles'] = [$en_parent => 'Camp EN draft parent'];
		$GLOBALS['intersoccer_test_post_parents'] = [
			$en_pub => $en_parent,
			$en_draft => $en_parent,
			$en_private => $en_parent,
			$fr_draft => $fr_parent,
			$fr_private => $fr_parent,
			$en_past => $en_parent,
			$fr_past => $fr_parent,
		];
		update_post_meta($en_parent, '_intersoccer_product_type', 'camp');
		update_post_meta($en_pub, '_camp_start_date', '2026-09-01');
		update_post_meta($en_pub, 'attribute_pa_intersoccer-venues', 'zurich-test');
		update_post_meta($en_pub, 'attribute_pa_age-group', '5-12y');
		update_post_meta($en_draft, '_camp_start_date', '2026-10-01');
		update_post_meta($en_draft, 'attribute_pa_intersoccer-venues', 'zurich-test');
		update_post_meta($en_draft, 'attribute_pa_age-group', '5-12y');
		update_post_meta($en_private, '_camp_start_date', '2026-11-01');
		update_post_meta($en_private, 'attribute_pa_intersoccer-venues', 'zurich-test');
		update_post_meta($en_private, 'attribute_pa_age-group', '5-12y');
		update_post_meta($en_past, '_camp_start_date', '2025-01-01');
		update_post_meta($en_past, 'attribute_pa_intersoccer-venues', 'zurich-test');
		update_post_meta($en_past, 'attribute_pa_age-group', '5-12y');
		$this->catalogue_wpdb->variation_posts_by_parent = [
			$fr_parent => [$fr_draft, $fr_private, $fr_past],
			$en_parent => [$en_pub],
		];

		intersoccer_winback_enumerate_upcoming_catalogue('2026-06-01');
		$list = intersoccer_winback_get_en_unpublished_variations();
		$this->assertCount(2, $list);
		$this->assertSame($en_draft, $list[0]['en_variation_id']);
		$this->assertSame('draft', $list[0]['en_variation_status']);
		$this->assertSame([$fr_draft], $list[0]['translation_variation_ids']);
		$this->assertSame('Camp EN draft parent', $list[0]['product']);
		$this->assertSame('2026-10-01', $list[0]['start_date']);
		$this->assertSame('zurich-test', $list[0]['venue']);
		$this->assertSame('camp', $list[0]['kind']);

		$this->assertSame($en_private, $list[1]['en_variation_id']);
		$this->assertSame('private', $list[1]['en_variation_status']);
		$this->assertSame([$fr_private], $list[1]['translation_variation_ids']);
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
		$this->assertIsArray($result['unmapped_facets']);
		$this->assertIsArray($result['rows']);

		intersoccer_winback_register_en_unpublished_variation([
			'en_variation_id' => 99,
			'en_variation_status' => 'draft',
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
