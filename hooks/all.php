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

// Side panel and header bar (since 1.01): only on full page loads (the header is not part of AJAX loads),
// only for the user groups / users chosen on the options page. Values are loaded by assets/widget.js.
function HookSystem_monitorAllAdditionalheaderjs()
{
    global $CSP_nonce, $pagename;
    try {
        if (in_array((string) $pagename, array('login', 'user_password', 'user_request', 'preview', 'terms'), true)
            || (isset($_GET['k']) && $_GET['k'] !== '')) {
            return false; // not logged in yet, or an external share
        }
        $access = system_monitor_widget_access();
        if ($access === null) {
            return false;
        }
        $dir = dirname(__DIR__) . '/assets';
        $url = get_plugin_path('system_monitor', true) . '/assets';
        $ver = (int) filemtime($dir . '/widget.js') . '-' . (int) filemtime($dir . '/widget.css');
        $nonce = !empty($CSP_nonce) ? ' nonce="' . htmlspecialchars($CSP_nonce) . '"' : '';
        echo '<link rel="stylesheet" href="' . htmlspecialchars($url . '/widget.css?v=' . $ver) . '">' . "\n";
        echo '<script' . $nonce . '>window.SystemMonitorWidget = '
            . json_encode(system_monitor_widget_config($access), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ';</script>' . "\n";
        echo '<script' . $nonce . ' defer src="' . htmlspecialchars($url . '/widget.js?v=' . $ver) . '"></script>' . "\n";
    } catch (Throwable $e) {
        // the widgets must never break a page
    }
    return false;
}
