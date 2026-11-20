<?php
include '../../../include/db.php';
include '../../../include/authenticate.php';
require_once dirname(__FILE__, 2) . '/include/stats.php';
require_once dirname(__FILE__, 2) . '/include/checks.php';

// Admins only
if (!checkperm('a')) {
    exit($lang['error-permissiondenied']);
}

$self        = $_SERVER['PHP_SELF'];
$plugins_url = $baseurl_short . 'pages/team/team_plugins.php';
$action      = (string) ($_POST['action'] ?? '');
$reset_open  = false;
$message     = '';
$errors      = array();
$settings    = system_monitor_settings();

// ---- Handle form actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    if (function_exists('enforcePostRequest') && !enforcePostRequest(false)) {
        $errors[] = 'The request could not be verified. Reload the page and try again.';
    } elseif ($action === 'save') {
        $new = array_merge($settings, array(
            'collect_on_requests' => !empty($_POST['collect_on_requests']),
            'interval'            => (int) ($_POST['interval'] ?? 60),
            'minute_days'         => (int) ($_POST['minute_days'] ?? 2),
            'hour_days'           => (int) ($_POST['hour_days'] ?? 400),
            'active_minutes'      => (int) ($_POST['active_minutes'] ?? 15),
            'live_seconds'        => (int) ($_POST['live_seconds'] ?? 3),
            'exclude_groups'      => array_map('intval', (array) ($_POST['exclude_groups'] ?? array())),
            'fpm_status_url'      => trim((string) ($_POST['fpm_status_url'] ?? '')),
            'nginx_status_url'    => trim((string) ($_POST['nginx_status_url'] ?? '')),
        ));
        foreach (array('fpm_status_url' => 'PHP-FPM status URL', 'nginx_status_url' => 'nginx stub_status URL') as $k => $label) {
            if ($new[$k] !== '' && !system_monitor_valid_status_url($new[$k])) {
                $errors[] = 'Not saved: ' . $label . ' must be an http(s) address on this server (127.0.0.1, localhost or ::1).';
            }
        }
        if (!$errors) {
            if (system_monitor_save_settings($new)) {
                system_monitor_clear_cache();
                redirect($self . '?done=saved#settings');
                exit;
            }
            $errors[] = 'The settings could not be stored (check write access to ' . system_monitor_data_dir() . ').';
        }
    } elseif ($action === 'save_widgets') {
        // side panel and header bar (since 1.01); unknown keys and values are dropped by system_monitor_widget_clean()
        $str  = function ($v) { return is_scalar($v) ? (string) $v : ''; };
        $list = function ($v) use ($str) { return array_map($str, is_array($v) ? $v : array()); };
        $sets = array();
        $posted_sets = is_array($_POST['bar_sets'] ?? null) ? $_POST['bar_sets'] : array();
        foreach (array_keys(system_monitor_bar_set_defaults()) as $k) {
            $in = is_array($posted_sets[$k] ?? null) ? $posted_sets[$k] : array();
            $sets[$k] = array('bar' => $list($in['bar'] ?? null), 'menu' => $list($in['menu'] ?? null), 'style' => $str($in['style'] ?? ''));
        }
        $bar_groups = array();
        foreach ((is_array($_POST['bar_groups'] ?? null) ? $_POST['bar_groups'] : array()) as $g => $set) {
            if ($str($set) !== '') {
                $bar_groups[(string) (int) $g] = $str($set);
            }
        }
        $new = array_merge($settings, array(
            'panel_groups'  => array_map('intval', $list($_POST['panel_groups'] ?? null)),
            'panel_users'   => system_monitor_lines($str($_POST['panel_users'] ?? '')),
            'panel_metrics' => $list($_POST['panel_metrics'] ?? null),
            'panel_side'    => $str($_POST['panel_side'] ?? 'right'),
            'panel_open'    => !empty($_POST['panel_open']),
            'panel_seconds' => (int) $str($_POST['panel_seconds'] ?? 10),
            'bar_groups'    => $bar_groups,
            'bar_sets'      => $sets,
            'bar_seconds'   => (int) $str($_POST['bar_seconds'] ?? 30),
            'widget_idle'   => (int) $str($_POST['widget_idle'] ?? 10),
        ));
        if (system_monitor_save_settings($new)) {
            log_activity('System Monitor: widget settings changed', LOG_CODE_SYSTEM, null, null, null, null, null, null, $userref);
            redirect($self . '?done=widgets#widgets');
            exit;
        }
        $errors[] = 'The settings could not be stored (check write access to ' . system_monitor_data_dir() . ').';
    } elseif ($action === 'sample') {
        system_monitor_collect('web', true);
        redirect($self . '?done=sample#check');
        exit;
    } elseif ($action === 'clear_history') {
        $ok = true;
        foreach (array('minute', 'hour', 'cache') as $sub) {
            $ok = system_monitor_rrmdir(system_monitor_data_dir() . '/' . $sub) && $ok;
        }
        if (is_file(system_monitor_state_file())) {
            $ok = unlink(system_monitor_state_file()) && $ok;
        }
        if ($ok) {
            log_activity('System Monitor: history deleted', LOG_CODE_SYSTEM, null, null, null, null, null, null, $userref);
            redirect($self . '?done=cleared#settings');
            exit;
        }
        $errors[] = 'The history could not be deleted completely.';
    } elseif ($action === 'reset') {
        $reset_open = true;
        if (trim((string) ($_POST['reset_confirm'] ?? '')) !== 'RESET') {
            $errors[] = 'Not reset: type RESET (capital letters) to confirm.';
        } elseif (system_monitor_reset()) {
            log_activity('System Monitor: plugin reset', LOG_CODE_SYSTEM, null, null, null, null, null, null, $userref);
            redirect($self . '?done=reset');
            exit;
        } else {
            $errors[] = 'The plugin could not be reset (check permissions of ' . system_monitor_base_dir() . ').';
        }
    }
}

