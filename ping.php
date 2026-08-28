<?php
/**
 * Step-by-step bootstrap diagnostic — visit with ?key=YOUR_CRON_SECRET
 * Delete or protect this file after fixing production.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$fail = static function (string $step, Throwable $e): void {
    http_response_code(500);
    echo "FAIL at: {$step}\n";
    echo $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
    exit;
};

$key = trim((string) ($_GET['key'] ?? ''));
if ($key === '') {
    http_response_code(403);
    echo "Forbidden. Use ?key=YOUR_CRON_SECRET from config.local.php\n";
    exit;
}

try {
    echo "PHP " . PHP_VERSION . "\n";
    require __DIR__ . '/config.php';
    echo "config.php OK\n";

    if (!hash_equals((string) CRON_SECRET, $key)) {
        http_response_code(403);
        echo "Invalid key.\n";
        exit;
    }

    require __DIR__ . '/includes/db.php';
    db_connect();
    echo "database OK (" . DB_NAME . ")\n";

    require __DIR__ . '/includes/helpers.php';
    echo "helpers OK\n";

    get_plans();
    echo "plans/settings OK\n";

    get_demo_bot();
    echo "demo bot query OK\n";

    echo "\nAll checks passed. If pages still 500, clear storage/security/config.local.*.php cache and OPcache.\n";
} catch (Throwable $e) {
    $fail('bootstrap', $e);
}
