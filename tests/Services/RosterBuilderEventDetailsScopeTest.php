<?php
/**
 * Stop copying the full roster row into event_details (data bug regression).
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventDetailsCleanupService;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;

require_once dirname(__DIR__, 2) . '/includes/event-details-cleanup-service.php';

class RosterBuilderEventDetailsScopeTest extends TestCase {

    private RosterBuilder $rosterBuilder;

    protected function setUp(): void {
        parent::setUp();
        $includes = dirname(__DIR__, 2) . '/includes';
        if (file_exists($includes . '/utils.php')) {
            require_once $includes . '/utils.php';
        }
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $this->rosterBuilder = new RosterBuilder(
            $logger,
            Mockery::mock(Database::class),
            Mockery::mock(PlayerRepository::class),
            Mockery::mock(RosterRepository::class),
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildRowEventDetails(): array {
        $order_data = [
            'order_id' => 919191,
            'order_item_id' => 828282,
            'product_id' => 303303,
            'variation_id' => 0,
            'customer_id' => 737373,
            'order_status' => 'completed',
            'activity_type' => 'Camp',
            'event_type' => 'Full Week',
            'booking_type' => 'Full Week',
            'camp_times' => '09:00-16:00',
            'selected_days' => 'Mon,Tue',
            'season' => 'SYNTH_SEASON_ZZ',
            'region' => 'SYNTH_REGION_AA',
            'city' => 'SYNTH_CITY_BB',
            'discount_applied' => 'SYNTH_DISCOUNT_CC',
            'venue' => 'SYNTH_VENUE_DD',
            'age_group' => 'U10',
            'start_date' => '2030-06-01',
            'line_subtotal' => 100.0,
            'line_total' => 0.0,
            '_catalog_product_resolved' => true,
        ];
        $player = new Player([
            'customer_id' => 737373,
            'player_index' => 0,
            'first_name' => 'SYNTH_FIRST_XX',
            'last_name' => 'SYNTH_LAST_YY',
            'dob' => '2015-01-01',
            'gender' => 'male',
            'medical_conditions' => '',
            'dietary_needs' => '',
        ]);
        $customer = [
            'email' => 'synthetic-not-real@example.invalid',
            'phone' => '0000000000',
            'first_name' => 'SYNTH_PARENT_AA',
            'last_name' => 'SYNTH_PARENT_BB',
            'emergency_contact' => 'SYNTH_PARENT_AA SYNTH_PARENT_BB',
            'emergency_phone' => '0000000000',
        ];
        $method = (new \ReflectionClass($this->rosterBuilder))->getMethod('buildRosterData');
        $method->setAccessible(true);
        $row = $method->invoke($this->rosterBuilder, $order_data, $player, $customer);
        $event_details = $row['event_details'] ?? [];
        if (is_string($event_details)) {
            $decoded = json_decode($event_details, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($event_details) ? $event_details : [];
    }

    public function test_event_details_contains_only_legitimate_keys_not_roster_row_columns(): void {
        $event_details = $this->buildRowEventDetails();
        $this->assertIsArray($event_details);
        $this->assertNotEmpty($event_details);

        $legit = array_flip(EventDetailsCleanupService::legitimateEventDetailKeys());
        foreach (array_keys($event_details) as $key) {
            $this->assertArrayHasKey(
                $key,
                $legit,
                sprintf('event_details must not include roster-row key "%s"', (string) $key)
            );
        }

        $this->assertArrayNotHasKey('player_first_name', $event_details);
        $this->assertArrayNotHasKey('order_id', $event_details);
        $this->assertArrayNotHasKey('parent_email', $event_details);
        $this->assertArrayNotHasKey('venue', $event_details);
        $this->assertNotEmpty($event_details['season'] ?? '');
        $this->assertTrue(!empty($event_details['is_buyclub']), 'BuyClub flag should still be set on event_details');
    }
}
