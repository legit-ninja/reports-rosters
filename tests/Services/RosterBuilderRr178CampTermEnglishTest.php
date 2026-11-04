<?php
/**
 * RR #178: roster `term` must store English camp_terms (not FR/DE order-line slug).
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use InterSoccer\ReportsRosters\Core\Database;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Models\Player;
use InterSoccer\ReportsRosters\Data\Repositories\PlayerRepository;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\DataValidator;
use InterSoccer\ReportsRosters\Services\EventMatcher;
use InterSoccer\ReportsRosters\Services\PlayerMatcher;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Tests\TestCase;
use Mockery;
use ReflectionClass;

class RosterBuilderRr178CampTermEnglishTest extends TestCase {

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
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_terms_by_slug'],
            $GLOBALS['intersoccer_test_terms_by_id'],
            $GLOBALS['intersoccer_test_terms_by_name'],
            $GLOBALS['intersoccer_test_wpml_current_language']
        );
        parent::tearDown();
    }

    private function registerCampTerms(): void {
        $easter = (object) [
            'term_id' => 8900,
            'slug' => 'easter',
            'name' => 'Easter',
            'wpml_lang' => 'en',
        ];
        $GLOBALS['intersoccer_test_terms_by_slug'] = [
            'pa_camp-terms' => [
                'easter' => $easter,
                'paques' => (object) [
                    'term_id' => 8900,
                    'slug' => 'paques',
                    'name' => 'Pâques',
                ],
                'ostern' => (object) [
                    'term_id' => 8900,
                    'slug' => 'ostern',
                    'name' => 'Ostern',
                ],
            ],
        ];
        $GLOBALS['intersoccer_test_terms_by_id'] = [
            8900 => $easter,
        ];
        $GLOBALS['intersoccer_test_terms_by_name'] = [
            'pa_camp-terms' => [
                'easter' => $easter,
                'pâques' => $GLOBALS['intersoccer_test_terms_by_slug']['pa_camp-terms']['paques'],
                'ostern' => $GLOBALS['intersoccer_test_terms_by_slug']['pa_camp-terms']['ostern'],
            ],
        ];
    }

    private function registerStaleCourseDayPollution(): void {
        $sunday = (object) [
            'term_id' => 8800,
            'slug' => 'sunday',
            'name' => 'Sunday',
            'wpml_lang' => 'en',
        ];
        $GLOBALS['intersoccer_test_terms_by_slug']['pa_course-day'] = [
            'dimanche' => (object) [
                'term_id' => 8800,
                'slug' => 'dimanche',
                'name' => 'Dimanche',
            ],
        ];
        $GLOBALS['intersoccer_test_terms_by_id'][8800] = $sunday;
    }

    /**
     * @param array<string,mixed> $order_overrides
     * @return array<string,mixed>
     */
    private function buildCampRoster(array $order_overrides): array {
        $this->registerCampTerms();

        $player = new Player([
            'customer_id' => 1,
            'player_index' => 0,
            'first_name' => 'Test',
            'last_name' => 'Player',
            'dob' => '2015-06-01',
            'gender' => 'male',
        ]);
        $customer = [
            'email' => 'parent@example.com',
            'phone' => '0790000000',
            'first_name' => 'Parent',
            'last_name' => 'Example',
            'emergency_contact' => 'Parent Example',
            'emergency_phone' => '0790000000',
        ];
        $order_data = array_merge([
            'activity_type' => 'Camp',
            'booking_type' => 'Full Week',
            'product_id' => 92001,
            'variation_id' => 0,
            'order_id' => 92002,
            'order_item_id' => 92003,
        ], $order_overrides);

        $method = (new ReflectionClass($this->rosterBuilder))->getMethod('buildRosterData');
        $method->setAccessible(true);

        return $method->invoke($this->rosterBuilder, $order_data, $player, $customer);
    }

    public function test_rr178_fr_de_camp_term_is_english_camp_terms(): void {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $fr_row = $this->buildCampRoster(['camp_terms' => 'paques']);
        $this->assertSame('Easter', $fr_row['camp_terms']);
        $this->assertSame('Easter', $fr_row['term']);

        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $de_row = $this->buildCampRoster(['camp_terms' => 'ostern']);
        $this->assertSame('Easter', $de_row['camp_terms']);
        $this->assertSame('Easter', $de_row['term']);

        $GLOBALS['intersoccer_test_wpml_current_language'] = 'en';
        $en_row = $this->buildCampRoster(['camp_terms' => 'easter']);
        $this->assertSame('Easter', $en_row['camp_terms']);
        $this->assertSame('Easter', $en_row['term']);
    }

    public function test_rr178_camp_term_uses_camp_terms_when_course_day_also_present(): void {
        $this->registerCampTerms();
        $this->registerStaleCourseDayPollution();
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';

        $row = $this->buildCampRoster([
            'camp_terms' => 'paques',
            'course_day' => 'dimanche',
            'activity_type' => '',
        ]);

        $this->assertSame('Easter', $row['term']);
    }
}
