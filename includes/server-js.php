<?php
/**
 * InterSoccer Serve JavaScript
 * Description: Serves JavaScript files independently of WordPress admin context.
 * Author: Jeremy Lee
 */

// Ensure direct access is restricted
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../../../');
}

// Minimal WordPress bootstrap to access constants
require_once ABSPATH . 'wp-load.php';

// Serve reports-rosters.js
if (isset($_GET['file']) && $_GET['file'] === 'reports-rosters.js') {
    $js_file = dirname(__FILE__) . '/reports-rosters.js';
    if (file_exists($js_file)) {
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('X-InterSoccer-Debug: Serve-JS-Endpoint');
        readfile($js_file);
        exit;
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        status_header(404);
        echo 'JavaScript file not found';
        exit;
    }
}

// If no valid file is requested, return 404
header('Content-Type: text/plain; charset=UTF-8');
status_header(404);
echo 'Invalid request';
exit;
?>
