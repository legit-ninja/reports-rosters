# Player course status — backend contract

Stacked on win-back / PCS shared loader (`includes/roster-report-loader.php`). UI: Finn builds on top of this contract.

## Admin page

| Item | Value |
|------|--------|
| Slug | `intersoccer-player-course-status` |
| Parent | `null` (hidden menu) |
| Capability | `manage_options` |
| Render | `intersoccer_render_player_course_status_page()` |
| Export hook | `admin_init` priority 1 + `load-{hook}` → `intersoccer_player_course_status_maybe_export_excel()` |

## Engine

`intersoccer_player_course_status_generate(array $filters): array`

Request parser: `intersoccer_player_course_status_request_filters()` (adds `paged` for QA page only).

### Filters

| Key | Type | Default | Notes |
|-----|------|---------|--------|
| `year` | int | current calendar year | Course year rule below |
| `season_type` | string | `''` | `Summer` / `Winter` / `Autumn` / `Spring`; empty = all |
| `girls_mode` | string | `all` | `all` \| `mixed` \| `girls_only` |
| `booked` | string | `all` | `all` \| `booked` \| `not_booked` — filters **players** after join. `not_booked` includes players whose `booking_status` is `pending_payment`, `on_hold`, or plain `not_booked`. |
| `booking_status` | string | `''` | `''` (all) \| `booked` \| `pending_payment` \| `on_hold` \| `not_booked` — applied **after** `booked`. Here `not_booked` means plain not-booked only (excludes pending/on-hold). |
| `search` | string | `''` | Player name or parent email (normalized) |
| `venue` | string | `''` | EN venue slug; narrows which bookings count toward `booked` / `booking_status` |
| `course_day` | string | `''` | EN weekday slug, lowercase (e.g. `sunday`) |
| `age_group` | string | `''` | EN age-group slug |

Pagination is **not** applied in the engine; the QA page slices `rows` (50 per page).

### Result shape

```php
[
  'rows' => list<playerRow>,
  'unmatched_bookings' => list<booking + ['player_name','customer_id']>,
  'filter_options' => [
    'venues' => slug => label,
    'course_days' => list<string slug>,
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
  'label' => string,
]
```

Label format: `product · venue · course_day · times · season · start–end` (empty parts skipped).

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

### Course vs camp

`intersoccer_roster_row_matches_listing_kind($row, 'course')` only. Placeholders excluded (`is_placeholder = 0`).

### Player matching

Reuse `intersoccer_player_camp_status_load_players()` (batched `cache_users()` after DISTINCT `user_id` query).

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
| Headers | `Player Name`, `Parent Email`, `User ID`, `DOB` (if allowed), `Age`, `Booked`, `Status`, `Course Bookings`, `Match Tier` |

`Booked` remains Yes/No. `Status` is the translated `booking_status_label`. Pending lines are not included in `Course Bookings`.

Export uses the same `generate()` rows as the screen (no separate query path). If `warnings` is non-empty, export stops with `wp_die()` instead of streaming a workbook.
