<?php
// System Monitor - widgets on every page (since 1.01):
//   * side panel  - floating box at the edge of the screen, for chosen user groups and users,
//   * header bar  - a few values in the top menu (before Upload) with a drop-down for more;
//                   each user group gets one of three sets (Basic / Standard / Full) or nothing.
// Values come from one shared file (widget.json) refreshed at most every SYSTEM_MONITOR_WIDGET_TTL
// seconds, however many users look at the widgets.
// Included by functions.php (settings defaults and validation need the catalogue).

const SYSTEM_MONITOR_WIDGET_TTL = 5;                       // seconds a computed widget snapshot is reused
const SYSTEM_MONITOR_WIDGET_STYLES = array('number', 'spark', 'bar');
const SYSTEM_MONITOR_BAR_MAX = 4;                         // values shown in the header bar itself

// Metrics the widgets can show: key => label, short label (header bar), help, sample key of the sparkline
function system_monitor_widget_catalog()
{
    return array(
        'health' => array('label' => 'System status',      'short' => 'Status', 'spark' => null,      'help' => 'OK, busy or overloaded - the worst of CPU, memory, load, swap, disk and the PHP-FPM queue'),
        'cpu'    => array('label' => 'CPU',                'short' => 'CPU',    'spark' => 'cpu',     'help' => 'Share of all cores in use'),
        'mem'    => array('label' => 'Memory',             'short' => 'RAM',    'spark' => 'mem',     'help' => 'Used memory (total minus available)'),
        'load'   => array('label' => 'Load average',       'short' => 'Load',   'spark' => 'load1',   'help' => '1-minute load average; busy from 1 per core'),
        'swap'   => array('label' => 'Swap',               'short' => 'Swap',   'spark' => 'swap',    'help' => 'Swap space in use'),
        'disk'   => array('label' => 'Filestore disk',     'short' => 'Disk',   'spark' => 'disk_pct', 'help' => 'Used space of the disk that holds the filestore'),
        'fpm'    => array('label' => 'PHP-FPM workers',    'short' => 'PHP',    'spark' => 'fpm_act', 'help' => 'Busy / running workers and the request queue'),
        'rps'    => array('label' => 'Requests',           'short' => 'Req/s',  'spark' => 'rps',     'help' => 'Requests per second (nginx, otherwise PHP-FPM)'),
        'conn'   => array('label' => 'Web connections',    'short' => 'Conn',   'spark' => 'ng_conn', 'help' => 'Open nginx connections'),
        'db'     => array('label' => 'Database',           'short' => 'DB',     'spark' => 'db_qps',  'help' => 'Queries per second, connections and running threads'),
        'net'    => array('label' => 'Network',            'short' => 'Net',    'spark' => 'net',     'help' => 'Bytes per second received + sent'),
        'io'     => array('label' => 'Disk I/O',           'short' => 'I/O',    'spark' => 'io',      'help' => 'Bytes per second read + written'),
        'media'  => array('label' => 'Media processing',   'short' => 'Media',  'spark' => 'media_n', 'help' => 'Running ImageMagick, ffmpeg, Ghostscript, ExifTool and LibreOffice processes'),
        'users'  => array('label' => 'Users online',       'short' => 'Users',  'spark' => 'u5',      'help' => 'Users active in the last 5 minutes'),
        'active' => array('label' => 'Active users',       'short' => 'Active', 'spark' => 'ua',      'help' => 'Users active in the last N minutes (setting)'),
        'jobs'   => array('label' => 'Offline jobs',       'short' => 'Jobs',   'spark' => 'jobs',    'help' => 'Jobs waiting in the job queue and failed jobs'),
        'dl'     => array('label' => 'Downloads today',    'short' => 'DL',     'spark' => null,      'help' => 'Resource downloads since midnight'),
        'up'     => array('label' => 'Uploads today',      'short' => 'UL',     'spark' => null,      'help' => 'New resources since midnight'),
    );
}

