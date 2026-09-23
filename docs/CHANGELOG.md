# Changelog

## 2.8.24 — 2026-09-23

### Changed
- **Final Reports as its own admin page** (`page=intersoccer-final-reports`) — no longer a hub tab. Camp/Course `activity_type` filters available. Form stays on the Final Reports page after submission. Booking Reports hub now contains only Booking Report + Revenue by Type. Legacy redirects preserved. (#65, #62)

## 2.8.23 — 2026-09-22

### Added
- **Revenue by Product Type** as a dedicated tab in the Booking Reports hub (alongside Booking Report and Final Numbers): Course · Camp · Birthday · Tournament · Other/Unmapped buckets with Net CHF, % of Net, Gross, Final, and Bookings columns.
- Revenue by Product Type dedicated Excel export via the new tab (same metrics as UI).
- `ProductTypeClassifierService` for order-item classification using the chain: `_intersoccer_canonical_activity_type` → FacetNormalizer → PV product type → Other/Unmapped.
- PHPUnit tests for product type classification, birthday inclusion, Net % math, and tournament French/German slug mapping.

### Changed
- Revenue by Product Type moved from embedded block in Booking Report totals to its own tab (`#revenue-by-type`) for cleaner separation.

### Fixed
- Net formula: `Net = Final − refund` (once), where `Final = Gross − discount` (no refund in Final). Previously refund was subtracted twice.
- French slug `tournoi` now correctly maps to Tournament (was incorrectly Birthday).
- Excel percentage format changed from `0.0"%"` to `0.0%` for proper rendering.

### Notes
- **Placement**: Own tab under Reports hub (`#revenue-by-type`), not embedded in Booking Report.
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

