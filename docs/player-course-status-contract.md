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
| `booked` | string | `all` | `all` \| `booked` \| `not_booked` — filters **players** after join |
| `search` | string | `''` | Player name or parent email (normalized) |
| `venue` | string | `''` | EN venue slug; narrows which bookings count as “booked” |
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
    'unmatched_bookings' => int,
  ],
]
```

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
  'booked' => bool,
  'bookings' => list<booking>,
  'bookings_label' => string, // labels joined "; "
]
```

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
- **Booked** (PCS parity via `intersoccer_roster_report_status_counts_as_booked`): `completed` and `processing` only.
- **Not booked** and **not** listed in `unmatched_bookings`: `pending`, `on-hold`, `cancelled`, `refunded`, `failed`, and wholly refunded orders.
- Line fully refunded excluded (`intersoccer_roster_report_row_line_is_fully_refunded` after refund priming).
- Partial refunds on completed/processing still count.
- Facet filters (`venue`, `course_day`, `age_group`) apply only to whether `booked` is true; player list remains all PM players.

### Year rule

A course row is in year **Y** when:

1. `start_date` year or `end_date` year equals **Y**, or
2. `intersoccer_reports_resolve_program_year()` on the row (after batch `Year` / `pa_program-year` on the line) equals **Y**.

SQL prefilter: `start_date` / `end_date` year in `[Y-1, Y+1]` or null/empty dates; final decision in PHP.

### Course vs camp

`intersoccer_roster_row_matches_listing_kind($row, 'course')` only. Placeholders excluded (`is_placeholder = 0`).

### Player matching

Reuse `intersoccer_player_camp_status_load_players()` (batched `cache_users()` after DISTINCT `user_id` query).

**Tier union:** For each PM player, collect bookings from **all** alias keys (`uuid`, then `idx`, then `name` — same key strings as `intersoccer_roster_report_collect_player_alias_keys`). Dedupe by `order_item_id`, sort bookings by `start_date`. Set `tier` / `key` to the **best-ranked** tier that had hits (`intersoccer_roster_report_player_key_tier_rank`: uuid > idx > name).

**Idx collision guard:** Before building the booking index, reset and feed collision detection (`intersoccer_roster_report_reset_idx_name_collision_state()` + `intersoccer_roster_report_note_idx_name_for_collision_detection($row)` over scoped rows) so `collect_player_alias_keys()` drops `idx` keys when the same `(customer_id, player_index)` carries two different names.

Legacy `customer_id = 0` rows resolve customer from order before keying; index 0 does not steal siblings.

### DOB

Same gate as PCS: `intersoccer_player_course_status_user_can_view_dob()` (wraps `intersoccer_rr_current_user_can_view_dob()` / `intersoccer_user_can_view_dob()`). Age always present.

## Excel export

| Item | Value |
|------|--------|
| Nonce action | `intersoccer_player_course_status_excel` |
| Filename | `player-course-status-{year}.xlsx` |
| Sheet title | `Player Course Status` |
| Headers | `Player Name`, `Parent Email`, `User ID`, `DOB` (if allowed), `Age`, `Booked`, `Course Bookings`, `Match Tier` |

Export uses the same `generate()` rows as the screen (no separate query path).
