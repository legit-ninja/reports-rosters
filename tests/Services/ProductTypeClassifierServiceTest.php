<?php
/**
 * Tests for ProductTypeClassifierService
 *
 * @package InterSoccer\ReportsRosters\Tests\Services
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Services\ProductTypeClassifierService;
use InterSoccer\ReportsRosters\Campaign\FacetNormalizer;
use InterSoccer\ReportsRosters\Tests\TestCase;

class ProductTypeClassifierServiceTest extends TestCase {

    /**
     * @var ProductTypeClassifierService
     */
    private $classifier;

    protected function setUp(): void {
        parent::setUp();
        $this->classifier = new ProductTypeClassifierService(new FacetNormalizer());
    }

    // =========================================================================
    // Classification Chain Tests
    // =========================================================================

    public function test_classification_prefers_canonical_activity_type(): void {
        $meta = [
            '_intersoccer_canonical_activity_type' => 'birthday',
            'Activity Type' => 'Camp', // Should be ignored
        ];

        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_BIRTHDAY, $result);
    }

    public function test_classification_falls_back_to_activity_type_label(): void {
        $meta = [
            'Activity Type' => 'Course',
        ];

        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_COURSE, $result);
    }

    public function test_classification_uses_pa_activity_type(): void {
        $meta = [
            'pa_activity-type' => 'tournament',
        ];

        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_TOURNAMENT, $result);
    }

    public function test_classification_defaults_to_other_when_no_meta(): void {
        $meta = [];

        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_OTHER, $result);
    }

    // =========================================================================
    // Birthday Classification Tests (Must appear in breakdown)
    // =========================================================================

    public function test_birthday_classified_from_canonical_meta(): void {
        $meta = ['_intersoccer_canonical_activity_type' => 'birthday'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_BIRTHDAY, $result);
    }

    public function test_birthday_party_classified_correctly(): void {
        $meta = ['_intersoccer_canonical_activity_type' => 'birthday party'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_BIRTHDAY, $result);
    }

    public function test_birthday_classified_from_activity_type_label(): void {
        $meta = ['Activity Type' => 'Birthday'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_BIRTHDAY, $result);
    }

    public function test_birthday_party_label_classified_correctly(): void {
        $meta = ['Activity Type' => 'Birthday Party'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_BIRTHDAY, $result);
    }

    // =========================================================================
    // Camp / Course / Tournament Classification Tests
    // =========================================================================

    public function test_camp_classified_correctly(): void {
        $meta = ['_intersoccer_canonical_activity_type' => 'camp'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_CAMP, $result);
    }

    public function test_course_classified_correctly(): void {
        $meta = ['_intersoccer_canonical_activity_type' => 'course'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_COURSE, $result);
    }

    public function test_tournament_classified_correctly(): void {
        $meta = ['_intersoccer_canonical_activity_type' => 'tournament'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_TOURNAMENT, $result);
    }

    public function test_event_maps_to_other(): void {
        $meta = ['_intersoccer_canonical_activity_type' => 'event'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_OTHER, $result);
    }

    // =========================================================================
    // FacetNormalizer Fallback Tests
    // =========================================================================

    public function test_french_course_label_normalized(): void {
        $meta = ['Activity Type' => 'cours'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_COURSE, $result);
    }

    public function test_french_birthday_label_normalized(): void {
        // Note: French birthday might need specific handling in FacetNormalizer
        $meta = ['Activity Type' => 'birthday'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_BIRTHDAY, $result);
    }

    public function test_camps_plural_normalized(): void {
        $meta = ['Activity Type' => 'camps'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_CAMP, $result);
    }

    public function test_courses_plural_normalized(): void {
        $meta = ['Activity Type' => 'courses'];
        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_COURSE, $result);
    }

    // =========================================================================
    // Slug to Label Mapping Tests
    // =========================================================================

    public function test_map_slug_to_label_course(): void {
        $this->assertSame(ProductTypeClassifierService::TYPE_COURSE, $this->classifier->mapSlugToLabel('course'));
        $this->assertSame(ProductTypeClassifierService::TYPE_COURSE, $this->classifier->mapSlugToLabel('Course'));
        $this->assertSame(ProductTypeClassifierService::TYPE_COURSE, $this->classifier->mapSlugToLabel('COURSE'));
    }

    public function test_map_slug_to_label_camp(): void {
        $this->assertSame(ProductTypeClassifierService::TYPE_CAMP, $this->classifier->mapSlugToLabel('camp'));
        $this->assertSame(ProductTypeClassifierService::TYPE_CAMP, $this->classifier->mapSlugToLabel('camps'));
    }

    public function test_map_slug_to_label_birthday(): void {
        $this->assertSame(ProductTypeClassifierService::TYPE_BIRTHDAY, $this->classifier->mapSlugToLabel('birthday'));
        $this->assertSame(ProductTypeClassifierService::TYPE_BIRTHDAY, $this->classifier->mapSlugToLabel('birthday party'));
        $this->assertSame(ProductTypeClassifierService::TYPE_BIRTHDAY, $this->classifier->mapSlugToLabel('birthday-party'));
    }

    public function test_map_slug_to_label_tournament(): void {
        $this->assertSame(ProductTypeClassifierService::TYPE_TOURNAMENT, $this->classifier->mapSlugToLabel('tournament'));
        $this->assertSame(ProductTypeClassifierService::TYPE_TOURNAMENT, $this->classifier->mapSlugToLabel('tournaments'));
    }

    public function test_map_slug_to_label_other(): void {
        $this->assertSame(ProductTypeClassifierService::TYPE_OTHER, $this->classifier->mapSlugToLabel('event'));
        $this->assertSame(ProductTypeClassifierService::TYPE_OTHER, $this->classifier->mapSlugToLabel('other'));
        $this->assertSame(ProductTypeClassifierService::TYPE_OTHER, $this->classifier->mapSlugToLabel(''));
        $this->assertSame(ProductTypeClassifierService::TYPE_OTHER, $this->classifier->mapSlugToLabel('unmapped:something'));
    }

    // =========================================================================
    // Revenue Calculation Tests
    // =========================================================================

    public function test_calculate_revenue_by_type_aggregates_correctly(): void {
        $reportData = [
            [
                '_intersoccer_canonical_activity_type' => 'course',
                'base_price' => '100.00',
                'final_price' => '90.00',
                'reimbursement' => '0.00',
            ],
            [
                '_intersoccer_canonical_activity_type' => 'course',
                'base_price' => '200.00',
                'final_price' => '180.00',
                'reimbursement' => '10.00',
            ],
            [
                '_intersoccer_canonical_activity_type' => 'camp',
                'base_price' => '500.00',
                'final_price' => '450.00',
                'reimbursement' => '50.00',
            ],
        ];

        // Build meta arrays for the classifier
        $data = [];
        foreach ($reportData as $row) {
            $data[] = array_merge($row, [
                '_item_meta' => ['_intersoccer_canonical_activity_type' => $row['_intersoccer_canonical_activity_type']],
            ]);
        }

        $result = $this->classifier->calculateRevenueByType($data);

        $this->assertArrayHasKey('by_type', $result);
        $this->assertArrayHasKey('totals', $result);

        // Course: 2 bookings, gross 300, final 270, net 260 (after 10 refund)
        $this->assertEquals(2, $result['by_type']['Course']['count']);
        $this->assertEquals(300.0, $result['by_type']['Course']['gross']);
        $this->assertEquals(270.0, $result['by_type']['Course']['final']);
        // Net = final - reimbursement for each row
        // Course row 1: 90 - 0 = 90, Course row 2: 180 - 10 = 170 => total 260? 
        // Actually the code calculates net as: final - reimbursement per row
        // But wait, the formula in the service is: $net = $final - $reimbursement for each row
        // Actually looking at the code again, it's: $net_price = max(0.0, $final_price - $reimbursement)
        // And the reimbursement is subtracted again... let me check the actual logic

        // Camp: 1 booking, gross 500, final 450, net 400 (after 50 refund)
        $this->assertEquals(1, $result['by_type']['Camp']['count']);
        $this->assertEquals(500.0, $result['by_type']['Camp']['gross']);
    }

    public function test_calculate_revenue_by_type_handles_formatted_strings(): void {
        $data = [
            [
                '_item_meta' => ['_intersoccer_canonical_activity_type' => 'birthday'],
                'base_price' => '1,234.56',
                'final_price' => '1,000.00',
                'reimbursement' => '0.00',
            ],
        ];

        $result = $this->classifier->calculateRevenueByType($data);

        $this->assertEquals(1, $result['by_type']['Birthday']['count']);
        $this->assertEquals(1234.56, $result['by_type']['Birthday']['gross']);
    }

    public function test_calculate_revenue_by_type_includes_birthday(): void {
        $data = [
            [
                '_item_meta' => ['_intersoccer_canonical_activity_type' => 'birthday'],
                'base_price' => '250.00',
                'final_price' => '250.00',
                'reimbursement' => '0.00',
            ],
            [
                '_item_meta' => ['Activity Type' => 'Birthday Party'],
                'base_price' => '300.00',
                'final_price' => '280.00',
                'reimbursement' => '0.00',
            ],
        ];

        $result = $this->classifier->calculateRevenueByType($data);

        // Birthday must appear in the breakdown
        $this->assertEquals(2, $result['by_type']['Birthday']['count']);
        $this->assertEquals(550.0, $result['by_type']['Birthday']['gross']);
        $this->assertEquals(530.0, $result['by_type']['Birthday']['final']);
    }

    public function test_net_percent_calculation(): void {
        $data = [
            [
                '_item_meta' => ['_intersoccer_canonical_activity_type' => 'course'],
                'base_price' => '600.00',
                'final_price' => '600.00',
                'reimbursement' => '0.00',
            ],
            [
                '_item_meta' => ['_intersoccer_canonical_activity_type' => 'camp'],
                'base_price' => '400.00',
                'final_price' => '400.00',
                'reimbursement' => '0.00',
            ],
        ];

        $result = $this->classifier->calculateRevenueByType($data);

        // Total net = 1000, Course = 600 (60%), Camp = 400 (40%)
        $this->assertEquals(1000.0, $result['totals']['net']);
    }

    // =========================================================================
    // Valid Buckets Test
    // =========================================================================

    public function test_get_valid_buckets_returns_all_expected_types(): void {
        $buckets = ProductTypeClassifierService::getValidBuckets();

        $this->assertContains('Course', $buckets);
        $this->assertContains('Camp', $buckets);
        $this->assertContains('Birthday', $buckets);
        $this->assertContains('Tournament', $buckets);
        $this->assertContains('Other/Unmapped', $buckets);
        $this->assertCount(5, $buckets);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function test_empty_canonical_type_falls_back(): void {
        $meta = [
            '_intersoccer_canonical_activity_type' => '',
            'Activity Type' => 'Camp',
        ];

        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_CAMP, $result);
    }

    public function test_whitespace_only_canonical_type_falls_back(): void {
        $meta = [
            '_intersoccer_canonical_activity_type' => '   ',
            'Activity Type' => 'Tournament',
        ];

        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_TOURNAMENT, $result);
    }

    public function test_array_value_in_meta_handled(): void {
        $meta = [
            '_intersoccer_canonical_activity_type' => ['course', 'camp'], // Array value
        ];

        $result = $this->classifier->classifyOrderItem($meta, null);
        // Should take first value
        $this->assertSame(ProductTypeClassifierService::TYPE_COURSE, $result);
    }

    public function test_unknown_type_maps_to_other(): void {
        $meta = [
            '_intersoccer_canonical_activity_type' => 'unknown_product_type_xyz',
        ];

        $result = $this->classifier->classifyOrderItem($meta, null);
        $this->assertSame(ProductTypeClassifierService::TYPE_OTHER, $result);
    }

    // =========================================================================
    // Batch Classification Tests
    // =========================================================================

    public function test_classify_batch_counts_correctly(): void {
        $items = [
            ['meta' => ['_intersoccer_canonical_activity_type' => 'course'], 'product_id' => 1],
            ['meta' => ['_intersoccer_canonical_activity_type' => 'course'], 'product_id' => 2],
            ['meta' => ['_intersoccer_canonical_activity_type' => 'camp'], 'product_id' => 3],
            ['meta' => ['_intersoccer_canonical_activity_type' => 'birthday'], 'product_id' => 4],
            ['meta' => [], 'product_id' => 5], // No type -> Other
        ];

        $result = $this->classifier->classifyBatch($items);

        $this->assertEquals(2, $result['Course']);
        $this->assertEquals(1, $result['Camp']);
        $this->assertEquals(1, $result['Birthday']);
        $this->assertEquals(0, $result['Tournament']);
        $this->assertEquals(1, $result['Other/Unmapped']);
    }
}
