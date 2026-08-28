<?php
/**
 * Public brand logo for HTML emails (always HTTPS, cache-friendly).
 */
require_once __DIR__ . '/config.php';

$candidates = [
    __DIR__ . '/assets/img/site-logo-on-dark-bg.png',
    __DIR__ . '/assets/img/Fav-Icon-on-white-bg.png',
    __DIR__ . '/assets/img/site-logo-on-white-bg.png',
];

$path = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}

if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Logo not found';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800, immutable');
header('Content-Length: ' . filesize($path));
readfile($path);
