<?php
/**
 * RR #178: roster `term` must store English camp_terms (not FR/DE order-line slug).
 */

namespace InterSoccer\ReportsRosters\Tests\Services;

use Brain\Monkey\Functions;
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

        Functions\when('remove_accents')->alias(static function ($string) {
            if (!is_string($string) || !class_exists('\Normalizer')) {
                return $string;
            }
            $n = \Normalizer::normalize($string, \Normalizer::FORM_D);

            return $n === false ? $string : (preg_replace('/\p{Mn}+/u', '', $n) ?? $string);
        });
        Functions\when('wc_get_product')->justReturn(null);
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_terms_by_slug'],
            $GLOBALS['intersoccer_test_terms_by_id'],
            $GLOBALS['intersoccer_test_terms_by_name']
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
                    'name' => 'Easter',
                    'wpml_lang' => 'en',
                ],
                'ostern' => (object) [
                    'term_id' => 8900,
                    'slug' => 'ostern',
                    'name' => 'Easter',
                    'wpml_lang' => 'en',
                ],
            ],
        ];
        $GLOBALS['intersoccer_test_terms_by_id'] = [
            8900 => $easter,
        ];
        $GLOBALS['intersoccer_test_terms_by_name'] = [
            'pa_camp-terms' => [
                'easter' => $easter,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCampRoster(string $camp_terms_slug): array {
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
        $order_data = [
            'activity_type' => 'Camp',
            'camp_terms' => $camp_terms_slug,
            'booking_type' => 'Full Week',
            'product_id' => 92001,
            'variation_id' => 0,
            'order_id' => 92002,
            'order_item_id' => 92003,
        ];

        $method = (new ReflectionClass($this->rosterBuilder))->getMethod('buildRosterData');
        $method->setAccessible(true);

        return $method->invoke($this->rosterBuilder, $order_data, $player, $customer);
    }

    public function test_rr178_fr_de_camp_term_is_english_camp_terms(): void {
        foreach (['paques', 'ostern'] as $localized_slug) {
            $row = $this->buildCampRoster($localized_slug);
            $this->assertSame('Easter', $row['camp_terms'], $localized_slug . ' camp_terms');
            $this->assertSame('Easter', $row['term'], $localized_slug . ' term');
            $this->assertSame($row['camp_terms'], $row['term'], $localized_slug . ' term vs camp_terms');
        }

        $en_row = $this->buildCampRoster('easter');
        $this->assertSame('Easter', $en_row['camp_terms']);
        $this->assertSame('Easter', $en_row['term']);
        $this->assertSame($en_row['camp_terms'], $en_row['term']);
    }
}
