<?php
// System Monitor - JSON data for the charts of the options page (administrators only, read-only)
include '../../../include/db.php';
include '../../../include/authenticate.php';
require_once dirname(__FILE__, 2) . '/include/stats.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!checkperm('a')) {
    http_response_code(403);
    exit(json_encode(array('error' => 'Permission denied')));
}

$what = (string) ($_GET['what'] ?? '');
switch ($what) {
    case 'live':
        $out = system_monitor_live($userref);
        $out['users'] = system_monitor_active_users();
        break;
    case 'history':
        $range = (string) ($_GET['range'] ?? '24h');
        $out = system_monitor_history(array_key_exists($range, system_monitor_ranges()) ? $range : '24h');
        break;
    case 'rs':
        $out = array(
            'overview' => system_monitor_rs_overview(),
            'series'   => system_monitor_rs_series((string) ($_GET['scale'] ?? 'day')),
            'top'      => system_monitor_rs_top(),
            'db'       => system_monitor_db_size(),
        );
        break;
    default:
        http_response_code(400);
        $out = array('error' => 'Unknown request');
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
