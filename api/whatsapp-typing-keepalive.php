<?php
/**
 * Background worker — keeps WhatsApp typing bubble alive (called async from webhook).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/whatsapp-typing-keepalive.php';

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

@set_time_limit(130);
ignore_user_abort(false);

if (function_exists('fastcgi_finish_request')) {
    http_response_code(200);
    echo 'OK';
    fastcgi_finish_request();
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$sessionId = trim($input['session_id'] ?? '');

if ($sessionId === '') {
    exit;
}

whatsapp_typing_keepalive_run($sessionId);
