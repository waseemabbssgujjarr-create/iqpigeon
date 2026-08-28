<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bot-context.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_login_json();
$userId = (int) $user['id'];
$botId = (int) ($_GET['bot_id'] ?? 0);

if ($botId <= 0) {
    $row = db_fetch('SELECT id FROM bots WHERE user_id = ? ORDER BY id ASC LIMIT 1', 'i', [$userId]);
    $botId = (int) ($row['id'] ?? 0);
}

if ($botId <= 0) {
    json_response(['success' => false, 'error' => 'No bot found'], 404);
}

json_response(array_merge(['success' => true], bot_context_snapshot($botId, $userId)));
