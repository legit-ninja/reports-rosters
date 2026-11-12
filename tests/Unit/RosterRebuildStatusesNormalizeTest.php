<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use InterSoccer\ReportsRosters\Tests\TestCase;

class RosterRebuildStatusesNormalizeTest extends TestCase {

    public function test_wc_completed_prefix(): void {
        $this->assertSame(['completed'], intersoccer_normalize_roster_rebuild_statuses(['wc-completed']));
    }

    public function test_disallowed_statuses_return_default(): void {
        $this->assertSame(
            ['completed', 'processing', 'on-hold'],
            intersoccer_normalize_roster_rebuild_statuses(['pending', 'failed', 'any'])
        );
    }

    public function test_wc_pending_stripped_and_intersected(): void {
        $this->assertSame(['processing'], intersoccer_normalize_roster_rebuild_statuses(['wc-pending', 'processing']));
    }

    public function test_mixed_case_and_whitespace(): void {
        $this->assertSame(
            ['completed', 'on-hold'],
            intersoccer_normalize_roster_rebuild_statuses(['  COMPLETED ', 'On-Hold'])
        );
    }

    public function test_non_array_and_empty_array_return_default(): void {
        $this->assertSame(['completed', 'processing', 'on-hold'], intersoccer_normalize_roster_rebuild_statuses(null));
        $this->assertSame(['completed', 'processing', 'on-hold'], intersoccer_normalize_roster_rebuild_statuses([]));
    }

    public function test_non_strings_skipped(): void {
        $this->assertSame(
            ['completed'],
            intersoccer_normalize_roster_rebuild_statuses([99, 'completed', false])
        );
    }

    public function test_dedupe_preserves_order(): void {
        $this->assertSame(
            ['completed', 'processing'],
            intersoccer_normalize_roster_rebuild_statuses(['completed', 'wc-completed', 'processing', 'processing'])
        );
    }
}
