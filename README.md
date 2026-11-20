# System Monitor – documentation

**English** | [Polski](README.pl.md)

Documentation for plugin version **1.01** (ResourceSpace 11).
*(since X.YY)* marks the plugin version in which a feature first appeared. Features without
a mark have been available since 1.00. Change history: [CHANGELOG.md](CHANGELOG.md).

The plugin shows the current state and the history of the server and of ResourceSpace on one page:
CPU, memory, PHP-FPM / nginx / database processes, disk and network with live and history charts,
plus active users, downloads, uploads, resources by type and offline jobs. A configuration check
tells you which metrics are missing on your server and how to switch them on. Two widgets *(since 1.01)*
show the most important values on every page: a side panel for chosen people and a header bar for
whole user groups.

**Where:** System > Plugins > System Monitor > **Options**. Only administrators (permission `a`)
can open this page.

> Built for Linux servers (Ubuntu / Debian) with nginx, PHP-FPM and MySQL / MariaDB.
> On other systems the ResourceSpace statistics work, the server metrics may not.

---

## Tabs

### Live

Refreshed every few seconds (setting *Live view refresh*, default 3 s) while the tab is open.

| Tile / box | Meaning |
|---|---|
| **CPU** | Share of all cores in use, number of cores, load average (1 min). |
| **Memory**, **Swap** | Used memory (total minus *available*) and swap use. |
| **Active users** | Users active in the last 5 minutes and in the last *N* minutes (setting). |
| **PHP-FPM workers** | Busy / running workers and the request queue of the pool. |
| **Requests** | Requests per second (nginx, otherwise PHP-FPM) and nginx connections. |
| **Database** | Connections, queries per second, running threads. |
| **Network**, **Disk I/O** | Bytes per second received / sent, read / written. |
| **Filestore disk** | Used space of the disk that holds the filestore. |
| **Offline jobs** | Jobs waiting in the job queue, failed jobs. |
| CPU / Memory charts | The last 10 minutes, including the CPU share of each process group. |
| **Processes** | Number, CPU and memory of: PHP-FPM, nginx, database, media processing (ImageMagick, ffmpeg, Ghostscript, ExifTool, LibreOffice), PHP CLI (cron, offline jobs). Memory is RSS: workers share code, so the sum is higher than the real use. |
| **PHP-FPM, database, OPcache, disks** | Pool details ("max children reached", slow requests, queue), OPcache hit rate and memory, disks. |
| **Active users** | Who is logged in: user, name, group, last activity. |

### Server history

Charts for 6 h, 24 h, 48 h (minute samples) and 7 days, 30 days, 90 days, 1 year (hourly history):
CPU, memory, load, active users, CPU / number / memory of each process group, PHP-FPM workers and
"max children reached", requests per second, nginx connections, database, network, disk I/O,
filestore disk and offline jobs. A chart shows at most about 800 points; the dashed line is the
maximum of each point, the solid line the average. Hover over a chart for the values.

The history builds up from the moment the plugin is active (see *Collecting*).

### ResourceSpace

Time scale: **hours** (48 h), **days** (90) or **months** (24).

- Tiles: resources (count, size), resources without a preview image, users (active in 30 days),
  collections, active external shares (and how many have no expiry date), offline jobs.
- Charts: unique users, downloads (count and data), uploads (new resources, count and size),
  resource views and searches, logins (successful / failed, days scale).
- Tables: resources by type and by workflow state, offline jobs (incl. the last failed ones),
  database size with the largest tables, top 10 of the last 30 days: downloaded resources, searches,
  downloading and uploading users.

Sources: `daily_stat` (ResourceSpace's own statistics – the day and month charts therefore also show
the time **before** the plugin was installed), `resource_log`, `resource`, `search_log`,
`activity_log` (logins: the newest 1,000,000 entries), `job_queue`, `external_access_keys`. Per hour,
users come from the plugin's own samples, so this chart starts when the plugin starts collecting.
Results are cached for 2–15 minutes (database size: 1 hour).

User groups chosen in *Settings > Leave these user groups out* (e.g. load test accounts) are not
counted. ResourceSpace's own statistics are not changed.

### System check

Shows what the plugin can measure on this server, what is missing and **how to add it** (commands
and configuration snippets for Ubuntu / Debian):

| Check | What is checked |
|---|---|
| Server metrics (/proc) | Linux and `/proc` readable (also with `open_basedir`). |
| Processes of other users | Whether `/proc` is mounted with `hidepid` (nginx and the database would be invisible); services not found (e.g. the database on another host). |
| PHP-FPM status (web) | `fpm_get_status()` works in the web request – pool, `pm`, `pm.max_children` (from `pool.d/*.conf` when readable); warning when "max children reached" > 0. |
| PHP-FPM status page | `pm.status_path` and an nginx location – needed by the cron collector (PHP CLI). |
| nginx stub_status | Connections and requests per second. |
| Database status | `SHOW GLOBAL STATUS` works, version, `max_connections` and the highest use. |
| Data folder | `<filestore>/system_monitor` is writable. |
| Cron collector | Whether samples come from cron and how old the last one is. |
| OPcache | On, not full. |
| PHP curl | Needed to read the status pages. |

