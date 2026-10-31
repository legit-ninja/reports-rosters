<?php

namespace InterSoccer\ReportsRosters\Tests\Ajax;

use InterSoccer\ReportsRosters\Ajax\RosterAjaxHandler;
use InterSoccer\ReportsRosters\Core\DatabaseMigrator;
use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterAjaxHandlerUpgradeDatabaseTest extends TestCase {

    public function test_upgrade_payload_already_running(): void {
        $payload = RosterAjaxHandler::buildUpgradeDatabaseAjaxPayload(DatabaseMigrator::RESULT_ALREADY_RUNNING);
        $this->assertTrue($payload['success']);
        $this->assertSame(DatabaseMigrator::RESULT_ALREADY_RUNNING, $payload['data']['status']);
        $this->assertStringContainsString('already running', strtolower($payload['data']['message']));
    }

    public function test_upgrade_payload_already_current(): void {
        $payload = RosterAjaxHandler::buildUpgradeDatabaseAjaxPayload(DatabaseMigrator::RESULT_ALREADY_CURRENT);
        $this->assertTrue($payload['success']);
        $this->assertSame(DatabaseMigrator::RESULT_ALREADY_CURRENT, $payload['data']['status']);
        $this->assertStringContainsString('already up to date', strtolower($payload['data']['message']));
        $this->assertStringContainsString('2.4.0', $payload['data']['message']);
        $this->assertStringContainsString('no changes', strtolower($payload['data']['message']));
    }

    public function test_upgrade_payload_upgraded(): void {
        $GLOBALS['intersoccer_test_option_store'][DatabaseMigrator::VERSION_OPTION] = '2.4.0';
        $payload = RosterAjaxHandler::buildUpgradeDatabaseAjaxPayload(DatabaseMigrator::RESULT_UPGRADED);
        $this->assertTrue($payload['success']);
        $this->assertSame(DatabaseMigrator::RESULT_UPGRADED, $payload['data']['status']);
        $this->assertStringContainsString('upgrade completed', strtolower($payload['data']['message']));
        unset($GLOBALS['intersoccer_test_option_store'][DatabaseMigrator::VERSION_OPTION]);
    }

    public function test_upgrade_payload_skipped_unrecognised_backup(): void {
        $payload = RosterAjaxHandler::buildUpgradeDatabaseAjaxPayload(DatabaseMigrator::RESULT_SKIPPED_UNRECOGNISED_BACKUP);
        $this->assertTrue($payload['success']);
        $this->assertSame(DatabaseMigrator::RESULT_SKIPPED_UNRECOGNISED_BACKUP, $payload['data']['status']);
        $this->assertStringContainsString('unrecognised backup', strtolower($payload['data']['message']));
    }

    public function test_upgrade_payload_failed(): void {
        $payload = RosterAjaxHandler::buildUpgradeDatabaseAjaxPayload(DatabaseMigrator::RESULT_FAILED);
        $this->assertFalse($payload['success']);
        $this->assertSame(DatabaseMigrator::RESULT_FAILED, $payload['data']['status']);
        $this->assertStringContainsString('failed', strtolower($payload['data']['message']));
    }
}
