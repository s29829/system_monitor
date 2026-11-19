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
        $new = array(
            'collect_on_requests' => !empty($_POST['collect_on_requests']),
            'interval'            => (int) ($_POST['interval'] ?? 60),
            'minute_days'         => (int) ($_POST['minute_days'] ?? 2),
            'hour_days'           => (int) ($_POST['hour_days'] ?? 400),
            'active_minutes'      => (int) ($_POST['active_minutes'] ?? 15),
            'live_seconds'        => (int) ($_POST['live_seconds'] ?? 3),
            'exclude_groups'      => array_map('intval', (array) ($_POST['exclude_groups'] ?? array())),
            'fpm_status_url'      => trim((string) ($_POST['fpm_status_url'] ?? '')),
            'nginx_status_url'    => trim((string) ($_POST['nginx_status_url'] ?? '')),
        );
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
