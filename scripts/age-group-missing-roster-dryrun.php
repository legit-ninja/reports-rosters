<?php
/**
 * Read-only count of paid order line items whose variation age group cannot be parsed by the
 * pre-fix Player parser (release 2.10.15 / master y-only dynamic ranges) and have no roster row.
 *
 * Usage (WP-CLI eval-file only; do not run against shared production DB from agents):
 *   wp eval-file scripts/age-group-missing-roster-dryrun.php
 */

if (!function_exists('intersoccer_agegroup_dryrun_sql_is_read_only')) {
    /**
     * @return bool
     */
    function intersoccer_agegroup_dryrun_sql_is_read_only(string $query) {
        $trimmed = ltrim($query);
        if ($trimmed === '') {
            return true;
        }
        return (bool) preg_match(
            '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\s/i',
            $trimmed
        );
    }
}

if (!function_exists('intersoccer_agegroup_dryrun_reset_gate_state')) {
    /**
     * @return array{sql_error: string, blocked: array<int, array{type: string, table: string, query: string}>}
     */
    function intersoccer_agegroup_dryrun_reset_gate_state() {
        $GLOBALS['intersoccer_agegroup_dryrun_gate'] = [
            'sql_error' => '',
            'blocked' => [],
        ];
        return $GLOBALS['intersoccer_agegroup_dryrun_gate'];
    }
}

if (!function_exists('intersoccer_agegroup_dryrun_gate_state')) {
    /**
     * @return array{sql_error: string, blocked: array<int, array{type: string, table: string, query: string}>}
     */
    function &intersoccer_agegroup_dryrun_gate_state() {
        if (!isset($GLOBALS['intersoccer_agegroup_dryrun_gate'])) {
            intersoccer_agegroup_dryrun_reset_gate_state();
        }
        return $GLOBALS['intersoccer_agegroup_dryrun_gate'];
    }
}

if (!function_exists('intersoccer_agegroup_dryrun_record_sql_error')) {
    function intersoccer_agegroup_dryrun_record_sql_error($wpdb) {
        if (!empty($wpdb->last_error)) {
            $state = &intersoccer_agegroup_dryrun_gate_state();
            $state['sql_error'] = (string) $wpdb->last_error;
        }
    }
}

if (!function_exists('intersoccer_agegroup_dryrun_classify_blocked_query')) {
    /**
     * @return array{type: string, table: string}
     */
    function intersoccer_agegroup_dryrun_classify_blocked_query(string $query) {
        $trimmed = ltrim($query);
        $type = 'OTHER';
        if (preg_match('/^(\w+)/', $trimmed, $m)) {
            $type = strtoupper($m[1]);
        }
        $table = '';
        if (preg_match(
            '/\b(?:INTO|UPDATE|TABLE|JOIN)\s+`?([a-zA-Z0-9_]+)`?/i',
            $trimmed,
            $m
        )) {
            $table = $m[1];
        }
        return ['type' => $type, 'table' => $table];
    }
}

if (!function_exists('intersoccer_agegroup_dryrun_filter_query')) {
    function intersoccer_agegroup_dryrun_filter_query($query) {
        if (intersoccer_agegroup_dryrun_sql_is_read_only((string) $query)) {
            return $query;
        }
        $state = &intersoccer_agegroup_dryrun_gate_state();
        $class = intersoccer_agegroup_dryrun_classify_blocked_query((string) $query);
        $state['blocked'][] = [
            'type' => $class['type'],
            'table' => $class['table'],
            'query' => substr(ltrim((string) $query), 0, 200),
        ];
        return 'SELECT 1';
    }
}

if (!function_exists('intersoccer_agegroup_dryrun_register_readonly_guards')) {
    function intersoccer_agegroup_dryrun_register_readonly_guards() {
        if (function_exists('wp_using_ext_object_cache')) {
            wp_using_ext_object_cache(true);
        }
        add_filter('query', 'intersoccer_agegroup_dryrun_filter_query', 0);
    }
}

if (!function_exists('intersoccer_agegroup_dryrun_disable_cron_for_request')) {
    function intersoccer_agegroup_dryrun_disable_cron_for_request() {
        remove_action('shutdown', 'wp_cron');
        remove_action('shutdown', '_wp_cron', 1);
        add_filter('pre_option_cron', static function () {
            return [];
        }, 999);
        add_filter('cron_request', static function () {
            return false;
        }, 999);
    }
}

if (!function_exists('intersoccer_agegroup_dryrun_evaluate_result')) {
    /**
     * @param array{sql_error: string, blocked: array} $state
     * @return array{status: string, reason: string}
     */
    function intersoccer_agegroup_dryrun_evaluate_result(array $state) {
        if ($state['sql_error'] !== '') {
            return [
                'status' => 'INVALID',
                'reason' => 'SQL error: ' . $state['sql_error'],
            ];
        }
        if (!empty($state['blocked'])) {
            $count = count($state['blocked']);
            $first = $state['blocked'][0];
            $detail = $first['type'];
            if ($first['table'] !== '') {
                $detail .= ' on ' . $first['table'];
            }
            return [
                'status' => 'INVALID',
                'reason' => "blocked non-read-only query ({$count}): {$detail}",
            ];
        }
        return [
            'status' => 'OK',
            'reason' => '',
        ];
    }
}

