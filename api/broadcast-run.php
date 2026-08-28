<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/broadcasts.php';

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

$broadcastId = (int) ($input['broadcast_id'] ?? $_POST['broadcast_id'] ?? 0);
$batchSize = (int) ($input['batch_size'] ?? 25);
$batchSize = max(5, min(50, $batchSize));

if (!$broadcastId) {
    json_response(['success' => false, 'error' => 'Missing broadcast_id'], 400);
}

$result = broadcast_send_batch($broadcastId, $userId, $batchSize);
$stats = broadcast_recipient_stats($broadcastId);

json_response([
    'success' => true,
    'batch'   => $result,
    'stats'   => $stats,
]);
