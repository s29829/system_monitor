<?php
// System Monitor - ResourceSpace statistics (users, resources, downloads, uploads, jobs).
// Sources: daily_stat (whole history, also from before the plugin was installed), resource_log,
// resource, user, job_queue, search_log, activity_log, external_access_keys.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/collect.php';

function system_monitor_q($sql, array $params = array())
{
    try {
        $r = ps_query($sql, $params, '', -1, false, 0);
        return is_array($r) ? $r : array();
    } catch (Throwable $e) {
        return array();
    }
}

// Users active within the configured number of minutes (newest first)
function system_monitor_active_users()
{
    $s = system_monitor_settings();
    list($gf, $gp) = system_monitor_group_filter('u.usergroup');
    return system_monitor_q(
        'SELECT u.ref, u.username, u.fullname, g.name groupname, u.last_active,
                ROUND((UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(u.last_active)) / 60) mins
           FROM user u LEFT JOIN usergroup g ON g.ref = u.usergroup
          WHERE u.approved = 1 AND u.last_active > NOW() - INTERVAL ? MINUTE' . $gf . '
          ORDER BY u.last_active DESC LIMIT 200',
        array_merge(array('i', (int) $s['active_minutes']), $gp)
    );
}

function system_monitor_type_name($name)
{
    return function_exists('lang_or_i18n_get_translated') ? lang_or_i18n_get_translated($name, 'resourcetype-') : $name;
}

function system_monitor_state_name($state)
{
    global $lang;
    return $lang['status' . (int) $state] ?? ('State ' . (int) $state);
}

