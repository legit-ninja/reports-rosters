<?php
/**
 * Legacy PCS fixture shim — loads plugin win-back engine when contact priming is available.
 */

$engine = dirname(__DIR__, 2) . '/includes/winback-report-engine.php';
if (file_exists($engine)) {
	require_once $engine;
}
