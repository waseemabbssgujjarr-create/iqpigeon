<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = get_user();
if (!$user || ($user['role'] ?? '') !== 'client') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh and try again.']);
    exit;
}

$current = $_POST['current_password'] ?? '';
$newPass = $_POST['new_password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

$row = db_fetch('SELECT password FROM users WHERE id = ?', 'i', [(int) $user['id']]);

if (!$row || !password_verify($current, $row['password'])) {
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
    exit;
}

if (strlen($newPass) < 8) {
    echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
    exit;
}

if ($newPass !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

db_execute(
    'UPDATE users SET password = ? WHERE id = ?',
    'si',
    [password_hash($newPass, PASSWORD_BCRYPT), (int) $user['id']]
);

echo json_encode(['success' => true, 'message' => 'Password updated.']);
