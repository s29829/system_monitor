<?php
// System Monitor - server metrics (Linux /proc, PHP-FPM, nginx, database), samples and history.
// Only reads files and status pages: no exec() / shell_exec(), so it also works when they are disabled.
require_once __DIR__ . '/functions.php';

// Process groups: key => [label, pattern for the process name (comm, max. 15 characters)]
function system_monitor_process_groups()
{
    return array(
        'fpm'   => array('PHP-FPM', '/^php-fpm/'),
        'nginx' => array('nginx', '/^nginx$/'),
        'db'    => array('Database (MySQL / MariaDB)', '/^(mysqld|mariadbd|mariadb)$/'),
        'media' => array('Media processing (ImageMagick, ffmpeg, Ghostscript, ExifTool, LibreOffice)', '/^(convert|magick|identify|composite|ffmpeg|ffprobe|gs|exiftool|soffice\.bin|unoconv)$/'),
        'cli'   => array('PHP CLI (cron, offline jobs)', '/^php(\d[\d.]*)?$/'),
    );
}

// open_basedir would turn every read outside the allowed folders into a warning (error page in RS)
function system_monitor_path_allowed($path)
{
    $base = (string) ini_get('open_basedir');
    if ($base === '') {
        return true;
    }
    foreach (explode(PATH_SEPARATOR, $base) as $dir) {
        $dir = rtrim($dir, '/');
        if ($dir !== '' && ($path === $dir || strpos($path, $dir . '/') === 0)) {
            return true;
        }
    }
    return false;
}

// Reads a small file; '' when it is missing or not readable. Processes can vanish between the
// check and the read, so warnings are caught by a temporary handler (never with "@").
function system_monitor_read($path)
{
    if (!system_monitor_path_allowed($path)) {
        return '';
    }
    set_error_handler(function () {
        return true;
    });
    try {
        $text = is_readable($path) ? file_get_contents($path) : false;
    } finally {
        restore_error_handler();
    }
    return is_string($text) ? $text : '';
}

function system_monitor_proc_available()
{
    return PHP_OS_FAMILY === 'Linux' && system_monitor_read('/proc/stat') !== '';
}

// ---- /proc ---------------------------------------------------------------------------------

// Total and idle CPU time (all cores, USER_HZ ticks) and the number of cores
function system_monitor_cpu_raw()
{
    $text = system_monitor_read('/proc/stat');
    if (!preg_match('/^cpu\s+(.+)$/m', $text, $m)) {
        return null;
    }
    $v = array_map('floatval', preg_split('/\s+/', trim($m[1])));
    $v = array_pad($v, 8, 0);
    // user nice system idle iowait irq softirq steal (guest time is already in user)
    $total = array_sum(array_slice($v, 0, 8));
    return array(
        'total'  => $total,
        'idle'   => $v[3] + $v[4],
        'iowait' => $v[4],
        'cores'  => max(1, preg_match_all('/^cpu\d+\s/m', $text)),
    );
}

function system_monitor_meminfo()
{
    $text = system_monitor_read('/proc/meminfo');
    $m = array();
    if (preg_match_all('/^(\w+):\s+(\d+)\s*kB/m', $text, $rows, PREG_SET_ORDER)) {
        foreach ($rows as $r) {
            $m[$r[1]] = (float) $r[2] * 1024;
        }
    }
    if (empty($m['MemTotal'])) {
        return null;
    }
    $avail = $m['MemAvailable'] ?? (($m['MemFree'] ?? 0) + ($m['Buffers'] ?? 0) + ($m['Cached'] ?? 0));
    $cache = ($m['Buffers'] ?? 0) + ($m['Cached'] ?? 0) + ($m['SReclaimable'] ?? 0);
    return array(
        'total'      => $m['MemTotal'],
        'available'  => $avail,
        'used'       => $m['MemTotal'] - $avail,
        'cache'      => $cache,
        'swap_total' => $m['SwapTotal'] ?? 0,
        'swap_used'  => ($m['SwapTotal'] ?? 0) - ($m['SwapFree'] ?? 0),
    );
}

function system_monitor_loadavg()
{
    $p = preg_split('/\s+/', trim(system_monitor_read('/proc/loadavg')));
    return count($p) >= 3 ? array((float) $p[0], (float) $p[1], (float) $p[2]) : null;
}

