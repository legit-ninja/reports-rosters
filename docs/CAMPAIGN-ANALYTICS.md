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

## Coded vs uncoded revenue

Headline includes additive keys: `coded_orders` / `coded_revenue_order_totals` and `uncoded_*` (orders using any configured campaign coupon vs none). Classification uses `used_campaign_coupon` on order lines. Per-code detail remains in Coupon usage. Momentum weekly/daily also expose `coupon_revenue_order_totals`.

**CPT coupon codes are required.** An empty campaign coupon list does not mean “any coupon” — coded metrics stay at zero and a `campaign_coupon_codes_empty` warning is raised. Coupon usage only lists codes configured on the campaign CPT (never incidental codes on the same orders).

Season / new-family weekly series (M4/M5) are deferred.

## Sequencing

**Hybrid (C):** `BookingSourceInterface` with `OrdersBookingSource` default; `RosterBookingSource` behind `intersoccer_campaign_booking_source` option / filter after roster integrity verification.

Never use saturated roster prices or stale `registration_timestamp` for money/time.

## Canonical meta (PV contract)

See `intersoccer-product-variations/docs/ORDER-META-CONTRACT.md` → Language-neutral canonical keys. FacetNormalizer is the only grouping normaliser until PV writers land.

## Admin

Reports and Rosters → **Campaign Analytics**. Campaigns are CPT `intersoccer_campaign`. Summaries compute via WP-Cron (`intersoccer_campaign_rebuild`) into `{prefix}intersoccer_campaign_summaries`.

## Exports

House-style templates mirror the hand-built SWISS15 Word/Excel reports:

| Format | Layout |
|--------|--------|
| **Word** (`DocxExporter`) | Calibri, navy `#1F4E5F` title + underline, grey provenance, red caveats, navy table headers. Section order: window note → executive summary → headline → timing → demand by season → mix → region → cohorts → codes/sources (attribution limitation mandatory) → recommendations → gaps → business-age footnote. Native `.docx` via PhpWord (`phpoffice/phpword`); HTML `.doc` fallback only if the library is missing. |
| **Excel** (`ExcelExporter`) | Core sheets **Bookings / Summary / By Day / Data Notes** (Arial, navy headers, zebra rows, freeze + autofilter on Bookings). Summary uses `COUNTA`/`COUNTIF`/`IF` against Bookings (LibreOffice-safe). Optional **Channels** + **Momentum** sheets append after Data Notes. CSV fallback if PhpSpreadsheet is unavailable. |

Bookings columns (privacy allowlist only): Order ID, Date, Day, Time, Child age, Gender, Activity, Booking type, Season, Region, Venue, Code used (`Yes`/`No`). No names, DOB, contact, medical, or AVS.

**Data-quality gate:** if `gate.ok` is false, rebuild stores a stub payload and both exporters return a one-page “cannot produce” document listing blocked reasons (no partial figures).

Admin buttons: **Export data (Excel)** / **Export report (Word)** — stream the cached summary only (never sync re-aggregate on click).

**Download streaming:** exports run on `admin_init` (priority 5) via `CampaignModule::maybe_export`, not inside the page render callback. `intersoccer_campaign_send_download()` discards output buffers before setting `Content-Length`. Calling export after `admin_head` (or setting `Content-Length` to only the binary while admin HTML is already buffered) truncates the download to WordPress chrome.

Shared section/prose builder: `Campaign\Export\CampaignReportSections`.

## Tests

```bash
./vendor/bin/phpunit --testsuite=Production
# or
./vendor/bin/phpunit tests/Campaign/
```
