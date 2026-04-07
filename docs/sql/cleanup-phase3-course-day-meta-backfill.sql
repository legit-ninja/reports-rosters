-- Phase 3: Resolve missing_in_woo_meta + reduce course_day_mismatch
-- Usage:
--   1) Replace {{wp_prefix}}
--   2) Replace {{run_suffix}}
--   3) Ensure candidate table exists: {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}}

-- PRECHECK
SELECT COUNT(*) AS candidate_meta_backfill_count
FROM {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}};

START TRANSACTION;

-- Canonical key used by diagnostics
INSERT INTO {{wp_prefix}}woocommerce_order_itemmeta (order_item_id, meta_key, meta_value)
SELECT m.order_item_id, 'pa_course-day', m.roster_course_day
FROM {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}} m
LEFT JOIN {{wp_prefix}}woocommerce_order_itemmeta oim
  ON oim.order_item_id = m.order_item_id
 AND oim.meta_key = 'pa_course-day'
WHERE oim.meta_id IS NULL;

-- Mirror key for compatibility with variation attribute readers
INSERT INTO {{wp_prefix}}woocommerce_order_itemmeta (order_item_id, meta_key, meta_value)
SELECT m.order_item_id, 'attribute_pa_course-day', m.roster_course_day
FROM {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}} m
LEFT JOIN {{wp_prefix}}woocommerce_order_itemmeta oim
  ON oim.order_item_id = m.order_item_id
 AND oim.meta_key = 'attribute_pa_course-day'
WHERE oim.meta_id IS NULL;

COMMIT;

-- ASSERTION: each candidate now has at least one canonical key entry
SELECT COUNT(*) AS candidates_without_pa_course_day
FROM {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}} m
LEFT JOIN {{wp_prefix}}woocommerce_order_itemmeta oim
  ON oim.order_item_id = m.order_item_id
 AND oim.meta_key = 'pa_course-day'
WHERE oim.meta_id IS NULL;

-- Optional normalization example:
-- UPDATE {{wp_prefix}}woocommerce_order_itemmeta
-- SET meta_value = LOWER(TRIM(meta_value))
-- WHERE meta_key IN ('pa_course-day', 'attribute_pa_course-day')
--   AND order_item_id IN (SELECT order_item_id FROM {{wp_prefix}}rr_missing_woo_meta_{{run_suffix}});
