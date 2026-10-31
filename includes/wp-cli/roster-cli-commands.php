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
     * [--yes]
     * : Skip the confirmation prompt when using --apply. Does nothing without --apply.
     *
     * [--retry-skipped]
     * : Re-evaluate orders marked _intersoccer_attribution_skipped (dry-run unless --apply).
     *
     * [--list-orders]
     * : With --retry-skipped dry-run, print per-order stored vs live skip reasons (default: reason counts only).
     *   Stored reasons come from order meta; live reasons are from a fresh attribution evaluation.
     *
     * ## EXAMPLES
     *
     *     wp intersoccer-rr attribution-backfill --from=2025-01-01 --to=2026-10-12
     *
     * @subcommand attribution-backfill
     * @alias attribution_backfill
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
        $yes = \WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false) === true;
        $retry_skipped = \WP_CLI\Utils\get_flag_value($assoc_args, 'retry-skipped', false) === true;
        $list_orders = \WP_CLI\Utils\get_flag_value($assoc_args, 'list-orders', false) === true;

        $service = intersoccer_oop_get_order_financial_attribution_service();

        if ($apply) {
            if (!$yes) {
                $prompt = 'Apply financial attribution backfill for the selected date range? [y/n] ';
                $answer = '';
                if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
                    $filtered = apply_filters('intersoccer_rr_cli_confirm', false, $prompt, 'attribution-backfill');
                    if (is_string($filtered)) {
                        $answer = $filtered;
                    }
                }
                if ($answer === '' && defined('STDIN') && is_resource(STDIN)) {
                    \WP_CLI::log(rtrim($prompt));
                    $answer = (string) fgets(STDIN);
                }
                if (strtolower(trim($answer)) !== 'y') {
                    \WP_CLI::log('Aborted, nothing written.');
                    return;
                }
            }
            $result = $retry_skipped
                ? $service->applyRetrySkippedInDateRange($from, $to)
                : $service->applyMissingAttributionInDateRange($from, $to);
            \WP_CLI::log($this->formatBackfillOutcomeLine($result, true));
            \WP_CLI::success($retry_skipped ? 'Retry-skipped apply complete.' : 'Backfill apply complete.');
            return;
        }

        if ($retry_skipped) {
            $summary = $service->summarizeRetrySkippedInDateRange($from, $to);
            \WP_CLI::log($this->formatDryRunCandidateLine($summary, true));
            $this->logRetrySkippedOrderDetails($summary, $list_orders);
            \WP_CLI::success('Dry run complete (no writes).');
            return;
        }

        $summary = $service->summarizeMissingAttributionInDateRange($from, $to);
        \WP_CLI::log($this->formatDryRunCandidateLine($summary, false));
        \WP_CLI::log(sprintf(
            'Order subtotal CHF (sum of line subtotals): %s',
            number_format((float) $summary['gross_chf'], 2, '.', '')
        ));
        \WP_CLI::log(sprintf(
            'Order total CHF (incl. tax, shipping, fees): %s',
            number_format((float) $summary['net_chf'], 2, '.', '')
        ));
        \WP_CLI::success('Dry run complete (no writes).');
    }

    /**
     * @param array<string,mixed> $counts
     */
    private function formatBackfillOutcomeLine(array $counts, bool $is_apply): string {
        $applied = (int) ($counts['applied_count'] ?? $counts['processed_count'] ?? 0);
        $skipped = (int) ($counts['skipped_count'] ?? 0);
        $failed = (int) ($counts['failed_count'] ?? $counts['error_count'] ?? 0);
        $previously_skipped = (int) ($counts['previously_skipped_count'] ?? 0);
        $skipped_detail = $this->formatSkippedReasonsDetail($counts['skipped_reasons'] ?? []);

        $line = $is_apply
            ? sprintf('applied %d, skipped %d%s, failed %d', $applied, $skipped, $skipped_detail, $failed)
            : sprintf('would apply %d, skipped %d%s, failed %d', $applied, $skipped, $skipped_detail, $failed);

        if ($previously_skipped > 0) {
            $line .= sprintf(', previously skipped %d', $previously_skipped);
        }

        return $line;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function formatDryRunCandidateLine(array $summary, bool $retry_skipped): string {
        $candidates = (int) ($summary['order_count'] ?? 0);
        $applied = (int) ($summary['applied_count'] ?? 0);
        $skipped = (int) ($summary['skipped_count'] ?? 0);
        $failed = (int) ($summary['failed_count'] ?? 0);
        $previously_skipped = (int) ($summary['previously_skipped_count'] ?? 0);
        $skipped_detail = $this->formatSkippedReasonsDetail($summary['skipped_reasons'] ?? []);

        $prefix = $retry_skipped ? 'Dry run (retry-skipped)' : 'Dry run';
        $line = sprintf(
            '%s candidates %d: would apply %d, skipped %d%s, failed %d',
            $prefix,
            $candidates,
            $applied,
            $skipped,
            $skipped_detail,
            $failed
        );

        if (!$retry_skipped && $previously_skipped > 0) {
            $line .= sprintf(', previously skipped %d', $previously_skipped);
        }

        return $line;
    }

    /**
     * @param array<string,int> $reasons
     */
    private function formatSkippedReasonsDetail(array $reasons): string {
        if ($reasons === []) {
            return '';
        }

        $parts = [];
        foreach ($reasons as $reason => $count) {
            $parts[] = sprintf('%d %s', (int) $count, $this->humanizeSkipReason((string) $reason));
        }

        return ' (' . implode(', ', $parts) . ')';
    }

    private function humanizeSkipReason(string $reason): string {
        if ($reason === \InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService::SKIP_REASON_NO_LINE_ITEMS) {
            return 'no line items';
        }
        if ($reason === \InterSoccer\ReportsRosters\Services\OrderFinancialAttributionService::SKIP_REASON_NO_ATTRIBUTABLE_ITEMS) {
            return 'no attributable line items';
        }

        return $reason;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function logRetrySkippedOrderDetails(array $summary, bool $list_orders = false): void {
        if ($list_orders) {
            $orders = $summary['retry_skipped_orders'] ?? [];
            if (!is_array($orders)) {
                $orders = [];
            }

            foreach ($orders as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $order_id = (int) ($row['order_id'] ?? 0);
                $stored = (string) ($row['stored_reason'] ?? '');
                $live = array_key_exists('live_reason', $row) ? $row['live_reason'] : null;
                $line = sprintf('Order %d: stored %s', $order_id, $this->humanizeSkipReason($stored));
                if ($live === null) {
                    if ($stored !== '') {
                        $line .= ', live eligible';
                    }
                } elseif ((string) $live !== $stored) {
                    $line .= ', live ' . $this->humanizeSkipReason((string) $live);
                }
                \WP_CLI::log($line);
            }
        }

        $stored_totals = $summary['stored_reason_totals'] ?? [];
        if (is_array($stored_totals) && $stored_totals !== []) {
            $parts = [];
            foreach ($stored_totals as $reason => $count) {
                $parts[] = sprintf('%s %d', $this->humanizeSkipReason((string) $reason), (int) $count);
            }
            \WP_CLI::log('Totals (stored): ' . implode(', ', $parts));
        }

        $live_totals = $summary['live_reason_totals'] ?? [];
        if (is_array($live_totals) && $live_totals !== []) {
            $parts = [];
            foreach ($live_totals as $reason => $count) {
                $label = $reason === 'eligible' ? 'eligible' : $this->humanizeSkipReason((string) $reason);
                $parts[] = sprintf('%s %d', $label, (int) $count);
            }
            \WP_CLI::log('Totals (live): ' . implode(', ', $parts));
        }
    }

    /**
     * Player course status totals (same engine as the admin PCS page).
     *
     * ## OPTIONS
     *
     * [--year=<year>]
     * : Report year (default: current calendar year).
     *
     * [--season-type=<slug>]
     * : Season bundle filter (default: courses). Use empty string for all seasons.
     *
     * [--format=<format>]
     * : Output format: json (default) or table.
     *
     * ## EXAMPLES
     *
     *     wp intersoccer-rr pcs-totals --year=2026 --season-type=courses
     *
     * @subcommand pcs-totals
     * @when after_wp_load
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function pcs_totals(array $args, array $assoc_args): void {
        $plugin_includes = dirname(__DIR__);
        if (!function_exists('intersoccer_player_course_status_report_totals')) {
            $pcs = $plugin_includes . '/player-course-status.php';
            if (!is_readable($pcs)) {
                \WP_CLI::error('Player course status engine is not available.');
            }
            require_once $pcs;
        }

        add_filter('intersoccer_player_course_status_cache_enabled', '__return_false', PHP_INT_MAX);

        $year = isset($assoc_args['year']) ? (int) $assoc_args['year'] : (int) gmdate('Y');
        if ($year < 2000) {
            \WP_CLI::error('Invalid --year (must be >= 2000).');
        }
        $season_type = array_key_exists('season-type', $assoc_args)
            ? (string) $assoc_args['season-type']
            : 'courses';
        $format = isset($assoc_args['format']) ? (string) $assoc_args['format'] : 'json';

        $filters = [
            'year' => $year,
            'season_type' => $season_type,
        ];
        $totals = intersoccer_player_course_status_report_totals($filters);

        if ($format === 'table') {
            \WP_CLI\Utils\format_items('table', [$totals], array_keys($totals));
            return;
        }

        \WP_CLI::log(wp_json_encode($totals, JSON_PRETTY_PRINT));
    }
}

\WP_CLI::add_command('intersoccer-rr', Intersoccer_Rr_Wp_Cli::class);
