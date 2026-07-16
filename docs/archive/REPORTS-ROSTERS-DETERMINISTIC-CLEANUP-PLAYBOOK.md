# Deterministic Reports/Rosters Cleanup Playbook

## Purpose
Use a repeatable, audit-friendly process to reduce diagnostics mismatches in this order:

1. `missing_in_rosters` (Only Woo)
2. `missing_in_woo` (Only Rosters)
3. `missing_in_woo_meta` + `course_day_mismatch`

This playbook is SQL-first and deterministic: every phase materializes explicit candidate sets before any write.

## Preconditions
- Run on a staging clone first.
- Take a full DB backup/snapshot before Phase 1.
- Execute all SQL as a DB user with create table/insert/update/delete privileges.
- Use the same diagnostics filter set before and after each phase (same year/activity/region/buyclub toggles).

## Table/Column Assumptions
- Roster table: `{{wp_prefix}}intersoccer_rosters`
- Woo line items: `{{wp_prefix}}woocommerce_order_items`
- Woo line item meta: `{{wp_prefix}}woocommerce_order_itemmeta`
- Orders posts: `{{wp_prefix}}posts`

Primary keys used:
- `intersoccer_rosters.order_item_id` (unique index exists)
- `woocommerce_order_items.order_item_id`

## Diagnostics Baseline (must record)
Record the diagnostics output in your run log:
- Woo rows
- Roster rows
- Intersection
- Only Woo
- Only Rosters
- Mismatches
- Reason counts

## Global Run Variables
Use one run label per execution. In SQL scripts, `{{run_suffix}}` is a literal placeholder you replace manually.

```text
run_suffix example: 20260406_1810
```

## Phase 0: Snapshot + Candidate Materialization

### 0.1 Create audit schema objects
```sql
CREATE TABLE IF NOT EXISTS {{wp_prefix}}rr_cleanup_runs (
  run_id varchar(64) PRIMARY KEY,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes text
);

CREATE TABLE IF NOT EXISTS {{wp_prefix}}rr_cleanup_orphan_quarantine
LIKE {{wp_prefix}}intersoccer_rosters;

ALTER TABLE {{wp_prefix}}rr_cleanup_orphan_quarantine
  ADD COLUMN IF NOT EXISTS quarantined_at datetime NULL,
  ADD COLUMN IF NOT EXISTS run_id varchar(64) NULL;

INSERT INTO {{wp_prefix}}rr_cleanup_runs (run_id, notes)
VALUES ('{{run_suffix}}', 'Deterministic reports/rosters cleanup')
ON DUPLICATE KEY UPDATE notes = VALUES(notes);
```

### 0.2 Materialize deterministic bucket sets
```sql
DROP TABLE IF EXISTS {{wp_prefix}}rr_missing_in_rosters_{{run_suffix}};
DROP TABLE IF EXISTS {{wp_prefix}}rr_missing_in_woo_{{run_suffix}};
DROP TABLE IF EXISTS {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}};

-- Bucket A: present in Woo line items, absent in rosters
CREATE TABLE {{wp_prefix}}rr_missing_in_rosters_{{run_suffix}} AS
SELECT
  oi.order_item_id,
  oi.order_id
FROM {{wp_prefix}}woocommerce_order_items oi
JOIN {{wp_prefix}}posts p
  ON p.ID = oi.order_id
  AND p.post_type = 'shop_order'
  AND p.post_status = 'wc-completed'
LEFT JOIN {{wp_prefix}}intersoccer_rosters r
  ON r.order_item_id = oi.order_item_id
WHERE oi.order_item_type = 'line_item'
  AND r.order_item_id IS NULL;

ALTER TABLE {{wp_prefix}}rr_missing_in_rosters_{{run_suffix}}
  ADD PRIMARY KEY (order_item_id);

-- Bucket B: present in rosters, absent in Woo line items (orphans)
CREATE TABLE {{wp_prefix}}rr_missing_in_woo_{{run_suffix}} AS
SELECT r.*
FROM {{wp_prefix}}intersoccer_rosters r
LEFT JOIN {{wp_prefix}}woocommerce_order_items oi
  ON oi.order_item_id = r.order_item_id
WHERE oi.order_item_id IS NULL;

ALTER TABLE {{wp_prefix}}rr_missing_in_woo_{{run_suffix}}
  ADD PRIMARY KEY (order_item_id);

-- Bucket C: intersecting rows with missing Woo course-day meta
CREATE TABLE {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}} AS
SELECT
  r.order_item_id,
  r.order_id,
  r.course_day AS roster_course_day,
  MAX(CASE WHEN oim.meta_key IN ('pa_course-day','attribute_pa_course-day','pa_course_day','attribute_pa_course_day')
           THEN TRIM(COALESCE(oim.meta_value,'')) ELSE '' END) AS woo_course_day
FROM {{wp_prefix}}intersoccer_rosters r
JOIN {{wp_prefix}}woocommerce_order_items oi
  ON oi.order_item_id = r.order_item_id
LEFT JOIN {{wp_prefix}}woocommerce_order_itemmeta oim
  ON oim.order_item_id = oi.order_item_id
WHERE oi.order_item_type = 'line_item'
GROUP BY r.order_item_id, r.order_id, r.course_day
HAVING (woo_course_day = '' OR woo_course_day IS NULL)
   AND COALESCE(TRIM(r.course_day),'') <> ''
   AND LOWER(TRIM(r.course_day)) NOT IN ('unknown','n/a','na','-');

ALTER TABLE {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}}
  ADD PRIMARY KEY (order_item_id);
```

