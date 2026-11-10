<?php
/**
 * Empty billing parent names must not become NULL on roster insert.
 *
 * parent_first_name and parent_last_name are NOT NULL with no default.
 * Phone and email columns already have defaults and stay on the empty-to-NULL path.
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

class RosterBuilderParentNameFallbackTest extends TestCase {

    private const ORDER_ID = 501;

    private const CUSTOMER_ID = 77;

    /** @var array<int,array{message:string,context:array}> */
    private $warnings = [];

    protected function tearDown(): void {
        unset(
            $GLOBALS['intersoccer_test_user_meta'],
            $GLOBALS['intersoccer_test_get_user_meta_call_count']
        );
        parent::tearDown();
    }

    public function test_empty_billing_first_name_uses_user_first_name(): void {
        $this->seedUserMeta('USER-FIRST', 'USER-LAST');

        $inserted = $this->insertData('', 'BILLING-LAST');

        $this->assertSame('USER-FIRST', $inserted['parent_first_name']);
        $this->assertSame('BILLING-LAST', $inserted['parent_last_name']);
        $this->assertNotNull($inserted['parent_first_name']);
        $this->assertNotNull($inserted['parent_last_name']);
        $this->assertFallbackLog('parent_first_name', 'user_first_name');
        $this->assertLogHasNoFixtureValues();
    }

    public function test_empty_billing_first_name_without_user_first_name_uses_unknown(): void {
        $this->seedUserMeta('', 'USER-LAST');

        $inserted = $this->insertData('', 'BILLING-LAST');

        $this->assertSame('Unknown', $inserted['parent_first_name']);
        $this->assertSame('BILLING-LAST', $inserted['parent_last_name']);
        $this->assertNotNull($inserted['parent_first_name']);
        $this->assertNotNull($inserted['parent_last_name']);
        $this->assertFallbackLog('parent_first_name', 'unknown');
        $this->assertLogHasNoFixtureValues();
    }

    public function test_empty_billing_last_name_uses_user_last_name(): void {
        $this->seedUserMeta('USER-FIRST', 'USER-LAST');

        $inserted = $this->insertData('BILLING-FIRST', '');

        $this->assertSame('BILLING-FIRST', $inserted['parent_first_name']);
        $this->assertSame('USER-LAST', $inserted['parent_last_name']);
        $this->assertNotNull($inserted['parent_first_name']);
        $this->assertNotNull($inserted['parent_last_name']);
        $this->assertFallbackLog('parent_last_name', 'user_last_name');
        $this->assertLogHasNoFixtureValues();
    }

    public function test_empty_billing_last_name_without_user_last_name_uses_unknown(): void {
        $this->seedUserMeta('USER-FIRST', '   ');

        $inserted = $this->insertData('BILLING-FIRST', null);

        $this->assertSame('BILLING-FIRST', $inserted['parent_first_name']);
        $this->assertSame('Unknown', $inserted['parent_last_name']);
        $this->assertNotNull($inserted['parent_first_name']);
        $this->assertNotNull($inserted['parent_last_name']);
        $this->assertFallbackLog('parent_last_name', 'unknown');
        $this->assertLogHasNoFixtureValues();
    }

    public function test_both_parent_names_missing_insert_without_null_and_empty_text_stays_null(): void {
        $inserted = $this->insertData('', '');

        $this->assertSame('Unknown', $inserted['parent_first_name']);
        $this->assertSame('Unknown', $inserted['parent_last_name']);
        $this->assertNotNull($inserted['parent_first_name']);
        $this->assertNotNull($inserted['parent_last_name']);
        $this->assertNull($inserted['venue']);
        $this->assertNull($inserted['city']);
        $this->assertFallbackLog('parent_first_name', 'unknown');
        $this->assertFallbackLog('parent_last_name', 'unknown');
        $this->assertLogHasNoFixtureValues();
    }

    public function test_present_billing_names_are_kept(): void {
        $this->seedUserMeta('USER-FIRST', 'USER-LAST');

        $inserted = $this->insertData('BILLING-FIRST', 'BILLING-LAST');

        $this->assertSame('BILLING-FIRST', $inserted['parent_first_name']);
        $this->assertSame('BILLING-LAST', $inserted['parent_last_name']);
        $this->assertSame([], $this->warnings);
    }

    public function test_sanitize_field_still_converts_empty_string_to_null(): void {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug')->andReturnNull();
        $database = new Database($logger);
        $method = new \ReflectionMethod(Database::class, 'sanitize_roster_data');
        $method->setAccessible(true);

        $sanitized = $method->invoke($database, [
            'parent_first_name' => '',
            'parent_last_name' => '',
            'venue' => '',
            'parent_email' => '',
            'parent_phone' => '',
            'city' => 'KEEP',
        ]);

        $this->assertNull($sanitized['parent_first_name']);
        $this->assertNull($sanitized['parent_last_name']);
        $this->assertNull($sanitized['venue']);
        $this->assertNull($sanitized['parent_email']);
        $this->assertNull($sanitized['parent_phone']);
        $this->assertSame('KEEP', $sanitized['city']);
    }

    /**
     * @param mixed $billing_first
     * @param mixed $billing_last
     * @return array
     */
    private function insertData($billing_first, $billing_last): array {
        $this->warnings = [];
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();
        $logger->shouldReceive('warning')->andReturnUsing(function ($message, $context = []) {
            $this->warnings[] = [
                'message' => (string) $message,
                'context' => is_array($context) ? $context : [],
            ];
        });

        $builder = new RosterBuilder(
            $logger,
            Mockery::mock(Database::class),
            Mockery::mock(PlayerRepository::class),
            Mockery::mock(RosterRepository::class),
            Mockery::mock(DataValidator::class),
            Mockery::mock(EventMatcher::class),
            Mockery::mock(PlayerMatcher::class)
        );

        $order = new class(self::ORDER_ID, $billing_first, $billing_last, self::CUSTOMER_ID) extends \WC_Order {
            /** @var mixed */
            private $billingFirst;
            /** @var mixed */
            private $billingLast;
            /** @var int */
            private $customerId;

            /**
             * @param mixed $billingFirst
             * @param mixed $billingLast
             */
            public function __construct($id, $billingFirst, $billingLast, $customerId) {
                parent::__construct($id);
                $this->billingFirst = $billingFirst;
                $this->billingLast = $billingLast;
                $this->customerId = (int) $customerId;
            }

            public function get_billing_first_name() {
                return $this->billingFirst;
            }

            public function get_billing_last_name() {
                return $this->billingLast;
            }

            public function get_customer_id() {
                return $this->customerId;
            }

            public function get_billing_email() {
                return 'fixture@example.com';
            }

            public function get_billing_phone() {
                return '000';
            }
        };

        $customer = $this->invoke($builder, 'extractCustomerData', $order);
        $player = new Player([
            'first_name' => 'PLAYER-ONE',
            'last_name' => 'PLAYER-TWO',
            'player_index' => 0,
        ]);
        $roster = $this->invoke($builder, 'buildRosterData', [
            'order_id' => self::ORDER_ID,
            'order_item_id' => 1,
            'product_id' => 0,
            'variation_id' => 0,
            'customer_id' => self::CUSTOMER_ID,
            'venue' => '',
            'city' => '',
        ], $player, $customer);

        $db_logger = Mockery::mock(Logger::class);
        $db_logger->shouldReceive('debug')->andReturnNull();
        $database = new Database($db_logger);
        $sanitize = new \ReflectionMethod(Database::class, 'sanitize_roster_data');
        $sanitize->setAccessible(true);

        return $sanitize->invoke($database, $roster);
    }

    private function seedUserMeta(string $first, string $last): void {
        $GLOBALS['intersoccer_test_user_meta'][self::CUSTOMER_ID] = [
            'first_name' => $first,
            'last_name' => $last,
        ];
    }

    private function assertFallbackLog(string $column, string $source): void {
        foreach ($this->warnings as $warning) {
            if (($warning['context']['column'] ?? '') !== $column) {
                continue;
            }
            $this->assertSame('Roster parent name fallback applied', $warning['message']);
            $this->assertSame(self::ORDER_ID, $warning['context']['order_id']);
            $this->assertSame($source, $warning['context']['source']);
            $this->assertSame(['order_id', 'column', 'source'], array_keys($warning['context']));
            return;
        }
        $this->fail('Missing parent-name fallback log for ' . $column);
    }

    private function assertLogHasNoFixtureValues(): void {
        $encoded = wp_json_encode($this->warnings);
        $this->assertIsString($encoded);
        foreach (['USER-FIRST', 'USER-LAST', 'BILLING-FIRST', 'BILLING-LAST', 'PLAYER-ONE', 'PLAYER-TWO', 'Unknown', 'fixture@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    /**
     * @param mixed ...$args
     * @return mixed
     */
    private function invoke(RosterBuilder $builder, string $method, ...$args) {
        $ref = new \ReflectionMethod(RosterBuilder::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($builder, ...$args);
    }
}
