<?php

namespace InterSoccer\ReportsRosters\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReconcileMemoryBenchmarkTest extends TestCase {

    public function test_batched_order_id_processing_peak_memory_stays_bounded(): void {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 2) . '/');
        }
        require_once dirname(__DIR__, 2) . '/includes/utils.php';

        $batch_size = 200;
        $total_orders = 4800;
        $peak = memory_get_usage(true);

        for ($page = 1; $page <= (int) ceil($total_orders / $batch_size); $page++) {
            $ids = range(($page - 1) * $batch_size + 1, min($page * $batch_size, $total_orders));
            $objects = [];
            foreach ($ids as $id) {
                $objects[] = (object) [
                    'id' => $id,
                    'items' => array_fill(0, 2, (object) ['order_item_id' => $id * 10]),
                ];
            }
            unset($objects, $ids);
            if (function_exists('intersoccer_reconcile_release_batch_memory')) {
                intersoccer_reconcile_release_batch_memory();
            }
            $peak = max($peak, memory_get_usage(true));
        }

        $peak_mb = $peak / 1024 / 1024;
        $this->assertLessThan(
            256,
            $peak_mb,
            'Synthetic reconcile batch loop peak memory (MB): ' . round($peak_mb, 2)
        );
    }
}
