# Win-back report engine contract

Backend contract for the camps + courses win-back report. Finn’s UI should call the engine only; the hidden QA page passes filters through unchanged.

## Admin page (QA, hidden)

| Item | Value |
|------|--------|
| Page slug | `intersoccer-winback-report` |
| Capability | `manage_options` |
| Render function | `intersoccer_render_winback_report_page()` (`includes/winback-report-admin.php`) |
| Export action | GET `export_excel=1` on the same page |
| Export nonce | `intersoccer_winback_report_excel` (`check_admin_referer`) |
| Filter parser | `intersoccer_winback_report_request_filters()` (single GET parser) |

Direct URL shape: `admin.php?page=intersoccer-winback-report`

## Engine entry point

| Item | Value |
|------|--------|
| Function | `intersoccer_winback_report_engine_generate(array $filters): array` (`includes/winback-report-engine.php`) |
| Return | `['rows' => list<row>, 'unmapped_facets' => ['venue' => string→count, 'age_group' => string→count]]` |

The page must not implement matching logic; it calls `intersoccer_winback_report_run()` which delegates to the engine.

## Filters (engine input)

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `kind` | `camp` \| `course` \| `all` | `all` | Limit history and catalogue matching |
| `venue` | string (EN slug) | `''` | Restrict to one venue slug; empty = all |
| `age_group` | string (EN slug) | `''` | Restrict to one age-group slug; empty = all |
| `lookback_months` | int | `12` | History window on resolved event start (months before today) |

GET query names match these keys (`lookback_months`, etc.).

## Output row (required keys)

Each element of `rows` is an associative array:

| Key | Type | Meaning |
|-----|------|---------|
| `player` | array | `name` (string), `key` (string), `tier` (`uuid` \| `idx` \| `name`) |
| `parent` | array | `name` (string), `id` (int customer user ID) |
| `contact` | array | `email` (string billing), `phone` (string), `contact_differs` (bool), `account_email` (string) |
| `last_event` | array | `product`, `venue`, `age_group` (EN slugs where resolved), `date` (`Y-m-d` or null), `date_source` (see below) |
| `target` | array \| null | `product`, `venue`, `age_group`, `start_date` — earliest upcoming catalogue match, or null |
| `aged_up` | bool | Child aged past last booked band (J1) |
| `sibling_booked` | bool | Another child on same account has upcoming booking same kind (J3) |
| `rebooked_elsewhere` | bool | Upcoming booking same kind at a different venue (J2b) |
| `full` | bool | Target variation out of stock (J12) |
| `status` | string | `actionable`, `no_upcoming`, or `date_unknown` |

### `date_source` values (`last_event.date_source`)

`roster_start_date`, `variation_camp_start`, `order_item_camp_start`, `camp_terms_parse`, `variation_course_start`, `order_date`, `unknown`

When no roster start, variation meta, or parseable `camp_terms` path yields a date but the row has a Woo order, `intersoccer_winback_resolve_row_event_date()` uses the order created date with `date_source` **`order_date`**.

Yearless `camp_terms` week slugs (no four-digit year in `camp_terms` or `season`) use **`camp_terms_parse`** with a calendar year from the **order created date** in the win-back chain (`intersoccer_winback_apply_camp_terms_order_year()`): default to the order year; if `order_month - parsed_camp_month > 6` (same calendar year), use **order year + 1** (book-ahead). A four-digit year in **`season` or `camp_terms` wins** over the order year. The report run date is never used. With no order created date, the camp_terms step yields no date and the chain continues (`order_date` / `unknown`).

### Optional extra keys (stable but not required for UI v1)

`dob`, `age`, `account_email` (duplicate of `contact.account_email`), `events_in_lookback`

## Player key tiers

1. `uuid:{assigned_player_id}` — tier `uuid`
2. `idx:{customer_id}:{player_index}` — tier `idx` when the roster row has a **stored** `customer_id > 0` (including `player_index` **0**). Never `idx:0:*`.
3. `name:{customer_id}:{first}|{last}` — normalized lowercase names — tier `name` for legacy rows with stored `customer_id = 0` (customer resolved from the order before keying).

Customer ID is resolved from the order when roster `customer_id` is 0 before keying and fake-user checks.

When the same resolved `customer_id` + `player_index` would map to **different** normalized player names, the engine does **not** merge those children: idx-tier union is skipped, rows key on the **name** tier, and output `player.tier` reflects the tier used (`name`).

## Order status and refunds (win-back)

- Live Woo order `post_status` (or HPOS equivalent) overrides roster `order_status` when the order row exists.
- History and upcoming counts use **completed** and **processing** only; **pending** / **on-hold** count as upcoming rebooked, not history (J10).
- **Wholly refunded** orders (`refunded` / `wc-refunded`) drop out.
- **Partial refunds:** an order with refunds but an active status stays eligible; a roster line is excluded only when that **order line is fully refunded** (same qty/total rule as `RosterRefundService::isOrderItemFullyRefunded()`). Partially refunded lines still count as booked.

## Excel export (DOB)

Win-back Excel uses `intersoccer_winback_report_format_dob_for_export()` → `intersoccer_player_camp_status_format_dob_for_display()` after `intersoccer_winback_report_load_dob_gate()`. When the gate cannot load, the restricted label is used (never raw DOB). Age column is always included.

## Age-group aliases (catalogue)

German `pa_age-group` slugs on EN source variations (e.g. `5-12-jahre`) normalize via the static alias map, the rule `N-M-jahre` → `N-My`, and the filter `intersoccer_winback_age_group_aliases`.

## Shared loader

`includes/roster-report-loader.php` — live Woo order status (batched from `posts` or HPOS `wc_orders`), order-status policy, line-level full refund checks, customer resolution, fake-user proxy, player keys. Player camp status and win-back apply live status before “booked” / history / rebook rules (roster `order_status` is fallback when the order row is missing).