Below: versions and limits (ResourceSpace, PHP, database, InnoDB buffer pool, PHP limits, paths of
ImageMagick / ffmpeg / Ghostscript, uptime), the collected history and **Take a sample now**.

A banner at the top of the page shows how many items need attention.

### Widgets *(since 1.01)*

Two widgets on every page of ResourceSpace. Both are off until you choose who sees them.

**Side panel** – a floating box at the right (or left) edge of the screen, over the normal page.
A small handle with a status colour opens it; the user's last choice (open / closed) is remembered in
the browser.

| Setting | Default | Meaning |
|---|---|---|
| Who sees it | – | User groups and / or single users (one user name per line; unknown names are listed). |
| Content | *Server load* | Any values from the list below. Presets: **Server load** (status, CPU, memory, load, PHP-FPM, requests, database, disk), **Users and activity** (status, users online, active users, downloads and uploads today, offline jobs, requests, CPU), **Everything**. |
| Side | right | right or left |
| Open on the first visit | off | |
| Refresh | 10 s | 5–120 s while the panel is open. Closed: only the status colour of the handle, every minute (or with the header bar). |

Each row shows the value, a detail line and a chart of the last 30 minutes.

**Header bar** – in the top menu, right before the Upload button: by default the current **CPU and memory
use in per cent** (up to 4 values can be set; numbers, numbers with small charts of the last 30 minutes,
or numbers with load bars). A click opens a drop-down with the other values and, for administrators,
a link to this page. Each user group gets one of three sets, or none:

| Set | In the bar | In the drop-down | Display |
|---|---|---|---|
| **Basic** | CPU %, memory % | status, users online, downloads / uploads today, offline jobs | numbers |
| **Standard** | CPU %, memory % | status, load, requests, users online, active users, downloads / uploads today, jobs, disk | numbers + load bars |
| **Full** | CPU %, memory % | all other values | numbers + small charts |

The content and display of each set can be changed (*Default bar* / *Default drop-down* restore it).
Settings saved with an earlier build of 1.01 are converted once: CPU and memory go to the bar of every
set, the drop-down keeps the other values (display style and groups stay). Refresh: 30 s (10–300 s); while the drop-down is
open every 10 s at most. On narrow screens the bar shows only the values (below 900 px) or only the
status colours (below 600 px).

**Values:** system status (OK / busy / overloaded – the worst of CPU, memory, load, swap, disk and the
PHP-FPM queue), CPU, memory, load average, swap, filestore disk, PHP-FPM workers, requests per second,
web connections, database, network, disk I/O, media processing, users online (5 min), active users,
offline jobs, downloads today, uploads today. Colours: green = OK, orange = busy, red = overloaded.
Limits: CPU 75 / 90 %, memory 85 / 95 %, load 1 / 2 per core, swap 50 / 80 %, disk 85 / 95 %, PHP-FPM
queue 1 / 10, offline jobs 50 / 500 waiting or 1 / 10 failed, running database threads 20 / 50.

**Load:** all viewers share one set of values, computed at most every 5 seconds (`widget.json`), so
more viewers add only small requests, not more measuring. Refreshing stops while the browser tab is
hidden and after *N* minutes without mouse or keyboard (setting, default 10; 0 = never). Every refresh
is a request to ResourceSpace and counts as activity of the user, like ResourceSpace's own message check.

The widgets appear from the next full page load after saving. They are not shown on the login page, to
the anonymous user or on external shares.

### Settings

| Setting | Default | Meaning |
|---|---|---|
| Take samples during page requests | on | No cron needed. At most one sample per interval, taken after the page was sent (one file time check per request). |
| Sample interval | 1 min | 30 s, 1, 2 or 5 min. |
| Keep minute samples | 2 days | 1–14 days, about 1 MB per day. |
| Keep hourly history | 400 days | 30–1100 days. |
| Live view refresh | 3 s | 2–30 s. |
| "Active" users | 15 min | Activity within the last N minutes. |
| Leave these user groups out | – | Not counted in the ResourceSpace statistics. |
| PHP-FPM status URL, nginx stub_status URL | empty | Only addresses on this server (`127.0.0.1`, `localhost`, `::1`). |

Also: **Delete history** (keeps the settings), **Reset plugin** and the list of planned features.

---

## Setting up the server (recommended)

All steps are optional: without them the plugin works and shows what it can.

### 1. Status pages of PHP-FPM and nginx

PHP-FPM pool (e.g. `/etc/php/8.3/fpm/pool.d/www.conf` – the pool that runs ResourceSpace):

```ini
pm.status_path = /fpm-status
```

nginx – a separate server that listens only on 127.0.0.1 (e.g. `/etc/nginx/conf.d/status-local.conf`):