function system_monitor_uptime()
{
    $p = explode(' ', trim(system_monitor_read('/proc/uptime')));
    return $p[0] !== '' ? (int) $p[0] : null;
}

// Processes of the groups: count, CPU ticks (user + system), resident memory (RSS, includes shared memory)
// + how many processes are visible at all (hidepid on /proc hides processes of other users)
function system_monitor_processes()
{
    $groups = system_monitor_process_groups();
    $out = array();
    foreach ($groups as $k => $g) {
        $out[$k] = array('n' => 0, 'ticks' => 0.0, 'rss' => 0.0);
    }
    $visible = 0;
    $own_uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $foreign = 0;
    if (!system_monitor_path_allowed('/proc') || !is_dir('/proc')) {
        return array('groups' => $out, 'visible' => 0, 'foreign' => 0);
    }
    foreach (scandir('/proc') ?: array() as $pid) {
        if (!ctype_digit($pid)) {
            continue;
        }
        $stat = system_monitor_read('/proc/' . $pid . '/stat');
        $close = strrpos($stat, ')');
        $open = strpos($stat, '(');
        if ($close === false || $open === false) {
            continue;
        }
        $visible++;
        $comm = substr($stat, $open + 1, $close - $open - 1);
        foreach ($groups as $k => $g) {
            if (preg_match($g[1], $comm)) {
                $f = preg_split('/\s+/', trim(substr($stat, $close + 2)));
                if (count($f) < 22) {
                    break;
                }
                $out[$k]['n']++;
                $out[$k]['ticks'] += (float) $f[11] + (float) $f[12]; // utime + stime
                $status = system_monitor_read('/proc/' . $pid . '/status');
                if (preg_match('/^VmRSS:\s+(\d+)\s*kB/m', $status, $m)) {
                    $out[$k]['rss'] += (float) $m[1] * 1024;
                }
                if ($own_uid !== null && preg_match('/^Uid:\s+\d+\s+(\d+)/m', $status, $u) && (int) $u[1] !== $own_uid) {
                    $foreign++;
                }
                break;
            }
        }
    }
    return array('groups' => $out, 'visible' => $visible, 'foreign' => $foreign);
}

// Bytes received / sent on all interfaces except loopback
function system_monitor_net_raw()
{
    $text = system_monitor_read('/proc/net/dev');
    if ($text === '') {
        return null;
    }
    $rx = $tx = 0.0;
    foreach (explode("\n", $text) as $line) {
        if (!preg_match('/^\s*([^:\s]+):\s*(.+)$/', $line, $m) || $m[1] === 'lo') {
            continue;
        }
        $v = preg_split('/\s+/', trim($m[2]));
        $rx += (float) ($v[0] ?? 0);
        $tx += (float) ($v[8] ?? 0);
    }
    return array('rx' => $rx, 'tx' => $tx);
}

// Bytes read / written on whole disks (partitions and virtual devices are skipped)
function system_monitor_disk_raw()
{
    $text = system_monitor_read('/proc/diskstats');
    if ($text === '') {
        return null;
    }
    $r = $w = 0.0;
    foreach (explode("\n", $text) as $line) {
        $v = preg_split('/\s+/', trim($line));
        if (count($v) < 10 || !preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|hd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/', $v[2])) {
            continue;
        }
        $r += (float) $v[5] * 512;
        $w += (float) $v[9] * 512;
    }
    return array('r' => $r, 'w' => $w);
}

// Space on the filestore and on the root file system
function system_monitor_disk_space()
{
    $out = array();
    foreach (array('filestore' => system_monitor_storage_dir(), 'root' => '/') as $k => $path) {
        if (!function_exists('disk_total_space') || !system_monitor_path_allowed($path) || !is_dir($path)) {
            continue;
        }
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        if ($total && $free !== false) {
            $out[$k] = array('path' => $path, 'total' => (float) $total, 'free' => (float) $free);
        }
    }
    return $out;
}

// ---- Status pages ------------------------------------------------------------------------------

