<?php
/**
 * Batched resolver vs frozen PV 2.10.16 read path + non-batched PV fallback.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccerRRTestMeta;

require_once dirname(__DIR__) . '/fixtures/pv-product-types-reference-2-10-16.php';

class ProductTypeBatchEquivalenceTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		require_once $includes . '/utils.php';
		require_once $includes . '/winback-report-perf.php';
		$this->reset_fixtures();
	}

	protected function tearDown(): void {
		$this->reset_fixtures();
		parent::tearDown();
	}

	private function reset_fixtures(): void {
		InterSoccerRRTestMeta::$data = [];
		unset(
			$GLOBALS['intersoccer_test_wp_get_object_terms'],
			$GLOBALS['intersoccer_test_terms_by_id'],
			$GLOBALS['intersoccer_test_post_parents'],
			$GLOBALS['intersoccer_test_get_product_type_calls'],
			$GLOBALS['intersoccer_rr_test_wpml_object_id_calls'],
			$GLOBALS['intersoccer_rr_test_api_call_counts']
		);
		if (function_exists('intersoccer_rr_product_type_memo_reset')) {
			intersoccer_rr_product_type_memo_reset();
		}
	}

	/**
	 * @return object
	 */
	private function term(int $term_id, string $slug, string $name) {
		$t = new \stdClass();
		$t->term_id = $term_id;
		$t->slug = $slug;
		$t->name = $name;

		return $t;
	}

	private function seed_wpml_map(array $map): void {
		add_filter('wpml_default_language', static function () {
			return 'en';
		});
		add_filter('wpml_object_id', static function ($id, $type, $return_original, $lang) use ($map) {
			if ($type === 'product' && isset($map[ (int) $id ])) {
				return (int) $map[ (int) $id ];
			}

			return (int) $id;
		}, 10, 4);
	}

	/**
	 * @param array<int,array<string,array<int,object>>> $terms_fixture
	 * @param int[]                                        $post_ids
	 */
	private function assert_batch_matches_pv_reference(array $terms_fixture, array $post_ids, array $safe_pairs = []): void {
		$GLOBALS['intersoccer_test_wp_get_object_terms'] = $terms_fixture;
		intersoccer_rr_product_type_memo_begin();
		$batch = intersoccer_rr_product_type_batch_load_for_post_ids($post_ids);
		foreach ($post_ids as $id) {
			$expected = \InterSoccer_PV_Product_Types_Reference_2_10_16::get_product_type($id);
			$this->assertSame($expected, $batch[ $id ] ?? null, 'batch vs PV reference for post ' . $id);
		}
		foreach ($safe_pairs as $pair) {
			$parent = (int) $pair[0];
			$variation = isset($pair[1]) ? (int) $pair[1] : null;
			$expected = '';
			if ($variation && $variation > 0) {
				$expected = (string) ( \InterSoccer_PV_Product_Types_Reference_2_10_16::get_product_type($variation) ?? '' );
				if ($expected === '') {
					$expected = (string) ( \InterSoccer_PV_Product_Types_Reference_2_10_16::get_product_type($parent) ?? '' );
				}
			} else {
				$expected = (string) ( \InterSoccer_PV_Product_Types_Reference_2_10_16::get_product_type($parent) ?? '' );
			}
			$safe = (string) intersoccer_get_product_type_safe($parent, $variation);
			$this->assertSame($expected, $safe, 'safe pair ' . $parent . ':' . ( $variation ?? '0' ));
		}
		intersoccer_rr_product_type_memo_end();
	}

	public function test_batch_matches_pv_reference_matrix(): void {
		$terms = [];
		$ids = [];
		InterSoccerRRTestMeta::$data[96001]['_intersoccer_product_type'] = 'course';
		$ids[] = 96001;

		$terms[96010] = ['pa_activity-type' => [ $this->term(101, 'cours', 'Cours') ], 'product_cat' => []];
		$ids[] = 96010;
		$terms[96011] = ['pa_activity-type' => [ $this->term(102, 'kurs', 'Kurs') ], 'product_cat' => []];
		$ids[] = 96011;
		$terms[96012] = ['pa_activity-type' => [ $this->term(103, 'lager', 'Lager') ], 'product_cat' => []];
		$ids[] = 96012;
		$terms[96013] = ['pa_activity-type' => [ $this->term(104, 'anniversaire', 'Anniversaire') ], 'product_cat' => []];
		$ids[] = 96013;

		InterSoccerRRTestMeta::$data[96020]['_intersoccer_product_type'] = 'camp';
		$terms[96020] = [
			'pa_activity-type' => [ $this->term(105, 'birthday-party', 'Birthday Party') ],
			'product_cat' => [ $this->term(201, 'birthdays', 'Birthdays') ],
		];
		$ids[] = 96020;

		$terms[96030] = ['pa_activity-type' => [], 'product_cat' => [ $this->term(202, 'courses', 'Courses') ]];
		$ids[] = 96030;

		InterSoccerRRTestMeta::$data[96040]['_product_attributes'] = serialize([
			'pa_activity-type' => [
				'name' => 'pa_activity-type',
				'value' => '301',
				'is_visible' => 1,
				'is_variation' => 0,
				'is_taxonomy' => 1,
			],
		]);
		$GLOBALS['intersoccer_test_terms_by_id'] = [
			301 => $this->term(301, 'stage', 'Stage'),
		];
		$ids[] = 96040;

		$parent = 96050;
		$variation = 96051;
		$GLOBALS['intersoccer_test_post_parents'][ $variation ] = $parent;
		InterSoccerRRTestMeta::$data[ $parent ]['_intersoccer_product_type'] = 'course';
		$terms[ $parent ] = [
			'pa_activity-type' => [ $this->term(401, 'course', 'Course') ],
			'product_cat' => [],
		];
		$terms[ $variation ] = ['pa_activity-type' => [], 'product_cat' => []];
		$ids[] = $parent;
		$ids[] = $variation;

		$this->seed_wpml_map([96060 => 96061]);
		InterSoccerRRTestMeta::$data[96061]['_intersoccer_product_type'] = 'tournament';
		$terms[96061] = [
			'pa_activity-type' => [ $this->term(501, 'tournament', 'Tournament') ],
			'product_cat' => [],
		];
		$terms[96060] = ['pa_activity-type' => [], 'product_cat' => []];
		$ids[] = 96060;
		$ids[] = 96061;

		$terms[96070] = ['pa_activity-type' => [], 'product_cat' => []];
		$ids[] = 96070;

		$this->assert_batch_matches_pv_reference($terms, $ids, [
			[ $parent, $variation ],
			[ 96001, null ],
		]);
	}

	public function test_non_batched_post_id_falls_back_to_pv_helper(): void {
		require_once __DIR__ . '/player-course-status-golden-stubs.php';
		$GLOBALS['intersoccer_test_golden_product_type_by_id'] = [88001 => 'camp'];
		$GLOBALS['intersoccer_test_get_product_type_calls'] = [];
		intersoccer_rr_product_type_memo_begin();
		intersoccer_rr_product_type_batch_load_for_post_ids([90001]);
		$this->assertSame('camp', intersoccer_get_product_type(88001));
		$this->assertSame('camp', intersoccer_rr_memoized_get_product_type(88001));
		$this->assertSame('', intersoccer_rr_memoized_get_product_type(90001));
		intersoccer_rr_product_type_memo_end();
		unset($GLOBALS['intersoccer_test_golden_product_type_by_id']);
	}
}
