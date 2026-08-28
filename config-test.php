<?php
/**
 * Minimal config test — no DB. Visit /config-test.php
 * Delete after fixing production.
 */
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    require __DIR__ . '/config.php';
    echo "OK config.php loaded\n";
    echo 'APP_URL=' . APP_URL . "\n";
    echo 'META_APP_ID=' . META_APP_ID . "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'CONFIG ERROR: ' . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}
