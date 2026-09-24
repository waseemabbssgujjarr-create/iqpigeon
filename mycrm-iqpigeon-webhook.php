<?php

/**
 * Partner webhook receiver for IQPigeon WhatsApp API (MYCRM_TRANSPORT=iqpigeon_api).
 * Meta must NOT post here — only whatsappapi.iqpigeon.com delivers signed events.
 */
declare(strict_types=1);

ini_set('display_errors', '0');

require_once __DIR__ . '/includes/mycrm/config.php';
require_once __DIR__ . '/includes/mycrm/webhook-verify.php';
require_once __DIR__ . '/includes/mycrm/webhook-events.php';
require_once __DIR__ . '/includes/mycrm/storage.php';

if (!mycrm_is_iqpigeon_api_mode()) {
    http_response_code(404);
    echo 'Not enabled';
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$signature = (string) ($_SERVER['HTTP_X_IQP_SIGNATURE'] ?? '');
$timestamp = (string) ($_SERVER['HTTP_X_IQP_TIMESTAMP'] ?? '');
$eventId = (string) ($_SERVER['HTTP_X_IQP_EVENT_ID'] ?? '');
$requestId = (string) ($_SERVER['HTTP_X_IQP_REQUEST_ID'] ?? '');

if (!mycrm_iqpigeon_verify_webhook($raw, $signature, $timestamp)) {
    http_response_code(401);
    echo 'Invalid signature';
    exit;
}

try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$id = (string) ($payload['id'] ?? $eventId);
if ($id === '' || !mycrm_mark_event_processed($id)) {
    http_response_code(200);
    echo 'duplicate';
    exit;
}

$type = (string) ($payload['type'] ?? 'unknown');
$data = $payload['data'] ?? [];
$parsed = mycrm_iqpigeon_parse_webhook_events($type, $data);

foreach ($parsed as $row) {
    mycrm_append_message(array_merge($row, [
        'transport' => 'iqpigeon_api',
        'event_id' => $id,
        'connection_id' => mycrm_iqpigeon_connection_id(),
        'request_id' => $requestId,
        'received_at' => gmdate('c'),
    ]));
    mycrm_update_message_status_from_webhook($row);
}

mycrm_set_state([
    'last_webhook_at' => gmdate('c'),
    'last_webhook_type' => $type,
    'last_webhook_event_id' => $id,
]);

http_response_code(200);
echo 'ok';