// GET of a local status page (curl, 2 s timeout); null on any error
function system_monitor_http_get($url)
{
    if (!system_monitor_valid_status_url($url) || !function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT      => 'ResourceSpace System Monitor',
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return is_string($body) && $code === 200 ? $body : null;
}

// PHP-FPM pool: fpm_get_status() inside an FPM worker (no configuration needed), otherwise the status page
function system_monitor_fpm_status($allow_http = true)
{
    $raw = null;
    $source = '';
    if (function_exists('fpm_get_status')) {
        $raw = fpm_get_status();
        $source = 'fpm_get_status()';
    }
    if (!is_array($raw) && $allow_http) {
        $url = system_monitor_settings()['fpm_status_url'];
        if ($url !== '') {
            $body = system_monitor_http_get($url . (strpos($url, '?') === false ? '?json' : '&json'));
            $raw = $body !== null ? json_decode($body, true) : null;
            $source = 'status page';
        }
    }
    if (!is_array($raw) || !isset($raw['pool'])) {
        return null;
    }
    $n = array();
    foreach ($raw as $k => $v) {
        $n[str_replace(' ', '-', (string) $k)] = $v;
    }
    return array(
        'source'     => $source,
        'pool'       => (string) $n['pool'],
        'pm'         => (string) ($n['process-manager'] ?? ''),
        'since'      => (int) ($n['start-since'] ?? 0),
        'accepted'   => (float) ($n['accepted-conn'] ?? 0),
        'queue'      => (int) ($n['listen-queue'] ?? 0),
        'max_queue'  => (int) ($n['max-listen-queue'] ?? 0),
        'idle'       => (int) ($n['idle-processes'] ?? 0),
        'active'     => (int) ($n['active-processes'] ?? 0),
        'total'      => (int) ($n['total-processes'] ?? 0),
        'max_active' => (int) ($n['max-active-processes'] ?? 0),
        'mcr'        => (float) ($n['max-children-reached'] ?? 0),
        'slow'       => (float) ($n['slow-requests'] ?? 0),
    );
}

// pm settings of the pool from /etc/php/<version>/fpm/pool.d/*.conf (when readable)
function system_monitor_fpm_pool_config($pool)
{
    $dir = '/etc/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '/fpm/pool.d';
    if ($pool === '' || !system_monitor_path_allowed($dir) || !is_dir($dir)) {
        return null;
    }
    foreach (glob($dir . '/*.conf') ?: array() as $file) {
        $text = system_monitor_read($file);
        if (!preg_match('/^\s*\[' . preg_quote($pool, '/') . '\]\s*$(.*?)(?=^\s*\[|\z)/ms', $text, $m)) {
            continue;
        }
        $cfg = array('file' => $file);
        foreach (array('pm', 'pm.max_children', 'pm.start_servers', 'pm.min_spare_servers', 'pm.max_spare_servers', 'pm.max_requests', 'pm.status_path', 'request_slowlog_timeout') as $key) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*([^;\r\n]+)/m', $m[1], $v)) {
                $cfg[$key] = trim($v[1]);
            }
        }
        return $cfg;
    }
    return null;
}

// nginx stub_status: "Active connections: 1 / server accepts handled requests / 1 1 1 / Reading: 0 Writing: 1 Waiting: 0"
function system_monitor_nginx_status()
{
    $url = system_monitor_settings()['nginx_status_url'];
    $body = $url !== '' ? system_monitor_http_get($url) : null;
    if ($body === null || !preg_match('/Active connections:\s*(\d+)/', $body, $a)
        || !preg_match('/\n\s*(\d+)\s+(\d+)\s+(\d+)\s*\n/', $body, $c)) {
        return null;
    }
    preg_match('/Reading:\s*(\d+)\s*Writing:\s*(\d+)\s*Waiting:\s*(\d+)/', $body, $s);
    return array(
        'conn'     => (int) $a[1],
        'requests' => (float) $c[3],
        'reading'  => (int) ($s[1] ?? 0),
        'writing'  => (int) ($s[2] ?? 0),
        'waiting'  => (int) ($s[3] ?? 0),
    );
}

// Database server counters (SHOW GLOBAL STATUS needs no extra privileges)
function system_monitor_db_status()
{
    $names = array('Threads_connected', 'Threads_running', 'Questions', 'Slow_queries', 'Uptime', 'Max_used_connections', 'Aborted_connects', 'Connections');
    try {
        $rows = ps_query("SHOW GLOBAL STATUS WHERE Variable_name IN ('" . implode("','", $names) . "')", array(), '', -1, false, 0);
    } catch (Throwable $e) {
        return null;
    }
    $out = array();
    foreach ((array) $rows as $r) {
        $r = array_change_key_case($r, CASE_LOWER);
        if (isset($r['variable_name'])) {
            $out[$r['variable_name']] = (float) $r['value'];
        }
    }
    return isset($out['Threads_connected']) ? $out : null;
}

