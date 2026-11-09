<?php
/**
 * Test stand-in for intersoccer_roster_admin_log_insert() (includes/roster-admin-log.php is not
 * loaded in tests).
 *
 * A real function instead of a Brain Monkey alias: once Patchwork's stream wrapper is removed after
 * bootstrap, Functions\when()->alias() on a function that does not exist yet defines it but never
 * attaches the alias, so the first call throws MissingFunctionExpectations. Tests set
 * $GLOBALS['intersoccer_test_admin_log_insert_callback']; TestCase::tearDown() clears it.
 */

if (!function_exists('intersoccer_roster_admin_log_insert')) {
    function intersoccer_roster_admin_log_insert(string $action, string $event_signature = '', array $payload = []): bool {
        $callback = $GLOBALS['intersoccer_test_admin_log_insert_callback'] ?? null;
        if (is_callable($callback)) {
            return (bool) $callback($action, $event_signature, $payload);
        }

        return true;
    }
}
