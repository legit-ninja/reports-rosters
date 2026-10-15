<?php
/**
 * Single-day camp handling in RosterBuilder: no guessed days, German label, days_unknown flag.
 * Kept separate from RosterBuilderTest (which has older failing tests) so CI's Production suite runs it.
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use Brain\Monkey\Functions;
use Mockery;

class RosterBuilderSingleDayTest extends TestCase {

    private $rosterBuilder;

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('intersoccer_roster_days_unknown')) {
            require_once dirname(__DIR__, 2) . '/includes/utils.php';
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
        Functions\when('remove_accents')->alias(function ($string) {
            if (!is_string($string) || !class_exists('\Normalizer')) {
                return $string;
            }
            $n = \Normalizer::normalize($string, \Normalizer::FORM_D);
            return $n === false ? $string : (preg_replace('/\p{Mn}+/u', '', $n) ?? $string);
        });
    }

    private function call(string $method, ...$args) {
        $m = (new \ReflectionClass($this->rosterBuilder))->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->rosterBuilder, ...$args);
    }

    private function meta($key, $value) {
        $meta = Mockery::mock();
        $meta->shouldReceive('get_data')->andReturn(['key' => $key, 'value' => $value]);
        return $meta;
    }

    private function campItem(array $metas, string $days_of_week) {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn(null);
        $item->shouldReceive('get_variation_id')->andReturn(0);
        $item->shouldReceive('get_product_id')->andReturn(303);
        $item->shouldReceive('get_meta_data')->andReturn($metas);
        $item->shouldReceive('get_meta')->with('Days of Week', true)->andReturn($days_of_week);
        $item->shouldReceive('get_meta')->andReturn('');
        return $item;
    }

    private function order() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1001);
        $order->shouldReceive('get_customer_id')->andReturn(99);
        $order->shouldReceive('get_status')->andReturn('completed');
        return $order;
    }

    public function test_extractOrderData_does_not_guess_days_from_days_of_week_when_days_selected_empty() {
        $item = $this->campItem([
            $this->meta('Booking Type', 'Single Day(s)'),
            $this->meta('Activity Type', 'Camp'),
            $this->meta('Days of Week', 'Monday, Wednesday, Friday'),
        ], 'Monday, Wednesday, Friday');

        $result = $this->call('extractOrderItemData', $this->order(), 7619, $item);

        $this->assertSame('Single Day(s)', $result['booking_type']);
        $this->assertSame('', (string) ($result['selected_days'] ?? ''), 'Days of Week must not be used as the booked days');
    }

    public function test_extractOrderData_keeps_days_selected() {
        $item = $this->campItem([
            $this->meta('Booking Type', 'Single Day(s)'),
            $this->meta('Activity Type', 'Camp'),
            $this->meta('Days Selected', 'Tuesday, Thursday'),
            $this->meta('Days of Week', 'Monday, Tuesday, Wednesday, Thursday, Friday'),
        ], 'Monday, Tuesday, Wednesday, Thursday, Friday');

        $result = $this->call('extractOrderItemData', $this->order(), 7620, $item);

        $this->assertSame('Tuesday, Thursday', $result['selected_days']);
    }

    /**
     * @dataProvider germanSingleDayLabels
     */
    public function test_german_single_day_label_normalizes_to_single_days($label) {
        $this->assertSame('Single Day(s)', $this->call('normalizeBookingTypeValue', $label));
    }

    public function germanSingleDayLabels() {
        return [['Einzelner Tage'], ['einzelner-tage'], ['Einzelne Tage'], ['Einzeltag']];
    }

    public function test_full_week_label_is_unchanged() {
        $this->assertSame('Full Week', $this->call('normalizeBookingTypeValue', 'Ganze Woche'));
    }

    public function test_event_details_flag_days_unknown_for_single_day_camp_without_days() {
        $details = $this->call('buildEventDetails', [
            'activity_type' => 'camp',
            'booking_type' => 'Single Day(s)',
            'selected_days' => '',
        ]);
        $this->assertArrayHasKey('days_unknown', $details);
        $this->assertTrue($details['days_unknown']);
    }

    public function test_event_details_flag_days_unknown_for_german_label() {
        $details = $this->call('buildEventDetails', [
            'activity_type' => 'camp',
            'booking_type' => 'Einzelner Tage',
            'selected_days' => '',
        ]);
        $this->assertTrue($details['days_unknown'] ?? false);
    }

    public function test_event_details_no_flag_when_days_known_or_full_week() {
        $known = $this->call('buildEventDetails', [
            'activity_type' => 'camp', 'booking_type' => 'Single Day(s)', 'selected_days' => 'Monday',
        ]);
        $this->assertArrayNotHasKey('days_unknown', $known);
        $week = $this->call('buildEventDetails', [
            'activity_type' => 'camp', 'booking_type' => 'Full Week', 'selected_days' => '',
        ]);
        $this->assertArrayNotHasKey('days_unknown', $week);
    }
}