### 0.3 Pre-write validation checks
```sql
SELECT COUNT(*) AS missing_in_rosters_count
FROM {{wp_prefix}}rr_missing_in_rosters_{{run_suffix}};

SELECT COUNT(*) AS missing_in_woo_count
FROM {{wp_prefix}}rr_missing_in_woo_{{run_suffix}};

SELECT COUNT(*) AS missing_woo_meta_count
FROM {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}};
```

If these counts are wildly different from diagnostics, stop and re-check filters.

## Phase 1: Resolve `missing_in_rosters`

### Preferred execution path
Use plugin reconcile tooling first:
- Run `Reconcile Rosters` from Advanced tools (full scope, no date filters).
- Re-run diagnostics.
- If `missing_in_rosters` remains > 0, continue with SQL fallback below.

### SQL fallback (deterministic)
This fallback inserts placeholder/minimal rows only for unresolved candidates.

```sql
START TRANSACTION;

INSERT INTO {{wp_prefix}}intersoccer_rosters (
  order_id,
  order_item_id,
  variation_id,
  product_id,
  player_name,
  first_name,
  last_name,
  booking_type,
  product_name,
  activity_type,
  player_first_name,
  player_last_name,
  parent_first_name,
  parent_last_name,
  created_at
)
SELECT
  m.order_id,
  m.order_item_id,
  CAST(COALESCE(MAX(CASE WHEN oim.meta_key = '_variation_id' THEN oim.meta_value END), 0) AS UNSIGNED) AS variation_id,
  CAST(COALESCE(MAX(CASE WHEN oim.meta_key = '_product_id' THEN oim.meta_value END), 0) AS UNSIGNED) AS product_id,
  'Unknown Player' AS player_name,
  'Unknown' AS first_name,
  'Unknown' AS last_name,
  'single-day' AS booking_type,
  COALESCE(MAX(oi.order_item_name), 'Unknown Product') AS product_name,
  COALESCE(MAX(CASE WHEN oim.meta_key = 'Activity Type' THEN oim.meta_value END), '') AS activity_type,
  'Unknown' AS player_first_name,
  'Unknown' AS player_last_name,
  'Unknown' AS parent_first_name,
  'Unknown' AS parent_last_name,
  NOW() AS created_at
FROM {{wp_prefix}}rr_missing_in_rosters_{{run_suffix}} m
JOIN {{wp_prefix}}woocommerce_order_items oi
  ON oi.order_item_id = m.order_item_id
LEFT JOIN {{wp_prefix}}woocommerce_order_itemmeta oim
  ON oim.order_item_id = oi.order_item_id
LEFT JOIN {{wp_prefix}}intersoccer_rosters r
  ON r.order_item_id = m.order_item_id
WHERE r.order_item_id IS NULL
GROUP BY m.order_id, m.order_item_id;

-- duplicate safety check (must be zero)
SELECT order_item_id, COUNT(*) AS c
FROM {{wp_prefix}}intersoccer_rosters
GROUP BY order_item_id
HAVING COUNT(*) > 1;

COMMIT;
```

## Phase 2: Resolve `missing_in_woo` (orphans)

### 2.1 Quarantine first (required)
```sql
START TRANSACTION;

INSERT INTO {{wp_prefix}}rr_cleanup_orphan_quarantine
SELECT r.*, NOW() AS quarantined_at, '{{run_suffix}}' AS run_id
FROM {{wp_prefix}}rr_missing_in_woo_{{run_suffix}} r;

COMMIT;
```

### 2.2 Delete only quarantined run rows
```sql
START TRANSACTION;

DELETE r
FROM {{wp_prefix}}intersoccer_rosters r
JOIN {{wp_prefix}}rr_missing_in_woo_{{run_suffix}} o
  ON o.order_item_id = r.order_item_id;

COMMIT;
```