// Predefined content of the side panel (buttons on the options page fill the checkboxes)
function system_monitor_panel_presets()
{
    return array(
        'server'   => array('label' => 'Server load',          'metrics' => array('health', 'cpu', 'mem', 'load', 'fpm', 'rps', 'db', 'disk')),
        'activity' => array('label' => 'Users and activity',   'metrics' => array('health', 'users', 'active', 'dl', 'up', 'jobs', 'rps', 'cpu')),
        'full'     => array('label' => 'Everything',           'metrics' => array_keys(system_monitor_widget_catalog())),
    );
}

// The three sets of the header bar: values in the bar, rows of the drop-down, display style
function system_monitor_bar_set_defaults()
{
    // In the bar: current CPU and memory use in per cent; the drop-down: the other values of the set
    $all = array_values(array_diff(array_keys(system_monitor_widget_catalog()), array('cpu', 'mem')));
    return array(
        'basic'    => array('bar' => array('cpu', 'mem'), 'menu' => array('health', 'users', 'dl', 'up', 'jobs'), 'style' => 'number'),
        'standard' => array('bar' => array('cpu', 'mem'), 'menu' => array('health', 'load', 'rps', 'users', 'active', 'dl', 'up', 'jobs', 'disk'), 'style' => 'bar'),
        'full'     => array('bar' => array('cpu', 'mem'), 'menu' => $all, 'style' => 'spark'),
    );
}

// Settings stored before this layout (no widget_rev): CPU and memory go to the bar of every set,
// the drop-down keeps the other values. The display style and the group assignment are kept.
const SYSTEM_MONITOR_WIDGET_REV = 2;

function system_monitor_widget_migrate(array $stored, array $s)
{
    if ((int) ($stored['widget_rev'] ?? 1) >= SYSTEM_MONITOR_WIDGET_REV || !isset($stored['bar_sets'])) {
        return $s;
    }
    foreach ($s['bar_sets'] as $k => $set) {
        if (!is_array($set)) {
            continue;
        }
        $s['bar_sets'][$k]['bar'] = array('cpu', 'mem');
        $s['bar_sets'][$k]['menu'] = array_values(array_diff((array) ($set['menu'] ?? array()), array('cpu', 'mem')));
    }
    return $s;
}

function system_monitor_bar_set_labels()
{
    return array('basic' => 'Basic', 'standard' => 'Standard', 'full' => 'Full');
}

function system_monitor_widget_defaults()
{
    return array(
        'panel_groups'  => array(),                                   // user groups that see the side panel
        'panel_users'   => array(),                                   // and single users (user names)
        'panel_metrics' => system_monitor_panel_presets()['server']['metrics'],
        'panel_side'    => 'right',                                   // right | left
        'panel_open'    => false,                                     // open on the first visit (later: the user's last choice)
        'panel_seconds' => 10,                                        // refresh (5-120)
        'bar_groups'    => array(),                                   // user group => basic | standard | full
        'bar_sets'      => system_monitor_bar_set_defaults(),
        'bar_seconds'   => 30,                                        // refresh (10-300)
        'widget_idle'   => 10,                                        // stop refreshing after N minutes without mouse / keyboard (0 = never)
        'widget_rev'    => SYSTEM_MONITOR_WIDGET_REV,                 // layout of the widget settings (see system_monitor_widget_migrate)
    );
}

// Only known metric keys, in catalogue order, no duplicates
function system_monitor_widget_keys($list, $max = 0)
{
    $list = array_map('strval', is_array($list) ? $list : array());
    $out = array_values(array_intersect(array_keys(system_monitor_widget_catalog()), $list));
    return $max > 0 ? array_slice($out, 0, $max) : $out;
}

