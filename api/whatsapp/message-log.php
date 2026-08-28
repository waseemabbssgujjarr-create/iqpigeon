<?php
/**
 * Message log JSON for client dashboard (auto-refresh).
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

$user = require_login();
$clientId = (int) $user['id'];

$requestedId = (int) ($_GET['client_id'] ?? $clientId);
if ($requestedId !== $clientId && $user['role'] !== 'admin') {
    json_response(['success' => false, 'error' => 'Unauthorized'], 403);
}

$rows = db_fetch_all(
    'SELECT id, direction, from_number, to_number, message_body, status, created_at
     FROM whatsapp_messages_log
     WHERE client_id = ?
     ORDER BY created_at DESC
     LIMIT 50',
    'i',
    [$requestedId]
);

$messages = array_map(static function (array $row): array {
    $body = (string) ($row['message_body'] ?? '');
    $preview = mb_strlen($body) > 80 ? mb_substr($body, 0, 80) . '…' : $body;

    return [
        'id'         => (int) $row['id'],
        'direction'  => $row['direction'],
        'from'       => $row['from_number'],
        'to'         => $row['to_number'],
        'preview'    => $preview,
        'status'     => $row['status'],
        'created_at' => $row['created_at'],
        'time_ago'   => format_date($row['created_at']),
    ];
}, $rows);

json_response(['success' => true, 'messages' => $messages]);
