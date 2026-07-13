# Report accuracy contract — Final Numbers / Live Snapshot

Honesty-first contract for enrollment figures in **InterSoccer Reports and Rosters**.

## What PHPUnit guarantees

These behaviours are locked by the **Production** test suite (`tests/Reports/FinalReportsTotalsTest.php`, `tests/Reports/FinalReportsAggregationAccuracyTest.php`) and fail `./deploy.sh` when broken:

| Guarantee | Helper / area |
|-----------|----------------|
| Final mode counts `wc-completed` only; Live counts `wc-completed` + `wc-processing` | `intersoccer_reports_final_report_order_statuses()`, `intersoccer_reports_order_status_allowed_for_mode()` |
| Full-week vs individual Mon–Fri day counts, min–max, order_item dedupe | `intersoccer_reports_aggregate_camp_location_group()` |
| Mini (3-5y / half-day) vs Full Day buckets | `intersoccer_reports_build_camp_report_from_entries()` |
| BuyClub zero-net rows omitted when exclude is on | aggregation + `intersoccer_reports_row_should_exclude_for_buyclub_option()` |
| Summer (season type) + year filter on synthetic rows | `intersoccer_reports_filter_entries_by_season_year()` |
| Course registrations: roster_row_id dedupe; order_item fallback | `intersoccer_reports_build_course_report_from_entries()` |
| Excel body Total column matches aggregated cell maths | `intersoccer_reports_camp_excel_data_rows()` |

Pure aggregation lives in `includes/final-reports-aggregation.php`. `intersoccer_get_final_reports_data()` materialises rows from WooCommerce / rosters SQL, then calls these helpers.

## What is still manual / not guaranteed in CI

- Full WooCommerce SQL → row materialisation on production/staging databases (indexes, missing meta, bad dates).
- WPML facet remapping edge cases beyond stubs (`INTERSOCCER_TESTING` skips facet lookups in helpers).
- Cypress admin Final Camp/Course specs remain **smoke** (page load / permissions) unless known fixture data is asserted.
- Pitchside / capacity / stock vs sold.

## How to add a regression

1. Reproduce the wrong number with a **synthetic entry array** (do not rely on live DB for the unit test).
2. Assert via the matching helper in `FinalReportsAggregationAccuracyTest`.
3. Comment: `// Regression: ISSUE-ID — short summary`.
4. Keep the test in the Production suite if it protects enrollment figures.

## Related docs

- Live Snapshot / Underfilled admin UX: Reports & Rosters admin menus.
- Deploy gate: `./deploy.sh` → `vendor/bin/phpunit --testsuite=Production`.
