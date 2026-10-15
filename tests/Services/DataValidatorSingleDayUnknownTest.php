<?php
/**
 * Single-day camp rows without selected days must pass roster validation (days_unknown).
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\DataValidator;

class DataValidatorSingleDayUnknownTest extends TestCase {

    private DataValidator $validator;

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_roster_days_unknown')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
        }
        $this->validator = new DataValidator();
    }

    public function test_validateRosterData_allows_single_day_without_selected_days() {
        $roster_data = [
            'order_id' => 9001,
            'order_item_id' => 9002,
            'product_id' => 303,
            'customer_id' => 42,
            'player_index' => 0,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'dob' => '2015-06-01',
            'gender' => 'male',
            'activity_type' => 'Camp',
            'venue' => 'Geneva - Stade de Varembé',
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-10',
            'booking_type' => 'Single Day(s)',
            'selected_days' => '',
            'order_status' => 'completed',
        ];

        $result = $this->validator->validateRosterData($roster_data);

        $this->assertTrue($result['valid']);
        $this->assertNotContains('Selected days are required for single day bookings', $result['errors']);
    }
}