switch ((string) ($_GET['done'] ?? '')) {
    case 'saved':
        $message = 'Settings saved.';
        break;
    case 'widgets':
        $message = 'Widget settings saved. They apply from the next full page load (reload the page).';
        break;
    case 'sample':
        $message = 'A sample was taken now.';
        break;
    case 'cleared':
        $message = 'The history was deleted. The settings were kept.';
        break;
    case 'reset':
        $message = 'The plugin was reset: settings and history were deleted. The default settings apply.';
        break;
}

$h       = function ($s) { return htmlspecialchars((string) $s); };
$nonce   = isset($CSP_nonce) ? ' nonce="' . htmlspecialchars($CSP_nonce) . '"' : '';
$checks  = system_monitor_checks();
$warns   = count(array_filter($checks, function ($c) { return $c[0] === 'warn'; }));
$info    = system_monitor_history_info();
$groups  = system_monitor_q('SELECT ref, name FROM usergroup ORDER BY name');
$wcat     = system_monitor_widget_catalog();
$wsets    = system_monitor_bar_set_defaults();
$wlabels  = system_monitor_bar_set_labels();
$wunknown = array();
if ($settings['panel_users']) {
    $known = array_map('mb_strtolower', array_column(system_monitor_q('SELECT username FROM user WHERE username IN (' . implode(',', array_fill(0, count($settings['panel_users']), '?')) . ')',
        array_merge(...array_map(function ($u) { return array('s', $u); }, $settings['panel_users']))), 'username'));
    foreach ($settings['panel_users'] as $u) {
        if (!in_array(mb_strtolower($u), $known, true)) {
            $wunknown[] = $u;
        }
    }
}
$plugin_url = get_plugin_path('system_monitor', true);
$plugin_dir = dirname(__FILE__, 2);
$asset_ver  = (string) filemtime($plugin_dir . '/assets/monitor.js');
$pgroups = array();
foreach (system_monitor_process_groups() as $k => $g) {
    $pgroups[$k] = $g[0];
}
$js_config = array(
    'dataUrl'     => $plugin_url . '/pages/data.php',
    'liveSeconds' => $settings['live_seconds'],
    'interval'    => $settings['interval'],
    'ranges'      => array_map(function ($r) { return $r[1]; }, system_monitor_ranges()),
    'groups'      => $pgroups,
    'viewUrl'     => $baseurl_short . '?r=',
    'activeMinutes' => $settings['active_minutes'],
);

