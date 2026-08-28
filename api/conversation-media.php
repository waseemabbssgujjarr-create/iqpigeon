<?php
/**
 * Upload and send image in a live conversation.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/conversation-media.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_login();
$userId = (int) $user['id'];

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    json_response(['success' => false, 'error' => 'Invalid request.'], 403);
}

$leadId = (int) ($_POST['lead_id'] ?? 0);
$caption = trim((string) ($_POST['caption'] ?? ''));

if ($leadId <= 0) {
    json_response(['success' => false, 'error' => 'lead_id is required.'], 400);
}

$lead = db_fetch(
    'SELECT l.id FROM leads l JOIN bots b ON b.id = l.bot_id WHERE l.id = ? AND b.user_id = ?',
    'ii',
    [$leadId, $userId]
);
if (!$lead) {
    json_response(['success' => false, 'error' => 'Lead not found.'], 404);
}

if (empty($_FILES['image'])) {
    json_response(['success' => false, 'error' => 'Choose an image to send.'], 400);
}

$saved = conversation_save_outbound_image($_FILES['image'], $userId);
if (empty($saved['success'])) {
    json_response(['success' => false, 'error' => $saved['error'] ?? 'Upload failed.'], 422);
}

$imageUrl = (string) ($saved['url'] ?? '');
if ($imageUrl === '') {
    json_response(['success' => false, 'error' => 'Could not build public image URL.'], 500);
}

if (!conversation_send_image_to_lead($leadId, $userId, $imageUrl, $caption)) {
    json_response(['success' => false, 'error' => 'Could not send image.'], 500);
}

$last = db_fetch(
    'SELECT id FROM conversations WHERE lead_id = ? ORDER BY id DESC LIMIT 1',
    'i',
    [$leadId]
);

json_response([
    'success'   => true,
    'message'   => 'Image sent.',
    'image_url' => $imageUrl,
    'last_id'   => (int) ($last['id'] ?? 0),
]);
