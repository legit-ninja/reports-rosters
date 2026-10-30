# Player course status — backend contract

Stacked on win-back / PCS shared loader (`includes/roster-report-loader.php`). UI: Finn builds on top of this contract.

## Admin page

| Item | Value |
|------|--------|
| Slug | `intersoccer-player-course-status` |
| Parent | `intersoccer-reports-rosters` (visible submenu beside **Player camp status**) |
| Capability | `manage_options` |
| Office UI | `includes/player-course-status-admin.php` — warnings `notice-warning inline` directly under `<h1>`; secondary **Final course numbers** → `admin.php?page=intersoccer-final-reports&activity_type=Course&year={year}` |
| Render | `intersoccer_render_player_course_status_page()` |
| Export hook | `admin_init` priority 1 + `load-{hook}` → `intersoccer_player_course_status_maybe_export_excel()` |

## Engine

`intersoccer_player_course_status_generate(array $filters): array`

Request parser: `intersoccer_player_course_status_request_filters()` (adds `paged` for QA page only).

### Filters

| Key | Type | Default | Notes |
|-----|------|---------|--------|
| `year` | int | current calendar year | Course year rule below |
| `season_type` | string | `''` in `normalize_filters()` / `generate()` | **`courses`** on first office page load via `request_filters()` when `season_type` is omitted — matches rows whose season type is **Autumn**, **Winter**, or **Spring** (excludes **Summer**). Label: “Autumn / Winter / Spring”. Also: `Summer` / `Winter` / `Autumn` / `Spring` individually; empty string (explicit **All seasons**) = no season filter |
| `girls_mode` | string | `all` | `all` \| `mixed` \| `girls_only` |
| `booked` | string | `all` | `all` \| `booked` \| `not_booked` — filters **players** after join. `not_booked` includes players whose `booking_status` is `pending_payment`, `on_hold`, or plain `not_booked`. |
| `booking_status` | string | `''` | `''` (all) \| `booked` \| `pending_payment` \| `on_hold` \| `not_booked` — applied **after** `booked`. Here `not_booked` means plain not-booked only (excludes pending/on-hold). |
| `search` | string | `''` | Player name or parent email (normalized) |
| `venue` | string | `''` | EN venue slug; narrows which bookings count toward `booked` / `booking_status` |
| `course_day` | string | `''` | EN weekday slug, lowercase (e.g. `sunday`) |
| `age_group` | string | `''` | EN age-group slug |

Pagination is **not** applied in the engine; the QA page slices `rows` (50 per page).

### Season labels (courses report)

Roster `season` strings are normalized only inside the player course status engine (not via shared `intersoccer_extract_season_type` / `intersoccer_normalize_season_for_display`).

- Collapse repeated whitespace.
- **Title case per word**, including segments after `/` (e.g. `Spring/summer 2026` → `Spring/Summer 2026`).
- `filter_options['seasons']` dedupes by case-insensitive canonical label; compound labels (containing `/`) stay as the full canonical string; simple `Season YYYY` rows still expose the extracted season **type** (e.g. `Autumn`) for backward compatibility.
- Booking `season`, `label`, and `bookings_label` use the canonical label.
- `season_type` filter matching uses canonical comparison plus existing season-type / substring rules. Special value **`courses`**: include Autumn, Winter, and Spring (compound **Spring/Summer** counts as Spring); exclude standalone Summer. Empty or unrecognised season labels remain visible in the **`courses`** filter.

### Full-result cache

`intersoccer_player_course_status_generate()` caches the complete result array (same shape as below) in a WP transient so paging and Excel export reuse one run per filter set.

