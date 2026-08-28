<?php
/**
 * Toggle website widget or WhatsApp auto-reply for a bot.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/phase5-schema.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = get_user();
if (!$user) {
    json_response(['success' => false, 'error' => 'Sign in required.'], 401);
}

$csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
if ($csrfToken === '') {
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $csrfToken = trim((string) ($json['csrf_token'] ?? ''));
        $_POST = array_merge($_POST, $json);
    }
}
if (!verify_csrf($csrfToken)) {
    json_response(['success' => false, 'error' => 'Invalid request. Refresh and try again.'], 403);
}

$userId = (int) $user['id'];
$botId = (int) ($_POST['bot_id'] ?? 0);
$field = (string) ($_POST['field'] ?? '');
$enabled = !empty($_POST['enabled']) || (string) ($_POST['enabled'] ?? '') === '1';

$allowed = ['widget_enabled', 'whatsapp_auto_reply'];
if (!in_array($field, $allowed, true)) {
    json_response(['success' => false, 'error' => 'Invalid toggle.'], 400);
}

if ($botId <= 0) {
    json_response(['success' => false, 'error' => 'No bot selected.'], 400);
}

$owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
if (!$owned) {
    json_response(['success' => false, 'error' => 'Bot not found.'], 404);
}

ensure_phase5_schema();

$value = $enabled ? 1 : 0;
if ($field === 'widget_enabled') {
    db_execute(
        'UPDATE bots SET widget_enabled = ? WHERE id = ? AND user_id = ?',
        'iii',
        [$value, $botId, $userId]
    );
    $message = $enabled ? 'Website widget is now ON.' : 'Website widget is now OFF.';
} else {
    db_execute(
        'UPDATE bots SET whatsapp_auto_reply = ? WHERE id = ? AND user_id = ?',
        'iii',
        [$value, $botId, $userId]
    );
    $message = $enabled
        ? 'WhatsApp auto-reply is ON — your AI will respond to customers.'
        : 'WhatsApp auto-reply is OFF — messages are saved but the AI will not reply.';
}

$row = db_fetch(
    'SELECT widget_enabled, whatsapp_auto_reply FROM bots WHERE id = ? AND user_id = ?',
    'ii',
    [$botId, $userId]
);

json_response([
    'success'              => true,
    'message'              => $message,
    'widget_enabled'       => (int) ($row['widget_enabled'] ?? 0),
    'whatsapp_auto_reply'    => (int) ($row['whatsapp_auto_reply'] ?? 1),
]);
