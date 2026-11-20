<?php
// System Monitor - storage, settings and small helpers.
// Layout on disk (everything below is removed by "Reset plugin"):
//   <filestore>/system_monitor/data_<key>/settings.json          settings
//   <filestore>/system_monitor/data_<key>/state.json             last raw counters (rates are computed from them)
//   <filestore>/system_monitor/data_<key>/minute/YYYY-MM-DD.jsonl one sample per line (about once a minute)
//   <filestore>/system_monitor/data_<key>/hour/YYYY-MM.jsonl      hourly aggregates (average + maximum)
//   <filestore>/system_monitor/data_<key>/cache/<name>.json       short-lived cache of heavier statistics
//   <filestore>/system_monitor/data_<key>/widget.json             shared values of the side panel / header bar

const SYSTEM_MONITOR_INTERVALS = array(30, 60, 120, 300);   // allowed sample intervals (seconds)

require_once __DIR__ . '/widgets.php'; // side panel and header bar on every page

// <filestore> ($storagedir, default <RS_ROOT>/filestore)
function system_monitor_storage_dir()
{
    $s = (string) ($GLOBALS['storagedir'] ?? '');
    return rtrim($s !== '' ? $s : dirname(__FILE__, 4) . '/filestore', '/');
}

// <filestore>/system_monitor - everything the plugin stores
function system_monitor_base_dir()
{
    return system_monitor_storage_dir() . '/system_monitor';
}

// Settings and history: not readable by URL (folder name derived from $scramble_key)
function system_monitor_data_dir()
{
    $key = (string) ($GLOBALS['scramble_key'] ?? '');
    return system_monitor_base_dir() . '/data' . ($key !== '' ? '_' . substr(md5($key . 'system_monitor'), 0, 12) : '');
}

function system_monitor_state_file()
{
    return system_monitor_data_dir() . '/state.json';
}

function system_monitor_mkdir($dir)
{
    if (is_dir($dir)) {
        return true;
    }
    return mkdir($dir, 0755, true) || is_dir($dir);
}

// ---- JSON files ------------------------------------------------------------------------------

function system_monitor_read_json($file, $default = array())
{
    if (!is_file($file) || !is_readable($file)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

// Atomic write (temporary file + rename)
function system_monitor_write_json($file, $data)
{
    if (!system_monitor_mkdir(dirname($file))) {
        return false;
    }
    $tmp = $file . '.tmp' . getmypid();
    if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), LOCK_EX) === false || !rename($tmp, $file)) {
        if (is_file($tmp)) {
            unlink($tmp);
        }
        return false;
    }
    return true;
}

// Cached result of $fn for $ttl seconds (heavier database statistics)
function system_monitor_cached($name, $ttl, callable $fn)
{
    $file = system_monitor_data_dir() . '/cache/' . preg_replace('/[^a-z0-9_-]/', '_', strtolower($name)) . '.json';
    if (is_file($file) && filemtime($file) > time() - $ttl) {
        $data = system_monitor_read_json($file, null);
        if ($data !== null && array_key_exists('v', $data)) {
            return $data['v'];
        }
    }
    $value = $fn();
    system_monitor_write_json($file, array('t' => time(), 'v' => $value));
    return $value;
}

function system_monitor_clear_cache()
{
    $dir = system_monitor_data_dir() . '/cache';
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.json') ?: array() as $f) {
            unlink($f);
        }
    }
}

// ---- Settings --------------------------------------------------------------------------------

function system_monitor_defaults()
{
    return array(
        'collect_on_requests' => true,    // take samples during normal page requests (no cron needed)
        'interval'            => 60,      // seconds between samples
        'minute_days'         => 2,       // keep minute samples for N days (1-14)
        'hour_days'           => 400,     // keep hourly aggregates for N days (30-1100)
        'active_minutes'      => 15,      // "active now" = activity within N minutes (5-120)
        'exclude_groups'      => array(), // user groups left out of the ResourceSpace statistics (e.g. load test accounts)
        'fpm_status_url'      => '',      // e.g. http://127.0.0.1:8099/fpm-status (needed by the cron collector)
        'nginx_status_url'    => '',      // e.g. http://127.0.0.1:8099/nginx_status
        'live_seconds'        => 3,       // refresh of the live view (2-30)
    ) + system_monitor_widget_defaults(); // side panel and header bar (include/widgets.php)
}

function system_monitor_settings()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $defaults = system_monitor_defaults();
    $stored = system_monitor_read_json(system_monitor_data_dir() . '/settings.json');
    $out = $defaults;
    foreach ($defaults as $k => $v) {
        if (array_key_exists($k, $stored) && gettype($stored[$k]) === gettype($v)) {
            $out[$k] = $stored[$k];
        }
    }
    return $cache = system_monitor_widget_clean(system_monitor_widget_migrate($stored, $out));
}

