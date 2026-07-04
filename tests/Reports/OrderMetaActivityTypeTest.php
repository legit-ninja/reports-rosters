<?php
/**
 * Activity type composite handling for legacy roster paths.
 */

namespace InterSoccer\Reports\Tests\Reports;

use PHPUnit\Framework\TestCase;

class OrderMetaActivityTypeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $utils = dirname(__DIR__, 2) . '/includes/utils.php';
        if (file_exists($utils)) {
            require_once $utils;
        }
    }

    public function test_canonical_activity_type_maps_girls_only_camp_composite() {
        if (!function_exists('intersoccer_canonical_activity_type_for_roster')) {
            $this->markTestSkipped('intersoccer_canonical_activity_type_for_roster not loaded');
        }

        $this->assertSame(
            'Camp, Girls Only',
            intersoccer_canonical_activity_type_for_roster('Camp, Girls Only')
        );
        $this->assertSame(
            'Camp, Girls Only',
            intersoccer_canonical_activity_type_for_roster('camp girls only')
        );
    }

    public function test_roster_listing_activity_types_includes_girls_only_camp_variants() {
        if (!function_exists('intersoccer_roster_listing_activity_types')) {
            $this->markTestSkipped('intersoccer_roster_listing_activity_types not loaded');
        }

        $types = intersoccer_roster_listing_activity_types('camp');
        $this->assertContains('Camp, Girls Only', $types);
        $this->assertContains('Camp', $types);
    }
}
