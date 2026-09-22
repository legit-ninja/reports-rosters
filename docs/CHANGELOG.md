# Changelog

## 2.8.23 — 2026-09-22

### Added
- **Revenue by Product Type** summary on Booking Report Dashboard: Course · Camp · Birthday · Tournament · Other/Unmapped buckets with Net CHF, % of Net, Gross, and Final columns.
- Revenue by Product Type section in Booking Report Excel export (same metrics as UI).
- `ProductTypeClassifierService` for order-item classification using the chain: `_intersoccer_canonical_activity_type` → FacetNormalizer → PV product type → Other/Unmapped.
- PHPUnit tests for product type classification, birthday inclusion, and Net % math.

### Notes
- **Currency**: CHF only (documented in UI help text).
- **BuyClub**: Excluded via existing `billing_company` skip (same as Booking Report).
- **Birthday**: Now appears in revenue breakdown even though Rosters/Final Numbers exclude birthday.
- No CRS changes; commission netting out of scope.

## 2.8.22 — 2026-09-22

### Fixed
- Fatal error from a duplicate `intersoccer_reports_urgency_band_label()` definition in `includes/reports-ui.php` (#59).

### Changed
- Test/CI infrastructure after 2.8.21 (#58). 2.8.21 was already used for taxonomy/canonical keys and cannot be republished on Underdog.

## Docs — 2026-09-10

- Document Final Numbers / Live Snapshot UX after Phase A (#22, #14–#17).

## 2.7.25 — 2026-07-25

### Added
- Campaign Analytics module with FINAL15-aligned orders reporting (admin UI, aggregations, exports, WPML facet normalization).
- Unified Rosters admin listing with activity-type filters (Camps / Courses / Girls Only / Tournaments).
- SQL listing aggregates and caching for faster roster listings; PHPUnit coverage for girls-only and listing performance.
- Camp schedule resolve via meta with stamp and parse fallback (`intersoccer_reports_resolve_camp_schedule`).

