<?php
/**
 * Disconnect WhatsApp (set status=revoked) for current client.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/whatsapp-token.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_login();
$clientId = (int) $user['id'];

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$targetId = (int) ($input['client_id'] ?? $clientId);

if ($targetId !== $clientId && $user['role'] !== 'admin') {
    json_response(['success' => false, 'error' => 'Unauthorized'], 403);
}

db_execute(
    'UPDATE client_whatsapp_accounts SET connection_status = \'revoked\' WHERE client_id = ? AND connection_status = \'active\'',
    'i',
    [$targetId]
);

ensure_whatsapp_token_schema();
db_execute(
    'UPDATE bots SET whatsapp_phone_id = NULL, whatsapp_token = NULL, whatsapp_verified = 0,
        whatsapp_token_error = ?
     WHERE user_id = ?',
    'si',
    ['Disconnected by user — connect WhatsApp again.', $targetId]
);

json_response(['success' => true]);
