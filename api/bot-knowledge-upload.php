<?php
/**
 * Upload PDF / Word / TXT and extract text for bot knowledge document.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/document-text.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = get_user();
if (!$user) {
    json_response(['success' => false, 'error' => 'Sign in required.'], 401);
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    json_response(['success' => false, 'error' => 'Invalid request.'], 403);
}

$botId = (int) ($_POST['bot_id'] ?? 0);
if (!$botId) {
    json_response(['success' => false, 'error' => 'bot_id is required.'], 400);
}

$bot = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, (int) $user['id']]);
if (!$bot) {
    json_response(['success' => false, 'error' => 'Bot not found.'], 404);
}

if (empty($_FILES['document'])) {
    json_response(['success' => false, 'error' => 'Choose a PDF, Word, or TXT file to upload.'], 400);
}

$result = extract_document_text($_FILES['document']);
if (!$result['success']) {
    json_response(['success' => false, 'error' => $result['error'] ?? 'Could not read file.'], 422);
}

json_response([
    'success'  => true,
    'text'     => $result['text'],
    'filename' => basename((string) ($_FILES['document']['name'] ?? 'document')),
    'message'  => 'Document loaded. Building your sales script...',
]);
