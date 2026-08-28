<?php
/**
 * Send a test WhatsApp message (authenticated client).
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../lib/WhatsAppSender.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_login();

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$clientId = (int) ($input['client_id'] ?? 0);
$toNumber = trim($input['to_number'] ?? '');
$messageBody = trim($input['message_body'] ?? '');

if ($clientId <= 0) {
    $clientId = (int) $user['id'];
}

if ($toNumber === '' || $messageBody === '') {
    json_response(['success' => false, 'error' => 'Enter a phone number and message.'], 400);
}

if ((int) $user['id'] !== $clientId && $user['role'] !== 'admin') {
    json_response(['success' => false, 'error' => 'Unauthorized'], 403);
}

$result = WhatsAppSender::sendTextMessage($clientId, $toNumber, $messageBody);

$toDigits = preg_replace('/\D/', '', $toNumber);
$recentChat = db_fetch(
    'SELECT l.id FROM leads l
     INNER JOIN bots b ON b.id = l.bot_id
     WHERE b.user_id = ? AND l.platform = \'whatsapp\'
       AND REPLACE(REPLACE(l.external_id, \'+\', \'\'), \' \', \'\') = ?
       AND l.updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
     LIMIT 1',
    'is',
    [$clientId, $toDigits]
);

if (!$result['success']) {
    json_response(['success' => false, 'error' => $result['error'] ?? 'Send failed'], 400);
}

json_response([
    'success'    => true,
    'message_id' => $result['message_id'] ?? '',
    'to_number'  => $toNumber,
    'warning'    => $recentChat === null
        ? 'Meta accepted the message, but WhatsApp usually only delivers to numbers that messaged your business first (24-hour window). Ask them to text your WhatsApp number, then reply here.'
        : '',
]);
