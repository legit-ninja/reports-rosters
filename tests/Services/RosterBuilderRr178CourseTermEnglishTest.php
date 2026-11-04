<?php
/**
 * RR #178: roster `term` must store English course day (not FR/DE order-line slug).
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

class RosterBuilderRr178CourseTermEnglishTest extends TestCase {

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

    private function registerCourseDayTerms(): void {
        $sunday = (object) [
            'term_id' => 8800,
            'slug' => 'sunday',
            'name' => 'Sunday',
            'wpml_lang' => 'en',
        ];
        $GLOBALS['intersoccer_test_terms_by_slug'] = [
            'pa_course-day' => [
                'sunday' => $sunday,
                'dimanche' => (object) [
                    'term_id' => 8800,
                    'slug' => 'dimanche',
                    'name' => 'Dimanche',
                ],
                'sonntag' => (object) [
                    'term_id' => 8800,
                    'slug' => 'sonntag',
                    'name' => 'Sonntag',
                ],
            ],
        ];
        $GLOBALS['intersoccer_test_terms_by_id'] = [
            8800 => $sunday,
        ];
        $GLOBALS['intersoccer_test_terms_by_name'] = [
            'pa_course-day' => [
                'sunday' => $sunday,
                'dimanche' => $GLOBALS['intersoccer_test_terms_by_slug']['pa_course-day']['dimanche'],
                'sonntag' => $GLOBALS['intersoccer_test_terms_by_slug']['pa_course-day']['sonntag'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $order_overrides
     * @return array<string,mixed>
     */
    private function buildCourseRoster(array $order_overrides): array {
        $this->registerCourseDayTerms();

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
            'activity_type' => 'Course',
            'product_id' => 91001,
            'variation_id' => 0,
            'order_id' => 91002,
            'order_item_id' => 91003,
        ], $order_overrides);

        $method = (new ReflectionClass($this->rosterBuilder))->getMethod('buildRosterData');
        $method->setAccessible(true);

        return $method->invoke($this->rosterBuilder, $order_data, $player, $customer);
    }

    public function test_rr178_fr_de_course_term_is_english_day(): void {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $fr_row = $this->buildCourseRoster(['course_day' => 'dimanche']);
        $this->assertSame('Sunday', $fr_row['course_day']);
        $this->assertSame('Sunday', $fr_row['term']);

        $GLOBALS['intersoccer_test_wpml_current_language'] = 'de';
        $de_row = $this->buildCourseRoster(['course_day' => 'sonntag']);
        $this->assertSame('Sunday', $de_row['course_day']);
        $this->assertSame('Sunday', $de_row['term']);

        $GLOBALS['intersoccer_test_wpml_current_language'] = 'en';
        $en_row = $this->buildCourseRoster(['course_day' => 'sunday']);
        $this->assertSame('Sunday', $en_row['course_day']);
        $this->assertSame('Sunday', $en_row['term']);
    }

    public function test_rr178_empty_activity_type_normalizes_course_term_to_english(): void {
        $GLOBALS['intersoccer_test_wpml_current_language'] = 'fr';
        $row = $this->buildCourseRoster(['course_day' => 'dimanche', 'activity_type' => '']);
        $this->assertSame('Sunday', $row['term']);
    }
}