// Validation of the widget settings (called by system_monitor_save_settings)
function system_monitor_widget_clean(array $s)
{
    $s['panel_groups']  = array_values(array_unique(array_filter(array_map('intval', $s['panel_groups']))));
    $s['panel_users']   = array_values(array_unique(array_filter(array_map(function ($u) {
        return mb_substr(trim((string) $u), 0, 100);
    }, $s['panel_users']), 'strlen')));
    $s['panel_metrics'] = system_monitor_widget_keys($s['panel_metrics']);
    $s['panel_side']    = $s['panel_side'] === 'left' ? 'left' : 'right';
    $s['panel_seconds'] = max(5, min(120, (int) $s['panel_seconds']));
    $s['bar_seconds']   = max(10, min(300, (int) $s['bar_seconds']));
    $s['widget_idle']   = max(0, min(240, (int) $s['widget_idle']));
    $s['widget_rev']    = SYSTEM_MONITOR_WIDGET_REV;
    $defaults = system_monitor_bar_set_defaults();
    $sets = array();
    foreach ($defaults as $k => $d) {
        $in = is_array($s['bar_sets'][$k] ?? null) ? $s['bar_sets'][$k] : $d;
        $sets[$k] = array(
            'bar'   => system_monitor_widget_keys($in['bar'] ?? array(), SYSTEM_MONITOR_BAR_MAX),
            'menu'  => system_monitor_widget_keys($in['menu'] ?? array()),
            'style' => in_array($in['style'] ?? '', SYSTEM_MONITOR_WIDGET_STYLES, true) ? $in['style'] : $d['style'],
        );
    }
    $s['bar_sets'] = $sets;
    $groups = array();
    foreach ($s['bar_groups'] as $g => $set) {
        if ((int) $g > 0 && isset($sets[$set])) {
            $groups[(string) (int) $g] = $set;
        }
    }
    $s['bar_groups'] = $groups;
    return $s;
}

// What the current user may see: null, or [panel => bool, set => '' | basic | standard | full, keys => [...]]
function system_monitor_widget_access()
{
    $user = (int) ($GLOBALS['userref'] ?? 0);
    $name = (string) ($GLOBALS['username'] ?? '');
    if ($user <= 0 || $name === '') {
        return null;
    }
    $anon = $GLOBALS['anonymous_login'] ?? null;
    if ((is_string($anon) && $anon === $name) || (is_array($anon) && in_array($name, $anon, true))) {
        return null; // the shared anonymous account never sees server data
    }
    $s = system_monitor_settings();
    $group = (int) ($GLOBALS['usergroup'] ?? 0);
    $panel = $s['panel_metrics'] && (in_array($group, $s['panel_groups'], true)
        || in_array(mb_strtolower($name), array_map('mb_strtolower', $s['panel_users']), true));
    $set = (string) ($s['bar_groups'][(string) $group] ?? '');
    if (!isset($s['bar_sets'][$set]) || !$s['bar_sets'][$set]['bar']) {
        $set = '';
    }
    if (!$panel && $set === '') {
        return null;
    }
    $keys = $panel ? $s['panel_metrics'] : array();
    if ($set !== '') {
        $keys = array_merge($keys, $s['bar_sets'][$set]['bar'], $s['bar_sets'][$set]['menu']);
    }
    return array('panel' => (bool) $panel, 'set' => $set, 'keys' => system_monitor_widget_keys($keys));
}

// ---- Values ----------------------------------------------------------------------------------

// Sample row with the combined values the sparklines use
function system_monitor_widget_flat(array $r)
{
    $r['rps'] = $r['ng_rps'] ?? ($r['fpm_rps'] ?? null);
    $r['net'] = isset($r['net_rx'], $r['net_tx']) ? $r['net_rx'] + $r['net_tx'] : null;
    $r['io'] = isset($r['dk_r'], $r['dk_w']) ? $r['dk_r'] + $r['dk_w'] : null;
    return $r;
}