if (!function_exists('intersoccer_agegroup_dryrun_run')) {
    function intersoccer_agegroup_dryrun_run() {
        if (!defined('ABSPATH')) {
            echo "This script must run under wp eval-file.\n";
            return;
        }

        global $wpdb;

        intersoccer_agegroup_dryrun_reset_gate_state();
        intersoccer_agegroup_dryrun_disable_cron_for_request();

        $roster_table = $wpdb->prefix . 'intersoccer_rosters';
        $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $order_items = $wpdb->prefix . 'woocommerce_order_items';
        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $terms = $wpdb->terms;
        $term_taxonomy = $wpdb->term_taxonomy;

        $ro_readonly = $wpdb->get_var('SELECT @@transaction_read_only');
        intersoccer_agegroup_dryrun_record_sql_error($wpdb);
        if (intersoccer_agegroup_dryrun_gate_state()['sql_error'] !== '') {
            goto finish;
        }
        if ($ro_readonly === null) {
            $state = &intersoccer_agegroup_dryrun_gate_state();
            $state['sql_error'] = 'could not read @@transaction_read_only';
            goto finish;
        }

        $wpdb->query('SET SESSION TRANSACTION READ ONLY');
        intersoccer_agegroup_dryrun_record_sql_error($wpdb);
        if (intersoccer_agegroup_dryrun_gate_state()['sql_error'] !== '') {
            goto finish;
        }

        $ro_after = $wpdb->get_var('SELECT @@transaction_read_only');
        intersoccer_agegroup_dryrun_record_sql_error($wpdb);
        if (intersoccer_agegroup_dryrun_gate_state()['sql_error'] !== '') {
            goto finish;
        }
        if ((string) $ro_after !== '1') {
            $state = &intersoccer_agegroup_dryrun_gate_state();
            $state['sql_error'] = 'SET SESSION TRANSACTION READ ONLY did not take effect (got ' . var_export($ro_after, true) . ')';
            goto finish;
        }

        intersoccer_agegroup_dryrun_register_readonly_guards();

        $predefined = \InterSoccer\ReportsRosters\Utils\ValidationHelper::flattenPredefinedAgeGroupCatalog(
            \InterSoccer\ReportsRosters\Data\Models\Player::AGE_GROUPS
        );

        $fails_master_parser = static function (string $label) use ($predefined): bool {
            $label = trim($label);
            if ($label === '') {
                return false;
            }
            return \InterSoccer\ReportsRosters\Utils\ValidationHelper::parseAgeGroupMinMaxAsOnRelease21015($label, $predefined) === null;
        };

        $paid_statuses = "'wc-completed','wc-processing','wc-on-hold'";

        $wc_orders_table = $wpdb->prefix . 'wc_orders';
        $orders_table_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wc_orders_table));
        intersoccer_agegroup_dryrun_record_sql_error($wpdb);
        if (intersoccer_agegroup_dryrun_gate_state()['sql_error'] !== '') {
            goto finish;
        }

        $hpos_enabled = $orders_table_exists
            && get_option('woocommerce_custom_orders_table_enabled') === 'yes';

        if ($hpos_enabled) {
            $order_storage = 'hpos';
            $order_join = "INNER JOIN {$wc_orders_table} o ON o.id = oi.order_id
                AND o.type = 'shop_order'
                AND o.status IN ({$paid_statuses})";
        } else {
            $order_storage = 'posts';
            $order_join = "INNER JOIN {$posts} o ON o.ID = oi.order_id
                AND o.post_type = 'shop_order'
                AND o.post_status IN ({$paid_statuses})";
        }

        echo 'Order storage: ' . $order_storage . "\n";

        $sql = "
            SELECT
                oi.order_item_id,
                CAST(variation.meta_value AS UNSIGNED) AS variation_id,
                attr.meta_value AS age_slug,
                COALESCE(t.name, attr.meta_value) AS age_group_label,
                COALESCE(
                    NULLIF(activity.meta_value, ''),
                    NULLIF(product_activity.meta_value, '')
                ) AS activity_type
            FROM {$order_items} oi
            {$order_join}
            INNER JOIN {$itemmeta} variation
                ON variation.order_item_id = oi.order_item_id
                AND variation.meta_key = '_variation_id'
                AND variation.meta_value > 0
            INNER JOIN {$postmeta} attr
                ON attr.post_id = variation.meta_value
                AND attr.meta_key = 'attribute_pa_age-group'
                AND attr.meta_value <> ''
            LEFT JOIN {$terms} t
                ON t.slug = attr.meta_value
            LEFT JOIN {$term_taxonomy} tt
                ON tt.term_id = t.term_id
                AND tt.taxonomy = 'pa_age-group'
            LEFT JOIN {$itemmeta} activity
                ON activity.order_item_id = oi.order_item_id
                AND activity.meta_key = 'Activity Type'
            LEFT JOIN {$itemmeta} product_ref
                ON product_ref.order_item_id = oi.order_item_id
                AND product_ref.meta_key = '_product_id'
            LEFT JOIN {$postmeta} product_activity
                ON product_activity.post_id = product_ref.meta_value
                AND product_activity.meta_key = '_intersoccer_activity_type'
            LEFT JOIN {$roster_table} r ON r.order_item_id = oi.order_item_id
            WHERE oi.order_item_type = 'line_item'
              AND r.id IS NULL
        ";

        $rows = $wpdb->get_results($sql, ARRAY_A);
        intersoccer_agegroup_dryrun_record_sql_error($wpdb);
        if ($rows === null && intersoccer_agegroup_dryrun_gate_state()['sql_error'] === '') {
            $state = &intersoccer_agegroup_dryrun_gate_state();
            $state['sql_error'] = 'main dry-run query returned null';
            goto finish;
        }

        if (intersoccer_agegroup_dryrun_gate_state()['sql_error'] === '' && is_array($rows)) {
            $by_age_group = [];
            $by_activity = [];
            $total_range_form = 0;

            $by_grade_age_group = [];
            $by_grade_activity = [];
            $total_grade = 0;

            foreach ($rows as $row) {
                $label = (string) ($row['age_group_label'] ?? '');
                if (!$fails_master_parser($label)) {
                    continue;
                }
                $activity = (string) ($row['activity_type'] ?? '(unknown)');
                if (\InterSoccer\ReportsRosters\Utils\ValidationHelper::isGradeAgeGroup($label)) {
                    ++$total_grade;
                    $by_grade_age_group[$label] = ($by_grade_age_group[$label] ?? 0) + 1;
                    if (!isset($by_grade_activity[$activity])) {
                        $by_grade_activity[$activity] = [];
                    }
                    $by_grade_activity[$activity][$label] = ($by_grade_activity[$activity][$label] ?? 0) + 1;
                    continue;
                }
                ++$total_range_form;
                $by_age_group[$label] = ($by_age_group[$label] ?? 0) + 1;
                if (!isset($by_activity[$activity])) {
                    $by_activity[$activity] = [];
                }
                $by_activity[$activity][$label] = ($by_activity[$activity][$label] ?? 0) + 1;
            }

            ksort($by_age_group);
            ksort($by_activity);
            ksort($by_grade_age_group);
            ksort($by_grade_activity);

            echo "== Paid order items: age group fails master parser, no roster row (range / non-grade) ==\n";
            echo 'Total: ' . $total_range_form . "\n\n";

            echo "By age-group string:\n";
            if ($by_age_group === []) {
                echo "  (none)\n";
            } else {
                foreach ($by_age_group as $label => $count) {
                    echo '  ' . $count . ' × ' . $label . "\n";
                }
            }

            echo "\nBy activity type (then age group):\n";
            if ($by_activity === []) {
                echo "  (none)\n";
            } else {
                foreach ($by_activity as $activity => $groups) {
                    echo '  ' . $activity . ":\n";
                    ksort($groups);
                    foreach ($groups as $label => $count) {
                        echo '    ' . $count . ' × ' . $label . "\n";
                    }
                }
            }

            echo "\n== grade terms (age check now skipped), no roster row ==\n";
            echo 'Total: ' . $total_grade . "\n\n";

            echo "By age-group string:\n";
            if ($by_grade_age_group === []) {
                echo "  (none)\n";
            } else {
                foreach ($by_grade_age_group as $label => $count) {
                    echo '  ' . $count . ' × ' . $label . "\n";
                }
            }

            echo "\nBy activity type (then age group):\n";
            if ($by_grade_activity === []) {
                echo "  (none)\n";
            } else {
                foreach ($by_grade_activity as $activity => $groups) {
                    echo '  ' . $activity . ":\n";
                    ksort($groups);
                    foreach ($groups as $label => $count) {
                        echo '    ' . $count . ' × ' . $label . "\n";
                    }
                }
            }

            echo "\n(read-only session; no roster rows created)\n";
        }

        finish:
        $evaluation = intersoccer_agegroup_dryrun_evaluate_result(intersoccer_agegroup_dryrun_gate_state());
        echo "\n== dry-run result summary\n";
        echo 'RESULT: ' . $evaluation['status'] . "\n";
        if ($evaluation['reason'] !== '') {
            echo 'REASON: ' . $evaluation['reason'] . "\n";
        }
    }
}

if (defined('ABSPATH') && !defined('INTERSOCCER_AGEGROUP_DRYRUN_TEST_LOAD')) {
    intersoccer_agegroup_dryrun_run();
}