function system_monitor_db_variables()
{
    $out = array();
    try {
        foreach ((array) ps_query("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections','innodb_buffer_pool_size','version','version_comment','slow_query_log','long_query_time')", array(), '', -1, false, 0) as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            $out[$r['variable_name']] = $r['value'];
        }
    } catch (Throwable $e) {
        return array();
    }
    return $out;
}

// Active users and offline jobs (cheap queries, also used by every sample)
function system_monitor_rs_live()
{
    $s = system_monitor_settings();
    list($gf, $gp) = system_monitor_group_filter('usergroup');
    $out = array('u5' => null, 'ua' => null, 'jobs' => null, 'jobs_err' => null);
    try {
        $r = ps_query('SELECT SUM(last_active > NOW() - INTERVAL 5 MINUTE) u5, SUM(last_active > NOW() - INTERVAL ? MINUTE) ua FROM user WHERE approved = 1' . $gf,
            array_merge(array('i', (int) $s['active_minutes']), $gp), '', -1, false, 0);
        $out['u5'] = (int) ($r[0]['u5'] ?? 0);
        $out['ua'] = (int) ($r[0]['ua'] ?? 0);
        $j = ps_query('SELECT SUM(status = 1) a, SUM(status = 5) e FROM job_queue', array(), '', -1, false, 0);
        $out['jobs'] = (int) ($j[0]['a'] ?? 0);
        $out['jobs_err'] = (int) ($j[0]['e'] ?? 0);
    } catch (Throwable $e) {
        // keep nulls
    }
    return $out;
}

// ---- Snapshot and rates ------------------------------------------------------------------------

// Raw readings (counters are turned into rates by system_monitor_rates())
function system_monitor_snapshot($allow_http = true)
{
    $proc = system_monitor_proc_available();
    return array(
        't'     => microtime(true),
        'cpu'   => $proc ? system_monitor_cpu_raw() : null,
        'mem'   => $proc ? system_monitor_meminfo() : null,
        'load'  => $proc ? system_monitor_loadavg() : null,
        'procs' => $proc ? system_monitor_processes() : null,
        'net'   => $proc ? system_monitor_net_raw() : null,
        'disk'  => $proc ? system_monitor_disk_raw() : null,
        'space' => system_monitor_disk_space(),
        'fpm'   => system_monitor_fpm_status($allow_http),
        'nginx' => $allow_http ? system_monitor_nginx_status() : null,
        'db'    => system_monitor_db_status(),
        'rs'    => system_monitor_rs_live(),
    );
}