// Counts that change slowly (cached for 5 minutes)
function system_monitor_rs_overview()
{
    return system_monitor_cached('overview', 300, function () {
        $types = array();
        foreach (system_monitor_q('SELECT r.resource_type t, rt.name, COUNT(*) n, COALESCE(SUM(r.file_size), 0) size
                                     FROM resource r LEFT JOIN resource_type rt ON rt.ref = r.resource_type
                                    WHERE r.ref > 0 GROUP BY r.resource_type, rt.name ORDER BY n DESC') as $r) {
            $types[] = array('id' => (int) $r['t'], 'name' => system_monitor_type_name((string) ($r['name'] ?? ('Type ' . $r['t']))),
                'n' => (int) $r['n'], 'size' => (float) $r['size']);
        }
        $states = array();
        foreach (system_monitor_q('SELECT archive, COUNT(*) n FROM resource WHERE ref > 0 GROUP BY archive ORDER BY archive') as $r) {
            $states[] = array('id' => (int) $r['archive'], 'name' => system_monitor_state_name($r['archive']), 'n' => (int) $r['n']);
        }
        $one = function ($sql, $params = array()) {
            $r = system_monitor_q($sql, $params);
            return $r ? array_change_key_case($r[0], CASE_LOWER) : array();
        };
        $res = $one("SELECT COUNT(*) n, COALESCE(SUM(file_size), 0) size,
                            SUM(has_image = 0 AND file_extension IS NOT NULL AND file_extension <> '') nopreview
                       FROM resource WHERE ref > 0 AND archive >= 0 AND archive < 3");
        $users = $one('SELECT COUNT(*) n, SUM(approved = 1) approved,
                              SUM(last_active > NOW() - INTERVAL 30 DAY) active30 FROM user');
        $coll = $one('SELECT COUNT(*) n FROM collection');
        $shares = $one('SELECT COUNT(DISTINCT access_key) n,
                               COUNT(DISTINCT CASE WHEN expires IS NULL THEN access_key END) noexpiry
                          FROM external_access_keys WHERE expires IS NULL OR expires > NOW()');
        $jobs = array();
        foreach (system_monitor_q('SELECT status, COUNT(*) n, MIN(start_date) oldest FROM job_queue GROUP BY status') as $r) {
            $jobs[(int) $r['status']] = array('n' => (int) $r['n'], 'oldest' => $r['oldest']);
        }
        $failed = system_monitor_q('SELECT ref, type, start_date, failure_text FROM job_queue WHERE status = 5 ORDER BY ref DESC LIMIT 5');
        return array(
            'types'     => $types,
            'states'    => $states,
            'resources' => (int) ($res['n'] ?? 0),
            'size'      => (float) ($res['size'] ?? 0),
            'nopreview' => (int) ($res['nopreview'] ?? 0),
            'users'     => (int) ($users['n'] ?? 0),
            'approved'  => (int) ($users['approved'] ?? 0),
            'active30'  => (int) ($users['active30'] ?? 0),
            'collections' => (int) ($coll['n'] ?? 0),
            'shares'    => (int) ($shares['n'] ?? 0),
            'shares_noexpiry' => (int) ($shares['noexpiry'] ?? 0),
            'jobs'      => $jobs,
            'failed'    => $failed,
            'time'      => time(),
        );
    });
}

// Database size and largest tables (cached for 1 hour)
function system_monitor_db_size()
{
    return system_monitor_cached('dbsize', 3600, function () {
        $tables = system_monitor_q('SELECT table_name, table_rows, data_length + index_length size
                                      FROM information_schema.TABLES WHERE table_schema = DATABASE()
                                     ORDER BY size DESC');
        $total = 0;
        $top = array();
        foreach ($tables as $i => $t) {
            $t = array_change_key_case($t, CASE_LOWER);
            $total += (float) $t['size'];
            if ($i < 8) {
                $top[] = array('name' => $t['table_name'], 'rows' => (int) $t['table_rows'], 'size' => (float) $t['size']);
            }
        }
        return array('total' => $total, 'top' => $top);
    });
}

// Time buckets of a scale: [keys, labels as Unix time, SQL date from]
function system_monitor_buckets($scale)
{
    $keys = array();
    $ts = array();
    if ($scale === 'hour') {
        $start = strtotime(date('Y-m-d H:00:00')) - 47 * 3600;
        for ($i = 0; $i < 48; $i++) {
            $t = $start + $i * 3600;
            $keys[] = date('Y-m-d H', $t);
            $ts[] = $t;
        }
    } elseif ($scale === 'month') {
        $start = strtotime(date('Y-m-01') . ' -23 months');
        for ($i = 0; $i < 24; $i++) {
            $t = strtotime('+' . $i . ' months', $start);
            $keys[] = date('Y-m', $t);
            $ts[] = $t;
        }
    } else {
        $start = strtotime(date('Y-m-d')) - 89 * 86400;
        for ($i = 0; $i < 90; $i++) {
            $t = strtotime(date('Y-m-d', $start) . ' +' . $i . ' days');
            $keys[] = date('Y-m-d', $t);
            $ts[] = $t;
        }
    }
    return array($keys, $ts, date('Y-m-d H:i:s', $start));
}

// Activity over time: scale hour (48 h), day (90 days) or month (24 months)
function system_monitor_rs_series($scale)
{
    if (!in_array($scale, array('hour', 'day', 'month'), true)) {
        $scale = 'day';
    }
    $groups = implode(',', system_monitor_settings()['exclude_groups']);
    return system_monitor_cached('series_' . $scale . '_' . md5($groups), $scale === 'hour' ? 120 : 900, function () use ($scale) {
        list($keys, $ts, $from) = system_monitor_buckets($scale);
        $idx = array_flip($keys);
        $series = array();
        $put = function ($name, $key, $value) use (&$series, $idx, $keys) {
            if (!isset($series[$name])) {
                $series[$name] = array_fill(0, count($keys), 0);
            }
            if (isset($idx[$key])) {
                $series[$name][$idx[$key]] += $value;
            }
        };
        $fmt = $scale === 'hour' ? '%Y-%m-%d %H' : ($scale === 'month' ? '%Y-%m' : '%Y-%m-%d');

        // uploads (count and size) from the resources themselves
        list($gf, $gp) = system_monitor_group_filter('u.usergroup');
        $join = $gf !== '' ? ' LEFT JOIN user u ON u.ref = r.created_by' : '';
        foreach (system_monitor_q("SELECT DATE_FORMAT(r.creation_date, '$fmt') k, COUNT(*) n, COALESCE(SUM(r.file_size), 0) size
                                     FROM resource r$join WHERE r.ref > 0 AND r.creation_date >= ?$gf GROUP BY k",
            array_merge(array('s', $from), $gp)) as $r) {
            $put('uploads', $r['k'], (int) $r['n']);
            $put('upload_bytes', $r['k'], (float) $r['size']);
        }

        if ($scale === 'hour') {
            // downloads and searches per hour from the logs
            $join = $gf !== '' ? ' LEFT JOIN user u ON u.ref = l.user' : '';
            foreach (system_monitor_q("SELECT DATE_FORMAT(l.date, '%Y-%m-%d %H') k, COUNT(*) n FROM resource_log l$join
                                        WHERE l.date >= ? AND l.type = 'd'$gf GROUP BY k", array_merge(array('s', $from), $gp)) as $r) {
                $put('downloads', $r['k'], (int) $r['n']);
            }
            $join = $gf !== '' ? ' LEFT JOIN user u ON u.ref = s.user' : '';
            foreach (system_monitor_q("SELECT DATE_FORMAT(s.logged, '%Y-%m-%d %H') k, COUNT(*) n FROM search_log s$join
                                        WHERE s.logged >= ?$gf GROUP BY k", array_merge(array('s', $from), $gp)) as $r) {
                $put('searches', $r['k'], (int) $r['n']);
            }
            // active users per hour: maximum of the samples (only since the plugin collects)
            $series['users'] = array_fill(0, count($keys), null);
            foreach (system_monitor_minute_rows(strtotime($from), time()) as $r) {
                $k = date('Y-m-d H', $r['t']);
                if (isset($idx[$k]) && isset($r['ua'])) {
                    $series['users'][$idx[$k]] = max((int) $series['users'][$idx[$k]], (int) $r['ua']);
                }
            }
        } else {
            // daily_stat: one row per day, user group, activity and object
            list($sf, $sp) = system_monitor_group_filter('usergroup');
            $y = (int) substr($from, 0, 4);
            $map = array('Resource download' => 'downloads', 'Downloaded KB' => 'download_kb', 'Resource view' => 'views', 'Search' => 'searches');
            $group = $scale === 'month' ? '`year`, `month`' : '`year`, `month`, `day`';
            foreach (system_monitor_q("SELECT `year` y, `month` m, " . ($scale === 'month' ? '1' : '`day`') . " d, activity_type, SUM(`count`) c
                                         FROM daily_stat WHERE `year` >= ? AND activity_type IN ('Resource download','Downloaded KB','Resource view','Search')$sf
                                        GROUP BY $group, activity_type", array_merge(array('i', $y), $sp)) as $r) {
                $k = $scale === 'month' ? sprintf('%04d-%02d', $r['y'], $r['m']) : sprintf('%04d-%02d-%02d', $r['y'], $r['m'], $r['d']);
                $put($map[$r['activity_type']], $k, (float) $r['c']);
            }
            // unique users ("User session": object_ref = user)
            foreach (system_monitor_q("SELECT `year` y, `month` m, " . ($scale === 'month' ? '1' : '`day`') . " d, COUNT(DISTINCT object_ref) u
                                         FROM daily_stat WHERE `year` >= ? AND activity_type = 'User session'$sf GROUP BY $group",
                array_merge(array('i', $y), $sp)) as $r) {
                $k = $scale === 'month' ? sprintf('%04d-%02d', $r['y'], $r['m']) : sprintf('%04d-%02d-%02d', $r['y'], $r['m'], $r['d']);
                $put('users', $k, (int) $r['u']);
            }
            if (isset($series['download_kb'])) {
                $series['download_bytes'] = array_map(function ($v) {
                    return $v * 1024;
                }, $series['download_kb']);
                unset($series['download_kb']);
            }
            if ($scale === 'day') {
                // logins (successful / failed) of the last 90 days; scan limited to the newest 1,000,000 log entries
                $max = (int) (system_monitor_q('SELECT MAX(ref) m FROM activity_log')[0]['m'] ?? 0);
                foreach (system_monitor_q("SELECT DATE_FORMAT(logged, '%Y-%m-%d') k, log_code c, COUNT(*) n FROM activity_log
                                            WHERE ref > ? AND logged >= ? AND log_code IN ('l','Xl') GROUP BY k, c",
                    array('i', $max - 1000000, 's', $from)) as $r) {
                    $put($r['c'] === 'l' ? 'logins' : 'failed_logins', $r['k'], (int) $r['n']);
                }
            }
        }
        foreach (array('users', 'downloads', 'download_bytes', 'uploads', 'upload_bytes', 'views', 'searches', 'logins', 'failed_logins') as $name) {
            if (!isset($series[$name])) {
                $series[$name] = array_fill(0, count($keys), $scale === 'hour' && in_array($name, array('views', 'download_bytes', 'logins', 'failed_logins'), true) ? null : 0);
            }
        }
        return array('scale' => $scale, 't' => $ts, 'series' => $series, 'time' => time());
    });
}

// Top lists of the last 30 days (cached for 15 minutes)
function system_monitor_rs_top()
{
    $groups = implode(',', system_monitor_settings()['exclude_groups']);
    return system_monitor_cached('top_' . md5($groups), 900, function () {
        $since = date('Y-m-d H:i:s', time() - 30 * 86400);
        list($gf, $gp) = system_monitor_group_filter('u.usergroup');
        list($sf, $sp) = system_monitor_group_filter('usergroup');
        $y = (int) date('Y', time() - 30 * 86400);
        $resources = system_monitor_q("SELECT object_ref ref, SUM(`count`) n FROM daily_stat
                                        WHERE activity_type = 'Resource download' AND `year` >= ?
                                          AND STR_TO_DATE(CONCAT(`year`,'-',`month`,'-',`day`), '%Y-%m-%d') >= DATE(?)$sf
                                        GROUP BY object_ref ORDER BY n DESC LIMIT 10", array_merge(array('i', $y, 's', $since), $sp));
        $downloaders = system_monitor_q("SELECT u.username, u.fullname, COUNT(*) n FROM resource_log l JOIN user u ON u.ref = l.user
                                          WHERE l.date >= ? AND l.type = 'd'$gf GROUP BY l.user, u.username, u.fullname ORDER BY n DESC LIMIT 10",
            array_merge(array('s', $since), $gp));
        $uploaders = system_monitor_q("SELECT u.username, u.fullname, COUNT(*) n, COALESCE(SUM(r.file_size), 0) size FROM resource r JOIN user u ON u.ref = r.created_by
                                        WHERE r.ref > 0 AND r.creation_date >= ?$gf GROUP BY r.created_by, u.username, u.fullname ORDER BY n DESC LIMIT 10",
            array_merge(array('s', $since), $gp));
        $searches = system_monitor_q("SELECT LEFT(search_string, 120) q, COUNT(*) n FROM search_log
                                       WHERE logged >= ? AND search_string <> '' GROUP BY q ORDER BY n DESC LIMIT 10", array('s', $since));
        return array('resources' => $resources, 'downloaders' => $downloaders, 'uploaders' => $uploaders, 'searches' => $searches, 'time' => time());
    });
}