### 2.3 Rollback path for Phase 2 only
```sql
START TRANSACTION;

INSERT IGNORE INTO {{wp_prefix}}intersoccer_rosters
SELECT
  q.id, q.order_id, q.order_item_id, q.variation_id, q.player_name, q.first_name, q.last_name, q.age, q.gender,
  q.booking_type, q.selected_days, q.camp_terms, q.venue, q.parent_phone, q.parent_email, q.medical_conditions,
  q.late_pickup, q.late_pickup_days, q.day_presence, q.age_group, q.start_date, q.end_date, q.event_dates, q.product_name,
  q.activity_type, q.shirt_size, q.shorts_size, q.registration_timestamp, q.course_day, q.updated_at, q.product_id,
  q.player_first_name, q.player_last_name, q.player_dob, q.player_gender, q.player_medical, q.player_dietary,
  q.parent_first_name, q.parent_last_name, q.emergency_contact, q.term, q.times, q.days_selected, q.season,
  q.canton_region, q.city, q.avs_number, q.created_at, q.base_price, q.discount_amount, q.final_price,
  q.reimbursement, q.discount_codes, q.girls_only, q.event_signature, q.is_placeholder, q.event_completed
FROM {{wp_prefix}}rr_cleanup_orphan_quarantine q
WHERE q.run_id = '{{run_suffix}}';

COMMIT;
```

## Phase 3: Resolve `missing_in_woo_meta` + `course_day_mismatch`

### 3.1 Backfill Woo course-day from roster value for deterministic candidate set
```sql
START TRANSACTION;

-- canonical key for current diagnostics query
INSERT INTO {{wp_prefix}}woocommerce_order_itemmeta (order_item_id, meta_key, meta_value)
SELECT m.order_item_id, 'pa_course-day', m.roster_course_day
FROM {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}} m
LEFT JOIN {{wp_prefix}}woocommerce_order_itemmeta oim
  ON oim.order_item_id = m.order_item_id
 AND oim.meta_key = 'pa_course-day'
WHERE oim.meta_id IS NULL;

-- keep attribute mirror in sync when absent
INSERT INTO {{wp_prefix}}woocommerce_order_itemmeta (order_item_id, meta_key, meta_value)
SELECT m.order_item_id, 'attribute_pa_course-day', m.roster_course_day
FROM {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}} m
LEFT JOIN {{wp_prefix}}woocommerce_order_itemmeta oim
  ON oim.order_item_id = m.order_item_id
 AND oim.meta_key = 'attribute_pa_course-day'
WHERE oim.meta_id IS NULL;

COMMIT;
```

### 3.2 Optional normalization update (if mismatches persist)
If slugs vs labels still mismatch on day values, normalize both keys to the same canonical source format (slug or label) consistently before re-running diagnostics.

## Verification Checklist (after each phase)

Run diagnostics with identical filters and capture:
- `only_woo`
- `only_rosters`
- `missing_in_woo_meta`
- `course_day_mismatch`
- total `mismatch_rows`

SQL checks:
```sql
-- Must stay unique
SELECT COUNT(*) AS total_rows, COUNT(DISTINCT order_item_id) AS distinct_order_items
FROM {{wp_prefix}}intersoccer_rosters;

-- unresolved bucket A
SELECT COUNT(*) AS unresolved_missing_in_rosters
FROM {{wp_prefix}}woocommerce_order_items oi
LEFT JOIN {{wp_prefix}}intersoccer_rosters r
  ON r.order_item_id = oi.order_item_id
WHERE oi.order_item_type = 'line_item'
  AND r.order_item_id IS NULL;

-- unresolved bucket B
SELECT COUNT(*) AS unresolved_missing_in_woo
FROM {{wp_prefix}}intersoccer_rosters r
LEFT JOIN {{wp_prefix}}woocommerce_order_items oi
  ON oi.order_item_id = r.order_item_id
WHERE oi.order_item_id IS NULL;
```

Acceptance targets:
- `missing_in_rosters` -> `0`
- `missing_in_woo` -> `0` (or fully quarantined and documented)
- `missing_in_woo_meta` and `course_day_mismatch` -> near-zero with explicit exceptions

## Practical Next Actions (Ops Sequence)
1. Capture baseline diagnostics and store with `run_suffix`.
2. Execute Phase 0 materialization and compare counts.
3. Run Phase 1 preferred reconcile path; fallback SQL only if needed.
4. Quarantine and clean Phase 2 orphans.
5. Apply Phase 3 meta backfill.
6. Re-run diagnostics and store after-counts.
7. Archive run artifacts (`run_suffix`, candidate counts, before/after screenshots, SQL transcript).
7. Archive run artifacts (`run_suffix`, candidate counts, before/after screenshots, SQL transcript).

## Script Bundle
Use these checked-in scripts directly:
- `docs/sql/cleanup-phase1-missing-in-rosters.sql`
- `docs/sql/cleanup-phase2-missing-in-woo.sql`
- `docs/sql/cleanup-phase3-course-day-meta-backfill.sql`
- `docs/sql/cleanup-verification-checklist.sql`

## Notes
- This playbook intentionally uses deterministic candidate tables to prevent drifting target sets during long operations.
- All destructive operations are preceded by quarantine/backups.
- Keep the same diagnostics filter set through the full run to maintain comparability.
