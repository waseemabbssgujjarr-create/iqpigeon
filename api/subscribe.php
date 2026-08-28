<?php
/**
 * Subscribe / unsubscribe to product updates (public + logged-in).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

security_rate_limit_or_abort('subscribe', 10, 3600);

$input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

if (!verify_csrf($input['csrf_token'] ?? '')) {
    json_response(['success' => false, 'message' => 'Invalid request.'], 403);
}

$action = $input['action'] ?? 'subscribe';

if ($action === 'unsubscribe') {
    $token = trim($input['token'] ?? '');
    $result = unsubscribe_from_updates($token);
    json_response($result, $result['success'] ? 200 : 400);
}

$email = trim($input['email'] ?? '');
$name = trim($input['name'] ?? '');

$loggedIn = get_user();
$userId = $loggedIn ? (int) $loggedIn['id'] : null;
$source = $loggedIn ? 'settings' : 'website';

if ($loggedIn && $email === '') {
    $email = $loggedIn['email'];
    $name = $loggedIn['name'];
}

$result = subscribe_to_updates($email, $name, $userId, $source);
json_response($result, $result['success'] ? 200 : 400);