// Downloads and uploads since midnight (cached for a minute)
function system_monitor_widget_today()
{
    return system_monitor_cached('widget_today', 60, function () {
        $out = array('dl' => null, 'up' => null);
        try {
            list($gf, $gp) = system_monitor_group_filter('usergroup');
            $out['dl'] = (int) ps_value("SELECT COALESCE(SUM(`count`), 0) value FROM daily_stat
                WHERE activity_type = 'Resource download' AND `year` = ? AND `month` = ? AND `day` = ?" . $gf,
                array_merge(array('i', (int) date('Y'), 'i', (int) date('n'), 'i', (int) date('j')), $gp), 0);
            list($uf, $up) = system_monitor_group_filter('u.usergroup');
            $join = $uf !== '' ? ' LEFT JOIN user u ON u.ref = r.created_by' : '';
            $out['up'] = (int) ps_value('SELECT COUNT(*) value FROM resource r' . $join . ' WHERE r.ref > 0 AND r.creation_date >= CURDATE()' . $uf,
                $up, 0);
        } catch (Throwable $e) {
            // keep nulls
        }
        return $out;
    });
}

// Sparklines: the last 30 minute samples, read again only when a new sample was stored
function system_monitor_widget_sparks()
{
    $state = system_monitor_state_file();
    $mtime = is_file($state) ? (int) filemtime($state) : 0;
    $file = system_monitor_data_dir() . '/cache/widget_spark.json';
    $cached = system_monitor_read_json($file, null);
    if (is_array($cached) && ($cached['m'] ?? -1) === $mtime && is_array($cached['s'] ?? null)) {
        return $cached['s'];
    }
    $keys = array_filter(array_column(system_monitor_widget_catalog(), 'spark'));
    $rows = array_slice(system_monitor_minute_rows(time() - 1800, time()), -30);
    $out = array();
    foreach ($rows as $r) {
        $r = system_monitor_widget_flat($r);
        foreach ($keys as $k) {
            $out[$k][] = isset($r[$k]) ? round((float) $r[$k], 2) : null;
        }
    }
    system_monitor_write_json($file, array('m' => $mtime, 's' => $out));
    return $out;
}

