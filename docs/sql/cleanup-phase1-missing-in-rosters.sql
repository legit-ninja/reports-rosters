-- Phase 1: Resolve missing_in_rosters (Only Woo)
-- Usage:
--   1) Replace {{wp_prefix}} (example: wp_)
--   2) Replace {{run_suffix}} with a stable suffix (example: 20260406_1810)
--   3) Run on staging first

-- Candidate set must already exist from playbook phase 0:
--   {{wp_prefix}}rr_missing_in_rosters_{{run_suffix}}

-- PRECHECK: current unresolved count
SELECT COUNT(*) AS unresolved_missing_in_rosters_before
FROM {{wp_prefix}}woocommerce_order_items oi
LEFT JOIN {{wp_prefix}}intersoccer_rosters r
  ON r.order_item_id = oi.order_item_id
WHERE oi.order_item_type = 'line_item'
  AND r.order_item_id IS NULL;

START TRANSACTION;

-- Deterministic insert for unresolved candidates only.
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

-- ASSERTION 1: no duplicates allowed (must return zero rows)
SELECT order_item_id, COUNT(*) AS duplicate_count
FROM {{wp_prefix}}intersoccer_rosters
GROUP BY order_item_id
HAVING COUNT(*) > 1;

-- ASSERTION 2: candidate table should now be fully represented in rosters
SELECT COUNT(*) AS still_missing_from_candidates
FROM {{wp_prefix}}rr_missing_in_rosters_{{run_suffix}} m
LEFT JOIN {{wp_prefix}}intersoccer_rosters r
  ON r.order_item_id = m.order_item_id
WHERE r.order_item_id IS NULL;

COMMIT;

-- POSTCHECK: unresolved count should decrease
SELECT COUNT(*) AS unresolved_missing_in_rosters_after
FROM {{wp_prefix}}woocommerce_order_items oi
LEFT JOIN {{wp_prefix}}intersoccer_rosters r
  ON r.order_item_id = oi.order_item_id
WHERE oi.order_item_type = 'line_item'
  AND r.order_item_id IS NULL;
