<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shop-integrations.php';

header('Content-Type: application/json');

$user = require_login();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
if (!verify_csrf($input['csrf_token'] ?? $_POST['csrf_token'] ?? '')) {
    json_response(['success' => false, 'error' => 'Invalid token'], 403);
}

$botId = (int) ($input['bot_id'] ?? $_POST['bot_id'] ?? 0);
$platform = trim((string) ($input['platform'] ?? $_POST['platform'] ?? ''));

if (!$botId || $platform === '') {
    json_response(['success' => false, 'error' => 'Missing bot_id or platform'], 400);
}

$owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
if (!$owned) {
    json_response(['success' => false, 'error' => 'Invalid bot'], 403);
}

$result = shop_sync_products($botId, $userId, $platform);
json_response($result, $result['success'] ? 200 : 400);