```nginx
server {
    listen 127.0.0.1:8099;
    server_name localhost;
    access_log off;
    allow 127.0.0.1;
    deny all;

    location = /nginx_status { stub_status; }

    location = /fpm-status {
        include fastcgi_params;
        fastcgi_param SCRIPT_NAME     $uri;
        fastcgi_param SCRIPT_FILENAME $uri;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx && sudo systemctl restart php8.3-fpm
curl -s 'http://127.0.0.1:8099/fpm-status?json'
curl -s http://127.0.0.1:8099/nginx_status
```

Then enter `http://127.0.0.1:8099/fpm-status` and `http://127.0.0.1:8099/nginx_status` in *Settings*.

### 2. Cron collector (even samples, also at night)

```bash
# /etc/cron.d/system_monitor
* * * * * www-data php <RS_ROOT>/plugins/system_monitor/cli/collect.php > /dev/null 2>&1
```

Test: `sudo -u www-data php <RS_ROOT>/plugins/system_monitor/cli/collect.php --force --verbose`.
Do not run it as root (the files would belong to root). `RS_ROOT=/path` sets the ResourceSpace folder
when the plugin is not in `<RS_ROOT>/plugins`.

### 3. hidepid

If `/proc` is mounted with `hidepid`, PHP sees only its own processes. System check shows how to
allow the web server user to see them (group `proc`, `gid=proc`).

---

## Data and privacy

- Everything is stored in `<filestore>/system_monitor/data_<key>/` (not readable by URL):
  `settings.json`, `state.json`, `minute/YYYY-MM-DD.jsonl`, `hour/YYYY-MM.jsonl`, `cache/`,
  `widget.json` / `widget_state.json` *(since 1.01)*.
- The plugin only reads: `/proc`, `/etc/php/<version>/fpm/pool.d/*.conf`, the configured local status
  pages and the database (SELECT / SHOW). No `exec()` or shell commands.
- Only administrators can see the page and its data (`pages/data.php`).
- Widgets *(since 1.01)*: `pages/widget.php` returns only the values of the panel / set of the logged-in
  user, nothing to other users (HTTP 403).
- **Reset plugin** (type `RESET`) deletes `<filestore>/system_monitor`. ResourceSpace data is not changed.

## Load and performance

- On a normal request: one `filemtime()` call. Once per interval one request takes a sample after
  the page was sent: reading `/proc` (all processes), two small database queries and the status pages.
- The live view reads the same data every few seconds only while an administrator has the tab open.
- The ResourceSpace statistics are cached.
- Widgets *(since 1.01)*: one request per viewer and refresh; the values are measured at most every
  5 seconds for all viewers together (see *Widgets*).

## Planned (next versions)

**Stage 2**
- Threshold alerts: CPU, memory, disk, offline jobs, busy PHP-FPM workers, failed logins – banner for administrators and e-mail
- Event markers on the charts (load test, ResourceSpace update) – added by hand or by the load test tool
- CSV / JSON export of every chart
- Dash tile for the ResourceSpace home page (CPU, memory, active users)
- Widgets: thresholds of the colours (OK / busy / overloaded) set on the options page; the user can pick a panel preset

**Stage 3**
- Per-request timing (slowest pages) from the nginx access log or PHP-FPM slow log
- Database: largest tables trend, slow query list
- Comparison of two periods (e.g. this week vs. last week)
- Permission for non-admin viewers of the dashboard

## To verify on ResourceSpace 11

The plugin was written against the ResourceSpace 10.7 sources: tables `daily_stat`, `resource_log`,
`activity_log`, `job_queue`, `user.last_active`, hook `initialise`. Check on ResourceSpace 11 (F12 /
System check) that the ResourceSpace tab shows data.
Header bar *(since 1.01)*: on ResourceSpace 11 it is placed in `#header-container .actions` right before
`a.UploadButton` (at the end when there is no Upload button) and takes the size and colours of the header
icon buttons; on ResourceSpace 10 in `#HeaderNav1 > ul` before `li.UploadButton`. When neither is found, the
bar is not shown (message in the browser console) – the side panel still works.

## Installation

- **In ResourceSpace:** Plugin Manager > Upload a plugin > `system_monitor-<version>.zip`, or
- **Command line:**
  ```bash
  curl -LO https://github.com/s29829/system_monitor/releases/download/v<version>/system_monitor-<version>.zip
  sudo unzip -o system_monitor-<version>.zip -d <RS_ROOT>/plugins/
  sudo chown -R www-data:www-data <RS_ROOT>/plugins/system_monitor
  ```

Then System > Plugins > activate **System Monitor** > Options. The plugin creates its data folder itself.

## License

PolyForm Noncommercial License 1.0.0 – free for private and noncommercial use. Commercial use only with
the author's written permission: [LICENSE-COMMERCIAL.md](LICENSE-COMMERCIAL.md). Full text: [LICENSE](LICENSE).