| Item | Detail |
|------|--------|
| Key prefix | `intersoccer_pcs_` + MD5 of JSON: cache schema version (`intersoccer_player_course_status_cache_schema_version()`, currently `5`), normalized filters (`year`, `season_type`, `girls_mode`, `booked`, `booking_status`, `search`, `venue`, `course_day`, `age_group` — not `paged`), data marker, output context |
| Data marker | `COUNT(*)` and `MAX(id)` on `{prefix}intersoccer_rosters`; `MAX(date_updated_gmt)` on `{prefix}wc_orders` when HPOS is active (`intersoccer_roster_report_uses_hpos_orders_table()`), else `MAX(post_modified_gmt)` on `shop_order` posts |
| Output context (cache key) | `get_locale()` and whether `intersoccer_player_course_status_user_can_view_dob()` is true (DOB column + `dob` / `dob_restricted` on rows; translated `booking_status_label` strings) |
| TTL | 300 seconds default; filter `intersoccer_player_course_status_cache_ttl` |
| Stored format | Slim result (drops derivable `player.name`, `bookings_label`, `booking_status_label`, and per-booking `label`; rehydrated on read) → `serialize` → `gzcompress` → **base64** string in the transient (text-safe for `wp_options` utf8mb4) |
| Size cap | Applied to the **stored base64 string** length after compression (default 5 MiB, filter `intersoccer_player_course_status_cache_max_bytes`). Skip write when non-empty `warnings` or stored size over cap |
| Object cache | Persistent object caches (e.g. memcached) may refuse items over ~1 MiB; WordPress then omits the transient and the report regenerates on each request until TTL/marker would have expired anyway |
| Corrupt / legacy blob | Base64 decode, `gzuncompress`, or `unserialize` failure → cache miss, transient deleted, regenerate |
| Disable | Constant `INTERSOCCER_RR_PCS_CACHE_DISABLED` or filter `intersoccer_player_course_status_cache_enabled` → false; off by default when `INTERSOCCER_RR_TESTING` |
| Bypass read | Query `pcs_refresh=1` with `_pcs_nonce` / action `intersoccer_pcs_refresh`, valid nonce and `manage_options`; regenerates and overwrites cache. Helper: `intersoccer_player_course_status_refresh_url( array $args = [] )`. Tests may pass `force_cache_refresh` in the optional second `$options` argument to `generate()` |
| Fail-safe | Cache read/write failures fall back to uncached `generate_inner()` |

### Result shape

```php
[
  'rows' => list<playerRow>,
  'unmatched_bookings' => list<booking + ['player_name','customer_id']>,
  'filter_options' => [
    'venues' => slug => label,
    'course_days' => slug => label (weekday label for admin `<select>`; value remains slug),
    'age_groups' => slug => label,
    'seasons' => list<string>,
  ],
  'totals' => [
    'players' => int,
    'booked' => int,
    'not_booked' => int,
    'pending_payment' => int,
    'on_hold' => int,
    'unmatched_bookings' => int,
  ],
  'warnings' => list<['code' => string, 'message' => string]>,  // always present; empty when OK
]
```

### Warnings

| Code | When set | UI / export |
|------|----------|-------------|
| `roster_query_failed` | `intersoccer_player_course_status_load_course_roster_rows()` ran against the DB and `$wpdb->last_error` was non-empty after the roster `SELECT` | QA page: `notice notice-warning` with escaped `message`. Excel export: **blocked** via `wp_die()` with the same message (no file download). PHP `error_log()` records a short line with the DB error text (no SQL parameters). |

Loader memo: call `intersoccer_player_course_status_reset_roster_query_state()` before load (done in `generate()`); after load, `intersoccer_player_course_status_roster_query_failed()` is true when the memo was set. Test fixtures (`$GLOBALS['intersoccer_test_player_course_status_roster_rows']`) bypass the DB loader and never set warnings.

### Strict SQL mode (DATE columns)

Roster prefilter SQL must not compare `start_date` / `end_date` to non-date literals (`''`, `'N/A'`, `'0000-00-00'`) or use string functions on DATE columns. Use `IS NULL`, sentinel `1970-01-01`, and `BETWEEN` with bound `Y-m-d` bounds (`[Y-1]-01-01` … `[Y+1]-12-31`). Empty / invalid dates are handled in PHP via `intersoccer_roster_report_has_real_date()` and `intersoccer_player_course_status_row_in_year()`.

`not_booked` total counts every player who is not facet-booked, **including** those labelled `pending_payment` or `on_hold`. The `pending_payment` and `on_hold` totals count players whose resolved `booking_status` is that label (same facet rules as `booked`).

### Player row

