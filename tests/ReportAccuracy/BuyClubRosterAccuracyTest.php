<?php

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;

class BuyClubRosterAccuracyTest extends TestCase {

    public function test_roster_event_details_is_buyclub_uses_shared_helper(): void {
        $includes = dirname(__DIR__, 2) . '/includes';
        if (file_exists($includes . '/utils.php')) {
            require_once $includes . '/utils.php';
        }

        $logger = $this->createMock(Logger::class);
        $database = $this->createMock(Database::class);
        $builder = new RosterBuilder(
            $logger,
            $database,
            $this->createMock(PlayerRepository::class),
            $this->createMock(RosterRepository::class),
            $this->createMock(DataValidator::class),
            $this->createMock(EventMatcher::class),
            $this->createMock(PlayerMatcher::class)
        );

        $method = new \ReflectionMethod(RosterBuilder::class, 'applyBuyClubToEventDetails');
        $method->setAccessible(true);

        $roster_data = [
            'order_id' => 42,
            'order_item_id' => 99,
            'line_subtotal' => 495.0,
            'line_total' => 0.0,
        ];
        $details = $method->invoke($builder, [], $roster_data);

        $this->assertIsArray($details);
        $this->assertTrue(!empty($details['is_buyclub']), 'Expected event_details.is_buyclub from intersoccer_reports_line_is_buyclub');
    }
}
