<?php
/**
 * RosterBuilder Test
 * 
 * Tests for the RosterBuilder service
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
use InterSoccer\ReportsRosters\Data\Collections\PlayersCollection;
use InterSoccer\ReportsRosters\Data\Collections\RostersCollection;
use Brain\Monkey\Functions;
use Mockery;

class RosterBuilderTest extends TestCase {
    
    private $rosterBuilder;
    private $logger;
    private $database;
    private $playerRepository;
    private $rosterRepository;
    private $dataValidator;
    private $eventMatcher;
    private $playerMatcher;
    
    protected function setUp(): void {
        parent::setUp();
        
        $this->logger = Mockery::mock(Logger::class);
        $this->logger->shouldReceive('info')->andReturn(null);
        $this->logger->shouldReceive('debug')->andReturn(null);
        $this->logger->shouldReceive('warning')->andReturn(null);
        $this->logger->shouldReceive('error')->andReturn(null);
        
        $this->database = Mockery::mock(Database::class);
        $this->playerRepository = Mockery::mock(PlayerRepository::class);
        $this->rosterRepository = Mockery::mock(RosterRepository::class);
        $this->dataValidator = Mockery::mock(DataValidator::class);
        $this->eventMatcher = Mockery::mock(EventMatcher::class);
        $this->playerMatcher = Mockery::mock(PlayerMatcher::class);
        
        $this->rosterBuilder = new RosterBuilder(
            $this->logger,
            $this->database,
            $this->playerRepository,
            $this->rosterRepository,
            $this->dataValidator,
            $this->eventMatcher,
            $this->playerMatcher
        );

        // Provide default implementations for common WordPress helpers.
        Functions\when('remove_accents')->alias(function($string) {
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
    
    public function test_roster_builder_initialization() {
        $this->assertInstanceOf(RosterBuilder::class, $this->rosterBuilder);
    }

    public function test_extract_order_item_data_handles_translated_meta_keys() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(42);
        $order->shouldReceive('get_customer_id')->andReturn(7);
        $order->shouldReceive('get_status')->andReturn('processing');

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product')->andReturn(null);
        $item->shouldReceive('get_variation_id')->andReturn(0);
        $item->shouldReceive('get_product_id')->andReturn(101);
        $item->shouldReceive('get_meta_data')->andReturn([
            $this->createOrderItemMeta('Type d’activité', 'Cours'),
            $this->createOrderItemMeta('Saison', 'Printemps 2025'),
            $this->createOrderItemMeta('Groupe d’âge', 'U10'),
            $this->createOrderItemMeta('Assigned Attendee', 'Theo Kuhn'),
        ]);

        $reflection = new \ReflectionClass($this->rosterBuilder);
        $method = $reflection->getMethod('extractOrderItemData');
        $method->setAccessible(true);

        $result = $method->invoke($this->rosterBuilder, $order, 555, $item);

        $this->assertSame('Course', $result['activity_type']);
        $this->assertSame('Printemps 2025', $result['season']);
        $this->assertSame('U10', $result['age_group']);
        $this->assertSame('Theo Kuhn', $result['assigned_attendee']);
    }
    
    public function test_build_rosters_with_empty_options() {
        $this->database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function($callback) {
                return $callback();
            });
        
        Functions\when('wc_get_orders')->justReturn([]);
        
        $result = $this->rosterBuilder->buildRosters();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('orders_processed', $result);
        $this->assertArrayHasKey('rosters_created', $result);
    }

    public function test_reconcile_with_no_orders_returns_zeroes() {
        $wpdbStub = new class {
            public $prefix = 'wp_';
            public function get_col($query) {
                return [];
            }
        };

        $this->database->shouldReceive('get_wpdb')
            ->once()
            ->andReturn($wpdbStub);

        Functions\when('wc_get_orders')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-01 00:00:00');

        $result = $this->rosterBuilder->reconcile();

        $this->assertEquals(0, $result['synced']);
        $this->assertEquals(0, $result['deleted']);
        $this->assertEquals(0, $result['errors']);
    }

    public function test_reconcile_deletes_obsolete_entries() {
        $wpdbStub = new class {
            public $prefix = 'wp_';
            public function get_col($query) {
                return [123];
            }
        };

        $this->database->shouldReceive('get_wpdb')
            ->once()
            ->andReturn($wpdbStub);

        Functions\when('wc_get_orders')->justReturn([]);
        Functions\when('current_time')->justReturn('2024-01-01 00:00:00');

        $this->rosterRepository->shouldReceive('deleteWhere')
            ->once()
            ->with(['order_item_id' => 123])
            ->andReturn(1);

        $result = $this->rosterBuilder->reconcile();

        $this->assertEquals(1, $result['deleted']);
    }
    
    public function test_build_rosters_processes_single_order() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_customer_id')->andReturn(1);
        $order->shouldReceive('get_billing_email')->andReturn('test@example.com');
        $order->shouldReceive('get_billing_phone')->andReturn('+41 12 345 67 89');
        $order->shouldReceive('get_billing_first_name')->andReturn('John');
        $order->shouldReceive('get_billing_last_name')->andReturn('Doe');
        $order->shouldReceive('get_items')->andReturn([]);
        
        Functions\expect('wc_get_order')
            ->with(1)
            ->andReturn($order);
        
        $this->playerRepository->shouldReceive('getPlayersByCustomerId')
            ->with(1)
            ->andReturn(new PlayersCollection());
        
        $result = $this->rosterBuilder->buildRosterFromOrder(1);
        
        $this->assertInstanceOf(RostersCollection::class, $result);
    }
    
    public function test_build_rosters_skips_invalid_order_status() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1);
        $order->shouldReceive('get_status')->andReturn('pending');
        
        Functions\expect('wc_get_order')
            ->with(1)
            ->andReturn($order);
        
        $result = $this->rosterBuilder->buildRosterFromOrder(1);
        
        $this->assertInstanceOf(RostersCollection::class, $result);
        $this->assertEquals(0, $result->count());
    }
    
    public function test_build_rosters_handles_missing_order() {
        Functions\expect('wc_get_order')
            ->with(999)
            ->andReturn(false);
        
        $result = $this->rosterBuilder->buildRosterFromOrder(999);
        
        $this->assertInstanceOf(RostersCollection::class, $result);
        $this->assertEquals(0, $result->count());
    }
    
    public function test_get_build_statistics() {
        $stats = $this->rosterBuilder->getBuildStatistics();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKeys([
            'orders_processed',
            'rosters_created',
            'rosters_updated',
            'players_processed',
            'validation_errors',
            'skipped_orders',
            'start_time',
            'errors',
            'warnings'
        ], $stats);
    }
    
    public function test_validate_build_integrity() {
        $this->rosterRepository->shouldReceive('all')
            ->once()
            ->andReturn(new RostersCollection());
        
        $result = $this->rosterBuilder->validateBuildIntegrity();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('total_rosters', $result);
        $this->assertArrayHasKey('valid_rosters', $result);
        $this->assertArrayHasKey('invalid_rosters', $result);
    }
    
    public function test_rebuild_specific_orders() {
        $this->database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function($callback) {
                return $callback();
            });
        
        $this->rosterRepository->shouldReceive('deleteWhere')
            ->with(['order_id' => 1])
            ->once()
            ->andReturn(1);
        
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_customer_id')->andReturn(1);
        $order->shouldReceive('get_billing_email')->andReturn('test@example.com');
        $order->shouldReceive('get_billing_phone')->andReturn('+41 12 345 67 89');
        $order->shouldReceive('get_billing_first_name')->andReturn('John');
        $order->shouldReceive('get_billing_last_name')->andReturn('Doe');
        $order->shouldReceive('get_items')->andReturn([]);
        
        Functions\expect('wc_get_order')
            ->with(1)
            ->andReturn($order);
        
        $this->playerRepository->shouldReceive('getPlayersByCustomerId')
            ->andReturn(new PlayersCollection());
        
        $result = $this->rosterBuilder->rebuildSpecificOrders([1]);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('rosters', $result);
        $this->assertArrayHasKey('statistics', $result);
    }
    
    public function test_cleanup_orphaned_rosters() {
        $roster = Mockery::mock();
        $roster->id = 1;
        $roster->order_id = 999;
        $roster->customer_id = 1;
        $roster->player_index = 0;
        
        $rosters = new RostersCollection([$roster]);
        
        $this->rosterRepository->shouldReceive('all')
            ->once()
            ->andReturn($rosters);
        
        Functions\expect('wc_get_order')
            ->with(999)
            ->andReturn(false); // Order doesn't exist
        
        $this->database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function($callback) {
                return $callback();
            });
        
        $this->rosterRepository->shouldReceive('delete')
            ->with(1)
            ->once()
            ->andReturn(true);
        
        $result = $this->rosterBuilder->cleanupOrphanedRosters();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('total_checked', $result);
        $this->assertArrayHasKey('orphaned_found', $result);
        $this->assertArrayHasKey('deleted', $result);
        $this->assertEquals(1, $result['total_checked']);
        $this->assertEquals(1, $result['orphaned_found']);
        $this->assertEquals(1, $result['deleted']);
    }
    
    public function test_get_build_recommendations() {
        Functions\expect('wc_get_orders')
            ->andReturn([]);
        
        $this->rosterRepository->shouldReceive('where')
            ->andReturn(new RostersCollection());
        
        $this->rosterRepository->shouldReceive('all')
            ->andReturn(new RostersCollection());
        
        Functions\expect('get_option')
            ->andReturn('2.0.0');
        
        $result = $this->rosterBuilder->getBuildRecommendations();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('rebuild_recommended', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('priority', $result);
    }
    
    public function test_preview_roster_build() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1);
        $order->shouldReceive('get_customer_id')->andReturn(1);
        $order->shouldReceive('get_billing_email')->andReturn('test@example.com');
        $order->shouldReceive('get_billing_phone')->andReturn('+41 12 345 67 89');
        $order->shouldReceive('get_billing_first_name')->andReturn('John');
        $order->shouldReceive('get_billing_last_name')->andReturn('Doe');
        $order->shouldReceive('get_items')->andReturn([]);
        
        Functions\expect('wc_get_orders')
            ->andReturn([$order]);
        
        $this->playerRepository->shouldReceive('getPlayersByCustomerId')
            ->andReturn(new PlayersCollection());
        
        $result = $this->rosterBuilder->previewRosterBuild(['limit' => 10]);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('orders_sampled', $result);
        $this->assertArrayHasKey('estimated_rosters', $result);
        $this->assertArrayHasKey('activity_breakdown', $result);
    }
    
    public function test_build_rosters_with_batch_processing() {
        $this->database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function($callback) {
                return $callback();
            });
        
        Functions\expect('wc_get_orders')
            ->times(2)
            ->andReturn([1, 2], []); // First batch has 2 orders, second is empty
        
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_customer_id')->andReturn(1);
        $order->shouldReceive('get_billing_email')->andReturn('test@example.com');
        $order->shouldReceive('get_billing_phone')->andReturn('+41 12 345 67 89');
        $order->shouldReceive('get_billing_first_name')->andReturn('John');
        $order->shouldReceive('get_billing_last_name')->andReturn('Doe');
        $order->shouldReceive('get_items')->andReturn([]);
        
        Functions\expect('wc_get_order')->andReturn($order);
        
        $this->playerRepository->shouldReceive('getPlayersByCustomerId')
            ->andReturn(new PlayersCollection());
        
        $result = $this->rosterBuilder->buildRosters(['batch_size' => 2]);
        
        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(0, $result['orders_processed']);
    }
    
    public function test_build_rosters_handles_transaction_rollback_on_error() {
        $this->database->shouldReceive('transaction')
            ->once()
            ->andThrow(new \Exception('Database error'));
        
        $result = $this->rosterBuilder->buildRosters();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertNotEmpty($result['errors']);
    }
    /**
     * Helper to create a lightweight meta data object for order item meta lists.
     *
     * @param string $key
     * @param mixed  $value
     * @return \Mockery\MockInterface
     */
    private function createOrderItemMeta($key, $value) {
        $meta = Mockery::mock();
        $meta->shouldReceive('get_data')
            ->andReturn([
                'key' => $key,
                'value' => $value,
            ]);

        return $meta;
    }
}

