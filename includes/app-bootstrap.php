<?php
/**
 * Global bootstrap — show fatal errors instead of a blank white screen.
 */
if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

if (defined('APP_TIMEZONE') && APP_TIMEZONE !== '') {
    date_default_timezone_set(APP_TIMEZONE);
}

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    if (headers_sent()) {
        echo "\n<div style=\"margin:16px;padding:16px;background:#ffdad6;color:#93000a;font-family:Inter,sans-serif;border-radius:12px;max-width:720px\">";
    } else {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Application Error</title></head><body style="font-family:Inter,sans-serif;padding:24px;background:#f8f9fa">';
        echo '<div style="padding:16px;background:#ffdad6;color:#93000a;border-radius:12px;max-width:720px">';
    }

    echo '<h1 style="margin:0 0 8px;font-size:18px">Something crashed while loading this page</h1>';
    echo '<pre style="white-space:pre-wrap;margin:0;font-size:13px">';
    echo htmlspecialchars($err['message'] . "\n" . $err['file'] . ':' . $err['line'], ENT_QUOTES, 'UTF-8');
    echo '</pre></div></body></html>';
});
