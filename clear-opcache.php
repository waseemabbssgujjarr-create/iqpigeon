<?php
/**
 * Clear PHP OPcache after uploading fixed PHP files.
 * Open once: /clear-opcache.php?key=YOUR_CRON_SECRET
 * DELETE this file after use.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$root = __DIR__;
$key = trim((string) ($_GET['key'] ?? ''));

$cron = null;
foreach (['config.local.php', 'config.php'] as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        continue;
    }
    $raw = file_get_contents($path) ?: '';
    if (preg_match("/define\\s*\\(\\s*['\"]CRON_SECRET['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $raw, $m)) {
        $cron = $m[1];
        break;
    }
}

if ($cron === null || $cron === '' || !hash_equals($cron, $key)) {
    http_response_code(403);
    echo "403 Forbidden\nUse ?key= from CRON_SECRET in config.local.php\n";
    exit;
}

$results = [];

if (function_exists('opcache_reset')) {
    $results[] = 'opcache_reset: ' . (opcache_reset() ? 'OK' : 'FAILED');
} else {
    $results[] = 'opcache_reset: not available';
}

if (function_exists('apcu_clear_cache')) {
    $results[] = 'apcu_clear_cache: ' . (apcu_clear_cache() ? 'OK' : 'FAILED');
}

$touchFiles = [
    $root . '/includes/admin-navigation.php',
    $root . '/includes/admin-nav.php',
    $root . '/includes/notification-bell.php',
    $root . '/includes/security-output.php',
    $root . '/config.php',
];
foreach ($touchFiles as $file) {
    if (is_file($file)) {
        touch($file);
        $results[] = 'touched: ' . basename($file);
    }
}

echo "OPcache clear complete\n\n";
echo implode("\n", $results) . "\n\n";
echo "Now hard-refresh an admin page (Ctrl+Shift+R).\n";
echo "Delete clear-opcache.php when done.\n";
