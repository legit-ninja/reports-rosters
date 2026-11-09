<?php
/**
 * RR #104 QA: roster rebuild blank-field variation fill via real RosterBuilder::buildRosterData.
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

class RosterBuilderMigrationBlankFillTest extends TestCase {

    private RosterBuilder $rosterBuilder;

    protected function setUp(): void {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/includes/order-meta-keys.php';
        require_once dirname(__DIR__, 2) . '/includes/migration-meta.php';
        require_once dirname(__DIR__, 2) . '/includes/utils.php';

        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('info')->andReturn(null);
        $logger->shouldReceive('debug')->andReturn(null);
        $logger->shouldReceive('warning')->andReturn(null);
        $logger->shouldReceive('error')->andReturn(null);

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

    protected function tearDown(): void {
        if (class_exists('\InterSoccerRRTestMeta')) {
            \InterSoccerRRTestMeta::$data = [];
        }
        unset(
            $GLOBALS['intersoccer_test_wc_get_product_callback'],
            $GLOBALS['intersoccer_test_terms_by_slug'],
            $GLOBALS['intersoccer_test_terms_by_id'],
            $GLOBALS['intersoccer_test_wpml_term_map']
        );
        parent::tearDown();
    }

    private function registerGenevaTerms(): void {
        $GLOBALS['intersoccer_test_terms_by_slug'] = [
            'pa_booking-type' => [
                'full-week' => (object) ['term_id' => 501, 'slug' => 'full-week', 'name' => 'Full Week'],
            ],
            'pa_city' => [
                'geneva' => (object) ['term_id' => 502, 'slug' => 'geneva', 'name' => 'Geneva'],
            ],
            'pa_intersoccer-venues' => [
                'stade-de-vessy' => (object) ['term_id' => 503, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
            ],
            'pa_age-group' => [
                '5-13y-full-day' => (object) ['term_id' => 504, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
            ],
            'pa_camp-terms' => [
                'easter' => (object) ['term_id' => 505, 'slug' => 'easter', 'name' => 'Easter'],
            ],
            'pa_canton-region' => [
                'geneva' => (object) ['term_id' => 506, 'slug' => 'geneva', 'name' => 'Geneva'],
            ],
            'pa_girls-only' => [
                'no' => (object) ['term_id' => 507, 'slug' => 'no', 'name' => 'No'],
            ],
        ];
        $GLOBALS['intersoccer_test_terms_by_id'] = [
            501 => (object) ['term_id' => 501, 'slug' => 'full-week', 'name' => 'Full Week'],
            502 => (object) ['term_id' => 502, 'slug' => 'geneva', 'name' => 'Geneva'],
            503 => (object) ['term_id' => 503, 'slug' => 'stade-de-vessy', 'name' => 'Stade de Vessy'],
            504 => (object) ['term_id' => 504, 'slug' => '5-13y-full-day', 'name' => '5-13y (Full Day)'],
            505 => (object) ['term_id' => 505, 'slug' => 'easter', 'name' => 'Easter'],
            506 => (object) ['term_id' => 506, 'slug' => 'geneva', 'name' => 'Geneva'],
            507 => (object) ['term_id' => 507, 'slug' => 'no', 'name' => 'No'],
        ];
        $GLOBALS['intersoccer_test_wpml_term_map'] = [];
    }

    private function stubVariation(int $variation_id, array $attrs): \WC_Product_Variation {
        $variation = Mockery::mock('WC_Product_Variation');
        $variation->shouldReceive('get_id')->andReturn($variation_id);
        $variation->shouldReceive('get_parent_id')->andReturn(900);
        $variation->shouldReceive('get_variation_attributes')->andReturn($attrs);
        $variation->shouldReceive('get_attribute')->andReturnUsing(static function ($name) use ($attrs) {
            $key = 'attribute_' . ltrim((string) $name, 'attribute_');
            if (isset($attrs[$key])) {
                return $attrs[$key];
            }
            if (isset($attrs[$name])) {
                return $attrs[$name];
            }

            return '';
        });
        $variation->shouldReceive('get_attributes')->andReturn([]);

        return $variation;
    }

    /**
     * @param array<string,mixed> $order_data
     * @return array<string,mixed>
     */
    private function invokeBuildRosterData(array $order_data): array {
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

        $reflection = new ReflectionClass($this->rosterBuilder);
        $method = $reflection->getMethod('buildRosterData');
        $method->setAccessible(true);

        return $method->invoke($this->rosterBuilder, $order_data, $player, $customer);
    }

    /**
     * Post-move roster write shape for blank booking/city (same helpers as move + rebuild fill).
     *
     * @param array<string,mixed> $record
     * @return array{booking_type:string,city:string,event_signature:string}
     */
    private function moveBlankKeyRosterExpectations(array $record, \WC_Product_Variation $variation): array {
        $filled = intersoccer_migration_apply_blank_roster_fill_from_variation($record, $variation);
        $event_data = [
            'activity_type' => $filled['activity_type'] ?? 'Camp',
            'venue'         => $filled['venue'] ?? '',
            'age_group'     => $filled['age_group'] ?? '',
            'camp_terms'    => $filled['camp_terms'] ?? $filled['event_type'] ?? '',
            'course_day'    => $filled['course_day'] ?? '',
            'times'         => $filled['times'] ?? '',
            'season'        => $filled['season'] ?? '',
            'girls_only'    => (int) ($filled['girls_only'] ?? 0),
            'city'          => $filled['city'] ?? '',
            'canton_region' => $filled['canton_region'] ?? '',
            'product_id'    => (int) ($filled['product_id'] ?? 900),
            'start_date'    => $filled['start_date'] ?? '',
        ];
        $signature = function_exists('intersoccer_event_signature_from_event_data')
            ? intersoccer_event_signature_from_event_data($event_data)
            : '';

        return [
            'booking_type'    => (string) ($filled['booking_type'] ?? ''),
            'city'            => (string) ($filled['city'] ?? ''),
            'event_signature' => (string) $signature,
        ];
    }

    public function test_apply_blank_roster_fill_fills_booking_type_and_city_from_variation(): void {
        $this->registerGenevaTerms();
        $variation = $this->stubVariation(88001, [
            'attribute_pa_booking-type' => 'full-week',
            'attribute_pa_city' => 'geneva',
            'attribute_pa_girls-only' => 'no',
        ]);

        $row = [
            'activity_type' => 'Camp',
            'booking_type' => '',
            'city' => '',
            'girls_only' => 0,
            'product_id' => 900,
        ];

        $filled = intersoccer_migration_apply_blank_roster_fill_from_variation($row, $variation);

        $this->assertSame('Full Week', $filled['booking_type']);
        $this->assertSame('Geneva', $filled['city']);
    }

    public function test_apply_blank_roster_fill_does_not_overwrite_existing_booking_and_city(): void {
        $this->registerGenevaTerms();
        $variation = $this->stubVariation(88002, [
            'attribute_pa_booking-type' => 'full-week',
            'attribute_pa_city' => 'geneva',
        ]);

        $row = [
            'activity_type' => 'Camp',
            'booking_type' => 'Single Day(s)',
            'city' => 'Zurich',
        ];

        $filled = intersoccer_migration_apply_blank_roster_fill_from_variation($row, $variation);

        $this->assertSame('Single Day(s)', $filled['booking_type']);
        $this->assertSame('Zurich', $filled['city']);
    }

    public function test_apply_blank_roster_fill_leaves_city_blank_when_variation_slug_unresolved(): void {
        $this->registerGenevaTerms();
        $variation = $this->stubVariation(88003, [
            'attribute_pa_city' => 'not-a-real-city-slug',
        ]);

        $row = [
            'activity_type' => 'Camp',
            'city' => '',
            'product_id' => 900,
        ];

        $filled = intersoccer_migration_apply_blank_roster_fill_from_variation($row, $variation);

        $this->assertSame('', trim((string) ($filled['city'] ?? '')));
    }

    public function test_apply_blank_roster_fill_does_not_fill_canton_region(): void {
        $this->registerGenevaTerms();
        $variation = $this->stubVariation(88004, [
            'attribute_pa_canton-region' => 'geneva',
        ]);

        $row = [
            'activity_type' => 'Camp',
            'canton_region' => '',
            'region' => '',
            'product_id' => 900,
        ];

        $filled = intersoccer_migration_apply_blank_roster_fill_from_variation($row, $variation);

        $this->assertSame('', trim((string) ($filled['canton_region'] ?? '')));
        $this->assertSame('', trim((string) ($filled['region'] ?? '')));
    }

    public function test_build_roster_data_blank_keys_match_move_roster_write_and_signature(): void {
        $this->registerGenevaTerms();
        $variation_id = 88100;
        $variation = $this->stubVariation($variation_id, [
            'attribute_pa_booking-type' => 'full-week',
            'attribute_pa_city' => 'geneva',
            'attribute_pa_intersoccer-venues' => 'stade-de-vessy',
            'attribute_pa_age-group' => '5-13y-full-day',
            'attribute_pa_camp-terms' => 'easter',
            'attribute_pa_girls-only' => 'no',
        ]);
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($variation, $variation_id) {
            return (int) $id === $variation_id ? $variation : null;
        };

        $order_data = [
            'variation_id' => $variation_id,
            'product_id' => 900,
            'activity_type' => 'Camp',
            'booking_type' => 'Unknown',
            'city' => '',
            'venue' => '',
            'age_group' => '',
            'camp_terms' => '',
            'girls_only' => 0,
            'season' => 'Summer 2027',
        ];

        $move_expected = $this->moveBlankKeyRosterExpectations($order_data, $variation);
        $rebuilt = $this->invokeBuildRosterData($order_data);

        $this->assertSame($move_expected['booking_type'], $rebuilt['booking_type']);
        $this->assertSame($move_expected['city'], $rebuilt['city']);
        $this->assertSame($move_expected['event_signature'], $rebuilt['event_signature']);
    }

    public function test_build_roster_data_with_populated_booking_city_venue_unchanged_and_no_wc_get_product(): void {
        $this->registerGenevaTerms();
        $variation = $this->stubVariation(88101, [
            'attribute_pa_booking-type' => 'full-week',
            'attribute_pa_city' => 'geneva',
        ]);
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($variation) {
            return (int) $id === 88101 ? $variation : null;
        };

        $order_data = [
            'variation_id' => 88101,
            'product_id' => 900,
            'activity_type' => 'Camp',
            'booking_type' => 'Single Day(s)',
            'city' => 'Zurich',
            'venue' => 'Stade de Vessy',
            'age_group' => '5-13y (Full Day)',
            'camp_terms' => 'Easter',
            'girls_only' => 0,
            'season' => 'Summer 2027',
            'times' => 'Full Day',
            'start_date' => '2027-04-05',
            'end_date' => '2027-04-09',
        ];

        $rebuilt = $this->invokeBuildRosterData($order_data);

        $this->assertSame('Single Day(s)', $rebuilt['booking_type']);
        $this->assertSame('zurich', $rebuilt['city']);
        $this->assertSame('Stade de Vessy', $rebuilt['venue']);
        $this->assertSame('2027-04-05', $rebuilt['start_date']);
        $this->assertSame('2027-04-09', $rebuilt['end_date']);
        $this->assertNotSame('', (string) ($rebuilt['event_signature'] ?? ''));
        $this->assertFalse(intersoccer_migration_roster_row_needs_blank_fill_from_variation($order_data));
    }

    public function test_build_roster_data_after_easter_move_uses_destination_camp_dates(): void {
        $this->registerGenevaTerms();
        $variation_id = 88102;
        $variation = $this->stubVariation($variation_id, [
            'attribute_pa_booking-type' => 'single-days',
            'attribute_pa_girls-only' => 'no',
        ]);
        \InterSoccerRRTestMeta::$data[$variation_id] = [
            '_camp_start_date' => '2027-04-05',
            '_camp_end_date' => '2027-04-09',
        ];
        $GLOBALS['intersoccer_test_wc_get_product_callback'] = static function ($id) use ($variation, $variation_id) {
            return (int) $id === $variation_id ? $variation : null;
        };

        $order_data = [
            'variation_id' => $variation_id,
            'product_id' => 900,
            'activity_type' => 'Camp',
            'booking_type' => 'Single Day(s)',
            'camp_start_date' => '2027-04-05',
            'camp_end_date' => '2027-04-09',
            'start_date' => '2027-04-05',
            'end_date' => '2027-04-09',
            'girls_only' => 0,
            'venue' => 'Stade de Vessy',
            'age_group' => '5-13y (Full Day)',
            'camp_terms' => 'Easter',
        ];

        $rebuilt = $this->invokeBuildRosterData($order_data);

        $this->assertSame('2027-04-05', $rebuilt['start_date']);
        $this->assertSame('2027-04-09', $rebuilt['end_date']);
    }
}
