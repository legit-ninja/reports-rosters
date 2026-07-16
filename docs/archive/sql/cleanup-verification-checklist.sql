-- Verification queries for before/after and recurring monthly checks
-- Replace {{wp_prefix}}

-- 1) Uniqueness integrity
SELECT
  COUNT(*) AS roster_rows,
  COUNT(DISTINCT order_item_id) AS distinct_order_items
FROM {{wp_prefix}}intersoccer_rosters;

-- 2) Missing in rosters (Only Woo)
SELECT COUNT(*) AS missing_in_rosters
FROM {{wp_prefix}}woocommerce_order_items oi
LEFT JOIN {{wp_prefix}}intersoccer_rosters r
  ON r.order_item_id = oi.order_item_id
WHERE oi.order_item_type = 'line_item'
  AND r.order_item_id IS NULL;

-- 3) Missing in Woo (Only Rosters)
SELECT COUNT(*) AS missing_in_woo
FROM {{wp_prefix}}intersoccer_rosters r
LEFT JOIN {{wp_prefix}}woocommerce_order_items oi
  ON oi.order_item_id = r.order_item_id
WHERE oi.order_item_id IS NULL;

-- 4) Missing Woo course-day metadata
SELECT COUNT(*) AS missing_in_woo_meta
FROM {{wp_prefix}}intersoccer_rosters r
JOIN {{wp_prefix}}woocommerce_order_items oi
  ON oi.order_item_id = r.order_item_id
LEFT JOIN {{wp_prefix}}woocommerce_order_itemmeta oim
  ON oim.order_item_id = oi.order_item_id
 AND oim.meta_key IN ('pa_course-day', 'attribute_pa_course-day', 'pa_course_day', 'attribute_pa_course_day')
WHERE oi.order_item_type = 'line_item'
GROUP BY r.order_item_id
HAVING SUM(
  CASE
    WHEN oim.meta_id IS NOT NULL AND COALESCE(TRIM(oim.meta_value), '') <> '' THEN 1
    ELSE 0
  END
) = 0;

-- 5) Quarantine ledger (operational)
SELECT
  run_id,
  COUNT(*) AS quarantined_rows,
  MIN(quarantined_at) AS first_quarantined,
  MAX(quarantined_at) AS last_quarantined
FROM {{wp_prefix}}rr_cleanup_orphan_quarantine
GROUP BY run_id
ORDER BY last_quarantined DESC;
