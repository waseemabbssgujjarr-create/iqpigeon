<?php
/**
 * Optional webhook endpoint — re-sync products when store sends product/update events.
 * Configure your store webhook to POST here with ?bot_id=&platform=shopify|woocommerce
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/shop-integrations.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$botId = (int) ($_GET['bot_id'] ?? 0);
$platform = trim((string) ($_GET['platform'] ?? ''));

if (!$botId || !array_key_exists($platform, shop_platforms())) {
    json_response(['success' => false, 'error' => 'Invalid bot or platform'], 400);
}

$integration = shop_integration_for_bot($botId, $platform);
if (!$integration) {
    json_response(['success' => false, 'error' => 'Integration not found'], 404);
}

$secret = decrypt_token($integration['webhook_secret'] ?? '');
if ($secret === false || $secret === '') {
    json_response(['success' => false, 'error' => 'Webhook secret not configured for this integration.'], 503);
}

$provided = (string) ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $_GET['secret'] ?? '');
security_require_webhook_secret($provided, $secret, 'shop_' . $platform);

$userId = (int) ($integration['user_id'] ?? 0);
$result = shop_sync_products($botId, $userId, $platform);

json_response([
    'success'  => $result['success'],
    'imported' => $result['imported'],
    'updated'  => $result['updated'],
]);
