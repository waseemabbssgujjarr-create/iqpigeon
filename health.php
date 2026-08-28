<?php
/**
 * Bootstrap health check — shows the exact PHP error causing HTTP 500.
 * Visit: /health.php?key=YOUR_CRON_SECRET
 * Delete this file after fixing production.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$key = trim((string) ($_GET['key'] ?? ''));
if ($key === '') {
    http_response_code(403);
    echo "Use ?key=CRON_SECRET from config.local.php\n";
    exit;
}

$steps = [
    'config.php'              => static fn () => require __DIR__ . '/config.php',
    'integration-settings.php' => static fn () => require_once __DIR__ . '/includes/integration-settings.php',
    'whatsapp-oauth.php'      => static fn () => require_once __DIR__ . '/includes/whatsapp-oauth.php',
    'whatsapp-token.php'      => static fn () => require_once __DIR__ . '/includes/whatsapp-token.php',
    'whatsapp-debug logic'    => static function () use ($key): void {
        if (!defined('CRON_SECRET') || !hash_equals((string) CRON_SECRET, $key)) {
            throw new RuntimeException(
                'CRON_SECRET mismatch — use the exact value from config.local.php in ?key= (not config.php default).'
            );
        }
        integration_meta_credentials();
        whatsapp_meta_verify_app_credentials();
        whatsapp_oauth_redirect_uri();
    },
];

echo 'PHP ' . PHP_VERSION . "\n\n";

foreach ($steps as $label => $step) {
    try {
        $step();
        echo "OK  {$label}\n";
    } catch (Throwable $e) {
        http_response_code(500);
        echo "FAIL {$label}\n";
        echo $e->getMessage() . "\n";
        echo $e->getFile() . ':' . $e->getLine() . "\n";
        exit;
    }
}

echo "\nAll steps passed.\n";
echo 'APP_URL=' . (defined('APP_URL') ? APP_URL : '(undefined)') . "\n";
echo 'META_APP_ID=' . (defined('META_APP_ID') ? META_APP_ID : '(undefined)') . "\n";
echo 'META_APP_SECRET set=' . (defined('META_APP_SECRET') && META_APP_SECRET !== '' ? 'yes' : 'no') . "\n";
$creds = integration_meta_credentials();
$verify = whatsapp_meta_verify_app_credentials();
echo 'meta_secret_source=' . (integration_get_stored_secrets()['meta_app_secret'] ?? false ? 'admin' : 'config') . "\n";
echo 'meta_app_verify=' . (!empty($verify['success']) ? 'OK' : ($verify['error'] ?? 'FAIL')) . "\n";
