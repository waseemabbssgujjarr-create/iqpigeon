<?php
/**
 * Admin login bootstrap diagnostic — visit with ?key=CRON_SECRET
 * Delete after fixing production.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$key = trim((string) ($_GET['key'] ?? ''));
if ($key === '') {
    http_response_code(403);
    echo "Forbidden. Use ?key=CRON_SECRET\n";
    exit;
}

$step = static function (string $label, callable $fn): void {
    try {
        $fn();
        echo "OK: {$label}\n";
    } catch (Throwable $e) {
        http_response_code(500);
        echo "FAIL: {$label}\n";
        echo $e->getMessage() . "\n";
        echo $e->getFile() . ':' . $e->getLine() . "\n";
        exit;
    }
};

echo 'PHP ' . PHP_VERSION . "\n";

$step('config.php', static function (): void {
    require __DIR__ . '/config.php';
    if (!hash_equals((string) CRON_SECRET, trim((string) ($_GET['key'] ?? '')))) {
        http_response_code(403);
        echo "Invalid key.\n";
        exit;
    }
});

$step('db.php', static function (): void {
    require __DIR__ . '/includes/db.php';
    db_connect();
});

$step('helpers.php', static function (): void {
    require __DIR__ . '/includes/helpers.php';
});

$step('auth.php + session', static function (): void {
    require __DIR__ . '/includes/auth.php';
});

$step('admin_access_granted (no key)', static function (): void {
    unset($_GET['key']);
    $ok = admin_access_granted();
    echo '  granted=' . ($ok ? 'yes' : 'no') . "\n";
});

$step('page_head()', static function (): void {
    require __DIR__ . '/includes/helpers.php';
    $html = page_head('Diag');
    if ($html === '') {
        throw new RuntimeException('page_head returned empty');
    }
});

$step('brand_logo_markup()', static function (): void {
    $html = brand_logo_markup('brand-logo-img', 'dark');
    if ($html === '') {
        throw new RuntimeException('brand_logo returned empty');
    }
});

$step('csrf_token()', static function (): void {
    $t = csrf_token();
    if ($t === '') {
        throw new RuntimeException('csrf_token empty');
    }
});

echo "\nAdmin login bootstrap OK.\n";
echo 'ADMIN_ACCESS_KEY set: ' . (defined('ADMIN_ACCESS_KEY') && ADMIN_ACCESS_KEY !== '' ? 'yes (' . strlen(ADMIN_ACCESS_KEY) . ' chars)' : 'no') . "\n";
echo "Use: /admin/login?key=YOUR_ADMIN_ACCESS_KEY\n";
