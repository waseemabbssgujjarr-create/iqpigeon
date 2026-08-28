<?php
/**
 * Admin: full WhatsApp message log for a client.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

require_admin();

$clientId = (int) ($_GET['client_id'] ?? 0);
if (!$clientId) {
    json_response(['success' => false, 'error' => 'client_id required'], 400);
}

$client = db_fetch('SELECT id, name FROM users WHERE id = ? AND role = \'client\'', 'i', [$clientId]);
if (!$client) {
    json_response(['success' => false, 'error' => 'Client not found'], 404);
}

$rows = db_fetch_all(
    'SELECT id, direction, from_number, to_number, message_body, status, wa_message_id, created_at
     FROM whatsapp_messages_log
     WHERE client_id = ?
     ORDER BY created_at DESC
     LIMIT 100',
    'i',
    [$clientId]
);

$messages = array_map(static function (array $row): array {
    $body = (string) ($row['message_body'] ?? '');
    return [
        'id'         => (int) $row['id'],
        'direction'  => $row['direction'],
        'from'       => $row['from_number'],
        'to'         => $row['to_number'],
        'preview'    => mb_strlen($body) > 80 ? mb_substr($body, 0, 80) . '…' : $body,
        'body'       => $body,
        'status'     => $row['status'],
        'wa_message_id' => $row['wa_message_id'],
        'created_at' => $row['created_at'],
    ];
}, $rows);

json_response([
    'success'     => true,
    'client_name' => $client['name'],
    'messages'    => $messages,
]);