function system_monitor_save_settings(array $new)
{
    $defaults = system_monitor_defaults();
    $clean = array();
    foreach ($defaults as $k => $v) {
        $clean[$k] = array_key_exists($k, $new) && gettype($new[$k]) === gettype($v) ? $new[$k] : $v;
    }
    if (!in_array($clean['interval'], SYSTEM_MONITOR_INTERVALS, true)) {
        $clean['interval'] = 60;
    }
    $clean['minute_days']    = max(1, min(14, $clean['minute_days']));
    $clean['hour_days']      = max(30, min(1100, $clean['hour_days']));
    $clean['active_minutes'] = max(5, min(120, $clean['active_minutes']));
    $clean['live_seconds']   = max(2, min(30, $clean['live_seconds']));
    $clean['exclude_groups'] = array_values(array_unique(array_filter(array_map('intval', $clean['exclude_groups']))));
    foreach (array('fpm_status_url', 'nginx_status_url') as $k) {
        $clean[$k] = system_monitor_valid_status_url($clean[$k]) ? $clean[$k] : '';
    }
    $clean = system_monitor_widget_clean($clean);
    return system_monitor_write_json(system_monitor_data_dir() . '/settings.json', $clean);
}

// Status pages must be plain http(s) URLs on this server (loopback), never other hosts
function system_monitor_valid_status_url($url)
{
    if (!is_string($url) || $url === '' || strlen($url) > 200) {
        return false;
    }
    $p = parse_url($url);
    if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), array('http', 'https'), true) || isset($p['user'])) {
        return false;
    }
    $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
    return in_array($host, array('localhost', '::1'), true) || strpos($host, '127.') === 0;
}

// Text area with one item per line -> list
function system_monitor_lines($text)
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text)), 'strlen'));
}

// ---- Reset -----------------------------------------------------------------------------------

function system_monitor_rrmdir($dir)
{
    if (!is_dir($dir)) {
        return true;
    }
    foreach (array_diff((array) scandir($dir), array('.', '..')) as $f) {
        $p = $dir . '/' . $f;
        if (is_dir($p) && !is_link($p)) {
            system_monitor_rrmdir($p);
        } else {
            unlink($p);
        }
    }
    return rmdir($dir);
}

// Removes <filestore>/system_monitor (settings and history); ResourceSpace data is not touched
function system_monitor_reset()
{
    return system_monitor_rrmdir(system_monitor_base_dir());
}

// ---- Formatting ------------------------------------------------------------------------------

function system_monitor_bytes($b, $dec = 1)
{
    $b = (float) $b;
    $u = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    $i = 0;
    while (abs($b) >= 1024 && $i < count($u) - 1) {
        $b /= 1024;
        $i++;
    }
    return number_format($b, $i === 0 ? 0 : $dec) . ' ' . $u[$i];
}

function system_monitor_duration($s)
{
    $s = (int) $s;
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    return $d > 0 ? $d . 'd ' . $h . 'h' : ($h > 0 ? $h . 'h ' . $m . 'min' : $m . ' min');
}

// SQL fragment + parameters that leave out the excluded user groups (column $col)
function system_monitor_group_filter($col)
{
    $groups = system_monitor_settings()['exclude_groups'];
    if (!$groups) {
        return array('', array());
    }
    $params = array();
    foreach ($groups as $g) {
        $params[] = 'i';
        $params[] = (int) $g;
    }
    return array(' AND ' . $col . ' NOT IN (' . implode(',', array_fill(0, count($groups), '?')) . ')', $params);
}

// Next stages (shown on the options page and in the README; remind about them in every new version)
function system_monitor_roadmap()
{
    return array(
        'Stage 2' => array(
            'Threshold alerts: CPU, memory, disk, offline jobs, busy PHP-FPM workers, failed logins - banner for administrators and e-mail',
            'Event markers on the charts (load test, ResourceSpace update) - added by hand or by the load test tool',
            'CSV / JSON export of every chart',
            'Dash tile for the ResourceSpace home page (CPU, memory, active users)',
            'Widgets: thresholds of the colours (OK / busy / overloaded) set on the options page; the user can pick a panel preset',
        ),
        'Stage 3' => array(
            'Per-request timing (slowest pages) from the nginx access log or PHP-FPM slow log',
            'Database: largest tables trend, slow query list',
            'Comparison of two periods (e.g. this week vs. last week)',
            'Permission for non-admin viewers of the dashboard',
        ),
    );
}
