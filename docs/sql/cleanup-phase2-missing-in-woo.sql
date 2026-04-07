-- Phase 2: Resolve missing_in_woo (Only Rosters / orphan rows)
-- Usage:
--   1) Replace {{wp_prefix}}
--   2) Replace {{run_suffix}}
--   3) Ensure candidate table exists: {{wp_prefix}}rr_missing_in_woo_{{run_suffix}}

-- PRECHECK
SELECT COUNT(*) AS orphan_candidates
FROM {{wp_prefix}}rr_missing_in_woo_{{run_suffix}};

-- Quarantine table (idempotent creation)
CREATE TABLE IF NOT EXISTS {{wp_prefix}}rr_cleanup_orphan_quarantine
LIKE {{wp_prefix}}intersoccer_rosters;

ALTER TABLE {{wp_prefix}}rr_cleanup_orphan_quarantine
  ADD COLUMN IF NOT EXISTS quarantined_at datetime NULL,
  ADD COLUMN IF NOT EXISTS run_id varchar(64) NULL;

START TRANSACTION;

-- Quarantine before delete
INSERT INTO {{wp_prefix}}rr_cleanup_orphan_quarantine
SELECT o.*, NOW() AS quarantined_at, '{{run_suffix}}' AS run_id
FROM {{wp_prefix}}rr_missing_in_woo_{{run_suffix}} o;

-- ASSERTION: quarantine count for run should match candidates
SELECT COUNT(*) AS quarantined_for_run
FROM {{wp_prefix}}rr_cleanup_orphan_quarantine
WHERE run_id = '{{run_suffix}}';

COMMIT;

START TRANSACTION;

-- Deterministic delete of exactly the candidate set
DELETE r
FROM {{wp_prefix}}intersoccer_rosters r
JOIN {{wp_prefix}}rr_missing_in_woo_{{run_suffix}} o
  ON o.order_item_id = r.order_item_id;

COMMIT;

-- POSTCHECK: unresolved orphans should be zero for candidate set
SELECT COUNT(*) AS candidate_orphans_remaining
FROM {{wp_prefix}}rr_missing_in_woo_{{run_suffix}} o
JOIN {{wp_prefix}}intersoccer_rosters r
  ON r.order_item_id = o.order_item_id;

-- ROLLBACK TEMPLATE (run only if needed)
-- START TRANSACTION;
-- INSERT IGNORE INTO {{wp_prefix}}intersoccer_rosters
-- SELECT
--   q.id, q.order_id, q.order_item_id, q.variation_id, q.player_name, q.first_name, q.last_name, q.age, q.gender,
--   q.booking_type, q.selected_days, q.camp_terms, q.venue, q.parent_phone, q.parent_email, q.medical_conditions,
--   q.late_pickup, q.late_pickup_days, q.day_presence, q.age_group, q.start_date, q.end_date, q.event_dates, q.product_name,
--   q.activity_type, q.shirt_size, q.shorts_size, q.registration_timestamp, q.course_day, q.updated_at, q.product_id,
--   q.player_first_name, q.player_last_name, q.player_dob, q.player_gender, q.player_medical, q.player_dietary,
--   q.parent_first_name, q.parent_last_name, q.emergency_contact, q.term, q.times, q.days_selected, q.season,
--   q.canton_region, q.city, q.avs_number, q.created_at, q.base_price, q.discount_amount, q.final_price,
--   q.reimbursement, q.discount_codes, q.girls_only, q.event_signature, q.is_placeholder, q.event_completed
-- FROM {{wp_prefix}}rr_cleanup_orphan_quarantine q
-- WHERE q.run_id = '{{run_suffix}}';
-- COMMIT;
