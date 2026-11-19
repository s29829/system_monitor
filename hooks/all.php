<?php
// Function name format: Hook + <plugin> + <page> + <hook name>
require_once dirname(__DIR__) . '/include/functions.php';

// Sampling during normal requests: one file time check per request; when the interval has passed,
// the sample is taken after the page was produced (shutdown function), at most once per interval.
function HookSystem_monitorAllInitialise()
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    $state = system_monitor_state_file();
    $last = is_file($state) ? filemtime($state) : 0;
    if (time() - $last < min(SYSTEM_MONITOR_INTERVALS)) {
        return false;
    }
    register_shutdown_function('system_monitor_collect_on_shutdown');
    return false;
}

function system_monitor_collect_on_shutdown()
{
    try {
        if (!system_monitor_settings()['collect_on_requests']) {
            return;
        }
        require_once dirname(__DIR__) . '/include/collect.php';
        system_monitor_collect('web');
    } catch (Throwable $e) {
        // monitoring must never break a page
    }
}
