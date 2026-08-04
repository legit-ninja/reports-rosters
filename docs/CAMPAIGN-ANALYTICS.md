# Campaign Analytics

Orders-backed campaign reporting for InterSoccer promotions (FINAL15, Swiss National Day / SWISS15, etc.).

## SQL contract (FINAL15 goldens)

Manual analysis queries and verified goldens live in the workspace (not shipped with the plugin):

`../scratch/campaign-sql/00_MASTER_query_log_and_findings.sql`

| Section | Use |
|---------|-----|
| **A** | Design constraints (legacy orders, no WC capacity, roster defects, WPML traps) |
| **B** | Production templates the tracker implements (B.1–B.10) |
| **C** | Diagnostic provenance |
| **D** | Superseded mistakes — do not reintroduce |
| **E** | FINAL15 regression fixture (must match PHPUnit + live rebuild) |

Sibling files (`final15_recut_16-19_july.sql`, `final15_round3.sql`, …) are round-by-round provenance; prefer the MASTER for implementation.

## Sales momentum (generate vs shift)

Observation window: `before_weeks` (default 4) before campaign start through `after_weeks` (default 2) after end, plus a 10-day daily pad before start. Rebuild fetches this window in addition to the equal-length headline baseline.

Payload key `momentum`: weekly series, before/during/after phase rates (`orders_per_week_equiv`), trough verdict (`generating` | `shifting` | `insufficient_after` | `inconclusive`), daily phase zoom. Phase rates use **total** demand; coupon overlay is campaign codes only.

SWISS15 validated goldens: `../scratch/campaign-sql/swiss15_momentum_goldens.md` (window `2026-07-30 00:00:00` → `2026-08-02 22:00:00`, code `swiss15`). Incomplete after coverage → `insufficient_after` + admin warning.

Season / new-family weekly series (M4/M5) are deferred.

## Sequencing

**Hybrid (C):** `BookingSourceInterface` with `OrdersBookingSource` default; `RosterBookingSource` behind `intersoccer_campaign_booking_source` option / filter after roster integrity verification.

Never use saturated roster prices or stale `registration_timestamp` for money/time.

## Canonical meta (PV contract)

See `intersoccer-product-variations/docs/ORDER-META-CONTRACT.md` → Language-neutral canonical keys. FacetNormalizer is the only grouping normaliser until PV writers land.

## Admin

Reports and Rosters → **Campaign Analytics**. Campaigns are CPT `intersoccer_campaign`. Summaries compute via WP-Cron (`intersoccer_campaign_rebuild`) into `{prefix}intersoccer_campaign_summaries`.

## Exports

Excel (PhpSpreadsheet) with privacy allowlist + Data notes sheet + Momentum sheet. Word uses PhpWord when installed; otherwise HTML `.doc` fallback (`Campaign\Export\DocxExporter`).

```bash
composer require phpoffice/phpword:^1.2
```

## Tests

```bash
./vendor/bin/phpunit --testsuite=Production
# or
./vendor/bin/phpunit tests/Campaign/
```

## Optional PhpWord

Listed as a Composer **suggest** — not required for deploy gates.
