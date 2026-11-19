<?php
// System Monitor - cron collector: takes one sample (run every minute as the web server user).
//   /etc/cron.d/system_monitor:
//   * * * * * www-data php <RS_ROOT>/plugins/system_monitor/cli/collect.php > /dev/null 2>&1
// Options: --force (sample even if the interval has not passed), --verbose (print the sample)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
// ResourceSpace root: RS_ROOT environment variable, otherwise the folder above plugins/ (or filestore/plugins/)
$root = (string) getenv('RS_ROOT');
if ($root === '') {
    $dir = dirname(__DIR__);
    while ($dir !== dirname($dir) && !is_file($dir . '/include/boot.php')) {
        $dir = dirname($dir);
    }
    $root = $dir;
}
if (!is_file($root . '/include/boot.php')) {
    fwrite(STDERR, "ResourceSpace not found - set RS_ROOT=/path/to/resourcespace\n");
    exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Do not run as root (files would belong to root) - use: sudo -u www-data php " . __FILE__ . "\n");
    exit(1);
}
include $root . '/include/boot.php';
require_once dirname(__DIR__) . '/include/collect.php';

$args = array_slice($argv, 1);
$ok = system_monitor_collect('cli', in_array('--force', $args, true));
if (in_array('--verbose', $args, true)) {
    $state = system_monitor_read_json(system_monitor_state_file());
    echo ($ok ? "Sample written\n" : "No sample (interval not passed or another collector is running)\n");
    $rows = system_monitor_minute_rows(time() - 300, time());
    if ($rows) {
        echo json_encode(end($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
exit(0);