// Flat sample: percentages, rates per second and current values (null = not available)
function system_monitor_rates($prev, array $cur)
{
    $s = array('t' => (int) round($cur['t']));
    $dt = is_array($prev) && isset($prev['t']) ? $cur['t'] - $prev['t'] : 0;
    $ok = $dt >= 0.2 && $dt < 3600;
    $rate = function ($a, $b) use ($dt, $ok) {
        if (!$ok || $a === null || $b === null || $b < $a) {
            return null; // first reading, too long a pause, or a counter that was reset
        }
        return ($b - $a) / $dt;
    };
    $inc = function ($a, $b) use ($ok) {
        return $ok && $a !== null && $b !== null && $b >= $a ? $b - $a : null;
    };
    $r1 = function ($v, $d = 1) {
        return $v === null ? null : round($v, $d);
    };

    // CPU
    $cpu_dt = null;
    if ($ok && !empty($cur['cpu']) && !empty($prev['cpu'])) {
        $cpu_dt = $cur['cpu']['total'] - $prev['cpu']['total'];
        if ($cpu_dt > 0) {
            $s['cpu'] = $r1(100 * (1 - ($cur['cpu']['idle'] - $prev['cpu']['idle']) / $cpu_dt));
            $s['iow'] = $r1(100 * ($cur['cpu']['iowait'] - $prev['cpu']['iowait']) / $cpu_dt);
        } else {
            $cpu_dt = null;
        }
    }
    $s['cores'] = $cur['cpu']['cores'] ?? null;
    if (!isset($s['cpu'])) {
        $s['cpu'] = $s['iow'] = null;
    }
    // load, memory
    $s['load1'] = $cur['load'][0] ?? null;
    $s['load5'] = $cur['load'][1] ?? null;
    $s['load15'] = $cur['load'][2] ?? null;
    $m = $cur['mem'];
    $s['mem'] = $m ? $r1(100 * $m['used'] / $m['total']) : null;
    $s['mem_used'] = $m ? $m['used'] : null;
    $s['mem_cache'] = $m ? $m['cache'] : null;
    $s['mem_total'] = $m ? $m['total'] : null;
    $s['swap'] = $m && $m['swap_total'] > 0 ? $r1(100 * $m['swap_used'] / $m['swap_total']) : ($m ? 0.0 : null);
    // process groups
    foreach (array_keys(system_monitor_process_groups()) as $g) {
        $c = $cur['procs']['groups'][$g] ?? null;
        $p = $prev['procs']['groups'][$g] ?? null;
        $s[$g . '_n'] = $c ? $c['n'] : null;
        $s[$g . '_mem'] = $c ? $c['rss'] : null;
        $s[$g . '_cpu'] = $c && $p && $cpu_dt && $c['ticks'] >= $p['ticks'] ? $r1(min(100, 100 * ($c['ticks'] - $p['ticks']) / $cpu_dt)) : ($c && $c['n'] === 0 ? 0.0 : null);
    }
    // PHP-FPM
    $f = $cur['fpm'];
    $pf = is_array($prev) ? ($prev['fpm'] ?? null) : null;
    $same_pool = $f && $pf && $pf['pool'] === $f['pool'];
    $s['fpm_act'] = $f ? $f['active'] : null;
    $s['fpm_idle'] = $f ? $f['idle'] : null;
    $s['fpm_tot'] = $f ? $f['total'] : null;
    $s['fpm_q'] = $f ? $f['queue'] : null;
    $s['fpm_rps'] = $same_pool ? $r1($rate($pf['accepted'], $f['accepted']), 2) : null;
    $s['fpm_mcr'] = $same_pool ? $inc($pf['mcr'], $f['mcr']) : null;
    $s['fpm_slow'] = $same_pool ? $inc($pf['slow'], $f['slow']) : null;
    // nginx
    $n = $cur['nginx'];
    $pn = is_array($prev) ? ($prev['nginx'] ?? null) : null;
    $s['ng_conn'] = $n ? $n['conn'] : null;
    $s['ng_write'] = $n ? $n['writing'] : null;
    $s['ng_wait'] = $n ? $n['waiting'] : null;
    $s['ng_rps'] = $n && $pn ? $r1($rate($pn['requests'], $n['requests']), 2) : null;
    // database
    $d = $cur['db'];
    $pd = is_array($prev) ? ($prev['db'] ?? null) : null;
    $s['db_conn'] = $d ? $d['Threads_connected'] : null;
    $s['db_run'] = $d ? $d['Threads_running'] : null;
    $s['db_qps'] = $d && $pd ? $r1($rate($pd['Questions'] ?? null, $d['Questions'] ?? null), 1) : null;
    $s['db_slow'] = $d && $pd ? $inc($pd['Slow_queries'] ?? null, $d['Slow_queries'] ?? null) : null;
    // network and disk I/O (bytes per second)
    $s['net_rx'] = $cur['net'] && !empty($prev['net']) ? $r1($rate($prev['net']['rx'], $cur['net']['rx']), 0) : null;
    $s['net_tx'] = $cur['net'] && !empty($prev['net']) ? $r1($rate($prev['net']['tx'], $cur['net']['tx']), 0) : null;
    $s['dk_r'] = $cur['disk'] && !empty($prev['disk']) ? $r1($rate($prev['disk']['r'], $cur['disk']['r']), 0) : null;
    $s['dk_w'] = $cur['disk'] && !empty($prev['disk']) ? $r1($rate($prev['disk']['w'], $cur['disk']['w']), 0) : null;
    // disk space of the filestore
    $sp = $cur['space']['filestore'] ?? null;
    $s['disk_free'] = $sp ? $sp['free'] : null;
    $s['disk_pct'] = $sp ? $r1(100 * (1 - $sp['free'] / $sp['total'])) : null;
    // ResourceSpace
    $s['u5'] = $cur['rs']['u5'];
    $s['ua'] = $cur['rs']['ua'];
    $s['jobs'] = $cur['rs']['jobs'];
    $s['jobs_err'] = $cur['rs']['jobs_err'];
    return $s;
}

