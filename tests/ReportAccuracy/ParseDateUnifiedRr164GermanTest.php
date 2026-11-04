<?php
/**
 * Regression: RR #164 — German month names in order-item Start/End dates must parse for roster rows.
 */

namespace InterSoccer\ReportsRosters\Tests\ReportAccuracy;

use Brain\Monkey\Functions;
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

class ParseDateUnifiedRr164GermanTest extends TestCase {

    private RosterBuilder $rosterBuilder;

    protected function setUp(): void {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/includes/utils.php';

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

        Functions\when('remove_accents')->alias(static function ($string) {
            if (!is_string($string)) {
                return $string;
            }
            if (!class_exists('\Normalizer')) {
                return $string;
            }
            $normalized = \Normalizer::isNormalized($string, \Normalizer::FORM_D)
                ? $string
                : \Normalizer::normalize($string, \Normalizer::FORM_D);
            if ($normalized === false) {
                return $string;
            }
            $without_accents = preg_replace('/\p{Mn}+/u', '', $normalized);

            return $without_accents === null ? $string : $without_accents;
        });
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function germanDateProvider(): array {
        return [
            'day dot januar' => ['5. Januar 2027', '2027-01-05'],
            'day dot maerz umlaut' => ['14. März 2027', '2027-03-14'],
            'day dot dezember' => ['1. Dezember 2026', '2026-12-01'],
            'weekday comma oktober' => ['Montag, 4. Oktober 2027', '2027-10-04'],
            'no dot mai' => ['3 Mai 2027', '2027-05-03'],
            'ascii maerz' => ['14. Maerz 2027', '2027-03-14'],
        ];
    }

    /**
     * @dataProvider germanDateProvider
     */
    public function test_rr164_parses_german_month_names(string $input, string $expected): void {
        $this->assertSame($expected, intersoccer_parse_date_unified($input, 'rr164-german'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function frenchAndEnglishDateProvider(): array {
        return [
            'english comma' => ['August 17, 2025', '2025-08-17'],
            'english iso' => ['2025-12-14', '2025-12-14'],
            'french janvier comma' => ['janvier 10, 2027', '2027-01-10'],
            'french day month year' => ['14 décembre 2025', '2025-12-14'],
        ];
    }

    /**
     * @dataProvider frenchAndEnglishDateProvider
     */
    public function test_rr164_french_and_english_unchanged(string $input, string $expected): void {
        $this->assertSame($expected, intersoccer_parse_date_unified($input, 'rr164-regression'));
    }

    public function test_rr164_builder_sets_dates_for_de_course_line(): void {
        $method = new \ReflectionMethod(RosterBuilder::class, 'parseDates');
        $method->setAccessible(true);

        $data = [
            'activity_type' => 'Course',
            'start_date' => '5. Januar 2027',
            'end_date' => '14. März 2027',
        ];

        $parsed = $method->invoke($this->rosterBuilder, $data);

        $this->assertSame('2027-01-05', $parsed['start_date'] ?? null);
        $this->assertSame('2027-03-14', $parsed['end_date'] ?? null);
    }
}