```php
[
  'player' => [
    'name', 'first_name', 'last_name',
    'player_index' => int|null,
    'key' => string,
    'tier' => 'uuid'|'idx'|'name'|'',
  ],
  'parent' => ['id' => int, 'email' => string],
  'dob' => 'Y-m-d'|null,
  'dob_restricted' => bool,
  'age' => int|null,
  'booked' => bool,  // true only when facet-matched completed/processing booking exists
  'booking_status' => 'booked'|'pending_payment'|'on_hold'|'not_booked',
  'booking_status_label' => string,  // translated via intersoccer_player_course_status_booking_status_label()
  'bookings' => list<booking>,       // in-scope active (completed/processing) lines only
  'pending_bookings' => list<booking>, // in-scope pending/on-hold lines (all for player, not facet-filtered)
  'bookings_label' => string, // labels from bookings[] joined "; "
]
```

**Tier / key:** Taken from the **booked** index match when that match produced any `bookings`. When the player has no booked index hits, `tier` and `key` come from the pending/on-hold index match (if any).

### Booking object

```php
[
  'order_id', 'order_item_id',
  'product', 'venue', 'venue_slug',
  'age_group', 'age_group_slug',
  'course_day', 'course_day_slug',
  'times', 'season', 'start_date', 'end_date',
  'order_status', 'girls_only' => bool,
  'parent_billing_name', 'parent_billing_email', 'parent_billing_phone' => string,  // from roster row at booking time
  'label' => string,
]
```

Label format: `product · venue · course_day · times · season · start–end` (empty parts skipped).

`times` on bookings uses roster `times`, falling back to `course_times` only when `times` is empty; values are normalized to a single display form (`HHMM-HHMM`, e.g. `1000-1130`) via `intersoccer_player_course_status_normalize_times_display()`.

`filter_options` venues / course days / age groups / seasons are built from **in-scope** roster rows for the active filter set (calendar **year**, **season_type**, **girls_mode**, and related scope applied in `filter_scoped_rows()`), limited to rows that are **completed/processing or pending/on-hold** (not cancelled/refunded/failed), so a pending-only season still appears in the season dropdown when that season is in scope. Course-day option labels are English weekdays (`intersoccer_normalize_weekday_token()` on roster label then slug).

### Status / booked rules

- Live order status from posts/HPOS batch loader (`intersoccer_roster_report_rows_apply_live_order_status`).
- Normalize with `intersoccer_roster_report_normalize_order_status()` (`pending`, `on-hold`, `wc-*` stripped).
- **`booked` bool** and **`bookings[]`**: `completed` and `processing` only (`intersoccer_roster_report_status_counts_as_booked`).
- **`booking_status` precedence** (facet-matched lines only): `booked` > `pending_payment` > `on_hold` > `not_booked`. Among pending/on-hold facet matches, any `pending` wins over `on-hold`.
- **`pending_bookings[]`**: all in-scope pending/on-hold lines for the player (like `bookings[]` lists all in-scope booked lines regardless of facet filters). Pending/on-hold lines are **not** in `bookings[]`, `bookings_label`, or `unmatched_bookings`.
- **Plain `not_booked`**: `cancelled`, `refunded`, `failed`, wholly refunded orders, fully refunded lines, or no qualifying line.
- Line fully refunded excluded (`intersoccer_roster_report_row_line_is_fully_refunded` after refund priming).
- Partial refunds on completed/processing still count as booked.
- Facet filters (`venue`, `course_day`, `age_group`) apply to whether `booked` and `booking_status` are set from booked vs pending intent; player list remains all PM players.

### Year rule

A course row is in year **Y** when:

1. `start_date` year or `end_date` year equals **Y**, or
2. `intersoccer_reports_resolve_program_year()` on the row (after batch `Year` / `pa_program-year` on the line) equals **Y**.

SQL prefilter: `start_date` / `end_date` in `[Y-1]-01-01` … `[Y+1]-12-31`, or `NULL` / `1970-01-01`; final year decision in PHP (see strict SQL note above).

### Course date resolution (read-time, PCS only)

When roster `start_date` / `end_date` are empty, sentinel (`1970-01-01`), or a localized string (FR/DE month names), the engine resolves **in memory** during `intersoccer_player_course_status_prepare_roster_rows()` (no roster table writes):

