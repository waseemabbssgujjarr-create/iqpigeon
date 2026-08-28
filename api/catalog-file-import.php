<?php
/**
 * Upload menu PDF or catalog image — parse products with deduplication.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/catalog-file-import.php';

header('Content-Type: application/json');

$user = require_login();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed'], 405);
}

$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf($token)) {
    json_response(['success' => false, 'error' => 'Invalid session token'], 403);
}

$botId = (int) ($_POST['bot_id'] ?? 0);
$owned = db_fetch('SELECT id FROM bots WHERE id = ? AND user_id = ?', 'ii', [$botId, $userId]);
if (!$owned) {
    json_response(['success' => false, 'error' => 'Invalid bot'], 403);
}

if (empty($_FILES['menu_file']) || ($_FILES['menu_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    json_response(['success' => false, 'error' => 'Choose a PDF or image file.'], 400);
}

try {
    $result = catalog_menu_import_file($botId, $userId, $_FILES['menu_file']);
    if (empty($result['success'])) {
        json_response([
            'success' => false,
            'error'   => $result['error'] ?? 'Import failed',
            'menu_url' => $result['menu_url'] ?? '',
        ], 422);
    }

    json_response([
        'success'  => true,
        'message'  => $result['message'] ?? 'Menu imported',
        'stats'    => $result['stats'] ?? [],
        'menu_url' => $result['menu_url'] ?? '',
        'total'    => count($result['products'] ?? []),
    ]);
} catch (Throwable $e) {
    error_log('catalog-file-import: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Import failed. Try another file.'], 500);
}
