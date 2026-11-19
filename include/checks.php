<?php
// System Monitor - configuration check: what is available, what is missing and how to add it.
require_once __DIR__ . '/collect.php';

// List of checks: [status ok|warn|info, title, text (HTML-safe), fix instructions (plain text, shown as code) or '']
function system_monitor_checks()
{
    $s = system_monitor_settings();
    $out = array();
    $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $plugin_dir = dirname(__DIR__);
    $h = function ($t) {
        return htmlspecialchars((string) $t);
    };
    $nginx_block = "# /etc/nginx/conf.d/status-local.conf - status pages only for this server (127.0.0.1)\n"
        . "server {\n"
        . "    listen 127.0.0.1:8099;\n"
        . "    server_name localhost;\n"
        . "    access_log off;\n"
        . "    allow 127.0.0.1;\n"
        . "    deny all;\n\n"
        . "    location = /nginx_status { stub_status; }\n\n"
        . "    location = /fpm-status {\n"
        . "        include fastcgi_params;\n"
        . "        fastcgi_param SCRIPT_NAME     \$uri;\n"
        . "        fastcgi_param SCRIPT_FILENAME \$uri;\n"
        . "        fastcgi_pass unix:/run/php/php$php-fpm.sock;   # the socket of the pool that runs ResourceSpace\n"
        . "    }\n"
        . "}\n\n"
        . "sudo nginx -t && sudo systemctl reload nginx";

    // 1. /proc
    if (PHP_OS_FAMILY !== 'Linux') {
        $out[] = array('warn', 'Server metrics (/proc)', 'The server runs ' . $h(PHP_OS_FAMILY) . ', not Linux: CPU, memory, processes, disk and network are not available. ResourceSpace statistics work.', '');
    } elseif (!system_monitor_proc_available()) {
        $base = (string) ini_get('open_basedir');
        $out[] = array('warn', 'Server metrics (/proc)', 'PHP cannot read /proc/stat' . ($base !== '' ? ' because of open_basedir (' . $h($base) . ')' : '') . ': CPU, memory and processes are not available.',
            $base !== '' ? "; PHP-FPM pool (e.g. /etc/php/$php/fpm/pool.d/www.conf): add /proc to open_basedir\nphp_admin_value[open_basedir] = $base:/proc\nsudo systemctl restart php$php-fpm" : '');
    } else {
        $out[] = array('ok', 'Server metrics (/proc)', 'CPU, memory, load, network and disk I/O are read from /proc.', '');
    }

    // 2. processes of other users (hidepid)
    if (system_monitor_proc_available()) {
        $p = system_monitor_processes();
        $mounts = system_monitor_read('/proc/mounts');
        $hidepid = preg_match('/^proc\s+\/proc\s+proc\s+\S*hidepid=(\d|invisible|noaccess)/m', $mounts) === 1;
        $missing = array();
        foreach (array('fpm' => 'PHP-FPM', 'nginx' => 'nginx', 'db' => 'database') as $k => $label) {
            if ($p['groups'][$k]['n'] === 0) {
                $missing[] = $label;
            }
        }
        if ($hidepid) {
            $out[] = array('warn', 'Processes of other users', '/proc is mounted with <code>hidepid</code>: PHP sees only the processes of its own user, so nginx and the database are not counted.',
                "# allow the web server user to see all processes (group \"proc\" exempt from hidepid)\nsudo groupadd -f proc\nsudo usermod -aG proc www-data\n# /etc/fstab:\nproc /proc proc defaults,hidepid=invisible,gid=proc 0 0\nsudo mount -o remount /proc && sudo systemctl restart php$php-fpm");
        } elseif ($missing) {
            $out[] = array('info', 'Processes', 'Not found: ' . $h(implode(', ', $missing)) . '. That is normal when the service runs on another server (e.g. the database on its own host); its processes cannot be measured from here.', '');
        } else {
            $out[] = array('ok', 'Processes', 'PHP-FPM, nginx and the database are visible (' . (int) $p['visible'] . ' processes on the server).', '');
        }
    }

    // 3. PHP-FPM status in the web request
    $fpm = system_monitor_fpm_status(false);
    if ($fpm) {
        $cfg = system_monitor_fpm_pool_config($fpm['pool']);
        $max = $cfg['pm.max_children'] ?? '';
        $out[] = array('ok', 'PHP-FPM status (web)', 'Pool <b>' . $h($fpm['pool']) . '</b>, pm = ' . $h($fpm['pm']) . ($max !== '' ? ', pm.max_children = ' . $h($max) : '')
            . ': workers are read with fpm_get_status() - no configuration needed.', '');
        if ($fpm['mcr'] > 0) {
            $out[] = array('warn', 'PHP-FPM: max children reached', 'The pool has reached pm.max_children ' . (int) $fpm['mcr'] . ' time(s) since its start ' . $h(system_monitor_duration($fpm['since'])) . ' ago: requests waited for a free worker. Raise pm.max_children if there is enough memory (about 60-120 MB per ResourceSpace worker).',
                "; " . ($cfg['file'] ?? "/etc/php/$php/fpm/pool.d/www.conf") . "\npm.max_children = " . max(10, (int) $max * 2) . "\nsudo systemctl restart php$php-fpm");
        }
    } else {
        $out[] = array('info', 'PHP-FPM status (web)', 'PHP does not run as PHP-FPM (or fpm_get_status() is not available): worker numbers come only from the status page below.', '');
    }

    // 4. PHP-FPM status page (needed by the cron collector, which runs PHP CLI)
    $fpm_page = $s['fpm_status_url'] !== '' ? system_monitor_http_get($s['fpm_status_url'] . '?json') : null;
    $fix_fpm = "; 1. PHP-FPM pool (e.g. /etc/php/$php/fpm/pool.d/www.conf):\npm.status_path = /fpm-status\nsudo systemctl restart php$php-fpm\n\n# 2. nginx: a local server block (the same file as for stub_status):\n" . $nginx_block
        . "\n\n# 3. test, then enter the URL in Settings > PHP-FPM status URL:\ncurl -s 'http://127.0.0.1:8099/fpm-status?json'";
    if ($s['fpm_status_url'] === '') {
        $out[] = array($fpm ? 'info' : 'warn', 'PHP-FPM status page (pm.status_path)', 'Not configured. ' . ($fpm ? 'Web samples do not need it, but the cron collector (PHP CLI) cannot read the workers without it.' : 'Without it there are no worker numbers.'), $fix_fpm);
    } elseif ($fpm_page === null || json_decode($fpm_page, true) === null) {
        $out[] = array('warn', 'PHP-FPM status page (pm.status_path)', 'No valid answer from <code>' . $h($s['fpm_status_url']) . '?json</code>' . (function_exists('curl_init') ? '' : ' (the PHP curl extension is missing)') . '.', $fix_fpm);
    } else {
        $out[] = array('ok', 'PHP-FPM status page (pm.status_path)', 'Answers at <code>' . $h($s['fpm_status_url']) . '</code>.', '');
    }

    // 5. nginx stub_status
    $fix_ng = "# 1. is the module built in? (Ubuntu packages: yes)\nnginx -V 2>&1 | grep -o with-http_stub_status_module\n\n# 2. local server block:\n" . $nginx_block
        . "\n\n# 3. test, then enter the URL in Settings > nginx stub_status URL:\ncurl -s http://127.0.0.1:8099/nginx_status";
    if ($s['nginx_status_url'] === '') {
        $out[] = array('warn', 'nginx stub_status', 'Not configured: no numbers of connections and requests per second.', $fix_ng);
    } elseif (system_monitor_nginx_status() === null) {
        $out[] = array('warn', 'nginx stub_status', 'No valid answer from <code>' . $h($s['nginx_status_url']) . '</code>' . (function_exists('curl_init') ? '' : ' (the PHP curl extension is missing)') . '.', $fix_ng);
    } else {
        $out[] = array('ok', 'nginx stub_status', 'Answers at <code>' . $h($s['nginx_status_url']) . '</code>.', '');
    }

    // 6. database
    $db = system_monitor_db_status();
    $vars = system_monitor_db_variables();
    if ($db) {
        $local = in_array(strtolower((string) ($GLOBALS['mysql_server'] ?? 'localhost')), array('localhost', '127.0.0.1', '::1', ''), true);
        $out[] = array('ok', 'Database status', $h(trim(($vars['version_comment'] ?? '') . ' ' . ($vars['version'] ?? ''))) . ', max_connections = ' . $h($vars['max_connections'] ?? '?')
            . ', highest use ' . (int) ($db['Max_used_connections'] ?? 0) . ($local ? '' : '. The database runs on another host: its CPU and memory are not measured here.'), '');
        if (isset($vars['max_connections']) && (int) $vars['max_connections'] > 0 && ($db['Max_used_connections'] ?? 0) >= 0.9 * (int) $vars['max_connections']) {
            $out[] = array('warn', 'Database connections', 'The highest number of connections has come close to max_connections.', "# /etc/mysql/mariadb.conf.d/50-server.cnf (or mysql.conf.d/mysqld.cnf), section [mysqld]\nmax_connections = " . ((int) $vars['max_connections'] * 2) . "\nsudo systemctl restart mariadb   # or mysql");
        }
    } else {
        $out[] = array('warn', 'Database status', 'SHOW GLOBAL STATUS did not answer: no database counters.', '');
    }

    // 7. data folder
    $dir = system_monitor_data_dir();
    if (system_monitor_mkdir($dir) && is_writable($dir)) {
        $out[] = array('ok', 'Data folder', 'History is stored in <code>' . $h($dir) . '</code>.', '');
    } else {
        $out[] = array('warn', 'Data folder', 'The web server cannot write to <code>' . $h($dir) . '</code>: no history is stored.', "sudo install -d -o www-data -g www-data -m 755 " . system_monitor_base_dir());
    }

    // 8. collector
    $info = system_monitor_history_info();
    $cron = "# /etc/cron.d/system_monitor - one sample per minute, also at night\n* * * * * www-data php $plugin_dir/cli/collect.php > /dev/null 2>&1";
    if ($info['last_cli'] > time() - 600) {
        $out[] = array('ok', 'Cron collector', 'Last cron sample ' . $h(system_monitor_duration(time() - $info['last_cli'])) . ' ago: even samples day and night.', '');
    } elseif ($info['last_cli'] > 0) {
        $out[] = array('warn', 'Cron collector', 'The last cron sample is ' . $h(system_monitor_duration(time() - $info['last_cli'])) . ' old: is the cron entry still there?', $cron);
    } else {
        $out[] = array('info', 'Cron collector (optional)', $s['collect_on_requests']
            ? 'Samples are taken during page requests (at most one per interval), so there are gaps while nobody uses ResourceSpace (e.g. at night). A cron entry gives even samples:'
            : 'Samples during page requests are switched off and there is no cron entry: <b>no history is collected</b>.', $cron);
    }

    // 9. OPcache
    $op = system_monitor_opcache();
    if ($op === null || empty($op['enabled'])) {
        $out[] = array('warn', 'OPcache', 'OPcache is off: every request compiles the PHP code again (much slower).', "; /etc/php/$php/fpm/conf.d/10-opcache.ini\nopcache.enable=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\nsudo systemctl restart php$php-fpm");
    } elseif ($op['full'] || $op['mem_pct'] > 95) {
        $out[] = array('warn', 'OPcache', 'The OPcache memory is full (' . $h($op['mem_pct']) . '%): raise opcache.memory_consumption.', "; /etc/php/$php/fpm/conf.d/10-opcache.ini\nopcache.memory_consumption=" . max(256, (int) round($op['mem'] / 1048576 * 2)) . "\nsudo systemctl restart php$php-fpm");
    } else {
        $out[] = array('ok', 'OPcache', 'On, hit rate ' . $h($op['hit_rate']) . '%, memory ' . $h($op['mem_pct']) . '% used.', '');
    }

    // 10. curl (status pages)
    if (!function_exists('curl_init') && ($s['fpm_status_url'] !== '' || $s['nginx_status_url'] !== '')) {
        $out[] = array('warn', 'PHP curl', 'The status pages are read with curl, which is missing.', "sudo apt-get install php$php-curl && sudo systemctl restart php$php-fpm");
    }
    return $out;
}