1. Parse roster column with `intersoccer_parse_date_unified()` (after optional German month normalization in `intersoccer_player_course_status_normalize_localized_date_string()`).
2. Batched order-item meta: `Start Date` / `End Date` and FR/DE aliases (`Date de début`, `Date de fin`, `Startdatum`, `Enddatum`), parsed with `intersoccer_parse_date_unified()`.
3. Default-language variation meta `_course_start_date` / `_end_date` (WPML via `intersoccer_get_default_language_variation_id()` when available).

Resolved `Y-m-d` values drive year scoping (`intersoccer_player_course_status_row_in_year()`), booking records, labels, and export. Program-year meta (`Year` / `pa_program-year`) still applies when dates do not place the row in **Y**.

### Course vs camp

`intersoccer_roster_row_matches_listing_kind($row, 'course')` only. Placeholders excluded (`is_placeholder = 0`).

### Player matching

Players: `intersoccer_player_course_status_load_players()` — one read-only `usermeta` query (`meta_key = intersoccer_players`), batched `users` email lookup via `intersoccer_roster_report_batch_chunk_size()`, same row shape/sort as PCS but **no** `intersoccer_get_user_players()` / UUID backfill writes. Test override: `$GLOBALS['intersoccer_test_player_course_status_players']`.

**Tier union:** For each PM player, collect bookings from **all** alias keys (`uuid`, then `idx`, then `name` — same key strings as `intersoccer_roster_report_collect_player_alias_keys`). Dedupe by `order_item_id`, sort bookings by `start_date`. Set `tier` / `key` to the **best-ranked** tier that had hits (`intersoccer_roster_report_player_key_tier_rank`: uuid > idx > name). Separate indexes for booked vs pending/on-hold rows share the same key logic and idx-collision priming.

**Idx collision guard:** Before building each booking index, reset and feed collision detection (`intersoccer_roster_report_reset_idx_name_collision_state()` + `intersoccer_roster_report_note_idx_name_for_collision_detection($row)` over scoped rows) so `collect_player_alias_keys()` drops `idx` keys when the same `(customer_id, player_index)` carries two different names.

Legacy `customer_id = 0` rows resolve customer from order before keying; index 0 does not steal siblings.

### DOB

Same gate as PCS: `intersoccer_player_course_status_user_can_view_dob()` (wraps `intersoccer_rr_current_user_can_view_dob()` / `intersoccer_user_can_view_dob()`). Age always present.

## Excel export

| Item | Value |
|------|--------|
| Nonce action | `intersoccer_player_course_status_excel` |
| Filename | `player-course-status-{year}.xlsx` |
| Sheet title | `Player Course Status` |
| Layout | **One row per player booking line** (booked `bookings[]` plus each `pending_bookings[]` line). Players with no lines still get **one** row with empty booking columns. |
| Helpers | `intersoccer_player_course_status_export_headers()`, `intersoccer_player_course_status_export_rows_for_player()` |

### Excel columns (in order)

**Player (repeated on each line):** `Player Name`, `Parent Email` (guardian WooCommerce account email — legacy office column), `Account Email` (same account email; billing contact per line remains `Parent Billing Email`), `User ID`, `DOB` (only when `intersoccer_player_course_status_user_can_view_dob()`), `Age`, `Booked` (Yes/No facet), `Status` (translated `booking_status_label`), `Match Tier`.

**Per line:** `Line Type` (`Booked line` / `Pending payment line` / `On hold line` / empty), `Product`, `Venue`, `Age Group`, `Course Day`, `Times` (roster `times`, not `course_times`), `Season`, `Start Date`, `End Date`, `Order Status` (normalized), `Girls Only` (Yes/No), `Order ID`, `Parent Billing Name`, `Parent Billing Email`, `Parent Billing Phone` (roster parent fields from that booking row).

`Booked` / `Status` reflect the **player** facet state; `Line Type` identifies each exported booking row. Screen/API `generate()` row shape is unchanged aside from optional new keys on booking objects (`parent_billing_*`).

Export uses the same `generate()` rows as the screen (no separate query path). If `warnings` is non-empty, export stops with `wp_die()` instead of streaming a workbook.
