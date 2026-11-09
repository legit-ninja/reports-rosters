<?php
/**
 * Minimal WordPress hook stubs for child-process pre-filter gate probes (no INTERSOCCER_TESTING).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

if (!isset($GLOBALS['intersoccer_pre_filter_gate_wp_filters'])) {
    $GLOBALS['intersoccer_pre_filter_gate_wp_filters'] = [];
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['intersoccer_pre_filter_gate_wp_filters'][$hook][(int) $priority][] = [
            'callback' => $callback,
            'accepted_args' => (int) $accepted_args,
        ];
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        if (empty($GLOBALS['intersoccer_pre_filter_gate_wp_filters'][$hook])) {
            return $value;
        }
        $by_priority = $GLOBALS['intersoccer_pre_filter_gate_wp_filters'][$hook];
        ksort($by_priority);
        foreach ($by_priority as $callbacks) {
            foreach ($callbacks as $entry) {
                $callback = $entry['callback'];
                $accepted = max(1, (int) $entry['accepted_args']);
                if ($accepted <= 1) {
                    $value = call_user_func($callback, $value);
                } else {
                    $call_args = array_merge([$value], array_slice($args, 0, $accepted - 1));
                    $value = call_user_func_array($callback, $call_args);
                }
            }
        }
        return $value;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}