// Versions and limits (informational table)
function system_monitor_versions()
{
    global $productversion;
    $vars = system_monitor_db_variables();
    $rows = array(
        'ResourceSpace'       => (string) ($productversion ?? ''),
        'PHP'                 => PHP_VERSION . ' (' . PHP_SAPI . ')',
        'Database'            => trim(($vars['version_comment'] ?? '') . ' ' . ($vars['version'] ?? '')),
        'InnoDB buffer pool'  => isset($vars['innodb_buffer_pool_size']) ? system_monitor_bytes($vars['innodb_buffer_pool_size']) : '',
        'Web server'          => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'memory_limit'        => (string) ini_get('memory_limit'),
        'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
        'post_max_size'       => (string) ini_get('post_max_size'),
        'max_execution_time'  => (string) ini_get('max_execution_time'),
        'ImageMagick path'    => (string) ($GLOBALS['imagemagick_path'] ?? ''),
        'ffmpeg path'         => (string) ($GLOBALS['ffmpeg_path'] ?? ''),
        'Ghostscript path'    => (string) ($GLOBALS['ghostscript_path'] ?? ''),
    );
    $up = system_monitor_uptime();
    if ($up !== null) {
        $rows['Server uptime'] = system_monitor_duration($up);
    }
    return array_filter($rows, 'strlen');
}
