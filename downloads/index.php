<?php
/**
 * Android APK download — upload sales-app.apk to /downloads/ on the server.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';

$path = android_apk_path();

if (!is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Android app is not available yet. Please check back soon.';
    exit;
}

header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="sales-app.apk"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-cache, must-revalidate');

readfile($path);
exit;
