<?php
/**
 * Client-side OAuth debug events (FINISH, SDK clicks, etc.).
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/whatsapp-oauth-debug.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_login();
$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$step = trim((string) ($input['step'] ?? 'client_event'));
$clientId = (int) ($input['client_id'] ?? $user['id']);

if ($clientId !== (int) $user['id'] && ($user['role'] ?? '') !== 'admin') {
    json_response(['success' => false, 'error' => 'Unauthorized'], 403);
}

whatsapp_oauth_debug_log($step !== '' ? $step : 'client_event', [
    'client_id' => $clientId,
    'payload'   => $input,
    'sdk'       => [
        'fbSdkReady'  => !empty($input['fbSdkReady']),
        'fbSdkFailed' => !empty($input['fbSdkFailed']),
        'userAgent'   => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
    ],
]);

json_response(['success' => true]);
