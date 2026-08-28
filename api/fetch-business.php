<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/business-fetch.php';
require_once __DIR__ . '/../includes/api-json.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

try {
    @set_time_limit(180);

    $user = require_login_json();
    security_require_api_csrf();

    $input = security_cached_json_body();
    $userId = (int) $user['id'];
    $botId = (int) ($input['bot_id'] ?? 0);
    $url = trim((string) ($input['url'] ?? ''));
    $importCatalog = !array_key_exists('import_catalog', $input) || !empty($input['import_catalog']);

    if (!$botId || $url === '') {
        json_response(['success' => false, 'error' => 'Missing bot_id or website URL'], 400);
    }

    $result = fetch_business_from_website($botId, $userId, $url, true, $importCatalog);

    if (!$result['success']) {
        json_response($result, 400);
    }

    $msg = 'Fetched website content';
    if (($result['products_imported'] ?? 0) > 0) {
        $msg .= ' and imported ' . (int) $result['products_imported'] . ' products';
    } elseif (!$importCatalog) {
        $msg .= ' (text only — fast mode). Import products from Shop if needed';
    }
    if (!empty($result['history_cleared'])) {
        $msg .= '. WhatsApp and website chat memory were reset for the updated business info';
    }
    if (!empty($result['domain_changed'])) {
        $msg .= ' (new website — old product catalog cleared)';
    }
    $msg .= '.';

    json_response(api_json_with_context($botId, $userId, array_merge($result, ['message' => $msg])));
} catch (Throwable $e) {
    error_log('fetch-business.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_response([
        'success' => false,
        'error'   => 'Fetch failed: ' . $e->getMessage(),
    ], 500);
}