// Shared snapshot for all widget users: [t, v => key => [t => text, s => 0|1|2|null, p => 0-100|null, x => detail], spark]
function system_monitor_widget_values()
{
    $dir = system_monitor_data_dir();
    $file = $dir . '/widget.json';
    $fresh = function ($d) {
        return is_array($d) && isset($d['t']) && microtime(true) - $d['t'] < SYSTEM_MONITOR_WIDGET_TTL;
    };
    $cached = system_monitor_read_json($file, null);
    if ($fresh($cached)) {
        return $cached;
    }
    $empty = array('t' => microtime(true), 'v' => array(), 'spark' => array());
    if (!system_monitor_mkdir($dir) || !is_writable($dir)) {
        return is_array($cached) ? $cached : $empty;
    }
    // one request computes, the others reuse the previous snapshot (or wait for the first one)
    $fp = fopen($dir . '/widget.lock', 'c');
    if ($fp === false) {
        return is_array($cached) ? $cached : $empty;
    }
    try {
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            if (is_array($cached)) {
                return $cached;
            }
            flock($fp, LOCK_EX);
        }
        $cached = system_monitor_read_json($file, null);
        if ($fresh($cached)) {
            return $cached;
        }
        $data = system_monitor_widget_compute();
        system_monitor_write_json($file, $data);
        return $data;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function system_monitor_widget_compute()
{
    require_once __DIR__ . '/collect.php';
    $state = system_monitor_data_dir() . '/widget_state.json';
    $prev = system_monitor_read_json($state, null);
    if (!is_array($prev) || !isset($prev['t']) || microtime(true) - $prev['t'] > 300) {
        $prev = system_monitor_raw_for_state(system_monitor_snapshot());
        usleep(300000);
    }
    $snap = system_monitor_snapshot();
    system_monitor_write_json($state, system_monitor_raw_for_state($snap));
    $s = system_monitor_widget_flat(system_monitor_rates($prev, $snap));
    $today = system_monitor_widget_today();
    $settings = system_monitor_settings();

    $st = function ($v, $warn, $crit) {
        return $v === null ? null : ($v >= $crit ? 2 : ($v >= $warn ? 1 : 0));
    };
    $pct = function ($v) {
        return $v === null ? '–' : number_format((float) $v, 0) . '%';
    };
    $n = function ($v, $d = 0) {
        return $v === null ? '–' : number_format((float) $v, $d);
    };
    $rate = function ($v) {
        return $v === null ? '–' : system_monitor_bytes($v) . '/s';
    };
    $cores = max(1, (int) ($s['cores'] ?? 1));
    $lpc = $s['load1'] === null ? null : $s['load1'] / $cores;

    $v = array();
    $v['cpu'] = array('t' => $pct($s['cpu']), 's' => $st($s['cpu'], 75, 90), 'p' => $s['cpu'], 'x' => $cores . ' cores' . ($s['iow'] ? ', I/O wait ' . $pct($s['iow']) : ''));
    $v['mem'] = array('t' => $pct($s['mem']), 's' => $st($s['mem'], 85, 95), 'p' => $s['mem'],
        'x' => $s['mem_total'] ? system_monitor_bytes($s['mem_used']) . ' of ' . system_monitor_bytes($s['mem_total']) : '');
    $v['load'] = array('t' => $n($s['load1'], 2), 's' => $st($lpc, 1, 2), 'p' => $lpc === null ? null : min(100, 50 * $lpc),
        'x' => $lpc === null ? '' : $n($lpc, 2) . ' per core, 5 min ' . $n($s['load5'], 2));
    $v['swap'] = array('t' => $pct($s['swap']), 's' => $st($s['swap'], 50, 80), 'p' => $s['swap'], 'x' => '');
    $v['disk'] = array('t' => $pct($s['disk_pct']), 's' => $st($s['disk_pct'], 85, 95), 'p' => $s['disk_pct'],
        'x' => $s['disk_free'] !== null ? system_monitor_bytes($s['disk_free']) . ' free' : '');
    $v['fpm'] = array('t' => $s['fpm_act'] === null ? '–' : $s['fpm_act'] . ' / ' . $s['fpm_tot'], 's' => $st($s['fpm_q'], 1, 10),
        'p' => $s['fpm_tot'] ? 100 * $s['fpm_act'] / $s['fpm_tot'] : null, 'x' => $s['fpm_q'] === null ? '' : 'queue ' . $s['fpm_q']);
    $v['rps'] = array('t' => $s['rps'] === null ? '–' : $n($s['rps'], 1) . '/s', 's' => null, 'p' => null, 'x' => '');
    $v['conn'] = array('t' => $n($s['ng_conn']), 's' => null, 'p' => null,
        'x' => $s['ng_conn'] === null ? '' : 'writing ' . $s['ng_write'] . ', waiting ' . $s['ng_wait']);
    $v['db'] = array('t' => $s['db_qps'] === null ? '–' : $n($s['db_qps']) . ' q/s', 's' => $st($s['db_run'], 20, 50), 'p' => null,
        'x' => $s['db_conn'] === null ? '' : $s['db_conn'] . ' connections, ' . $s['db_run'] . ' running');
    $v['net'] = array('t' => $rate($s['net']), 's' => null, 'p' => null,
        'x' => $s['net'] === null ? '' : 'in ' . $rate($s['net_rx']) . ', out ' . $rate($s['net_tx']));
    $v['io'] = array('t' => $rate($s['io']), 's' => null, 'p' => null,
        'x' => $s['io'] === null ? '' : 'read ' . $rate($s['dk_r']) . ', write ' . $rate($s['dk_w']));
    $v['media'] = array('t' => $n($s['media_n']), 's' => null, 'p' => null,
        'x' => $s['media_cpu'] === null ? '' : 'CPU ' . $pct($s['media_cpu']));
    $v['users'] = array('t' => $n($s['u5']), 's' => null, 'p' => null, 'x' => $s['ua'] === null ? '' : $s['ua'] . ' in ' . $settings['active_minutes'] . ' min');
    $v['active'] = array('t' => $n($s['ua']), 's' => null, 'p' => null, 'x' => 'last ' . $settings['active_minutes'] . ' min');
    $v['jobs'] = array('t' => $n($s['jobs']), 's' => $s['jobs'] === null ? null : max($st($s['jobs'], 50, 500), $st($s['jobs_err'], 1, 10)),
        'p' => null, 'x' => $s['jobs_err'] === null ? '' : $s['jobs_err'] . ' failed');
    $v['dl'] = array('t' => $n($today['dl']), 's' => null, 'p' => null, 'x' => 'since midnight');
    $v['up'] = array('t' => $n($today['up']), 's' => null, 'p' => null, 'x' => 'since midnight');

    // overall state: the worst of the checked values, with the reasons
    $worst = null;
    $why = array();
    foreach (array('cpu', 'mem', 'load', 'swap', 'disk', 'fpm') as $k) {
        if ($v[$k]['s'] === null) {
            continue;
        }
        $worst = max((int) $worst, $v[$k]['s']);
        if ($v[$k]['s'] > 0) {
            $why[] = system_monitor_widget_catalog()[$k]['label'] . ' ' . $v[$k]['t'];
        }
    }
    $v['health'] = array('t' => $worst === null ? 'Unknown' : array('OK', 'Busy', 'Overloaded')[$worst], 's' => $worst, 'p' => null,
        'x' => $why ? implode(', ', $why) : ($worst === null ? 'no server metrics' : 'all values normal'));

    // sparklines: minute history + the current value
    $spark = system_monitor_widget_sparks();
    foreach (system_monitor_widget_catalog() as $k => $c) {
        if ($c['spark'] !== null) {
            $line = $spark[$c['spark']] ?? array();
            $line[] = isset($s[$c['spark']]) ? round((float) $s[$c['spark']], 2) : null;
            $spark[$c['spark']] = $line;
        }
    }
    foreach ($v as $k => $row) {
        $v[$k]['p'] = $row['p'] === null ? null : round(max(0, min(100, (float) $row['p'])), 1);
    }
    return array('t' => microtime(true), 'v' => $v, 'spark' => $spark);
}

// Values the current user may see (pages/widget.php)
function system_monitor_widget_output(array $access)
{
    $data = system_monitor_widget_values();
    $cat = system_monitor_widget_catalog();
    $out = array('t' => (int) round($data['t'] ?? time()), 'v' => array(), 'spark' => array());
    foreach ($access['keys'] as $k) {
        if (isset($data['v'][$k])) {
            $out['v'][$k] = $data['v'][$k];
        }
        $sk = $cat[$k]['spark'];
        if ($sk !== null && isset($data['spark'][$sk])) {
            $out['spark'][$k] = $data['spark'][$sk];
        }
    }
    return $out;
}

// Configuration for assets/widget.js (labels only for the metrics this user may see)
function system_monitor_widget_config(array $access)
{
    $s = system_monitor_settings();
    $cat = system_monitor_widget_catalog();
    $labels = array();
    foreach ($access['keys'] as $k) {
        $labels[$k] = array($cat[$k]['label'], $cat[$k]['short'], $cat[$k]['help']);
    }
    $set = $access['set'] !== '' ? $s['bar_sets'][$access['set']] : null;
    return array(
        'url'      => get_plugin_path('system_monitor', true) . '/pages/widget.php',
        'labels'   => $labels,
        'panel'    => $access['panel'] ? array('metrics' => $s['panel_metrics'], 'side' => $s['panel_side'], 'open' => $s['panel_open'], 'seconds' => $s['panel_seconds']) : null,
        'bar'      => $set ? array('bar' => $set['bar'], 'menu' => $set['menu'], 'style' => $set['style'], 'seconds' => $s['bar_seconds']) : null,
        'idle'     => $s['widget_idle'] * 60,
        'adminUrl' => function_exists('checkperm') && checkperm('a') ? get_plugin_path('system_monitor', true) . '/pages/setup.php' : '',
    );
}
