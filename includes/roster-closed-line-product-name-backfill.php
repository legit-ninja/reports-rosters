<?php
/**
 * One-off backfill: closed roster rows product_name from order line (English orders only).
 *
 * @package InterSoccer_Reports_Rosters
 */

defined('ABSPATH') or die('Restricted access');

if (!class_exists('Intersoccer_Roster_Closed_Line_Product_Name_Backfill_Service')) {
    /**
     * Injectable DB accessors for PHPUnit (no live DB required in unit tests).
     */
    final class Intersoccer_Roster_Closed_Line_Product_Name_Backfill_Service {
        /** @var callable */
        private $fetch_rows;

        /** @var callable */
        private $fetch_line_context;

        /** @var callable|null */
        private $persist_name;

        /**
         * @param callable $fetch_rows (int $id_cursor, int $chunk): array<int,array<string,mixed>>
         * @param callable $fetch_line_context (int $order_item_id): ?array{order_item_name:string,order_wpml_language:string}
         * @param callable|null $persist_name (int $roster_id, string $product_name): void
         */
        public function __construct(callable $fetch_rows, callable $fetch_line_context, callable $persist_name = null) {
            $this->fetch_rows = $fetch_rows;
            $this->fetch_line_context = $fetch_line_context;
            $this->persist_name = $persist_name;
        }

        /**
         * @param array<string,mixed> $options id_cursor, chunk, apply
         * @return array<string,int>
         */
        public function run(array $options = []) {
            $id_cursor = isset($options['id_cursor']) ? (int) $options['id_cursor'] : 0;
            $chunk = isset($options['chunk']) ? max(1, (int) $options['chunk']) : 500;
            $apply = !empty($options['apply']);

            $summary = [
                'rows_scanned' => 0,
                'would_change' => 0,
                'changed' => 0,
                'skipped_non_english' => 0,
                'skipped_no_line_name' => 0,
            ];

            while (true) {
                $rows = ($this->fetch_rows)($id_cursor, $chunk);
                if (!is_array($rows) || $rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $roster_id = (int) ($row['id'] ?? 0);
                    if ($roster_id > $id_cursor) {
                        $id_cursor = $roster_id;
                    }
                    if ((int) ($row['event_completed'] ?? 0) !== 1) {
                        continue;
                    }
                    $summary['rows_scanned']++;
                    $order_item_id = (int) ($row['order_item_id'] ?? 0);
                    if ($order_item_id <= 0) {
                        $summary['skipped_no_line_name']++;
                        continue;
                    }
                    $context = ($this->fetch_line_context)($order_item_id);
                    if (!is_array($context)) {
                        $summary['skipped_no_line_name']++;
                        continue;
                    }
                    $line_name = trim((string) ($context['order_item_name'] ?? ''));
                    $order_lang = trim((string) ($context['order_wpml_language'] ?? ''));
                    if ($line_name === '') {
                        $summary['skipped_no_line_name']++;
                        continue;
                    }
                    if (!function_exists('intersoccer_roster_order_language_is_english_or_unknown')
                        || !intersoccer_roster_order_language_is_english_or_unknown($order_lang)) {
                        $summary['skipped_non_english']++;
                        continue;
                    }
                    $target = function_exists('intersoccer_roster_resolve_product_name_for_storage')
                        ? intersoccer_roster_resolve_product_name_for_storage([
                            'order_line_name' => $line_name,
                            'order_wpml_language' => $order_lang,
                            'product_id' => (int) ($row['product_id'] ?? 0),
                            'variation_id' => (int) ($row['variation_id'] ?? 0),
                            'catalog_product_name' => (string) ($row['product_name'] ?? ''),
                        ])
                        : ['product_name' => $line_name];
                    $new_name = trim((string) ($target['product_name'] ?? ''));
                    if ($new_name === '') {
                        $summary['skipped_no_line_name']++;
                        continue;
                    }
                    $current = trim((string) ($row['product_name'] ?? ''));
                    if ($current === $new_name) {
                        continue;
                    }
                    $summary['would_change']++;
                    if ($apply && $this->persist_name !== null) {
                        ($this->persist_name)($roster_id, $new_name);
                        $summary['changed']++;
                    }
                }
                if (count($rows) < $chunk) {
                    break;
                }
            }

            return $summary;
        }

        /**
         * Production wiring (wpdb).
         */
        public static function from_wpdb(): self {
            global $wpdb;
            $table = $wpdb->prefix . 'intersoccer_rosters';
            $items = $wpdb->prefix . 'woocommerce_order_items';
            $orders = $wpdb->prefix . 'posts';

            $fetch_rows = static function (int $id_cursor, int $chunk) use ($wpdb, $table) {
                return $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, order_item_id, order_id, product_id, variation_id, product_name, event_completed
                         FROM {$table}
                         WHERE event_completed = 1 AND id > %d
                         ORDER BY id ASC
                         LIMIT %d",
                        $id_cursor,
                        $chunk
                    ),
                    ARRAY_A
                ) ?: [];
            };

            $fetch_line = static function (int $order_item_id) use ($wpdb, $items, $orders) {
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT oi.order_item_name, pm.meta_value AS order_wpml_language
                         FROM {$items} oi
                         INNER JOIN {$orders} p ON p.ID = oi.order_id AND p.post_type = 'shop_order'
                         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = oi.order_id AND pm.meta_key = 'wpml_language'
                         WHERE oi.order_item_id = %d
                         LIMIT 1",
                        $order_item_id
                    ),
                    ARRAY_A
                );
                if (!$row) {
                    return null;
                }
                return [
                    'order_item_name' => (string) ($row['order_item_name'] ?? ''),
                    'order_wpml_language' => (string) ($row['order_wpml_language'] ?? ''),
                ];
            };

            $persist = static function (int $roster_id, string $product_name) use ($wpdb, $table) {
                $wpdb->update(
                    $table,
                    ['product_name' => substr($product_name, 0, 255)],
                    ['id' => $roster_id],
                    ['%s'],
                    ['%d']
                );
            };

            return new self($fetch_rows, $fetch_line, $persist);
        }
    }
}
