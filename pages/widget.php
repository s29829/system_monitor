<?php
// System Monitor - values of the side panel and the header bar (since 1.01).
// Only for users the options page allows; only the metrics of their panel / header set.
// The values are shared by all users and computed at most every few seconds (include/widgets.php).
include '../../../include/db.php';
include '../../../include/authenticate.php';
require_once dirname(__FILE__, 2) . '/include/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$access = system_monitor_widget_access();
if ($access === null) {
    http_response_code(403);
    exit(json_encode(array('error' => 'Permission denied')));
}
try {
    $out = system_monitor_widget_output($access);
} catch (Throwable $e) {
    http_response_code(500);
    $out = array('error' => 'Values not available');
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
