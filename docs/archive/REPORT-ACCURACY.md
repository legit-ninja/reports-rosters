# Report accuracy contract — Final Numbers / Live Snapshot

Honesty-first contract for enrollment figures in **InterSoccer Reports and Rosters**.

## What PHPUnit guarantees

These behaviours are locked by the **Production** test suite (`tests/Reports/FinalReportsTotalsTest.php`, `tests/Reports/FinalReportsAggregationAccuracyTest.php`, `tests/Reports/FinalReportsUrgencyTest.php`) and fail `./deploy.sh` when broken:

| Guarantee | Helper / area |
|-----------|----------------|
| Final mode counts `wc-completed` only; Live counts `wc-completed` + `wc-processing` | `intersoccer_reports_final_report_order_statuses()`, `intersoccer_reports_order_status_allowed_for_mode()` |
| Full-week vs BuyClub vs individual Mon–Fri day counts; min = FW+BuyClub, max = that + peak single-day weekday; order_item dedupe | `intersoccer_reports_aggregate_camp_location_group()` |
| Mini (3-5y / half-day) vs Full Day buckets | `intersoccer_reports_build_camp_report_from_entries()` |
| BuyClub counted in its own column (WooCommerce 100% coupon / buyclub.ch); omitted when exclude is on | aggregation + `intersoccer_reports_row_should_exclude_for_buyclub_option()` |
| Grand TOTAL / All registrations = Full Week + BuyClub; Individual days = sum of M–F | `intersoccer_reports_camp_grand_totals()` |
| Summer (season type) + year filter on synthetic rows | `intersoccer_reports_filter_entries_by_season_year()` |
| Course registrations: roster_row_id dedupe; order_item fallback | `intersoccer_reports_build_course_report_from_entries()` |
| Flat excel helper All-registrations cell = FW+BuyClub (not FW+sum(days)) | `intersoccer_reports_camp_excel_data_rows()` |
| Urgency bands Critical ≤7 / Low ≤20 / Good ≤29 / Optimal 30+ | `intersoccer_reports_urgency_band()` |
| Camp heatmap score = max of min–max string | `intersoccer_reports_parse_min_max_max()`, `intersoccer_reports_camp_metrics_urgency_band()` |
| Excel Urgency column derived from max(min–max); `urgency_only` filters Critical+Low | `intersoccer_reports_camp_excel_data_rows($data, true)` |
| FR/DE camp_terms slugs parse to English calendar Date Range keys | `intersoccer_parse_camp_dates_fixed()`, `intersoccer_reports_month_token_to_english()` |

Pure aggregation lives in `includes/final-reports-aggregation.php`. `intersoccer_get_final_reports_data()` materialises rows from WooCommerce / rosters SQL, then calls these helpers.

## Multilingual camp_terms Date Range

French/German camp term **slugs** (e.g. `semaine-dete-4-13-17-juillet-5-jours`) must not appear as Final Camp Date Range keys. The parser maps them to English calendar keys such as `July 13 - July 17, 2026`. On live sites, `intersoccer_reports_normalize_camp_terms_for_dates()` also resolves WPML default-language term names before parse / undated fallback (`INTERSOCCER_TESTING` skips WPML for deterministic unit tests).

## Urgency heatmap on Final Camp / Course Reports

The standalone **Underfilled programs** admin page was removed. Marketing underfilled targeting lives on **Final Camp Reports** and **Final Course Reports**:

- **Bands** match roster count badges (`intersoccer_get_count_class()` / `intersoccer_reports_urgency_band()`).
- **Camps:** color Total min–max cells using the **max** of the range; optional `urgency_only` keeps venues where Full Day and/or Mini is Critical or Low.
- **Courses:** color Registrations cells; `urgency_only` keeps Critical/Low rows.
- **Status modes unchanged:** Final = completed; Live = completed + processing (no pending/on-hold).
- **Live Snapshot** links to Live Final Reports, including Critical+Low presets (`urgency_only=1`).
- **Excel** Final Camp export uses the summer camps numbers **grid** (Full Day | Mini side-by-side with BuyClub, week section headers, TOTAL + All registrations). Min-max cells keep urgency fill. Pitchside is out of scope and omitted.
- **Excel** Course export still includes an Urgency column and band-colored Registrations cells.

## What is still manual / not guaranteed in CI

- Full WooCommerce SQL → row materialisation on production/staging databases (indexes, missing meta, bad dates).
- WPML facet remapping edge cases beyond stubs (`INTERSOCCER_TESTING` skips facet lookups in helpers).
- Cypress admin Final Camp/Course specs remain **smoke** (page load / permissions) unless known fixture data is asserted.
- Pitchside / capacity / stock vs sold (intentionally omitted from Final Camp Numbers).

## How to add a regression

1. Reproduce the wrong number with a **synthetic entry array** (do not rely on live DB for the unit test).
2. Assert via the matching helper in `FinalReportsAggregationAccuracyTest` or `FinalReportsUrgencyTest`.
3. Comment: `// Regression: ISSUE-ID — short summary`.
4. Keep the test in the Production suite if it protects enrollment figures or urgency banding.

## Related docs

- Live Snapshot / Final Camp & Course heatmap UX: Reports & Rosters admin menus.
- Deploy gate: `./deploy.sh` → `vendor/bin/phpunit --testsuite=Production`.
