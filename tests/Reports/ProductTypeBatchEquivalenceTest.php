<?php
/**
 * Batched product-type resolution matches PV-style per-ID results on representative fixtures.
 */

namespace InterSoccer\ReportsRosters\Tests\Reports;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccerRRTestMeta;

class ProductTypeBatchEquivalenceTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$includes = dirname(__DIR__, 2) . '/includes';
		require_once $includes . '/utils.php';
		require_once $includes . '/winback-report-perf.php';
		if (function_exists('intersoccer_reset_product_type_cache')) {
			intersoccer_reset_product_type_cache();
		}
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
			$GLOBALS['intersoccer_test_post_parents'],
			$GLOBALS['intersoccer_test_pv_product_type_reference'],
			$GLOBALS['intersoccer_rr_pv_product_type_by_id'],
			$GLOBALS['intersoccer_rr_product_type_batch_loaded']
		);
		if (function_exists('intersoccer_rr_product_type_memo_reset')) {
			intersoccer_rr_product_type_memo_reset();
		}
	}

	/**
	 * @return object
	 */
	private function term($term_id, $slug, $name) {
		$t = new \stdClass();
		$t->term_id = $term_id;
		$t->slug = $slug;
		$t->name = $name;

		return $t;
	}

	/**
	 * @param array<int,array<string,array<int,object>>> $fixture
	 */
	private function seed_terms_fixture(array $fixture): void {
		$GLOBALS['intersoccer_test_wp_get_object_terms'] = $fixture;
	}

	/**
	 * @param array<int,string> $reference post_id => expected type from PV stub
	 */
	private function assert_batch_matches_reference(array $post_ids, array $reference): void {
		intersoccer_rr_product_type_memo_begin();
		$loaded = intersoccer_rr_product_type_batch_load_for_post_ids($post_ids);
		foreach ($reference as $id => $expected) {
			$batch = $loaded[ $id ] ?? null;
			$this->assertSame($expected, $batch, 'batch type for post ' . $id);
			if (function_exists('intersoccer_get_product_type_safe')) {
				$parent = (int) ( $GLOBALS['intersoccer_test_post_parents'][ $id ] ?? 0 );
				$safe = intersoccer_get_product_type_safe(
					$parent > 0 ? $parent : $id,
					$parent > 0 ? $id : null
				);
				$this->assertSame((string) ( $expected ?? '' ), (string) $safe, 'safe cache for post ' . $id);
			}
		}
		intersoccer_rr_product_type_memo_end();
	}

	public function test_batch_matches_meta_and_activity_for_each_canonical_type(): void {
		$cases = [
			91001 => 'camp',
			91002 => 'course',
			91003 => 'birthday',
			91004 => 'tournament',
		];
		$terms = [];
		$reference = [];
		foreach ($cases as $id => $type) {
			InterSoccerRRTestMeta::$data[ $id ]['_intersoccer_product_type'] = $type;
			$terms[ $id ] = [
				'pa_activity-type' => [ $this->term($id, $type, ucfirst($type)) ],
				'product_cat' => [],
			];
			$reference[ $id ] = $type;
		}
		$this->seed_terms_fixture($terms);
		$this->assert_batch_matches_reference(array_keys($reference), $reference);
	}

	public function test_batch_resolves_translated_activity_slugs(): void {
		$fr_id = 92001;
		$de_id = 92002;
		$this->seed_terms_fixture([
			$fr_id => [
				'pa_activity-type' => [ $this->term(501, 'cours', 'Cours') ],
				'product_cat' => [],
			],
			$de_id => [
				'pa_activity-type' => [ $this->term(502, 'kurs', 'Kurs') ],
				'product_cat' => [],
			],
		]);
		$this->assert_batch_matches_reference(
			[ $fr_id, $de_id ],
			[ $fr_id => 'course', $de_id => 'course' ]
		);
	}

	public function test_batch_resolves_variation_via_parent_and_variation_terms(): void {
		$parent = 93001;
		$variation = 93002;
		$GLOBALS['intersoccer_test_post_parents'][ $variation ] = $parent;
		InterSoccerRRTestMeta::$data[ $parent ]['_intersoccer_product_type'] = 'course';
		$this->seed_terms_fixture([
			$parent => [
				'pa_activity-type' => [ $this->term(601, 'course', 'Course') ],
				'product_cat' => [ $this->term(701, 'courses', 'Courses') ],
			],
			$variation => [
				'pa_activity-type' => [],
				'product_cat' => [],
			],
		]);
		intersoccer_rr_product_type_memo_begin();
		intersoccer_rr_product_type_batch_load_for_post_ids([ $parent, $variation ]);
		$safe = intersoccer_get_product_type_safe($parent, $variation);
		intersoccer_rr_product_type_memo_end();
		$this->assertSame('course', $safe);
	}

	public function test_batch_corrects_camp_meta_with_birthday_signals(): void {
		$id = 94001;
		InterSoccerRRTestMeta::$data[ $id ]['_intersoccer_product_type'] = 'camp';
		$this->seed_terms_fixture([
			$id => [
				'pa_activity-type' => [ $this->term(801, 'birthday-party', 'Birthday Party') ],
				'product_cat' => [ $this->term(802, 'birthdays', 'Birthdays') ],
			],
		]);
		$this->assert_batch_matches_reference([ $id ], [ $id => 'birthday' ]);
	}

	public function test_batch_falls_back_to_product_categories(): void {
		$id = 95001;
		$this->seed_terms_fixture([
			$id => [
				'pa_activity-type' => [],
				'product_cat' => [ $this->term(901, 'camps', 'Camps') ],
			],
		]);
		$this->assert_batch_matches_reference([ $id ], [ $id => 'camp' ]);
	}
}
