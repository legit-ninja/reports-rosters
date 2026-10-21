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
     * Reconcile rosters against WooCommerce orders (dry-run by default).
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Persist reconcile changes (requires explicit flag).
     *
     * [--only-missing]
     * : Insert missing roster rows only (no updates or deletes).
     *
     * [--from=<date>]
     * : Optional order date from (Y-m-d).
     *
     * [--to=<date>]
     * : Optional order date to (Y-m-d).
     *
     * ## EXAMPLES
     *
     *     wp intersoccer-rr reconcile-rosters
     *     wp intersoccer-rr reconcile-rosters --apply --yes
     *
     * @subcommand reconcile-rosters
     * @alias reconcile_rosters
     * @when after_wp_load
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc_args
     */
    public function reconcile_rosters(array $args, array $assoc_args): void {
        if (!function_exists('intersoccer_oop_reconcile_rosters')) {
            \WP_CLI::error('OOP roster reconcile is not available.');
        }

        $apply = \WP_CLI\Utils\get_flag_value($assoc_args, 'apply', false) === true;
        $yes = \WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false) === true;
        $only_missing = \WP_CLI\Utils\get_flag_value($assoc_args, 'only-missing', false) === true;

        if ($apply && !$yes) {
            $prompt = 'Apply roster reconcile? [y/n] ';
            $answer = '';
            if (defined('INTERSOCCER_RR_TESTING') && INTERSOCCER_RR_TESTING) {
                $filtered = apply_filters('intersoccer_rr_cli_confirm', false, $prompt, 'reconcile-rosters');
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

        $options = [
            'dry_run' => !$apply,
            'only_missing' => $only_missing,
            'delete_obsolete' => true,
        ];
        if (!empty($assoc_args['from'])) {
            $options['date_from'] = (string) $assoc_args['from'];
        }
        if (!empty($assoc_args['to'])) {
            $options['date_to'] = (string) $assoc_args['to'];
        }
        if (!empty($options['date_from']) || !empty($options['date_to'])) {
            $options['delete_obsolete'] = false;
        }

        $result = intersoccer_oop_reconcile_rosters($options);
        $report = is_array($result['report'] ?? null) ? $result['report'] : [];

        if (!$apply) {
            \WP_CLI::log(sprintf(
                'Dry run: would insert %d, update %d, delete %d, ambiguous player skips %d, errors %d',
                (int) ($report['would_insert'] ?? 0),
                (int) ($report['would_update'] ?? 0),
                (int) ($report['would_delete'] ?? 0),
                (int) ($report['ambiguous_player']['count'] ?? 0),
                (int) ($result['errors'] ?? 0)
            ));
            \WP_CLI::success('Dry run complete (no writes).');
            return;
        }

        \WP_CLI::log(sprintf(
            'Applied: synced %d, deleted %d, errors %d',
            (int) ($result['synced'] ?? 0),
            (int) ($result['deleted'] ?? 0),
            (int) ($result['errors'] ?? 0)
        ));
        \WP_CLI::success('Reconcile apply complete.');
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
}

\WP_CLI::add_command('intersoccer-rr', Intersoccer_Rr_Wp_Cli::class);
