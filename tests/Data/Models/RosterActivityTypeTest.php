<?php
/**
 * Roster activity_type allow-list.
 *
 * Synthetic data only.
 */

namespace InterSoccer\ReportsRosters\Tests\Data\Models;

use InterSoccer\ReportsRosters\Data\Models\Roster;
use InterSoccer\ReportsRosters\Exceptions\ValidationException;
use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterActivityTypeTest extends TestCase {
    /**
     * @return array<string,array{0:string}>
     */
    public function canonicalActivityTypes(): array {
        $cases = [];
        foreach (Roster::CANONICAL_ACTIVITY_TYPES as $type) {
            $cases[$type] = [$type];
        }
        return $cases;
    }

    /**
     * @dataProvider canonicalActivityTypes
     */
    public function test_canonical_activity_type_validates(string $activity_type): void {
        $this->assertTrue($this->roster($activity_type)->validate());
    }

    public function test_unknown_activity_type_is_rejected(): void {
        $roster = $this->roster('Not A Type');
        try {
            $roster->validate();
            $this->fail('An unknown activity type must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('activity_type', $e->getValidationErrors());
        }
    }

    public function test_canonical_girls_only_and_other_labels_validate_on_the_model(): void {
        $girls_only = intersoccer_canonical_activity_type_for_roster('girls only');
        $other = intersoccer_canonical_activity_type_for_roster('other');

        $this->assertSame('Girls Only', $girls_only);
        $this->assertSame('Other', $other);
        $this->assertTrue($this->roster($girls_only)->validate());
        $this->assertTrue($this->roster($other)->validate());
    }

    private function roster(string $activity_type): Roster {
        return new Roster([
            'order_id' => 1,
            'order_item_id' => 10,
            'product_id' => 100,
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'Synthetic',
            'last_name' => 'Player',
            'dob' => '2015-06-01',
            'gender' => 'female',
            'activity_type' => $activity_type,
            'venue' => 'Venue A',
            'order_status' => 'completed',
        ]);
    }
}
