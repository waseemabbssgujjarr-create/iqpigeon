<?php
/**
 * Admin: revoke client WhatsApp connection.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

security_require_api_csrf();

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$clientId = (int) ($input['client_id'] ?? 0);

if (!$clientId) {
    json_response(['success' => false, 'error' => 'client_id required'], 400);
}

db_execute(
    'UPDATE client_whatsapp_accounts SET connection_status = \'revoked\' WHERE client_id = ?',
    'i',
    [$clientId]
);

json_response(['success' => true]);
