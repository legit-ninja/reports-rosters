<?php
/**
 * Regression: unparseable start/end dates must not raise an undefined-key notice (#158).
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;

class RosterBuilderParseDates158Test extends TestCase {

    public function test_unparseable_dates_warn_with_original_value_and_no_undefined_key(): void {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info', 'debug', 'warning', 'error')->andReturn(null);
        $builder = new RosterBuilder(
            $logger,
            Mockery::mock(Database::class),
            Mockery::mock(PlayerRepository::class),
            Mockery::mock(RosterRepository::class),
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        $bad_start = 'not-a-real-start';
        $bad_end = 'not-a-real-end';
        $notices = [];
        set_error_handler(static function ($severity, $message) use (&$notices) {
            $notices[] = (string) $message;
            return true;
        });
        try {
            $method = new ReflectionMethod(RosterBuilder::class, 'parseDates');
            $method->setAccessible(true);
            $result = $method->invoke($builder, [
                'activity_type' => 'Course',
                'start_date' => $bad_start,
                'end_date' => $bad_end,
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('start_date', $result);
        $this->assertArrayNotHasKey('end_date', $result);

        foreach ($notices as $message) {
            $this->assertStringNotContainsString('Undefined array key', $message);
            $this->assertStringNotContainsString('Undefined index', $message);
        }

        $stats = new ReflectionProperty(RosterBuilder::class, 'build_stats');
        $stats->setAccessible(true);
        $warnings = $stats->getValue($builder)['warnings'] ?? [];
        $warning_text = implode("\n", $warnings);
        $this->assertStringContainsString('Could not parse date: ' . $bad_start, $warning_text);
        $this->assertStringContainsString('Could not parse date: ' . $bad_end, $warning_text);
    }
}
