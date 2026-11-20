# Changelog - System Monitor

Plugin version numbers: 1.00, 1.01, 1.02 … (+0.01 for every change). The current version is in `system_monitor.yaml`.

## [1.01] - 2026-11-20
- Widgets on every page: a floating side panel for chosen user groups and users (presets: server load, users and activity, everything) and a header bar before the Upload button with a drop-down (Basic / Standard / Full set per user group; numbers, small charts or load bars). Shared values computed at most every 5 seconds; refreshing pauses in hidden tabs and after idle time.

## [1.00] - 2026-11-19
- First version: live view (CPU, memory, swap, load, PHP-FPM / nginx / database / media / PHP CLI processes, PHP-FPM pool, OPcache, disks, network, disk I/O, active users) with 10-minute charts; server history (minute samples and hourly history, 6 hours to 1 year); ResourceSpace statistics (unique users, downloads, uploads, views, searches, logins per hour / day / month, resources by type and state, offline jobs, database size, top lists); configuration check with setup instructions (/proc, hidepid, PHP-FPM status, nginx stub_status, database, cron collector, OPcache); samples during page requests or from cron (cli/collect.php); excluded user groups; Delete history and Reset plugin.
