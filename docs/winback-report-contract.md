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
| `venue` | string (EN slug) | `''` | Restrict to players whose **latest** history event is at this venue slug; empty = all |
| `age_group` | string (EN slug) | `''` | Restrict to players whose **latest** history event uses this age-group slug; empty = all |
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
| `target` | array \| null | `product`, `venue`, `age_group`, `start_date` — earliest upcoming catalogue match at the resolved target age band, or null |
| `more_targets` | array | Up to **3** further upcoming catalogue matches (same kind, venue, and target age group as `target`), same shape as `target`, ordered by `start_date`. Empty array when `target` is null or no additional matches exist. Does not repeat `target`. `target` and `more_targets` are deduped together by **(product ID, week start date `start_date`)**: at most one entry per product per week; when multiple variations share that key, keep the **full-week** booking type (`pa_booking-type` normalized to `full-week`) over **single-days**, then lowest `source_variation_id`. The chosen `target` week is never repeated in `more_targets`. |
| `aged_up` | bool | True when the target age band differs from the last booked band because the child is older than the booked band’s max at **that target’s** `start_date` (J1). False when there is no catalogue target. |
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

- Live Woo order status overrides roster `order_status` when the order can be loaded. **HPOS is enabled only when** `OrderUtil::custom_orders_table_usage_is_enabled()` is true (or `woocommerce_custom_orders_table_enabled === 'yes'` when OrderUtil is absent). Table presence alone does **not** enable HPOS reads. When HPOS is enabled but an order id is missing from `wc_orders`, the loader falls back to `shop_order` posts for that id.
- History and upcoming counts use **completed** and **processing** only; **pending** / **on-hold** count as upcoming rebooked, not history (J10).
- **Wholly refunded** orders (`refunded` / `wc-refunded`) drop out.
- **Partial refunds:** an order with refunds but an active status stays eligible; a roster line is excluded only when that **order line is fully refunded** (same qty/total rule as `RosterRefundService::isOrderItemFullyRefunded()`). Partially refunded lines still count as booked.

## Excel export (DOB)

Win-back Excel uses `intersoccer_winback_report_format_dob_for_export()` → `intersoccer_player_camp_status_format_dob_for_display()` after `intersoccer_winback_report_load_dob_gate()`. When the gate cannot load, the restricted label is used (never raw DOB). Age column is always included.

## Age-group aliases (catalogue)

German and French `pa_age-group` slugs on EN source variations normalize via the static alias map, regex rules, optional WPML default-language slug lookup, and the filter `intersoccer_winback_age_group_aliases`.

| Pattern | EN slug |
|---------|---------|
| `N-M-jahre` | `N-My` |
| `N-M-jahre-halber-tag` | `N-My-half-day` |
| `N-M-jahre-ganzer-tag` | `N-My-full-day` |
| `N-My-demi-journee` | `N-My-half-day` |
| `N-My-journee-complete` or `N-My-journee-entiere` | `N-My-full-day` |
| `N-M-ans` | `N-My` |
| `N-M-ans-demi-journee` | `N-My-half-day` |
| `N-M-ans-journee-complete` or `N-M-ans-journee-entiere` | `N-My-full-day` |

Typo `-and-` in slugs is normalized to `-ans-` before mapping (e.g. `4-5-and-demi-journee` → `4-5y-half-day`).

When WPML is active, a slug that exists in `pa_age-group` may be replaced with the default-language term slug before the regex rules run (no-op in PHPUnit / when WPML is absent).

## Multi-value roster facets (venue / age_group)

Some camp roster rows store concatenated fallback strings (multi-venue slugs joined with `---`, or two age bands in one slug). These are **not** alias-mapped.

Detection: `intersoccer_winback_facet_is_multi_value()` — venue values containing `---`, age values with two or more `\d+-\d+y` tokens, or (at runtime with term lookup) a slug-like value that is not a single `pa_*` term.

Resolution: `intersoccer_winback_normalize_roster_facet_slug()` loads the order line / variation attribute (`attribute_pa_*`, `pa_*`, `_intersoccer_canonical_*`) and normalizes that value with the same EN-slug rules. The engine uses this for history and upcoming venue slugs.

When resolution fails: the facet slug is left empty, the raw value is counted in `unmapped_facets`, and the row does not match venue/age filters or catalogue targeting (`target` stays null via the existing `no_upcoming` path). No new `status` value was added.

When a roster age value **does** normalize to an EN slug (including static aliases such as `5-12` / `5-12-jahre` → `5-12y`) but that slug is **not** an upcoming catalogue age band at the player’s venue for that kind **and** is not an existing `pa_age-group` term slug, the engine registers the **normalized EN slug** in `unmapped_facets['age_group']` when the row ends `no_upcoming` (one count per output row, same counter rules as other unmapped facets). Status stays `no_upcoming`; do not invent alias→catalogue band mappings.

## History aggregation and filters

Per player and kind, the engine keeps the **latest** eligible history row (by resolved event start date), then applies `venue` / `age_group` filters to that row only. `events_in_lookback` still counts all eligible history rows in the lookback window.

**J8 (course, current term):** A course player with a live roster row whose `start_date` ≤ today and (`end_date` is NULL or a real end date ≥ today) is treated as still enrolled in the running term and is excluded from win-back output. Date sanity uses `intersoccer_roster_report_has_real_date()` in PHP; roster SQL does not compare DATE columns to `''` (MySQL strict mode). Cancelled / refunded live orders do not count as current.

## Shared loader

`includes/roster-report-loader.php` — live Woo order status (batched from HPOS `wc_orders` when enabled, else `posts`, with per-id posts fallback when HPOS is on), order-status policy, line-level full refund checks, customer resolution, fake-user proxy, player keys. Player camp status and win-back apply live status before “booked” / history / rebook rules (roster `order_status` is fallback when the order row is missing).

## Excel export

Optional column **More targets**: comma-separated `product (start_date)` from `more_targets` (export stream only; HTML page unchanged).
