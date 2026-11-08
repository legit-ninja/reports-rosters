<?php

namespace InterSoccer\ReportsRosters\Tests\Harness;

use InterSoccer\ReportsRosters\Tests\TestCase;

class WpdbPrepareContractTest extends TestCase {
    public function test_prepare_formats_supported_placeholders(): void {
        $db = new \InterSoccerTestWpdb();

        $this->assertSame(
            "SELECT 'Zurich', 2, 1.500000",
            $db->prepare('SELECT %s, %d, %f', 'Zurich', 2, 1.5)
        );
        $this->assertSame("LIKE '%camp%'", $db->prepare('LIKE %s', '%camp%'));
        $this->assertSame('100% done', $db->prepare('100%% done'));
    }

    public function test_prepare_rejects_argument_count_mismatch(): void {
        $db = new \InterSoccerTestWpdb();

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('The arguments array must contain 2 items, 1 given');
        $db->prepare('SELECT %s, %d', 'only-one');
    }

    public function test_prepare_rejects_unsupported_placeholder(): void {
        $db = new \InterSoccerTestWpdb();

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Unsupported prepare placeholder %i');
        $db->prepare('SELECT %i FROM t', 'rosters');
    }
}
