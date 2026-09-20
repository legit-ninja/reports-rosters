<?php
/**
 * RosterAjaxHandler Test
 *
 * Tests for the RosterAjaxHandler, specifically the handleRebuildRosters method.
 */

namespace InterSoccer\ReportsRosters\Tests\Ajax;

use InterSoccer\ReportsRosters\Tests\TestCase;
use InterSoccer\ReportsRosters\Ajax\RosterAjaxHandler;
use InterSoccer\ReportsRosters\Core\Logger;
use InterSoccer\ReportsRosters\Data\Repositories\RosterRepository;
use InterSoccer\ReportsRosters\Services\RosterBuilder;
use InterSoccer\ReportsRosters\Services\PlaceholderManager;
use InterSoccer\ReportsRosters\Services\EventSignatureGenerator;
use InterSoccer\ReportsRosters\Core\DatabaseMigrator;
use Brain\Monkey\Functions;
use Mockery;

class RosterAjaxHandlerTest extends TestCase
{
    private $logger;
    private $rosterRepository;
    private $rosterBuilder;
    private $placeholderManager;
    private $signatureGenerator;
    private $databaseMigrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = Mockery::mock(Logger::class);
        $this->logger->shouldReceive('info')->andReturn(null);
        $this->logger->shouldReceive('debug')->andReturn(null);
        $this->logger->shouldReceive('warning')->andReturn(null);
        $this->logger->shouldReceive('error')->andReturn(null);

        $this->rosterRepository = Mockery::mock(RosterRepository::class);
        $this->rosterBuilder = Mockery::mock(RosterBuilder::class);
        $this->placeholderManager = Mockery::mock(PlaceholderManager::class);
        $this->signatureGenerator = Mockery::mock(EventSignatureGenerator::class);
        $this->databaseMigrator = Mockery::mock(DatabaseMigrator::class);
    }

    public function test_handleRebuildRosters_calls_buildRosters_not_rebuildAll(): void
    {
        Functions\expect('check_ajax_referer')
            ->once()
            ->with('intersoccer_rebuild_nonce', 'nonce')
            ->andReturn(true);

        Functions\when('current_user_can')->justReturn(true);

        $buildResults = [
            'orders_processed' => 10,
            'rosters_created' => 25,
            'rosters_updated' => 5,
            'players_processed' => 30,
            'validation_errors' => 0,
            'skipped_orders' => 1,
            'start_time' => microtime(true),
            'end_time' => microtime(true),
            'errors' => [],
            'warnings' => [],
        ];

        $this->rosterBuilder
            ->shouldReceive('buildRosters')
            ->once()
            ->with(['clear_existing' => true, 'batch_size' => 100])
            ->andReturn($buildResults);

        $capturedSuccess = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$capturedSuccess) {
                $capturedSuccess = $data;
            });

        $handler = new RosterAjaxHandler(
            $this->logger,
            $this->rosterRepository,
            $this->rosterBuilder,
            $this->placeholderManager,
            $this->signatureGenerator,
            $this->databaseMigrator
        );

        $handler->handleRebuildRosters();

        $this->assertNotNull($capturedSuccess);
        $this->assertArrayHasKey('message', $capturedSuccess);
        $this->assertArrayHasKey('results', $capturedSuccess);
        $this->assertStringContainsString('10', $capturedSuccess['message']);
        $this->assertStringContainsString('25', $capturedSuccess['message']);
    }

    public function test_handleRebuildRosters_counts_errors_array(): void
    {
        Functions\expect('check_ajax_referer')
            ->once()
            ->with('intersoccer_rebuild_nonce', 'nonce')
            ->andReturn(true);

        Functions\when('current_user_can')->justReturn(true);

        $buildResults = [
            'orders_processed' => 5,
            'rosters_created' => 8,
            'rosters_updated' => 2,
            'players_processed' => 10,
            'validation_errors' => 3,
            'skipped_orders' => 2,
            'start_time' => microtime(true),
            'end_time' => microtime(true),
            'errors' => ['Error 1', 'Error 2', 'Error 3'],
            'warnings' => [],
        ];

        $this->rosterBuilder
            ->shouldReceive('buildRosters')
            ->once()
            ->andReturn($buildResults);

        $capturedSuccess = null;
        Functions\expect('wp_send_json_success')
            ->once()
            ->andReturnUsing(function ($data) use (&$capturedSuccess) {
                $capturedSuccess = $data;
            });

        $handler = new RosterAjaxHandler(
            $this->logger,
            $this->rosterRepository,
            $this->rosterBuilder,
            $this->placeholderManager,
            $this->signatureGenerator,
            $this->databaseMigrator
        );

        $handler->handleRebuildRosters();

        $this->assertNotNull($capturedSuccess);
        $this->assertStringContainsString('Errors: 3', $capturedSuccess['message']);
    }
}