// Raw readings kept between two samples (process lists etc. are not needed)
function system_monitor_raw_for_state(array $snap)
{
    unset($snap['space'], $snap['rs']);
    return $snap;
}

// ---- Collector ---------------------------------------------------------------------------------

// Takes one sample when the interval has passed; $source = 'web' or 'cli'. Returns true when a sample was written.
function system_monitor_collect($source = 'web', $force = false)
{
    $settings = system_monitor_settings();
    $dir = system_monitor_data_dir();
    if (!system_monitor_mkdir($dir . '/minute')) {
        return false;
    }
    $lock = fopen($dir . '/collect.lock', 'c');
    if ($lock === false) {
        return false;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return false; // another request is taking the sample
    }
    $written = false;
    try {
        $state = system_monitor_read_json(system_monitor_state_file());
        $last = (int) ($state['t'] ?? 0);
        // the cron collector runs every minute: a few seconds of tolerance so it does not skip every other minute
        $due = $settings['interval'] - ($source === 'cli' ? 5 : 0);
        if ($force || time() - $last >= $due) {
            $snap = system_monitor_snapshot($source === 'cli' || $settings['fpm_status_url'] !== '' || $settings['nginx_status_url'] !== '');
            $sample = system_monitor_rates($state['raw'] ?? null, $snap);
            $sample['src'] = $source === 'cli' ? 'c' : 'w';
            $day = date('Y-m-d', $sample['t']);
            $line = json_encode($sample, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
            if (file_put_contents($dir . '/minute/' . $day . '.jsonl', $line, FILE_APPEND | LOCK_EX) !== false) {
                $written = true;
            }
            $hour = date('Y-m-d H', $sample['t']);
            if (!empty($state['hour']) && $state['hour'] !== $hour) {
                system_monitor_aggregate_hour($state['hour']);
                system_monitor_prune($settings);
            }
            $state = array(
                't'        => $sample['t'],
                'hour'     => $hour,
                'raw'      => system_monitor_raw_for_state($snap),
                'last_cli' => $source === 'cli' ? $sample['t'] : (int) ($state['last_cli'] ?? 0),
                'last_web' => $source === 'web' ? $sample['t'] : (int) ($state['last_web'] ?? 0),
                'first'    => (int) ($state['first'] ?? $sample['t']),
            );
            system_monitor_write_json(system_monitor_state_file(), $state);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $written;
}

// Average and maximum of every numeric field of one hour -> hour/YYYY-MM.jsonl
function system_monitor_aggregate_hour($hour)
{
    $start = strtotime($hour . ':00:00');
    if ($start === false) {
        return;
    }
    $rows = array_filter(system_monitor_minute_rows($start, $start + 3600), function ($r) use ($start) {
        return $r['t'] >= $start && $r['t'] < $start + 3600;
    });
    if (!$rows) {
        return;
    }
    $sum = $cnt = $max = array();
    foreach ($rows as $r) {
        foreach ($r as $k => $v) {
            if ($k === 't' || !is_int($v) && !is_float($v)) {
                continue;
            }
            $sum[$k] = ($sum[$k] ?? 0) + $v;
            $cnt[$k] = ($cnt[$k] ?? 0) + 1;
            $max[$k] = isset($max[$k]) ? max($max[$k], $v) : $v;
        }
    }
    $a = array();
    foreach ($sum as $k => $v) {
        $a[$k] = round($v / $cnt[$k], 2);
    }
    $rec = array('t' => $start, 'n' => count($rows), 'a' => $a, 'x' => $max);
    $file = system_monitor_data_dir() . '/hour/' . date('Y-m', $start) . '.jsonl';
    if (system_monitor_mkdir(dirname($file))) {
        file_put_contents($file, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n", FILE_APPEND | LOCK_EX);
    }
}

// Deletes minute files and hourly files older than the retention
function system_monitor_prune(array $settings)
{
    $dir = system_monitor_data_dir();
    $min_day = date('Y-m-d', time() - $settings['minute_days'] * 86400 - 86400);
    foreach (glob($dir . '/minute/*.jsonl') ?: array() as $f) {
        if (basename($f, '.jsonl') < $min_day) {
            unlink($f);
        }
    }
    $min_month = date('Y-m', time() - $settings['hour_days'] * 86400 - 31 * 86400);
    foreach (glob($dir . '/hour/*.jsonl') ?: array() as $f) {
        if (basename($f, '.jsonl') < $min_month) {
            unlink($f);
        }
    }
}

// ---- History -----------------------------------------------------------------------------------

function system_monitor_jsonl($file)
{
    $out = array();
    if (!is_file($file) || !is_readable($file)) {
        return $out;
    }
    $fh = fopen($file, 'r');
    if ($fh === false) {
        return $out;
    }
    while (($line = fgets($fh)) !== false) {
        $r = json_decode($line, true);
        if (is_array($r) && isset($r['t'])) {
            $out[] = $r;
        }
    }
    fclose($fh);
    return $out;
}

function system_monitor_minute_rows($from, $to)
{
    $rows = array();
    for ($d = strtotime(date('Y-m-d', $from)); $d <= $to; $d += 86400) {
        foreach (system_monitor_jsonl(system_monitor_data_dir() . '/minute/' . date('Y-m-d', $d) . '.jsonl') as $r) {
            if ($r['t'] >= $from && $r['t'] <= $to) {
                $rows[] = $r;
            }
        }
    }
    return $rows;
}

function system_monitor_hour_rows($from, $to)
{
    $rows = array();
    $m = strtotime(date('Y-m-01', $from));
    while ($m <= $to) {
        foreach (system_monitor_jsonl(system_monitor_data_dir() . '/hour/' . date('Y-m', $m) . '.jsonl') as $r) {
            if ($r['t'] >= $from && $r['t'] <= $to) {
                $rows[] = $r;
            }
        }
        $m = strtotime('+1 month', $m);
    }
    return $rows;
}

// Ranges of the history charts: key => [seconds, label]
function system_monitor_ranges()
{
    return array(
        '6h'   => array(6 * 3600, '6 hours'),
        '24h'  => array(86400, '24 hours'),
        '48h'  => array(2 * 86400, '48 hours'),
        '7d'   => array(7 * 86400, '7 days'),
        '30d'  => array(30 * 86400, '30 days'),
        '90d'  => array(90 * 86400, '90 days'),
        '365d' => array(365 * 86400, '1 year'),
    );
}

// Columns for the charts: {t: [...], a: {key: [...]}, x: {key: [...]}} with at most ~800 points.
// Short ranges use the minute samples, longer ones the hourly aggregates; empty buckets are null (gaps).
function system_monitor_history($range)
{
    $ranges = system_monitor_ranges();
    $span = $ranges[$range][0] ?? 86400;
    $to = time();
    $from = $to - $span;
    $settings = system_monitor_settings();
    $minute = $span <= min(2, $settings['minute_days']) * 86400;
    $step_min = $minute ? $settings['interval'] : 3600;
    $step = max($step_min, (int) (ceil($span / 800 / $step_min) * $step_min));
    $rows = $minute ? system_monitor_minute_rows($from, $to) : system_monitor_hour_rows($from, $to);
    // the current hour is not aggregated yet: add it from the minute samples
    if (!$minute) {
        $cur = strtotime(date('Y-m-d H:00:00'));
        $tail = system_monitor_minute_rows($cur, $to);
        if ($tail) {
            $a = $x = array();
            foreach ($tail as $r) {
                foreach ($r as $k => $v) {
                    if ($k !== 't' && (is_int($v) || is_float($v))) {
                        $a[$k][] = $v;
                        $x[$k] = isset($x[$k]) ? max($x[$k], $v) : $v;
                    }
                }
            }
            $rows[] = array('t' => $cur, 'a' => array_map(function ($l) {
                return array_sum($l) / count($l);
            }, $a), 'x' => $x);
        }
    }
    $buckets = array();
    foreach ($rows as $r) {
        $b = (int) (floor(($r['t'] - $from) / $step));
        $avg = $minute ? $r : $r['a'];
        $mx = $minute ? $r : $r['x'];
        foreach ($avg as $k => $v) {
            if ($k === 't' || $k === 'src' || (!is_int($v) && !is_float($v))) {
                continue;
            }
            $buckets[$b]['s'][$k] = ($buckets[$b]['s'][$k] ?? 0) + $v;
            $buckets[$b]['c'][$k] = ($buckets[$b]['c'][$k] ?? 0) + 1;
            $m = $mx[$k] ?? $v;
            $buckets[$b]['x'][$k] = isset($buckets[$b]['x'][$k]) ? max($buckets[$b]['x'][$k], $m) : $m;
        }
    }
    $keys = array();
    foreach ($buckets as $b) {
        $keys += array_flip(array_keys($b['s']));
    }
    $keys = array_keys($keys);
    $n = (int) ceil($span / $step);
    $out = array('t' => array(), 'a' => array_fill_keys($keys, array()), 'x' => array_fill_keys($keys, array()),
        'step' => $step, 'source' => $minute ? 'minute' : 'hour', 'from' => $from, 'to' => $to);
    for ($i = 0; $i < $n; $i++) {
        $out['t'][] = $from + $i * $step + (int) ($step / 2);
        foreach ($keys as $k) {
            $has = isset($buckets[$i]['c'][$k]);
            $out['a'][$k][] = $has ? round($buckets[$i]['s'][$k] / $buckets[$i]['c'][$k], 2) : null;
            $out['x'][$k][] = $has ? $buckets[$i]['x'][$k] : null;
        }
    }
    return $out;
}

// Size and age of the stored history (for the options page)
function system_monitor_history_info()
{
    $dir = system_monitor_data_dir();
    $size = 0;
    $files = 0;
    foreach (array('minute', 'hour', 'cache') as $sub) {
        foreach (glob($dir . '/' . $sub . '/*') ?: array() as $f) {
            $size += (int) filesize($f);
            $files++;
        }
    }
    $state = system_monitor_read_json(system_monitor_state_file());
    return array('size' => $size, 'files' => $files, 'first' => (int) ($state['first'] ?? 0), 'last' => (int) ($state['t'] ?? 0),
        'last_cli' => (int) ($state['last_cli'] ?? 0), 'last_web' => (int) ($state['last_web'] ?? 0));
}

// Live reading for the options page: rates since the previous live reading of this administrator
function system_monitor_live($userref)
{
    $file = system_monitor_data_dir() . '/live_' . (int) $userref . '.json';
    $prev = system_monitor_read_json($file, null);
    if (!is_array($prev) || !isset($prev['t']) || microtime(true) - $prev['t'] > 120) {
        // first reading: a second one after 0.3 s gives CPU and rates right away
        $prev = system_monitor_raw_for_state(system_monitor_snapshot());
        usleep(300000);
    }
    $snap = system_monitor_snapshot();
    system_monitor_write_json($file, system_monitor_raw_for_state($snap));
    $sample = system_monitor_rates($prev, $snap);
    $groups = array();
    foreach (system_monitor_process_groups() as $k => $g) {
        $groups[$k] = $g[0];
    }
    return array(
        'sample'  => $sample,
        'fpm'     => $snap['fpm'],
        'db'      => $snap['db'] ? array('uptime' => $snap['db']['Uptime'] ?? null, 'max_used' => $snap['db']['Max_used_connections'] ?? null) : null,
        'space'   => $snap['space'],
        'uptime'  => system_monitor_uptime(),
        'groups'  => $groups,
        'visible' => $snap['procs']['visible'] ?? null,
        'opcache' => system_monitor_opcache(),
    );
}

function system_monitor_opcache()
{
    if (!function_exists('opcache_get_status')) {
        return null;
    }
    $s = opcache_get_status(false);
    if (!is_array($s) || empty($s['opcache_enabled'])) {
        return array('enabled' => false);
    }
    $mem = $s['memory_usage'] ?? array();
    $st = $s['opcache_statistics'] ?? array();
    $used = (float) ($mem['used_memory'] ?? 0);
    $free = (float) ($mem['free_memory'] ?? 0);
    $wasted = (float) ($mem['wasted_memory'] ?? 0);
    return array(
        'enabled'  => true,
        'hit_rate' => round((float) ($st['opcache_hit_rate'] ?? 0), 2),
        'scripts'  => (int) ($st['num_cached_scripts'] ?? 0),
        'max_keys' => (int) ($st['max_cached_keys'] ?? 0),
        'mem_pct'  => $used + $free + $wasted > 0 ? round(100 * ($used + $wasted) / ($used + $free + $wasted), 1) : null,
        'mem'      => $used + $free + $wasted,
        'full'     => !empty($s['cache_full']),
        'restarts' => (int) ($st['oom_restarts'] ?? 0) + (int) ($st['hash_restarts'] ?? 0),
    );
}