include '../../../include/header.php';
?>
<link rel="stylesheet" href="<?php echo $h($plugin_url . '/assets/monitor.css?v=' . $asset_ver); ?>">
<div class="BasicsBox sm-page">
    <h1>System Monitor</h1>
    <?php
    renderBreadcrumbs([
        ['title' => $lang['systemsetup'],   'href' => $baseurl_short . 'pages/admin/admin_home.php', 'menu' => true],
        ['title' => $lang['pluginmanager'], 'href' => $plugins_url],
        ['title' => 'System Monitor'],
    ]);
    ?>

    <?php if ($message) { ?><div class="PageInformal"><?php echo $h($message); ?></div><?php } ?>
    <?php foreach ($errors as $e) { ?><div class="FormError"><?php echo $h($e); ?></div><?php } ?>
    <?php if ($warns > 0) { ?>
        <div class="sm-attention"><b><?php echo (int) $warns; ?> configuration item<?php echo $warns > 1 ? 's need' : ' needs'; ?> attention</b> -
            some metrics are missing. <a href="#check" data-sm-goto="check">Open System check</a> for what to add and how.</div>
    <?php } ?>

    <nav class="sm-tabs" role="tablist">
        <button type="button" data-tab="live" role="tab">Live</button>
        <button type="button" data-tab="history" role="tab">Server history</button>
        <button type="button" data-tab="rs" role="tab">ResourceSpace</button>
        <button type="button" data-tab="check" role="tab">System check<?php echo $warns ? ' <span class="sm-count">' . (int) $warns . '</span>' : ''; ?></button>
        <button type="button" data-tab="widgets" role="tab">Widgets</button>
        <button type="button" data-tab="settings" role="tab">Settings</button>
    </nav>

    <!-- LIVE -->
    <section class="sm-tab" id="sm-tab-live" data-tab="live">
        <div class="sm-tiles" id="sm-live-tiles"><p class="sm-muted">Loading…</p></div>
        <div class="sm-grid2">
            <div class="sm-box"><h3>CPU <small>last 10 minutes, % of all cores</small></h3><div class="sm-chart" id="sm-live-cpu"></div></div>
            <div class="sm-box"><h3>Memory <small>last 10 minutes</small></h3><div class="sm-chart" id="sm-live-mem"></div></div>
        </div>
        <div class="sm-grid2">
            <div class="sm-box"><h3>Processes</h3><div id="sm-live-procs"></div>
                <p class="sm-note">CPU = share of the whole server. Memory = resident memory (RSS); PHP-FPM workers share code, so the sum is higher than the real use.</p></div>
            <div class="sm-box"><h3>PHP-FPM, database, OPcache, disks</h3><div id="sm-live-services"></div></div>
        </div>
        <div class="sm-box"><h3>Active users <small id="sm-users-note"></small></h3><div id="sm-live-users"></div></div>
    </section>

    <!-- HISTORY -->
    <section class="sm-tab" id="sm-tab-history" data-tab="history" hidden>
        <div class="sm-toolbar"><span>Range:</span><span class="sm-seg" id="sm-ranges"></span><span class="sm-muted" id="sm-hist-note"></span></div>
        <div class="sm-grid2" id="sm-hist-charts"></div>
    </section>

    <!-- RESOURCESPACE -->
    <section class="sm-tab" id="sm-tab-rs" data-tab="rs" hidden>
        <div class="sm-tiles" id="sm-rs-tiles"><p class="sm-muted">Loading…</p></div>
        <div class="sm-toolbar"><span>Time scale:</span><span class="sm-seg" id="sm-scales">
            <button type="button" data-scale="hour">Hours (48 h)</button><button type="button" data-scale="day">Days (90)</button><button type="button" data-scale="month">Months (24)</button>
        </span><span class="sm-muted" id="sm-rs-note"></span></div>
        <div class="sm-grid2" id="sm-rs-charts"></div>
        <div class="sm-grid2">
            <div class="sm-box"><h3>Resources by type</h3><div id="sm-rs-types"></div></div>
            <div class="sm-box"><h3>Resources by workflow state</h3><div id="sm-rs-states"></div></div>
        </div>
        <div class="sm-grid2">
            <div class="sm-box"><h3>Offline jobs</h3><div id="sm-rs-jobs"></div></div>
            <div class="sm-box"><h3>Database size</h3><div id="sm-rs-db"></div></div>
        </div>
        <div class="sm-grid2">
            <div class="sm-box"><h3>Most downloaded resources <small>30 days</small></h3><div id="sm-top-res"></div></div>
            <div class="sm-box"><h3>Top searches <small>30 days</small></h3><div id="sm-top-search"></div></div>
            <div class="sm-box"><h3>Top downloading users <small>30 days</small></h3><div id="sm-top-down"></div></div>
            <div class="sm-box"><h3>Top uploading users <small>30 days</small></h3><div id="sm-top-up"></div></div>
        </div>
    </section>

    <!-- SYSTEM CHECK -->
    <section class="sm-tab" id="sm-tab-check" data-tab="check" hidden>
        <div class="sm-box">
            <h3>Configuration check</h3>
            <p class="sm-muted">What the plugin can measure on this server, what is missing and how to add it. Commands are for Ubuntu / Debian with nginx and PHP-FPM; check paths on your server before you use them.</p>
            <ul class="sm-checks">
            <?php foreach ($checks as $c) { ?>
                <li class="sm-check sm-<?php echo $h($c[0]); ?>">
                    <span class="sm-badge"><?php echo $c[0] === 'ok' ? 'OK' : ($c[0] === 'warn' ? 'Missing' : 'Info'); ?></span>
                    <div><b><?php echo $h($c[1]); ?></b><div><?php echo $c[2]; /* built with htmlspecialchars in checks.php */ ?></div>
                    <?php if ($c[3] !== '') { ?>
                        <details<?php echo $c[0] === 'warn' ? ' open' : ''; ?>><summary>How to add it</summary><pre class="sm-pre"><?php echo $h($c[3]); ?></pre></details>
                    <?php } ?></div>
                </li>
            <?php } ?>
            </ul>
        </div>
        <div class="sm-grid2">
            <div class="sm-box"><h3>Versions and limits</h3>
                <table class="sm-table"><?php foreach (system_monitor_versions() as $k => $v) { ?><tr><th><?php echo $h($k); ?></th><td><?php echo $h($v); ?></td></tr><?php } ?></table>
            </div>
            <div class="sm-box"><h3>Collected history</h3>
                <table class="sm-table">
                    <tr><th>First sample</th><td><?php echo $info['first'] ? $h(date('Y-m-d H:i', $info['first'])) : 'none yet'; ?></td></tr>
                    <tr><th>Last sample</th><td><?php echo $info['last'] ? $h(date('Y-m-d H:i:s', $info['last'])) . ' (' . $h(system_monitor_duration(time() - $info['last'])) . ' ago)' : 'none yet'; ?></td></tr>
                    <tr><th>Last cron sample</th><td><?php echo $info['last_cli'] ? $h(date('Y-m-d H:i:s', $info['last_cli'])) : 'never (no cron entry)'; ?></td></tr>
                    <tr><th>Stored</th><td><?php echo $h(system_monitor_bytes($info['size'])); ?> in <?php echo (int) $info['files']; ?> files</td></tr>
                </table>
                <form method="post" action="<?php echo $h($self); ?>#check"><?php generateFormToken('system_monitor'); ?>
                    <button type="submit" name="action" value="sample" class="sm-outline">Take a sample now</button></form>
            </div>
        </div>
    </section>

    <!-- WIDGETS (since 1.01) -->
    <section class="sm-tab" id="sm-tab-widgets" data-tab="widgets" hidden>
        <form method="post" action="<?php echo $h($self); ?>#widgets" class="sm-form">
            <?php generateFormToken('system_monitor'); ?>
            <p class="sm-muted">Two widgets on every page of ResourceSpace: a <b>side panel</b> at the edge of the screen for chosen people,
                and a <b>header bar</b> with a few values before the Upload button for whole user groups (click it for more).
                All viewers share one set of values, computed at most every <?php echo (int) SYSTEM_MONITOR_WIDGET_TTL; ?> seconds.
                Colours: green = OK, orange = busy, red = overloaded.</p>

            <div class="sm-box">
                <h3>Side panel <small>floating box over the page, open / closed by the user</small></h3>
                <div class="sm-field"><b>Who sees it - user groups</b>
                    <div class="sm-groups"><?php foreach ($groups as $g) { ?>
                        <label><input type="checkbox" name="panel_groups[]" value="<?php echo (int) $g['ref']; ?>"<?php echo in_array((int) $g['ref'], $settings['panel_groups'], true) ? ' checked' : ''; ?>> <?php echo $h($g['name']); ?></label>
                    <?php } ?></div></div>
                <label><b>… and these users</b> <span class="sm-help">one user name per line</span>
                    <textarea name="panel_users" rows="3" cols="40"><?php echo $h(implode("\n", $settings['panel_users'])); ?></textarea></label>
                <?php if ($wunknown) { ?><p class="sm-warntext">Unknown user names: <?php echo $h(implode(', ', $wunknown)); ?></p><?php } ?>
                <div class="sm-field"><b>Content</b> <span class="sm-help">Start from a preset, then add or remove values.</span>
                    <p><?php foreach (system_monitor_panel_presets() as $pk => $p) { ?>
                        <button type="button" class="sm-outline" data-sm-fill="panel_metrics[]" data-keys="<?php echo $h(json_encode($p['metrics'])); ?>"><?php echo $h($p['label']); ?></button>
                    <?php } ?></p>
                    <div class="sm-groups"><?php foreach ($wcat as $k => $c) { ?>
                        <label title="<?php echo $h($c['help']); ?>"><input type="checkbox" name="panel_metrics[]" value="<?php echo $h($k); ?>"<?php echo in_array($k, $settings['panel_metrics'], true) ? ' checked' : ''; ?>> <?php echo $h($c['label']); ?></label>
                    <?php } ?></div></div>
                <label>Side <select name="panel_side">
                    <option value="right"<?php echo $settings['panel_side'] === 'right' ? ' selected' : ''; ?>>right</option>
                    <option value="left"<?php echo $settings['panel_side'] === 'left' ? ' selected' : ''; ?>>left</option></select></label>
                <label class="sm-checkline"><input type="checkbox" name="panel_open" value="1"<?php echo $settings['panel_open'] ? ' checked' : ''; ?>> Open on the first visit
                    <span class="sm-help">Later the panel remembers the user's last choice (in the browser).</span></label>
                <label>Refresh every <input type="number" name="panel_seconds" min="5" max="120" value="<?php echo (int) $settings['panel_seconds']; ?>"> seconds while the panel is open
                    <span class="sm-help">Closed, the panel only keeps the status colour of its handle up to date (every minute, or with the header bar).</span></label>
            </div>

            <div class="sm-box">
                <h3>Header bar <small>before the Upload button; a click opens a drop-down with more values</small></h3>
                <p class="sm-help">Choose a set for each user group. By default every set shows the current <b>CPU and memory use (%)</b> in the bar;
                    the drop-down differs: <b>Basic</b> - status and counts, <b>Standard</b> - also load, requests and disk, <b>Full</b> - all values.
                    Groups without a set do not see the bar.</p>
                <table class="sm-table"><tr><th>User group</th><th>Set</th></tr>
                <?php foreach ($groups as $g) { $cur = (string) ($settings['bar_groups'][(string) (int) $g['ref']] ?? ''); ?>
                    <tr><td><?php echo $h($g['name']); ?></td><td><select name="bar_groups[<?php echo (int) $g['ref']; ?>]">
                        <option value="">- none -</option>
                        <?php foreach ($wlabels as $sk => $sl) { ?><option value="<?php echo $h($sk); ?>"<?php echo $cur === $sk ? ' selected' : ''; ?>><?php echo $h($sl); ?></option><?php } ?>
                    </select></td></tr>
                <?php } ?></table>

                <div class="sm-grid2">
                <?php foreach ($settings['bar_sets'] as $sk => $set) { $base = 'bar_sets[' . $sk . ']'; ?>
                    <details class="sm-box">
                        <summary><h3><?php echo $h($wlabels[$sk]); ?> <small><?php echo $h(implode(', ', array_map(function ($k) use ($wcat) { return $wcat[$k]['short']; }, $set['bar']))); ?></small></h3></summary>
                        <label>Display <select name="<?php echo $h($base . '[style]'); ?>">
                            <?php foreach (array('number' => 'numbers', 'spark' => 'numbers + small charts (30 min)', 'bar' => 'numbers + load bars') as $st => $stl) { ?>
                                <option value="<?php echo $h($st); ?>"<?php echo $set['style'] === $st ? ' selected' : ''; ?>><?php echo $h($stl); ?></option>
                            <?php } ?></select></label>
                        <div class="sm-field"><b>In the bar</b> <span class="sm-help">at most <?php echo (int) SYSTEM_MONITOR_BAR_MAX; ?> - keep them short</span>
                            <div class="sm-groups"><?php foreach ($wcat as $k => $c) { ?>
                                <label title="<?php echo $h($c['help']); ?>"><input type="checkbox" name="<?php echo $h($base . '[bar][]'); ?>" value="<?php echo $h($k); ?>" data-sm-max="<?php echo (int) SYSTEM_MONITOR_BAR_MAX; ?>"<?php echo in_array($k, $set['bar'], true) ? ' checked' : ''; ?>> <?php echo $h($c['label']); ?></label>
                            <?php } ?></div></div>
                        <div class="sm-field"><b>In the drop-down</b>
                            <div class="sm-groups"><?php foreach ($wcat as $k => $c) { ?>
                                <label title="<?php echo $h($c['help']); ?>"><input type="checkbox" name="<?php echo $h($base . '[menu][]'); ?>" value="<?php echo $h($k); ?>"<?php echo in_array($k, $set['menu'], true) ? ' checked' : ''; ?>> <?php echo $h($c['label']); ?></label>
                            <?php } ?></div></div>
                        <p><button type="button" class="sm-outline" data-sm-fill="<?php echo $h($base . '[bar][]'); ?>" data-keys="<?php echo $h(json_encode($wsets[$sk]['bar'])); ?>"
                                data-style="<?php echo $h($wsets[$sk]['style']); ?>" data-style-name="<?php echo $h($base . '[style]'); ?>">Default bar</button>
                           <button type="button" class="sm-outline" data-sm-fill="<?php echo $h($base . '[menu][]'); ?>" data-keys="<?php echo $h(json_encode($wsets[$sk]['menu'])); ?>">Default drop-down</button></p>
                    </details>
                <?php } ?>
                </div>
                <label>Refresh every <input type="number" name="bar_seconds" min="10" max="300" value="<?php echo (int) $settings['bar_seconds']; ?>"> seconds
                    <span class="sm-help">While the drop-down is open: every 10 seconds at most.</span></label>
            </div>

            <div class="sm-box">
                <h3>Load</h3>
                <label>Stop refreshing after <input type="number" name="widget_idle" min="0" max="240" value="<?php echo (int) $settings['widget_idle']; ?>"> minutes without mouse or keyboard
                    <span class="sm-help">0 = never. Refreshing also stops while the browser tab is hidden. Every refresh is a request to ResourceSpace
                    (and counts as activity of the user, like ResourceSpace's own message check), so do not set very short times for large groups.</span></label>
            </div>
            <p><button type="submit" name="action" value="save_widgets" class="sm-blue">Save widget settings</button></p>
        </form>
    </section>

    <!-- SETTINGS -->
    <section class="sm-tab" id="sm-tab-settings" data-tab="settings" hidden>
        <form method="post" action="<?php echo $h($self); ?>#settings" class="sm-box sm-form">
            <?php generateFormToken('system_monitor'); ?>
            <h3>Collecting</h3>
            <label class="sm-checkline"><input type="checkbox" name="collect_on_requests" value="1"<?php echo $settings['collect_on_requests'] ? ' checked' : ''; ?>>
                <b>Take samples during page requests</b> <span class="sm-help">No cron needed: at most one sample per interval, taken after a page was sent. Without traffic (e.g. at night) there are gaps - the cron collector (System check) fills them.</span></label>
            <label>Sample interval <select name="interval"><?php foreach (SYSTEM_MONITOR_INTERVALS as $i) { ?><option value="<?php echo $i; ?>"<?php echo $settings['interval'] === $i ? ' selected' : ''; ?>><?php echo $i < 60 ? $i . ' s' : ($i / 60) . ' min'; ?></option><?php } ?></select></label>
            <label>Keep minute samples for <input type="number" name="minute_days" min="1" max="14" value="<?php echo (int) $settings['minute_days']; ?>"> days <span class="sm-help">about 1 MB per day at 1 sample per minute</span></label>
            <label>Keep hourly history for <input type="number" name="hour_days" min="30" max="1100" value="<?php echo (int) $settings['hour_days']; ?>"> days</label>
            <label>Live view refresh every <input type="number" name="live_seconds" min="2" max="30" value="<?php echo (int) $settings['live_seconds']; ?>"> seconds</label>

            <h3>ResourceSpace statistics</h3>
            <label>"Active" users = activity within the last <input type="number" name="active_minutes" min="5" max="120" value="<?php echo (int) $settings['active_minutes']; ?>"> minutes</label>
            <div class="sm-field"><b>Leave these user groups out of the statistics</b> <span class="sm-help">e.g. the group of load test accounts, so tests do not change the numbers of real users (does not change ResourceSpace's own statistics).</span>
                <div class="sm-groups"><?php foreach ($groups as $g) { ?>
                    <label><input type="checkbox" name="exclude_groups[]" value="<?php echo (int) $g['ref']; ?>"<?php echo in_array((int) $g['ref'], $settings['exclude_groups'], true) ? ' checked' : ''; ?>> <?php echo $h($g['name']); ?></label>
                <?php } ?></div></div>

            <h3>Status pages <small>(only addresses on this server)</small></h3>
            <label>PHP-FPM status URL <input type="text" name="fpm_status_url" size="45" placeholder="http://127.0.0.1:8099/fpm-status" value="<?php echo $h($settings['fpm_status_url']); ?>"></label>
            <label>nginx stub_status URL <input type="text" name="nginx_status_url" size="45" placeholder="http://127.0.0.1:8099/nginx_status" value="<?php echo $h($settings['nginx_status_url']); ?>"></label>
            <p class="sm-help">How to switch them on: System check.</p>
            <p><button type="submit" name="action" value="save" class="sm-blue">Save settings</button></p>
        </form>

        <div class="sm-grid2">
            <details class="sm-box">
                <summary><h3>Planned (next versions)</h3></summary>
                <?php foreach (system_monitor_roadmap() as $stage => $items) { ?>
                    <p><b><?php echo $h($stage); ?></b></p><ul><?php foreach ($items as $it) { ?><li><?php echo $h($it); ?></li><?php } ?></ul>
                <?php } ?>
            </details>
            <div>
                <details class="sm-box">
                    <summary><h3>Delete history</h3></summary>
                    <p>Deletes the collected samples and hourly history; the settings stay. ResourceSpace's own statistics (daily_stat) are not touched.</p>
                    <form method="post" action="<?php echo $h($self); ?>" id="sm-clear"><?php generateFormToken('system_monitor'); ?>
                        <button type="submit" name="action" value="clear_history" class="sm-outline">Delete history</button></form>
                </details>
                <details class="sm-box"<?php echo $reset_open ? ' open' : ''; ?>>
                    <summary><h3>Reset plugin</h3></summary>
                    <p>Deletes everything the plugin stored in <code><?php echo $h(system_monitor_base_dir()); ?></code>: settings and history. ResourceSpace data is not changed. This cannot be undone.</p>
                    <form method="post" action="<?php echo $h($self); ?>" id="sm-reset"><?php generateFormToken('system_monitor'); ?>
                        <label>To confirm, type <b>RESET</b> <input type="text" name="reset_confirm" autocomplete="off" spellcheck="false"></label>
                        <button type="submit" name="action" value="reset">Reset plugin</button>
                    </form>
                </details>
            </div>
        </div>
    </section>
</div>
<script<?php echo $nonce; ?>>window.SystemMonitorConfig = <?php echo json_encode($js_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;</script>
<script<?php echo $nonce; ?> src="<?php echo $h($plugin_url . '/assets/monitor.js?v=' . $asset_ver); ?>"></script>
<?php
include '../../../include/footer.php';
