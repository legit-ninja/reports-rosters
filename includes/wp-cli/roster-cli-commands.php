<?php
/**
 * WP-CLI commands for roster operations (optional; requires WP-CLI).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * wp intersoccer-rosters <subcommand>
 */
final class Intersoccer_Rosters_Wp_Cli {
    /**
     * Clear roster list transient cache.
     *
     * ## EXAMPLES
     *
     *     wp intersoccer-rosters cache-clear
     *
     * @when after_wp_load
     */
    public function cache_clear(): void {
        delete_transient('intersoccer_rosters_cache');
        \WP_CLI::success('Deleted transient intersoccer_rosters_cache.');
    }

    /**
     * Run a short signature drift scan (first chunk only; for smoke tests).
     *
     * ## OPTIONS
     *
     * [--chunk=<n>]
     * : Rows per chunk (default 200, max 2000).
     *
     * ## EXAMPLES
     *
     *     wp intersoccer-rosters signature-drift-sample
     *
     * @when after_wp_load
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function signature_drift_sample(array $args, array $assoc_args): void {
        if (!class_exists(\InterSoccer\ReportsRosters\Services\SignatureDriftService::class)) {
            \WP_CLI::error('SignatureDriftService is not available.');
        }
        $chunk = isset($assoc_args['chunk']) ? (int) $assoc_args['chunk'] : 200;
        $svc = new \InterSoccer\ReportsRosters\Services\SignatureDriftService();
        $res = $svc->scanChunk(['int_cursor' => 0, 'chunk' => $chunk, 'max_drifts' => 20]);
        \WP_CLI::log(sprintf('Scanned: %d, drifts returned: %d, exhausted: %s', $res['scanned'], count($res['drifts']), $res['exhausted'] ? 'yes' : 'no'));
        \WP_CLI::success('Sample scan complete.');
    }
}

\WP_CLI::add_command('intersoccer-rosters', Intersoccer_Rosters_Wp_Cli::class);

/**
 * wp intersoccer-rr <subcommand>
 */
final class Intersoccer_Rr_Wp_Cli {
    /**
     * Report or apply financial attribution backfill for orders missing metadata.
     *
     * Default is dry-run (counts and CHF totals only, no writes).
     *
     * ## OPTIONS
     *
     * [--from=<date>]
     * : Start date (Y-m-d). Default: 2025-01-01.
     *
     * [--to=<date>]
     * : End date (Y-m-d). Default: today.
     *
     * [--apply]
     * : Persist attribution (requires explicit flag). Idempotent.
     *
     * ## EXAMPLES
     *
     *     wp intersoccer-rr attribution-backfill --from=2025-01-01 --to=2026-10-12
     *
     * @when after_wp_load
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function attribution_backfill(array $args, array $assoc_args): void {
        if (!function_exists('intersoccer_oop_get_order_financial_attribution_service')) {
            \WP_CLI::error('Order financial attribution service is not available.');
        }

        $from = isset($assoc_args['from']) ? (string) $assoc_args['from'] : '2025-01-01';
        $to = isset($assoc_args['to']) ? (string) $assoc_args['to'] : gmdate('Y-m-d');
        $apply = \WP_CLI\Utils\get_flag_value($assoc_args, 'apply', false) === true;

        $service = intersoccer_oop_get_order_financial_attribution_service();

        if ($apply) {
            \WP_CLI::confirm('Apply financial attribution backfill for the selected date range?');
            $result = $service->applyMissingAttributionInDateRange($from, $to);
            \WP_CLI::log(sprintf(
                'Applied attribution to %d orders (%d errors).',
                (int) $result['processed_count'],
                (int) $result['error_count']
            ));
            \WP_CLI::success('Backfill apply complete.');
            return;
        }

        $summary = $service->summarizeMissingAttributionInDateRange($from, $to);
        \WP_CLI::log(sprintf('Dry run: %d orders missing attribution.', (int) $summary['order_count']));
        \WP_CLI::log(sprintf('Gross CHF: %s', number_format((float) $summary['gross_chf'], 2, '.', '')));
        \WP_CLI::log(sprintf('Net CHF: %s', number_format((float) $summary['net_chf'], 2, '.', '')));
        \WP_CLI::success('Dry run complete (no writes).');
    }
}

\WP_CLI::add_command('intersoccer-rr', Intersoccer_Rr_Wp_Cli::class);
