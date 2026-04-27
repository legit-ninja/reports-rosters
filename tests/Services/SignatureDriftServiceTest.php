<?php

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Services\SignatureDriftService;
use InterSoccer\ReportsRosters\Services\EventSignatureGenerator;
use InterSoccer\ReportsRosters\Core\Logger;
use Mockery;

class SignatureDriftServiceTest extends TestCase {
    public function test_event_data_from_row_maps_keys(): void {
        $row = [
            'activity_type' => 'Camp',
            'venue' => 'Geneva',
            'age_group' => 'U10',
            'camp_terms' => 'Week1',
            'course_day' => 'Monday',
            'times' => 'Morning',
            'season' => '2026',
            'girls_only' => 1,
            'city' => 'Geneva',
            'canton_region' => 'GE',
            'product_id' => 42,
            'start_date' => '2026-07-01',
            'event_signature' => 'abc',
        ];
        $data = SignatureDriftService::eventDataFromRow($row);
        $this->assertSame('Camp', $data['activity_type']);
        $this->assertSame(1, $data['girls_only']);
        $this->assertSame(42, $data['product_id']);
        $this->assertSame('2026-07-01', $data['start_date']);
    }

    public function test_row_has_drift_when_signatures_differ(): void {
        $gen = Mockery::mock(EventSignatureGenerator::class);
        $gen->shouldReceive('generate')->once()->andReturn('expected_sig_123');

        $service = new SignatureDriftService(new Logger(), $gen);
        $row = [
            'activity_type' => 'Course',
            'venue' => 'x',
            'age_group' => 'y',
            'camp_terms' => '',
            'course_day' => 'Mon',
            'times' => 't',
            'season' => 's',
            'girls_only' => 0,
            'city' => '',
            'canton_region' => '',
            'product_id' => 1,
            'start_date' => '',
            'event_signature' => 'old_sig',
        ];
        $this->assertTrue($service->rowHasDrift($row));
    }

    public function test_row_has_no_drift_when_signatures_match(): void {
        $gen = Mockery::mock(EventSignatureGenerator::class);
        $gen->shouldReceive('generate')->once()->andReturn('same_sig');

        $service = new SignatureDriftService(new Logger(), $gen);
        $row = [
            'activity_type' => 'Course',
            'venue' => 'x',
            'age_group' => 'y',
            'camp_terms' => '',
            'course_day' => 'Mon',
            'times' => 't',
            'season' => 's',
            'girls_only' => 0,
            'city' => '',
            'canton_region' => '',
            'product_id' => 1,
            'start_date' => '',
            'event_signature' => 'same_sig',
        ];
        $this->assertFalse($service->rowHasDrift($row));
    }

    public function test_event_data_from_row_includes_start_date_for_tournaments(): void {
        $row = [
            'activity_type' => 'Tournament',
            'venue' => 'Zurich',
            'age_group' => 'U12',
            'camp_terms' => '',
            'course_day' => '',
            'times' => '10:00',
            'season' => '2026',
            'girls_only' => 0,
            'city' => '',
            'canton_region' => '',
            'product_id' => 9,
            'start_date' => '2026-08-15',
            'event_signature' => 'sig',
        ];
        $data = SignatureDriftService::eventDataFromRow($row);
        $this->assertSame('2026-08-15', $data['start_date']);

        $gen = Mockery::mock(EventSignatureGenerator::class);
        $gen->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(static function ($arg) {
                return is_array($arg) && ($arg['start_date'] ?? null) === '2026-08-15';
            }))
            ->andReturn('tournament_sig');

        $service = new SignatureDriftService(new Logger(), $gen);
        $this->assertFalse($service->rowHasDrift($row + ['event_signature' => 'tournament_sig']));
    }

    public function test_row_has_no_drift_when_expected_empty(): void {
        $gen = Mockery::mock(EventSignatureGenerator::class);
        $gen->shouldReceive('generate')->once()->andReturn('');

        $service = new SignatureDriftService(new Logger(), $gen);
        $row = [
            'activity_type' => 'Course',
            'venue' => 'x',
            'age_group' => 'y',
            'camp_terms' => '',
            'course_day' => 'Mon',
            'times' => 't',
            'season' => 's',
            'girls_only' => 0,
            'city' => '',
            'canton_region' => '',
            'product_id' => 1,
            'start_date' => '',
            'event_signature' => 'anything',
        ];
        $this->assertFalse($service->rowHasDrift($row));
    }
}
